<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.7                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2017                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
 */
namespace Civi\FlexMailer\Listener;

use Civi\FlexMailer\Event\RunEvent;
use Civi\FlexMailer\Event\SendBatchEvent;
use Civi\FlexMailer\Event\WalkBatchesEvent;
use Civi\FlexMailer\FlexMailerTask;
use Civi\Token\TokenProcessor;
use Civi\Token\TokenRow;
use Symfony\Component\EventDispatcher\Event;

class DefaultSender extends BaseListener {
  const BULK_MAIL_INSERT_COUNT = 10;

  public function onSend(SendBatchEvent $e) {
    static $smtpConnectionErrors = 0;

    if (!$this->isActive()) {
      return;
    }

    $e->stopPropagation();

    $job = $e->getJob();
    $mailing = $e->getMailing();
    $job_date = \CRM_Utils_Date::isoToMysql($job->scheduled_date);
    if (version_compare(\CRM_Utils_System::version(), '4.7.0', '>=')) {
      $mailer = \Civi::service('pear_mail');
    }
    else {
      $mailer = \CRM_Core_Config::singleton()->getMailer(TRUE);
    }

    $targetParams = $deliveredParams = array();
    $count = 0;
    $retryBatch = FALSE;

    foreach ($e->getTasks() as $key => $task) {
      /** @var FlexMailerTask $task */
      /** @var \Mail_mime $message */
      if (!$task->hasContent()) {
        continue;
      }

      $message = \Civi\FlexMailer\MailParams::convertMailParamsToMime($task->getMailParams());

      if (empty($message)) {
        // lets keep the message in the queue
        // most likely a permissions related issue with smarty templates
        // or a bad contact id? CRM-9833
        continue;
      }

      // disable error reporting on real mailings (but leave error reporting for tests), CRM-5744
      if ($job_date) {
        $errorScope = \CRM_Core_TemporaryErrorScope::ignoreException();
      }

      $headers = $message->headers();
      $result = $mailer->send($headers['To'], $message->headers(),
        $message->get());

      if ($job_date) {
        unset($errorScope);
      }

      if (is_a($result, 'PEAR_Error')) {
        /** @var \PEAR_Error $result */
        // CRM-9191
        $message = $result->getMessage();
        if ($this->isTemporaryError($result->getMessage())) {
          // lets log this message and code
          $code = $result->getCode();
          \CRM_Core_Error::debug_log_message("SMTP Socket Error or failed to set sender error. Message: $message, Code: $code");

          // these are socket write errors which most likely means smtp connection errors
          // lets skip them and reconnect.
          $smtpConnectionErrors++;
          if ($smtpConnectionErrors <= 5) {
            $mailer->disconnect();
            $retryBatch = TRUE;
            continue;
          }

          // seems like we have too many of them in a row, we should
          // write stuff to disk and abort the cron job
          $job->writeToDB($deliveredParams, $targetParams, $mailing, $job_date);

          \CRM_Core_Error::debug_log_message("Too many SMTP Socket Errors. Exiting");
          \CRM_Utils_System::civiExit();
        }
        else {
          $this->recordBounce($job, $task, $result->getMessage());
        }
      }
      else {
        // Register the delivery event.
        $deliveredParams[] = $task->getEventQueueId();
        $targetParams[] = $task->getContactId();

        $count++;
        if ($count % self::BULK_MAIL_INSERT_COUNT == 0) {
          $job->writeToDB($deliveredParams, $targetParams, $mailing, $job_date);
          $count = 0;

          // hack to stop mailing job at run time, CRM-4246.
          // to avoid making too many DB calls for this rare case
          // lets do it when we snapshot
          $status = \CRM_Core_DAO::getFieldValue(
            'CRM_Mailing_DAO_MailingJob',
            $job->id,
            'status',
            'id',
            TRUE
          );

          if ($status != 'Running') {
            $e->setCompleted(FALSE);
            return;
          }
        }
      }

      unset($result);

      // seems like a successful delivery or bounce, lets decrement error count
      // only if we have smtp connection errors
      if ($smtpConnectionErrors > 0) {
        $smtpConnectionErrors--;
      }

      // If we have enabled the Throttle option, this is the time to enforce it.
      $mailThrottleTime = \CRM_Core_Config::singleton()->mailThrottleTime;
      if (!empty($mailThrottleTime)) {
        usleep((int) $mailThrottleTime);
      }
    }

    $completed = $job->writeToDB(
      $deliveredParams,
      $targetParams,
      $mailing,
      $job_date
    );
    if ($retryBatch) {
      $completed = FALSE;
    }
    $e->setCompleted($completed);
  }

  /**
   * Determine if an SMTP error is temporary or permanent.
   *
   * @param string $message
   *   PEAR error message.
   * @return bool
   *   TRUE - Temporary/retriable error
   *   FALSE - Permanent/non-retriable error
   */
  protected function isTemporaryError($message) {
    // SMTP response code is buried in the message.
    $code = preg_match('/ \(code: (.+), response: /', $message, $matches) ? $matches[1] : '';

    if (strpos($message, 'Failed to write to socket') !== FALSE) {
      return TRUE;
    }

    // Register 5xx SMTP response code (permanent failure) as bounce.
    if ($code{0} === '5') {
      return FALSE;
    }

    if (strpos($message, 'Failed to set sender') !== FALSE) {
      return TRUE;
    }

    if (strpos($message, 'Failed to add recipient') !== FALSE) {
      return TRUE;
    }

    if (strpos($message, 'Failed to send data') !== FALSE) {
      return TRUE;
    }

    return FALSE;
  }

  /**
   * @param \CRM_Mailing_BAO_MailingJob $job
   * @param FlexMailerTask $task
   * @param string $errorMessage
   */
  protected function recordBounce($job, $task, $errorMessage) {
    $params = array(
      'event_queue_id' => $task->getEventQueueId(),
      'job_id' => $job->id,
      'hash' => $task->getHash(),
    );
    $params = array_merge($params,
      \CRM_Mailing_BAO_BouncePattern::match($errorMessage)
    );
    \CRM_Mailing_Event_BAO_Bounce::create($params);
  }

}

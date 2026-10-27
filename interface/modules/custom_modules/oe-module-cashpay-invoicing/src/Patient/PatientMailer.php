<?php

/**
 * Emails a link (pay link, card-on-file link) to the patient. The message
 * names the practice and the amount only.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use MyMailer;
use OpenEMR\Common\Utils\ValidationUtils;
use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;

final readonly class PatientMailer
{
    public function __construct(private string $senderEmail, private string $practiceName)
    {
    }

    public function sendLink(PatientInfo $patient, string $subject, string $intro, string $url): void
    {
        if (!ValidationUtils::isValidEmail($patient->email)) {
            throw new ValidationException(['email' => 'The patient has no valid email address on file. Copy the link and text it instead.']);
        }
        if (!ValidationUtils::isValidEmail($this->senderEmail)) {
            throw new ValidationException(['email' => 'Set the patient reminder sender email in Admin > Config > Notifications first']);
        }
        $mail = new MyMailer(true);
        $mail->setFrom($this->senderEmail, $this->practiceName !== '' ? $this->practiceName : $this->senderEmail);
        $mail->addReplyTo($this->senderEmail, $this->practiceName);
        $mail->addAddress($patient->email, $patient->name);
        $mail->Subject = $subject;
        $body = $intro . "\n\n" . $url . "\n\n" . 'The link is secure and handled by Stripe. It expires in 23 hours. '
            . 'Questions? Call us' . ($this->practiceName !== '' ? ' at ' . $this->practiceName : '') . '.';
        $mail->Body = $body;
        $mail->AltBody = $body;
        $mail->isHTML(false);
        if (!$mail->send()) {
            throw new \RuntimeException('Mail server refused the message');
        }
    }
}

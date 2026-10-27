<?php

/**
 * Emails a quote PDF to the patient, marked for information only.
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

final readonly class QuoteMailer
{
    public function __construct(private string $senderEmail, private string $practiceName)
    {
    }

    /**
     * @throws ValidationException when there is no usable address
     * @throws \RuntimeException when the mail server refuses the message
     */
    public function send(PatientInfo $patient, string $pdf, int $quoteId): void
    {
        if (!ValidationUtils::isValidEmail($patient->email)) {
            throw new ValidationException(['email' => 'The patient has no valid email address on file']);
        }
        if (!ValidationUtils::isValidEmail($this->senderEmail)) {
            throw new ValidationException(['email' => 'Set the patient reminder sender email in Admin > Config > Notifications first']);
        }
        $mail = new MyMailer(true);
        $mail->setFrom($this->senderEmail, $this->practiceName !== '' ? $this->practiceName : $this->senderEmail);
        $mail->addReplyTo($this->senderEmail, $this->practiceName);
        $mail->addAddress($patient->email, $patient->name);
        $mail->Subject = 'Your quote from ' . ($this->practiceName !== '' ? $this->practiceName : 'our office');
        $body = 'Attached is the quote you asked for. It is for your information only and is not a contract or a bill. '
            . 'Please call us with any questions.';
        $mail->Body = $body;
        $mail->AltBody = $body;
        $mail->isHTML(false);
        $mail->addStringAttachment($pdf, 'quote-' . $quoteId . '.pdf', 'base64', 'application/pdf');
        if (!$mail->send()) {
            throw new \RuntimeException('Mail server refused the quote email');
        }
    }
}

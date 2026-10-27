<?php

/**
 * Stores a scanned, hand-signed quote in the patient's documents.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CashpayInvoicing\Patient;

use OpenEMR\Modules\CashpayInvoicing\Admin\ValidationException;
use OpenEMR\Modules\CashpayInvoicing\Database\Database;
use OpenEMR\Modules\CashpayInvoicing\Database\Row;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final readonly class SignedScanUploader
{
    private const ALLOWED = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];

    private const MAX_BYTES = 15_000_000;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return int the new documents.id
     */
    public function store(int $pid, int $quoteId, UploadedFile $file, int $userId): int
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new ValidationException(['file' => 'Choose a PDF, JPG or PNG under 15 MB']);
        }
        $mime = $file->getMimeType() ?? '';
        $extension = self::ALLOWED[$mime] ?? null;
        if ($extension === null) {
            throw new ValidationException(['file' => 'Choose a PDF, JPG or PNG under 15 MB']);
        }
        $data = file_get_contents($file->getPathname());
        if ($data === false) {
            throw new \RuntimeException('Uploaded file could not be read');
        }
        $category = $this->db->fetchOne("SELECT `id` FROM `categories` WHERE `name` = 'Patient Information' LIMIT 1")
            ?? $this->db->fetchOne("SELECT `id` FROM `categories` WHERE `name` = 'Medical Record' LIMIT 1")
            ?? throw new \RuntimeException('No document category to store the signed quote');
        $document = new \Document();
        $error = $document->createDocument(
            (string) $pid,
            Row::int($category, 'id'),
            'signed-quote-' . $quoteId . '.' . $extension,
            $mime,
            $data,
            '',
            1,
            $userId,
        );
        if ($error !== '') {
            throw new \RuntimeException('Signed quote could not be stored');
        }
        $id = $document->get_id();
        if (!is_numeric($id)) {
            throw new \RuntimeException('Signed quote document has no id');
        }
        return (int) $id;
    }
}

<?php

/**
 * Patient photo for the hover card: finds the photo document, checks the
 * user may open it, and returns a small JPEG thumbnail (never the original).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard;

use OpenEMR\Common\Database\QueryUtils;

class PhotoService
{
    /** Source images larger than this are refused rather than decoded. */
    private const MAX_SOURCE_BYTES = 15 * 1024 * 1024;

    public function findDocumentId(int $pid, string $category): ?int
    {
        $row = QueryUtils::querySingleRow(
            'SELECT d.`id` FROM `documents` d '
            . 'JOIN `categories_to_documents` cd ON cd.`document_id` = d.`id` '
            . 'JOIN `categories` c ON c.`id` = cd.`category_id` '
            . 'WHERE d.`foreign_id` = ? AND d.`deleted` = 0 AND c.`name` = ? '
            . 'ORDER BY d.`date` DESC, d.`id` DESC LIMIT 1',
            [$pid, $category],
            false
        );
        return is_array($row) && isset($row['id']) ? (int)$row['id'] : null;
    }

    /**
     * JPEG thumbnail of the patient's photo, or null when there is none, the
     * user may not open the document, or it cannot be decoded.
     */
    public function thumbnailFor(int $pid, string $category, int $size): ?string
    {
        $id = $this->findDocumentId($pid, $category);
        if ($id === null) {
            return null;
        }
        $document = new \Document($id);
        // Same per-category ACL core applies, plus an ownership check against IDOR.
        if ((int)$document->get_foreign_id() !== $pid || !$document->can_access()) {
            return null;
        }
        try {
            $bytes = $document->get_data();
        } catch (\Throwable) {
            return null;
        }
        if (!is_string($bytes) || $bytes === '') {
            return null;
        }
        return self::thumbnail($bytes, $size);
    }

    /**
     * Center-cropped square JPEG of $size pixels. Null if GD is missing or the
     * data is not a decodable image.
     */
    public static function thumbnail(string $bytes, int $size): ?string
    {
        if (!function_exists('imagecreatefromstring') || $bytes === '' || strlen($bytes) > self::MAX_SOURCE_BYTES) {
            return null;
        }
        $size = max(16, min(512, $size));
        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }
        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = imagecreatetruecolor($size, $size);
        if ($side < 1 || $target === false) {
            return null;
        }
        $white = imagecolorallocate($target, 255, 255, 255);
        if ($white !== false) {
            imagefill($target, 0, 0, $white); // flatten transparency
        }
        imagecopyresampled($target, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $size, $size, $side, $side);
        ob_start();
        imagejpeg($target, null, 82);
        $jpeg = ob_get_clean();
        return is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }
}

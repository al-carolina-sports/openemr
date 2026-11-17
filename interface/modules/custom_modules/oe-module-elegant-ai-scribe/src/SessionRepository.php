<?php

/**
 * Recording sessions. A session belongs to one user, patient and encounter; every lookup checks all three.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Core\OEGlobalsBag;

class SessionRepository
{
    public function __construct(private readonly int $userId, private readonly int $pid, private readonly int $encounter)
    {
    }

    public function create(): string
    {
        $uuid = bin2hex(random_bytes(16));
        QueryUtils::sqlStatementThrowException(
            'INSERT INTO `oe_module_eas_sessions` (`uuid`, `user_id`, `pid`, `encounter`, `status`) VALUES (?, ?, ?, ?, ?)',
            [$uuid, $this->userId, $this->pid, $this->encounter, 'new']
        );
        return $uuid;
    }

    /** @return array<string, mixed>|null */
    public function find(string $uuid): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $uuid)) {
            return null;
        }
        $row = QueryUtils::querySingleRow(
            'SELECT * FROM `oe_module_eas_sessions` WHERE `uuid` = ? AND `user_id` = ? AND `pid` = ? AND `encounter` = ?',
            [$uuid, $this->userId, $this->pid, $this->encounter]
        );
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $fields */
    public function update(string $uuid, array $fields): void
    {
        $allowed = ['status', 'audio_path', 'audio_mime', 'audio_seconds', 'transcriber', 'generator', 'job_name', 'transcript', 'note_json', 'error', 'form_id'];
        $sets = [];
        $binds = [];
        foreach ($fields as $k => $v) {
            if (in_array($k, $allowed, true)) {
                $sets[] = '`' . $k . '` = ?';
                $binds[] = $v;
            }
        }
        if ($sets === []) {
            return;
        }
        $binds[] = $uuid;
        $binds[] = $this->userId;
        QueryUtils::sqlStatementThrowException('UPDATE `oe_module_eas_sessions` SET ' . implode(', ', $sets) . ' WHERE `uuid` = ? AND `user_id` = ?', $binds);
    }

    public static function audioDir(): string
    {
        $dir = rtrim(OEGlobalsBag::getInstance()->getString('OE_SITE_DIR'), '/') . '/documents/elegant_ai_scribe';
        if (!is_dir($dir)) {
            mkdir($dir, 0770, true);
        }
        return $dir;
    }

    public static function deleteAudio(?string $path): void
    {
        if ($path && str_starts_with(realpath($path) ?: '', realpath(self::audioDir()) ?: "\0") && is_file($path)) {
            unlink($path);
        }
    }
}

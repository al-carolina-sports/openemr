<?php

/**
 * Speech-to-text provider. Batch: start() may finish at once (mock) or return a job to poll.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantAiScribe\Provider;

interface TranscriberInterface
{
    public function id(): string;

    /**
     * Start transcribing a local audio file.
     *
     * @return array{status: 'done'|'running', transcript?: string, job?: string}
     */
    public function start(string $audioPath, string $mime, string $sessionUuid): array;

    /**
     * Poll a running job.
     *
     * @return array{status: 'done'|'running'|'failed', transcript?: string, error?: string}
     */
    public function poll(string $job): array;

    /** Remove any provider-side copies (uploaded audio, result files). */
    public function cleanup(string $job): void;
}

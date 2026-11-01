<?php

/**
 * Sends a Converse request to a model.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Bedrock;

interface ConverseClient
{
    /**
     * @throws BedrockException
     */
    public function converse(ConverseRequest $request): ConverseResponse;
}

<?php

/**
 * The tools the model may call, by name. Anything else is refused.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant\Tools;

use OpenEMR\Modules\AiAssistant\Bedrock\ToolSpec;
use OpenEMR\Modules\AiAssistant\Bedrock\ToolUse;
use Psr\Log\LoggerInterface;

final readonly class ToolRegistry
{
    /** @var array<string, Tool> */
    private array $tools;

    /**
     * @param list<Tool> $tools
     */
    public function __construct(array $tools, private LoggerInterface $logger)
    {
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool::spec()->name] = $tool;
        }
        $this->tools = $byName;
    }

    /**
     * @return list<ToolSpec>
     */
    public function specs(): array
    {
        return array_values(array_map(static fn(Tool $t): ToolSpec => $t::spec(), $this->tools));
    }

    public function run(ToolUse $call): ToolResult
    {
        $tool = $this->tools[$call->name] ?? null;
        if ($tool === null) {
            return ToolResult::error('Unknown tool "' . mb_substr($call->name, 0, 64) . '". Use only the tools provided.');
        }
        try {
            return $tool->run(new ToolInput($call->input));
        } catch (InvalidToolInput $e) {
            return ToolResult::error('Invalid input: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('AI Assistant tool failed', ['tool' => $call->name, 'exception' => $e]);
            return ToolResult::error('The tool failed because of a system error. Tell the user to try again or use the calendar directly.');
        }
    }
}

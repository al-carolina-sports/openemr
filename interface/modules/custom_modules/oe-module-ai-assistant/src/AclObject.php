<?php

/**
 * Access control objects of the "ai_assistant" ACO section.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant;

/**
 * Backed because the value is stored in phpGACL.
 */
enum AclObject: string
{
    case Use = 'use';
    case Settings = 'settings';
    case Audit = 'audit';

    public const SECTION = 'ai_assistant';
    public const SECTION_TITLE = 'AI Assistant';

    public function title(): string
    {
        return match ($this) {
            self::Use => 'AI Assistant: use the chat panel',
            self::Settings => 'AI Assistant: settings (model, limits, on/off)',
            self::Audit => 'AI Assistant: view the audit log',
        };
    }

    /**
     * Stock OpenEMR groups that receive this object on first enable.
     * Using the assistant never grants more than the user already has:
     * every tool also checks the core calendar and demographics rights.
     *
     * @return list<string>
     */
    public function defaultGroups(): array
    {
        return match ($this) {
            self::Use => ['Administrators', 'Physicians', 'Front Office'],
            self::Settings, self::Audit => ['Administrators'],
        };
    }
}

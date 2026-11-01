<?php

/**
 * Builds the module's services from OpenEMR's runtime (database, clock,
 * logger, calendar settings), so every entry point wires them the same way.
 * Configuration only: business classes never see this.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\AiAssistant;

use GuzzleHttp\Client;
use OpenEMR\BC\ServiceContainer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Modules\AiAssistant\Agent\Assistant;
use OpenEMR\Modules\AiAssistant\Agent\ConversationStore;
use OpenEMR\Modules\AiAssistant\Audit\AuditLog;
use OpenEMR\Modules\AiAssistant\Audit\CoreAuditRecorder;
use OpenEMR\Modules\AiAssistant\Bedrock\CredentialsLoader;
use OpenEMR\Modules\AiAssistant\Bedrock\HttpConverseClient;
use OpenEMR\Modules\AiAssistant\Calendar\CoreCalendarReader;
use OpenEMR\Modules\AiAssistant\Calendar\OfficeHours;
use OpenEMR\Modules\AiAssistant\Calendar\OpenSlotFinder;
use OpenEMR\Modules\AiAssistant\Database\Database;
use OpenEMR\Modules\AiAssistant\Database\QueryUtilsDatabase;
use OpenEMR\Modules\AiAssistant\Directory\SqlPatientDirectory;
use OpenEMR\Modules\AiAssistant\Directory\SqlProviderDirectory;
use OpenEMR\Modules\AiAssistant\Settings\Settings;
use OpenEMR\Modules\AiAssistant\Settings\SettingsRepository;
use OpenEMR\Modules\AiAssistant\Tools\FindOpenSlotsTool;
use OpenEMR\Modules\AiAssistant\Tools\FindPatientTool;
use OpenEMR\Modules\AiAssistant\Tools\GetPatientAppointmentsTool;
use OpenEMR\Modules\AiAssistant\Tools\GetProviderScheduleTool;
use OpenEMR\Modules\AiAssistant\Tools\ListProvidersTool;
use OpenEMR\Modules\AiAssistant\Tools\Permissions;
use OpenEMR\Modules\AiAssistant\Tools\ToolRegistry;

final class ModuleServices
{
    public readonly Database $db;
    public readonly SettingsRepository $settingsRepository;
    private ?Settings $settings = null;

    public function __construct()
    {
        $this->db = new QueryUtilsDatabase();
        $this->settingsRepository = new SettingsRepository($this->db);
    }

    public static function webRoot(): string
    {
        return OEGlobalsBag::getInstance()->getWebRoot() . Bootstrap::MODULE_PATH;
    }

    public function settings(): Settings
    {
        return $this->settings ??= $this->settingsRepository->load();
    }

    public function credentials(): CredentialsLoader
    {
        return CredentialsLoader::forFile($this->settings()->credentialsFile);
    }

    public function audit(): AuditLog
    {
        return new AuditLog($this->db, ServiceContainer::getClock(), new CoreAuditRecorder());
    }

    /**
     * The clinic's time zone: OpenEMR's "Time Zone" global, else PHP's default.
     */
    public static function timezone(): \DateTimeZone
    {
        $name = OEGlobalsBag::getInstance()->getString('gbl_time_zone');
        try {
            return new \DateTimeZone($name !== '' ? $name : date_default_timezone_get());
        } catch (\Throwable) {
            return new \DateTimeZone(date_default_timezone_get());
        }
    }

    /**
     * Office hours for open-slot search, from the calendar globals
     * (Calendar Starting/Ending Hour, Calendar Interval) and the module settings.
     */
    public function officeHours(): OfficeHours
    {
        $globals = OEGlobalsBag::getInstance();
        $start = self::hour($globals->getString('schedule_start'), 8);
        $end = self::hour($globals->getString('schedule_end'), 17);
        if ($end <= $start) {
            [$start, $end] = [8, 17];
        }
        $step = $globals->getInt('calendar_interval');
        return new OfficeHours(
            $start * 60,
            $end * 60,
            $step >= 5 && $step <= 240 ? $step : 15,
            $this->settings()->useDefaultHours,
            $this->settings()->workingDays,
        );
    }

    public function assistant(Permissions $access): Assistant
    {
        $settings = $this->settings();
        $clock = ServiceContainer::getClock();
        $timezone = self::timezone();
        $calendar = CoreCalendarReader::create($this->db, OEGlobalsBag::getInstance()->getProjectDir(), $timezone);
        $patients = new SqlPatientDirectory($this->db);
        $providers = new SqlProviderDirectory($this->db);
        $logger = ServiceContainer::getLogger();

        $tools = new ToolRegistry([
            new FindPatientTool($patients, $access),
            new ListProvidersTool($providers, $access),
            new GetProviderScheduleTool($calendar, $providers, $access),
            new FindOpenSlotsTool($calendar, $providers, new OpenSlotFinder($this->officeHours()), $access, $clock, $timezone),
            new GetPatientAppointmentsTool($calendar, $patients, $access, $clock, $timezone),
        ], $logger);

        $model = new HttpConverseClient(
            // A separate client: the shared one times out after 15 seconds, too soon for a model.
            new Client(['timeout' => 90, 'connect_timeout' => 10, 'http_errors' => false]),
            ServiceContainer::getRequestFactory(),
            ServiceContainer::getStreamFactory(),
            $this->credentials(),
            $clock,
            $settings->region,
        );

        return new Assistant(
            $model,
            $tools,
            new ConversationStore($this->db, $clock),
            $this->audit(),
            $settings,
            $clock,
            $timezone,
            ServiceContainer::getUuidFactory(),
            $logger,
        );
    }

    private static function hour(string $value, int $default): int
    {
        return preg_match('/^\d{1,2}$/', $value) === 1 && (int) $value <= 24 ? (int) $value : $default;
    }
}

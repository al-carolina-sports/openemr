<?php

/**
 * Isolated tests for the module's pure logic (no database, no OpenEMR boot).
 * Run with: vendor/bin/phpunit interface/modules/custom_modules/oe-module-appt-hovercard/tests
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard\Tests;

use OpenEMR\Modules\ApptHoverCard\AccessPolicy;
use OpenEMR\Modules\ApptHoverCard\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/FieldCatalog.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/AccessPolicy.php';

final class AccessAndSettingsTest extends TestCase
{
    public function testDefaultsAllowEveryRole(): void
    {
        $access = new AccessPolicy(Settings::defaults(), ['Front Office']);
        $this->assertTrue($access->canViewCard());
        $this->assertTrue($access->canViewSensitive());
    }

    public function testRoleOutsideCardListSeesNothing(): void
    {
        $settings = Settings::normalize(['enabled' => '1', 'roles_card' => ['Clinicians'], 'roles_contact' => ['Clinicians']]);
        $access = new AccessPolicy($settings, ['Accounting']);
        $this->assertFalse($access->canViewCard());
        $this->assertFalse($access->canViewSensitive());
    }

    public function testContactRolesCanBeNarrowerThanCardRoles(): void
    {
        $settings = Settings::normalize(['enabled' => '1', 'roles_card' => ['*'], 'roles_contact' => ['Clinicians']]);
        $frontOffice = new AccessPolicy($settings, ['Front Office']);
        $this->assertTrue($frontOffice->canViewCard());
        $this->assertFalse($frontOffice->canViewSensitive());
        $this->assertTrue((new AccessPolicy($settings, ['Clinicians']))->canViewSensitive());
    }

    public function testDisabledModuleShowsNoCard(): void
    {
        $settings = Settings::normalize(['roles_card' => ['*'], 'roles_contact' => ['*']]); // "enabled" absent = unchecked
        $this->assertFalse((new AccessPolicy($settings, ['Clinicians']))->canViewCard());
    }

    /**
     * @return array<string, array{string, string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function phoneProvider(): array
    {
        return [
            'formatted' => ['(919) 555-1234', '(***) ***-1234'],
            'digits' => ['9195551234', '(***) ***-1234'],
            'empty' => ['', ''],
            'no digits' => ['n/a', ''],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function testMaskPhone(string $input, string $expected): void
    {
        $this->assertSame($expected, AccessPolicy::maskPhone($input));
    }

    public function testAge(): void
    {
        $today = new \DateTimeImmutable('2026-10-11');
        $this->assertSame(46, AccessPolicy::ageOn('1980-10-11', $today));
        $this->assertSame(45, AccessPolicy::ageOn('1980-10-12', $today));
        $this->assertNull(AccessPolicy::ageOn('', $today));
        $this->assertNull(AccessPolicy::ageOn('0000-00-00', $today));
        $this->assertNull(AccessPolicy::ageOn('2030-01-01', $today));
    }

    public function testNormalizeClampsAndRejectsBadInput(): void
    {
        $settings = Settings::normalize([
            'enabled' => '1',
            'hover_delay_ms' => '999999',
            'card_width' => '10',
            'views' => ['day', 'bogus'],
            'photo_size' => 'huge',
            'source_best_time' => 'x; DROP TABLE patient_data',
            'roles_card' => ['Clinicians', '*'],
        ]);
        $this->assertSame(5000, $settings['hover_delay_ms']);
        $this->assertSame(240, $settings['card_width']);
        $this->assertSame(['day'], $settings['views']);
        $this->assertSame('medium', $settings['photo_size']);
        $this->assertSame('', $settings['source_best_time']);
        $this->assertSame(['*'], $settings['roles_card']);
    }
}

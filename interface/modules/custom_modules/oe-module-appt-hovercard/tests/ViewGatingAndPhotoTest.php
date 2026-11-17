<?php

/**
 * Isolated tests: calendar view gating and photo thumbnails (no database).
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ApptHoverCard\Tests;

use OpenEMR\Modules\ApptHoverCard\PhotoService;
use OpenEMR\Modules\ApptHoverCard\Settings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/FieldCatalog.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/PhotoService.php';

final class ViewGatingAndPhotoTest extends TestCase
{
    /**
     * @return array<string, array{mixed, bool}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function viewProvider(): array
    {
        return [
            'day on by default' => ['day', true],
            'week on by default' => ['week', true],
            'month off by default' => ['month', false],
            'unknown view' => ['year', false],
            'missing view' => [null, false],
            'non-string view' => [['day'], false],
        ];
    }

    #[DataProvider('viewProvider')]
    public function testViewEnabledWithDefaults(mixed $view, bool $expected): void
    {
        $this->assertSame($expected, Settings::viewEnabled(Settings::defaults(), $view));
    }

    public function testViewEnabledFollowsAdminSetting(): void
    {
        $settings = Settings::normalize(['enabled' => '1', 'views' => ['month']]);
        $this->assertTrue(Settings::viewEnabled($settings, 'month'));
        $this->assertFalse(Settings::viewEnabled($settings, 'day'));
        $this->assertFalse(Settings::viewEnabled(Settings::normalize(['enabled' => '1']), 'day'));
    }

    public function testThumbnailIsSmallSquareJpeg(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD not available');
        }
        $image = imagecreatetruecolor(800, 400);
        $this->assertNotFalse($image);
        ob_start();
        imagepng($image);
        $png = (string)ob_get_clean();

        $jpeg = PhotoService::thumbnail($png, 128);
        $this->assertNotNull($jpeg);
        $info = getimagesizefromstring($jpeg);
        $this->assertIsArray($info);
        $this->assertSame([128, 128], [$info[0], $info[1]]);
        $this->assertSame('image/jpeg', $info['mime']);
    }

    public function testThumbnailRejectsNonImages(): void
    {
        $this->assertNull(PhotoService::thumbnail('not an image', 100));
        $this->assertNull(PhotoService::thumbnail('', 100));
    }
}

<?php

/**
 * A set of theme settings submitted from the theme picker, already validated.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    Carolina Sports and Spine
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\CompactTheme;

use Symfony\Component\HttpFoundation\InputBag;

final readonly class ThemeChoice
{
    /**
     * @param string  $themeId An installed theme pack id, or ThemeSettings::THEME_OFF
     * @param ?string $accent  Six hex digits without '#', or null for the theme's own accent
     */
    private function __construct(
        public string $themeId,
        public Density $density,
        public MenuStyle $menuStyle,
        public ?string $accent
    ) {
    }

    /**
     * Parses the picker form. Returns null when the theme isn't installed or a value is unknown.
     *
     * @param InputBag<string> $post
     */
    public static function fromPost(InputBag $post, ThemeRegistry $registry): ?self
    {
        $themeId = $post->getString('theme');
        if ($themeId !== ThemeSettings::THEME_OFF && $registry->find($themeId) === null) {
            return null;
        }
        $density = Density::tryFrom($post->getString('density'));
        $menuStyle = MenuStyle::tryFrom($post->getString('menu_style'));
        if ($density === null || $menuStyle === null) {
            return null;
        }
        $accent = $post->getBoolean('use_accent') ? ThemeSettings::parseAccent($post->getString('accent')) : null;
        return new self($themeId, $density, $menuStyle, $accent);
    }

    /**
     * @return array<string, string> Global name => stored value
     */
    public function toGlobals(): array
    {
        return [
            ThemeSettings::KEY_THEME => $this->themeId,
            ThemeSettings::KEY_DENSITY => $this->density->value,
            ThemeSettings::KEY_MENU_STYLE => $this->menuStyle->value,
            ThemeSettings::KEY_ACCENT => $this->accent === null ? '' : '#' . $this->accent,
        ];
    }
}

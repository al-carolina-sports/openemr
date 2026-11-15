<?php

/**
 * Annie's Elegant Themes catalog.
 *
 * base    = which stock OpenEMR theme is loaded underneath (light/solar) so the
 *           module CSS only has to refine it instead of fighting a dark base.
 * compact = also switch the tab bar to OpenEMR's compact tab layout.
 * menu    = where the main menu sits: 'top' bar or 'left' icon rail (v1.2.0).
 * density/desc/suits = picker-card copy (v1.2.0); screenshots live in
 *           public/assets/screens/<key>-calendar.jpg and <key>-hovercard.jpg.
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @copyright Copyright (c) 2026 Carolina Sports and Spine
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ElegantThemes;

final class Themes
{
    public const VERSION = '1.5.2';

    /** Stored value meaning "use the site default". */
    public const SITE = 'site';
    /** Stored value meaning "no module theme" (OpenEMR's own theme). */
    public const OFF = '';

    public const ALL = [
        'radiance-atelier' => [
            'name' => 'Radiance Atelier',
            'blurb' => 'Ivory, plum and champagne gold. Calm spa look, roomier rows.',
            'base' => 'light', 'compact' => false,
            'swatch' => ['#FBF8F4', '#4A2C40', '#B08D57', '#8FA89B'],
            'menu' => 'top', 'density' => 'Roomy',
            'desc' => 'Ivory background, plum header and champagne-gold accents with sage highlights. Soft and calm, with roomier rows and larger touch targets.',
            'suits' => 'Med-spa and aesthetics front desks, and anyone who wants a warm, low-glare look rather than maximum density.',
        ],
        'storyboard-slate' => [
            'name' => 'Storyboard Slate',
            'blurb' => 'Very compact navy. Left icon rail and pinned patient banner.',
            'base' => 'light', 'compact' => true,
            'swatch' => ['#EEF1F5', '#1F3A5F', '#2F80ED', '#27AE60'],
            'menu' => 'left', 'density' => 'Very compact',
            'desc' => 'Navy and cool grey with bright-blue actions and green success states. Tight spacing plus a pinned patient banner, Epic-style.',
            'suits' => 'Busy clinicians who live in the chart and want more vertical space and every module one click away on the rail.',
        ],
        'inbox-ledger' => [
            'name' => 'Inbox Ledger',
            'blurb' => 'Very compact teal. Dense tables, ledger-style lists.',
            'base' => 'light', 'compact' => true,
            'swatch' => ['#F6F7F7', '#0F3D3A', '#0F766E', '#6366F1'],
            'menu' => 'top', 'density' => 'Very compact',
            'desc' => 'Off-white with deep-teal headers, teal accents and an indigo secondary. Ledger-style lists and dense tables with a teal left edge on cards.',
            'suits' => 'Billing, intake and staff who triage long lists (messages, claims, reports) and want many rows per screen.',
        ],
        'solar-noir-hc' => [
            'name' => 'Solar Noir HC',
            'blurb' => 'High-contrast refresh of the dark Solar theme. Amber focus.',
            'base' => 'solar', 'compact' => true,
            'swatch' => ['#0B0D10', '#13171C', '#FFB020', '#2DD4BF'],
            'menu' => 'top', 'density' => 'Compact',
            'desc' => 'Near-black panels with amber focus and teal highlights. A high-contrast refresh of the dark Solar theme.',
            'suits' => 'Dark-mode users, dim reading rooms, late shifts, and people who need stronger contrast for readability.',
        ],
        'paper-one-screen' => [
            'name' => 'Paper One-Screen',
            'blurb' => 'Clean light paper. Flat cards, chart fits on one screen.',
            'base' => 'light', 'compact' => true,
            'swatch' => ['#F7F7F5', '#FFFFFF', '#2E7D5B', '#C98A2B'],
            'menu' => 'top', 'density' => 'Compact',
            'desc' => 'Clean warm-white paper, white flat cards, forest-green actions and an ochre accent. Squeezes the demographics and chart onto one screen.',
            'suits' => 'Providers who want a calm, print-like chart that fits on one monitor without scrolling.',
        ],
        // v1.1.0: ported from Annie's Compact Themes theme packs
        'slate-night' => [
            'name' => 'Slate Night',
            'blurb' => 'Dark and dense on the Dark base. Teal accent, uppercase card titles.',
            'base' => 'dark', 'compact' => true,
            'swatch' => ['#0D1015', '#151A21', '#2DD4BF', '#E6E9EE'],
            'menu' => 'top', 'density' => 'Compact (dense)',
            'desc' => 'Dark slate surfaces, teal accent and light text, with uppercase card titles. Ported from Compact Themes.',
            'suits' => 'Dark-mode users who still want dense, compact screens, such as scribes and power users on large monitors.',
        ],
        'paper-compact' => [
            'name' => 'Paper Compact',
            'blurb' => 'Light, hairline borders, table-like cards. Blue accent.',
            'base' => 'light', 'compact' => true,
            'swatch' => ['#F4F5F7', '#FFFFFF', '#2563EB', '#1B1F24'],
            'menu' => 'top', 'density' => 'Compact (dense)',
            'desc' => 'Light grey and white with hairline borders, table-like cards and a crisp blue accent. Ported from Compact Themes.',
            'suits' => 'Everyday default for most staff: familiar light OpenEMR, tidier and tighter, easy on new users.',
        ],
        'graphite-rail' => [
            'name' => 'Graphite Rail',
            'blurb' => 'Light workspace with a 72px graphite icon rail. Indigo accent.',
            'base' => 'light', 'compact' => true,
            'swatch' => ['#F6F6F4', '#111827', '#4F46E5', '#FFFFFF'],
            'menu' => 'left', 'density' => 'Compact',
            'desc' => 'Light workspace with a 72px dark-graphite icon rail on the left and an indigo accent. Ported from Compact Themes.',
            'suits' => 'Wide-screen users who want the full width for the chart and calendar and prefer icon navigation on the left.',
        ],
        // v1.5.0: modern SaaS-style themes on the shared modern rail (themes/_modern-rail.css)
        'espresso' => [
            'name' => 'Espresso',
            'blurb' => 'Frappe/ERPNext Espresso look. White, hairline grey, black + Add, pill tags.',
            'base' => 'light', 'compact' => true,
            'swatch' => ['#FFFFFF', '#F3F3F3', '#171717', '#278F5E'],
            'menu' => 'left', 'density' => 'Compact',
            'desc' => 'Modelled on Frappe/ERPNext Espresso: white workspace, light-grey hairlines and soft rounded cards, a thin light icon rail with collapsible tree flyouts (chevrons), a "Dashboard / page" breadcrumb, black primary buttons, grey pill filters and green/blue pill status tags.',
            'suits' => 'Staff who like the ERPNext/Frappe desk and want a calm, information-dense white workspace with icon + label navigation.',
        ],
        'shadcn' => [
            'name' => 'shadcn',
            'blurb' => 'ui.shadcn.com dashboard look. Zinc greys, black buttons, light/dark toggle.',
            'base' => 'light', 'compact' => true,
            'swatch' => ['#FFFFFF', '#F4F4F5', '#18181B', '#E4E4E7'],
            'menu' => 'left', 'density' => 'Compact',
            'desc' => 'Modelled on the ui.shadcn.com dashboard example: neutral zinc greys, black primary buttons, thin borders, rounded cards, Inter, status pills, with a light/dark toggle in the top bar.',
            'suits' => 'Anyone who wants a modern, minimal SaaS look and switches between light and dark during the day.',
        ],
        'shadcn-dark' => [
            'name' => 'shadcn (dark)',
            'blurb' => 'Dark variant of shadcn. Zinc-950 surfaces, white primary buttons.',
            'base' => 'dark', 'compact' => true,
            'swatch' => ['#09090B', '#27272A', '#FAFAFA', '#A1A1AA'],
            'menu' => 'left', 'density' => 'Compact',
            'desc' => 'The dark half of shadcn: zinc-950 surfaces, zinc-800 borders, light text and white primary buttons. The top-bar toggle switches back to light.',
            'suits' => 'Dark-mode users who want the shadcn look; same layout and features as the light version.',
        ],
    ];

    /** v1.5.0: light/dark pairs switched by the top-bar toggle. */
    public const MODE_PAIRS = ['shadcn' => 'shadcn-dark'];

    public static function isTheme(string $key): bool
    {
        return isset(self::ALL[$key]);
    }
}

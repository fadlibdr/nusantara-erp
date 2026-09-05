<?php

namespace Modules\Iam\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The per-role onboarding guide, read from docs/ONBOARDING/<role>.md and
 * rendered for the in-app pop-up (owner's request, 5 Sep 2026: "on boarding
 * is not working" — the guides only ever existed as files in the repository).
 *
 * The markdown files stay the single source: the same page a supervisor
 * prints and hands over (README §"Cara menyerahkan") is what the screen
 * shows, so the two can never say different things. Every guide has the same
 * seven `## ` sections in the same order (README §"Kerangka yang sama"), and
 * that is what the modal's "1 dari 7" counter and rail are built on — the
 * H1 and the preamble above the first `## ` are the paper cover sheet and
 * are skipped.
 *
 * Rendering goes through Str::markdown with raw HTML STRIPPED and unsafe
 * links refused. The guides are repository files, not user input, but the
 * SPA injects the result with innerHTML into an authenticated session, and a
 * guide is edited in a text editor by whoever maintains the docs: one pasted
 * `<script>` would otherwise run in every employee's browser at login.
 */
final class OnboardingGuide
{
    /** Repository-relative folder; overridable through config for a test's scratch guide. */
    public const DIRECTORY = 'docs/ONBOARDING';

    /**
     * Where "Buka panduan lengkap" points. The repository the docs cite
     * (LAPORAN-DEVIASI: github.com/fadlibdr/nusantara-erp); the SPA resolves
     * a guide's relative links (finance.md, ../PANDUAN-PENGGUNA.md) against
     * the same base so they open the sibling file instead of the SPA route.
     */
    public const DOCS_URL = 'https://github.com/fadlibdr/nusantara-erp/blob/main';

    /** Role names are file names here; anything else must never reach the filesystem. */
    private const ROLE_PATTERN = '/^[a-z-]+$/';

    /** Sections open with a `## ` heading; the numbered "1. Siapa Anda di sistem" form is the norm. */
    private const SECTION_PATTERN = '/^## +(.+?)\s*$/m';

    public static function directory(): string
    {
        return (string) (config('iam.onboarding_guides') ?: base_path(self::DIRECTORY));
    }

    /**
     * The guide file for a role, or null when the role has none (a custom
     * role, an empty name, or a name that is not a plain slug — `../README`
     * is refused by the pattern, not by the filesystem).
     */
    public static function pathFor(?string $role): ?string
    {
        if ($role === null || $role === '' || ! preg_match(self::ROLE_PATTERN, $role)) {
            return null;
        }

        // README.md is the index, not a role. A case-insensitive filesystem
        // (a developer's laptop) would otherwise serve it for a role named
        // "readme"; production Linux would not, and the two must agree.
        if ($role === 'readme') {
            return null;
        }

        $path = self::directory().'/'.$role.'.md';

        return is_file($path) ? $path : null;
    }

    /**
     * @return array{role: string, title: string, sections: list<array{id: string, heading: string, html: string, locations: list<array{group: string, item: string, raw: string}>, tour_route: string|null}>, guide_path: string, guide_url: string}|null
     */
    public static function load(string $role): ?array
    {
        $path = self::pathFor($role);

        if ($path === null) {
            return null;
        }

        // Keyed on the file's mtime: editing a guide invalidates its entry on
        // the next request without anyone clearing a cache. Rendering twelve
        // small files is cheap, but this runs at every login of every user
        // who has not decided yet, and CommonMark is the slowest thing in it.
        // The shape version is in the key too: v2 added `locations` to every
        // section (owner's feedback, 5 Sep 2026) while no guide file changed,
        // and a database cache survives a deploy — the mtime alone would have
        // kept serving v1 renders without locations for up to a day.
        $key = 'iam.onboarding.v2.'.$role.'.'.(string) filemtime($path);

        return Cache::remember($key, now()->addDay(), fn (): array => self::render($role, $path));
    }

    /**
     * @return array{role: string, title: string, sections: list<array{id: string, heading: string, html: string, locations: list<array{group: string, item: string, raw: string}>, tour_route: string|null}>, guide_path: string, guide_url: string}
     */
    private static function render(string $role, string $path): array
    {
        $markdown = (string) file_get_contents($path);
        $relative = self::DIRECTORY.'/'.$role.'.md';

        return [
            'role' => $role,
            'title' => self::title($markdown, $role),
            'sections' => self::sections($markdown),
            'guide_path' => $relative,
            'guide_url' => self::DOCS_URL.'/'.$relative,
        ];
    }

    /**
     * "Petugas Keuangan" out of "# Onboarding minggu pertama — Petugas
     * Keuangan (`finance`)". The H1 carries the human label of the role and
     * nothing else in the system does (Spatie roles have a name only), so the
     * modal's "Selamat datang, Rina — Petugas Keuangan" is read from here.
     * A guide without a recognisable H1 falls back to the role name itself.
     */
    private static function title(string $markdown, string $role): string
    {
        if (! preg_match('/^# +(.+?)\s*$/m', $markdown, $h1)) {
            return $role;
        }

        $title = preg_replace('/^Onboarding minggu pertama\s+[—-]\s+/u', '', $h1[1]) ?? $h1[1];
        $title = preg_replace('/\s*\(`[^`]*`\)\s*$/', '', $title) ?? $title;

        return trim($title) !== '' ? trim($title) : $role;
    }

    /**
     * @return list<array{id: string, heading: string, html: string, locations: list<array{group: string, item: string, raw: string}>, tour_route: string|null}>
     */
    private static function sections(string $markdown): array
    {
        preg_match_all(self::SECTION_PATTERN, $markdown, $headings, PREG_OFFSET_CAPTURE);

        $sections = [];
        $count = count($headings[0]);

        foreach ($headings[0] as $index => [, $start]) {
            $bodyStart = $start + strlen($headings[0][$index][0]);
            $bodyEnd = $index + 1 < $count ? $headings[0][$index + 1][1] : strlen($markdown);
            $heading = trim($headings[1][$index][0]);
            $body = trim(substr($markdown, $bodyStart, $bodyEnd - $bodyStart));

            $sections[] = [
                // "1-siapa-anda-di-sistem": stable across renders, unique within
                // a guide, and what the SPA keys its session-only ticks on.
                'id' => Str::slug($heading) ?: 'bagian-'.($index + 1),
                'heading' => $heading,
                'html' => Str::markdown($body, [
                    'html_input' => 'strip',
                    'allow_unsafe_links' => false,
                ]),
                'locations' => self::locations($body),
                // The sidebar (schema.js NAV) is the only table that maps a
                // label to a route, and it lives in the SPA; guessing here
                // would be a second copy that drifts. Resolution is client-side.
                'tour_route' => null,
            ];
        }

        return $sections;
    }

    // ------------------------------------------------------------ locations

    /**
     * At most this many screens per section. Section 3 of a guide names every
     * screen of the role's week (procurement: 17); the tour needs the first few
     * in reading order, and the rest are still in the text.
     */
    public const LOCATIONS_MAX = 8;

    /**
     * Inline code spans and bold runs, paired SEQUENTIALLY — a lazy pattern
     * that also demanded a `›` inside used to start at the closing marker of a
     * span without one and swallow the prose up to the next span.
     */
    private const CODE_SPAN = '/`([^`]+)`/u';

    private const BOLD_RUN = '/\*\*([^*]+)\*\*/u';

    /** A label, not a sentence: nothing in it ends a clause or points on. */
    private const NOT_A_LABEL = '/[.:;→|§"]/u';

    /**
     * The screens a section talks about, in the order the text names them:
     * the guides write every screen as `Grup › Layar` in a code span or in
     * bold (README §"Kerangka yang sama"; 103 mentions across the twelve
     * guides), sometimes `Grup › Layar › Tombol`. Owner's feedback, 5 Sep 2026:
     * "it was great to show the intended page/location while user displayed
     * the onboarding" — this is what the SPA's guided tour navigates to and
     * highlights; matching a label to a sidebar route happens there.
     *
     * De-duplicated on the printed form, capped at LOCATIONS_MAX. A section
     * that names no screen gets an empty list, not a guess.
     *
     * @return list<array{group: string, item: string, raw: string}>
     */
    public static function locations(string $markdown): array
    {
        $candidates = [];

        preg_match_all(self::CODE_SPAN, $markdown, $spans, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($spans as $span) {
            $candidates[$span[0][1]] = $span[1][0];
        }

        preg_match_all(self::BOLD_RUN, $markdown, $runs, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($runs as $run) {
            // **Sistem › Pengguna › `Tambah Pengguna`** carries its own code
            // span; a bold whose only `›` sits INSIDE a code span is prose
            // around a mention the loop above already has.
            $outside = (string) preg_replace(self::CODE_SPAN, '', $run[1][0]);
            if (str_contains($outside, '›')) {
                $candidates[$run[0][1]] = $run[1][0];
            }
        }

        ksort($candidates);

        $locations = [];
        $seen = [];

        foreach ($candidates as $candidate) {
            $location = self::location($candidate);
            if ($location === null) {
                continue;
            }

            $key = mb_strtolower($location['raw']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $locations[] = $location;
            if (count($locations) >= self::LOCATIONS_MAX) {
                break;
            }
        }

        return $locations;
    }

    /**
     * One candidate span into {group, item, raw}, or null when it does not
     * read as a screen name. Wrapped lines (the guides wrap at ~90 columns,
     * so `Pengadaan ›\n   Permintaan (PR)` is common) collapse to one space;
     * a third segment (the button) is kept in `raw` only.
     *
     * @return array{group: string, item: string, raw: string}|null
     */
    private static function location(string $candidate): ?array
    {
        if (! str_contains($candidate, '›')) {
            return null;
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace('`', '', $candidate)));

        $segments = [];
        foreach (explode('›', $text) as $segment) {
            $segment = self::tidySegment($segment);
            if ($segment === '') {
                return null; // "› Tenggat" or "Ringkasan ›" is a fragment, not a screen
            }
            if (preg_match(self::NOT_A_LABEL, $segment) || mb_strlen($segment) > 60 || str_word_count($segment) > 6) {
                return null;
            }
            $segments[] = $segment;
        }

        // Group labels are one to three words (SDM & Payroll, Mutu (QA/QC)).
        if (count($segments) < 2 || str_word_count($segments[0]) > 3 || mb_strlen($segments[0]) > 24) {
            return null;
        }

        return [
            'group' => $segments[0],
            'item' => $segments[1],
            'raw' => implode(' › ', $segments),
        ];
    }

    /**
     * Trim, drop a trailing clause mark, and cut a dangling "(" fragment —
     * "Ketidaksesuaian (NCR" happens when the closing parenthesis fell outside
     * the span; the label is the part before it.
     */
    private static function tidySegment(string $segment): string
    {
        $segment = trim($segment, " \t\n\r\0\x0B,;:.");

        $open = strrpos($segment, '(');
        if ($open !== false && ! str_contains(substr($segment, $open), ')')) {
            $segment = rtrim(substr($segment, 0, $open));
        }

        return trim($segment);
    }
}

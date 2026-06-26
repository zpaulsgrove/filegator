<?php

/*
 * This file is part of the FileGator package.
 *
 * (c) Milos Stojanovic <alcalbg@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE file
 */

namespace Filegator\Utils;

/**
 * Shared normalisation for the multi-folder homedirs list.
 *
 * Before this helper existed, the same "trim string entries, drop blanks
 * and non-strings, re-index" loop and the same "read either `homedirs`
 * array or legacy `homedir` scalar from an array-shaped row" extractor
 * were duplicated in five places: User::setHomedirs, the two JsonFile +
 * Database adapter row extractors, AuditMailer's snapshot extractor, and
 * AdminController's request normaliser. The AuditMailer copy silently
 * drifted — it forgot to trim — which would have produced misleading
 * subject lines for any homedir that ever picked up leading/trailing
 * whitespace. Centralising removes the drift surface.
 */
class Homedirs
{
    /**
     * Trim string entries, drop blanks and non-strings, re-index.
     * Returns $default when the cleaned list is empty.
     */
    public static function clean(array $list, array $default = []): array
    {
        $out = [];
        foreach ($list as $h) {
            if (! is_string($h)) continue;
            $t = trim($h);
            if ($t === '') continue;
            $out[] = $t;
        }
        return $out ?: $default;
    }

    /**
     * True when $path points at a real subfolder, i.e. at least one level
     * below the storage root. Used to stop a non-admin user being granted the
     * firm root: after trimming the separator from both ends, root ('/') and
     * empty strings collapse to '' and are rejected; '/clientA' and
     * '/clientA/2023' keep a non-empty remainder and pass.
     */
    public static function isStrictSubfolder(string $path, string $separator = '/'): bool
    {
        return trim(trim($path), $separator) !== '';
    }

    /**
     * Read homedirs from an array-shaped row (users.json row,
     * jsonSerialize snapshot). Prefers the new `homedirs` array key;
     * falls back to wrapping the legacy `homedir` scalar; returns
     * $default when neither key has usable data.
     */
    public static function fromArrayRow(array $row, array $default = []): array
    {
        if (isset($row['homedirs']) && is_array($row['homedirs'])) {
            return self::clean($row['homedirs'], $default);
        }
        if (isset($row['homedir']) && is_string($row['homedir']) && trim($row['homedir']) !== '') {
            return [trim($row['homedir'])];
        }
        return $default;
    }

    /**
     * Canonical key for a folder path: trim whitespace, split on the
     * separator, drop empty segments (which collapses leading, trailing and
     * repeated separators), and re-join. So '/clientA', '/clientA/' and
     * 'clientA' all map to the same 'clientA', and the storage root ('/' or
     * '') collapses to ''. `..` segments are left literal — these comparisons
     * never touch the filesystem, so an unresolved `..` simply fails to match a
     * real folder rather than escaping one.
     *
     * Used both to de-duplicate the audited folder list and inside covers().
     */
    public static function normalizePath(string $path, string $separator = '/'): string
    {
        $segments = array_filter(
            explode($separator, trim($path)),
            function ($s) {
                return $s !== '';
            }
        );

        return implode($separator, $segments);
    }

    /**
     * True when $homedir grants access to $path under the home-directory
     * sandbox model: a user rooted at $homedir can reach that folder and
     * everything beneath it. The storage root (normalised '') covers
     * everything. Segment boundaries are respected so '/client' does NOT
     * cover '/client2'.
     */
    public static function covers(string $homedir, string $path, string $separator = '/'): bool
    {
        $h = self::normalizePath($homedir, $separator);
        $p = self::normalizePath($path, $separator);

        if ($h === '') {
            return true;
        }
        if ($h === $p) {
            return true;
        }

        return strpos($p, $h . $separator) === 0;
    }

    /**
     * Return the most specific (deepest) homedir from $homedirs that covers
     * $path, or null when none do. The original (un-normalised) string is
     * returned so callers can display it verbatim. When a user lists both an
     * ancestor and the folder itself, the exact/deepest match wins — so a
     * direct grant is reported as such rather than as inherited from a parent.
     */
    public static function grantingHomedir(array $homedirs, string $path, string $separator = '/'): ?string
    {
        $best = null;
        $bestLen = -1;
        foreach ($homedirs as $h) {
            if (! is_string($h)) {
                continue;
            }
            if (self::covers($h, $path, $separator)) {
                $len = strlen(self::normalizePath($h, $separator));
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $h;
                }
            }
        }

        return $best;
    }
}

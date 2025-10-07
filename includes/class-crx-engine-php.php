<?php
/**
 * CRX Engine – PHP runner
 */

if (!defined('ABSPATH')) {
    exit;
}

class CRX_Engine_PHP
{

    private static function ensure_unicode_regex(string $pattern): string
    {
        if (@preg_match($pattern, '') !== false) {
            $delim = $pattern[0];
            $last = strrpos($pattern, $delim);
            if ($last !== false) {
                $mods = substr($pattern, $last + 1);
                if (strpos($mods, 'u') === false) {
                    $pattern = substr($pattern, 0, $last + 1) . $mods . 'u';
                }
            }
            return $pattern;
        }
        return '#' . $pattern . '#u';
    }


    public static function maybe_redirect(array $rules): void
    {
        if (empty($rules))
            return;

        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($req_uri === '')
            return;

        $path = parse_url($req_uri, PHP_URL_PATH) ?? '/';
        $path = rawurldecode($path);
        $path = '/' . ltrim($path, '/');
        $path_no_slash = trim($path, '/');

        foreach ($rules as $r) {
            $engine = isset($r['engine']) ? $r['engine'] : 'htaccess';
            if ($engine !== 'php')
                continue;

            $type = isset($r['type']) ? (int) $r['type'] : 301;
            $isRegex = !empty($r['regex']);

            $from = isset($r['from']) ? (string) $r['from'] : '';
            $to = isset($r['to']) ? (string) $r['to'] : '';

            if ($from === '')
                continue;

            $from = rawurldecode($from);
            $parsed_from = wp_parse_url($from);
            if (isset($parsed_from['path'])) {
                $from = $parsed_from['path'];
            }
            if ($to !== '' && !preg_match('#^https?://#i', $to)) {
                $to = rawurldecode($to);
                $to = '/' . ltrim($to, '/');
            }

            if ($isRegex) {
                $from_trim = trim($from, '/');
                $pattern = self::ensure_unicode_regex('^' . $from_trim . '$');

                if (preg_match($pattern, $path_no_slash)) {
                    if ($type === 410)
                        self::do_gone();

                    $target = ($to !== '')
                        ? preg_replace($pattern, $to, $path_no_slash)
                        : '/';

                    if ($target !== '' && !preg_match('#^https?://#i', $target) && strpos($target, '/') !== 0) {
                        $target = '/' . ltrim($target, '/');
                    }

                    $target = self::append_query_string($target);
                    self::do_redirect($target, $type);
                }
            } else {
                $from_norm = '/' . trim($from, '/');
                $matchA = rtrim($from_norm, '/');
                $matchB = $matchA . '/';

                if ($path === $matchA || $path === $matchB) {
                    if ($type === 410)
                        self::do_gone();

                    $target = ($to !== '') ? $to : '/';
                    $target = self::append_query_string($target);
                    self::do_redirect($target, $type);
                }
            }
        }
    }


    private static function append_query_string(string $target): string
    {
        $q = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($q === '')
            return $target;
        return (strpos($target, '?') === false) ? $target . '?' . $q : $target . '&' . $q;
    }

    private static function do_gone(): void
    {
        status_header(410);
        nocache_headers();
        exit;
    }

    private static function do_redirect(string $target, int $type): void
    {
        $code = ($type === 302) ? 302 : 301;
        if (!$target)
            $target = '/';
        wp_redirect($target, $code);
        exit;
    }
}

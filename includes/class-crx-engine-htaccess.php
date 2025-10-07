<?php
/**
 * CRX Engine – .htaccess writer
 */

if (!defined('ABSPATH')) {
    exit;
}

class CRX_Engine_Htaccess
{
    const HTACCESS_BEGIN = '# BEGIN CRX Redirects';
    const HTACCESS_END = '# END CRX Redirects';

    public static function write_block(array $rules): bool
    {
        if (!self::is_apache_like())
            return false;

        $htaccess = self::get_htaccess_path();
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "# Created by CRX Redirect Manager\n");
        }
        if (!is_writable($htaccess))
            return false;

        $contents = @file_get_contents($htaccess);
        if ($contents === false)
            $contents = '';

        $pattern = '/' . preg_quote(self::HTACCESS_BEGIN, '/') . '.*?' . preg_quote(self::HTACCESS_END, '/') . '\s*/s';
        $contents = preg_replace($pattern, '', $contents);

        $block = self::build_block($rules);

        $new_contents = $block . "\n" . ltrim($contents);

        return @file_put_contents($htaccess, $new_contents) !== false;
    }

    public static function remove_block(): bool
    {
        if (!self::is_apache_like())
            return false;
        $htaccess = self::get_htaccess_path();
        if (!file_exists($htaccess) || !is_writable($htaccess))
            return false;

        $contents = @file_get_contents($htaccess);
        if ($contents === false)
            return false;

        $pattern = '/' . preg_quote(self::HTACCESS_BEGIN, '/') . '.*?' . preg_quote(self::HTACCESS_END, '/') . '\s*/s';
        $new = preg_replace($pattern, '', $contents);

        return @file_put_contents($htaccess, $new) !== false;
    }

    public static function build_block(array $rules): string
    {
        $lines = [];
        $lines[] = self::HTACCESS_BEGIN;
        $lines[] = '# این بلوک به‌صورت خودکار تولید شده است. از ویرایش دستی خودداری کنید.';
        $lines[] = 'Options -MultiViews';
        $lines[] = 'RewriteEngine On';

        foreach ($rules as $r) {
            $engine = isset($r['engine']) ? $r['engine'] : 'htaccess';
            if ($engine !== 'htaccess')
                continue;

            $type = isset($r['type']) ? (int) $r['type'] : 301;
            $isRegex = !empty($r['regex']);
            $from = isset($r['from']) ? (string) $r['from'] : '';
            $to = isset($r['to']) ? (string) $r['to'] : '';

            if ($from === '')
                continue;

            $from = rawurldecode($from);
            $pf = wp_parse_url($from);
            if (isset($pf['path'])) {
                $from = $pf['path'];
            }
            $from = trim($from, '/');

            if ($to === '' && $type !== 410) {
                $target = '/';
            } else {
                $target = (string) $to;
                if ($target !== '' && !preg_match('#^https?://#i', $target)) {
                    $target = rawurldecode($target);
                    $target = '/' . ltrim($target, '/');
                }
            }

            if ($isRegex) {
                $pattern = trim($from);
                if ($pattern !== '') {
                    if ($pattern[0] !== '^')
                        $pattern = '^' . $pattern;
                    if (substr($pattern, -1) !== '$')
                        $pattern .= '$';
                } else {
                    continue;
                }
            } else {
                $raw = $from;
                $pattern = '^' . preg_quote($raw, '/') . '/?$';
            }

            if ($type === 410) {
                $lines[] = "RewriteRule {$pattern} - [G,L]";
            } else {
                $flag = ($type === 302) ? 'R=302' : 'R=301';
                $lines[] = "RewriteRule {$pattern} {$target} [{$flag},L,NE,QSA]";
            }
        }

        $lines[] = self::HTACCESS_END;
        return implode("\n", $lines);
    }

    public static function is_apache_like(): bool
    {
        $server = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower($_SERVER['SERVER_SOFTWARE']) : '';
        return (strpos($server, 'apache') !== false || strpos($server, 'litespeed') !== false);
    }

    public static function get_htaccess_path(): string
    {
        $base = defined('ABSPATH') ? ABSPATH : dirname(__FILE__, 3) . DIRECTORY_SEPARATOR;

        if (!function_exists('get_home_path') && defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/file.php')) {
            @require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        if (function_exists('get_home_path')) {
            $home = get_home_path();
            if (!empty($home))
                $base = $home;
        }

        $base = rtrim(str_replace('\\', '/', $base), '/');
        return $base . '/.htaccess';
    }
}

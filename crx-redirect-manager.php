<?php
/**
 * Plugin Name: CRX Redirect Manager
 * Description: Create and manage all types of 301, 302, and 405 redirects with the ability to select the execution engine
 * Version: 2.1.0
 * Author: KIAN KIANI
 * License: GPLv2 or later
 * Text Domain: crx-redirect
 */

if (!defined('ABSPATH')) {
    exit;
}

// ===== Includes (engines) =====
$__crx_inc = plugin_dir_path(__FILE__) . 'includes/';
require_once $__crx_inc . 'class-crx-engine-htaccess.php';
require_once $__crx_inc . 'class-crx-engine-php.php';

class CRX_Redirect_Manager
{
    const OPTION_KEY = 'crx_redirect_rules';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'handle_admin_post']);

        register_activation_hook(__FILE__, [__CLASS__, 'on_activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'on_deactivate']);

        add_action('template_redirect', function () {
            $rules = get_option(self::OPTION_KEY, []);
            CRX_Engine_PHP::maybe_redirect(is_array($rules) ? $rules : []);
        }, 0);
    }

    /* ======================= Admin UI ======================= */
    public function register_admin_page()
    {
        add_management_page(
            __('CRX Redirects', 'crx-redirect'),
            __('CRX Redirects', 'crx-redirect'),
            'manage_options',
            'crx-redirects',
            [$this, 'render_admin_page']
        );
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $rules = self::get_rules();
        $server = isset($_SERVER['SERVER_SOFTWARE']) ? sanitize_text_field($_SERVER['SERVER_SOFTWARE']) : 'unknown';
        $htaccess_path = CRX_Engine_Htaccess::get_htaccess_path();
        $ht_exists = file_exists($htaccess_path);
        $ht_writable = $ht_exists ? is_writable($htaccess_path) : is_writable(dirname($htaccess_path));
        ?>
        <div class="wrap">
            <?php if (isset($_GET['crx_written'])) {
                $ok = (int) $_GET['crx_written']; ?>
                <div class="notice notice-<?php echo $ok ? 'success' : 'warning'; ?>">
                    <p>
                        <?php if ($ok) { ?>
                            بلوک htaccess با موفقیت نوشته شد (مسیر: <code><?php echo esc_html($htaccess_path); ?></code>).
                        <?php } else { ?>
                            هشدار: بلوک htaccess نوشته نشد. سطح دسترسی فایل یا پشتیبانی Apache/LiteSpeed را بررسی کنید. مسیر هدف:
                            <code><?php echo esc_html($htaccess_path); ?></code>. قوانین PHP همچنان فعال هستند.
                        <?php } ?>
                    </p>
                </div>
            <?php } ?>

            <h1>CRX Redirect Manager</h1>
            <p>برای هر ریدایرکت انتخاب کنید با کدام موتور اجرا شود. قوانین htaccess در ابتدای <code>.htaccess</code> نوشته
                می‌شوند؛ قوانین PHP در وردپرس اجرا می‌شوند.</p>
            <p><strong>نوع سرور:</strong> <code><?php echo esc_html($server); ?></code> | <strong>مسیر .htaccess:</strong>
                <code><?php echo esc_html($htaccess_path); ?></code> | <strong>وضعیت:</strong>
                <?php echo CRX_Engine_Htaccess::is_apache_like() ? ($ht_writable ? '<span style="color:green">قابل استفاده</span>' : '<span style="color:#d63638">ممکن است محدود باشد</span>') : '<span style="color:#d63638">نامعتبر برای htaccess</span>'; ?>
            </p>

            <hr />
            <h2>افزودن ریدایرکت جدید</h2>
            <form method="post">
                <?php wp_nonce_field('crx_redirect_save', 'crx_nonce'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="crx_from">From (مبدأ)</label></th>
                        <td>
                            <input type="text" id="crx_from" name="crx_from" class="regular-text"
                                placeholder="/about-us یا https://example.com/about-us یا regex:^blog/(.*)$" required>
                            <p class="description">برای الگوی Regex با <code>regex:</code> شروع کنید. مثال:
                                <code>regex:^blog/(.*)$</code>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="crx_to">To (مقصد)</label></th>
                        <td>
                            <input type="text" id="crx_to" name="crx_to" class="regular-text"
                                placeholder="/contact-us/ یا https://example.com/new-path">
                            <p class="description">برای 410 خالی بگذارید.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Type (نوع)</th>
                        <td>
                            <select name="crx_type" id="crx_type">
                                <option value="301">301 (Permanent)</option>
                                <option value="302">302 (Temporary)</option>
                                <option value="410">410 (Gone)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Engine (موتور اجرا)</th>
                        <td>
                            <select name="crx_engine" id="crx_engine">
                                <option value="htaccess">.htaccess (وب‌سرور)</option>
                                <option value="php">PHP (وردپرس)</option>
                            </select>
                            <p class="description">قوانین htaccess سریع‌تر هستند؛ قوانین PHP برای محیط‌های فاقد دسترسی htaccess
                                مناسب‌اند.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="hidden" name="crx_action" value="add" />
                    <button type="submit" class="button button-primary">افزودن ریدایرکت</button>
                </p>
            </form>

            <h2>لیست ریدایرکت‌ها</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>From</th>
                        <th>To</th>
                        <th>Type</th>
                        <th>Regex?</th>
                        <th>Engine</th>
                        <th>اقدام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rules)): ?>
                        <tr>
                            <td colspan="7">موردی ثبت نشده است.</td>
                        </tr>
                    <?php else:
                        foreach ($rules as $i => $r): ?>
                            <tr>
                                <td><?php echo (int) $i + 1; ?></td>
                                <td><code><?php echo esc_html($r['from']); ?></code></td>
                                <td><code><?php echo isset($r['to']) ? esc_html($r['to']) : ''; ?></code></td>
                                <td><?php echo isset($r['type']) ? (int) $r['type'] : 301; ?></td>
                                <td><?php echo !empty($r['regex']) ? 'بله' : 'خیر'; ?></td>
                                <td><?php echo isset($r['engine']) ? esc_html($r['engine']) : 'htaccess'; ?></td>
                                <td>
                                    <form method="post" style="display:inline">
                                        <?php wp_nonce_field('crx_redirect_save', 'crx_nonce'); ?>
                                        <input type="hidden" name="crx_action" value="delete" />
                                        <input type="hidden" name="idx" value="<?php echo (int) $i; ?>" />
                                        <button class="button button-secondary" onclick="return confirm('حذف شود؟')">حذف</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                </tbody>
            </table>

            <form method="post" style="margin-top:16px">
                <?php wp_nonce_field('crx_redirect_save', 'crx_nonce'); ?>
                <input type="hidden" name="crx_action" value="rebuild_htaccess" />
                <button class="button">بازسازی .htaccess</button>
            </form>
        </div>
        <?php
    }

    public function handle_admin_post()
    {
        if (!isset($_POST['crx_action']))
            return;
        if (!current_user_can('manage_options'))
            return;
        check_admin_referer('crx_redirect_save', 'crx_nonce');

        $action = sanitize_text_field(wp_unslash($_POST['crx_action']));
        $rules = self::get_rules();

        if ($action === 'add') {
            $from = isset($_POST['crx_from']) ? trim(wp_unslash($_POST['crx_from'])) : '';
            $to = isset($_POST['crx_to']) ? trim(wp_unslash($_POST['crx_to'])) : '';
            $type = isset($_POST['crx_type']) ? (int) $_POST['crx_type'] : 301;
            $engine = isset($_POST['crx_engine']) ? sanitize_text_field($_POST['crx_engine']) : 'htaccess';
            $engine = ($engine === 'php') ? 'php' : 'htaccess';

            if ($from === '')
                return;

            $is_regex = false;
            if (stripos($from, 'regex:') === 0) {
                $is_regex = true;
                $from = substr($from, 6);
            }

            $rules[] = [
                'from' => self::normalize_path($from),
                'to' => (int) $type === 410
                    ? ''
                    : (preg_match('#^https?://#i', $to) ? esc_url_raw($to) : self::normalize_path($to)),
                'type' => in_array((int) $type, [301, 302, 410], true) ? (int) $type : 301,
                'regex' => $is_regex ? 1 : 0,
                'engine' => $engine,
            ];

            update_option(self::OPTION_KEY, $rules);
            $written = CRX_Engine_Htaccess::write_block($rules);
            wp_safe_redirect(add_query_arg(['crx_added' => 1, 'crx_written' => (int) $written], admin_url('tools.php?page=crx-redirects')));
            exit;
        } elseif ($action === 'delete') {
            $idx = isset($_POST['idx']) ? (int) $_POST['idx'] : -1;
            if ($idx >= 0 && isset($rules[$idx])) {
                unset($rules[$idx]);
                $rules = array_values($rules);
                update_option(self::OPTION_KEY, $rules);
                $written = CRX_Engine_Htaccess::write_block($rules);
                wp_safe_redirect(add_query_arg(['crx_deleted' => 1, 'crx_written' => (int) $written], admin_url('tools.php?page=crx-redirects')));
                exit;
            }
        } elseif ($action === 'rebuild_htaccess') {
            $written = CRX_Engine_Htaccess::write_block($rules);
            wp_safe_redirect(add_query_arg(['crx_rebuilt' => 1, 'crx_written' => (int) $written], admin_url('tools.php?page=crx-redirects')));
            exit;
        }
    }

    /* ======================= Core helpers ======================= */
    public static function get_rules()
    {
        $rules = get_option(self::OPTION_KEY, []);
        return is_array($rules) ? $rules : [];
    }

    public static function normalize_path($path)
    {
        $path = trim($path);
        if ($path === '')
            return '';

        $path = rawurldecode($path);

        $parsed = wp_parse_url($path);
        if (isset($parsed['path'])) {
            $path = $parsed['path'];
        }

        $home_path = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if ($home_path && $home_path !== '/') {
            $hp = '/' . trim($home_path, '/') . '/';
            $pp = '/' . ltrim($path, '/');
            if (strpos($pp, $hp) === 0) {
                $path = '/' . ltrim(substr($pp, strlen($hp)), '/');
            }
        }

        if ($path === '') {
            $path = '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $path = preg_replace('~//+~', '/', $path);

        return $path;
    }


    /* ======================= Lifecycle ======================= */
    public static function on_activate()
    {
        $rules = self::get_rules();
        CRX_Engine_Htaccess::write_block($rules);
    }

    public static function on_deactivate()
    {
        CRX_Engine_Htaccess::remove_block();
    }
}

new CRX_Redirect_Manager();

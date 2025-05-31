<?php
/**
 * Plugin Name: Auto Permalink Refresh
 * Description: هر ۲ ساعت بطور خودکار دکمه ذخیره تغییرات را در تنظیمات پیوند یکتا کلیک می‌کند
 * Version: 1.0.0
 * Author: FarnadTech
 * Author URI: https://farnadtech.com/
 * Text Domain: auto-permalink-refresh
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 *
 * Auto Permalink Refresh is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Auto Permalink Refresh is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Auto Permalink Refresh. If not, see http://www.gnu.org/licenses/gpl-2.0.txt.
 */

// اگر به طور مستقیم فراخوانی شود، خروج
if (!defined('ABSPATH')) {
    exit;
}

/**
 * بارگیری فایل‌های ترجمه
 */
function apr_load_textdomain() {
    load_plugin_textdomain('auto-permalink-refresh', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'apr_load_textdomain');

/**
 * هنگام فعال‌سازی افزونه، زمان‌بندی را تنظیم می‌کند
 */
register_activation_hook(__FILE__, 'apr_activate');
function apr_activate() {
    // بازنشانی کرون برای اطمینان
    wp_clear_scheduled_hook('apr_refresh_permalinks_event');
    
    // زمان‌بندی برای اجرای هر ۲ ساعت
    if (!wp_next_scheduled('apr_refresh_permalinks_event')) {
        wp_schedule_event(time(), 'apr_two_hours', 'apr_refresh_permalinks_event');
    }
    
    // اضافه کردن رویداد برای تست اولیه
    if (!wp_next_scheduled('apr_initial_test_event')) {
        wp_schedule_single_event(time() + 60, 'apr_initial_test_event');
    }
    
    // ذخیره زمان تنظیم شده برای نمایش در تنظیمات
    update_option('apr_next_refresh', wp_next_scheduled('apr_refresh_permalinks_event'));
    
    // ثبت گزارش در لاگ
    error_log('Auto Permalink Refresh: Plugin activated. First refresh will occur in 1 minute.');
}

/**
 * هنگام غیرفعال‌سازی افزونه، زمان‌بندی را حذف می‌کند
 */
register_deactivation_hook(__FILE__, 'apr_deactivate');
function apr_deactivate() {
    // حذف زمان‌بندی اجرای خودکار
    wp_clear_scheduled_hook('apr_refresh_permalinks_event');
    wp_clear_scheduled_hook('apr_initial_test_event');
    
    // ثبت گزارش در لاگ
    error_log('Auto Permalink Refresh: Plugin deactivated.');
}

/**
 * اضافه کردن بازه زمانی سفارشی ۲ ساعت
 */
add_filter('cron_schedules', 'apr_add_cron_interval');
function apr_add_cron_interval($schedules) {
    $schedules['apr_two_hours'] = array(
        'interval' => 2 * HOUR_IN_SECONDS,
        'display'  => esc_html__('هر ۲ ساعت', 'auto-permalink-refresh')
    );
    return $schedules;
}

/**
 * اجرای عملیات به روزرسانی پیوند یکتا (هر ۲ ساعت)
 */
add_action('apr_refresh_permalinks_event', 'apr_refresh_permalinks');

/**
 * تست اولیه بعد از فعال‌سازی (۱ دقیقه بعد از فعال‌سازی)
 */
add_action('apr_initial_test_event', 'apr_refresh_permalinks');

/**
 * تابع اصلی که پیوندهای یکتا را با روش امن به‌روز می‌کند
 * این تابع از روش بازنویسی مستقیم برای بروز کردن پیوندها استفاده می‌کند
 * بدون نیاز به تقلید کاربر مدیر یا درخواست HTTP
 */
function apr_refresh_permalinks() {
    // دریافت ساختار فعلی پیوند یکتا
    $current_structure = get_option('permalink_structure');
    
    // سعی در اجرای دو روش مختلف برای بازنویسی پیوندها
    
    // روش ۱: لود و ذخیره‌ی مجدد تنظیمات پیوند یکتا بدون تغییر
    update_option('permalink_structure', $current_structure);
    
    // روش ۲: بازنویسی قوانین مسیریابی وردپرس
    flush_rewrite_rules(true);
    
    // روش ۳: فراخوانی تابع داخلی وردپرس برای به‌روزرسانی تنظیمات پیوند یکتا
    global $wp_rewrite;
    if ($wp_rewrite) {
        $wp_rewrite->init();
        $wp_rewrite->flush_rules(true);
    }
    
    // ثبت گزارش در لاگ
    $time = current_time('mysql');
    error_log("Auto Permalink Refresh: Permalinks successfully refreshed at $time");
    
    // ذخیره زمان آخرین به‌روزرسانی
    update_option('apr_last_refresh', $time);
    
    // بررسی و ذخیره زمان بعدی به‌روزرسانی
    $next_time = wp_next_scheduled('apr_refresh_permalinks_event');
    if (!$next_time) {
        // اگر رویداد بعدی تنظیم نشده، آن را دوباره تنظیم کنید
        wp_schedule_event(time() + 2 * HOUR_IN_SECONDS, 'apr_two_hours', 'apr_refresh_permalinks_event');
        $next_time = wp_next_scheduled('apr_refresh_permalinks_event');
    }
    update_option('apr_next_refresh', $next_time);
    
    // افزودن پیام اطلاع‌رسانی در پنل مدیریت (فقط برای مرتبه بعدی بازدید از پنل مدیریت)
    set_transient('apr_permalink_refreshed', $time, DAY_IN_SECONDS);
}

/**
 * افزودن پیام اطلاع‌رسانی در پنل مدیریت
 */
add_action('admin_notices', 'apr_admin_notices');
function apr_admin_notices() {
    // بررسی اگر پیام‌های موقت وجود دارد
    $refresh_time = get_transient('apr_permalink_refreshed');
    
    if ($refresh_time) {
        // نمایش پیام
        echo '<div class="notice notice-success is-dismissible">';
        echo '<p>' . sprintf(__('پیوندهای یکتا در تاریخ %s به‌روزرسانی شدند.', 'auto-permalink-refresh'), $refresh_time) . '</p>';
        echo '</div>';
        
        // حذف پیام موقت پس از نمایش
        delete_transient('apr_permalink_refreshed');
    }
}

/**
 * اضافه کردن صفحه تنظیمات در بخش تنظیمات
 */
add_action('admin_menu', 'apr_add_settings_page');
function apr_add_settings_page() {
    add_options_page(
        __('تنظیمات به‌روزرسانی خودکار پیوندها', 'auto-permalink-refresh'),
        __('به‌روزرسانی خودکار پیوندها', 'auto-permalink-refresh'),
        'manage_options',
        'auto-permalink-refresh',
        'apr_settings_page'
    );
}

/**
 * بررسی وضعیت کرون وردپرس و برنامه‌ریزی مجدد در صورت نیاز
 */
add_action('admin_init', 'apr_check_cron_status');
function apr_check_cron_status() {
    // بررسی فقط در صفحه تنظیمات افزونه
    if (isset($_GET['page']) && $_GET['page'] == 'auto-permalink-refresh') {
        // بررسی اگر رویداد زمانبندی شده وجود ندارد
        if (!wp_next_scheduled('apr_refresh_permalinks_event')) {
            // زمانبندی مجدد
            wp_schedule_event(time(), 'apr_two_hours', 'apr_refresh_permalinks_event');
            // ذخیره زمان بعدی
            update_option('apr_next_refresh', wp_next_scheduled('apr_refresh_permalinks_event'));
        }
    }
}

/**
 * نمایش صفحه تنظیمات افزونه
 */
function apr_settings_page() {
    // دکمه به‌روزرسانی دستی
    if (isset($_POST['apr_manual_refresh']) && check_admin_referer('apr_manual_refresh_action')) {
        apr_refresh_permalinks();
        echo '<div class="notice notice-success"><p>' . __('پیوندهای یکتا با موفقیت به‌روزرسانی شدند.', 'auto-permalink-refresh') . '</p></div>';
    }
    
    // دکمه بازنشانی کرون
    if (isset($_POST['apr_reset_cron']) && check_admin_referer('apr_manual_refresh_action')) {
        wp_clear_scheduled_hook('apr_refresh_permalinks_event');
        wp_schedule_event(time(), 'apr_two_hours', 'apr_refresh_permalinks_event');
        update_option('apr_next_refresh', wp_next_scheduled('apr_refresh_permalinks_event'));
        echo '<div class="notice notice-success"><p>' . __('زمانبندی به‌روزرسانی خودکار با موفقیت بازنشانی شد.', 'auto-permalink-refresh') . '</p></div>';
    }
?>
    <div class="wrap">
        <h1><?php echo esc_html__('تنظیمات به‌روزرسانی خودکار پیوندها', 'auto-permalink-refresh'); ?></h1>
        
        <form method="post" action="">
            <?php wp_nonce_field('apr_manual_refresh_action'); ?>
            
            <p><?php echo esc_html__('این افزونه هر ۲ ساعت به صورت خودکار پیوندهای یکتای سایت شما را به‌روز می‌کند.', 'auto-permalink-refresh'); ?></p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('آخرین به‌روزرسانی:', 'auto-permalink-refresh'); ?></th>
                    <td>
                        <strong><?php echo esc_html(get_option('apr_last_refresh', __('هنوز انجام نشده', 'auto-permalink-refresh'))); ?></strong>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('به‌روزرسانی بعدی در:', 'auto-permalink-refresh'); ?></th>
                    <td>
                        <?php 
                        $next = get_option('apr_next_refresh');
                        if ($next) {
                            $time_diff = $next - time();
                            if ($time_diff > 0) {
                                $hours = floor($time_diff / 3600);
                                $minutes = floor(($time_diff % 3600) / 60);
                                echo '<strong>' . sprintf(__('%d ساعت و %d دقیقه دیگر', 'auto-permalink-refresh'), $hours, $minutes) . '</strong>';
                                echo ' (' . date_i18n('Y-m-d H:i:s', $next) . ')';
                            } else {
                                echo '<strong>' . __('در حال انتظار برای بازدید بعدی سایت', 'auto-permalink-refresh') . '</strong>';
                            }
                        } else {
                            echo '<strong>' . __('نامشخص', 'auto-permalink-refresh') . '</strong>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__('وضعیت کرون وردپرس:', 'auto-permalink-refresh'); ?></th>
                    <td>
                        <?php
                        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
                            echo '<span style="color: red;">' . __('WP-Cron غیرفعال شده است. افزونه به صورت خودکار اجرا نخواهد شد.', 'auto-permalink-refresh') . '</span>';
                        } else {
                            echo '<span style="color: green;">' . __('WP-Cron فعال است.', 'auto-permalink-refresh') . '</span>';
                        }
                        ?>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="apr_manual_refresh" class="button button-primary" value="<?php echo esc_attr__('به‌روزرسانی دستی پیوندها', 'auto-permalink-refresh'); ?>" />
                <input type="submit" name="apr_reset_cron" class="button" value="<?php echo esc_attr__('بازنشانی زمانبندی خودکار', 'auto-permalink-refresh'); ?>" />
            </p>
        </form>

        <div class="card" style="max-width: 600px; margin-top: 20px; padding: 10px 20px; background-color: #f8f9fa; border: 1px solid #ddd;">
            <h3><?php echo esc_html__('اطلاعات مهم درباره WP-Cron', 'auto-permalink-refresh'); ?></h3>
            <p><?php echo esc_html__('سیستم زمانبندی وردپرس (WP-Cron) فقط زمانی اجرا می‌شود که سایت شما بازدید داشته باشد. اگر سایت شما بازدید کم دارد، ممکن است به‌روزرسانی خودکار به تعویق بیفتد.', 'auto-permalink-refresh'); ?></p>
            <p><?php echo esc_html__('برای اطمینان از اجرای دقیق، می‌توانید از سرویس‌های مانیتورینگ خارجی استفاده کنید تا هر ساعت یکبار سایت شما را بازدید کنند.', 'auto-permalink-refresh'); ?></p>
        </div>
        
        <div class="card" style="max-width: 600px; margin-top: 20px; padding: 10px 20px; background-color: #fff; border: 1px solid #ddd;">
            <div style="display: flex; align-items: center; justify-content: space-between;">
                <div style="flex: 1;">
                    <h3><?php echo esc_html__('سازنده افزونه', 'auto-permalink-refresh'); ?></h3>
                    <p><?php echo esc_html__('این افزونه توسط شرکت FarnadTech طراحی و توسعه یافته است.', 'auto-permalink-refresh'); ?></p>
                    <p><a href="https://farnadtech.com/" target="_blank" style="text-decoration: none; color: #0073aa; font-weight: bold;"><?php echo esc_html__('مشاهده وب‌سایت سازنده', 'auto-permalink-refresh'); ?></a></p>
                </div>
                <?php 
                $logo_path = plugin_dir_path(__FILE__) . 'assets/farnadtech-logo.png';
                $logo_url = plugins_url('assets/farnadtech-logo.png', __FILE__);
                if (file_exists($logo_path)): 
                ?>
                <div style="flex: 0 0 150px; margin-left: 20px;">
                    <img src="<?php echo esc_url($logo_url); ?>" alt="FarnadTech" style="max-width: 100%; height: auto;">
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php
}

/**
 * افزودن دکمه بازنشانی کرون در لیست افزونه‌ها
 */
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'apr_plugin_action_links');
function apr_plugin_action_links($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=auto-permalink-refresh') . '">' . __('تنظیمات', 'auto-permalink-refresh') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
} 
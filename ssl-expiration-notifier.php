<?php
/*
Plugin Name: SSL Expiration Notifier
Description: Sends an email notification if the SSL certificate expiration is within a week.
Version: 1.0
Author: Emre Ece
Text Domain: ssl-expiration-notifier
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Eklenti aktive edildiğinde çağrılan fonksiyon
function sen_activate() {
    // "logs" klasörünü oluştur
    $upload_dir = wp_upload_dir();
    $logs_folder = $upload_dir['basedir'] . '/ssl-expiration-notifier-logs';

    if (!file_exists($logs_folder)) {
        wp_mkdir_p($logs_folder);
    }
}
register_activation_hook(__FILE__, 'sen_activate');

// Eklenti ayarlarını kaydeden fonksiyon
function sen_options_page() {
    add_options_page('SSL Expiration Notifier Settings', 'SSL Expiration Notifier', 'manage_options', 'sen-settings', 'sen_render_options_page');
}

// Ayar sayfasını oluşturan fonksiyon
function sen_render_options_page() {
    ?>
    <div class="wrap">
        <h2><?php echo esc_html(get_admin_page_title()); ?></h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('sen_options');
            do_settings_sections('sen-settings');
            submit_button();
            ?>
        </form>
        <h2><?php esc_html_e('Log', 'ssl-expiration-notifier'); ?></h2>
        <?php echo '<p>' . __('You can view recorded logs.', 'ssl-expiration-notifier') . '</p>'; ?>
        <?php sen_render_log_field(); ?>
    </div>
    <?php
}

// Ayarları kaydeden ve gönderen fonksiyon
function sen_setup_settings() {
    register_setting('sen_options', 'sen_settings', 'sen_sanitize_settings');

    add_settings_section('sen_main_section', __('Main Settings', 'ssl-expiration-notifier'), 'sen_section_text', 'sen-settings');

    add_settings_field('sen_warning_days', __('Days Before Expiration', 'ssl-expiration-notifier'), 'sen_warning_days_input', 'sen-settings', 'sen_main_section');
}

// Ayar sayfasında görünen metin
function sen_section_text() {
    echo '<p>' . __('Configure SSL Expiration Notifier settings.', 'ssl-expiration-notifier') . '</p>';
}

// Ayar sayfasındaki "Days Before Expiration" alanı
function sen_warning_days_input() {
    $options = get_option('sen_settings');
    $days = isset($options['warning_days']) ? esc_attr($options['warning_days']) : '';

    echo '<input type="number" name="sen_settings[warning_days]" value="' . $days . '" />';
    echo '<p class="description">' . __('Set the number of days before SSL certificate expiration to send a notification.', 'ssl-expiration-notifier') . '</p>';
}

// Ayar sayfasındaki gün sayısını temizleyen fonksiyon
function sen_sanitize_settings($input) {
    $input['warning_days'] = absint($input['warning_days']);
    return $input;
}

// WordPress başlangıcında eklentiyi başlatan fonksiyon
function sen_plugin_init() {
    load_plugin_textdomain('ssl-expiration-notifier', false, dirname(plugin_basename(__FILE__)) . '/languages');
    add_action('admin_menu', 'sen_options_page');
    add_action('admin_init', 'sen_setup_settings');

    // SSL sertifikası kontrolü için günlük bir cron işlemi oluştur
    if (!wp_next_scheduled('sen_check_ssl_cert')) {
        wp_schedule_event(time(), 'daily', 'sen_check_ssl_cert');
    }
    add_action('sen_check_ssl_cert', 'sen_check_ssl_cert_function');
}

// SSL sertifikası kontrolünü gerçekleştiren fonksiyon
function sen_check_ssl_cert_function() {
    $options = get_option('sen_settings');
    $warning_days = isset($options['warning_days']) ? $options['warning_days'] : 7;

    $url = site_url();
    $context = stream_context_create([
        "ssl" => [
            "capture_peer_cert" => true
        ]
    ]);

    $stream = stream_socket_client(
        "ssl://" . parse_url($url, PHP_URL_HOST) . ":443",
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT,
        $context
    );

    if (!$stream) {
        return; // Hata durumunda işlemi sonlandır
    }

    $params = stream_context_get_params($stream);
    $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

       // SSL sertifikası bitiş tarihi kontrolü
       $expiration_date = $cert['validTo_time_t'];
       $current_time = time();
   
       // "logs" klasörüne günlük ekle
       $upload_dir = wp_upload_dir();
       $logs_folder = $upload_dir['basedir'] . '/ssl-expiration-notifier-logs';
       $log_file = $logs_folder . '/ssl-expiration-notifier-log.txt';
   
       if ($expiration_date - $current_time <= $warning_days * 24 * 60 * 60) {
           // Sertifika süresi dolmak üzere, uyarı e-postası gönder
           $subject = __('SSL Certificate Expiration Warning', 'ssl-expiration-notifier');
           $message = __('SSL certificate on your website will expire soon. Please renew it as soon as possible.', 'ssl-expiration-notifier');
   
           wp_mail(get_option('admin_email'), $subject, $message);
   
           // Logu dosyaya ekle
           $log_content = '[' . date('Y-m-d H:i:s') . '] SSL certificate expiration warning email sent.' . PHP_EOL;
           file_put_contents($log_file, $log_content, FILE_APPEND);
       } else {
              // Sertifika süresi dolmamış, loga ekle
              $log_content = '[' . date('Y-m-d H:i:s') . '] SSL certificate expiration warning email not sent.' . PHP_EOL;
              file_put_contents($log_file, $log_content, FILE_APPEND);
       }
       fclose($stream);
}

// Eklenti başlatıldığında sen_plugin_init fonksiyonunu çağır
add_action('plugins_loaded', 'sen_plugin_init');


// Eklenti ayarlar sayfasına log görüntüleme alanı ekleyen fonksiyon
function sen_render_log_field() {
    $upload_dir = wp_upload_dir();
    $logs_folder = $upload_dir['basedir'] . '/ssl-expiration-notifier-logs';
    $log_file = $logs_folder . '/ssl-expiration-notifier-log.txt';

    if (file_exists($log_file)) {
        $log_content = esc_textarea(file_get_contents($log_file));
        echo '<textarea rows="10" cols="50" disabled>' . $log_content . '</textarea>';
    } else {
        echo '<p>' . __('No log entries found.', 'ssl-expiration-notifier') . '</p>';
    }
}
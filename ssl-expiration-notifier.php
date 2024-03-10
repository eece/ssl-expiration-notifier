<?php
/*
Plugin Name: SSL Expiration Notifier
Description: Sends an email notification if the SSL certificate expiration is within a week.
Version: 1.0
Author: Emre Ece
Author URI:        https://emreece.com
Text Domain: ssl-expiration-notifier
License:           GPL v2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class SSL_Expiration_Notifier {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'plugin_settings'));
        add_action('plugins_loaded', array($this, 'init'));
        add_action('admin_enqueue_scripts', array($this, 'handle_ssl_checks_parameter'));
        add_action('admin_enqueue_scripts', array($this, 'load_assets'));
    }

    public function init() {
        load_plugin_textdomain('ssl-expiration-notifier', false, dirname(plugin_basename(__FILE__)) . '/languages');
        if (!wp_next_scheduled('ssl_expiration_notifier_check_ssl_cert')) {
            wp_schedule_event(time(), 'daily', 'ssl_expiration_notifier_check_ssl_cert');
        }
        add_action('ssl_expiration_notifier_check_ssl_cert', array($this, 'check_ssl_cert_function'));
    }

    public function load_assets() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'settings_page_sen-settings') {
        wp_enqueue_style('ssl-expiration-notifier', plugin_dir_url(__FILE__) . 'assets/css/ssl-expiration-notifier.css', array(), '1.0.0', 'all');
        }
    }

    public function add_plugin_page() {
        add_options_page('SSL Expiration Notifier Settings', 'SSL Expiration Notifier', 'manage_options', 'sen-settings', array($this, 'render_options_page'));
    }

    public function render_options_page() { ?>
        <div class="ssl-expiration-notifier-wrapper">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('ssl_expiration_notifier_options');
                do_settings_sections('sen-settings');
                submit_button();
                ?>
            </form>
            <?php echo '<p>' . esc_html__('You can check your SSL status now.', 'ssl-expiration-notifier') . '</p>'; ?>
            <a id="check_ssl_cert" class="button-primary" href="?page=sen-settings&checkSSL=1" /><?php echo esc_html__("Check SSL Certificate Now!", "ssl-expiration-notifier"); ?></a>
            <h2><?php esc_html_e('Logs', 'ssl-expiration-notifier'); ?></h2>
            <?php echo '<p>' . esc_html__('You can view recorded logs.', 'ssl-expiration-notifier') . '</p>'; ?>
            <?php $this->render_log_field(); ?>
            <div>
                <?php echo '<p>' . esc_html__('If you like this plugin, you can support me!', 'ssl-expiration-notifier') . '</p>'; ?>
                <a href="<?php echo esc_url('https://www.buymeacoffee.com/emreece'); ?>"><img src="<?php echo esc_url('https://img.buymeacoffee.com/button-api/?text=Support Me!&emoji=😇&slug=emreece&button_colour=FFDD00&font_colour=000000&font_family=Poppins&outline_colour=000000&coffee_colour=ffffff'); ?>" /></a>
            </div>
        </div>
        <?php
    }

    public function plugin_settings() {
        register_setting('ssl_expiration_notifier_options', 'ssl_expiration_notifier_settings', array($this, 'sanitize_settings'));

        add_settings_section('ssl_expiration_notifier_main_section', esc_html__('Main Settings', 'ssl-expiration-notifier'), array($this, 'section_text'), 'sen-settings');

        add_settings_field('ssl_expiration_notifier_warning_days', esc_html__('Days Before Expiration', 'ssl-expiration-notifier'), array($this, 'warning_days_input'), 'sen-settings', 'ssl_expiration_notifier_main_section');

        update_option('ssl_expiration_notifier_warning_days', '7');
    }

    public function section_text() {
        echo '<p>' . esc_html__('Configure SSL Expiration Notifier settings.', 'ssl-expiration-notifier') . '</p>';
    }


    public function warning_days_input() {
        $options = get_option('ssl_expiration_notifier_settings');
        $days = isset($options['warning_days']) ? esc_attr($options['warning_days']) : '';

        echo '<input type="number" name="ssl_expiration_notifier_settings[warning_days]" value="' . esc_attr($days) . '" />';
        echo '<p class="description">' . esc_html__('Set the number of days before SSL certificate expiration to send a notification.', 'ssl-expiration-notifier') . '</p>';
    }

    public function sanitize_settings($input) {
        $input['warning_days'] = absint($input['warning_days']);
        return $input;
    }

    public function check_ssl_cert_function() {
        $options = get_option('ssl_expiration_notifier_settings');
        $warning_days = isset($options['warning_days']) ? intval($options['warning_days']) : 7;
        $upload_dir = wp_upload_dir();
        $logs_folder = esc_url_raw($upload_dir['basedir'] . '/ssl-expiration-notifier-logs');
        $log_file = esc_url_raw($logs_folder . '/ssl-expiration-notifier-log.txt');
        $url = esc_url_raw(site_url());
        $context = stream_context_create([
            "ssl" => [
                "capture_peer_cert" => true
            ]
        ]);
        try {
            ini_set('display_errors', 'Off');
            error_reporting(E_ALL & ~E_WARNING);
            $stream = stream_socket_client(
                "ssl://" . parse_url($url, PHP_URL_HOST) . ":443",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if (!$stream) {
                $does_not_have_ssl = esc_html__("does not have SSL.", 'ssl-expiration-notifier');
                $log_content = '[' . esc_html(date('Y-m-d H:i:s')) . '] '.$url .' '.$does_not_have_ssl.'' . PHP_EOL;
                file_put_contents($log_file, $log_content, FILE_APPEND);
                return;
            }
        } catch (Exception $e) {
            return;
        }

        $params = stream_context_get_params($stream);
        $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);

        $expiration_date = $cert['validTo_time_t'];
        $current_time = time();
        if ($expiration_date - $current_time <= $warning_days * 24 * 60 * 60) {
            $subject = esc_html__('SSL Certificate Expiration Warning', 'ssl-expiration-notifier');
            $message = esc_html__('SSL certificate on your website will expire soon. Please renew it as soon as possible.', 'ssl-expiration-notifier');
            $logMessageMailSuccessful = esc_html__('SSL certificate expiration warning email sent.', 'ssl-expiration-notifier');

            wp_mail(get_option('admin_email'), $subject, $message);

            $log_content = '[' . esc_html(date('Y-m-d H:i:s')) . '] '.$logMessageMailSuccessful.'' . PHP_EOL;
            file_put_contents($log_file, $log_content, FILE_APPEND);
        } else {
            $logMessageMailError = esc_html__('SSL certificate expiration warning email sent.', 'ssl-expiration-notifier');
            $log_content = '[' . esc_html(date('Y-m-d H:i:s')) . '] '.$logMessageMailError.'' . PHP_EOL;
            file_put_contents($log_file, $log_content, FILE_APPEND);
        }
        fclose($stream);
        ini_set('display_errors', 'On');
        error_reporting(E_ALL);
    }

    public function render_log_field() {
        $upload_dir = wp_upload_dir();
        $logs_folder = esc_url_raw($upload_dir['basedir'] . '/ssl-expiration-notifier-logs');
        $log_file = esc_url_raw($logs_folder . '/ssl-expiration-notifier-log.txt');

        if (file_exists($log_file)) {
            $log_content = esc_textarea(file_get_contents($log_file));
            $allowed_html = array(
                'textarea' => array(
                    'rows' => array(),
                    'cols' => array(),
                    'disabled' => array(),
                    'style'=> array(),
                ),
            );
            echo wp_kses('<textarea rows="10" style="width:720px; max-width:100%;" disabled>' . esc_textarea($log_content) . '</textarea>', $allowed_html);
        } else {
            echo '<p>' . esc_html__('No log entries found.', 'ssl-expiration-notifier') . '</p>';
        }
    }

    public function handle_ssl_checks_parameter() {
        $screen = get_current_screen();
        if ($screen && $screen->id === 'settings_page_sen-settings' && isset($_GET['checkSSL'])) {
            $this->run_ssl_checks();
        }
    }

    public function run_ssl_checks() {
        $this->check_ssl_cert_function();
    }
}

new SSL_Expiration_Notifier();
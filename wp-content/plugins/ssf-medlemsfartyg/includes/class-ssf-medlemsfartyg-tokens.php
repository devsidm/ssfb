<?php
/**
 * Collection link tokens.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Medlemsfartyg_Tokens
{
    public function __construct()
    {
        add_action('init', array(__CLASS__, 'maybe_create_table'));
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        add_action('admin_notices', array($this, 'render_admin_notice'));
        add_action('admin_post_ssf_ship_generate_token', array($this, 'generate_from_admin'));
        add_action('admin_post_ssf_ship_send_token', array($this, 'send_from_admin'));
        add_action('admin_post_ssf_ship_revoke_token', array($this, 'revoke_from_admin'));
    }

    public function render_links_page(): void
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table_name() . ' ORDER BY id DESC LIMIT 100');
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Insamlingslänkar', 'ssf-medlemsfartyg'); ?></h1>
            <p><?php esc_html_e('Här visas de senaste tokenbaserade länkarna för insamling av fartygsuppgifter.', 'ssf-medlemsfartyg'); ?></p>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php esc_html_e('Fartyg', 'ssf-medlemsfartyg'); ?></th>
                        <th><?php esc_html_e('Mottagare', 'ssf-medlemsfartyg'); ?></th>
                        <th><?php esc_html_e('Status', 'ssf-medlemsfartyg'); ?></th>
                        <th><?php esc_html_e('Skapad', 'ssf-medlemsfartyg'); ?></th>
                        <th><?php esc_html_e('Skickad', 'ssf-medlemsfartyg'); ?></th>
                        <th><?php esc_html_e('Öppnad', 'ssf-medlemsfartyg'); ?></th>
                        <th><?php esc_html_e('Inskickad', 'ssf-medlemsfartyg'); ?></th>
                        <th><?php esc_html_e('Gäller till', 'ssf-medlemsfartyg'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row) : ?>
                        <tr>
                            <td><?php echo esc_html((string) $row->id); ?></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link((int) $row->ship_id)); ?>"><?php echo esc_html(get_the_title((int) $row->ship_id)); ?></a></td>
                            <td><?php echo esc_html($row->recipient_name . ' <' . $row->recipient_email . '>'); ?></td>
                            <td><?php echo esc_html($row->status); ?></td>
                            <td><?php echo esc_html($row->created_at); ?></td>
                            <td><?php echo esc_html($row->sent_at); ?></td>
                            <td><?php echo esc_html($row->opened_at); ?></td>
                            <td><?php echo esc_html($row->submitted_at); ?></td>
                            <td><?php echo esc_html($row->expires_at); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'ssf_ship_submission_tokens';
    }

    public static function maybe_create_table(): void
    {
        if ('0.2.0' === get_option('ssf_medlemsfartyg_db_version')) {
            return;
        }

        self::create_table();
        update_option('ssf_medlemsfartyg_db_version', '0.2.0');
    }

    public static function create_table(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        $table = self::table_name();
        dbDelta(
            "CREATE TABLE $table (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                ship_id bigint(20) unsigned NOT NULL,
                token_hash varchar(255) NOT NULL,
                recipient_email varchar(190) DEFAULT '' NOT NULL,
                recipient_name varchar(190) DEFAULT '' NOT NULL,
                status varchar(30) DEFAULT 'created' NOT NULL,
                created_at datetime NOT NULL,
                sent_at datetime NULL,
                opened_at datetime NULL,
                submitted_at datetime NULL,
                expires_at datetime NULL,
                created_by_user_id bigint(20) unsigned DEFAULT 0 NOT NULL,
                last_ip_hash varchar(255) DEFAULT '' NOT NULL,
                user_agent_hash varchar(255) DEFAULT '' NOT NULL,
                PRIMARY KEY  (id),
                KEY ship_id (ship_id),
                KEY token_hash (token_hash),
                KEY status (status)
            ) $charset_collate;"
        );
    }

    public static function hash_token(string $token): string
    {
        return hash_hmac('sha256', $token, wp_salt('auth'));
    }

    public static function create_token(int $ship_id, string $name = '', string $email = '', string $expires_at = ''): array
    {
        global $wpdb;
        $settings = SSF_Medlemsfartyg_Plugin::settings();
        $token = bin2hex(random_bytes(32));
        $expires_at = $expires_at ?: gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS * (int) $settings['token_days']);
        $wpdb->insert(
            self::table_name(),
            array(
                'ship_id' => $ship_id,
                'token_hash' => self::hash_token($token),
                'recipient_email' => sanitize_email($email),
                'recipient_name' => sanitize_text_field($name),
                'status' => 'created',
                'created_at' => current_time('mysql', true),
                'expires_at' => get_gmt_from_date($expires_at),
                'created_by_user_id' => get_current_user_id(),
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d')
        );

        return array('id' => (int) $wpdb->insert_id, 'token' => $token, 'url' => self::token_url($token));
    }

    public static function token_url(string $token): string
    {
        return add_query_arg('token', rawurlencode($token), home_url('/fartygsuppgifter/'));
    }

    public static function get_by_token(string $token): ?object
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE token_hash = %s', self::hash_token($token)));
        if (! $row) {
            return null;
        }
        if ('revoked' === $row->status || 'submitted' === $row->status) {
            return null;
        }
        if ($row->expires_at && strtotime($row->expires_at . ' UTC') < time()) {
            self::update_status((int) $row->id, 'expired');
            return null;
        }
        return $row;
    }

    public static function update_status(int $id, string $status): void
    {
        global $wpdb;
        $field = array('opened' => 'opened_at', 'sent' => 'sent_at', 'submitted' => 'submitted_at')[$status] ?? '';
        $data = array('status' => $status);
        if ($field) {
            $data[$field] = current_time('mysql', true);
        }
        $wpdb->update(self::table_name(), $data, array('id' => $id));
    }

    public static function latest_for_ship(int $ship_id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE ship_id = %d ORDER BY id DESC LIMIT 1', $ship_id));
    }

    public function add_meta_box(): void
    {
        add_meta_box('ssf_ship_collection', __('Insamling från fartygsombud', 'ssf-medlemsfartyg'), array($this, 'render_meta_box'), 'medlemsfartyg', 'side', 'high');
    }

    public function render_meta_box(WP_Post $post): void
    {
        $token = self::latest_for_ship($post->ID);
        $url = get_post_meta($post->ID, '_ssf_last_collection_url', true);
        $nonce = wp_create_nonce('ssf_ship_token_' . $post->ID);
        ?>
        <div class="ssf-ship-collection" data-action-url="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ship-id="<?php echo esc_attr((string) $post->ID); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" data-token-id="<?php echo esc_attr((string) ($token->id ?? 0)); ?>">
            <p><strong><?php esc_html_e('Nuvarande status:', 'ssf-medlemsfartyg'); ?></strong> <?php echo esc_html($token->status ?? __('Ingen länk', 'ssf-medlemsfartyg')); ?></p>
            <?php if ($token) : ?>
                <p><?php echo esc_html(sprintf(__('Mottagare: %s <%s>', 'ssf-medlemsfartyg'), $token->recipient_name, $token->recipient_email)); ?></p>
                <p><?php echo esc_html(sprintf(__('Gäller till: %s', 'ssf-medlemsfartyg'), $token->expires_at)); ?></p>
                <?php if ($url && ! in_array($token->status, array('revoked', 'expired', 'submitted'), true)) : ?>
                    <p><label><?php esc_html_e('Länk att kopiera', 'ssf-medlemsfartyg'); ?><input type="url" readonly onclick="this.select();" value="<?php echo esc_attr($url); ?>"></label></p>
                <?php endif; ?>
            <?php endif; ?>
            <label><?php esc_html_e('Mottagarens namn', 'ssf-medlemsfartyg'); ?><input class="ssf-token-recipient-name" type="text" value="<?php echo esc_attr($token->recipient_name ?? get_post_meta($post->ID, '_ssf_contact_name', true)); ?>"></label>
            <label><?php esc_html_e('Mottagarens e-post', 'ssf-medlemsfartyg'); ?><input class="ssf-token-recipient-email" type="email" value="<?php echo esc_attr($token->recipient_email ?? get_post_meta($post->ID, '_ssf_email', true)); ?>"></label>
            <label><?php esc_html_e('Utgångsdatum', 'ssf-medlemsfartyg'); ?><input class="ssf-token-expires-at" type="date" value="<?php echo esc_attr($token && $token->expires_at ? gmdate('Y-m-d', strtotime($token->expires_at . ' UTC')) : gmdate('Y-m-d', time() + DAY_IN_SECONDS * 30)); ?>"></label>
            <p><button type="button" class="button button-secondary" data-ssf-token-action="generate"><?php esc_html_e('Generera ny länk', 'ssf-medlemsfartyg'); ?></button></p>
            <?php if ($token) : ?>
                <p><button type="button" class="button button-primary" data-ssf-token-action="send"><?php esc_html_e('Skicka länk via e-post', 'ssf-medlemsfartyg'); ?></button></p>
                <p><button type="button" class="button-link-delete" data-ssf-token-action="revoke"><?php esc_html_e('Spärra länk', 'ssf-medlemsfartyg'); ?></button></p>
            <?php endif; ?>
        </div>
        <?php
    }

    public function generate_from_admin(): void
    {
        $ship_id = (int) ($_POST['ship_id'] ?? 0);
        $this->assert_admin_request($ship_id);
        $created = self::create_token($ship_id, (string) wp_unslash($_POST['recipient_name'] ?? ''), (string) wp_unslash($_POST['recipient_email'] ?? ''), (string) wp_unslash($_POST['expires_at'] ?? ''));
        update_post_meta($ship_id, '_ssf_last_collection_url', esc_url_raw($created['url']));
        $this->redirect_to_edit($ship_id, 'created');
        exit;
    }

    public function send_from_admin(): void
    {
        global $wpdb;
        $ship_id = (int) ($_POST['ship_id'] ?? 0);
        $token_id = (int) ($_POST['token_id'] ?? 0);
        $this->assert_admin_request($ship_id);
        $token = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table_name() . ' WHERE id = %d AND ship_id = %d', $token_id, $ship_id));
        $notice = 'invalid_recipient';
        if (! $token) {
            $notice = 'missing_token';
        } elseif (! is_email($token->recipient_email)) {
            $notice = 'invalid_recipient';
        } elseif (! in_array($token->status, array('created', 'sent'), true)) {
            $notice = 'unavailable_token';
        } elseif (! get_post_meta($ship_id, '_ssf_last_collection_url', true)) {
            $notice = 'missing_url';
        } else {
            $settings = SSF_Medlemsfartyg_Plugin::settings();
            $url = get_post_meta($ship_id, '_ssf_last_collection_url', true);
            $message = str_replace(
                array('[NAMN]', '[FARTYGSNAMN]', '[LÄNK]', '[DATUM]'),
                array($token->recipient_name, get_the_title($ship_id), $url, get_date_from_gmt($token->expires_at, 'Y-m-d')),
                $settings['invitation_text']
            );
            $sent = wp_mail($token->recipient_email, 'Uppdatera uppgifter om ' . get_the_title($ship_id) . ' till SSF', $message, array('Content-Type: text/plain; charset=UTF-8'));
            if ($sent) {
                self::update_status($token_id, 'sent');
                $notice = 'sent';
            } else {
                $notice = 'send_failed';
            }
        }
        $this->redirect_to_edit($ship_id, $notice);
        exit;
    }

    public function revoke_from_admin(): void
    {
        $ship_id = (int) ($_POST['ship_id'] ?? 0);
        $token_id = (int) ($_POST['token_id'] ?? 0);
        $this->assert_admin_request($ship_id);
        self::update_status($token_id, 'revoked');
        $this->redirect_to_edit($ship_id, 'revoked');
        exit;
    }

    public function render_admin_notice(): void
    {
        if (! current_user_can('manage_options') || empty($_GET['ssf_token_notice'])) {
            return;
        }

        $notice = sanitize_key((string) wp_unslash($_GET['ssf_token_notice']));
        $messages = array(
            'created' => array('success', __('En ny insamlingslänk har skapats.', 'ssf-medlemsfartyg')),
            'sent' => array('success', __('E-postmeddelandet har lämnats till e-posttjänsten för leverans.', 'ssf-medlemsfartyg')),
            'revoked' => array('success', __('Länken har spärrats.', 'ssf-medlemsfartyg')),
            'invalid_recipient' => array('error', __('Länken saknar en giltig mottagaradress.', 'ssf-medlemsfartyg')),
            'missing_token' => array('error', __('Kunde inte hitta den valda länken.', 'ssf-medlemsfartyg')),
            'unavailable_token' => array('error', __('Länken kan inte skickas eftersom den är spärrad, utgången eller redan inskickad.', 'ssf-medlemsfartyg')),
            'missing_url' => array('error', __('Länken saknar en aktiv formuläradress. Skapa en ny länk först.', 'ssf-medlemsfartyg')),
            'send_failed' => array('error', __('E-post kunde inte skickas. Kontrollera FluentSMTP:s e-postlogg och försök igen.', 'ssf-medlemsfartyg')),
        );

        if (! isset($messages[$notice])) {
            return;
        }

        printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($messages[$notice][0]), esc_html($messages[$notice][1]));
    }

    private function redirect_to_edit(int $ship_id, string $notice): void
    {
        wp_safe_redirect(add_query_arg(array('post' => $ship_id, 'action' => 'edit', 'ssf_token_notice' => $notice), admin_url('post.php')));
    }

    private function assert_admin_request(int $ship_id): void
    {
        if (! $ship_id || ! current_user_can('manage_options') || ! check_admin_referer('ssf_ship_token_' . $ship_id)) {
            wp_die(esc_html__('Du saknar behörighet.', 'ssf-medlemsfartyg'));
        }
    }
}

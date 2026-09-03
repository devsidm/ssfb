<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

use SSF\MemberPortal\Modules\Motions\MotionDeadline;

if (! defined('ABSPATH')) {
    exit;
}

final class Frontend
{
    private Module $meetings;
    private RegistrationService $registrations;
    private MotionDeadline $motions;

    public function __construct(Module $meetings, RegistrationService $registrations)
    {
        $this->meetings = $meetings;
        $this->registrations = $registrations;
        $this->motions = new MotionDeadline($meetings);
        add_shortcode('ssf_member_portal_annual_meeting', array($this, 'meeting_shortcode'));
        add_shortcode('ssf_member_portal_annual_meetings', array($this, 'archive_shortcode'));
        add_filter('the_content', array($this, 'append_archive_to_page'), 20);
        add_shortcode('ssf_member_portal_annual_meeting_registration', array($this, 'registration_shortcode'));
        add_action('admin_post_nopriv_ssf_member_portal_submit_meeting_registration', array($this, 'submit'));
        add_action('admin_post_ssf_member_portal_submit_meeting_registration', array($this, 'submit'));
        add_action('admin_post_nopriv_ssf_member_portal_cancel_meeting_registration', array($this, 'cancel'));
        add_action('admin_post_ssf_member_portal_cancel_meeting_registration', array($this, 'cancel'));
        add_action('admin_post_nopriv_ssf_member_portal_meeting_calendar', array($this, 'calendar'));
        add_action('admin_post_ssf_member_portal_meeting_calendar', array($this, 'calendar'));
        add_action('admin_post_nopriv_ssf_member_portal_annual_meeting_calendar_public', array($this, 'public_calendar'));
        add_action('admin_post_ssf_member_portal_annual_meeting_calendar_public', array($this, 'public_calendar'));
    }

    public function meeting_shortcode(): string
    {
        if (! $this->feature_enabled('annual_meetings')) {
            return '';
        }
        $meeting_post = $this->public_meeting();
        if (! $meeting_post) {
            return $this->message(__('Information om nästa årsmöte publiceras här.', 'ssf-member-portal'));
        }
        $meeting = $this->meetings->data($meeting_post->ID);
        if (! $this->is_publicly_configured($meeting_post, $meeting)) {
            return $this->message(__('Information om nästa årsmöte publiceras här.', 'ssf-member-portal'));
        }
        $registration_state = $this->registrations->registration_state($meeting, $meeting_post);
        $choices = (array) $registration_state['choices'];
        $choice_states = (array) $registration_state['choice_states'];
        $calendar_url = $this->meetings->calendar_url((int) $meeting['id']);
        $motion = $this->motions->state($meeting);
        ob_start();
        include SSF_MEMBER_PORTAL_PATH . 'templates/annual-meetings/meeting.php';
        return (string) ob_get_clean();
    }

    public function archive_shortcode(): string
    {
        if (! $this->feature_enabled('annual_meetings')) {
            return '';
        }
        $upcoming = array();
        $past = array();
        $now = current_datetime()->getTimestamp();
        $posts = get_posts(array('post_type' => Module::POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => -1, 'meta_key' => '_ssf_am_start_at', 'orderby' => 'meta_value_num', 'order' => 'ASC'));
        foreach ($posts as $post) {
            $meeting = $this->meetings->data((int) $post->ID);
            if (! $this->is_publicly_configured($post, $meeting)) {
                continue;
            }
            $item = array('post' => $post, 'meeting' => $meeting);
            if ((int) $meeting['start_at'] >= $now) {
                $upcoming[] = $item;
            } else {
                array_unshift($past, $item);
            }
        }
        ob_start();
        include SSF_MEMBER_PORTAL_PATH . 'templates/annual-meetings/archive.php';
        return (string) ob_get_clean();
    }

    public function append_archive_to_page(string $content): string
    {
        if (! $this->feature_enabled('annual_meetings')) {
            return $content;
        }
        $page_id = (int) get_option('ssf_member_portal_annual_meetings_archive_page_id', 0);
        if (! $page_id || ! is_main_query() || ! in_the_loop() || ! is_page($page_id)) {
            return $content;
        }
        $raw = (string) get_post_field('post_content', $page_id);
        return has_shortcode($raw, 'ssf_member_portal_annual_meetings') ? $content : $content . $this->archive_shortcode();
    }

    public function registration_shortcode(): string
    {
        if (! $this->feature_enabled('annual_meetings')) {
            return $this->message(__('Information om nästa årsmöte publiceras här.', 'ssf-member-portal'));
        }
        if (! $this->feature_enabled('annual_meeting_registration')) {
            return $this->message(__('Anmälan till middag och aktiviteter är inte öppen just nu.', 'ssf-member-portal'));
        }
        $meeting_post = $this->public_meeting();
        if (! $meeting_post) {
            return $this->message(__('Det finns ingen middag eller aktivitet att anmäla sig till just nu.', 'ssf-member-portal'));
        }
        $meeting = $this->meetings->data($meeting_post->ID);
        if (! $this->is_publicly_configured($meeting_post, $meeting)) {
            return $this->message(__('Det finns ingen middag eller aktivitet att anmäla sig till just nu.', 'ssf-member-portal'));
        }
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $registration_post = $token ? $this->registrations->find_by_token($token) : null;
        if ($registration_post && (int) $registration_post->post_parent !== (int) $meeting['id']) {
            $registration_post = null;
            $token = '';
        }
        $registration = $registration_post ? $this->registrations->details($registration_post->ID, $meeting) : array();
        $registration_state = $this->registrations->registration_state($meeting, $meeting_post, $registration_post ? (int) $registration_post->ID : 0);
        $choices = (array) $registration_state['choices'];
        $choice_states = (array) $registration_state['choice_states'];
        if ($registration_post && isset($_GET['ssf_am_confirmation'])) {
            ob_start();
            include SSF_MEMBER_PORTAL_PATH . 'templates/annual-meetings/confirmation.php';
            return (string) ob_get_clean();
        }
        $error = isset($_GET['ssf_am_error']) ? sanitize_text_field(wp_unslash($_GET['ssf_am_error'])) : '';
        ob_start();
        include SSF_MEMBER_PORTAL_PATH . 'templates/annual-meetings/form.php';
        return (string) ob_get_clean();
    }

    public function submit(): void
    {
        $redirect = $this->meetings->registration_url();
        if (! $this->feature_enabled('annual_meetings') || ! $this->feature_enabled('annual_meeting_registration')) {
            $this->redirect_error($redirect, __('Anmälan till middag och aktiviteter är inte öppen just nu.', 'ssf-member-portal'));
        }
        if (! isset($_POST['ssf_member_portal_meeting_registration_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_member_portal_meeting_registration_nonce'])), 'ssf_member_portal_submit_meeting_registration') || ! empty($_POST['website'])) {
            $this->redirect_error($redirect, __('Formuläret kunde inte verifieras. Försök igen.', 'ssf-member-portal'));
        }
        $ip = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $limit_key = 'ssf_am_rate_' . md5($ip);
        $attempts = (int) get_transient($limit_key);
        if ($attempts >= 8) {
            $this->redirect_error($redirect, __('För många försök. Vänta några minuter och försök igen.', 'ssf-member-portal'));
        }
        set_transient($limit_key, $attempts + 1, 10 * MINUTE_IN_SECONDS);

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : null;
        $result = $this->registrations->submit(wp_unslash($_POST), $token ?: null);
        if (is_wp_error($result)) {
            $this->redirect_error($token ? $this->meetings->registration_url(array('token' => rawurlencode($token))) : $redirect, $result->get_error_message());
        }
        delete_transient($limit_key);
        $url = $this->meetings->registration_url(array('token' => rawurlencode((string) $result['token']), 'ssf_am_confirmation' => 1));
        wp_safe_redirect($url);
        exit;
    }

    public function cancel(): void
    {
        if (! $this->feature_enabled('annual_meeting_registration')) {
            $this->redirect_error($this->meetings->registration_url(), __('Anmälan till middag och aktiviteter är inte öppen just nu.', 'ssf-member-portal'));
        }
        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $registration = $this->registrations->find_by_token($token);
        $url = $this->meetings->registration_url($token ? array('token' => rawurlencode($token)) : array());
        if (! $registration || ! isset($_POST['ssf_member_portal_meeting_cancel_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ssf_member_portal_meeting_cancel_nonce'])), 'ssf_member_portal_cancel_meeting_registration')) {
            $this->redirect_error($url, __('Avbokningen kunde inte verifieras.', 'ssf-member-portal'));
        }
        $result = $this->registrations->cancel($registration, $token);
        if (is_wp_error($result)) {
            $this->redirect_error($url, $result->get_error_message());
        }
        wp_safe_redirect(add_query_arg('ssf_am_cancelled', 1, $url));
        exit;
    }

    public function calendar(): void
    {
        if (! $this->feature_enabled('annual_meeting_registration')) {
            status_header(404);
            exit;
        }
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        $registration = $this->registrations->find_by_token($token);
        if (! $registration) {
            status_header(404);
            exit;
        }
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="ssf-arsmote.ics"');
        echo $this->registrations->calendar($registration); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function public_calendar(): void
    {
        if (! $this->feature_enabled('annual_meetings')) {
            status_header(404);
            exit;
        }
        $meeting_id = isset($_GET['meeting']) && is_scalar($_GET['meeting']) ? absint(wp_unslash($_GET['meeting'])) : 0;
        $post = $meeting_id ? get_post($meeting_id) : null;
        if (! $post || Module::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status) {
            status_header(404);
            exit;
        }
        $meeting = $this->meetings->data($meeting_id);
        if (! $this->is_publicly_configured($post, $meeting) || ! $this->meetings->module_enabled($meeting, 'calendar')) {
            status_header(404);
            exit;
        }
        nocache_headers();
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="ssf-arsmoteshelg-' . (int) $meeting['year'] . '.ics"');
        echo $this->registrations->calendar_for_meeting($meeting_id); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    public function date_range(array $meeting): string
    {
        if (empty($meeting['start_at'])) {
            return __('Datum meddelas senare', 'ssf-member-portal');
        }
        $start = wp_date('j F Y, H:i', (int) $meeting['start_at'], wp_timezone());
        return empty($meeting['end_at']) ? $start : $start . ' – ' . wp_date('j F Y, H:i', (int) $meeting['end_at'], wp_timezone());
    }

    public function render_question(array $question, array $answers): void
    {
        $key = (string) $question['key'];
        $value = $answers[$key] ?? '';
        $id = 'ssf-am-question-' . sanitize_html_class($key);
        $required = ! empty($question['required']);
        ?>
        <fieldset class="ssf-am-fieldset"><legend><?php echo esc_html($question['title']); ?><?php if ($required) : ?> <span aria-hidden="true">*</span><?php endif; ?></legend>
        <?php if (! empty($question['help'])) : ?><p class="ssf-am-help"><?php echo esc_html($question['help']); ?></p><?php endif; ?>
        <?php if ('info' === $question['type']) : ?><p><?php echo wp_kses_post(wpautop((string) $question['help'])); ?></p>
        <?php elseif ('textarea' === $question['type']) : ?><textarea id="<?php echo esc_attr($id); ?>" name="answers[<?php echo esc_attr($key); ?>]" rows="4" <?php required($required); ?>><?php echo esc_textarea((string) $value); ?></textarea>
        <?php elseif ('yes_no' === $question['type']) : ?>
            <?php foreach (array('yes' => __('Ja', 'ssf-member-portal'), 'no' => __('Nej', 'ssf-member-portal')) as $option => $label) : ?><label class="ssf-am-option"><input type="radio" name="answers[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($option); ?>" <?php checked($value, $option); ?> <?php required($required); ?>> <?php echo esc_html($label); ?></label><?php endforeach; ?>
        <?php elseif (in_array($question['type'], array('single', 'multiple', 'checkbox'), true)) : ?>
            <?php foreach ((array) $question['options'] as $option) : $multiple = in_array($question['type'], array('multiple', 'checkbox'), true); $checked = $multiple ? in_array($option, (array) $value, true) : $value === $option; ?><label class="ssf-am-option"><input type="<?php echo $multiple ? 'checkbox' : 'radio'; ?>" name="answers[<?php echo esc_attr($key); ?>]<?php echo $multiple ? '[]' : ''; ?>" value="<?php echo esc_attr($option); ?>" <?php checked($checked); ?>> <?php echo esc_html($option); ?></label><?php endforeach; ?>
        <?php else : ?><input id="<?php echo esc_attr($id); ?>" type="<?php echo 'date' === $question['type'] ? 'date' : 'text'; ?>" name="answers[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr((string) $value); ?>" <?php required($required); ?>>
        <?php endif; ?></fieldset>
        <?php
    }

    private function redirect_error(string $url, string $error): void
    {
        wp_safe_redirect(add_query_arg('ssf_am_error', rawurlencode($error), $url));
        exit;
    }

    private function is_publicly_configured(\WP_Post $post, array $meeting): bool
    {
        return (bool) trim($post->post_title) && ! empty($meeting['year']) && ! empty($meeting['start_at']);
    }

    private function public_meeting(): ?\WP_Post
    {
        if (! $this->feature_enabled('annual_meetings')) {
            return null;
        }
        $requested_id = isset($_GET['meeting']) && is_scalar($_GET['meeting']) ? absint(wp_unslash($_GET['meeting'])) : 0;
        if ($requested_id) {
            $requested = get_post($requested_id);
            if ($requested && Module::POST_TYPE === $requested->post_type && 'publish' === $requested->post_status) {
                return $requested;
            }
        }
        $active = $this->meetings->active();
        return $active && 'publish' === $active->post_status ? $active : null;
    }

    private function message(string $message): string
    {
        return '<section class="ssf-am-page"><p class="ssf-am-message">' . esc_html($message) . '</p></section>';
    }

    private function feature_enabled(string $feature): bool
    {
        return ! class_exists('SSF_Feature_Manager') || \SSF_Feature_Manager::can_access($feature);
    }
}

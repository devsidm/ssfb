<?php
if (! defined('ABSPATH')) { exit; }
$statuses = SSF_Medlemsprocess_Application::statuses();
$current = $statuses[$status];
$submitted_at = (string) get_post_meta($application->ID, '_ssf_submitted_at', true);
$external_comment = (string) get_post_meta($application->ID, '_ssf_sp_public_comment', true);
$status_events = array_filter($history, static function ($item) { return in_array($item['type'] ?? '', array('submitted', 'status'), true); });
?>
<section class="ssf-process-shell ssf-status-page">
    <div class="ssf-process-heading">
        <p class="ssf-process-eyebrow">Din ansökan till SSF</p>
        <h1><?php echo esc_html($data['ship_name'] ?? 'Fartygsansökan'); ?></h1>
        <p>Ansökningsnummer: <strong><?php echo esc_html(get_post_meta($application->ID, '_ssf_application_number', true)); ?></strong></p>
        <?php if ($submitted_at) : ?><p>Inkommen: <strong><?php echo esc_html(mysql2date('j F Y, H:i', $submitted_at)); ?></strong></p><?php endif; ?>
    </div>
    <div class="ssf-status-summary">
        <span class="ssf-status-badge"><?php echo esc_html($current['label']); ?></span>
        <p><?php echo esc_html($current['public']); ?></p>
        <?php if ($external_comment) : ?><div class="ssf-status-public-comment"><strong>Meddelande från SSF</strong><p><?php echo nl2br(esc_html($external_comment)); ?></p></div><?php endif; ?>
        <p><strong>Nästa steg:</strong> <?php echo esc_html((string) (get_post_meta($application->ID, '_ssf_next_action', true) ?: 'SSF återkommer när nästa steg är klart.')); ?></p>
    </div>
    <ol class="ssf-status-timeline" aria-label="Ansökans tidslinje"><?php foreach (array(1 => 'Inkommen', 2 => 'Under granskning', 3 => 'Komplettering', 4 => 'Inspektion', 5 => 'Beslut') as $step => $label) : ?><li class="<?php echo $current['step'] >= $step ? 'is-complete' : ''; ?> <?php echo $current['step'] === $step ? 'is-current' : ''; ?>"><span><?php echo esc_html((string) $step); ?></span><?php echo esc_html($label); ?></li><?php endforeach; ?></ol>
    <?php if ($status_events) : ?><section class="ssf-status-section"><h2>Statushistorik</h2><ol class="ssf-status-history"><?php foreach ($status_events as $item) : ?><li><time><?php echo esc_html(mysql2date('j F Y, H:i', $item['time'] ?? '')); ?></time><span><?php echo esc_html('submitted' === ($item['type'] ?? '') ? 'Ansökan inkommen' : ($item['message'] ?? 'Status uppdaterad')); ?></span></li><?php endforeach; ?></ol></section><?php endif; ?>
    <?php if (! empty($booking['date'])) : ?><section class="ssf-status-section"><h2>Bokad tid</h2><p><strong><?php echo esc_html($booking['date'] . ' ' . ($booking['start'] ?? '')); ?></strong><?php echo ! empty($booking['end']) ? esc_html(' - ' . $booking['end']) : ''; ?></p><p><?php echo esc_html($booking['location'] ?? ''); ?></p><?php if (! empty($booking['comment'])) : ?><p><?php echo esc_html($booking['comment']); ?></p><?php endif; ?></section><?php endif; ?>
    <?php $public_messages = array_filter($history, static function ($item) use ($external_comment) { return ! empty($item['public']) && ! in_array($item['type'] ?? '', array('submitted', 'status'), true) && trim((string) ($item['message'] ?? '')) !== trim($external_comment); }); if ($public_messages) : ?><section class="ssf-status-section"><h2>Meddelanden från SSF</h2><?php foreach (array_reverse($public_messages) as $item) : ?><article class="ssf-status-message"><p class="ssf-process-help"><?php echo esc_html(mysql2date('j F Y, H:i', $item['time'])); ?></p><p><?php echo nl2br(esc_html($item['message'])); ?></p></article><?php endforeach; ?></section><?php endif; ?>
    <?php if ('needs_completion' === $status) : ?><section class="ssf-status-section ssf-status-attention"><h2>Komplettering krävs</h2><p>Skriv ditt svar och bifoga filer om SSF har bett om det.</p><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_submit_completion"><input type="hidden" name="token" value="<?php echo esc_attr($token); ?>"><?php wp_nonce_field('ssf_application_completion_' . $application->ID); ?><label>Ditt svar<textarea name="completion_message" rows="5"></textarea></label><label>Filer <input type="file" name="ssf_completion_files[]" accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx" multiple></label><?php if (class_exists('SSF_Antispam')) { SSF_Antispam::render('application_completion'); } ?><button class="ssf-process-button" type="submit">Skicka komplettering</button></form></section><?php endif; ?>
    <?php $all_files = array_merge($files, $completion_files, $inspector_visible_files); if ($all_files) : ?><section class="ssf-status-section"><h2>Inskickade filer</h2><ul class="ssf-status-files"><?php foreach (array_unique($all_files) as $file_id) : ?><li><a href="<?php echo esc_url(wp_get_attachment_url($file_id)); ?>" target="_blank" rel="noopener"><?php echo esc_html(get_the_title($file_id)); ?></a></li><?php endforeach; ?></ul></section><?php endif; ?>
</section>

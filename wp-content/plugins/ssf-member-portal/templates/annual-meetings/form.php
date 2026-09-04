<?php
/** @var array $meeting */
/** @var array $registration */
/** @var array $choices */
/** @var array $choice_states */
/** @var array $registration_state */
/** @var string $token */
/** @var string $error */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
$editing = ! empty($registration['id']);
$value = static function (string $key, $default = '') use ($registration) { return $registration[$key] ?? $default; };
$selected_program = (array) $value('program', array());
$food_enabled = (bool) array_filter($choices, static function (array $choice): bool { return ! empty($choice['food']); });
$food_selected = (bool) array_filter($choices, static function (array $choice) use ($selected_program): bool {
    return ! empty($choice['food']) && ! empty($selected_program[$choice['key'] ?? '']);
});
?>
<section class="ssf-am-page" aria-labelledby="ssf-am-registration-heading">
    <header class="ssf-am-intro">
        <p class="ssf-am-eyebrow"><?php echo esc_html(get_the_title((int) $meeting['id'])); ?></p>
        <h1 id="ssf-am-registration-heading"><?php echo $editing ? esc_html__('Ändra din anmälan', 'ssf-member-portal') : esc_html__('Anmälan till årsmöteshelgen', 'ssf-member-portal'); ?></h1>
        <p class="ssf-am-dates"><?php echo esc_html($this->date_range($meeting)); ?><?php if ($meeting['location']) : ?> · <?php echo esc_html($meeting['location']); ?><?php endif; ?></p>
    </header>
    <div class="ssf-am-callout"><strong><?php esc_html_e('Anmäl gärna att du kommer.', 'ssf-member-portal'); ?></strong><span><?php esc_html_e('Det är inte ett krav för att delta i själva årsmötet, men det hjälper oss att planera helgen.', 'ssf-member-portal'); ?></span></div>
    <?php if ($error) : ?><p class="ssf-am-message ssf-am-message--error" role="alert" tabindex="-1" data-ssf-error-message><?php echo esc_html($error); ?></p><?php endif; ?>
    <?php if (isset($_GET['ssf_am_cancelled'])) : ?><p class="ssf-am-message ssf-am-message--success" role="status"><?php esc_html_e('Din anmälan är avbokad.', 'ssf-member-portal'); ?></p><?php endif; ?>

    <?php if (! $choices) : ?>
        <p class="ssf-am-message"><?php esc_html_e('Det finns ännu inget deltagande att anmäla.', 'ssf-member-portal'); ?></p>
    <?php elseif (empty($registration_state['can_register'])) : ?>
        <p class="ssf-am-message"><?php echo esc_html((string) ($registration_state['message'] ?? __('Anmälan till årsmöteshelgen är stängd.', 'ssf-member-portal'))); ?></p>
    <?php else : ?>
        <form class="ssf-am-form" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="ssf_member_portal_submit_meeting_registration">
            <?php wp_nonce_field('ssf_member_portal_submit_meeting_registration', 'ssf_member_portal_meeting_registration_nonce'); ?>
            <?php if ($editing) : ?><input type="hidden" name="token" value="<?php echo esc_attr($token); ?>"><?php endif; ?>
            <p class="ssf-am-honeypot" aria-hidden="true"><label><?php esc_html_e('Webbplats', 'ssf-member-portal'); ?><input type="text" name="website" tabindex="-1" autocomplete="off"></label></p>

            <fieldset><legend><?php esc_html_e('1. Dina uppgifter', 'ssf-member-portal'); ?></legend>
                <div class="ssf-am-grid">
                    <p><label for="ssf-am-first-name"><?php esc_html_e('Förnamn', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-am-first-name" name="first_name" autocomplete="given-name" required value="<?php echo esc_attr($value('first_name')); ?>"></p>
                    <p><label for="ssf-am-last-name"><?php esc_html_e('Efternamn', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-am-last-name" name="last_name" autocomplete="family-name" required value="<?php echo esc_attr($value('last_name')); ?>"></p>
                    <p><label for="ssf-am-email"><?php esc_html_e('E-postadress', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-am-email" type="email" name="email" autocomplete="email" required value="<?php echo esc_attr($value('email')); ?>"></p>
                    <p><label for="ssf-am-phone"><?php esc_html_e('Telefonnummer', 'ssf-member-portal'); ?></label><input id="ssf-am-phone" type="tel" name="phone" autocomplete="tel" value="<?php echo esc_attr($value('phone')); ?>"></p>
                </div>
            </fieldset>

            <fieldset><legend><?php esc_html_e('2. Din relation till SSF', 'ssf-member-portal'); ?></legend>
                <div class="ssf-am-relations" data-ssf-relations>
                    <label class="ssf-am-option"><input type="radio" name="relationship" value="representative" <?php checked($value('relationship'), 'representative'); ?> required> <?php esc_html_e('Fartygsombud', 'ssf-member-portal'); ?></label>
                    <label class="ssf-am-option"><input type="radio" name="relationship" value="supporter" <?php checked($value('relationship'), 'supporter'); ?>> <?php esc_html_e('Stödmedlem', 'ssf-member-portal'); ?></label>
                    <?php if ($meeting['allow_guest']) : ?><label class="ssf-am-option"><input type="radio" name="relationship" value="guest" <?php checked($value('relationship'), 'guest'); ?>> <?php esc_html_e('Annan eller inbjuden deltagare', 'ssf-member-portal'); ?></label><?php endif; ?>
                </div>
                <div class="ssf-am-conditional" data-ssf-relationship="representative"><p><label><?php esc_html_e('Fartygsombud för', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><span data-ssf-vessels><?php foreach ((array) $value('represented_vessels', array('')) as $vessel) : ?><span class="ssf-am-vessel"><input name="represented_vessels[]" value="<?php echo esc_attr($vessel); ?>" placeholder="<?php esc_attr_e('Fartygsnamn', 'ssf-member-portal'); ?>"><button type="button" class="ssf-am-icon-button" data-ssf-remove-vessel aria-label="<?php esc_attr_e('Ta bort fartyg', 'ssf-member-portal'); ?>">×</button></span><?php endforeach; ?></span><button class="ssf-am-text-button" type="button" data-ssf-add-vessel><?php esc_html_e('Lägg till ytterligare fartyg', 'ssf-member-portal'); ?></button></p></div>
                <div class="ssf-am-conditional" data-ssf-relationship="supporter"><label class="ssf-am-option"><input type="checkbox" name="has_associated_vessels" value="1" data-ssf-associated-toggle <?php checked($value('has_associated_vessels')); ?>> <?php esc_html_e('Jag har anknytning till något av SSF:s fartyg', 'ssf-member-portal'); ?></label><p data-ssf-associated-vessels><label><?php esc_html_e('Vilket eller vilka fartyg?', 'ssf-member-portal'); ?></label><span data-ssf-vessels><?php foreach ((array) $value('associated_vessels', array('')) as $vessel) : ?><span class="ssf-am-vessel"><input name="associated_vessels[]" value="<?php echo esc_attr($vessel); ?>" placeholder="<?php esc_attr_e('Fartygsnamn', 'ssf-member-portal'); ?>"><button type="button" class="ssf-am-icon-button" data-ssf-remove-vessel aria-label="<?php esc_attr_e('Ta bort fartyg', 'ssf-member-portal'); ?>">×</button></span><?php endforeach; ?></span><button class="ssf-am-text-button" type="button" data-ssf-add-vessel><?php esc_html_e('Lägg till fartyg', 'ssf-member-portal'); ?></button></p></div>
            </fieldset>

            <fieldset><legend><?php esc_html_e('3. Välj vad du deltar i', 'ssf-member-portal'); ?></legend>
                <p class="ssf-am-help"><?php esc_html_e('Själva årsmötet är frivilligt att anmäla. Middag och vissa aktiviteter behöver bokas.', 'ssf-member-portal'); ?></p>
                <?php foreach ($choices as $choice) :
                    $choice_key = (string) ($choice['key'] ?? '');
                    $is_annual_meeting = 'annual_meeting' === ($choice['source'] ?? '');
                    $selected = ! empty($selected_program[$choice_key]);
                    $state = $choice_states[$choice_key] ?? array();
                    $unavailable = empty($state['available']) && ! $selected;
                    $choice_meta = array_filter(array(
                        trim((string) ($choice['date'] ?? '') . ' ' . (string) ($choice['start'] ?? '')),
                        ! empty($choice['location']) ? (string) $choice['location'] : '',
                        ! empty($choice['price']) ? (string) $choice['price'] : '',
                    ));
                    if (! empty($state['available']) && ! empty($state['closes_at'])) {
                        $choice_meta[] = sprintf(__('Anmäl senast %s', 'ssf-member-portal'), wp_date('j F Y H:i', (int) $state['closes_at'], wp_timezone()));
                    }
                    if (! empty($state['capacity']) && ! empty($state['available'])) {
                        $choice_meta[] = sprintf(__('%1$d av %2$d platser bokade', 'ssf-member-portal'), (int) $state['count'], (int) $state['capacity']);
                    } elseif (empty($state['available']) && ! empty($state['message'])) {
                        $choice_meta[] = (string) $state['message'];
                    }
                    ?>
                    <label class="ssf-am-program-option <?php echo $is_annual_meeting ? 'ssf-am-program-option--meeting ' : ''; ?><?php echo $unavailable ? 'is-closed' : ''; ?>">
                        <?php if ($selected && ! empty($state['closed'])) : ?><input type="hidden" name="program[<?php echo esc_attr($choice_key); ?>]" value="1"><?php endif; ?>
                        <input type="checkbox" name="program[<?php echo esc_attr($choice_key); ?>]" value="1" <?php checked($selected); ?> <?php disabled($unavailable || ($selected && ! empty($state['closed']))); ?> <?php echo ! empty($choice['food']) ? 'data-ssf-food-choice="1"' : ''; ?>>
                        <span><strong><?php echo esc_html($choice['title']); ?></strong><?php if ($choice_meta) : ?><small><?php echo esc_html(implode(' · ', $choice_meta)); ?></small><?php endif; ?></span>
                        <?php if ($is_annual_meeting) : ?><span class="ssf-am-choice-note"><?php esc_html_e('Anmäl gärna', 'ssf-member-portal'); ?></span><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <?php if ($food_enabled && $meeting['food_options']) : ?><fieldset data-ssf-food-section <?php echo $food_selected ? '' : 'hidden'; ?>><legend><?php esc_html_e('4. Mat och specialkost', 'ssf-member-portal'); ?></legend><p class="ssf-am-help"><?php esc_html_e('Ange endast information som arrangören behöver för att kunna ordna maten.', 'ssf-member-portal'); ?></p><div class="ssf-am-options"><?php foreach ($meeting['food_options'] as $option) : ?><label class="ssf-am-option"><input type="checkbox" name="food[<?php echo esc_attr($option); ?>]" value="1" <?php checked(in_array($option, (array) $value('food', array()), true)); ?>> <?php echo esc_html($option); ?></label><?php endforeach; ?></div><p><label for="ssf-am-food-note"><?php esc_html_e('Annat eller information till köket', 'ssf-member-portal'); ?></label><textarea id="ssf-am-food-note" name="food_note" rows="3"><?php echo esc_textarea($value('food_note')); ?></textarea></p></fieldset><?php endif; ?>

            <?php if ($meeting['questions']) : ?><fieldset><legend><?php esc_html_e('5. Övriga frågor', 'ssf-member-portal'); ?></legend><?php foreach ($meeting['questions'] as $question) : if (! empty($question['visible'])) { $this->render_question($question, (array) $value('answers', array())); } endforeach; ?></fieldset><?php endif; ?>
            <fieldset class="ssf-am-submit"><legend><?php esc_html_e('Kontrollera och skicka', 'ssf-member-portal'); ?></legend><p><?php esc_html_e('Uppgifterna används för att administrera de praktiska arrangemangen kring SSF:s årsmöteshelg.', 'ssf-member-portal'); ?></p><button class="ssf-am-button" type="submit"><?php echo $editing ? esc_html__('Spara ändringar', 'ssf-member-portal') : esc_html__('Skicka anmälan', 'ssf-member-portal'); ?></button></fieldset>
        </form>
        <?php if ($editing && 'cancelled' !== $value('status')) : ?><form class="ssf-am-cancel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_member_portal_cancel_meeting_registration"><input type="hidden" name="token" value="<?php echo esc_attr($token); ?>"><?php wp_nonce_field('ssf_member_portal_cancel_meeting_registration', 'ssf_member_portal_meeting_cancel_nonce'); ?><button type="submit" class="ssf-am-text-button"><?php esc_html_e('Avboka anmälan', 'ssf-member-portal'); ?></button></form><?php endif; ?>
    <?php endif; ?>
</section>

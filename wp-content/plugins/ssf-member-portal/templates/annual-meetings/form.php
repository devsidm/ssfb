<?php
/** @var array $meeting */
/** @var array $registration */
/** @var array $choices */
/** @var array $choice_states */
/** @var string $token */
/** @var string $error */
/** @var SSF\MemberPortal\Modules\AnnualMeetings\Frontend $this */
$editing = ! empty($registration['id']);
$value = static function (string $key, $default = '') use ($registration) { return $registration[$key] ?? $default; };
$food_enabled = (bool) array_filter($choices, static function (array $choice): bool { return ! empty($choice['food']); });
?>
<section class="ssf-am-page" aria-labelledby="ssf-am-registration-heading">
    <header class="ssf-am-intro">
        <p class="ssf-am-eyebrow"><?php echo esc_html(get_the_title((int) $meeting['id'])); ?></p>
        <h1 id="ssf-am-registration-heading"><?php echo $editing ? esc_html__('Ändra val för middag och aktiviteter', 'ssf-member-portal') : esc_html__('Anmälan till middag och aktiviteter', 'ssf-member-portal'); ?></h1>
        <p class="ssf-am-dates"><?php echo esc_html($this->date_range($meeting)); ?><?php if ($meeting['location']) : ?> · <?php echo esc_html($meeting['location']); ?><?php endif; ?></p>
    </header>
    <div class="ssf-am-callout"><strong><?php esc_html_e('Du behöver inte anmäla dig till själva årsmötet.', 'ssf-member-portal'); ?></strong><span><?php esc_html_e('Formuläret gäller endast de praktiska arrangemang du väljer nedan.', 'ssf-member-portal'); ?></span></div>
    <?php if ($error) : ?><p class="ssf-am-message ssf-am-message--error" role="alert"><?php echo esc_html($error); ?></p><?php endif; ?>
    <?php if (isset($_GET['ssf_am_cancelled'])) : ?><p class="ssf-am-message ssf-am-message--success" role="status"><?php esc_html_e('Din anmälan är avbokad.', 'ssf-member-portal'); ?></p><?php endif; ?>

    <?php if (! $choices) : ?>
        <p class="ssf-am-message"><?php esc_html_e('Det finns ännu ingen middag eller aktivitet att anmäla sig till.', 'ssf-member-portal'); ?></p>
    <?php elseif (! $editing && ! $this->meetings->is_registration_open($meeting)) : ?>
        <p class="ssf-am-message"><?php esc_html_e('Anmälan till middag och aktiviteter är stängd.', 'ssf-member-portal'); ?></p>
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
                    <p><label for="ssf-am-phone"><?php esc_html_e('Telefonnummer', 'ssf-member-portal'); ?> <span aria-hidden="true">*</span></label><input id="ssf-am-phone" type="tel" name="phone" autocomplete="tel" required value="<?php echo esc_attr($value('phone')); ?>"></p>
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

            <fieldset><legend><?php esc_html_e('3. Välj middag och aktiviteter', 'ssf-member-portal'); ?></legend>
                <p class="ssf-am-help"><?php esc_html_e('Du kan göra flera val i samma anmälan.', 'ssf-member-portal'); ?></p>
                <?php foreach ($choices as $choice) : $selected = ! empty($value('program', array())[$choice['key']]); $state = $choice_states[$choice['key']] ?? array(); $unavailable = (! empty($state['closed']) || ! empty($state['full'])) && ! $selected; ?>
                    <label class="ssf-am-program-option <?php echo $unavailable ? 'is-closed' : ''; ?>">
                        <?php if ($selected && ! empty($state['closed'])) : ?><input type="hidden" name="program[<?php echo esc_attr($choice['key']); ?>]" value="1"><?php endif; ?>
                        <input type="checkbox" name="program[<?php echo esc_attr($choice['key']); ?>]" value="1" <?php checked($selected); ?> <?php disabled($unavailable || ($selected && ! empty($state['closed']))); ?>>
                        <span><strong><?php echo esc_html($choice['title']); ?></strong><small><?php echo esc_html(trim($choice['date'] . ' ' . $choice['start'])); ?><?php if (! empty($choice['location'])) : ?> · <?php echo esc_html($choice['location']); ?><?php endif; ?><?php if (! empty($choice['price'])) : ?> · <?php echo esc_html($choice['price']); ?><?php endif; ?><?php if (! empty($choice['deadline'])) : ?> · <?php echo esc_html(sprintf(__('Anmäl senast %s', 'ssf-member-portal'), wp_date('j F Y', (int) $choice['deadline'], wp_timezone()))); ?><?php endif; ?><?php if (! empty($state['full'])) : ?> · <?php esc_html_e('Fullbokad', 'ssf-member-portal'); ?><?php elseif (! empty($state['closed'])) : ?> · <?php esc_html_e('Anmälan stängd', 'ssf-member-portal'); ?><?php elseif (! empty($state['capacity'])) : ?> · <?php echo esc_html(sprintf(__('%1$d av %2$d platser bokade', 'ssf-member-portal'), (int) $state['count'], (int) $state['capacity'])); ?><?php endif; ?></small></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <?php if ($food_enabled && $meeting['food_options']) : ?><fieldset><legend><?php esc_html_e('4. Mat och specialkost', 'ssf-member-portal'); ?></legend><p class="ssf-am-help"><?php esc_html_e('Ange endast information som arrangören behöver för att kunna ordna maten.', 'ssf-member-portal'); ?></p><div class="ssf-am-options"><?php foreach ($meeting['food_options'] as $option) : ?><label class="ssf-am-option"><input type="checkbox" name="food[<?php echo esc_attr($option); ?>]" value="1" <?php checked(in_array($option, (array) $value('food', array()), true)); ?>> <?php echo esc_html($option); ?></label><?php endforeach; ?></div><p><label for="ssf-am-food-note"><?php esc_html_e('Annat eller information till köket', 'ssf-member-portal'); ?></label><textarea id="ssf-am-food-note" name="food_note" rows="3"><?php echo esc_textarea($value('food_note')); ?></textarea></p></fieldset><?php endif; ?>

            <?php if ($meeting['questions']) : ?><fieldset><legend><?php esc_html_e('5. Övriga frågor', 'ssf-member-portal'); ?></legend><?php foreach ($meeting['questions'] as $question) : if (! empty($question['visible'])) { $this->render_question($question, (array) $value('answers', array())); } endforeach; ?></fieldset><?php endif; ?>
            <fieldset class="ssf-am-submit"><legend><?php esc_html_e('Kontrollera och skicka', 'ssf-member-portal'); ?></legend><p><?php esc_html_e('Uppgifterna används för att administrera de praktiska arrangemangen kring SSF:s årsmöteshelg.', 'ssf-member-portal'); ?></p><button class="ssf-am-button" type="submit"><?php echo $editing ? esc_html__('Spara ändringar', 'ssf-member-portal') : esc_html__('Skicka anmälan', 'ssf-member-portal'); ?></button></fieldset>
        </form>
        <?php if ($editing && 'cancelled' !== $value('status')) : ?><form class="ssf-am-cancel" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="ssf_member_portal_cancel_meeting_registration"><input type="hidden" name="token" value="<?php echo esc_attr($token); ?>"><?php wp_nonce_field('ssf_member_portal_cancel_meeting_registration', 'ssf_member_portal_meeting_cancel_nonce'); ?><button type="submit" class="ssf-am-text-button"><?php esc_html_e('Avboka anmälan till middag och aktiviteter', 'ssf-member-portal'); ?></button></form><?php endif; ?>
    <?php endif; ?>
</section>

<?php

namespace SSF\MemberPortal\Modules\AnnualMeetings;

if (! defined('ABSPATH')) {
    exit;
}

final class Editor
{
    private Module $meetings;
    private RegistrationService $registrations;
    private int $meeting_id = 0;
    private array $selection_counts = array();

    public function __construct(Module $meetings, RegistrationService $registrations)
    {
        $this->meetings = $meetings;
        $this->registrations = $registrations;
    }

    public function render(\WP_Post $post): void
    {
        wp_nonce_field('ssf_member_portal_meeting', 'ssf_member_portal_meeting_nonce');
        $this->meeting_id = (int) $post->ID;
        $this->selection_counts = $this->registrations->selection_counts($this->meeting_id);
        $data = $this->meetings->data($post->ID);
        $program = $data['program'] ?: array($this->program_row());
        $documents = $data['documents'] ?: array($this->document_row());
        $questions = $data['questions'] ?: array($this->question_row());
        $tabs = array(
            'overview' => 'Översikt',
            'invitation' => 'Kallelse',
            'time-place' => 'Helg & plats',
            'day2' => 'Program & aktiviteter',
            'motions' => 'Motioner',
            'documents' => 'Handlingar',
            'publishing' => 'Anmälan & publicering',
        );
        ?>
        <div class="ssf-am-editor" data-ssf-am-editor>
            <nav class="ssf-am-admin-tabs" aria-label="Årsmötets delar" role="tablist">
                <?php foreach ($tabs as $key => $label) : ?><button type="button" role="tab" aria-selected="<?php echo 'overview' === $key ? 'true' : 'false'; ?>" aria-controls="ssf-am-tab-<?php echo esc_attr($key); ?>" id="ssf-am-tab-button-<?php echo esc_attr($key); ?>" data-ssf-admin-tab="<?php echo esc_attr($key); ?>" class="<?php echo 'overview' === $key ? 'is-active' : ''; ?>"><?php echo esc_html($label); ?></button><?php endforeach; ?>
            </nav>

            <section id="ssf-am-tab-overview" role="tabpanel" aria-labelledby="ssf-am-tab-button-overview" data-ssf-admin-panel="overview" class="ssf-am-admin-panel is-active">
                <div class="ssf-am-admin-heading"><div><h2>Översikt</h2><p>Grundinformation och vilka delar som ingår i årsmötet.</p></div><?php $this->status($post, $data); ?></div>
                <div class="ssf-am-admin-grid">
                    <label>År<input name="ssf_meeting_year" type="number" min="2000" max="2100" value="<?php echo esc_attr((string) $data['year']); ?>"></label>
                    <label>Kort ingress<textarea name="ssf_meeting_intro" rows="4"><?php echo esc_textarea($data['intro']); ?></textarea></label>
                </div>
                <label class="ssf-am-admin-switch"><input type="checkbox" name="ssf_meeting_active" value="1" <?php checked((int) get_option('ssf_member_portal_active_meeting_id', 0), $post->ID); ?>><span>Aktivt årsmöte</span><small>Detta årsmöte används på den publika huvudsidan och i anmälningsflödet.</small></label>
                <h3>Aktiva moduler</h3>
                <div class="ssf-am-module-switches">
                    <?php foreach (array('invitation' => 'Kallelse', 'day2' => 'Program & aktiviteter', 'motions' => 'Motioner', 'documents' => 'Handlingar', 'calendar' => 'Kalender') as $key => $label) : ?><label><input type="checkbox" name="ssf_meeting_modules[<?php echo esc_attr($key); ?>]" value="1" <?php checked(! empty($data['modules'][$key])); ?> data-ssf-module-toggle="<?php echo esc_attr($key); ?>"> <span><?php echo esc_html($label); ?></span></label><?php endforeach; ?>
                    <input type="hidden" name="ssf_meeting_modules[meeting]" value="1">
                </div>
                <p class="description">Titel, lång beskrivning och huvudbild hanteras med WordPress-fälten ovanför denna ruta.</p>
            </section>

            <section id="ssf-am-tab-invitation" role="tabpanel" aria-labelledby="ssf-am-tab-button-invitation" data-ssf-admin-panel="invitation" class="ssf-am-admin-panel" hidden>
                <?php $this->module_heading('invitation', 'Kallelse', 'Kallelsen är en del av årsmötet och kan publiceras som text, PDF eller båda.', $data); ?>
                <div data-ssf-module-fields="invitation">
                    <div class="ssf-am-admin-grid">
                        <label>Rubrik<input name="ssf_meeting_invitation[title]" value="<?php echo esc_attr($data['invitation']['title']); ?>"></label>
                        <label>Publiceras från<input type="datetime-local" name="ssf_meeting_invitation[publish_at]" value="<?php echo esc_attr($this->input_date((int) $data['invitation']['publish_at'])); ?>"></label>
                    </div>
                    <label class="ssf-am-admin-switch"><input type="checkbox" name="ssf_meeting_invitation[visible]" value="1" <?php checked(! empty($data['invitation']['visible'])); ?>><span>Visa kallelsen på webben</span></label>
                    <label class="ssf-am-editor-label">Kallelsetext</label>
                    <?php wp_editor((string) $data['invitation']['text'], 'ssf_meeting_invitation_text', array('textarea_name' => 'ssf_meeting_invitation[text]', 'textarea_rows' => 8, 'media_buttons' => false)); ?>
                    <?php $this->media_field('Kallelse PDF', 'ssf_meeting_invitation[pdf_id]', (int) $data['invitation']['pdf_id'], 'application/pdf'); ?>
                </div>
            </section>

            <section id="ssf-am-tab-time-place" role="tabpanel" aria-labelledby="ssf-am-tab-button-time-place" data-ssf-admin-panel="time-place" class="ssf-am-admin-panel" hidden>
                <div class="ssf-am-admin-heading"><div><h2>Helg & plats</h2><p>Välj datum och hur många dagar årsmöteshelgen omfattar. Tider läggs bara på de aktiviteter där de behövs.</p></div></div>
                <h3>Årsmöteshelgen</h3>
                <div class="ssf-am-admin-grid">
                    <label>Startdatum<input name="ssf_meeting_start_date" type="date" value="<?php echo esc_attr($this->input_day((int) $data['start_at'])); ?>" data-ssf-weekend-start></label>
                    <label>Helgens längd<select name="ssf_meeting_duration_days" data-ssf-weekend-duration><?php foreach (array(1 => '1 dag', 2 => '2 dagar', 3 => '3 dagar') as $days => $label) : ?><option value="<?php echo esc_attr((string) $days); ?>" <?php selected((int) $data['duration_days'], $days); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label>
                    <label>Platsnamn<input name="ssf_meeting_location" value="<?php echo esc_attr($data['location']); ?>"></label>
                    <label>Ort<input name="ssf_meeting_city" value="<?php echo esc_attr($data['city']); ?>"></label>
                    <label>Adress<textarea rows="3" name="ssf_meeting_address"><?php echo esc_textarea($data['address']); ?></textarea></label>
                    <label>Postnummer<input name="ssf_meeting_postal_code" value="<?php echo esc_attr($data['postal_code']); ?>"></label>
                    <label>Google Maps-länk<input type="url" name="ssf_meeting_maps_url" value="<?php echo esc_attr($data['maps_url']); ?>"><small>Valfri. Tomt bygger en söklänk från plats och adress.</small></label>
                </div>
                <div class="ssf-am-admin-notice"><strong>Programmet byggs per dag</strong><p>Lägg till årsmöte, middag, presentationer och andra aktiviteter under Program & aktiviteter. Aktiviteter behöver bara tid om den ska visas för deltagarna.</p></div>
            </section>

            <section id="ssf-am-tab-dinner" role="tabpanel" aria-labelledby="ssf-am-tab-button-dinner" data-ssf-admin-panel="dinner" class="ssf-am-admin-panel" hidden>
                <?php $this->module_heading('dinner', 'Middag', 'Middagen har egen tid, kapacitet och anmälningsdeadline inom samma årsmöte.', $data); ?>
                <div data-ssf-module-fields="dinner">
                    <div class="ssf-am-admin-grid">
                        <label>Rubrik<input name="ssf_meeting_dinner[title]" value="<?php echo esc_attr($data['dinner']['title']); ?>"></label>
                        <label>Plats<input name="ssf_meeting_dinner[location]" value="<?php echo esc_attr($data['dinner']['location']); ?>"></label>
                        <label>Start<input type="datetime-local" name="ssf_meeting_dinner[start_at]" value="<?php echo esc_attr($this->input_date((int) $data['dinner']['start_at'])); ?>"></label>
                        <label>Slut<input type="datetime-local" name="ssf_meeting_dinner[end_at]" value="<?php echo esc_attr($this->input_date((int) $data['dinner']['end_at'])); ?>"></label>
                        <label>Anmälan öppnar<input type="datetime-local" name="ssf_meeting_dinner[opens_at]" value="<?php echo esc_attr($this->input_date((int) ($data['dinner']['opens_at'] ?? 0))); ?>"><small>Tomt använder årsmötets gemensamma öppning.</small></label>
                        <label>Sista anmälningsdag<input type="datetime-local" name="ssf_meeting_dinner[deadline]" value="<?php echo esc_attr($this->input_date((int) $data['dinner']['deadline'])); ?>"></label>
                        <label>Max antal<input type="number" min="0" name="ssf_meeting_dinner[capacity]" value="<?php echo esc_attr((string) $data['dinner']['capacity']); ?>"><small>0 betyder obegränsat.</small></label>
                        <label>Pris<input name="ssf_meeting_dinner[price]" value="<?php echo esc_attr($data['dinner']['price']); ?>" placeholder="Exempel: 395 kr"></label>
                    </div>
                    <label>Beskrivning<textarea rows="5" name="ssf_meeting_dinner[description]"><?php echo esc_textarea($data['dinner']['description']); ?></textarea></label>
                    <div class="ssf-am-admin-checks"><label><input type="checkbox" name="ssf_meeting_dinner[food_enabled]" value="1" <?php checked(! empty($data['dinner']['food_enabled'])); ?>> Fråga om matpreferenser</label><label><input type="checkbox" name="ssf_meeting_dinner[manual_open]" value="1" <?php checked(! empty($data['dinner']['manual_open'])); ?>> Håll anmälan öppen efter deadline</label></div>
                    <p class="ssf-am-admin-selection-status"><strong><?php echo esc_html(sprintf('%d anmälda', (int) ($this->selection_counts['dinner'] ?? 0))); ?></strong> <a href="<?php echo esc_url($this->registration_list_url('dinner')); ?>">Visa anmälda</a></p>
                    <label>Matpreferenser, ett alternativ per rad<textarea rows="5" name="ssf_meeting_food_options"><?php echo esc_textarea(implode("\n", (array) $data['food_options'])); ?></textarea><small>Samla bara information som köket behöver.</small></label>
                </div>
            </section>

            <section id="ssf-am-tab-day2" role="tabpanel" aria-labelledby="ssf-am-tab-button-day2" data-ssf-admin-panel="day2" class="ssf-am-admin-panel" hidden>
                <?php $this->module_heading('day2', 'Program & aktiviteter', 'Bygg helgen i ordning. Välj dag och typ för varje aktivitet. Aktivera anmälan endast där SSF behöver deltagarantal.', $data); ?>
                <div data-ssf-module-fields="day2">
                    <div class="ssf-am-admin-list" data-ssf-repeater="program">
                        <?php foreach ($program as $index => $item) : $this->render_program_row((string) $index, $item); endforeach; ?>
                    </div>
                    <p><button class="button" type="button" data-ssf-add-row="program"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Lägg till aktivitet</button></p>
                    <?php $this->media_field('Program PDF', 'ssf_meeting_program_pdf_id', (int) $data['program_pdf_id'], 'application/pdf'); ?>
                </div>
            </section>

            <section id="ssf-am-tab-motions" role="tabpanel" aria-labelledby="ssf-am-tab-button-motions" data-ssf-admin-panel="motions" class="ssf-am-admin-panel" hidden>
                <?php $this->module_heading('motions', 'Motioner', 'Befintlig motions- och SharePoint-logik används oförändrad och kopplas till detta årsmöte.', $data); ?>
                <div data-ssf-module-fields="motions">
                    <div class="ssf-am-admin-grid">
                        <label>Motioner öppnar<input name="ssf_motion_opens_on" type="date" value="<?php echo esc_attr($this->input_day((int) $data['motion_opens_at'])); ?>"></label>
                        <label>Motioner stänger<input name="ssf_motion_closes_on" type="date" value="<?php echo esc_attr($this->input_day((int) $data['motion_closes_at'])); ?>"></label>
                    </div>
                    <div class="ssf-am-admin-checks"><label><input type="checkbox" name="ssf_meeting_allow_late_motions" value="1" <?php checked($data['allow_late_motions']); ?>> Tillåt sena motioner</label><label><input type="checkbox" name="ssf_meeting_motions_public" value="1" <?php checked($data['motions_public']); ?>> Visa motionsinformation publikt</label></div>
                    <label class="ssf-am-editor-label">Instruktion till medlem</label>
                    <?php wp_editor($data['motion_instructions'], 'ssf_meeting_motion_instructions', array('textarea_name' => 'ssf_meeting_motion_instructions', 'textarea_rows' => 7, 'media_buttons' => false)); ?>
                    <p><a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=ssf_motion&ssf_am_meeting=' . $post->ID)); ?>">Visa motioner för årsmötet</a></p>
                </div>
            </section>

            <section id="ssf-am-tab-documents" role="tabpanel" aria-labelledby="ssf-am-tab-button-documents" data-ssf-admin-panel="documents" class="ssf-am-admin-panel" hidden>
                <?php $this->module_heading('documents', 'Handlingar', 'Lägg handlingarna på årsmötet. PDF-filer kan publiceras eller döljas var för sig.', $data); ?>
                <div data-ssf-module-fields="documents">
                    <div class="ssf-am-admin-list" data-ssf-repeater="document">
                        <?php foreach ($documents as $index => $document) : $this->render_document_row((string) $index, $document); endforeach; ?>
                    </div>
                    <p><button class="button" type="button" data-ssf-add-row="document"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Lägg till handling</button></p>
                </div>
            </section>

            <section id="ssf-am-tab-publishing" role="tabpanel" aria-labelledby="ssf-am-tab-button-publishing" data-ssf-admin-panel="publishing" class="ssf-am-admin-panel" hidden>
                <div class="ssf-am-admin-heading"><div><h2>Kalender & publicering</h2><p>Årsmötesobjektet är källa för webbkalendern och den publika iCal-filen.</p></div></div>
                <div data-ssf-module-fields="calendar">
                    <div class="ssf-am-admin-grid"><label>Kalendertitel<input name="ssf_meeting_calendar_title" value="<?php echo esc_attr($data['calendar_title']); ?>"></label><label>Kalenderbeskrivning<textarea rows="3" name="ssf_meeting_calendar_description"><?php echo esc_textarea($data['calendar_description']); ?></textarea></label></div>
                </div>
                <h3>Gemensam anmälan till aktiviteter</h3>
                <div class="ssf-am-admin-grid">
                    <label>Anmälan öppnar<input name="ssf_meeting_registration_opens_on" type="date" value="<?php echo esc_attr($this->input_day((int) $data['registration_opens_at'])); ?>"></label>
                    <label>Sista anmälningsdag<input name="ssf_meeting_registration_closes_on" type="date" value="<?php echo esc_attr($this->input_day((int) $data['registration_closes_at'])); ?>"><small>Gäller middag och samtliga aktiviteter.</small></label>
                    <div><strong>Notifieringsadress</strong><small>Styrs centralt under SSF → System → Microsoft 365.</small></div>
                    <label>Gallra personuppgifter efter<input type="number" min="1" max="60" name="ssf_meeting_retention_months" value="<?php echo esc_attr((string) $data['retention_months']); ?>"><small>Månader efter årsmötets slut.</small></label>
                </div>
                <div class="ssf-am-admin-checks"><label><input type="checkbox" name="ssf_meeting_registration_open" value="1" <?php checked($data['registration_open']); ?>> Anmälan är öppen</label><label><input type="checkbox" name="ssf_meeting_allow_edits" value="1" <?php checked($data['allow_edits']); ?>> Tillåt ändring och avbokning</label><label><input type="checkbox" name="ssf_meeting_allow_guest" value="1" <?php checked($data['allow_guest']); ?>> Tillåt inbjudna gäster</label><label><input type="checkbox" name="ssf_meeting_notify_each" value="1" <?php checked($data['notify_each']); ?>> Skicka e-post vid ny anmälan</label><label><input type="checkbox" name="ssf_meeting_waitlist" value="1" <?php checked($data['waitlist']); ?>> Använd reservlista för äldre gemensam kapacitet</label></div>
                <input type="hidden" name="ssf_meeting_capacity" value="<?php echo esc_attr((string) $data['capacity']); ?>">
                <h3>Extra frågor i anmälan</h3>
                <div class="ssf-am-admin-list" data-ssf-repeater="question"><?php foreach ($questions as $index => $question) : $this->render_question_row((string) $index, $question); endforeach; ?></div>
                <p><button class="button" type="button" data-ssf-add-row="question"><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Lägg till fråga</button></p>
                <div class="ssf-am-admin-notice"><strong>Publicering</strong><p>Använd WordPress-rutan Publicera eller Uppdatera. Endast aktiverade moduler med innehåll visas på webbplatsen.</p></div>
            </section>
        </div>

        <script type="text/html" id="tmpl-ssf-am-program-row"><?php $this->render_program_row('__INDEX__', $this->program_row()); ?></script>
        <script type="text/html" id="tmpl-ssf-am-document-row"><?php $this->render_document_row('__INDEX__', $this->document_row()); ?></script>
        <script type="text/html" id="tmpl-ssf-am-question-row"><?php $this->render_question_row('__INDEX__', $this->question_row()); ?></script>
        <?php
    }

    private function status(\WP_Post $post, array $data): void
    {
        $count = count(get_posts(array('post_type' => RegistrationPostType::POST_TYPE, 'post_status' => 'private', 'post_parent' => $post->ID, 'fields' => 'ids', 'posts_per_page' => -1)));
        ?><dl class="ssf-am-admin-status"><div><dt>Status</dt><dd><?php echo esc_html('publish' === $post->post_status ? 'Publicerad' : 'Utkast'); ?></dd></div><div><dt>Anmälningar</dt><dd><?php echo esc_html((string) $count); ?></dd></div><div><dt>Plats</dt><dd><?php echo esc_html($data['location'] ?: 'Saknas'); ?></dd></div></dl><?php
    }

    private function module_heading(string $key, string $title, string $description, array $data): void
    {
        ?><div class="ssf-am-admin-heading"><div><h2><?php echo esc_html($title); ?></h2><p><?php echo esc_html($description); ?></p></div><label class="ssf-am-module-toggle"><input type="checkbox" name="ssf_meeting_modules[<?php echo esc_attr($key); ?>]" value="1" <?php checked(! empty($data['modules'][$key])); ?> data-ssf-module-toggle="<?php echo esc_attr($key); ?>"><span>Aktiv</span></label></div><?php
    }

    private function media_field(string $label, string $name, int $attachment_id, string $mime): void
    {
        $filename = $attachment_id ? basename((string) get_attached_file($attachment_id)) : '';
        ?><div class="ssf-am-media-field" data-ssf-media-field data-mime="<?php echo esc_attr($mime); ?>"><label><?php echo esc_html($label); ?><input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr((string) $attachment_id); ?>" data-ssf-media-id></label><span data-ssf-media-name><?php echo esc_html($filename ?: 'Ingen fil vald'); ?></span><button type="button" class="button" data-ssf-select-media>Välj PDF</button><button type="button" class="button-link-delete" data-ssf-remove-media <?php echo $attachment_id ? '' : 'hidden'; ?>>Ta bort</button></div><?php
    }

    private function render_program_row(string $index, array $item): void
    {
        $item = array_merge($this->program_row(), $item);
        $prefix = 'ssf_meeting_program[' . $index . ']';
        ?>
        <article class="ssf-am-admin-item" data-ssf-repeater-row>
            <header><span class="dashicons dashicons-move" aria-hidden="true"></span><strong data-ssf-item-title><?php echo esc_html($item['title'] ?: 'Ny programpunkt'); ?></strong><div><button type="button" class="button-link" data-ssf-move="up" aria-label="Flytta upp"><span class="dashicons dashicons-arrow-up-alt2"></span></button><button type="button" class="button-link" data-ssf-move="down" aria-label="Flytta ned"><span class="dashicons dashicons-arrow-down-alt2"></span></button><button type="button" class="button-link-delete" data-ssf-remove-row>Ta bort</button></div></header>
            <input type="hidden" name="<?php echo esc_attr($prefix); ?>[key]" value="<?php echo esc_attr($item['key']); ?>"><input type="hidden" name="<?php echo esc_attr($prefix); ?>[order]" value="<?php echo esc_attr((string) $item['order']); ?>" data-ssf-order>
            <div class="ssf-am-admin-grid ssf-am-admin-grid--three"><label>Rubrik<input name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr($item['title']); ?>" data-ssf-title-input></label><label>Dag<select name="<?php echo esc_attr($prefix); ?>[day]" data-ssf-program-day><?php for ($day = 1; $day <= 3; $day++) : ?><option value="<?php echo esc_attr((string) $day); ?>" <?php selected((int) $item['day'], $day); ?>><?php echo esc_html(sprintf(__('Dag %d', 'ssf-member-portal'), $day)); ?></option><?php endfor; ?></select></label><label>Typ<select name="<?php echo esc_attr($prefix); ?>[type]"><?php foreach (array('annual_meeting' => 'Årsmöte', 'dinner' => 'Middag', 'presentation' => 'Presentation', 'activity' => 'Aktivitet', 'other' => 'Övrigt') as $type => $label) : ?><option value="<?php echo esc_attr($type); ?>" <?php selected($item['type'], $type); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>Plats<input name="<?php echo esc_attr($prefix); ?>[location]" value="<?php echo esc_attr($item['location']); ?>"></label><label>Start, valfritt<input type="time" name="<?php echo esc_attr($prefix); ?>[start]" value="<?php echo esc_attr($item['start']); ?>"></label><label>Slut, valfritt<input type="time" name="<?php echo esc_attr($prefix); ?>[end]" value="<?php echo esc_attr($item['end']); ?>"></label><label>Pris<input name="<?php echo esc_attr($prefix); ?>[price]" value="<?php echo esc_attr($item['price']); ?>"></label></div>
            <label>Beskrivning<textarea rows="3" name="<?php echo esc_attr($prefix); ?>[description]"><?php echo esc_textarea($item['description']); ?></textarea></label>
            <div class="ssf-am-admin-checks"><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[visible]" value="1" <?php checked(! empty($item['visible'])); ?>> Synlig</label><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[requires_registration]" value="1" <?php checked(! empty($item['requires_registration'])); ?> data-ssf-registration-toggle> Kräver anmälan</label><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[food]" value="1" <?php checked(! empty($item['food'])); ?>> Påverkar mat</label><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[closed]" value="1" <?php checked(! empty($item['closed'])); ?>> Stäng anmälan manuellt</label></div>
            <div class="ssf-am-admin-grid" data-ssf-registration-fields><label>Max antal<input type="number" min="0" name="<?php echo esc_attr($prefix); ?>[capacity]" value="<?php echo esc_attr((string) $item['capacity']); ?>"><small>0 betyder obegränsat. Anmälningsdatum styrs gemensamt för alla aktiviteter.</small></label></div>
            <?php if (! empty($item['key']) && ! empty($item['requires_registration'])) : ?><p class="ssf-am-admin-selection-status"><strong><?php echo esc_html(sprintf('%1$d%2$s anmälda', (int) ($this->selection_counts[$item['key']] ?? 0), $item['capacity'] ? ' / ' . (int) $item['capacity'] : '')); ?></strong> <a href="<?php echo esc_url($this->registration_list_url((string) $item['key'])); ?>">Visa anmälda</a></p><?php endif; ?>
            <input type="hidden" name="<?php echo esc_attr($prefix); ?>[optional]" value="1">
        </article>
        <?php
    }

    private function render_document_row(string $index, array $item): void
    {
        $item = array_merge($this->document_row(), $item);
        $prefix = 'ssf_meeting_documents[' . $index . ']';
        $filename = $item['attachment_id'] ? basename((string) get_attached_file((int) $item['attachment_id'])) : 'Ingen fil vald';
        ?>
        <article class="ssf-am-admin-item" data-ssf-repeater-row data-ssf-media-field data-mime="application/pdf"><header><span class="dashicons dashicons-media-document" aria-hidden="true"></span><strong data-ssf-item-title><?php echo esc_html($item['title'] ?: 'Ny handling'); ?></strong><div><button type="button" class="button-link" data-ssf-move="up" aria-label="Flytta upp"><span class="dashicons dashicons-arrow-up-alt2"></span></button><button type="button" class="button-link" data-ssf-move="down" aria-label="Flytta ned"><span class="dashicons dashicons-arrow-down-alt2"></span></button><button type="button" class="button-link-delete" data-ssf-remove-row>Ta bort</button></div></header><input type="hidden" name="<?php echo esc_attr($prefix); ?>[attachment_id]" value="<?php echo esc_attr((string) $item['attachment_id']); ?>" data-ssf-media-id><input type="hidden" name="<?php echo esc_attr($prefix); ?>[order]" value="<?php echo esc_attr((string) $item['order']); ?>" data-ssf-order><div class="ssf-am-admin-grid ssf-am-admin-grid--three"><label>Titel<input name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr($item['title']); ?>" data-ssf-title-input></label><label>Typ<select name="<?php echo esc_attr($prefix); ?>[type]"><?php foreach ($this->document_types() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($item['type'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label class="ssf-am-inline-check"><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[visible]" value="1" <?php checked(! empty($item['visible'])); ?>> Publicerad</label></div><div class="ssf-am-media-field__actions"><span data-ssf-media-name><?php echo esc_html($filename); ?></span><button type="button" class="button" data-ssf-select-media>Välj PDF</button><button type="button" class="button-link-delete" data-ssf-remove-media <?php echo $item['attachment_id'] ? '' : 'hidden'; ?>>Ta bort fil</button></div></article>
        <?php
    }

    private function render_question_row(string $index, array $item): void
    {
        $item = array_merge($this->question_row(), $item);
        $prefix = 'ssf_meeting_questions[' . $index . ']';
        ?><article class="ssf-am-admin-item" data-ssf-repeater-row><header><span class="dashicons dashicons-editor-help" aria-hidden="true"></span><strong data-ssf-item-title><?php echo esc_html($item['title'] ?: 'Ny fråga'); ?></strong><button type="button" class="button-link-delete" data-ssf-remove-row>Ta bort</button></header><input type="hidden" name="<?php echo esc_attr($prefix); ?>[key]" value="<?php echo esc_attr($item['key']); ?>"><input type="hidden" name="<?php echo esc_attr($prefix); ?>[order]" value="<?php echo esc_attr((string) $item['order']); ?>" data-ssf-order><div class="ssf-am-admin-grid"><label>Fråga<input name="<?php echo esc_attr($prefix); ?>[title]" value="<?php echo esc_attr($item['title']); ?>" data-ssf-title-input></label><label>Typ<select name="<?php echo esc_attr($prefix); ?>[type]"><?php foreach (array('text' => 'Kort text', 'textarea' => 'Lång text', 'yes_no' => 'Ja/nej', 'checkbox' => 'Checkbox', 'single' => 'Ett val', 'multiple' => 'Flera val', 'date' => 'Datum', 'info' => 'Informationsblock') as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($item['type'], $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select></label><label>Hjälptext<textarea rows="2" name="<?php echo esc_attr($prefix); ?>[help]"><?php echo esc_textarea($item['help']); ?></textarea></label><label>Alternativ, ett per rad<textarea rows="2" name="<?php echo esc_attr($prefix); ?>[options]"><?php echo esc_textarea(implode("\n", (array) $item['options'])); ?></textarea></label></div><div class="ssf-am-admin-checks"><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[required]" value="1" <?php checked(! empty($item['required'])); ?>> Obligatorisk</label><label><input type="checkbox" name="<?php echo esc_attr($prefix); ?>[visible]" value="1" <?php checked(! empty($item['visible'])); ?>> Synlig</label></div></article><?php
    }

    private function program_row(): array
    {
        return array('key' => '', 'day' => 1, 'date' => '', 'start' => '', 'end' => '', 'type' => 'activity', 'title' => '', 'description' => '', 'location' => '', 'requires_registration' => 0, 'optional' => 1, 'food' => 0, 'closed' => 0, 'manual_open' => 0, 'capacity' => 0, 'opens_at' => 0, 'deadline' => 0, 'price' => '', 'visible' => 1, 'order' => 0);
    }

    private function document_row(): array
    {
        return array('attachment_id' => 0, 'title' => '', 'type' => 'other', 'visible' => 1, 'order' => 0);
    }

    private function question_row(): array
    {
        return array('key' => '', 'title' => '', 'help' => '', 'type' => 'text', 'options' => array(), 'required' => 0, 'visible' => 1, 'order' => 0);
    }

    private function document_types(): array
    {
        return array('agenda' => 'Dagordning', 'annual_report' => 'Verksamhetsberättelse', 'financial_report' => 'Ekonomisk rapport', 'budget' => 'Budget', 'motions' => 'Motioner', 'board_response' => 'Styrelsens yttranden', 'minutes' => 'Protokoll', 'other' => 'Övrigt');
    }

    private function input_date(int $timestamp): string
    {
        return $timestamp ? wp_date('Y-m-d\TH:i', $timestamp, wp_timezone()) : '';
    }

    private function input_day(int $timestamp): string
    {
        return $timestamp ? wp_date('Y-m-d', $timestamp, wp_timezone()) : '';
    }

    private function registration_list_url(string $choice): string
    {
        return add_query_arg(
            array('post_type' => RegistrationPostType::POST_TYPE, 'ssf_am_meeting' => $this->meeting_id, 'ssf_am_program' => $choice),
            admin_url('edit.php')
        );
    }
}

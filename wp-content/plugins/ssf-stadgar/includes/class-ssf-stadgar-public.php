<?php
/**
 * Public rendering for the statutes and documents page.
 *
 * @package SSF_Stadgar
 */

if (! defined('ABSPATH')) {
    exit;
}

class SSF_Stadgar_Public
{
    private SSF_Stadgar_Document $documents;

    public function __construct(SSF_Stadgar_Document $documents)
    {
        $this->documents = $documents;
    }

    public function render(): string
    {
        $document = $this->documents->current_statutes();
        $intro = (string) get_option(
            'ssf_stadgar_intro',
            'På denna sida hittar du Sveriges Segelfartygsförbunds gällande stadgar, tidigare versioner och relaterade dokument.'
        );

        ob_start();
        ?>
        <section class="ssf-stadgar" aria-labelledby="ssf-stadgar-title">
            <header class="ssf-stadgar__intro">
                <p class="ssf-stadgar__eyebrow">Sveriges Segelfartygsförbund</p>
                <h1 id="ssf-stadgar-title">Stadgar</h1>
                <p><?php echo esc_html($intro); ?></p>
            </header>
            <?php if (! $document instanceof WP_Post) : ?>
                <section class="ssf-stadgar__empty" aria-labelledby="ssf-stadgar-empty-title">
                    <h2 id="ssf-stadgar-empty-title">Gällande stadgar</h2>
                    <p>Det finns ännu ingen publicerad gällande version. Administratören kan lägga till och publicera dokumentet under Stadgar &amp; dokument i WordPress.</p>
                </section>
            <?php else : ?>
                <?php $this->render_current_document($document); ?>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    private function render_current_document(WP_Post $document): void
    {
        $data = $this->documents->data($document->ID);
        $pdf_url = $this->documents->pdf_url($document->ID);
        $has_online_content = '' !== trim(wp_strip_all_tags($document->post_content));
        $outline = $data['outline'];
        $current_note = (string) get_option('ssf_stadgar_current_note', 'Detta är den version av stadgarna som gäller just nu.');
        ?>
        <section class="ssf-stadgar__current" aria-labelledby="ssf-current-title">
            <div class="ssf-stadgar__current-heading">
                <p class="ssf-stadgar__status">Gällande version</p>
                <h2 id="ssf-current-title"><?php echo esc_html($document->post_title); ?></h2>
                <p class="ssf-stadgar__current-note"><?php echo esc_html($current_note); ?></p>
            </div>
            <dl class="ssf-stadgar__metadata">
                <?php if ($data['version']) : ?><div><dt>Version</dt><dd><?php echo esc_html($data['version']); ?></dd></div><?php endif; ?>
                <?php if ($data['adopted_date']) : ?><div><dt>Antagna</dt><dd><?php echo esc_html($this->format_date($data['adopted_date'])); ?></dd></div><?php endif; ?>
                <?php if ($data['adopted_by']) : ?><div><dt>Antagen av</dt><dd><?php echo esc_html($data['adopted_by']); ?></dd></div><?php endif; ?>
            </dl>
            <?php if ($data['summary']) : ?><p class="ssf-stadgar__summary"><?php echo esc_html($data['summary']); ?></p><?php endif; ?>
            <div class="ssf-stadgar__actions">
                <?php if ($has_online_content) : ?><a class="ssf-stadgar__button" href="#stadgar-online">Läs stadgarna online</a><?php endif; ?>
                <?php if ($pdf_url) : ?><a class="ssf-stadgar__button ssf-stadgar__button--secondary" href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener">Ladda ner PDF</a><?php endif; ?>
            </div>
        </section>

        <?php if ($outline || $has_online_content) : ?>
            <div class="ssf-stadgar__reading">
                <?php if ($outline) : ?>
                    <nav class="ssf-stadgar__outline" aria-labelledby="ssf-outline-title">
                        <h2 id="ssf-outline-title">Snabböversikt</h2>
                        <ol>
                            <?php foreach ($outline as $item) : ?>
                                <li><a href="#<?php echo esc_attr($item['anchor']); ?>"><?php echo esc_html($item['title']); ?></a></li>
                            <?php endforeach; ?>
                        </ol>
                    </nav>
                <?php endif; ?>
                <?php if ($has_online_content) : ?>
                    <section class="ssf-stadgar__online" id="stadgar-online" aria-labelledby="ssf-online-title">
                        <h2 id="ssf-online-title">Läs stadgarna</h2>
                        <div class="ssf-stadgar__document-content">
                            <?php echo $this->online_content($document->post_content, $outline); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php $this->render_related_documents($document->ID); ?>
        <?php $this->render_history($document->ID); ?>
        <?php
    }

    private function render_related_documents(int $document_id): void
    {
        $documents = $this->documents->related_documents($document_id);
        if (! $documents) {
            return;
        }
        ?>
        <section class="ssf-stadgar__section" aria-labelledby="ssf-related-title">
            <div class="ssf-stadgar__section-heading">
                <h2 id="ssf-related-title">Relaterade dokument</h2>
                <p>Dokument som hör ihop med stadgarna och används i SSF:s verksamhet.</p>
            </div>
            <div class="ssf-stadgar__document-grid">
                <?php foreach ($documents as $document) : ?>
                    <?php $this->render_document_card($document); ?>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    }

    private function render_document_card(WP_Post $document): void
    {
        $data = $this->documents->data($document->ID);
        $pdf_url = $this->documents->pdf_url($document->ID);
        ?>
        <article class="ssf-stadgar__document-card">
            <p class="ssf-stadgar__document-type"><?php echo esc_html($this->documents->type_label($data['type'])); ?></p>
            <h3><?php echo esc_html($document->post_title); ?></h3>
            <?php if ($data['summary']) : ?><p><?php echo esc_html($data['summary']); ?></p><?php endif; ?>
            <?php if ($pdf_url) : ?><a href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener">Ladda ner <?php echo esc_html($document->post_title); ?> som PDF</a><?php endif; ?>
        </article>
        <?php
    }

    private function render_history(int $current_document_id): void
    {
        $history = $this->documents->history($current_document_id);
        if (! $history) {
            return;
        }
        ?>
        <section class="ssf-stadgar__section ssf-stadgar__history" aria-labelledby="ssf-history-title">
            <div class="ssf-stadgar__section-heading">
                <h2 id="ssf-history-title">Tidigare versioner</h2>
                <p>Tidigare stadgar sparas här för transparens och historik.</p>
            </div>
            <ol class="ssf-stadgar__history-list">
                <?php foreach ($history as $document) : ?>
                    <?php $data = $this->documents->data($document->ID); ?>
                    <?php $pdf_url = $this->documents->pdf_url($document->ID); ?>
                    <li>
                        <div>
                            <h3><?php echo esc_html($document->post_title); ?></h3>
                            <p>
                                <?php if ($data['adopted_date']) : ?><?php echo esc_html($this->format_date($data['adopted_date'])); ?><?php elseif ($data['version']) : ?>Version <?php echo esc_html($data['version']); ?><?php endif; ?>
                            </p>
                            <?php if ($data['change_note']) : ?><p class="ssf-stadgar__change-note"><?php echo esc_html($data['change_note']); ?></p><?php endif; ?>
                        </div>
                        <?php if ($pdf_url) : ?><a href="<?php echo esc_url($pdf_url); ?>" target="_blank" rel="noopener">Ladda ner PDF</a><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
        </section>
        <?php
    }

    private function online_content(string $content, array $outline): string
    {
        $content = $this->add_heading_anchors($content, $outline);
        return apply_filters('the_content', $content);
    }

    private function add_heading_anchors(string $content, array $outline): string
    {
        if (! $outline || ! $content) {
            return $content;
        }

        return (string) preg_replace_callback(
            '#<h([2-4])([^>]*)>(.*?)</h\\1>#is',
            function (array $matches) use ($outline): string {
                if (preg_match('/\\sid=["\']/i', $matches[2])) {
                    return $matches[0];
                }

                $heading = $this->normalise_heading(wp_strip_all_tags($matches[3]));
                foreach ($outline as $item) {
                    if ($heading === $this->normalise_heading($item['title'])) {
                        return '<h' . $matches[1] . $matches[2] . ' id="' . esc_attr($item['anchor']) . '">' . $matches[3] . '</h' . $matches[1] . '>';
                    }
                }

                return $matches[0];
            },
            $content
        );
    }

    private function normalise_heading(string $heading): string
    {
        return trim(preg_replace('/\\s+/u', ' ', wp_strip_all_tags($heading)) ?: '');
    }

    private function format_date(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? wp_date('j F Y', $timestamp) : $date;
    }
}

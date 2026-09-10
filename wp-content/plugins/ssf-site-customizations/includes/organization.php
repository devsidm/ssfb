<?php
/**
 * Public organization, membership fee and payment information.
 *
 * @package SSF_Site
 */

if (! defined('ABSPATH')) {
    exit;
}

function ssf_site_membership_fees_shortcode(): string
{
    if (! class_exists('SSF_Organization_Info')) {
        return '';
    }
    $info = SSF_Organization_Info::get();
    $fees = SSF_Organization_Info::membership_fees();
    ob_start();
    ?>
    <section class="ssf-page-section ssf-membership-fees" aria-labelledby="ssf-membership-fees-title">
        <h2 id="ssf-membership-fees-title">Medlemsavgifter</h2>
        <p>Medlemsavgiften betalas per kalenderår. För fartyg avgör fartygskategorin vilken avgift som gäller.</p>
        <div class="ssf-fee-grid">
            <?php foreach ($fees as $key => $fee) : ?>
                <article class="ssf-fee-item ssf-fee-item--<?php echo esc_attr($key); ?>">
                    <h3><?php echo esc_html($fee['label']); ?></h3>
                    <p class="ssf-fee-item__amount"><?php echo esc_html($fee['amount']); ?></p>
                    <p><?php echo esc_html('support' === $key ? 'För dig som vill stödja SSF:s arbete.' : 'Avgiften gäller för varje anslutet fartyg.'); ?></p>
                </article>
            <?php endforeach; ?>
        </div>
        <div class="ssf-payment-info">
            <h3>Så betalar du</h3>
            <dl>
                <div><dt>Bankgiro</dt><dd><?php echo esc_html($info['bankgiro']); ?></dd></div>
                <div><dt>Swish</dt><dd><?php echo esc_html($info['swish']); ?></dd></div>
            </dl>
            <p>Som stödmedlem anger du ditt namn som meddelande. Vid en fartygsansökan anger du ansökningsnumret som finns i bekräftelsemejlet.</p>
            <p><strong>Medlemsavgiften behöver vara betald innan SSF börjar behandla en fartygsansökan.</strong></p>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('ssf_membership_fees', 'ssf_site_membership_fees_shortcode');

function ssf_site_organization_info_shortcode(): string
{
    if (! class_exists('SSF_Organization_Info')) {
        return '';
    }
    $info = SSF_Organization_Info::get();
    ob_start();
    ?>
    <section class="ssf-page-section ssf-organization-info" aria-labelledby="ssf-organization-info-title">
        <h2 id="ssf-organization-info-title">Organisationsuppgifter</h2>
        <div class="ssf-organization-info__grid">
            <div><h3>Adress</h3><address><?php foreach (SSF_Organization_Info::address_lines() as $line) : ?><?php echo esc_html($line); ?><br><?php endforeach; ?></address></div>
            <dl>
                <div><dt>Organisationsnummer</dt><dd><?php echo esc_html($info['organization_number']); ?></dd></div>
                <div><dt>Bankgiro</dt><dd><?php echo esc_html($info['bankgiro']); ?></dd></div>
                <div><dt>Swish</dt><dd><?php echo esc_html($info['swish']); ?></dd></div>
                <div><dt>Webb</dt><dd><a href="<?php echo esc_url($info['website_url']); ?>"><?php echo esc_html($info['website_label']); ?></a></dd></div>
            </dl>
        </div>
    </section>
    <?php
    return (string) ob_get_clean();
}
add_shortcode('ssf_organization_info', 'ssf_site_organization_info_shortcode');

function ssf_site_append_managed_information(string $content): string
{
    if (is_admin() || ! in_the_loop() || ! is_main_query() || ! is_page()) {
        return $content;
    }
    $slug = (string) get_post_field('post_name', get_queried_object_id());
    if ('medlemskap' === $slug && ! has_shortcode($content, 'ssf_membership_fees')) {
        return $content . "\n[ssf_membership_fees]";
    }
    if ('forbundet' === $slug && ! has_shortcode($content, 'ssf_organization_info')) {
        return $content . "\n[ssf_organization_info]";
    }
    return $content;
}
add_filter('the_content', 'ssf_site_append_managed_information', 9);

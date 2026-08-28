<?php
/**
 * Public fallback when the member vessels feature is unavailable.
 *
 * @package SSF_Medlemsfartyg
 */

if (! defined('ABSPATH')) {
    exit;
}

get_header();
?>
<main class="ssf-ships-page">
    <?php echo class_exists('SSF_Feature_Manager') ? SSF_Feature_Manager::unavailable_markup('member_vessels') : '<p>Medlemsfartygen är inte tillgängliga just nu.</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
</main>
<?php
get_footer();

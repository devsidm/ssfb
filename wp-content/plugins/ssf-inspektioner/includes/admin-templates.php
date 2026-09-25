<?php
if (! defined('ABSPATH') || ! SSF_Inspections_Core::manager()) {
    wp_die('Åtkomst nekad.');
}

global $wpdb;
$notice = '';
$error = '';
if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '')) {
    check_admin_referer('ssf_inspection_templates');
    $action = sanitize_key(wp_unslash($_POST['template_action'] ?? ''));
    $template_id = absint($_POST['template_id'] ?? 0);
    $version_id = absint($_POST['version_id'] ?? 0);
    $result = null;
    if ('create' === $action) {
        $result = SSF_Inspections_Templates::create(sanitize_text_field(wp_unslash($_POST['name'] ?? '')));
    } elseif ('section' === $action) {
        $result = SSF_Inspections_Templates::add_section($template_id, $version_id, sanitize_text_field(wp_unslash($_POST['title'] ?? '')));
    } elseif ('item' === $action) {
        $result = SSF_Inspections_Templates::add_item($template_id, $version_id, absint($_POST['section_id'] ?? 0), array(
            'title' => wp_unslash($_POST['title'] ?? ''), 'help_text' => wp_unslash($_POST['help_text'] ?? ''),
            'required' => ! empty($_POST['required']), 'photo_policy' => sanitize_key($_POST['photo_policy'] ?? 'optional'),
        ));
    } elseif ('publish' === $action) {
        $result = SSF_Inspections_Templates::publish($template_id, $version_id);
    } elseif ('duplicate' === $action) {
        $result = SSF_Inspections_Templates::duplicate($template_id, $version_id);
    } elseif ('archive' === $action) {
        $result = SSF_Inspections_Templates::archive($template_id);
    } elseif ('save_section' === $action && SSF_Inspections_Templates::draft($template_id, $version_id)) {
        $result = $wpdb->update(SSF_Inspections_DB::table('sections'), array(
            'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
            'sort_order' => intval($_POST['sort_order'] ?? 0),
        ), array('id' => absint($_POST['section_id'] ?? 0), 'version_id' => $version_id));
    } elseif ('save_item' === $action && SSF_Inspections_Templates::draft($template_id, $version_id)) {
        $section_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('sections') . ' WHERE id=%d AND version_id=%d', absint($_POST['section_id'] ?? 0), $version_id));
        if ($section_id) {
            $result = $wpdb->update(SSF_Inspections_DB::table('items'), array(
                'title' => sanitize_text_field(wp_unslash($_POST['title'] ?? '')),
                'help_text' => sanitize_textarea_field(wp_unslash($_POST['help_text'] ?? '')),
                'is_required' => ! empty($_POST['required']) ? 1 : 0,
                'photo_policy' => in_array($_POST['photo_policy'] ?? '', array('none', 'optional', 'required'), true) ? $_POST['photo_policy'] : 'optional',
                'sort_order' => intval($_POST['sort_order'] ?? 0),
            ), array('id' => absint($_POST['item_id'] ?? 0), 'section_id' => $section_id));
        }
    } elseif ('delete_item' === $action && SSF_Inspections_Templates::draft($template_id, $version_id)) {
        $section_id = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('sections') . ' WHERE id=%d AND version_id=%d', absint($_POST['section_id'] ?? 0), $version_id));
        if ($section_id) {
            $result = $wpdb->delete(SSF_Inspections_DB::table('items'), array('id' => absint($_POST['item_id'] ?? 0), 'section_id' => $section_id));
        }
    } elseif ('seed' === $action && false !== strpos(home_url('/'), '/dev/')) {
        $created = SSF_Inspections_Templates::create('SSF testmall – fartygsinspektion');
        if (! is_wp_error($created)) {
            foreach (array('Allmänt', 'Skrov', 'Däck', 'Rigg och master', 'Maskin och tekniska system', 'Säkerhet', 'Inredning', 'Dokumentation', 'Helhetsbedömning') as $title) {
                $section_id = SSF_Inspections_Templates::add_section($created['template_id'], $created['version_id'], $title);
                if (! is_wp_error($section_id)) {
                    SSF_Inspections_Templates::add_item($created['template_id'], $created['version_id'], $section_id, array('title' => $title . ' – allmänt skick', 'help_text' => 'Dokumentera iakttagelser ombord.', 'required' => true));
                }
            }
            $result = $created;
        } else {
            $result = $created;
        }
    }
    if (is_wp_error($result)) {
        $error = $result->get_error_message();
    } elseif (null === $result || false === $result) {
        $error = 'Åtgärden kunde inte utföras.';
    } else {
        $notice = 'Ändringen sparades.';
        if (is_array($result)) {
            $template_id = (int) $result['template_id'];
            $version_id = (int) $result['version_id'];
        }
    }
}

$templates = $wpdb->get_results('SELECT * FROM ' . SSF_Inspections_DB::table('templates') . ' ORDER BY id DESC', ARRAY_A);
$selected = absint($_GET['template_id'] ?? $template_id ?? 0);
$selected_version = absint($_GET['version_id'] ?? $version_id ?? 0);
if ($selected && ! $selected_version) {
    $selected_version = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . SSF_Inspections_DB::table('versions') . ' WHERE template_id=%d ORDER BY version DESC LIMIT 1', $selected));
}
$version = $selected_version ? $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('versions') . ' WHERE id=%d AND template_id=%d', $selected_version, $selected), ARRAY_A) : null;
$sections = $version ? $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('sections') . ' WHERE version_id=%d ORDER BY sort_order,id', $selected_version), ARRAY_A) : array();

echo '<div class="wrap"><h1>Inspektionsmallar</h1>';
if ($notice) {
    echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
}
if ($error) {
    echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
}
echo '<h2>Skapa mall</h2><form method="post">';
wp_nonce_field('ssf_inspection_templates');
echo '<input type="hidden" name="template_action" value="create"><input name="name" required placeholder="Mallens namn"><button class="button button-primary">Skapa utkast</button></form>';
if (false !== strpos(home_url('/'), '/dev/')) {
    echo '<form method="post">';
    wp_nonce_field('ssf_inspection_templates');
    echo '<input type="hidden" name="template_action" value="seed"><button class="button">Skapa explicit DEV-testmall</button></form>';
}
echo '<h2>Mallar</h2><ul>';
foreach ($templates as $template) {
    echo '<li><a href="' . esc_url(add_query_arg(array('page' => 'ssf-inspection-templates', 'template_id' => $template['id']), admin_url('admin.php'))) . '">' . esc_html($template['name']) . '</a> · ' . esc_html($template['status']) . '</li>';
}
echo '</ul>';
if ($selected) {
    $template = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('templates') . ' WHERE id=%d', $selected), ARRAY_A);
    if ($template) {
        echo '<h2>' . esc_html($template['name']) . '</h2>';
        $versions = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('versions') . ' WHERE template_id=%d ORDER BY version DESC', $selected), ARRAY_A);
        foreach ($versions as $v) {
            echo '<a class="button" href="' . esc_url(add_query_arg(array('page' => 'ssf-inspection-templates', 'template_id' => $selected, 'version_id' => $v['id']), admin_url('admin.php'))) . '">Version ' . (int) $v['version'] . ' · ' . esc_html($v['status']) . '</a> ';
        }
        if ('active' === $template['status']) {
            echo '<form method="post">';
            wp_nonce_field('ssf_inspection_templates');
            echo '<input type="hidden" name="template_action" value="archive"><input type="hidden" name="template_id" value="' . $selected . '"><button class="button">Arkivera mall</button></form>';
        }
    }
}
if ($version) {
    echo '<h2>Version ' . (int) $version['version'] . ' · ' . esc_html($version['status']) . '</h2>';
    if ('published' === $version['status']) {
        echo '<p>Publicerade versioner är låsta och ändrar aldrig redan startade inspektioner.</p><form method="post">';
        wp_nonce_field('ssf_inspection_templates');
        echo '<input type="hidden" name="template_action" value="duplicate"><input type="hidden" name="template_id" value="' . $selected . '"><input type="hidden" name="version_id" value="' . $selected_version . '"><button class="button button-primary">Duplicera till nytt utkast</button></form>';
    }
    foreach ($sections as $section) {
        echo '<div class="postbox" style="padding:16px"><h3>' . esc_html($section['title']) . '</h3>';
        if ('draft' === $version['status']) {
            echo '<form method="post">';
            wp_nonce_field('ssf_inspection_templates');
            echo '<input type="hidden" name="template_action" value="save_section"><input type="hidden" name="template_id" value="' . $selected . '"><input type="hidden" name="version_id" value="' . $selected_version . '"><input type="hidden" name="section_id" value="' . (int) $section['id'] . '"><input name="title" value="' . esc_attr($section['title']) . '" required><input type="number" name="sort_order" value="' . (int) $section['sort_order'] . '" style="width:70px"><button class="button">Spara sektion</button></form>';
        }
        $items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . SSF_Inspections_DB::table('items') . ' WHERE section_id=%d ORDER BY sort_order,id', $section['id']), ARRAY_A);
        foreach ($items as $item) {
            echo '<p><strong>' . esc_html($item['title']) . '</strong> · ' . ($item['is_required'] ? 'Obligatorisk' : 'Valfri') . ' · Foto: ' . esc_html($item['photo_policy']) . '</p>';
            if ('draft' === $version['status']) {
                echo '<form method="post">';
                wp_nonce_field('ssf_inspection_templates');
                echo '<input type="hidden" name="template_action" value="save_item"><input type="hidden" name="template_id" value="' . $selected . '"><input type="hidden" name="version_id" value="' . $selected_version . '"><input type="hidden" name="section_id" value="' . (int) $section['id'] . '"><input type="hidden" name="item_id" value="' . (int) $item['id'] . '"><input name="title" value="' . esc_attr($item['title']) . '" required><input name="help_text" value="' . esc_attr($item['help_text']) . '" placeholder="Hjälptext"><input type="number" name="sort_order" value="' . (int) $item['sort_order'] . '" style="width:70px"><label><input type="checkbox" name="required" value="1" ' . checked($item['is_required'], 1, false) . '> Obligatorisk</label><select name="photo_policy">';
                foreach (array('none' => 'Inget foto', 'optional' => 'Foto valfritt', 'required' => 'Foto krävs') as $key => $label) {
                    echo '<option value="' . esc_attr($key) . '" ' . selected($item['photo_policy'], $key, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select><button class="button">Spara punkt</button></form>';
                echo '<form method="post" onsubmit="return confirm(\'Ta bort kontrollpunkten?\')">';
                wp_nonce_field('ssf_inspection_templates');
                echo '<input type="hidden" name="template_action" value="delete_item"><input type="hidden" name="template_id" value="' . $selected . '"><input type="hidden" name="version_id" value="' . $selected_version . '"><input type="hidden" name="section_id" value="' . (int) $section['id'] . '"><input type="hidden" name="item_id" value="' . (int) $item['id'] . '"><button class="button">Ta bort punkt</button></form>';
            }
        }
        if ('draft' === $version['status']) {
            echo '<form method="post">';
            wp_nonce_field('ssf_inspection_templates');
            echo '<input type="hidden" name="template_action" value="item"><input type="hidden" name="template_id" value="' . $selected . '"><input type="hidden" name="version_id" value="' . $selected_version . '"><input type="hidden" name="section_id" value="' . (int) $section['id'] . '"><input name="title" required placeholder="Ny kontrollpunkt"><input name="help_text" placeholder="Hjälptext"><label><input type="checkbox" name="required" value="1" checked> Obligatorisk</label><select name="photo_policy"><option value="optional">Foto valfritt</option><option value="required">Foto krävs</option><option value="none">Inget foto</option></select><button class="button">Lägg till punkt</button></form>';
        }
        echo '</div>';
    }
    if ('draft' === $version['status']) {
        echo '<form method="post">';
        wp_nonce_field('ssf_inspection_templates');
        echo '<input type="hidden" name="template_action" value="section"><input type="hidden" name="template_id" value="' . $selected . '"><input type="hidden" name="version_id" value="' . $selected_version . '"><input name="title" required placeholder="Ny sektion"><button class="button">Lägg till sektion</button></form><form method="post">';
        wp_nonce_field('ssf_inspection_templates');
        echo '<input type="hidden" name="template_action" value="publish"><input type="hidden" name="template_id" value="' . $selected . '"><input type="hidden" name="version_id" value="' . $selected_version . '"><button class="button button-primary">Publicera version</button></form>';
    }
}
echo '</div>';

<?php

namespace SSF\MemberPortal\Modules\Motions;

use SSF\MemberPortal\Core\Settings;

if (! defined('ABSPATH')) {
    exit;
}

final class MotionFiles
{
    private const FIELD = 'ssf_motion_files';
    private const ALLOWED_TYPES = array('application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    public function validate(array $files): ?\WP_Error
    {
        if (empty($files[self::FIELD]['name']) || ! is_array($files[self::FIELD]['name'])) {
            return new \WP_Error('motion_document_required', __('Bifoga hela motionen som PDF- eller Word-dokument.', 'ssf-member-portal'));
        }
        $max = (int) Settings::all()['max_upload_mb'] * MB_IN_BYTES;
        $has_file = false;
        foreach ($files[self::FIELD]['name'] as $index => $name) {
            if (! $name || UPLOAD_ERR_NO_FILE === (int) ($files[self::FIELD]['error'][$index] ?? UPLOAD_ERR_NO_FILE)) {
                continue;
            }
            $has_file = true;
            if (UPLOAD_ERR_OK !== (int) $files[self::FIELD]['error'][$index]) {
                return new \WP_Error('upload_error', __('En bilaga kunde inte laddas upp.', 'ssf-member-portal'));
            }
            if ((int) ($files[self::FIELD]['size'][$index] ?? 0) > $max) {
                return new \WP_Error('upload_size', sprintf(__('Varje bilaga får vara högst %d MB.', 'ssf-member-portal'), (int) Settings::all()['max_upload_mb']));
            }
            $tmp_name = (string) ($files[self::FIELD]['tmp_name'][$index] ?? '');
            $checked = $tmp_name ? wp_check_filetype_and_ext($tmp_name, (string) $name) : array();
            $type = (string) ($checked['type'] ?? '');
            if (! $type || ! in_array($type, self::ALLOWED_TYPES, true)) {
                return new \WP_Error('upload_type', __('Endast PDF-, Word- och DOCX-filer kan bifogas.', 'ssf-member-portal'));
            }
        }
        return $has_file ? null : new \WP_Error('motion_document_required', __('Bifoga hela motionen som PDF- eller Word-dokument.', 'ssf-member-portal'));
    }

    public function attach(array $files, int $motion_id)
    {
        if (empty($files[self::FIELD]['name']) || ! is_array($files[self::FIELD]['name'])) {
            return new \WP_Error('motion_document_required', __('Bifoga hela motionen som PDF- eller Word-dokument.', 'ssf-member-portal'));
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $ids = array();
        foreach ($files[self::FIELD]['name'] as $index => $name) {
            if (! $name || UPLOAD_ERR_OK !== (int) ($files[self::FIELD]['error'][$index] ?? UPLOAD_ERR_NO_FILE)) {
                continue;
            }
            $_FILES['ssf_member_portal_file'] = array('name' => $name, 'type' => $files[self::FIELD]['type'][$index], 'tmp_name' => $files[self::FIELD]['tmp_name'][$index], 'error' => $files[self::FIELD]['error'][$index], 'size' => $files[self::FIELD]['size'][$index]);
            $attachment_id = media_handle_upload('ssf_member_portal_file', $motion_id);
            if (is_wp_error($attachment_id)) {
                foreach ($ids as $uploaded_id) {
                    wp_delete_attachment($uploaded_id, true);
                }
                unset($_FILES['ssf_member_portal_file']);
                return new \WP_Error('motion_document_save', __('Motionens dokument kunde inte sparas. Försök igen.', 'ssf-member-portal'));
            }
            $ids[] = (int) $attachment_id;
        }
        unset($_FILES['ssf_member_portal_file']);
        if (! $ids) {
            return new \WP_Error('motion_document_required', __('Bifoga hela motionen som PDF- eller Word-dokument.', 'ssf-member-portal'));
        }
        update_post_meta($motion_id, '_ssf_mp_file_ids', $ids);
        return $ids;
    }
}

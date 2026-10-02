<?php
/** GET displays confirmation only. Files are touched only after an authorized POST. */
function cherry_gallery_admin_action() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Access denied.', 'sakurairo'), '', array('response' => 403));
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    $input = $method === 'POST' ? $_POST : $_GET;
    $operation = $input['operation'] ?? '';
    if (!is_string($operation) || !in_array($operation, array('init', 'webp'), true)) {
        wp_die('Invalid gallery operation.', '', array('response' => 400));
    }
    if ($method === 'GET') {
        $label = $operation === 'init' ? __('Rebuild gallery index', 'sakurairo') : __('Create WebP copies', 'sakurairo');
        $form = '<p>' . esc_html__('Original image files will be preserved.', 'sakurairo') . '</p>'
            . '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">'
            . '<input type="hidden" name="action" value="cherry_gallery">'
            . '<input type="hidden" name="operation" value="' . esc_attr($operation) . '">'
            . wp_nonce_field('cherry_gallery_' . $operation, '_wpnonce', true, false)
            . '<button type="submit">' . esc_html($label) . '</button></form>';
        wp_die($form, esc_html($label), array('response' => 200, 'back_link' => true));
    }
    if ($method !== 'POST') {
        wp_die('Method not allowed.', '', array('response' => 405));
    }
    check_admin_referer('cherry_gallery_' . $operation);
    require_once __DIR__ . '/classes/gallery.php';
    $gallery = new \Sakura\API\gallery();
    $result = $operation === 'init' ? $gallery->init() : $gallery->webp();
    wp_die(wp_kses_post($result), esc_html__('Gallery', 'sakurairo'), array('response' => 200, 'back_link' => true));
}
add_action('admin_post_cherry_gallery', 'cherry_gallery_admin_action');

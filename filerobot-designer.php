<?php
/*
Plugin Name: Filerobot Web2Print Designer (Popup)
Description: Открывает Filerobot Editor в новом окне и сохраняет дизайн в заказ WooCommerce.
Version: 2.0
Author: Viktor
*/

define('FD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FD_PLUGIN_PATH', plugin_dir_path(__FILE__));

add_action('wp_enqueue_scripts', function() {
    if (is_product()) {
        $image_url = get_the_post_thumbnail_url(get_the_ID(), 'full');
        wp_enqueue_script('fd-editor', FD_PLUGIN_URL . 'assets/js/editor.js', ['jquery'], null, true);
        wp_localize_script('fd-editor', 'fd_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('fd_nonce'),
            'image_url' => $image_url,
            'popup_url' => FD_PLUGIN_URL . 'popup/index.html'
        ]);
    }
});

add_action('woocommerce_after_add_to_cart_button', function() {
    echo '<button id="open-designer" type="button" class="button alt">Vytvoriť dizajn</button>';
    echo '<input type="hidden" id="fd-design-url" name="fd-design-url" value="">';
});

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order) {
    $design_url = WC()->session->get('fd_design_file');
    if ($design_url) {
        $item->add_meta_data('Dizajn zákazníka', $design_url);
        WC()->session->__unset('fd_design_file');
    }
}, 10, 4);

add_action('wp_ajax_fd_save_design', 'fd_save_design');
add_action('wp_ajax_nopriv_fd_save_design', 'fd_save_design');
function fd_save_design() {
    check_ajax_referer('fd_nonce', 'nonce');

    if (!isset($_POST['image']) || empty($_POST['image'])) {
        wp_send_json_error('No image provided.');
    }

    $image_data = $_POST['image'];
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $image_data));
    $filename = 'design-' . time() . '.png';
    $upload = wp_upload_bits($filename, null, $decoded);

    if ($upload['error']) {
        wp_send_json_error($upload['error']);
    }

    WC()->session->set('fd_design_file', $upload['url']);
    wp_send_json_success(['url' => $upload['url']]);
}

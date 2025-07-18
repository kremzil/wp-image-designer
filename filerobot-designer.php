<?php
/*
Plugin Name: Filerobot Web2Print Designer (Popup)
Description: Открывает Filerobot Editor в новом окне и сохраняет дизайн в заказ WooCommerce.
Version: 2.1
Author: Viktor
*/

define('FD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FD_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('FD_VERSION', '2.1');

/**
 * Return array of files in directory by allowed extensions.
 */
function fd_list_files($dir) {
    $patterns = ['*.svg', '*.png', '*.jpg', '*.jpeg', '*.webp'];
    $files = [];
    if (!is_dir($dir)) {
        return $files;
    }
    foreach ($patterns as $pattern) {
        $files = array_merge($files, glob(trailingslashit($dir) . $pattern));
    }
    return $files;
}

/**
 * Generate gallery array for Filerobot editor.
 */
function fd_get_clipart_gallery() {
    $gallery = [];

    $builtin = [
        [
            'path' => FD_PLUGIN_PATH . 'assets/cliparts/icons',
            'url'  => plugins_url('assets/cliparts/icons', __FILE__),
        ],
        [
            'path' => FD_PLUGIN_PATH . 'assets/cliparts/backgrounds',
            'url'  => plugins_url('assets/cliparts/backgrounds', __FILE__),
        ],
    ];

    $upload = wp_upload_dir();
    $custom = [
        [
            'path' => trailingslashit($upload['basedir']) . 'wp-image-designer/cliparts/icons',
            'url'  => trailingslashit($upload['baseurl']) . 'wp-image-designer/cliparts/icons',
        ],
        [
            'path' => trailingslashit($upload['basedir']) . 'wp-image-designer/cliparts/backgrounds',
            'url'  => trailingslashit($upload['baseurl']) . 'wp-image-designer/cliparts/backgrounds',
        ],
    ];

    foreach (array_merge($builtin, $custom) as $set) {
        if (!is_dir($set['path'])) {
            continue;
        }

        foreach (fd_list_files($set['path']) as $file) {
            $url = $set['url'] . '/' . basename($file);
            $gallery[] = [
                'originalUrl' => $url,
                'previewUrl'  => $url
            ];
        }
    }

    return $gallery;
}


// Add product setting to enable the designer
add_action('woocommerce_product_options_general_product_data', function() {
    global $product_object;
    $value = $product_object ? $product_object->get_meta('_fd_enable_designer') : 'no';
    woocommerce_wp_checkbox([
        'id'          => '_fd_enable_designer',
        'label'       => __('Enable Filerobot Designer', 'filerobot-designer'),
        'desc_tip'    => true,
        'description' => __('Allow customers to design this product.', 'filerobot-designer'),
        'value'       => $value,
    ]);
});

add_action('woocommerce_admin_process_product_object', function($product) {
    $enabled = isset($_POST['_fd_enable_designer']) && wc_string_to_bool(wp_unslash($_POST['_fd_enable_designer'])) ? 'yes' : 'no';
    $product->update_meta_data('_fd_enable_designer', $enabled);
});

add_action('wp_enqueue_scripts', function() {
    if (is_product()) {
        $enabled = get_post_meta(get_the_ID(), '_fd_enable_designer', true);
        if ($enabled === 'yes') {
            $image_url = esc_url(get_the_post_thumbnail_url(get_the_ID(), 'full'));
            wp_enqueue_script('fd-editor', FD_PLUGIN_URL . 'assets/js/editor.js', ['jquery'], FD_VERSION, true);
            wp_localize_script('fd-editor', 'fd_ajax', [
                'ajax_url'  => admin_url('admin-ajax.php'),
                'nonce'     => wp_create_nonce('fd_nonce'),
                'image_url' => $image_url,
                'popup_url' => esc_url(FD_PLUGIN_URL . 'popup/index.html'),
                'gallery'   => fd_get_clipart_gallery(),
            ]);
        }
    }
});

add_action('woocommerce_after_add_to_cart_button', function() {
    $enabled = get_post_meta(get_the_ID(), '_fd_enable_designer', true);
    if ($enabled === 'yes') {
        echo '<button id="open-designer" type="button" class="button alt">' . esc_html__('Vytvoriť dizajn', 'filerobot-designer') . '</button>';
        echo '<input type="hidden" id="fd-design-url" name="fd-design-url" value="">';
    }
});

add_action('woocommerce_checkout_create_order_line_item', function($item, $cart_item_key, $values, $order) {
    $design_url = WC()->session->get('fd_design_file');
    if ($design_url) {
        $item->add_meta_data('Dizajn zákazníka', esc_url($design_url));
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

    $image_data = sanitize_textarea_field($_POST['image']);
    $decoded = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $image_data));
    $filename = 'design-' . time() . '.png';
    $upload = wp_upload_bits($filename, null, $decoded);

    if ($upload['error']) {
        wp_send_json_error($upload['error']);
    }

    WC()->session->set('fd_design_file', $upload['url']);
    wp_send_json_success(['url' => $upload['url']]);
}

// -------------------- Admin Cliparts Page --------------------
add_action('admin_menu', 'fd_register_admin_page');
function fd_register_admin_page() {
    add_menu_page('WP Image Designer', 'WP Image Designer', 'manage_options', 'fd-main', '__return_null', 'dashicons-format-image');
    add_submenu_page('fd-main', 'Cliparty', 'Cliparty', 'manage_options', 'fd-cliparts', 'fd_cliparts_page');
}

function fd_cliparts_page() {
    if (!current_user_can('manage_options')) {
        return;
    }

    $upload = wp_upload_dir();
    $base_dir = trailingslashit($upload['basedir']) . 'wp-image-designer/cliparts';
    $base_url = trailingslashit($upload['baseurl']) . 'wp-image-designer/cliparts';
    $icons_dir = $base_dir . '/icons';
    $backgrounds_dir = $base_dir . '/backgrounds';

    // Handle upload
    if (isset($_POST['fd_upload_nonce']) && wp_verify_nonce($_POST['fd_upload_nonce'], 'fd_upload')) {
        $type = in_array($_POST['clipart_type'] ?? '', ['icons', 'backgrounds'], true) ? $_POST['clipart_type'] : 'icons';
        if (!empty($_FILES['clipart']['name'])) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            $attachment_id = media_handle_upload('clipart', 0);
            if (!is_wp_error($attachment_id)) {
                $file = get_attached_file($attachment_id);
                $filename = sanitize_file_name(basename($file));
                $dest_dir = ($type === 'backgrounds') ? $backgrounds_dir : $icons_dir;
                wp_mkdir_p($dest_dir);
                copy($file, trailingslashit($dest_dir) . $filename);
            }
        }
    }

    // Handle delete
    if (isset($_GET['fd_delete'], $_GET['fd_type']) && wp_verify_nonce($_GET['_wpnonce'], 'fd_delete')) {
        $type = in_array($_GET['fd_type'], ['icons', 'backgrounds'], true) ? $_GET['fd_type'] : 'icons';
        $file = basename(sanitize_file_name(wp_unslash($_GET['fd_delete'])));
        $path = (($type === 'backgrounds') ? $backgrounds_dir : $icons_dir) . '/' . $file;
        if (file_exists($path)) {
            unlink($path);
        }
    }

    $icons = fd_list_files($icons_dir);
    $backgrounds = fd_list_files($backgrounds_dir);

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Cliparts', 'filerobot-designer'); ?></h1>
        <form method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('fd_upload', 'fd_upload_nonce'); ?>
            <input type="file" name="clipart" required>
            <select name="clipart_type">
                <option value="icons"><?php esc_html_e('Icon', 'filerobot-designer'); ?></option>
                <option value="backgrounds"><?php esc_html_e('Background', 'filerobot-designer'); ?></option>
            </select>
            <input type="submit" class="button button-primary" value="<?php esc_attr_e('Upload', 'filerobot-designer'); ?>">
        </form>

        <h2><?php esc_html_e('Icons', 'filerobot-designer'); ?></h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php foreach ($icons as $icon) : ?>
                <div style="text-align:center;">
                    <img src="<?php echo esc_url($base_url . '/icons/' . basename($icon)); ?>" style="max-width:80px;height:auto;" />
                    <br>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=fd-cliparts&fd_delete=' . urlencode(basename($icon)) . '&fd_type=icons'), 'fd_delete')); ?>" onclick="return confirm('<?php esc_attr_e('Delete?', 'filerobot-designer'); ?>');">Delete</a>
                </div>
            <?php endforeach; ?>
        </div>

        <h2><?php esc_html_e('Backgrounds', 'filerobot-designer'); ?></h2>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <?php foreach ($backgrounds as $bg) : ?>
                <div style="text-align:center;">
                    <img src="<?php echo esc_url($base_url . '/backgrounds/' . basename($bg)); ?>" style="max-width:80px;height:auto;" />
                    <br>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=fd-cliparts&fd_delete=' . urlencode(basename($bg)) . '&fd_type=backgrounds'), 'fd_delete')); ?>" onclick="return confirm('<?php esc_attr_e('Delete?', 'filerobot-designer'); ?>');">Delete</a>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php
}
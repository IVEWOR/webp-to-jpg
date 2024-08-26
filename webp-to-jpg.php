<?php
/*
Plugin Name: WebP to JPG Converter
Plugin URI: https://deepslog.com
Description: A plugin to convert WebP images to JPG format and update URLs in the WordPress database.
Version: 1.0
Author: Deepak Jangra
Author URI: https://deepslog.com
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: webp-to-jpg
Requires PHP: 7.0
Requires at least: 5.6
Tested up to: 6.6.1
*/

// Enqueue the JavaScript file in the admin area
function enqueue_webp_to_jpg_script_admin()
{
    wp_enqueue_script('webp-to-jpg-js', plugin_dir_url(__FILE__) . 'webp-to-jpg.js', array('jquery'), null, true);
}
add_action('admin_enqueue_scripts', 'enqueue_webp_to_jpg_script_admin');

// Add a menu page to the WordPress admin
function add_webp_to_jpg_menu()
{
    add_menu_page(
        'WebP to JPG Converter',       // Page title
        'WebP to JPG',                 // Menu title
        'manage_options',              // Capability
        'webp-to-jpg',                 // Menu slug
        'webp_conversion_page',        // Function to display the page content
        'dashicons-images-alt2',       // Icon URL
        81                             // Position
    );
}
add_action('admin_menu', 'add_webp_to_jpg_menu');

// Function to display the conversion page
function webp_conversion_page()
{
?>
    <div class="wrap">
        <h1>WebP to JPG Converter</h1>
        <form id="webp-conversion-form" method="post" action="<?php echo admin_url('admin-ajax.php'); ?>">
            <input type="hidden" name="action" value="convert_all_webp">
            <?php wp_nonce_field('convert_all_webp', 'nonce'); ?>
            <input type="submit" value="Convert All WebP to JPG">
        </form>
        <div id="conversion-result"></div>
    </div>
<?php
}

// AJAX handler for converting all WebP images
function convert_all_webp_images()
{
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'convert_all_webp')) {
        wp_send_json_error('Invalid nonce');
    }

    // Get all WebP images
    global $wpdb;
    $results = $wpdb->get_results("
        SELECT ID, guid
        FROM {$wpdb->posts}
        WHERE post_mime_type = 'image/webp'
    ");

    if (!$results) {
        wp_send_json_error('No WebP images found');
    }

    foreach ($results as $image) {
        $attachment_id = $image->ID;
        $webp_to_jpg_converter = new WebP_To_JPG_Converter();

        if (!$webp_to_jpg_converter->convert_single_image($attachment_id)) {
            wp_send_json_error('Conversion failed for image ID ' . $attachment_id);
        }
    }

    wp_send_json_success('All WebP images have been converted to JPG');
}
add_action('wp_ajax_convert_all_webp', 'convert_all_webp_images');

// WebP to JPG Converter class
class WebP_To_JPG_Converter
{
    private $image;

    public function convert_single_image($attachment_id)
    {
        if (get_post_type($attachment_id) != 'attachment') {
            return false;
        }

        $this->image = [
            'ID' => intval($attachment_id),
            'link' => wp_get_attachment_url($attachment_id),
            'path' => get_attached_file($attachment_id)
        ];

        if (file_exists($this->image['path'])) {
            // Convert image
            if ($this->convert_image($this->image)) {
                $this->update_image_data();
                return true;
            }
        }

        return false;
    }

    private function convert_image($params)
    {
        $image_path = $params['path'];
        $new_image_path = str_replace('.webp', '.jpg', $image_path);

        // Load WebP image
        $image = imagecreatefromwebp($image_path);
        if (!$image) {
            return false;
        }

        // Save as JPG
        if (!imagejpeg($image, $new_image_path)) {
            imagedestroy($image);
            return false;
        }
        imagedestroy($image);

        // Update image metadata
        $this->image['new_path'] = $new_image_path;
        $this->image['new_url'] = str_replace('.webp', '.jpg', wp_get_attachment_url($params['ID']));

        return true;
    }

    private function update_image_data()
    {
        global $wpdb;

        $old_name = basename($this->image['link']);
        $new_name = basename($this->image['new_url']);

        $thumbs = wp_get_attachment_metadata($this->image['ID']);
        foreach ($thumbs['sizes'] as $img) {
            $thumb = dirname($this->image['path']) . '/' . $img['file'];
            if (file_exists($thumb)) {
                $new_thumb = str_replace('.webp', '.jpg', $img['file']);
                if ($old_name !== $new_name) {
                    $new_thumb = str_replace($old_name, $new_name, $new_thumb);
                }
                unlink($thumb);
            }
        }

        wp_update_post([
            'ID' => $this->image['ID'],
            'post_mime_type' => 'image/jpeg'
        ]);

        $wpdb->update(
            $wpdb->posts,
            ['guid' => $this->image['new_url']],
            ['ID' => $this->image['ID']],
            ['%s'],
            ['%d']
        );

        $meta = get_post_meta($this->image['ID'], '_wp_attached_file', true);
        $meta = str_replace(basename($this->image['link']), basename($this->image['new_url']), $meta);
        update_post_meta($this->image['ID'], '_wp_attached_file', $meta);

        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($this->image['ID'], $this->image['new_path']);
        update_post_meta($this->image['ID'], '_wp_attachment_metadata', $attach_data);

        // Update URLs in the database
        $replaces = [$old_name => $new_name];
        foreach ($replaces as $old => $new) {
            $wpdb->query("
                UPDATE {$wpdb->posts}
                SET post_content = REPLACE(post_content, '/{$old}', '/{$new}')
                WHERE post_content LIKE '%/{$old}%'
            ");
            $wpdb->query("
                UPDATE {$wpdb->posts}
                SET post_excerpt = REPLACE(post_excerpt, '/{$old}', '/{$new}')
                WHERE post_excerpt LIKE '%/{$old}%'
            ");
            $wpdb->query("
                UPDATE {$wpdb->postmeta}
                SET meta_value = REPLACE(meta_value, '/{$old}', '/{$new}')
                WHERE meta_value LIKE '%/{$old}%'
            ");
            $wpdb->query("
                UPDATE {$wpdb->options}
                SET option_value = REPLACE(option_value, '/{$old}', '/{$new}')
                WHERE option_value LIKE '%/{$old}%'
            ");
        }
    }
}

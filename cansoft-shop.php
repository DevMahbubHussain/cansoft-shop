<?php

/**
 * Plugin Name: Cansoft Fetch To Shop
 * Plugin URI: https://cansoft.com/plugins/fetch-to-shop/
 * Description: Fetch To Cansoft Shop
 * Version: 1.0.0
 * Requires at least: 5.2
 * Requires PHP: 7.2
 * Author: Mahbub Hussain
 * Author URI: https://mahbub.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI: https://example.com/my-plugin/
 * Text Domain: cansoft-shop
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main Wrapper class
 */
class CansoftFetchToShop
{

    /**
     * The unique identifier (slug) for the Cansoft Shop plugin.
     * 
     * @var string
     */
    public $slug = 'cansoft-shop';

    /**
     * Constructor
     */
    public function __construct()
    {
        register_activation_hook(__FILE__, array($this, 'cansoft_activate'));
        register_deactivation_hook(__FILE__, array($this, 'cansoft_deactivate'));
        add_action('admin_menu', array($this, 'cansoft_init_menu'));
        add_action('init', array($this, 'register_custom_taxonomies'));
        add_filter('manage_edit-product_columns', array($this, 'cansoft_add_brand_column'));
        add_action('manage_product_posts_custom_column', array($this, 'cansoft_populate_brand_column'), 10, 2);
    }

    /**
     * Do stuff during plugin activation.
     *
     * @return void
     */
    public function cansoft_activate()
    {
        add_option('cansoft_product_gallery', '1');
    }

    /**
     * Do stuff during plugin deactivation.
     *
     * @return void
     */
    public function cansoft_deactivate()
    {
        delete_option('cansoft_product_gallery');
    }

    /**
     * Registers a custom taxonomy called 'cansoft_brand' for the 'product' post type.
     *
     * This function defines a new taxonomy for categorizing products by brands.
     *
     *
     * @return void
     */
    public function register_custom_taxonomies()
    {
        register_taxonomy('cansoft_brand', array('product'), array(
            'label' => __('Brands', 'cansoft-shop'),
            'rewrite' => array('slug' => 'brand'),
            'hierarchical' => false,
        ));
    }

    /**
     * Add 'Brand' column to admin screen for 'product' post type.
     * 
     * @param array $columns The existing columns in the admin screen.
     * @return array Modified columns with the 'Brand' column added.
     */

    public function cansoft_add_brand_column($columns)
    {
        $columns['cansoft_brand'] = __('Brand', 'cansoft-shop');
        return $columns;
    }

    /**
     * Populate 'Brand' column with brand info for each product.
     * 
     * @param string $column
     * @param init $post_id
     * @return void
     */

    public function cansoft_populate_brand_column($column, $post_id)
    {
        if ($column === 'cansoft_brand') {
            $brand_terms = get_the_terms($post_id, 'cansoft_brand');
            if ($brand_terms && !is_wp_error($brand_terms)) {
                $brand_links = array();

                foreach ($brand_terms as $brand_term) {
                    $brand_link = get_term_link($brand_term, 'cansoft_brand');
                    if (!is_wp_error($brand_link)) {
                        $brand_links[] = '<a href="' . esc_url($brand_link) . '">' . esc_html($brand_term->name) . '</a>';
                    } else {
                        $brand_links[] = esc_html($brand_term->name);
                    }
                }

                echo implode(', ', $brand_links);
            }
            else {
                esc_html_e('Failed to assign category or brand.', 'cansoft-shop');
            }
        }
    }

    /**
     * Initialize the admin menu for the Cansoft Shop plugin.
     *
     * @return void
     */
    public function cansoft_init_menu()
    {
        $menu_position = 50;
        $capability    = 'manage_options';
        $logo_icon = 'dashicons-plugins-checked';
        add_menu_page(esc_attr__('Cansoft Shop', ' cansoft-shop'), esc_attr__('Cansoft Shop', ' cansoft-shop'), $capability, $this->slug, [$this, 'cansoft_plugin_page'], $logo_icon, $menu_position);
    }

    /**
     * Plugin page logic for importing products from an external API.
     *
     * This function handles the process of fetching product data from an external API
     * and creating WooCommerce products based on the retrieved data. It also associates
     * categories and brands with the created products.
     *
     * @return void
     */

    public function cansoft_plugin_page()
    {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            esc_html_e('Please install and activate WooCommerce to use this plugin.', 'cansoft-shop');
            return;
        }
        $product_gallery_images_options_value = sanitize_text_field(get_option('cansoft_product_gallery', '0')); // Default to '0'
        include __DIR__ . '/includes/views/form.php';

        if (isset($_POST['updated']) && $_POST['updated'] === 'true') {

            if (!isset($_POST['submit_fetch_product'])) {
                return;
            }
            if (!wp_verify_nonce($_POST['cansoft_data_fetch_nonce'], 'cansoft_data_fetch_nonce') || !current_user_can('manage_options')) {
                wp_die(__('Security check failed. Please try again.', 'cansoft-shop'));
            }

            //update options
            $product_gallery_images_options_value = isset($_POST['cansoft_product_gallery']) && $_POST['cansoft_product_gallery'] === '1' ? '1' : '0';
            update_option('cansoft_product_gallery', $product_gallery_images_options_value);

            // Check if the method exists and call it based on the checkbox value
            if (method_exists($this, 'cansoft_render_data')) {
                if ($product_gallery_images_options_value === '1') {
                    $this->cansoft_render_data(true);  // Fetch with images
                } elseif ($product_gallery_images_options_value === '0') {
                    $this->cansoft_render_data(false); // Fetch without images
                } else {
                    wp_die(__('An error occurred while processing the data.', 'cansoft-shop'));
                }

                // Flush rewrite rules to update permalinks
                flush_rewrite_rules();
            } else {
                wp_die(__('An error occurred while processing the data.', 'cansoft-shop'));
            }
            exit();
        }
    }
    /**
     * Fetches data from the API, creates WooCommerce products, and assigns categories and brands.
     *
     * @return void
     */
    public function cansoft_render_data($fetch_images = true)
    {
        $response  = $this->cansoft_fetch_api_data();

        if (is_array($response) && !is_wp_error($response)) {
            $parsed_data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($parsed_data) && isset($parsed_data['products'])) {
                foreach ($parsed_data['products'] as $product_data) {
                    $new_product_id = $this->cansoft_create_product($product_data);
                    if ($new_product_id) {
                        $this->cansoft_product_category($new_product_id, $product_data['category']);
                        $this->cansoft_product_brand($new_product_id, $product_data['brand']);

                        if ($fetch_images) {
                            $this->cansoft_product_images($new_product_id, $product_data);
                        }
                        // echo  $new_product_id . '<br>';
                    }
                }
                esc_html_e('Product created Successfully', 'cansoft-shop');
            } else {
                esc_html_e('Invalid or missing data structure.', 'cansoft-shop');
            }
        } else {
            $error_message = is_wp_error($response) ? $response->get_error_message() : __('Unknown error', 'cansoft-shop');
            esc_html_e('Failed to fetch data', 'cansoft-shop');
            echo $error_message;
        }
    }

    /**
     * Fetches data from the external API endpoint.
     *
     * @return WP_Error|array The API response on success, or a WP_Error object on failure.
     */
    private function cansoft_fetch_api_data()
    {
        $products_endpoint = 'https://dummyjson.com/products';
        return wp_remote_get($products_endpoint);
    }

    /**
     * Creates a new WooCommerce product using provided data.
     *
     * @param array $product_data The data for the product.
     * @return int|false The ID of the newly created product on success, or false on failure.
     */
    private function cansoft_create_product($product_data)
    {
        $cansoft_new_product = new WC_Product_Simple();
        $cansoft_new_product->set_name($product_data['title']);
        $cansoft_new_product->set_short_description($product_data['description']);
        $cansoft_new_product->set_regular_price($product_data['price']);
        $cansoft_new_product->set_sale_price($product_data['discountPercentage']);
        $cansoft_new_product->set_rating_counts($product_data['rating']);
        $cansoft_new_product->set_stock_quantity($product_data['stock']);
        return $cansoft_new_product->save();
    }

    /**
     * Assigns the given product to a product category.
     *
     * @param int $product_id The ID of the product.
     * @param string $category_name The name of the product category.
     * @return void
     */
    private function cansoft_product_category($product_id, $category_name)
    {
        $category_term = term_exists($category_name, 'product_cat');
        if (!$category_term) {
            $category_term = wp_insert_term($category_name, 'product_cat');
        }
        if ($category_term) {
            wp_set_post_terms($product_id, $category_term['term_id'], 'product_cat', true);
        } else {
            esc_html_e('Failed to assign category:', 'cansoft-shop');
            echo $category_name . '<br>';
        }
    }

    /**
     * Assigns the given product to a brand taxonomy term.
     *
     * @param int $product_id The ID of the product.
     * @param string $brand_name The name of the brand.
     * @return void
     */
    private function cansoft_product_brand($product_id, $brand_name)
    {
        $sanitized_brand_name = sanitize_title($brand_name); // Sanitize and format the brand name
        $brand_term = term_exists($sanitized_brand_name, 'cansoft_brand');
        if (!$brand_term) {
            $brand_term = wp_insert_term($brand_name, 'cansoft_brand');
        }
        if ($brand_term && !is_wp_error($brand_term)) {
            $brand_term_id = $brand_term['term_id'];
            wp_set_post_terms($product_id, array($brand_term_id), 'cansoft_brand', true);
        } else {
            esc_html_e('Failed to assign brand:', 'cansoft-shop');
            echo $brand_name . '<br>';
        }
    }

    /**
     * Handles the attachment of images to a product and updates gallery images.
     *
     * @param int $product_id The ID of the product.
     * @param array $product_data The data for the product, including image URLs.
     * @return void
     */
    private function cansoft_product_images($product_id, $product_data)
    {
        $thumbnail_url = $product_data['thumbnail'];
        $thumbnail_id = $this->cansoft_sideload_image($thumbnail_url);

        if (!is_wp_error($thumbnail_id)) {
            $product = wc_get_product($product_id);
            $product->set_image_id($thumbnail_id);

            // Handling product gallery images
            $gallery_ids = $this->cansoft_product_gallery($product_data);
            $product->set_gallery_image_ids($gallery_ids);

            // Save the product again to update gallery images
            $product->save();

            // Set the product thumbnail
            set_post_thumbnail($product_id, $thumbnail_id);
        } else {
            esc_html_e('Failed to download and attach thumbnail image:', 'cansoft-shop');
            echo esc_url($thumbnail_url) . '<br>';
        }
    }


    /**
     * Sideload an image from a URL and return the attachment ID.
     *
     * @param string $image_url The URL of the image to sideload.
     * @return int|false The attachment ID if successful, false on failure.
     */
    private function cansoft_sideload_image($image_url)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp = download_url($image_url);
        if (is_wp_error($tmp)) {
            return false;
        }

        // $desc = esc_html_e('Downloaded image from given url.', 'cansoft-shop');
        $desc = '';

        $file_array = array(
            'name' => basename($image_url),
            'tmp_name' => $tmp,
        );

        // Sideload the image
        $thumbnail_id = media_handle_sideload($file_array, 0, $desc);

        if (is_wp_error($thumbnail_id)) {
            @unlink($file_array['tmp_name']);
            return false;
        }

        return $thumbnail_id;
    }

    /**
     * Process and attach gallery images to a WooCommerce product.
     *
     * @param array $product_data An array.
     * @return array An array of gallery image IDs. 
     */
    private function cansoft_product_gallery($product_data)
    {
        $gallery_ids = array();
        if (isset($product_data['images']) && is_array($product_data['images'])) {
            foreach ($product_data['images'] as $product_gallery_images) {
                $gallery_id = $this->cansoft_sideload_image($product_gallery_images);
                if ($gallery_id) {
                    $gallery_ids[] = $gallery_id;
                }
            }
        }
        return $gallery_ids;
    }
}

//kick of the plugin
new CansoftFetchToShop();

<div class="wrap">
    <h2>Cansoft Fetch To Shop</h2>
    <form method="post" action="">
        <input type="hidden" name="updated" value="true" />
        <?php wp_nonce_field('cansoft_data_fetch_nonce', 'cansoft_data_fetch_nonce'); ?>
        <table class="form-table">
            <tbody>
                <tr>
                    <th><label for="cansoft_product_gallery"><?php _e('Enable Product Gallery', 'cansoft-shop'); ?></label></th>
                    <td>
                        <input type="checkbox" id="cansoft_product_gallery" name="cansoft_product_gallery" value="1" <?php checked($product_gallery_images_options_value, '1'); ?>>
                        <p class="epm-description"><?php _e('If selected, product gallery images will be fetched and downloaded', 'cansoft-shop'); ?></p>
                        <p><?php _e('<strong>Please note that fetching data from the external URL may take some time, especially if images are being fetched. Your patience is appreciated.</strong>', 'cansoft-shop'); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="submit">
            <?php submit_button(__('Fetch Products', 'cansoft-shop'), 'primary', 'submit_fetch_product'); ?>
        </p>
    </form>
</div>
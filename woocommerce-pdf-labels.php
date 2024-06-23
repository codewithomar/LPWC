<?php

/**
 * Plugin Name: WooCommerce PDF Labels
 * Description: Generate PDF labels for WooCommerce products.
 * Version: 1.4
 * License: GPL2
 * Author: Omar Faruk
 * Author URI: https://www.logicean.com
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Enqueue your custom CSS file
function wc_pdf_labels_custom_admin_css()
{
    wp_enqueue_style('wc-pdf-labels-admin-css', plugin_dir_url(__FILE__) . 'assets/admin-styles.css');
}
add_action('admin_enqueue_scripts', 'wc_pdf_labels_custom_admin_css');

// Include mPDF library autoload
require_once(plugin_dir_path(__FILE__) . 'libraries/vendor/autoload.php');

// Define font directory path
$fontDir = plugin_dir_path(__FILE__) . 'libraries/fonts/';

// Add a new font definition for Noto Serif Bengali
$defaultConfig = (new Mpdf\Config\ConfigVariables())->getDefaults();
$fontDirs = $defaultConfig['fontDir'];

$defaultFontConfig = (new Mpdf\Config\FontVariables())->getDefaults();
$fontData = $defaultFontConfig['fontdata'];

// Initialize mPDF with custom settings
$mpdf = new \Mpdf\Mpdf([
    'fontDir' => array_merge($fontDirs, [$fontDir]),
    'fontdata' => $fontData + [
        'notosansbengali' => [
            'R' => 'NotoSerifBengali-Regular.ttf',
            'B' => 'NotoSerifBengali-Bold.ttf',
            'useOTL' => 0xFF,   // Enable all OpenType Layout features
            'useKashida' => 75, // Length of kashida strokes (not typically used for Bengali)
        ],
    ],
    'format' => [101.6, 101.6], // Set the page size to 4x4 inches
    'default_font' => 'notosansbengali', // Set default font to Noto Serif Bengali
]);

// Add admin menu
add_action('admin_menu', 'wc_pdf_labels_admin_menu');
function wc_pdf_labels_admin_menu()
{
    add_menu_page('PDF Labels', 'PDF Labels', 'manage_options', 'wc-pdf-labels', 'wc_pdf_labels_page');
}

// Admin page content for product search and display
function wc_pdf_labels_page()
{
    // Handle pagination and search
    $paged = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
    $posts_per_page = 25;
    $search_query = isset($_GET['product_search']) ? sanitize_text_field($_GET['product_search']) : '';

    $args = array(
        'post_type' => 'product',
        's' => $search_query,
        'posts_per_page' => $posts_per_page,
        'paged' => $paged,
    );

    $query = new WP_Query($args);
?>

    <div class="wcpdf wrap">
        <h1>Generate PDF Labels</h1>
        <form method="get" action="">
            <input type="hidden" name="page" value="wc-pdf-labels">
            <label for="product_search">Search Product:</label>
            <input type="search" id="product_search" name="product_search" value="<?php echo esc_attr($search_query); ?>">
            <button type="submit">Search</button>
        </form>

        <?php
        if ($query->have_posts()) {
            echo '<h2>Products:</h2>';
            echo '<ul>';
            $serial_number = ($paged - 1) * $posts_per_page + 1; // Calculate starting serial number
            while ($query->have_posts()) {
                $query->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);

                // Check if product has variations
                if ($product->is_type('variable')) {
                    // Get variations
                    $variations = $product->get_available_variations();

                    foreach ($variations as $variation) {
                        $variation_id = $variation['variation_id'];
                        $variation_obj = wc_get_product($variation_id);

                        // Get variation weight
                        $variation_weight = $variation_obj->get_weight();

                        // Display variation name, weight, and link to generate PDF
                        echo '<li>';
                        echo '<span class="serial-number">' . $serial_number . ' - ' . ' </span>';
                        echo '<a href="' . esc_url(admin_url('admin-post.php?action=generate_pdf_label&product_id=' . $variation_id)) . '" target="_blank">';
                        echo esc_html($variation_obj->get_name());
                        echo '</a>';
                        echo '<ul>';
                        echo '</ul>';
                        echo '</li>';

                        $serial_number++; // Increment serial number
                    }
                } else {
                    // Display simple product
                    echo '<li>';
                    echo '<span class="serial-number">' . $serial_number . '</span>';
                    echo '<a href="' . esc_url(admin_url('admin-post.php?action=generate_pdf_label&product_id=' . $product_id)) . '" target="_blank">';
                    echo esc_html($product->get_name());
                    echo '</a>';
                    echo '</li>';

                    $serial_number++; // Increment serial number
                }
            }
            echo '</ul>';

            // Pagination
            $total_pages = $query->max_num_pages;
            if ($total_pages > 1) {
                echo '<div class="tablenav"><div class="tablenav-pages">';
                echo paginate_links(array(
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'current' => max(1, $paged),
                    'total' => $total_pages,
                    'add_args' => array('product_search' => urlencode($search_query)),
                ));
                echo '</div></div>';
            }
        } else {
            echo '<p>No products found.</p>';
        }
        wp_reset_postdata();
        ?>
    </div>
<?php
}



// Add action for PDF generation
add_action('admin_post_generate_pdf_label', 'wc_generate_pdf_label');
function wc_generate_pdf_label()
{
    if (!isset($_GET['product_id']) || empty($_GET['product_id'])) {
        wp_die('Product ID is required.');
    }

    $product_id = absint($_GET['product_id']);
    $product = wc_get_product($product_id);

    if (!$product) {
        wp_die('Invalid product.');
    }

    // Check if product is a variation
    if ($product->is_type('variation')) {
        // Get parent product to display variation details
        $parent_product_id = $product->get_parent_id();
        $parent_product = wc_get_product($parent_product_id);

        $product_name = $parent_product->get_name();
        $product_weight = $product->get_weight(); // Variation weight
        $product_price = $product->get_regular_price(); // Variation price
    } else {
        $product_name = $product->get_name();
        $product_weight = $product->get_weight();
        $product_price = $product->get_regular_price();
    }

    $current_date = date_i18n('Y-m-d');

    // HTML content for the PDF label
    ob_start();
?>
    <html>

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
        <style>
            body {
                font-family: 'notosansbengali', sans-serif;
                font-size: 15px;
            }

            img {
                max-width: 120px;
                /* Adjust max-width as needed */
                height: auto;
            }
        </style>
    </head>

    <body>
        <div class="logo-container">
            <img src="<?php echo plugin_dir_url(__FILE__) . 'assets/logo.png'; ?>" alt="Logo">
        </div>
        <p>নাম: <?php echo htmlspecialchars($product_name, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php if ($product_weight) : ?>
            <p>ওজন: <?php echo htmlspecialchars($product_weight, ENT_QUOTES, 'UTF-8'); ?> গ্রাম</p>
        <?php endif; ?>
        <p>মূল্য: ৳<?php echo htmlspecialchars($product_price, ENT_QUOTES, 'UTF-8'); ?></p>
        <p>প্যাকেজিংয়ের তারিখ: <?php echo htmlspecialchars($current_date, ENT_QUOTES, 'UTF-8'); ?></p>
        <p>প্যাকেজিং এর তারিখ হতে ১৮ মাস খাওয়ার উপযোগী।</p>
    </body>

    </html>
<?php
    $html = ob_get_clean();

    // Load HTML into mPDF
    global $mpdf;
    $mpdf->WriteHTML($html);

    // Output PDF
    $mpdf->Output('ProductLabel.pdf', \Mpdf\Output\Destination::INLINE);
    exit;
}

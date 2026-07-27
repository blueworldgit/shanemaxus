<?php
// ============================================================
// FIX: Vehicle & Department page titles (override AIOSEO)
// ============================================================
add_filter( 'pre_get_document_title', 'mvp_fix_page_titles', 999 );
add_filter( 'aioseo_title', 'mvp_fix_page_titles_aioseo', 999 );

function mvp_fix_page_titles( $title ) {
    $vehicle_slug = get_query_var( 'mvp_vehicle' );
    if ( $vehicle_slug ) {
        $maxus_term_id = mvp_get_maxus_term_id();
        $vin_terms = get_terms( array(
            'taxonomy'   => 'product_cat',
            'parent'     => $maxus_term_id,
            'hide_empty' => false,
            'meta_query' => array( array( 'key' => 'vehicle_slug', 'value' => sanitize_title( $vehicle_slug ) ) ),
        ) );
        if ( ! is_wp_error( $vin_terms ) && ! empty( $vin_terms ) ) {
            $model = get_term_meta( $vin_terms[0]->term_id, 'vehicle_model', true );
            return ( $model ? $model : 'Vehicle' ) . ' - Maxus Parts Direct';
        }
    }

    $dept_slug = get_query_var( 'mvp_department' );
    if ( $dept_slug ) {
        $dept_name = ucwords( str_replace( '-', ' ', $dept_slug ) );
        $vehicle_slug2 = get_query_var( 'mvp_dept_vehicle' );
        if ( $vehicle_slug2 ) {
            $maxus_term_id = mvp_get_maxus_term_id();
            $vin_terms = get_terms( array(
                'taxonomy'   => 'product_cat',
                'parent'     => $maxus_term_id,
                'hide_empty' => false,
                'meta_query' => array( array( 'key' => 'vehicle_slug', 'value' => sanitize_title( $vehicle_slug2 ) ) ),
            ) );
            if ( ! is_wp_error( $vin_terms ) && ! empty( $vin_terms ) ) {
                $model = get_term_meta( $vin_terms[0]->term_id, 'vehicle_model', true );
                return $dept_name . ' - ' . ( $model ? $model : $vehicle_slug2 ) . ' - Maxus Parts Direct';
            }
        }
        return $dept_name . ' - Maxus Parts Direct';
    }

    return $title;
}

function mvp_fix_page_titles_aioseo( $title ) {
    $custom = mvp_fix_page_titles( '' );
    return $custom ? $custom : $title;
}
require_once get_stylesheet_directory() . "/trade-account-form.php";

function mobex_enovathemes_child_scripts() {
    wp_enqueue_style( 'mobex_enovathemes-parent-style', get_template_directory_uri(). '/style.css' );
}
add_action( 'wp_enqueue_scripts', 'mobex_enovathemes_child_scripts' );

// Replace product SKU with original_sku meta field ONLY for frontend display
// This does NOT affect order processing, inventory, or any backend operations
add_filter( 'woocommerce_product_get_sku', 'mvp_use_original_sku_on_frontend', 10, 2 );
add_filter( 'woocommerce_product_variation_get_sku', 'mvp_use_original_sku_on_frontend', 10, 2 );
function mvp_use_original_sku_on_frontend( $sku, $product ) {
    // Skip if in admin area
    if ( is_admin() ) {
        return $sku;
    }
    
    // Skip during AJAX requests (checkout, cart updates, etc.)
    if ( wp_doing_ajax() ) {
        return $sku;
    }
    
    // Skip during REST API requests (order processing, inventory sync, etc.)
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
        return $sku;
    }
    
    // Skip during cron jobs
    if ( wp_doing_cron() ) {
        return $sku;
    }
    
    // Skip during any order/cart processing to ensure backend operations use real SKU
    $wc_actions = array(
        'woocommerce_checkout_process',
        'woocommerce_checkout_order_processed',
        'woocommerce_new_order',
        'woocommerce_order_status_changed',
        'woocommerce_add_to_cart',
        'woocommerce_cart_item_removed',
        'woocommerce_update_cart_action_cart_updated',
    );
    foreach ( $wc_actions as $action ) {
        if ( did_action( $action ) || doing_action( $action ) ) {
            return $sku;
        }
    }
    
    // Only replace for display purposes on frontend
    $original_sku = get_post_meta( $product->get_id(), 'original_sku', true );
    if ( $original_sku ) {
        return $original_sku;
    }
    
    return $sku;
}

add_action('after_switch_theme', 'mobex_child_repair_theme_mods_and_kirki_css');
add_action('admin_init', 'mobex_child_repair_theme_mods_and_kirki_css_once');

function mobex_child_repair_theme_mods_and_kirki_css_once() {
    // If we already repaired, skip.
    if (get_option('mobex_child_theme_mods_repaired')) {
        return;
    }
    $did = mobex_child_repair_theme_mods_and_kirki_css();
    if ($did) {
        update_option('mobex_child_theme_mods_repaired', 1);
    }
}

/**
 * Returns true if it actually migrated/changed anything.
 */
function mobex_child_repair_theme_mods_and_kirki_css() {
    $parent = get_template();
    $child  = get_stylesheet();
    if ($parent === $child) {
        // Not a child setup.
        return false;
    }
}

/**
 * Custom REST API endpoint to find products NOT in a specific category
 */
add_action('rest_api_init', function () {
    register_rest_route('custom/v1', '/products-not-in-category', array(
        'methods' => 'GET',
        'callback' => 'get_products_not_in_category',
        'permission_callback' => function() {
            return current_user_can('edit_products');
        }
    ));
});

function get_products_not_in_category($request) {
    $exclude_category = $request->get_param('exclude_category');
    $page = $request->get_param('page') ?: 1;
    $per_page = 100;
    
    if (!$exclude_category) {
        return new WP_Error('missing_param', 'exclude_category parameter required', array('status' => 400));
    }
    
    global $wpdb;
    
    // Find all product IDs that DO NOT have the specified category
    // This excludes products that have this category in their term relationships
    $offset = ($page - 1) * $per_page;
    
    $query = "
        SELECT DISTINCT p.ID 
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND p.ID NOT IN (
            SELECT object_id 
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id = %d
            AND tt.taxonomy = 'product_cat'
        )
        ORDER BY p.ID
        LIMIT %d OFFSET %d
    ";
    
    $product_ids = $wpdb->get_col($wpdb->prepare($query, $exclude_category, $per_page, $offset));
    
    // Get total count
    $count_query = "
        SELECT COUNT(DISTINCT p.ID) 
        FROM {$wpdb->posts} p
        WHERE p.post_type = 'product'
        AND p.post_status = 'publish'
        AND p.ID NOT IN (
            SELECT object_id 
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            WHERE tt.term_id = %d
            AND tt.taxonomy = 'product_cat'
        )
    ";
    
    $total = $wpdb->get_var($wpdb->prepare($count_query, $exclude_category));
    $total_pages = ceil($total / $per_page);
    
    return array(
        'ids' => array_map('intval', $product_ids),
        'total' => (int)$total,
        'page' => (int)$page,
        'per_page' => $per_page,
        'total_pages' => (int)$total_pages
    );
}


/**


/**
 * Maxus Van Parts — Homepage Facelift
 *
 * Transforms shane.maxusvanparts.co.uk homepage to match
 * the maxusvanparts.acstestweb.co.uk design.
 *
 * Approach: CSS hides unwanted Elementor sections, JS injects
 * hero banner + vehicle carousel into correct DOM position.
 */


// ============================================================
// 0. SITE-WIDE HEADER — 3-row header matching target site
// ============================================================
add_action( 'wp_head', 'mvp_sitewide_header_css', 998 );
function mvp_sitewide_header_css() {
    ?>
    <link rel="stylesheet" id="mvp-sitewide-header" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-sitewide-header.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-sitewide-header.css' ); ?>">
    <?php
}

// ============================================================
// 0b. INJECT 3-ROW HEADER via wp_footer JS
// ============================================================
add_action( 'wp_footer', 'mvp_sitewide_header_inject', 1 );
function mvp_sitewide_header_inject() {
    $home       = esc_url( home_url( '/' ) );
    $shop       = esc_url( home_url( '/shop/' ) );
    $my_account = esc_url( home_url( '/my-account/' ) );
    $contact    = esc_url( home_url( '/contact-us/' ) );
    $cart       = esc_url( home_url( '/cart/' ) );
    $cart_count = function_exists("WC") && WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
    $logo_url   = esc_url( content_url( '/uploads/mpd-logo-original.webp' ) );
    $ajax_url   = esc_url( admin_url( 'admin-ajax.php' ) );

    // Read nav menu items from the existing Mobex header menu (slug: header-menu-1, term_id: 505)
    $menu_items = wp_get_nav_menu_items( 'header-menu-1' );
    $nav_html = '';
    $vehicle_cats = get_terms( array( 'taxonomy' => 'product_cat', 'parent' => 3590, 'hide_empty' => false ) );
    // Menu = vehicles only: keep terms that have vehicle_model + vehicle_slug, drop mis-categorised
    // leaf departments (Brakes, Air Con, Rear Closure, etc.). Then order popular-first (Deliver 9, then 3...).
    if ( ! is_wp_error( $vehicle_cats ) ) {
        $vehicle_cats = array_values( array_filter( $vehicle_cats, function( $vc ) {
            return get_term_meta( $vc->term_id, 'vehicle_model', true ) && get_term_meta( $vc->term_id, 'vehicle_slug', true );
        } ) );
        usort( $vehicle_cats, 'mvp_vehicle_popularity_cmp' );
    }
    if ( $menu_items ) {
        foreach ( $menu_items as $item ) {
            if ( $item->menu_item_parent != 0 ) continue;
            $is_vehicles = ( strpos( strtolower( $item->title ), 'vehicle' ) !== false );
            if ( $is_vehicles && ! is_wp_error( $vehicle_cats ) && count( $vehicle_cats ) > 0 ) {
                $nav_html .= '<li class="mvp-has-dropdown"><a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . ' <svg width="10" height="6" viewBox="0 0 10 6" style="margin-left:4px;vertical-align:middle;"><path d="M1 1l4 4 4-4" stroke="#fff" stroke-width="1.5" fill="none"/></svg></a>';
                $nav_html .= '<div class="mvp-dropdown mvp-mega" style="display:none;position:absolute;top:100%;left:-50px;z-index:9999;background:#D18A0C;width:340px;box-shadow:0 4px 20px rgba(0,0,0,0.15);border-radius:0 0 6px 6px;padding:12px 0;max-height:500px;overflow-y:auto;">';
                // Group vehicles by model family
                $groups = array();
                foreach ( $vehicle_cats as $vc ) {
                    $model = get_term_meta( $vc->term_id, 'vehicle_model', true );
                    $display = $model ? $model : $vc->name;
                    if ( preg_match( '/^((?:E |New )?(?:Deliver \d+|T\d+|A\d+|V\d+))/i', $display, $m ) ) {
                        $family = $m[1];
                    } else {
                        $family = 'Other';
                    }
                    if ( ! isset( $groups[$family] ) ) $groups[$family] = array();
                    $groups[$family][] = array( 'slug' => (get_term_meta( $vc->term_id, 'vehicle_slug', true ) ?: $vc->slug), 'name' => $display, 'img' => get_term_meta( $vc->term_id, 'vehicle_image', true ) );
                }
                // (groups already in popularity order — no alphabetical ksort)
                foreach ( $groups as $family => $vehicles ) {
                    $nav_html .= '<div style="padding:6px 16px 2px;font-size:11px;font-weight:700;color:rgba(255,255,255,0.6);text-transform:uppercase;letter-spacing:0.5px;">' . esc_html( $family ) . '</div>';
                    foreach ( $vehicles as $v ) {
                        $link = home_url( '/vehicle/' . $v['slug'] . '/' );
                        $nav_html .= '<a href="' . esc_url( $link ) . '" style="display:flex;align-items:center;gap:10px;padding:5px 16px;color:#fff;font-size:13px;font-weight:500;text-decoration:none;white-space:nowrap;">';
                        if ( $v['img'] ) {
                            $nav_html .= '<img src="' . esc_url( $v['img'] ) . '" style="width:40px;height:26px;object-fit:contain;border-radius:2px;background:rgba(255,255,255,0.9);" alt="">';
                        }
                        $nav_html .= esc_html( $v['name'] ) . '</a>';
                    }
                }
                $nav_html .= '</div></li>';
            } elseif ( strpos( strtolower( $item->title ), 'vin' ) !== false ) {
                $nav_html .= '<li class="mvp-has-dropdown mvp-dd-vin"><a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . ' <svg width="10" height="6" viewBox="0 0 10 6" style="margin-left:4px;vertical-align:middle;"><path d="M1 1l4 4 4-4" stroke="#fff" stroke-width="1.5" fill="none"/></svg></a>';
                $nav_html .= '<div class="mvp-dropdown mvp-dd-search" style="display:none;position:absolute;top:100%;left:0;z-index:9999;background:#D18A0C;min-width:400px;box-shadow:0 4px 20px rgba(0,0,0,0.15);border-radius:0 0 6px 6px;padding:16px 20px;">';
                $nav_html .= '<p style="color:#fff;font-size:14px;font-weight:600;margin:0 0 8px;">Search by VIN Number</p>';
                $nav_html .= '<form class="mvp-dd-vin-form" style="display:flex;gap:8px;" action="' . home_url( '/vin-lookup/' ) . '">';
                $nav_html .= '<input type="text" name="vin" placeholder="Enter VIN number" style="flex:1;height:40px;padding:0 12px;border:none;border-radius:4px;font-size:14px;">';
                $nav_html .= '<button type="submit" style="height:40px;padding:0 20px;background:#BF3617;color:#fff;border:none;border-radius:4px;font-weight:600;cursor:pointer;">Search</button>';
                $nav_html .= '</form></div></li>';
            } elseif ( strpos( strtolower( $item->title ), 'registration' ) !== false ) {
                $nav_html .= '<li class="mvp-has-dropdown mvp-dd-reg"><a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . ' <svg width="10" height="6" viewBox="0 0 10 6" style="margin-left:4px;vertical-align:middle;"><path d="M1 1l4 4 4-4" stroke="#fff" stroke-width="1.5" fill="none"/></svg></a>';
                $nav_html .= '<div class="mvp-dropdown mvp-dd-search" style="display:none;position:absolute;top:100%;left:0;z-index:9999;background:#D18A0C;min-width:400px;box-shadow:0 4px 20px rgba(0,0,0,0.15);border-radius:0 0 6px 6px;padding:16px 20px;">';
                $nav_html .= '<p style="color:#fff;font-size:14px;font-weight:600;margin:0 0 8px;">Search by Registration</p>';
                $nav_html .= '<form class="mvp-dd-reg-form" style="display:flex;gap:8px;" action="' . home_url( '/registration-lookup/' ) . '">';
                $nav_html .= '<input type="text" name="reg" placeholder="e.g. AB12 CDE" maxlength="10" style="flex:1;height:40px;padding:0 12px;border:none;border-radius:4px;font-size:14px;text-transform:uppercase;">';
                $nav_html .= '<button type="submit" style="height:40px;padding:0 20px;background:#BF3617;color:#fff;border:none;border-radius:4px;font-weight:600;cursor:pointer;">Search</button>';
                $nav_html .= '</form></div></li>';
            } else {
                $nav_html .= '<li><a href="' . esc_url( $item->url ) . '">' . esc_html( $item->title ) . '</a></li>';
            }
        }
    }
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var wrap = document.getElementById('wrap');
        if (!wrap) return;
        // Don't inject on small screens (mobile uses default header)

        var hdr = document.createElement('div');
        hdr.className = 'mvp-hdr';
        hdr.innerHTML = ''
        /* ── ROW 1: Utility ── */
        + '<div class="mvp-hdr-r1"><div class="mvp-hdr-wrap">'
        +   '<a href="<?php echo $contact; ?>" class="mvp-hdr-r1-ask">'
        +     '<svg viewBox="0 0 24 24"><path d="M12 1c-6.627 0-12 4.208-12 9.399 0 3.356 2.246 6.301 5.625 7.963-.225 2.254-1.365 3.576-1.389 3.601-.078.091-.101.218-.054.329.046.111.152.185.273.185 2.891 0 5.281-1.749 6.543-2.901.324.033.656.05.993.05 6.627 0 12-4.208 12-9.399-.009-5.218-5.382-9.227-11.991-9.227z"/></svg>'
        +     'Ask us a question?'
        +   '</a>'
        +   '<div class="mvp-hdr-r1-social">'
        +     '<span>Stay connected:</span>'
        +     '<a href="https://www.facebook.com/maxusvanpartsdirect" target="_blank" rel="noopener" title="Facebook"><svg viewBox="0 0 24 24"><path d="M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z"/></svg></a>'
        +     '<a href="#" title="Instagram"><svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="5" ry="5" fill="none" stroke="#bbb" stroke-width="2"/><circle cx="12" cy="12" r="5" fill="none" stroke="#bbb" stroke-width="2"/><circle cx="17.5" cy="6.5" r="1.5" fill="#bbb"/></svg></a>'
        +     '<a href="#" title="LinkedIn"><svg viewBox="0 0 24 24"><path d="M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-4 0v7h-4v-7a6 6 0 016-6zM2 9h4v12H2zM4 6a2 2 0 100-4 2 2 0 000 4z"/></svg></a>'
        +     '<a href="#" title="Twitter"><svg viewBox="0 0 24 24"><path d="M23 3a10.9 10.9 0 01-3.14 1.53A4.48 4.48 0 0012 7.5v1A10.66 10.66 0 013 4s-4 9 5 13a11.64 11.64 0 01-7 2c9 5 20 0 20-11.5 0-.28-.03-.56-.08-.83A7.72 7.72 0 0023 3z"/></svg></a>'
        +     '<a href="#" title="YouTube"><svg viewBox="0 0 24 24"><path d="M22.54 6.42a2.78 2.78 0 00-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 00-1.94 2A29 29 0 001 11.75a29 29 0 00.46 5.33 2.78 2.78 0 001.94 2c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 001.94-2 29 29 0 00.46-5.25 29 29 0 00-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02" fill="#fff"/></svg></a>'
        +   '</div>'
        +   '<div class="mvp-hdr-r1-login">'
        +     '<a href="<?php echo $my_account; ?>"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Login</a>'
        +     '<div class="mvp-login-dd">'
        +       '<form action="<?php echo esc_url( wp_login_url( $my_account ) ); ?>" method="post">'
        +         '<input type="text" name="log" placeholder="Username" autocomplete="username">'
        +         '<input type="password" name="pwd" placeholder="Password" autocomplete="current-password">'
        +         '<button type="submit" class="mvp-login-btn">Log In</button>'
        +       '</form>'
        +       '<div class="mvp-login-links">'
        +         '<a href="<?php echo esc_url( wp_lostpassword_url() ); ?>">Forgot password?</a>'
        +         '<a href="<?php echo $my_account; ?>">Sign up</a>'
        +       '</div>'
        +     '</div>'
        +   '</div>'
        + '</div></div>'

        /* ── ROW 2: Logo + search + phone ── */
        + '<div class="mvp-hdr-r2"><div class="mvp-hdr-wrap">'
        +   '<a href="<?php echo $home; ?>" class="mvp-hdr-r2-logo"><img src="<?php echo $logo_url; ?>" alt="Maxus Parts Direct"></a>'
        +   '<a href="<?php echo $home; ?>" class="mvp-hdr-r2-home">Home</a>'
        +   '<div class="mvp-hdr-r2-search">'
        +     '<input type="text" placeholder="Enter a keyword or product SKU" id="mvp-hdr-search-input">'
        +     '<button type="button" onclick="var v=document.getElementById(\'mvp-hdr-search-input\').value;if(v)window.location.href=\'<?php echo $shop; ?>?s=\'+encodeURIComponent(v)+\'&amp;post_type=product\';"></button>'
        +   '</div>'
        +   '<a href="tel:01953528800" class="mvp-hdr-r2-phone">'
        +     '<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6A19.79 19.79 0 012.12 4.18 2 2 0 014.11 2h3a2 2 0 012 1.72c.13.81.37 1.6.65 2.37a2 2 0 01-.45 2.11L8.09 9.42a16 16 0 006 6l1.22-1.22a2 2 0 012.11-.45c.77.28 1.56.52 2.37.65a2 2 0 011.72 2.03z"/></svg>'
        +     '<span class="mvp-hdr-r2-phone-text"><span class="mvp-hdr-r2-phone-num">01953 528800</span><span class="mvp-hdr-r2-phone-sub">Call us between 9 AM - 5 PM</span></span>'
        +   '</a>'
        + '</div></div>'

        /* ── ROW 3: Nav + actions ── */
        + '<div class="mvp-hdr-r3"><div class="mvp-hdr-wrap">'
        +   '<ul class="mvp-hdr-r3-nav"><?php echo $nav_html; ?></ul>'
        +   '<div class="mvp-hdr-r3-actions">'
        +     '<a href="<?php echo $cart; ?>" class="mvp-r3-cart"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1" fill="#fff"/><circle cx="20" cy="21" r="1" fill="#fff"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="mvp-cart-stacked"><span class="mvp-cart-text">Cart</span><span class="mvp-cart-sub"><span class="mvp-cart-badge"><?php echo $cart_count; ?></span>items</span></span></a>'
        +     '<a href="#" class="mvp-r3-myvehicle">My Vehicle</a>'
        +   '</div>'
        + '</div></div>';

        wrap.insertBefore(hdr, wrap.firstChild);

        // Logo hiding removed - using original logo

        // Enter key on search
        document.getElementById('mvp-hdr-search-input').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') { e.preventDefault(); this.nextElementSibling.click(); }
        });
        // Dropdown hover show/hide
        // Dropdown hover show/hide for ALL dropdown menu items
        document.querySelectorAll(".mvp-has-dropdown").forEach(function(ddParent) {
            var ddMenu = ddParent.querySelector(".mvp-dropdown");
            if (!ddMenu) return;
            ddParent.style.position = "relative";
            ddParent.addEventListener("mouseenter", function() { ddMenu.style.display = "block"; });
            ddParent.addEventListener("mouseleave", function() { ddMenu.style.display = "none"; });
            ddMenu.querySelectorAll("a:not([type=submit])").forEach(function(a) {
                a.addEventListener("mouseenter", function() { a.style.background = "rgba(255,255,255,0.15)"; });
                a.addEventListener("mouseleave", function() { a.style.background = ""; });
            });
        });
        var cartObserver = new MutationObserver(function(mutations) {
            mutations.forEach(function(m) {
                m.addedNodes.forEach(function(node) {
                    if (node.nodeType === 1 && node.classList && node.classList.contains("added_to_cart")) {
                        // Badge update
                        var badge = document.querySelector(".mvp-cart-badge");
                        if (badge) badge.textContent = parseInt(badge.textContent || "0") + 1;
                        // Show notification bar
                        var existing = document.querySelector(".mvp-cart-notice");
                        if (existing) existing.remove();
                        var wrapper = document.querySelector(".mvp-cart-notice-area"); if (!wrapper) { var contentArea = document.querySelector(".entry-content, .site-content, .woocommerce, #content, main, #wrap"); if (contentArea) { wrapper = document.createElement("div"); wrapper.className = "mvp-cart-notice-area"; wrapper.style.cssText = "max-width:1320px;margin:0 auto;padding:0 10px;"; var header = document.querySelector(".mvp-hdr-r3, .et-desktop"); if (header) { header.parentNode.insertBefore(wrapper, header.nextSibling); } else { contentArea.insertBefore(wrapper, contentArea.firstChild); } } }
                        if (wrapper) {
                            var productName = "";
                            var card = node.closest("li.product");
                            if (card) {
                                var title = card.querySelector(".woocommerce-loop-product__title, h2, h3");
                                if (title) productName = title.textContent.trim();
                            }
                            var msg = document.createElement("div");
                            msg.className = "woocommerce-message mvp-cart-notice";
                            msg.setAttribute("role", "alert");
                            var linkHtml = '<a href="/cart/" class="button wc-forward" style="background:#BF3617;color:#fff;padding:8px 18px;border-radius:4px;text-decoration:none;font-weight:600;float:right;">View cart</a>';
                            msg.innerHTML = linkHtml + (productName ? "u201c" + productName + "u201d has been added to your cart." : "Product added to your cart.");
                            wrapper.innerHTML = "";
                            wrapper.appendChild(msg);
                            window.scrollTo({top: 0, behavior: "smooth"});
                        }
                    }
                });
            });
        });
        cartObserver.observe(document.body, {childList: true, subtree: true});
    });
    </script>
    <script id="mvp-sitewide-header-inject-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-sitewide-header-inject.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-sitewide-header-inject.js' ); ?>"></script>
    <script id="mvp-moveNotice-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-moveNotice.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-moveNotice.js' ); ?>"></script>
    <?php
}


// ============================================================
// 1. HOMEPAGE CSS — Hide unwanted sections & inject styles
// ============================================================
add_action( 'wp_head', 'mvp_facelift_css', 999 );
function mvp_facelift_css() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <link rel="stylesheet" id="mvp-facelift-css" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-facelift.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-facelift.css' ); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@700&display=swap" rel="stylesheet">
    <?php
}

// ============================================================
// 2. INJECT HERO + VEHICLE CAROUSEL via JavaScript
//    (places them inside #wrap after the header, before content)
// ============================================================
add_action( 'wp_footer', 'mvp_facelift_inject_hero_and_carousel', 1 );
function mvp_facelift_inject_hero_and_carousel() {
    if ( ! is_front_page() && ! is_home() ) return;

    // Build vehicle cards from DB term meta (organic)
    $maxus_term_id = mvp_get_maxus_term_id();
    $vin_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $maxus_term_id,
        'hide_empty' => false,
        'orderby'    => 'name',
    ) );

    $cards = '';
    if ( ! is_wp_error( $vin_terms ) ) {
        usort( $vin_terms, 'mvp_vehicle_popularity_cmp' ); // popular models first: Deliver 9, then Deliver 3, then others
        foreach ( $vin_terms as $term ) {
            $model = get_term_meta( $term->term_id, 'vehicle_model', true );
            $year  = get_term_meta( $term->term_id, 'vehicle_year', true );
            $img   = get_term_meta( $term->term_id, 'vehicle_image', true );
            $slug  = get_term_meta( $term->term_id, 'vehicle_slug', true );
            if ( ! $model || ! $slug ) continue;
            $url = home_url( '/vehicle/' . $slug . '/' );
            $cards .= '<a href="' . esc_url( $url ) . '" class="mvp-vehicle-card">'
                . '<div class="mvp-vehicle-circle"><img src="' . esc_url( $img ) . '" alt="' . esc_attr( $model ) . '" loading="lazy"></div>'
                . '<div class="mvp-vehicle-name">' . esc_html( $model ) . '</div>'
                . '<div class="mvp-vehicle-years">' . esc_html( $year ) . '</div>'
                . '</a>';
        }
    }

    $hero_html = '<div class="mvp-hero">'
        . '<div class="mvp-hero-content">'
        . '<h1>Genuine OEM Parts<span class="hero-sub">Direct From Maxus</span></h1>'
        . '<p>Original factory parts at competitive prices.<br>Perfect fit. Guaranteed quality.</p>'
        /* Shop All Parts button removed */
        . '</div></div>';

    $carousel_html = '<section id="mvp-vehicles" class="mvp-vehicles">'
        . '<div class="mvp-carousel-wrapper">'
        . '<button class="mvp-carousel-nav prev" onclick="document.querySelector(\'.mvp-carousel-track\').scrollBy({left:-220,behavior:\'smooth\'})">&#8249;</button>'
        . '<div class="mvp-carousel-track">' . $cards . '</div>'
        . '<button class="mvp-carousel-nav next" onclick="document.querySelector(\'.mvp-carousel-track\').scrollBy({left:220,behavior:\'smooth\'})">&#8250;</button>'
        . '</div></section>';

    $combined_json = json_encode( $hero_html . $carousel_html );
    ?>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        var target = document.querySelector(".elementor-11641");
        if (!target) return;
        var heroDiv = document.createElement("div");
        heroDiv.id = "mvp-facelift-hero-area";
        heroDiv.innerHTML = <?php echo $combined_json; ?>;
        target.parentNode.insertBefore(heroDiv, target);
    });
    </script>
    <?php
}

// ============================================================
// 3. "WHY USE US?" — Injected before the footer
// ============================================================
add_action( 'wp_footer', 'mvp_facelift_why_us', 5 );
function mvp_facelift_why_us() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <section class="mvp-why-us">
        <h2>Why Use Us?</h2>
        <div class="mvp-why-grid">
            <div class="mvp-why-card">
                <div class="mvp-why-icon"><svg viewBox="0 0 48 48"><path d="M24 4L6 12v12c0 11 8 18 18 20 10-2 18-9 18-20V12L24 4z"/><polyline points="16 24 22 30 34 18"/></svg></div>
                <h3>Genuine OEM Parts</h3>
                <p>All parts are original Maxus or OEM-equivalent, ensuring the right fit and quality for your vehicle.</p>
            </div>
            <div class="mvp-why-card">
                <div class="mvp-why-icon"><svg viewBox="0 0 48 48"><circle cx="24" cy="14" r="8"/><path d="M8 42c0-9 7-16 16-16s16 7 16 16"/></svg></div>
                <h3>Professional Team</h3>
                <p>Expert staff with deep knowledge of the full Maxus range.</p>
            </div>
            <div class="mvp-why-card">
                <div class="mvp-why-icon"><svg viewBox="0 0 48 48"><path d="M24 44s-18-10-18-24a10 10 0 0118-6 10 10 0 0118 6c0 14-18 24-18 24z"/></svg></div>
                <h3>Happy to Help</h3>
                <p>Friendly support to help you find exactly the right part.</p>
            </div>
            <div class="mvp-why-card">
                <div class="mvp-why-icon"><svg viewBox="0 0 48 48"><path d="M4 24c0-11 9-20 20-20s20 9 20 20"/><path d="M4 28v6a4 4 0 004 4h2a2 2 0 002-2v-8a2 2 0 00-2-2H6a4 4 0 00-2 4zm40 0v6a4 4 0 01-4 4h-2a2 2 0 01-2-2v-8a2 2 0 012-2h4a4 4 0 012 4z"/><path d="M40 38c0 4-7 6-16 6"/></svg></div>
                <h3>Great Customer Service</h3>
                <p>Friendly, knowledgeable support from order to delivery.</p>
            </div>
            <div class="mvp-why-card">
                <div class="mvp-why-icon"><svg viewBox="0 0 48 48"><rect x="6" y="8" width="36" height="32" rx="3"/><polyline points="16 24 22 30 34 18"/><line x1="6" y1="16" x2="42" y2="16"/></svg></div>
                <h3>Verified Before Dispatch</h3>
                <p>Every order is checked against your vehicle details before dispatch to ensure the right part is sent.</p>
            </div>
            <div class="mvp-why-card">
                <div class="mvp-why-icon"><svg viewBox="0 0 48 48"><rect x="2" y="10" width="28" height="20" rx="2"/><path d="M30 16h8l6 8v6h-14V16z"/><circle cx="12" cy="34" r="4"/><circle cx="38" cy="34" r="4"/></svg></div>
                <h3>UK Wide Delivery</h3>
                <p>Fast, tracked delivery to anywhere in the United Kingdom.</p>
            </div>
        </div>
    </section>
    <?php
}

// ============================================================
// 4. CUSTOM FOOTER — Matching target site
// ============================================================
add_action( 'wp_footer', 'mvp_facelift_footer', 10 );
function mvp_facelift_footer() {
    ?>
    <footer class="mvp-footer">
        <div class="mvp-footer-main">
            <div class="mvp-footer-col">
                <h4>Maxus Parts Direct</h4>
                <div class="mvp-footer-trading">A trading name of Van Parts Direct Ltd</div>
                <div class="mvp-footer-contact">
                    <p>Unit 1-10, Cherry Tree Road,<br>Tibenham, NR16 1PH</p>
                    <p class="mvp-footer-phone"><a href="tel:01953528800">01953 528 800</a></p>
                    <p><a href="mailto:accounts@vanparts-direct.co.uk">accounts@vanparts-direct.co.uk</a></p>
                </div>
                <div class="mvp-footer-reg">
                    <p>Company Reg: 16322863</p>
                    <p>VAT No: 490 9953 39</p>
                </div>
                <div class="mvp-footer-payments" title="We accept Visa, Mastercard and American Express">
                    <svg class="pay-card" width="46" height="29" viewBox="0 0 46 29" aria-label="Visa"><rect width="46" height="29" rx="4" fill="#fff" stroke="#e5e5e5"/><text x="23" y="20" font-family="Arial,Helvetica,sans-serif" font-size="13" font-weight="bold" font-style="italic" fill="#1A1F71" text-anchor="middle">VISA</text></svg>
                    <svg class="pay-card" width="46" height="29" viewBox="0 0 46 29" aria-label="Mastercard"><rect width="46" height="29" rx="4" fill="#fff" stroke="#e5e5e5"/><circle cx="19" cy="14.5" r="7.5" fill="#EB001B"/><circle cx="27" cy="14.5" r="7.5" fill="#F79E1B" fill-opacity="0.85"/></svg>
                    <svg class="pay-card" width="46" height="29" viewBox="0 0 46 29" aria-label="American Express"><rect width="46" height="29" rx="4" fill="#006FCF"/><text x="23" y="18" font-family="Arial,Helvetica,sans-serif" font-size="8" font-weight="bold" fill="#fff" text-anchor="middle" letter-spacing="0.5">AMEX</text></svg>
                </div>
            </div>
            <div class="mvp-footer-col">
                <h4>Quick Links</h4>
                <ul>
                    <li><a href="/">Home</a></li>
                    <li><a href="/shop/">Shop</a></li>
                    <li><a href="/my-account/">My Account</a></li>
                    <li><a href="/cart/">Cart</a></li>
                </ul>
            </div>
            <div class="mvp-footer-col">
                <h4>Information</h4>
                <ul>
                    <li><a href="/about-us/">About Us</a></li>
                    <li><a href="/contact-us/">Contact Us</a></li>
                    <li><a href="/terms-and-conditions/">Terms &amp; Conditions</a></li>
                    <li><a href="/privacy-policy/">Privacy Policy</a></li>
                    <li><a href="/gdpr-data-protection/">GDPR Data Protection</a></li>
                    <li><a href="/returns-and-exchanges/">Returns &amp; Exchanges</a></li>
                </ul>
            </div>
            <div class="mvp-footer-col">
                <h4>Vehicles</h4>
                <ul>
                <?php
                $maxus_id = mvp_get_maxus_term_id();
                if ($maxus_id) {
                    $vterms = get_terms(array("taxonomy"=>"product_cat","parent"=>$maxus_id,"hide_empty"=>false,"orderby"=>"name"));
                    if (!is_wp_error($vterms)) {
                        foreach ($vterms as $vt) {
                            $vslug = get_term_meta($vt->term_id, "vehicle_slug", true);
                            $vmodel = get_term_meta($vt->term_id, "vehicle_model", true);
                            if ($vslug && $vmodel) {
                                echo "<li><a href=\"/vehicle/" . esc_attr($vslug) . "/\">" . esc_html($vmodel) . "</a></li>";
                            }
                        }
                    }
                }
                ?>
                </ul>
            </div>
            <div class="mvp-footer-col">
                <h4>Customer Service</h4>
                <ul>
                    <li><a href="/my-account/">Login</a></li>
                    <li><a href="/my-account/">Register</a></li>
                    <li><a href="/my-account/orders/">Order History</a></li>
                    <li><a href="/shipping-information/">Shipping Info</a></li>
                    <li><a href="/faq/">FAQ</a></li>
                    <li><a href="/trade-account/">Trade Account</a></li>
                </ul>
            </div>
            <div class="mvp-footer-col">
                <h4>Our Other Services</h4>
                <ul>
                    <li><a href="https://vansalesdirect.uk" target="_blank" rel="noopener">vansalesdirect.uk</a></li>
                    <li><a href="https://direct-vanhire.co.uk" target="_blank" rel="noopener">direct-vanhire.co.uk</a></li>
                    <li><a href="https://rapidfit.co.uk" target="_blank" rel="noopener">rapidfit.co.uk</a></li>
                </ul>
            </div>
        </div>
        <div class="mvp-footer-bottom">
            <div class="mvp-footer-bottom-inner">
                <p class="mvp-footer-copyright">&copy; <?php echo date('Y'); ?> Van Parts Direct Ltd. All rights reserved.</p>
                <div class="mvp-footer-bottom-links">
                    <a href="/privacy-policy/">Privacy Policy</a>
                    <span class="sep">|</span>
                    <a href="/terms-and-conditions/">Terms &amp; Conditions</a>
                </div>
            </div>
        </div>
    </footer>
    <?php
}

// ============================================================
// 4b. MAXUS ROOT TERM HELPER — resolves by slug, not hardcoded ID
// ============================================================

function mvp_get_maxus_term_id() {
    static $id = null;
    if ( $id !== null ) return $id;
    $term = get_term_by( 'slug', 'maxus', 'product_cat' );
    $id   = ( $term && ! is_wp_error( $term ) ) ? (int) $term->term_id : 0;
    return $id;
}


// ============================================================
// 4b. SINGLE PRODUCT PAGE — Match target layout
// ============================================================

// Remove default WooCommerce meta (SKU, categories, tags) — we show our own
add_action( 'wp', function() {
    if ( is_product() ) {
        remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 25 );
    }
});

// Remove reviews tab and additional information tab
add_filter( 'woocommerce_product_tabs', 'mvp_remove_product_tabs', 98 );
function mvp_remove_product_tabs( $tabs ) {
    unset( $tabs['reviews'] );
    unset( $tabs['additional_information'] );
    return $tabs;
}

// Add SKU / Part No / Weight row after title (priority 6, after title at 5)
add_action( 'woocommerce_single_product_summary', 'mvp_product_meta_info', 6 );
function mvp_product_meta_info() {
    global $product;
    $sku    = $product->get_sku();
    $weight = $product->get_weight();
    $w_unit = get_option( 'woocommerce_weight_unit', 'kg' );
    if ( ! $sku && ! $weight ) return;
    echo '<div class="mvp-product-meta-info">';
    if ( $sku ) {
        echo '<span class="meta-label">SKU:</span> <span class="meta-value">' . esc_html( $sku ) . '</span>';
    }
    if ( $weight && $weight > 0 ) {
        if ( $sku ) echo '<span class="meta-sep">|</span>';
        echo '<span class="meta-label">Weight:</span> <span class="meta-value">' . esc_html( $weight . $w_unit ) . '</span>';
    }
    echo '</div>';
}

// Replace Add to Cart with "Request a Price" for products without a price
// ============================================================
// Request Price Modal — for products with no price
// ============================================================
add_action( 'woocommerce_single_product_summary', 'mvp_request_price_button', 29 );
function mvp_request_price_button() {
    global $product;
    if ( $product->get_price() === '' || $product->get_price() === null ) {
        $lr = get_post_meta( $product->get_id(), 'lr', true );
        $remark = get_post_meta( $product->get_id(), 'remark', true );
        echo '<div class="mvp-price-request-text">Price on request</div>';
        echo '<button type="button" class="mvp-request-price-btn" '
            . 'data-lr="' . esc_attr( $lr ) . '" '
            . 'data-remark="' . esc_attr( $remark ) . '" '
            . 'onclick="mvpOpenPriceModal(this)">REQUEST A PRICE</button>';
        echo '<style>body.single-product .product form.cart { display: none !important; }</style>';
    }
}

// Replace "Add to cart" on archive/loop pages for no-price products
add_filter( 'woocommerce_loop_add_to_cart_link', 'mvp_loop_request_price', 10, 2 );
function mvp_loop_request_price( $link, $product ) {
    if ( $product->get_price() === '' || $product->get_price() === null ) {
        return '<a href="' . esc_url( get_permalink( $product->get_id() ) ) . '" class="button mvp-request-price-loop">Request Price</a>';
    }
    return $link;
}

// AJAX handler for price request form
add_action( 'wp_ajax_mvp_price_request', 'mvp_handle_price_request' );
add_action( 'wp_ajax_nopriv_mvp_price_request', 'mvp_handle_price_request' );
function mvp_handle_price_request() {
    check_ajax_referer( 'mvp_price_request_nonce', 'nonce' );

    $name    = sanitize_text_field( $_POST['name'] ?? '' );
    $email   = sanitize_email( $_POST['email'] ?? '' );
    $phone   = sanitize_text_field( $_POST['phone'] ?? '' );
    $sku     = sanitize_text_field( $_POST['sku'] ?? '' );
    $product_name = sanitize_text_field( $_POST['product_name'] ?? '' );
    $product_url  = esc_url_raw( $_POST['product_url'] ?? '' );
    $product_meta = sanitize_text_field( $_POST['product_meta'] ?? '' );

    if ( ! $name || ! $email ) {
        wp_send_json_error( 'Name and email are required.' );
    }

    $to      = 'neil@rapidfit.co.uk';
    $subject = 'Price Request: ' . $sku . ' - ' . $product_name;
    $body    = "New price request received:\n\n";
    $body   .= "Product: " . $product_name . "\n";
    $body   .= "SKU: " . $sku . "\n";
    if ( $product_meta ) {
        $body .= "Details: " . $product_meta . "\n";
    }
    $body   .= "URL: " . $product_url . "\n\n";
    $body   .= "Customer Details:\n";
    $body   .= "Name: " . $name . "\n";
    $body   .= "Email: " . $email . "\n";
    $body   .= "Phone: " . $phone . "\n";

    $headers = array(
        'Content-Type: text/plain; charset=UTF-8',
        'Reply-To: ' . $name . ' <' . $email . '>',
    );

    $sent = wp_mail( $to, $subject, $body, $headers );

    if ( $sent ) {
        wp_send_json_success( 'Your request has been sent. We will be in touch shortly.' );
    } else {
        wp_send_json_error( 'Failed to send. Please try calling us on 01953 528800.' );
    }
}

// Output the modal HTML + CSS + JS in the footer
add_action( 'wp_footer', 'mvp_price_request_modal', 50 );
function mvp_price_request_modal() {
    $nonce = wp_create_nonce( 'mvp_price_request_nonce' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
    <link rel="stylesheet" id="mvp-price-request-modal" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-price-request-modal.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-price-request-modal.css' ); ?>">

    <div class="mvp-price-modal-overlay" id="mvpPriceModal">
        <div class="mvp-price-modal">
            <button class="mvp-price-modal-close" onclick="mvpClosePriceModal()">&times;</button>
            <h3>Request a Price</h3>
            <div class="mvp-price-modal-product">
                <strong id="mvpPriceProductName"></strong><br>
                SKU: <span id="mvpPriceProductSku"></span>
                <span id="mvpPriceMetaWrap" style="display:none"><br><span id="mvpPriceMeta" style="color:#333;font-weight:600"></span></span>
            </div>
            <form id="mvpPriceForm" onsubmit="return mvpSubmitPriceForm(event)">
                <input type="hidden" id="mvpPriceSku" name="sku">
                <input type="hidden" id="mvpPriceProductNameHidden" name="product_name">
                <input type="hidden" id="mvpPriceProductUrl" name="product_url">
                <input type="hidden" id="mvpPriceProductMeta" name="product_meta">
                <label for="mvpPriceName">Your Name *</label>
                <input type="text" id="mvpPriceName" name="name" required placeholder="Full name">
                <label for="mvpPriceEmail">Email Address *</label>
                <input type="email" id="mvpPriceEmail" name="email" required placeholder="your@email.com">
                <label for="mvpPricePhone">Phone Number</label>
                <input type="tel" id="mvpPricePhone" name="phone" placeholder="Optional">
                <button type="submit" class="mvp-price-modal-submit" id="mvpPriceSubmitBtn">Submit Enquiry</button>
                <div class="mvp-price-modal-msg" id="mvpPriceMsg"></div>
            </form>
        </div>
    </div>

    <script id="mvp-price-request-modal-data">window.mvpData=window.mvpData||{};window.mvpData["mvp-price-request-modal"]=[<?php echo json_encode( $nonce ), ",", json_encode( esc_url( $ajax_url ) ); ?>];</script>
    <script id="mvp-price-request-modal-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-price-request-modal.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-price-request-modal.js' ); ?>"></script>
    <?php
}

// Add estimated delivery time (priority 35, after add to cart at 30)
add_action( 'woocommerce_single_product_summary', 'mvp_estimated_delivery', 35 );
function mvp_estimated_delivery() {
    global $product;
    $delivery = get_post_meta( $product->get_id(), '_estimated_delivery_time', true );
    if ( empty( $delivery ) ) {
        $delivery = '2-3 working days';
    }
    echo '<div class="mvp-delivery-time">';
    echo '<span class="delivery-label">Estimated Delivery:</span> ';
    echo '<span class="delivery-value">' . esc_html( $delivery ) . '</span>';
    echo '</div>';
}

// Add Compatible Vehicles section (placeholder — blank for now)
add_action( 'woocommerce_single_product_summary', 'mvp_compatible_vehicles', 45 );
function mvp_compatible_vehicles() {
    global $product;
    if ( ! $product ) return;

    $original_sku = get_post_meta( $product->get_id(), 'original_sku', true );
    if ( ! $original_sku ) {
        // Fallback: no original_sku, show current vehicle only
        $original_sku = $product->get_sku();
    }
    if ( ! $original_sku ) {
        echo '<div class="mvp-vehicle-compat">';
        echo '<h4>Compatible Vehicles:</h4>';
        echo '<p class="v-empty">Vehicle compatibility data coming soon.</p>';
        echo '</div>';
        return;
    }

    // Check transient cache first
    $cache_key = 'mvp_compat_' . md5( $original_sku );
    $vehicles = get_transient( $cache_key );

    if ( false === $vehicles ) {
        // Find all products with the same original_sku
        $matching = get_posts( array(
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => 'original_sku',
                    'value' => $original_sku,
                ),
            ),
        ) );

        if ( empty( $matching ) ) {
            // Try matching by _sku prefix (strip the hash suffix)
            $base_sku = preg_replace( '/-[A-F0-9]{4,}$/i', '', $product->get_sku() );
            if ( $base_sku && $base_sku !== $product->get_sku() ) {
                $matching = get_posts( array(
                    'post_type'      => 'product',
                    'post_status'    => 'publish',
                    'posts_per_page' => 100,
                    'fields'         => 'ids',
                    'meta_query'     => array(
                        array(
                            'key'     => 'original_sku',
                            'value'   => $base_sku,
                        ),
                    ),
                ) );
            }
        }

        // Also include current product
        if ( ! in_array( $product->get_id(), $matching ) ) {
            $matching[] = $product->get_id();
        }

        $maxus_id = mvp_get_maxus_term_id();
        $vehicles = array();

        foreach ( $matching as $pid ) {
            $cats = wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) );
            if ( is_wp_error( $cats ) ) continue;
            foreach ( $cats as $cat_id ) {
                $ancestors = get_ancestors( $cat_id, 'product_cat', 'taxonomy' );
                foreach ( $ancestors as $anc_id ) {
                    if ( isset( $vehicles[ $anc_id ] ) ) continue;
                    $anc_term = get_term( $anc_id, 'product_cat' );
                    if ( ! $anc_term || (int) $anc_term->parent !== $maxus_id ) continue;
                    $model = get_term_meta( $anc_id, 'vehicle_model', true );
                    $year  = get_term_meta( $anc_id, 'vehicle_year', true );
                    $slug  = get_term_meta( $anc_id, 'vehicle_slug', true );
                    if ( $model ) {
                        $vehicles[ $anc_id ] = array(
                            'model' => $model,
                            'year'  => $year,
                            'slug'  => $slug,
                        );
                    }
                }
            }
        }

        // Sort by model name
        uasort( $vehicles, function( $a, $b ) {
            return strcmp( $a['model'], $b['model'] );
        } );

        // Cache for 24 hours
        set_transient( $cache_key, $vehicles, DAY_IN_SECONDS );
    }

    echo '<div class="mvp-vehicle-compat">';
    echo '<h4>Compatible Vehicles:</h4>';

    if ( ! empty( $vehicles ) ) {
        echo '<ul>';
        foreach ( $vehicles as $v ) {
            $url = $v['slug'] ? home_url( '/vehicle/' . $v['slug'] . '/' ) : '#';
            echo '<li>';
            echo '<a href="' . esc_url( $url ) . '" class="v-name">' . esc_html( $v['model'] ) . '</a>';
            if ( $v['year'] ) {
                echo '<span class="v-year">(' . esc_html( $v['year'] ) . ')</span>';
            }
            echo '</li>';
        }
        echo '</ul>';
    } else {
        echo '<p class="v-empty">Vehicle compatibility data coming soon.</p>';
    }

    echo '</div>';
}

// Hide "Callout: X / Qty: Y" text in summary via JS
add_action( 'wp_footer', 'mvp_hide_callout_text' );
function mvp_hide_callout_text() {
    if ( ! is_product() ) return;
    ?>
    <script id="mvp-hide-callout-text-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-hide-callout-text.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-hide-callout-text.js' ); ?>"></script>
    <?php
}

// Add description section below the product summary (via footer hook on product pages)
add_action( 'woocommerce_after_single_product_summary', 'mvp_product_description_section', 5 );
function mvp_product_description_section() {
    global $product;
    $desc = $product->get_description();
    $short = $product->get_short_description();
    $content = $desc ? $desc : $short;
    if ( ! $content ) return;
    echo '<div class="mvp-product-description">';
    echo '<h3>Description</h3>';
    echo '<div>' . wp_kses_post( wpautop( $content ) ) . '</div>';
    echo '</div>';
}

// Replace VIN numbers with vehicle model names in breadcrumbs (all sources)
// 1. WooCommerce breadcrumbs
add_filter( 'woocommerce_get_breadcrumb', 'mvp_breadcrumb_replace_vin', 10, 1 );
function mvp_breadcrumb_replace_vin( $crumbs ) {
    if ( empty( $crumbs ) ) return $crumbs;
    $maxus_id = mvp_get_maxus_term_id();
    foreach ( $crumbs as &$crumb ) {
        $term = get_term_by( 'name', $crumb[0], 'product_cat' );
        if ( ! $term || (int) $term->parent !== $maxus_id ) continue;
        $model = get_term_meta( $term->term_id, 'vehicle_model', true );
        if ( ! $model ) continue;
        $year = get_term_meta( $term->term_id, 'vehicle_year', true );
        $crumb[0] = $model . ( $year ? ' (' . $year . ')' : '' );
    }
    return $crumbs;
}

// 2. Theme breadcrumbs (enovathemes) — JS replacement for VIN terms
add_action( 'wp_footer', function() {
    if ( ! is_tax( 'product_cat' ) && ! is_product() ) return;
    // Build VIN-to-model map for terms in the current breadcrumb
    $maxus_id = mvp_get_maxus_term_id();
    $vin_terms = get_terms( array(
        'taxonomy' => 'product_cat',
        'parent'   => $maxus_id,
        'hide_empty' => false,
        'fields'   => 'all',
    ) );
    if ( is_wp_error( $vin_terms ) || empty( $vin_terms ) ) return;
    $map = array();
    foreach ( $vin_terms as $vt ) {
        $model = get_term_meta( $vt->term_id, 'vehicle_model', true );
        $year  = get_term_meta( $vt->term_id, 'vehicle_year', true );
        if ( $model ) {
            $display = $model . ( $year ? ' (' . $year . ')' : '' );
            $map[ $vt->name ] = $display;
        }
    }
    if ( empty( $map ) ) return;
    ?>
    <script id="mvp-breadcrumb-replace-vin-data">window.mvpData=window.mvpData||{};window.mvpData["mvp-breadcrumb-replace-vin"]=[<?php echo json_encode( $map ); ?>];</script>
    <script id="mvp-breadcrumb-replace-vin-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-breadcrumb-replace-vin.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-breadcrumb-replace-vin.js' ); ?>"></script>
    <?php
}, 99 );

// Filter out utility categories from breadcrumbs
add_filter( 'get_the_terms', 'mvp_filter_breadcrumb_terms', 10, 3 );
function mvp_filter_breadcrumb_terms( $terms, $post_id, $taxonomy ) {
    if ( $taxonomy !== 'product_cat' || ! is_product() ) return $terms;
    if ( empty( $terms ) || is_wp_error( $terms ) ) return $terms;
    $exclude = array( 'priceupdated', 'imageupdated', 'uncategorized' );
    return array_filter( $terms, function( $term ) use ( $exclude ) {
        return ! in_array( $term->slug, $exclude );
    });
}


// ============================================================
// 5. VEHICLE LANDING PAGES — Rewrite rules + template
// ============================================================

// Register rewrite rule: /vehicle/{slug}/ → index.php?mvp_vehicle={slug}
add_action( 'init', 'mvp_vehicle_rewrite_rules' );
function mvp_vehicle_rewrite_rules() {
    add_rewrite_rule(
        '^vehicle/([^/]+)/?$',
        'index.php?mvp_vehicle=$matches[1]',
        'top'
    );
}

// Register query var
add_filter( 'query_vars', 'mvp_vehicle_query_vars' );
function mvp_vehicle_query_vars( $vars ) {
    $vars[] = 'mvp_vehicle';
    return $vars;
}

// Prevent WordPress from treating vehicle pages as 404
add_action( 'pre_get_posts', 'mvp_vehicle_prevent_404' );
function mvp_vehicle_prevent_404( $query ) {
    if ( ! $query->is_main_query() ) return;
    $vehicle_slug = $query->get( 'mvp_vehicle' );
    if ( $vehicle_slug ) {
        $query->is_404 = false;
    }
}

// Render vehicle page via template_redirect (before any template loads)
add_action( 'template_redirect', 'mvp_vehicle_template_redirect' );
function mvp_vehicle_template_redirect() {
    $vehicle_slug = get_query_var( 'mvp_vehicle' );
    if ( ! $vehicle_slug ) return;

    // Find the VIN term by its vehicle_slug meta
    $maxus_term_id = mvp_get_maxus_term_id();
    $vin_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $maxus_term_id,
        'hide_empty' => false,
        'meta_query' => array( array( 'key' => 'vehicle_slug', 'value' => sanitize_title( $vehicle_slug ) ) ),
    ) );

    if ( is_wp_error( $vin_terms ) || empty( $vin_terms ) ) {
        return; // Let WP handle 404
    }

    $vin_term = $vin_terms[0];

    // Store data in global
    global $mvp_vehicle_data;
    $mvp_vehicle_data = array(
        'vin_term'     => $vin_term,
        'vin_serial'   => $vin_term->name,
        'vehicle_slug' => sanitize_title( $vehicle_slug ),
        'model'        => get_term_meta( $vin_term->term_id, 'vehicle_model', true ),
        'year'         => get_term_meta( $vin_term->term_id, 'vehicle_year', true ),
        'img'          => get_term_meta( $vin_term->term_id, 'vehicle_image', true ),
        'categories'   => get_terms( array(
            'taxonomy'   => 'product_cat',
            'parent'     => $vin_term->term_id,
            'hide_empty' => true,
            'orderby'    => 'name',
        ) ),
        'cat_img_base' => '/wp-content/uploads/categories/',
    );

    // Reset 404 status and set 200
    global $wp_query;
    $wp_query->is_404 = false;
    status_header( 200 );

    // Render and exit
    mvp_vehicle_render_full_page();
    exit;
}

// Render full vehicle landing page
function mvp_vehicle_render_full_page() {
    global $mvp_vehicle_data;
    $vin_term     = $mvp_vehicle_data['vin_term'];
    $model        = $mvp_vehicle_data['model'];
    $year         = $mvp_vehicle_data['year'];
    $img          = $mvp_vehicle_data['img'];
    $categories   = $mvp_vehicle_data['categories'];
    $cat_img_base = $mvp_vehicle_data['cat_img_base'];

    // Set page title
    add_filter( "document_title_parts", function( $title ) use ( $model ) {
        $title["title"] = $model ? $model . " - Maxus Parts Direct" : "Vehicle - Maxus Parts Direct";
        return $title;
    } );
    get_header();
    ?>
    <link rel="stylesheet" id="mvp-vehicle-render-full-page" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-vehicle-render-full-page.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-vehicle-render-full-page.css' ); ?>">

    <div class="mvp-vehicle-page">
        <div class="mvp-vehicle-header">
            <?php if ( $img ) : ?>
            <div class="mvp-vehicle-header-img">
                <img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $model ); ?>">
            </div>
            <?php endif; ?>
            <div class="mvp-vehicle-header-info">
                <p class="mvp-vh-breadcrumb"><a href="<?php echo home_url('/'); ?>">Home</a> &rsaquo; <a href="<?php echo home_url('/'); ?>">Vehicles</a> &rsaquo; <?php echo esc_html( $model ); ?></p>
                <h1><?php echo esc_html( $model ); ?></h1>
                <p class="mvp-vh-years"><?php echo esc_html( $year ); ?></p>
            </div>
        </div>

        <?php if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) : ?>
        <div class="mvp-category-grid">
            <?php foreach ( $categories as $cat ) :
                // Build category image filename: replace spaces with underscores
                $cat_img_file = mvp_category_icon_file( $cat->name );
                $cat_img_url  = $cat_img_file ? $cat_img_base . $cat_img_file : '';
                // Link directly to the WooCommerce category archive for this vehicle's category
                $cat_url = get_term_link( $cat );
                if ( is_wp_error( $cat_url ) ) {
                    $cat_url = home_url( '/department/' . sanitize_title( $cat->name ) . '/' );
                }
                // WordPress counts already include all descendant products
                $product_count = $cat->count;
            ?>
            <a href="<?php echo esc_url( $cat_url ); ?>" class="mvp-category-card">
                <div class="mvp-category-card-img">
                    <?php if ( $cat_img_url ) : ?><img src="<?php echo esc_url( $cat_img_url ); ?>" alt="<?php echo esc_attr( $cat->name ); ?>" loading="lazy" onerror="this.style.display='none'"><?php endif; ?>
                </div>
                <div class="mvp-category-card-body">
                    <h3><?php echo esc_html( $cat->name ); ?></h3>
                    <span class="mvp-cat-count"><?php echo $product_count; ?> part<?php echo $product_count !== 1 ? 's' : ''; ?></span>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
            <?php
            // No child categories - check if products are directly in this VIN category
            if ( $vin_term->count > 0 ) :
                // Products exist directly in VIN category - redirect to it
                $vin_cat_url = get_term_link( $vin_term );
                if ( ! is_wp_error( $vin_cat_url ) ) {
                    wp_redirect( $vin_cat_url, 302 );
                    exit;
                }
            endif;
            ?>
        <p style="text-align:center;color:#888;padding:40px 0;">No parts categories found for this vehicle yet.</p>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        var expires = new Date();
        expires.setDate(expires.getDate() + 30);
        var exp = expires.toUTCString();
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        var path = 'path=/; SameSite=Lax' + secure;
        document.cookie = 'mvp_vehicle_slug='   + encodeURIComponent('<?php echo esc_js( $mvp_vehicle_data['vehicle_slug'] ); ?>') + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_serial=' + encodeURIComponent('<?php echo esc_js( $mvp_vehicle_data['vin_serial'] ); ?>')   + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_model='  + encodeURIComponent('<?php echo esc_js( $model ); ?>')                             + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_year='   + encodeURIComponent('<?php echo esc_js( $year ); ?>')                              + '; expires=' + exp + '; ' + path;
    });
    </script>

    <?php
    get_footer();
}

// Rewrite hardcoded domain in nav menu department links to the current site URL.
// This means the same DB works on localhost and production without changes.
add_filter( 'wp_nav_menu_objects', 'mvp_fix_menu_dept_urls', 10, 2 );
function mvp_fix_menu_dept_urls( $items, $args ) {
    foreach ( $items as &$item ) {
        if ( ! empty( $item->url ) && strpos( $item->url, '/department/' ) !== false ) {
            $parsed = parse_url( $item->url );
            if ( ! empty( $parsed['path'] ) ) {
                $item->url = home_url( $parsed['path'] );
            }
        }
    }
    return $items;
}

// ============================================================
// 5b-i. DEPARTMENT SLUG → CATEGORY NAMES MAP
// ============================================================

function mvp_dept_get_slug_map() {
    static $map = null;
    if ( $map === null ) {
        $file = get_stylesheet_directory() . '/assets/data/dept-slug-map.json';
        $map  = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : array();
        if ( ! is_array( $map ) ) { $map = array(); }
    }
    return $map;
}

// Sidebar display names keyed by slug
function mvp_dept_get_display_names() {
    return array(
        'air-conditioning' => 'Air Conditioning',
        'belts-rollers'    => 'Belts &amp; Rollers',
        'body'             => 'Body',
        'brakes'           => 'Brakes',
        'damping'          => 'Damping',
        'electrics'        => 'Electrics',
        'engine'           => 'Engine',
        'filters'          => 'Filters',
        'induction'        => 'Induction',
        'ignition'         => 'Ignition',
        'interior'         => 'Interior',
        'lighting'         => 'Lighting',
        'oils-and-fluids'  => 'Oils &amp; Fluids',
        'wiper-and-washers'=> 'Wipers &amp; Washers',
        'suspension'       => 'Suspension',
        'tires'            => 'Tires',
        'steering'         => 'Steering',
        'transmission'     => 'Transmission',
    );
}

// ============================================================
// 5b. DEPARTMENT PAGES — /department/{slug}/ and /department/{slug}/{vehicle-slug}/
// ============================================================

// Auto-flush rewrite rules when theme version changes (e.g. after deployment).
// Bump MVp_REWRITE_VERSION whenever new rewrite rules are added.
define( 'MVP_REWRITE_VERSION', '3' );
add_action( 'init', 'mvp_maybe_flush_rewrite_rules', 99 );
function mvp_maybe_flush_rewrite_rules() {
    if ( get_option( 'mvp_rewrite_version' ) !== MVP_REWRITE_VERSION ) {
        flush_rewrite_rules( false );
        update_option( 'mvp_rewrite_version', MVP_REWRITE_VERSION, false );
    }
}

// Register rewrite rules for department pages
add_action( 'init', 'mvp_department_rewrite_rules' );
function mvp_department_rewrite_rules() {
    // /department/{cat-slug}/{vehicle-slug}/ → show products for that vehicle's category
    add_rewrite_rule(
        '^department/([^/]+)/([^/]+)/?$',
        'index.php?mvp_department=$matches[1]&mvp_dept_vehicle=$matches[2]',
        'top'
    );
    // /department/{cat-slug}/ → show all vehicles with that category
    add_rewrite_rule(
        '^department/([^/]+)/?$',
        'index.php?mvp_department=$matches[1]',
        'top'
    );
}

// Register query vars
add_filter( 'query_vars', 'mvp_department_query_vars' );
function mvp_department_query_vars( $vars ) {
    $vars[] = 'mvp_department';
    $vars[] = 'mvp_dept_vehicle';
    return $vars;
}

// Prevent 404 for department pages
add_action( 'pre_get_posts', 'mvp_department_prevent_404' );
function mvp_department_prevent_404( $query ) {
    if ( ! $query->is_main_query() ) return;
    if ( $query->get( 'mvp_department' ) ) {
        $query->is_404 = false;
    }
}

// Render department pages via template_redirect
add_action( 'template_redirect', 'mvp_department_template_redirect' );
function mvp_department_template_redirect() {
    $dept_slug = get_query_var( 'mvp_department' );
    if ( ! $dept_slug ) return;

    // Add Vary header so caches (Cloudflare) know response depends on cookies
    header( 'Vary: Cookie', false );

    $vehicle_slug = get_query_var( 'mvp_dept_vehicle' );

    // If vehicle slug present, show intermediate category page for that vehicle+department
    if ( $vehicle_slug ) {
        mvp_department_vehicle_redirect( $dept_slug, $vehicle_slug );
        return;
    }

    // No vehicle in URL — check for saved vehicle cookie and auto-redirect if present
    // Skip redirect if ?all=1 (user clicked View all vehicles)
    if ( ! $vehicle_slug && ! empty( $_COOKIE['mvp_vehicle_slug'] ) && empty( $_GET['all'] ) ) {
        $cookie_slug = sanitize_title( wp_unslash( $_COOKIE['mvp_vehicle_slug'] ) );
        if ( $cookie_slug ) {
            // Add nocache header to prevent caching of redirect
            header( 'Cache-Control: no-cache, must-revalidate, max-age=0', false );
            $redirect_url = home_url( '/department/' . $dept_slug . '/' . $cookie_slug . '/' );
            wp_redirect( $redirect_url, 302 );
            exit;
        }
    }

    // Show department page with all vehicles that have this category
    global $wp_query;
    $wp_query->is_404 = false;
    status_header( 200 );

    mvp_department_render_page( $dept_slug );
    exit;
}

// Render an intermediate category-listing page for /department/{cat}/{vehicle}/
function mvp_department_vehicle_redirect( $dept_slug, $vehicle_slug ) {
    $maxus_term_id = mvp_get_maxus_term_id();

    // Find VIN term by vehicle_slug meta
    $vin_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $maxus_term_id,
        'hide_empty' => false,
        'meta_query' => array( array( 'key' => 'vehicle_slug', 'value' => sanitize_title( $vehicle_slug ) ) ),
    ) );

    if ( is_wp_error( $vin_terms ) || empty( $vin_terms ) ) {
        global $wp_query;
        $wp_query->set_404();
        status_header( 404 );
        return;
    }

    $vin_term = $vin_terms[0];

    // Build allowed slug set from the map
    $slug_map      = mvp_dept_get_slug_map();
    $display_names = mvp_dept_get_display_names();
    $allowed_names = isset( $slug_map[ $dept_slug ] ) ? $slug_map[ $dept_slug ] : array();
    $allowed_slugs = array_map( 'sanitize_title', $allowed_names );
    $use_fallback  = empty( $allowed_slugs );
    $dept_name_clean = str_replace( '-', ' ', $dept_slug );

    $dept_display_name = isset( $display_names[ $dept_slug ] )
        ? html_entity_decode( $display_names[ $dept_slug ] )
        : ucwords( $dept_name_clean );

    $vehicle_model = get_term_meta( $vin_term->term_id, 'vehicle_model', true );
    $vehicle_year  = get_term_meta( $vin_term->term_id, 'vehicle_year', true );

    // Get all descendants, build parent→children map and identify leaves
    $all_cats = get_terms( array(
        'taxonomy'   => 'product_cat',
        'child_of'   => $vin_term->term_id,
        'hide_empty' => true,
    ) );

    // Build lookup maps
    $cat_by_id        = array();
    $has_children_ids = array();
    $children_of      = array(); // parent_id => [ child, ... ]

    if ( ! is_wp_error( $all_cats ) && ! empty( $all_cats ) ) {
        foreach ( $all_cats as $c ) {
            $cat_by_id[ $c->term_id ] = $c;
            $has_children_ids[ $c->parent ] = true;
            $children_of[ $c->parent ][] = $c;
        }
    }

    // Filter to leaf cats that match the department mapping
    $matching_leaves = array();
    if ( ! empty( $cat_by_id ) ) {
        foreach ( $cat_by_id as $c ) {
            if ( isset( $has_children_ids[ $c->term_id ] ) ) continue; // not a leaf
            $matches = $use_fallback
                ? ( sanitize_title( $c->name ) === sanitize_title( $dept_name_clean ) || sanitize_title( $c->name ) === $dept_slug )
                : in_array( sanitize_title( $c->name ), $allowed_slugs, true );
            if ( $matches ) {
                $matching_leaves[ $c->term_id ] = $c;
            }
        }
    }

    // If only one match, redirect straight to it
    if ( count( $matching_leaves ) === 1 ) {
        $only = reset( $matching_leaves );
        $url  = get_term_link( $only );
        if ( ! is_wp_error( $url ) ) {
            wp_redirect( $url, 302 );
            exit;
        }
    }

    // Show every matching leaf directly so the user sees all individual sub-categories.
    $display_cats = array();
    foreach ( $matching_leaves as $leaf ) {
        $display_cats[ $leaf->term_id ] = array( 'term' => $leaf, 'count' => $leaf->count );
    }

    // Sort by name
    uasort( $display_cats, function( $a, $b ) {
        return strcmp( $a['term']->name, $b['term']->name );
    } );

    // Render the intermediate page
    global $wp_query;
    $wp_query->is_404 = false;
    status_header( 200 );

    get_header();
    ?>
    <link rel="stylesheet" id="mvp-department-vehicle-redirect" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-department-vehicle-redirect.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-department-vehicle-redirect.css' ); ?>">

    <div class="mvp-vdept-page">
        <div class="mvp-vdept-header">
            <p class="mvp-vdept-breadcrumb">
                <a href="<?php echo home_url('/'); ?>">Home</a> &rsaquo;
                <a href="<?php echo home_url( '/department/' . $dept_slug . '/' ); ?>"><?php echo esc_html( $dept_display_name ); ?></a> &rsaquo;
                <?php echo esc_html( $vehicle_model ); ?>
            </p>
            <h1><?php echo esc_html( $dept_display_name ); ?> &mdash; <?php echo esc_html( $vehicle_model ); ?></h1>
            <p class="mvp-vdept-subtitle"><?php echo esc_html( $vehicle_year ); ?> &bull; Select a category to view parts</p>
        </div>

        <div class="mvp-vehicle-notice">
            <span class="mvp-vehicle-notice-text">
                Showing <strong><?php echo esc_html( $dept_display_name ); ?></strong> parts for your saved vehicle: <strong><?php echo esc_html( $vehicle_model ); ?><?php if ( $vehicle_year ) : ?> (<?php echo esc_html( $vehicle_year ); ?>)<?php endif; ?></strong>
            </span>
            <a class="mvp-vehicle-notice-change" href="#" onclick="mvpClearVehicleCookies(); return false;">&#8635; Change vehicle</a>
            <a class="mvp-vehicle-notice-change mvp-view-all" href="<?php echo esc_url( home_url( '/department/' . $dept_slug . '/?all=1' ) ); ?>">View all vehicles</a>
        </div>

        <?php if ( ! empty( $display_cats ) ) : ?>
        <div class="mvp-vdept-grid">
            <?php foreach ( $display_cats as $dc ) :
                $term_url = get_term_link( $dc['term'] );
                if ( is_wp_error( $term_url ) ) continue;
            ?>
            <?php
                $img_name = mvp_category_icon_file( $dc['term']->name );
                $has_img  = ( $img_name !== '' );
            ?>
            <a href="<?php echo esc_url( $term_url ); ?>" class="mvp-vdept-card<?php echo $has_img ? ' has-img' : ''; ?>">
                <?php if ( $has_img ) : ?>
                <span class="mvp-vdept-card-img"><img src="<?php echo esc_url( content_url( '/uploads/categories/' . $img_name ) ); ?>" alt="<?php echo esc_attr( $dc['term']->name ); ?>" /></span>
                <?php endif; ?>
                <span class="mvp-vdept-card-name"><?php echo esc_html( $dc['term']->name ); ?></span>
                <span class="mvp-vdept-card-count"><?php echo (int) $dc['count']; ?> part<?php echo $dc['count'] !== 1 ? 's' : ''; ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <p style="text-align:center;color:#888;padding:40px 0;">No parts found for this vehicle in <?php echo esc_html( $dept_display_name ); ?>.</p>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        var expires = new Date();
        expires.setDate(expires.getDate() + 30);
        var exp = expires.toUTCString();
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        var path = 'path=/; SameSite=Lax' + secure;
        document.cookie = 'mvp_vehicle_slug='   + encodeURIComponent('<?php echo esc_js( $vehicle_slug ); ?>') + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_serial=' + encodeURIComponent('<?php echo esc_js( $vin_term->slug ); ?>') + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_model='  + encodeURIComponent('<?php echo esc_js( $vehicle_model ); ?>') + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_year='   + encodeURIComponent('<?php echo esc_js( $vehicle_year ); ?>') + '; expires=' + exp + '; ' + path;
    });
    function mvpClearVehicleCookies() {
        var past = 'Thu, 01 Jan 1970 00:00:00 UTC';
        var keys = ['mvp_vehicle_slug', 'mvp_vehicle_serial', 'mvp_vehicle_model', 'mvp_vehicle_year'];
        keys.forEach(function(k) {
            document.cookie = k + '=; expires=' + past + '; path=/; SameSite=Lax';
        });
        window.location.href = '<?php echo esc_js( home_url( '/#mvp-vehicles' ) ); ?>';
    }
    </script>

    <?php
    get_footer();
    exit;
}

// Render the department page showing all vehicles with this category
function mvp_department_render_page( $dept_slug ) {
    $maxus_term_id = mvp_get_maxus_term_id();

    // Resolve display name and allowed category list from map
    $slug_map      = mvp_dept_get_slug_map();
    $display_names = mvp_dept_get_display_names();
    $allowed_names = isset( $slug_map[ $dept_slug ] ) ? $slug_map[ $dept_slug ] : array();
    $allowed_slugs = array_map( 'sanitize_title', $allowed_names );
    $use_fallback  = empty( $allowed_slugs );
    $dept_name_clean = str_replace( '-', ' ', $dept_slug );

    $dept_display_name = isset( $display_names[ $dept_slug ] )
        ? html_entity_decode( $display_names[ $dept_slug ] )
        : ucwords( $dept_name_clean );

    // Get all VIN terms
    $vin_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $maxus_term_id,
        'hide_empty' => false,
        'orderby'    => 'name',
    ) );

    $vehicles_with_dept = array();
    $cat_img_base = '/wp-content/uploads/categories/';

    if ( ! is_wp_error( $vin_terms ) ) {
        foreach ( $vin_terms as $vin_term ) {
            $model = get_term_meta( $vin_term->term_id, 'vehicle_model', true );
            $slug  = get_term_meta( $vin_term->term_id, 'vehicle_slug', true );
            if ( ! $model || ! $slug ) continue;

            // Get all descendant categories and match against leaf nodes only
            $all_cats = get_terms( array(
                'taxonomy'   => 'product_cat',
                'child_of'   => $vin_term->term_id,
                'hide_empty' => true,
            ) );

            if ( is_wp_error( $all_cats ) || empty( $all_cats ) ) continue;

            $has_children_ids = array();
            foreach ( $all_cats as $c ) {
                $has_children_ids[ $c->parent ] = true;
            }
            $leaf_cats = array_filter( $all_cats, function( $c ) use ( $has_children_ids ) {
                return ! isset( $has_children_ids[ $c->term_id ] );
            } );

            // Accumulate product count across ALL matching leaf cats for this vehicle
            $total_count = 0;
            foreach ( $leaf_cats as $cat ) {
                $matches = $use_fallback
                    ? ( sanitize_title( $cat->name ) === sanitize_title( $dept_name_clean ) || sanitize_title( $cat->name ) === $dept_slug )
                    : in_array( sanitize_title( $cat->name ), $allowed_slugs, true );
                if ( $matches ) {
                    $total_count += $cat->count;
                }
            }

            if ( $total_count > 0 ) {
                $year = get_term_meta( $vin_term->term_id, 'vehicle_year', true );
                $img  = get_term_meta( $vin_term->term_id, 'vehicle_image', true );

                $vehicles_with_dept[] = array(
                    'model'         => $model,
                    'year'          => $year,
                    'img'           => $img,
                    'vehicle_slug'  => $slug,
                    'product_count' => $total_count,
                );
            }
        }
    }

    // Category image with fallback matching
    $cat_img_name  = mvp_category_icon_file( $dept_display_name );
    $cat_img_found = ( $cat_img_name !== '' );
    $cat_img_url   = $cat_img_found ? $cat_img_base . $cat_img_name : '';

    get_header();
    ?>
    <link rel="stylesheet" id="mvp-department-render-page" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-department-render-page.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-department-render-page.css' ); ?>">

    <div class="mvp-dept-page">
        <div class="mvp-dept-header">
            <?php if ( $cat_img_url ) : ?>
            <div class="mvp-dept-header-img">
                <img src="<?php echo esc_url( $cat_img_url ); ?>" alt="<?php echo esc_attr( $dept_display_name ); ?>"
                     onerror="this.parentElement.style.display='none'">
            </div>
            <?php endif; ?>
            <div class="mvp-dept-header-info">
                <p class="mvp-dept-breadcrumb"><a href="<?php echo home_url('/'); ?>">Home</a> &rsaquo; <?php echo esc_html( $dept_display_name ); ?></p>
                <h1><?php echo esc_html( $dept_display_name ); ?></h1>
                <p class="mvp-dept-subtitle">Select your vehicle to view <?php echo esc_html( strtolower( $dept_display_name ) ); ?> parts</p>
            </div>
        </div>

        <?php if ( ! empty( $vehicles_with_dept ) ) : ?>
        <div class="mvp-dept-vehicle-grid">
            <?php foreach ( $vehicles_with_dept as $v ) :
                $cat_url = home_url( '/department/' . $dept_slug . '/' . $v['vehicle_slug'] . '/' );
            ?>
            <a href="<?php echo esc_url( $cat_url ); ?>" class="mvp-dept-vehicle-card">
                <div class="mvp-dept-vehicle-card-img">
                    <?php if ( $v['img'] ) : ?>
                    <img src="<?php echo esc_url( $v['img'] ); ?>" alt="<?php echo esc_attr( $v['model'] ); ?>" loading="lazy">
                    <?php endif; ?>
                </div>
                <div class="mvp-dept-vehicle-card-body">
                    <h3><?php echo esc_html( $v['model'] ); ?></h3>
                    <p class="mvp-dept-year"><?php echo esc_html( $v['year'] ); ?></p>
                    <p class="mvp-dept-parts"><?php echo $v['product_count']; ?> part<?php echo $v['product_count'] !== 1 ? 's' : ''; ?></p>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php else : ?>
        <p style="text-align:center;color:#888;padding:40px 0;">No vehicles found with <?php echo esc_html( $dept_display_name ); ?> parts.</p>
        <?php endif; ?>
    </div>
    <?php
    get_footer();
}

// ============================================================
// 6. VEHICLE DATA HELPER — Returns all VIN-to-vehicle mappings
// ============================================================
function mvp_get_vehicle_vins() {
    $maxus_term_id = mvp_get_maxus_term_id();
    $vin_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $maxus_term_id,
        'hide_empty' => false,
    ) );

    $vehicles = array();
    if ( is_wp_error( $vin_terms ) ) return $vehicles;

    foreach ( $vin_terms as $term ) {
        $model = get_term_meta( $term->term_id, 'vehicle_model', true );
        $year  = get_term_meta( $term->term_id, 'vehicle_year', true );
        $slug  = get_term_meta( $term->term_id, 'vehicle_slug', true );
        $img   = get_term_meta( $term->term_id, 'vehicle_image', true );
        if ( ! $model || ! $slug ) continue;

        $vehicles[ $slug ] = array(
            'vin'     => strtoupper( $term->name ),
            'name'    => $model,
            'year'    => $year,
            'img'     => $img,
            'term_id' => $term->term_id,
        );
    }
    return $vehicles;
}

// ============================================================
// 7. VIN LOOKUP — AJAX handler
// ============================================================
add_action( 'wp_ajax_maxus_vin_lookup', 'mvp_vin_lookup' );
add_action( 'wp_ajax_nopriv_maxus_vin_lookup', 'mvp_vin_lookup' );
function mvp_vin_lookup() {
    $vin = isset( $_POST['vin'] ) ? sanitize_text_field( $_POST['vin'] ) : '';
    $vin = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $vin ) );

    if ( strlen( $vin ) !== 17 ) {
        wp_send_json_error( array( 'error' => 'VIN must be exactly 17 characters' ) );
    }
    if ( substr( $vin, 0, 2 ) !== 'LS' ) {
        wp_send_json_error( array( 'error' => 'This does not appear to be a Maxus VIN (should start with LS)' ) );
    }

    // Model year from position 10 of VIN
    $year_codes = array(
        'A'=>2010,'B'=>2011,'C'=>2012,'D'=>2013,'E'=>2014,'F'=>2015,'G'=>2016,
        'H'=>2017,'J'=>2018,'K'=>2019,'L'=>2020,'M'=>2021,'N'=>2022,'P'=>2023,
        'R'=>2024,'S'=>2025,'T'=>2026,'V'=>2027,'W'=>2028,'X'=>2029,'Y'=>2030,
    );
    $customer_pattern = substr( $vin, 0, 8 );
    $customer_year_code = substr( $vin, 9, 1 );
    $customer_year = isset( $year_codes[ $customer_year_code ] ) ? $year_codes[ $customer_year_code ] : null;
    $home_url = home_url( '/' );
    $vehicles = mvp_get_vehicle_vins();

    // 1. Try exact VIN match first (VIN categories ARE full VINs)
    foreach ( $vehicles as $slug => $v ) {
        if ( strtoupper( $v['vin'] ) === $vin ) {
            wp_send_json_success( array(
                'vehicle_name'  => $v['name'],
                'customer_year' => $customer_year,
                'shop_url'      => $home_url . 'vehicle/' . $slug . '/',
            ) );
        }
    }

    // 2. Pattern match by first 8 chars of VIN
    $matches = array();
    foreach ( $vehicles as $slug => $v ) {
        $v_pattern = substr( strtoupper( $v['vin'] ), 0, 8 );
        if ( $v_pattern === $customer_pattern ) {
            $matches[ $slug ] = $v;
        }
    }

    if ( empty( $matches ) ) {
        wp_send_json_error( array(
            'error'            => 'No vehicle found for VIN pattern: ' . $customer_pattern,
            'suggestion'       => 'We may not have parts for this specific Maxus model yet. Please contact us for assistance.',
            'customer_pattern' => $customer_pattern,
            'customer_year'    => $customer_year,
        ) );
    }

    // Single match
    if ( count( $matches ) === 1 ) {
        $slug = array_key_first( $matches );
        $v = $matches[ $slug ];
        wp_send_json_success( array(
            'vehicle_name'  => $v['name'],
            'customer_year' => $customer_year,
            'shop_url'      => $home_url . 'vehicle/' . $slug . '/',
        ) );
    }

    // Multiple matches — narrow by year
    $best_slug = null;
    if ( $customer_year ) {
        foreach ( $matches as $slug => $v ) {
            if ( preg_match( '/(\d{4})\s*-\s*(\S+)/', $v['year'], $m ) ) {
                $start = intval( $m[1] );
                $end = ( $m[2] === 'Present' ) ? 2030 : intval( $m[2] );
                if ( $customer_year >= $start && $customer_year <= $end ) {
                    $best_slug = $slug;
                    break;
                }
            }
        }
    }
    if ( ! $best_slug ) $best_slug = array_key_first( $matches );

    $v = $matches[ $best_slug ];
    wp_send_json_success( array(
        'vehicle_name'  => $v['name'],
        'customer_year' => $customer_year,
        'shop_url'      => $home_url . 'vehicle/' . $best_slug . '/',
    ) );
}

// ============================================================
// Registration lookup shortcode
add_shortcode("maxus_reg_search", "mvp_reg_search_shortcode");
function mvp_reg_search_shortcode() {
    ob_start();
    ?>
    <div class="maxus-reg-search-wrap" style="max-width:700px;margin:40px auto;text-align:center;">
        <h2 style="font-family:Inter,sans-serif;font-size:28px;margin-bottom:10px;">Registration Lookup</h2>
        <p style="color:#666;margin-bottom:24px;">Enter your UK vehicle registration to find compatible parts.</p>
        <form id="mvp-reg-form" style="display:flex;gap:10px;max-width:500px;margin:0 auto 12px;">
            <input type="text" id="mvp-reg-input" placeholder="e.g. AB12 CDE" maxlength="10" autocomplete="off"
                style="flex:1;height:48px;padding:0 16px;font-size:16px;border:2px solid #ddd;border-radius:6px;text-transform:uppercase;">
            <button type="submit" style="height:48px;padding:0 24px;background:#BF3617;color:#fff;border:none;border-radius:6px;font-size:16px;font-weight:600;cursor:pointer;">Search Parts</button>
        </form>
        <p style="font-size:13px;color:#999;">UK registration plate number</p>
        <div id="mvp-reg-result" style="margin-top:20px;text-align:left;display:none;"></div>
    </div>
    <script id="mvp-reg-search-shortcode-data">window.mvpData=window.mvpData||{};window.mvpData["mvp-reg-search-shortcode"]=[<?php echo json_encode( admin_url("admin-ajax.php") ); ?>];</script>
    <script id="mvp-reg-search-shortcode-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-reg-search-shortcode.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-reg-search-shortcode.js' ); ?>"></script>
    <?php
    return ob_get_clean();
}

// 8. REGISTRATION LOOKUP — AJAX handler (checkcardetails API)
// ============================================================
add_action( 'wp_ajax_maxus_reg_lookup', 'mvp_reg_lookup' );
add_action( 'wp_ajax_nopriv_maxus_reg_lookup', 'mvp_reg_lookup' );
function mvp_reg_lookup() {
    $reg = isset( $_POST['reg'] ) ? sanitize_text_field( $_POST['reg'] ) : '';
    $reg = preg_replace( '/\s+/', '', strtoupper( $reg ) );

    if ( empty( $reg ) || strlen( $reg ) < 2 ) {
        wp_send_json_error( array( 'error' => 'Please enter a valid registration number' ) );
    }

    // Call checkcardetails.co.uk API
    $api_key = 'd54fb43716925ad8f4dc415a4e2f962d';
    $api_url = 'https://api.checkcardetails.co.uk/vehicledata/vehicleregistration?apikey=' . $api_key . '&vrm=' . urlencode( $reg );
    $response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );

    if ( is_wp_error( $response ) ) {
        wp_send_json_error( array( 'error' => 'Could not connect to vehicle lookup service' ) );
    }

    $code = wp_remote_retrieve_response_code( $response );
    $body = json_decode( wp_remote_retrieve_body( $response ), true );

    if ( $code === 404 || empty( $body ) ) {
        wp_send_json_error( array( 'error' => 'No vehicle found for registration: ' . $reg ) );
    }
    if ( $code !== 200 ) {
        wp_send_json_error( array( 'error' => 'Vehicle lookup failed. Please try again.' ) );
    }

    $make  = isset( $body['make'] ) ? strtoupper( trim( $body['make'] ) ) : '';
    $model_name = isset( $body['model'] ) ? trim( $body['model'] ) : '';
    $year  = isset( $body['yearOfManufacture'] ) ? intval( $body['yearOfManufacture'] ) : '';
    $fuel  = isset( $body['fuelType'] ) ? trim( $body['fuelType'] ) : '';

    // Check if Maxus/LDV
    if ( ! in_array( $make, array( 'MAXUS', 'LDV', 'SAIC', 'MG' ) ) ) {
        wp_send_json_error( array(
            'error' => 'This is a ' . ucwords( strtolower( $make ) ) . ' ' . $model_name . ' (' . $year . '). We only stock Maxus/LDV parts.',
        ) );
    }

    // Match model to vehicle landing page
    $vehicles = mvp_get_vehicle_vins();
    $home_url = home_url( '/' );
    $model_upper = strtoupper( $model_name );
    $is_electric = ( stripos( $fuel, 'ELECTRIC' ) !== false );
    $display_name = ucwords( strtolower( $make . ' ' . $model_name ) );

    // Collect ALL matching variants for this model
    $all_matches = array();
    $keywords = array( 'DELIVER 9', 'DELIVER 7', 'E DELIVER 9', 'E DELIVER 7', 'E DELIVER 3', 'E-DELIVER', 'T60', 'T90', 'V80', 'A80' );

    foreach ( $vehicles as $slug => $v ) {
        $v_name = strtoupper( $v['name'] );
        $v_is_electric = ( stripos( $v_name, 'E DELIVER' ) !== false || stripos( $v_name, 'EV' ) !== false );

        // Skip electric/non-electric mismatch
        if ( $is_electric !== $v_is_electric ) continue;

        $matched = false;

        // Direct match
        if ( stripos( $model_upper, $v_name ) !== false || stripos( $v_name, $model_upper ) !== false ) {
            $matched = true;
        }

        // Keyword matching
        if ( ! $matched ) {
            foreach ( $keywords as $kw ) {
                if ( stripos( $model_upper, $kw ) !== false && stripos( $v_name, $kw ) !== false ) {
                    $matched = true;
                    break;
                }
            }
        }

        if ( $matched ) {
            $all_matches[] = array(
                'slug' => $slug,
                'name' => $v['name'],
                'year' => $v['year'],
                'img'  => $v['img'],
                'url'  => $home_url . 'vehicle/' . $slug . '/',
            );
        }
    }

    // Single match — redirect directly (same as before)
    if ( count( $all_matches ) === 1 ) {
        wp_send_json_success( array(
            'vehicle_name'  => $display_name,
            'customer_year' => $year,
            'shop_url'      => $all_matches[0]['url'],
        ) );
    }

    // Multiple matches — return variants so frontend can show picker
    if ( count( $all_matches ) > 1 ) {
        wp_send_json_success( array(
            'vehicle_name'  => $display_name,
            'customer_year' => $year,
            'colour'        => isset( $body['colour'] ) ? ucwords( strtolower( $body['colour'] ) ) : '',
            'fuel'          => ucwords( strtolower( $fuel ) ),
            'variants'      => $all_matches,
        ) );
    }

    // No match — fallback to shop
    wp_send_json_success( array(
        'vehicle_name'  => $display_name,
        'customer_year' => $year,
        'shop_url'      => $home_url . 'shop/',
        'note'          => 'Could not match exact model. Showing all parts.',
    ) );
}

// ============================================================
// 9. HEADER VEHICLE PANEL — Dropdown for VIN/Reg search
//    Attaches to nav menu items: VIN Lookup, Registration Lookup
add_action( 'wp_footer', 'mvp_vehicle_search_bar', 25 );
function mvp_vehicle_search_bar() {
    if ( ! is_front_page() && ! is_home() ) return;

    // Build model → slug + year data from DB term meta
    $maxus_term_id = mvp_get_maxus_term_id();
    $vin_terms = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $maxus_term_id,
        'hide_empty' => false,
        'orderby'    => 'name',
    ) );

    $models = array();
    if ( ! is_wp_error( $vin_terms ) ) {
        foreach ( $vin_terms as $t ) {
            $model = get_term_meta( $t->term_id, 'vehicle_model', true );
            $slug  = get_term_meta( $t->term_id, 'vehicle_slug', true );
            $year  = get_term_meta( $t->term_id, 'vehicle_year', true );
            if ( $model && $slug ) {
                $models[ $model ] = array( 'slug' => $slug, 'year' => $year ? $year : '' );
            }
        }
    }
    ksort( $models );

    $home_url = home_url( '/' );
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
    <link rel="stylesheet" id="mvp-vehicle-search-bar" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-vehicle-search-bar.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-vehicle-search-bar.css' ); ?>">
    <script id="mvp-vehicle-search-bar-data">window.mvpData=window.mvpData||{};window.mvpData["mvp-vehicle-search-bar"]=[<?php echo json_encode( $models ); ?>,<?php echo json_encode( $home_url ); ?>,<?php echo json_encode( $ajax_url ); ?>];</script>
    <script id="mvp-vehicle-search-bar-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-vehicle-search-bar.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-vehicle-search-bar.js' ); ?>"></script>
    <?php
}


// ============================================================
// 11. VEHICLE META FIELDS ON PRODUCT CATEGORY EDIT SCREEN
// ============================================================

/**
 * Render editable vehicle meta fields on the Edit Category screen.
 */
add_action( 'product_cat_edit_form_fields', 'mvp_vehicle_meta_edit_fields', 10, 2 );
function mvp_vehicle_meta_edit_fields( $term, $taxonomy ) {
    $model = get_term_meta( $term->term_id, 'vehicle_model', true );
    $slug  = get_term_meta( $term->term_id, 'vehicle_slug',  true );
    $year  = get_term_meta( $term->term_id, 'vehicle_year',  true );
    $image = get_term_meta( $term->term_id, 'vehicle_image', true );
    wp_nonce_field( 'mvp_vehicle_meta_save', 'mvp_vehicle_meta_nonce' );
    ?>
    <tr class="form-field">
        <th scope="row"><label for="mvp_vehicle_model"><?php esc_html_e( 'Vehicle Model', 'mobex-child' ); ?></label></th>
        <td>
            <input type="text" id="mvp_vehicle_model" name="mvp_vehicle_model" value="<?php echo esc_attr( $model ); ?>" />
            <p class="description"><?php esc_html_e( 'e.g. Maxus Deliver 9', 'mobex-child' ); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label for="mvp_vehicle_slug"><?php esc_html_e( 'Vehicle Slug', 'mobex-child' ); ?></label></th>
        <td>
            <input type="text" id="mvp_vehicle_slug" name="mvp_vehicle_slug" value="<?php echo esc_attr( $slug ); ?>" />
            <p class="description"><?php esc_html_e( 'URL-safe identifier, e.g. deliver-9', 'mobex-child' ); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label for="mvp_vehicle_year"><?php esc_html_e( 'Vehicle Year', 'mobex-child' ); ?></label></th>
        <td>
            <input type="text" id="mvp_vehicle_year" name="mvp_vehicle_year" value="<?php echo esc_attr( $year ); ?>" />
            <p class="description"><?php esc_html_e( 'e.g. 2022', 'mobex-child' ); ?></p>
        </td>
    </tr>
    <tr class="form-field">
        <th scope="row"><label for="mvp_vehicle_image"><?php esc_html_e( 'Vehicle Image URL', 'mobex-child' ); ?></label></th>
        <td>
            <input type="url" id="mvp_vehicle_image" name="mvp_vehicle_image" value="<?php echo esc_attr( $image ); ?>" style="width:100%;" />
            <?php if ( $image ) : ?>
                <img src="<?php echo esc_url( $image ); ?>" alt="Vehicle preview" style="margin-top:8px;max-height:80px;" />
            <?php endif; ?>
            <p class="description"><?php esc_html_e( 'Full URL to the vehicle image.', 'mobex-child' ); ?></p>
        </td>
    </tr>
    <?php
}

/**
 * Render vehicle meta fields on the Add New Category screen.
 */
add_action( 'product_cat_add_form_fields', 'mvp_vehicle_meta_add_fields', 10, 1 );
function mvp_vehicle_meta_add_fields( $taxonomy ) {
    wp_nonce_field( 'mvp_vehicle_meta_save', 'mvp_vehicle_meta_nonce' );
    ?>
    <div class="form-field">
        <label for="mvp_vehicle_model"><?php esc_html_e( 'Vehicle Model', 'mobex-child' ); ?></label>
        <input type="text" id="mvp_vehicle_model" name="mvp_vehicle_model" value="" />
        <p><?php esc_html_e( 'e.g. Maxus Deliver 9', 'mobex-child' ); ?></p>
    </div>
    <div class="form-field">
        <label for="mvp_vehicle_slug"><?php esc_html_e( 'Vehicle Slug', 'mobex-child' ); ?></label>
        <input type="text" id="mvp_vehicle_slug" name="mvp_vehicle_slug" value="" />
        <p><?php esc_html_e( 'URL-safe identifier, e.g. deliver-9', 'mobex-child' ); ?></p>
    </div>
    <div class="form-field">
        <label for="mvp_vehicle_year"><?php esc_html_e( 'Vehicle Year', 'mobex-child' ); ?></label>
        <input type="text" id="mvp_vehicle_year" name="mvp_vehicle_year" value="" />
        <p><?php esc_html_e( 'e.g. 2022', 'mobex-child' ); ?></p>
    </div>
    <div class="form-field">
        <label for="mvp_vehicle_image"><?php esc_html_e( 'Vehicle Image URL', 'mobex-child' ); ?></label>
        <input type="url" id="mvp_vehicle_image" name="mvp_vehicle_image" value="" />
        <p><?php esc_html_e( 'Full URL to the vehicle image.', 'mobex-child' ); ?></p>
    </div>
    <?php
}

/**
 * Save vehicle meta fields when a product category is created or updated.
 */
add_action( 'created_product_cat', 'mvp_vehicle_meta_save_fields', 10, 2 );
add_action( 'edited_product_cat',  'mvp_vehicle_meta_save_fields', 10, 2 );
function mvp_vehicle_meta_save_fields( $term_id, $tt_id ) {
    if ( ! isset( $_POST['mvp_vehicle_meta_nonce'] ) ||
         ! wp_verify_nonce( $_POST['mvp_vehicle_meta_nonce'], 'mvp_vehicle_meta_save' ) ) {
        return;
    }
    $fields = array( 'vehicle_model', 'vehicle_slug', 'vehicle_year', 'vehicle_image' );
    foreach ( $fields as $field ) {
        $post_key = 'mvp_' . $field;
        if ( isset( $_POST[ $post_key ] ) ) {
            $value = sanitize_text_field( $_POST[ $post_key ] );
            if ( $value !== '' ) {
                update_term_meta( $term_id, $field, $value );
            } else {
                delete_term_meta( $term_id, $field );
            }
        }
    }
}


// ============================================================
// 12. VEHICLE NOTICE BAR — STICKY TOP BAR ON ALL PAGES
// ============================================================

// Set vehicle cookies on WooCommerce product_cat pages when a Maxus VIN term is an ancestor
add_action( 'wp_footer', 'mvp_set_vehicle_cookies_from_product_cat' );
function mvp_set_vehicle_cookies_from_product_cat() {
    if ( ! is_tax( 'product_cat' ) ) return;

    $maxus_term_id = mvp_get_maxus_term_id();
    $queried = get_queried_object();
    if ( ! ( $queried instanceof WP_Term ) ) return;

    // Walk up the ancestor chain to find the VIN-level term (direct child of Maxus)
    $vin_term = null;
    if ( (int) $queried->parent === $maxus_term_id ) {
        $vin_term = $queried;
    } else {
        $ancestors = get_ancestors( $queried->term_id, 'product_cat', 'taxonomy' );
        foreach ( $ancestors as $anc_id ) {
            $anc = get_term( (int) $anc_id, 'product_cat' );
            if ( $anc && ! is_wp_error( $anc ) && (int) $anc->parent === $maxus_term_id ) {
                $vin_term = $anc;
                break;
            }
        }
    }

    if ( ! $vin_term ) return;

    $vehicle_slug  = get_term_meta( $vin_term->term_id, 'vehicle_slug', true );
    $vehicle_model = get_term_meta( $vin_term->term_id, 'vehicle_model', true );
    $vehicle_year  = get_term_meta( $vin_term->term_id, 'vehicle_year', true );
    $vin_serial    = $vin_term->slug;

    if ( empty( $vehicle_slug ) || empty( $vehicle_model ) ) return;
    ?>
    <script>
    (function() {
        var expires = new Date();
        expires.setDate(expires.getDate() + 30);
        var exp = expires.toUTCString();
        var secure = location.protocol === 'https:' ? '; Secure' : '';
        var path = 'path=/; SameSite=Lax' + secure;
        document.cookie = 'mvp_vehicle_slug='   + encodeURIComponent('<?php echo esc_js( $vehicle_slug ); ?>') + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_serial=' + encodeURIComponent('<?php echo esc_js( $vin_serial ); ?>') + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_model='  + encodeURIComponent('<?php echo esc_js( $vehicle_model ); ?>') + '; expires=' + exp + '; ' + path;
        document.cookie = 'mvp_vehicle_year='   + encodeURIComponent('<?php echo esc_js( $vehicle_year ); ?>') + '; expires=' + exp + '; ' + path;
    });
    </script>
    <?php
}

add_action( 'wp_body_open', 'mvp_vehicle_sticky_notice_bar' );
function mvp_vehicle_sticky_notice_bar() {
    if ( empty( $_COOKIE['mvp_vehicle_slug'] ) || empty( $_COOKIE['mvp_vehicle_model'] ) ) return;

    $model = sanitize_text_field( wp_unslash( $_COOKIE['mvp_vehicle_model'] ) );
    $year  = ! empty( $_COOKIE['mvp_vehicle_year'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['mvp_vehicle_year'] ) ) : '';
    $slug  = sanitize_title( wp_unslash( $_COOKIE['mvp_vehicle_slug'] ) );
    $vehicle_url = home_url( '/vehicle/' . $slug . '/' );
    ?>
    <link rel="stylesheet" id="mvp-vehicle-sticky-notice-bar" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-vehicle-sticky-notice-bar.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-vehicle-sticky-notice-bar.css' ); ?>">

    <div id="mvp-vehicle-bar">
        <span class="mvp-bar-label">Viewing parts for:</span>
        <span class="mvp-bar-vehicle"><?php echo esc_html( $model ); ?><?php if ( $year ) echo ' (' . esc_html( $year ) . ')'; ?></span>
        <a class="mvp-bar-change" href="<?php echo esc_url( home_url( '/#mvp-vehicles' ) ); ?>" onclick="mvpClearVehicleCookies(event);">&#8635; Change vehicle</a>
    </div>

    <script>
    function mvpClearVehicleCookies(e) {
        if (e) e.preventDefault();
        var past = 'Thu, 01 Jan 1970 00:00:00 UTC';
        ['mvp_vehicle_slug', 'mvp_vehicle_serial', 'mvp_vehicle_model', 'mvp_vehicle_year'].forEach(function(k) {
            document.cookie = k + '=; expires=' + past + '; path=/; SameSite=Lax';
        });
        window.location.href = '<?php echo esc_js( home_url( '/#mvp-vehicles' ) ); ?>';
    }
    </script>
    <?php
}

// ============================================================
// DYNAMIC SEO META TAGS & JSON-LD SCHEMA FOR PRODUCT PAGES
// ============================================================

/**
 * Inject dynamic SEO meta tags and JSON-LD schema for product pages.
 * Outputs original_sku (Oscar part number) and vehicle model for Google indexing.
 */
add_action( 'wp_head', 'mvp_inject_product_seo_meta', 1 );
function mvp_inject_product_seo_meta() {
    if ( ! is_product() ) return;
    global $post; if ( ! $post ) return;
    $product = wc_get_product( $post->ID ); if ( ! $product ) return;
    $pid = $product->get_id();

    $oem  = get_post_meta( $pid, 'original_sku', true ) ?: $product->get_sku();
    $name = $product->get_name();

    // Vehicle model(s) from the VIN category (direct child of Maxus root)
    $models = array(); $model_one = ''; $year_one = '';
    $cats = get_the_terms( $pid, 'product_cat' );
    if ( $cats && ! is_wp_error( $cats ) ) {
        $maxus = mvp_get_maxus_term_id();
        foreach ( $cats as $cat ) {
            if ( $cat->parent === $maxus ) {
                $vm = get_term_meta( $cat->term_id, 'vehicle_model', true );
                $vy = get_term_meta( $cat->term_id, 'vehicle_year', true );
                if ( $vm ) { if ( ! $model_one ) { $model_one = $vm; $year_one = $vy; } $models[] = $vm . ( $vy ? " ($vy)" : '' ); }
            }
        }
    }

    // Schema price MUST match the price shown on the page and sent in the Meta feed.
    // The store holds _price EX VAT (prices_include_tax=no) but DISPLAYS inc VAT
    // (tax_display_shop=incl), so gross it up here too. Bare schema 'price' is read
    // by Google as the final consumer price, so an ex-VAT value understates it.
    $price_inc = wc_get_price_including_tax( $product );
    $price     = ( '' === $price_inc || null === $price_inc ) ? '' : wc_format_decimal( $price_inc, 2 );
    $currency  = get_woocommerce_currency();
    $image_url = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
    $url       = get_permalink( $pid );
    $avail     = $product->is_in_stock() ? 'InStock' : 'OutOfStock';
    $weight    = $product->get_weight();
    $desc      = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
    $desc      = trim( mb_substr( $desc, 0, 300 ) );

    // NOTE: <meta name="description"> is intentionally NOT echoed here.
    // AIOSEO is the single authoritative source (echoing here caused a duplicate-meta bug).

    // --- Product JSON-LD ---
    // AIOSEO emits no Product node. WooCommerce core DOES emit one at this same @id,
    // so it is suppressed below (see woocommerce_structured_data_product filter) and
    // this richer node - brand, mpn, weight, vehicle fitment, FAQ - is the only one.
    $product_schema = array(
        '@type'       => 'Product',
        '@id'         => $url . '#product',
        'name'        => $name,
        'sku'         => $oem,
        'mpn'         => $oem,
        'description' => $desc,
        'url'         => $url,
        'brand'       => array( '@type' => 'Brand', 'name' => 'Maxus' ),
        'category'    => 'Genuine Maxus Van Parts',
    );
    if ( $image_url ) $product_schema['image'] = $image_url;
    if ( $weight )    $product_schema['weight'] = array( '@type' => 'QuantitativeValue', 'value' => $weight, 'unitCode' => 'KGM' );
    if ( '' !== $price && (float) $price > 0 ) {
        $product_schema['offers'] = array(
            '@type'              => 'Offer',
            'price'              => $price,
            'priceCurrency'      => $currency,
            'itemCondition'      => 'https://schema.org/NewCondition',
            'availability'       => 'https://schema.org/' . $avail,
            'url'                => $url,
            // Explicitly declare VAT-inclusive so Google cannot mistake this for a net price.
            'priceSpecification' => array(
                '@type'                 => 'UnitPriceSpecification',
                'price'                 => $price,
                'priceCurrency'         => $currency,
                'valueAddedTaxIncluded' => true,
            ),
            'seller'             => array( '@type' => 'Organization', 'name' => 'Maxus Parts Direct' ),
        );

        // Shopping agents (Google Shopping / AI assistants) read delivery and returns
        // straight off the offer. Values mirror /shipping-information/ and
        // /returns-and-exchanges/ - keep all three in step if the policies change.
        // No shippingRate is published on purpose: carriage is quoted live by FedEx at
        // checkout from weight, size and destination, so any fixed figure would be wrong.
        $product_schema['offers']['shippingDetails'] = array(
            '@type'               => 'OfferShippingDetails',
            'shippingDestination' => array(
                '@type'          => 'DefinedRegion',
                'addressCountry' => 'GB',
            ),
            'deliveryTime'        => array(
                '@type'         => 'ShippingDeliveryTime',
                'handlingTime'  => array(
                    '@type'    => 'QuantitativeValue',
                    'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY',
                ),
                'transitTime'   => array(
                    '@type'    => 'QuantitativeValue',
                    'minValue' => 2, 'maxValue' => 7, 'unitCode' => 'DAY',
                ),
            ),
        );

        // 14 days from delivery under the Consumer Contracts Regulations 2013.
        // Return carriage is the customer's for a change of mind (we cover it only when
        // the part is faulty or wrongly supplied, which this vocabulary cannot express).
        $product_schema['offers']['hasMerchantReturnPolicy'] = array(
            '@type'                => 'MerchantReturnPolicy',
            'applicableCountry'    => 'GB',
            'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
            'merchantReturnDays'   => 14,
            'returnMethod'         => 'https://schema.org/ReturnByMail',
            'returnFees'           => 'https://schema.org/ReturnShippingFeesCustomerResponsibility',
            'merchantReturnLink'   => home_url( '/returns-and-exchanges/' ),
        );
    }
    if ( $model_one ) {
        $veh = array( '@type' => 'Vehicle', 'name' => 'Maxus ' . $model_one );
        if ( $year_one ) $veh['vehicleModelDate'] = $year_one;
        $product_schema['isAccessoryOrSparePartFor'] = $veh;
    }

    // --- FAQPage JSON-LD (answer engines / AI quote these Q&A directly) ---
    $model_txt = $model_one ? ( 'the Maxus ' . $model_one . ( $year_one ? " ($year_one)" : '' ) ) : 'your Maxus';
    $faq = array(
        '@type'      => 'FAQPage',
        '@id'        => $url . '#faq',
        'mainEntity' => array(
            array( '@type' => 'Question', 'name' => 'Is this a genuine Maxus part?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'Yes. This is a genuine SAIC Maxus component, never an aftermarket copy.' ) ),
            array( '@type' => 'Question', 'name' => 'Will this part fit ' . $model_txt . '?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'This part is listed for ' . $model_txt . '. Exact fitment can vary by build, so add your registration or VIN at checkout and our parts team confirms the correct part for your vehicle before dispatch.' ) ),
            array( '@type' => 'Question', 'name' => 'What is the OEM part number?',
                'acceptedAnswer' => array( '@type' => 'Answer', 'text' => 'The genuine Maxus OEM part number is ' . $oem . '.' ) ),
        ),
    );

    $graph = array( '@context' => 'https://schema.org', '@graph' => array( $product_schema, $faq ) );
    echo "\n<script type=\"application/ld+json\">" . wp_json_encode( $graph, JSON_UNESCAPED_SLASHES ) . "</script>\n";
}

/**
 * WooCommerce core emits its own Product JSON-LD at the SAME @id as ours above,
 * leaving two competing Product entities on one URL - Google then picks one at
 * random, which could be the sparser core node. Ours is the richer of the two
 * (brand, mpn, weight, isAccessoryOrSparePartFor, FAQ), so core's is dropped.
 * Returning an empty array is safe: WC_Structured_Data::set_data() ignores any
 * payload with no '@type'.
 */
add_filter( 'woocommerce_structured_data_product', '__return_empty_array' );

// ============================================================
// 13. COMPONENT DIAGRAM — SVG + PARTS TABLE ON LEAF CATEGORY PAGES
// ============================================================

/**
 * Component SVG store — content-addressed files under uploads/component-svg/.
 * Each distinct diagram is stored once as <sha1>.svg; terms hold only the
 * 40-char hash in component_svg_hash. Reads fall back to the legacy
 * component_svg_code term-meta blob if the file is missing.
 */
function mvp_get_component_svg( $term_id ) {
    $sha = get_term_meta( $term_id, 'component_svg_hash', true );
    if ( $sha && preg_match( '/^[0-9a-f]{40}$/', $sha ) ) {
        $f = WP_CONTENT_DIR . '/uploads/component-svg/' . $sha . '.svg';
        if ( is_readable( $f ) ) {
            $svg = file_get_contents( $f );
            if ( $svg !== false && $svg !== '' ) { return $svg; }
        }
    }
    return get_term_meta( $term_id, 'component_svg_code', true ); // legacy fallback
}

/**
 * Public URL for a term's component SVG file (Cloudflare-cacheable),
 * or '' when the term has no valid stored diagram.
 */
function mvp_get_component_svg_url( $term_id ) {
    $sha = get_term_meta( $term_id, 'component_svg_hash', true );
    if ( $sha && preg_match( '/^[0-9a-f]{40}$/', $sha )
         && is_readable( WP_CONTENT_DIR . '/uploads/component-svg/' . $sha . '.svg' ) ) {
        return content_url( '/uploads/component-svg/' . $sha . '.svg' );
    }
    return '';
}

function mvp_store_component_svg( $term_id, $svg_code ) {
    $sha = sha1( $svg_code );
    $dir = WP_CONTENT_DIR . '/uploads/component-svg';
    if ( ! is_dir( $dir ) ) { wp_mkdir_p( $dir ); }
    $f = $dir . '/' . $sha . '.svg';
    if ( ! file_exists( $f ) ) {
        if ( file_put_contents( $f . '.tmp', $svg_code ) === false || ! rename( $f . '.tmp', $f ) ) {
            return new WP_Error( 'svg_write_failed', 'Could not write component SVG file.', array( 'status' => 500 ) );
        }
        chmod( $f, 0644 );
    }
    update_term_meta( $term_id, 'component_svg_hash', $sha );
    return $sha;
}

/**
 * On a leaf product_cat page (depth >= 3 below Maxus) that has component_svg_code
 * and component_parts_json term meta set, render an interactive SVG diagram
 * alongside a parts table grouped by call_out_order.
 * Clicking a callout number in the SVG highlights the matching row(s), and vice versa.
 */
add_action( 'woocommerce_before_shop_loop', 'mvp_render_component_diagram', 5 );
function mvp_render_component_diagram() {
    if ( ! is_tax( 'product_cat' ) ) return;

    $term = get_queried_object();
    if ( ! ( $term instanceof WP_Term ) ) return;

    // Must be at least 3 levels below Maxus root (Maxus > VIN > mid-category > leaf)
    $maxus_id  = mvp_get_maxus_term_id();
    $ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );
    if ( count( $ancestors ) < 2 || ! in_array( $maxus_id, $ancestors, true ) ) return;

    $svg_url    = mvp_get_component_svg_url( $term->term_id );
    $parts_json = get_term_meta( $term->term_id, 'component_parts_json', true );
    if ( ! $svg_url || ! $parts_json ) return;

    $parts = json_decode( $parts_json, true );
    if ( ! is_array( $parts ) || empty( $parts ) ) return;

    // Build lookup: original_sku (uppercase) -> product post
    // The import stores original_sku = JSON part_number (e.g. "C00157255")
    $products_by_sku = array();
    $loop = new WP_Query( array(
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => 500,
        'tax_query'      => array( array(
            'taxonomy' => 'product_cat',
            'field'    => 'term_id',
            'terms'    => $term->term_id,
        ) ),
    ) );
    foreach ( $loop->posts as $p ) {
        $sku = get_post_meta( $p->ID, 'original_sku', true );
        if ( $sku ) {
            $wc_product = wc_get_product( $p->ID );
            $products_by_sku[ strtoupper( trim( $sku ) ) ] = array(
                'post'       => $p,
                'wc_product' => $wc_product,
            );
        }
    }

    // Group parts by call_out_order
    $grouped = array();
    foreach ( $parts as $part ) {
        $order = (int) ( $part['call_out_order'] ?? 0 );
        $grouped[ $order ][] = $part;
    }
    ksort( $grouped );

    $uid = 'mvp-cd-' . $term->term_id;
    ?>
    <div class="mvp-component-diagram" id="<?php echo esc_attr( $uid ); ?>">

        <div class="mvp-cd-svg-wrap">
            <div class="mvp-cd-zoom-controls" aria-label="Zoom controls">
                <button class="mvp-cd-zoom-btn" data-action="out" aria-label="Zoom out">&#8722;</button>
                <button class="mvp-cd-zoom-btn" data-action="reset" aria-label="Reset zoom">&#8635;</button>
                <button class="mvp-cd-zoom-btn" data-action="in" aria-label="Zoom in">&#43;</button>
            </div>
            <div class="mvp-cd-svg-inner" data-svg-src="<?php echo esc_url( $svg_url ); ?>"></div>
            <?php // Diagram SVG is fetched client-side from the content-addressed,
                  // Cloudflare-cached file (phase 2) — no longer inlined in the HTML. ?>
        </div>

        <div class="mvp-cd-table-wrap">
            <table class="mvp-cd-table">
                <thead>
                    <tr>
                        <th class="mvp-cd-th-num">#</th>
                        <th>Part No.</th>
                        <th>Description</th>
                        <th class="mvp-cd-th-price">Price</th>
                        <th class="mvp-cd-th-cart"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $grouped as $callout_num => $group_parts ) : ?>
                    <tr class="mvp-cd-row" data-callout="<?php echo esc_attr( $callout_num ); ?>">
                        <td class="mvp-cd-num"><?php echo esc_html( $callout_num ); ?></td>
                        <td class="mvp-cd-part-col">
                        <?php foreach ( $group_parts as $i => $part ) :
                            $sku_key    = strtoupper( trim( $part['part_number'] ?? '' ) );
                            $entry      = $products_by_sku[ $sku_key ] ?? null;
                            $prod       = $entry ? $entry['post']       : null;
                            $wc_product = $entry ? $entry['wc_product'] : null;
                        ?>
                            <div class="mvp-cd-part-line<?php echo $i > 0 ? ' mvp-cd-sep' : ''; ?>">
                                <?php if ( $prod ) : ?>
                                    <a href="<?php echo esc_url( get_permalink( $prod->ID ) ); ?>">
                                        <?php echo esc_html( $part['part_number'] ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html( $part['part_number'] ); ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </td>
                        <td class="mvp-cd-desc-col">
                        <?php foreach ( $group_parts as $i => $part ) :
                            $sku_key_d  = strtoupper( trim( $part['part_number'] ?? '' ) );
                            $entry_d    = $products_by_sku[ $sku_key_d ] ?? null;
                            $prod_d     = $entry_d ? $entry_d['post'] : null;
                            $lr_val_d   = $prod_d ? get_post_meta( $prod_d->ID, 'lr', true ) : '';
                        ?>
                            <div class="mvp-cd-part-line<?php echo $i > 0 ? ' mvp-cd-sep' : ''; ?>">
                                <?php echo esc_html( $part['usage_name'] ); ?>
                                <?php if ( $lr_val_d ) : ?>
                                    <span class="mvp-cd-lr-badge mvp-cd-lr-<?php echo esc_attr( strtolower( $lr_val_d ) ); ?>"><?php echo esc_html( $lr_val_d ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </td>
                        <td class="mvp-cd-price-col">
                        <?php foreach ( $group_parts as $i => $part ) :
                            $sku_key    = strtoupper( trim( $part['part_number'] ?? '' ) );
                            $entry      = $products_by_sku[ $sku_key ] ?? null;
                            $wc_product = $entry ? $entry['wc_product'] : null;
                        ?>
                            <div class="mvp-cd-part-line<?php echo $i > 0 ? ' mvp-cd-sep' : ''; ?>">
                                <?php if ( $wc_product && $wc_product->get_price() !== '' ) : ?>
                                    <?php echo wp_kses_post( wc_price( $wc_product->get_price() ) ); ?>
                                <?php else : ?>
                                    <span class="mvp-cd-no-price">—</span>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </td>
                        <td class="mvp-cd-cart-col">
                        <?php foreach ( $group_parts as $i => $part ) :
                            $sku_key    = strtoupper( trim( $part['part_number'] ?? '' ) );
                            $entry      = $products_by_sku[ $sku_key ] ?? null;
                            $prod       = $entry ? $entry['post']       : null;
                            $wc_product = $entry ? $entry['wc_product'] : null;
                        ?>
                            <div class="mvp-cd-part-line<?php echo $i > 0 ? ' mvp-cd-sep' : ''; ?>">
                                <?php if ( $prod && $wc_product && $wc_product->is_purchasable() && $wc_product->is_in_stock() ) : ?>
                                    <a href="<?php echo esc_url( $wc_product->add_to_cart_url() ); ?>"
                                       class="mvp-cd-atc-btn"
                                       aria-label="<?php echo esc_attr( 'Add ' . $part['part_number'] . ' to cart' ); ?>">
                                        Add to cart
                                    </a>
                                <?php elseif ( $prod && $wc_product && ( $wc_product->get_price() === '' || $wc_product->get_price() === null ) ) :
                                    $lr_val = get_post_meta( $prod->ID, 'lr', true );
                                    $remark_val = get_post_meta( $prod->ID, 'remark', true );
                                ?>
                                    <button type="button"
                                       class="mvp-cd-atc-btn mvp-cd-request-price"
                                       data-sku="<?php echo esc_attr( $wc_product->get_sku() ); ?>"
                                       data-name="<?php echo esc_attr( $prod->post_title ); ?>"
                                       data-url="<?php echo esc_url( get_permalink( $prod->ID ) ); ?>"
                                       data-lr="<?php echo esc_attr( $lr_val ); ?>"
                                       data-remark="<?php echo esc_attr( $remark_val ); ?>"
                                       onclick="event.stopPropagation(); mvpOpenPriceModalFromTable(this)">
                                        Request Price
                                    </button>
                                <?php elseif ( $prod ) : ?>
                                    <a href="<?php echo esc_url( get_permalink( $prod->ID ) ); ?>"
                                       class="mvp-cd-atc-btn mvp-cd-atc-view">
                                        View
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    </div>

    <link rel="stylesheet" id="mvp-render-component-diagram" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-render-component-diagram.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-render-component-diagram.css' ); ?>">

    <script id="mvp-render-component-diagram-data">window.mvpData=window.mvpData||{};window.mvpData["mvp-render-component-diagram"]=[<?php echo json_encode( $uid ); ?>];</script>
    <script id="mvp-render-component-diagram-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-render-component-diagram.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-render-component-diagram.js' ); ?>"></script>
    <?php
}


/**
 * Custom REST API: query and update products by original_sku meta
 * Namespace: custom/v1
 * Routes:
 *   GET  /wp-json/custom/v1/products-by-sku?original_sku=B00004124
 *   POST /wp-json/custom/v1/products-by-sku  body: { original_sku, price }
 *   GET  /wp-json/custom/v1/products-by-sku/test
 */
add_action( 'rest_api_init', function () {

    // --- GET: look up products by original_sku ---
    register_rest_route( 'custom/v1', '/products-by-sku', array(
        'methods'             => 'GET',
        'callback'            => 'cvone_get_products_by_original_sku',
        'permission_callback' => 'cvone_auth_check',
        'args'                => array(
            'original_sku' => array(
                'required'          => true,
                'sanitize_callback' => 'sanitize_text_field',
            ),
            'vehicle_serial' => array(
                'required'          => false,
                'sanitize_callback' => 'sanitize_text_field',
            ),
        ),
    ) );

    // --- POST: update price for products with original_sku ---
    register_rest_route( 'custom/v1', '/products-by-sku', array(
        'methods'             => 'POST',
        'callback'            => 'cvone_update_price_by_original_sku',
        'permission_callback' => 'cvone_auth_check',
    ) );

    // --- GET /test: verify endpoint and meta query work ---
    register_rest_route( 'custom/v1', '/products-by-sku/test', array(
        'methods'             => 'GET',
        'callback'            => 'cvone_test_endpoint',
        'permission_callback' => 'cvone_auth_check',
    ) );

    // --- POST /products-by-skus-bulk: look up many SKUs in one query ---
    register_rest_route( 'custom/v1', '/products-by-skus-bulk', array(
        'methods'             => 'POST',
        'callback'            => 'cvone_bulk_products_by_original_sku',
        'permission_callback' => 'cvone_auth_check',
    ) );
} );

/**
 * Authenticate via WC consumer key/secret (Basic Auth or query-string).
 * WC's own auth sets the user for wc/v3 routes but NOT custom namespaces,
 * so we validate the key directly against the woocommerce_api_keys table.
 */
function cvone_auth_check( WP_REST_Request $request ) {
    // If WC auth has already set a user, check capability
    if ( is_user_logged_in() && current_user_can( 'edit_products' ) ) {
        return true;
    }
    // Accept the same shared secret used by the component-meta endpoint
    $secret = $request->get_param( 'secret' );
    if ( $secret && defined( 'MVP_COMPONENT_API_SECRET' ) && hash_equals( MVP_COMPONENT_API_SECRET, (string) $secret ) ) {
        return true;
    }
    // Validate WC consumer key directly from query params or Basic Auth header
    $ck = $request->get_param( 'consumer_key' );
    $cs = $request->get_param( 'consumer_secret' );
    if ( ! $ck ) {
        // Try Basic Auth header (consumer_key as username, consumer_secret as password)
        $ck = isset( $_SERVER['PHP_AUTH_USER'] ) ? $_SERVER['PHP_AUTH_USER'] : '';
        $cs = isset( $_SERVER['PHP_AUTH_PW'] )   ? $_SERVER['PHP_AUTH_PW']   : '';
    }
    if ( $ck && $cs ) {
        global $wpdb;
        $keys = $wpdb->get_row( $wpdb->prepare(
            "SELECT user_id, permissions, consumer_secret
               FROM {$wpdb->prefix}woocommerce_api_keys
              WHERE consumer_key = %s",
            wc_api_hash( $ck )
        ) );
        if ( $keys && hash_equals( $keys->consumer_secret, $cs ) ) {
            wp_set_current_user( $keys->user_id );
            return current_user_can( 'edit_products' );
        }
    }
    return false;
}

/**
 * Query the postmeta table directly for original_sku (+ optional vehicle_serial) matches.
 * Returns all post IDs (products + variations) that match.
 */
function cvone_query_ids_by_original_sku( $sku, $vehicle_serial = '' ) {
    global $wpdb;
    $sku            = sanitize_text_field( $sku );
    $vehicle_serial = sanitize_text_field( $vehicle_serial );

    if ( $vehicle_serial ) {
        // Narrow to posts that have BOTH original_sku AND vehicle_serial meta
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT pm1.post_id
               FROM {$wpdb->postmeta} pm1
               JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = pm1.post_id
              WHERE pm1.meta_key   = 'original_sku'
                AND pm1.meta_value = %s
                AND pm2.meta_key   = 'vehicle_serial'
                AND pm2.meta_value = %s",
            $sku,
            $vehicle_serial
        ) );
    } else {
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT post_id
               FROM {$wpdb->postmeta}
              WHERE meta_key   = 'original_sku'
                AND meta_value = %s",
            $sku
        ) );
    }

    return array_map( 'intval', $ids );
}

/**
 * POST /wp-json/custom/v1/products-by-skus-bulk
 * Body (JSON): { "skus": ["C00371126", ...], "secret": "..." }
 * Returns: { "C00371126": {id, parent_id, type, wc_sku, status}, ... }
 * SKUs with no match are omitted from the response.
 */
function cvone_bulk_products_by_original_sku( WP_REST_Request $request ) {
    global $wpdb;

    $body = $request->get_json_params();
    $skus = isset( $body['skus'] ) ? (array) $body['skus'] : array();

    if ( empty( $skus ) ) {
        return new WP_Error( 'missing_skus', 'No SKUs provided', array( 'status' => 400 ) );
    }

    // Sanitise and de-duplicate
    $skus = array_values( array_unique( array_map( 'sanitize_text_field', $skus ) ) );

    // Build a single IN (...) query
    $placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
    // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
    $rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT post_id, meta_value AS original_sku
               FROM {$wpdb->postmeta}
              WHERE meta_key   = 'original_sku'
                AND meta_value IN ($placeholders)",
            ...$skus
        )
    );

    $result = array();
    foreach ( $rows as $row ) {
        $post      = get_post( (int) $row->post_id );
        if ( ! $post ) continue;
        $parent_id = (int) $post->post_parent;
        $result[ $row->original_sku ] = array(
            'id'           => (int) $row->post_id,
            'parent_id'    => $parent_id,
            'type'         => ( $parent_id > 0 ) ? 'variation' : 'product',
            'wc_sku'       => get_post_meta( (int) $row->post_id, '_sku', true ),
            'status'       => $post->post_status,
        );
    }

    return new WP_REST_Response( $result, 200 );
}


function cvone_get_products_by_original_sku( WP_REST_Request $request ) {
    $sku            = $request->get_param( 'original_sku' );
    $vehicle_serial = (string) $request->get_param( 'vehicle_serial' );
    $ids            = cvone_query_ids_by_original_sku( $sku, $vehicle_serial );

    if ( empty( $ids ) ) {
        return new WP_REST_Response( array(
            'found'        => 0,
            'original_sku' => $sku,
            'products'     => array(),
        ), 200 );
    }

    $results = array();
    foreach ( $ids as $post_id ) {
        $post        = get_post( $post_id );
        $wc_sku      = get_post_meta( $post_id, '_sku', true );
        $parent_id   = $post ? (int) $post->post_parent : 0;
        $results[]   = array(
            'id'           => $post_id,
            'parent_id'    => $parent_id,
            'type'         => ( $parent_id > 0 ) ? 'variation' : 'product',
            'wc_sku'       => $wc_sku,
            'original_sku' => $sku,
            'status'       => $post ? $post->post_status : 'unknown',
        );
    }

    return new WP_REST_Response( array(
        'found'        => count( $results ),
        'original_sku' => $sku,
        'products'     => $results,
    ), 200 );
}

/**
 * POST /wp-json/custom/v1/products-by-sku
 * Body (JSON): { "original_sku": "B00004124", "price": "1.33" }
 */
function cvone_update_price_by_original_sku( WP_REST_Request $request ) {
    $sku   = sanitize_text_field( $request->get_param( 'original_sku' ) );
    $price = $request->get_param( 'price' );

    if ( ! $sku ) {
        return new WP_Error( 'missing_sku', 'original_sku is required', array( 'status' => 400 ) );
    }
    if ( ! is_numeric( $price ) || (float) $price <= 0 ) {
        return new WP_Error( 'invalid_price', 'price must be a positive number', array( 'status' => 400 ) );
    }

    $price_str = number_format( (float) $price, 2, '.', '' );
    $ids       = cvone_query_ids_by_original_sku( $sku );

    if ( empty( $ids ) ) {
        return new WP_REST_Response( array(
            'updated'      => 0,
            'original_sku' => $sku,
            'message'      => 'No products found with that original_sku',
        ), 200 );
    }

    $updated = array();
    $failed  = array();

    foreach ( $ids as $post_id ) {
        // Update WooCommerce price meta directly
        $ok1 = update_post_meta( $post_id, '_price',         $price_str );
        $ok2 = update_post_meta( $post_id, '_regular_price', $price_str );

        // Clear the transient/object cache for this product
        $parent_id = (int) get_post_field( 'post_parent', $post_id );
        wc_delete_product_transients( $parent_id > 0 ? $parent_id : $post_id );

        if ( $ok1 !== false || $ok2 !== false ) {
            $updated[] = array(
                'id'        => $post_id,
                'parent_id' => $parent_id,
                'price'     => $price_str,
            );
        } else {
            $failed[] = $post_id;
        }
    }

    return new WP_REST_Response( array(
        'original_sku' => $sku,
        'price'        => $price_str,
        'updated'      => count( $updated ),
        'failed'       => count( $failed ),
        'products'     => $updated,
        'failed_ids'   => $failed,
    ), 200 );
}

/**
 * GET /wp-json/custom/v1/products-by-sku/test
 * Runs a test query against postmeta to confirm original_sku meta exists on the site.
 */
function cvone_test_endpoint( WP_REST_Request $request ) {
    global $wpdb;

    // Count how many products have the original_sku meta key at all
    $total_with_meta = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'original_sku'"
    );

    // Grab up to 5 example values
    $examples = $wpdb->get_results(
        "SELECT post_id, meta_value
           FROM {$wpdb->postmeta}
          WHERE meta_key = 'original_sku'
          LIMIT 5",
        ARRAY_A
    );

    // Try a specific lookup for B00004124 as a known test SKU
    $test_sku  = 'B00004124';
    $test_ids  = cvone_query_ids_by_original_sku( $test_sku );

    return new WP_REST_Response( array(
        'status'                        => 'ok',
        'total_with_original_sku_meta'  => $total_with_meta,
        'example_values'                => $examples,
        'test_sku'                      => $test_sku,
        'test_sku_post_ids'             => $test_ids,
    ), 200 );
}

/**
 * Diagnostic: Check if functions.php is loaded and cache status
 */
add_action( 'rest_api_init', function () {
    register_rest_route( 'custom/v1', '/diagnostic', array(
        'methods'             => 'GET',
        'callback'            => 'cvone_diagnostic',
        'permission_callback' => '__return_true',
    ) );
} );

function cvone_diagnostic() {
    $functions_file = get_stylesheet_directory() . '/functions.php';
    $file_mtime = file_exists( $functions_file ) ? filemtime( $functions_file ) : 0;
    
    return new WP_REST_Response( array(
        'functions_php_modified' => $file_mtime > 0 ? date( 'Y-m-d H:i:s', $file_mtime ) : 'not found',
        'functions_php_modified_timestamp' => $file_mtime,
        'wordpress_time' => current_time( 'mysql' ),
        'php_version' => phpversion(),
        'opcache_enabled' => function_exists( 'opcache_get_status' ) && opcache_get_status() !== false,
        'test_cookie_value' => isset( $_COOKIE['mvp_vehicle_slug'] ) ? $_COOKIE['mvp_vehicle_slug'] : 'not set',
        'diagnostic_added' => 'March 19, 2026 - Cookie issue investigation',
    ), 200 );
}

// ============================================================
// Products by Date Updated Status — REST Endpoint
// Now with unique original_sku filtering
// ============================================================
// GET /wp-json/custom/v1/products-by-date-updated?status=empty|invalid|stale|all&days=7&page=1&per_page=100&unique_original_sku=1
add_action( 'rest_api_init', function () {
    register_rest_route( 'custom/v1', '/products-by-date-updated', array(
        'methods'             => 'GET',
        'callback'            => 'cvone_get_products_by_date_updated',
        'permission_callback' => '__return_true',
    ) );
} );

function cvone_get_products_by_date_updated( WP_REST_Request $request ) {
    global $wpdb;

    $status              = $request->get_param( 'status' ) ?: 'all';
    $days                = (int) ( $request->get_param( 'days' ) ?: 7 );
    $page                = (int) ( $request->get_param( 'page' ) ?: 1 );
    $per_page            = (int) ( $request->get_param( 'per_page' ) ?: 100 );
    $offset              = ( $page - 1 ) * $per_page;
    $unique_original_sku = filter_var( $request->get_param( 'unique_original_sku' ), FILTER_VALIDATE_BOOLEAN );

    $date_threshold = date( 'Y-m-d', strtotime( "-{$days} days" ) );

    $where_clause = "p.post_type = 'product' AND p.post_status = 'publish'";

    // Join used only when dedup is requested
    $osku_join = "
        LEFT JOIN {$wpdb->postmeta} osku ON p.ID = osku.post_id AND osku.meta_key = 'original_sku'
    ";

    // Dedup key: real original_sku when present/non-empty, otherwise a per-ID fallback so
    // products without an original_sku are never collapsed into each other.
    $dedup_key = "IF(osku.meta_value IS NULL OR osku.meta_value = '', CONCAT('__no_osku_', p.ID), osku.meta_value)";

    if ( $status === 'empty' ) {
        $base_select = "p.ID, p.post_title, '' as date_updated";
        $base_from   = "
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'date_updated'
            {$osku_join}
            WHERE {$where_clause}
              AND pm.meta_id IS NULL
        ";
        $prepare_args_query = array( $per_page, $offset );
        $prepare_args_count = array();

    } elseif ( $status === 'invalid' ) {
        $base_select = "p.ID, p.post_title, pm.meta_value as date_updated";
        $base_from   = "
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'date_updated'
            {$osku_join}
            WHERE {$where_clause}
              AND pm.meta_value != ''
              AND pm.meta_value NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
        ";
        $prepare_args_query = array( $per_page, $offset );
        $prepare_args_count = array();

    } elseif ( $status === 'stale' ) {
        $base_select = "p.ID, p.post_title, pm.meta_value as date_updated";
        $base_from   = "
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'date_updated'
            {$osku_join}
            WHERE {$where_clause}
              AND pm.meta_value REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
              AND pm.meta_value < %s
        ";
        $prepare_args_query = array( $date_threshold, $per_page, $offset );
        $prepare_args_count = array( $date_threshold );

    } else { // all
        $base_select = "p.ID, p.post_title, COALESCE(pm.meta_value, '') as date_updated";
        $base_from   = "
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'date_updated'
            {$osku_join}
            WHERE {$where_clause}
              AND (
                pm.meta_id IS NULL
                OR pm.meta_value = ''
                OR pm.meta_value NOT REGEXP '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
                OR pm.meta_value < %s
              )
        ";
        $prepare_args_query = array( $date_threshold, $per_page, $offset );
        $prepare_args_count = array( $date_threshold );
    }

    if ( $unique_original_sku ) {
        // Wrap in a window-function subquery to pick one row per original_sku
        // (lowest post ID wins), THEN paginate the deduped set.
        $query = "
            SELECT ID, post_title, date_updated FROM (
                SELECT {$base_select},
                       ROW_NUMBER() OVER (
                           PARTITION BY {$dedup_key}
                           ORDER BY p.ID ASC
                       ) as rn
                {$base_from}
            ) deduped
            WHERE rn = 1
            ORDER BY ID ASC
            LIMIT %d OFFSET %d
        ";

        $count_query = "
            SELECT COUNT(*) FROM (
                SELECT {$dedup_key} as dkey
                {$base_from}
                GROUP BY dkey
            ) deduped_count
        ";

        // query needs all args except the trailing LIMIT/OFFSET replaced correctly;
        // count query drops the trailing per_page/offset args entirely.
        $count_args = array_slice( $prepare_args_query, 0, count( $prepare_args_query ) - 2 );

        $products = $wpdb->get_results( $wpdb->prepare( $query, $prepare_args_query ), ARRAY_A );
        $total    = empty( $count_args )
            ? (int) $wpdb->get_var( $count_query )
            : (int) $wpdb->get_var( $wpdb->prepare( $count_query, $count_args ) );

    } else {
        // Original (non-deduped) behavior
        $query = "
            SELECT {$base_select}
            {$base_from}
            ORDER BY p.ID ASC
            LIMIT %d OFFSET %d
        ";
        $count_query = "
            SELECT COUNT(DISTINCT p.ID)
            {$base_from}
        ";
        $count_args = array_slice( $prepare_args_query, 0, count( $prepare_args_query ) - 2 );

        $products = $wpdb->get_results( $wpdb->prepare( $query, $prepare_args_query ), ARRAY_A );
        $total    = empty( $count_args )
            ? (int) $wpdb->get_var( $count_query )
            : (int) $wpdb->get_var( $wpdb->prepare( $count_query, $count_args ) );
    }

    // Add additional product details
    $enriched_products = array();
    foreach ( $products as $product ) {
        $_product = wc_get_product( $product['ID'] );
        if ( ! $_product ) continue;

        $enriched_products[] = array(
            'id'            => (int) $product['ID'],
            'title'         => $product['post_title'],
            'sku'           => $_product->get_sku(),
            'original_sku'  => get_post_meta( $product['ID'], 'original_sku', true ),
            'date_updated'  => $product['date_updated'],
            'edit_link'     => admin_url( 'post.php?post=' . $product['ID'] . '&action=edit' ),
        );
    }

    $total_pages = ceil( $total / $per_page );

    return new WP_REST_Response( array(
        'status'              => $status,
        'days'                => $days,
        'date_threshold'      => $date_threshold,
        'page'                => $page,
        'per_page'            => $per_page,
        'unique_original_sku' => $unique_original_sku,
        'total'               => $total,
        'total_pages'         => $total_pages,
        'products'            => $enriched_products,
    ), 200 );
}

// ============================================================
// 14. COMPONENT DIAGRAM — REST ENDPOINT TO SAVE TERM META
// ============================================================
// POST /wp-json/custom/v1/set-component-meta
// Body (JSON): { "term_id": 4356, "svg_code": "...", "parts_json": "[...]" }
// Auth: WC Consumer Key / Consumer Secret via HTTP Basic Auth.

// Secret shared between this endpoint and the import script.
// Change this value if you need to rotate it.
define( 'MVP_COMPONENT_API_SECRET', 'mvp-comp-2026-xK9pLq' );

add_action( 'rest_api_init', function () {
    register_rest_route( 'custom/v1', '/set-component-meta', array(
        'methods'             => 'POST',
        'callback'            => 'mvp_set_component_meta',
        'permission_callback' => 'mvp_set_component_meta_permission',
        'args'                => array(
            'term_id'    => array( 'required' => true,  'type' => 'integer' ),
            'svg_code'   => array( 'required' => false, 'type' => 'string'  ),
            'parts_json' => array( 'required' => false, 'type' => 'string'  ),
            'secret'     => array( 'required' => false, 'type' => 'string'  ),
        ),
    ) );
} );

function mvp_set_component_meta_permission( WP_REST_Request $request ) {
    // Allow logged-in admins (WP application-password auth) in addition to the shared secret.
    if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
        return true;
    }
    $secret = $request->get_param( 'secret' );
    return hash_equals( MVP_COMPONENT_API_SECRET, (string) $secret );
}

function mvp_set_component_meta( WP_REST_Request $request ) {
    $term_id = (int) $request->get_param( 'term_id' );

    // Verify the term exists and is a product_cat
    $term = get_term( $term_id, 'product_cat' );
    if ( ! $term || is_wp_error( $term ) ) {
        return new WP_Error( 'invalid_term', 'Term not found or not a product_cat.', array( 'status' => 404 ) );
    }

    $updated = array();

    $svg_code = $request->get_param( 'svg_code' );
    // No-overwrite guard: only fill categories that currently have no diagram.
    if ( $svg_code !== null
         && ! get_term_meta( $term_id, 'component_svg_hash', true )
         && ! get_term_meta( $term_id, 'component_svg_code', true ) ) {
        $stored = mvp_store_component_svg( $term_id, $svg_code );
        if ( is_wp_error( $stored ) ) {
            return $stored;
        }
        $updated[] = 'component_svg_hash';
    }

    $parts_json = $request->get_param( 'parts_json' );
    if ( $parts_json !== null && ! get_term_meta( $term_id, 'component_parts_json', true ) ) {
        // Validate it's parseable JSON
        $decoded = json_decode( $parts_json, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'invalid_json', 'parts_json must be a valid JSON array.', array( 'status' => 400 ) );
        }
        update_term_meta( $term_id, 'component_parts_json', $parts_json );
        $updated[] = 'component_parts_json';
    }

    return new WP_REST_Response( array(
        'success' => true,
        'term_id' => $term_id,
        'term_name' => $term->name,
        'updated'   => $updated,
    ), 200 );
}


// ============================================================
// 15. SUBCATEGORY GRID — MID-LEVEL CATEGORY PAGES
// ============================================================

/**
 * On mid-level category pages with exactly 1 leaf child, skip straight to the leaf.
 */
add_action( 'template_redirect', 'mvp_midlevel_single_child_redirect' );
function mvp_midlevel_single_child_redirect() {
    if ( ! is_tax( 'product_cat' ) ) return;

    $term = get_queried_object();
    if ( ! ( $term instanceof WP_Term ) ) return;

    $maxus_id  = mvp_get_maxus_term_id();
    $ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );

    // Only act on mid-level: exactly 2 ancestors = [VIN-id, Maxus-id]
    if ( count( $ancestors ) !== 2 || ! in_array( $maxus_id, $ancestors, true ) ) return;

    $children = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $term->term_id,
        'hide_empty' => false,
        'number'     => 2, // only need to know if count is 1
    ) );

    if ( ! is_wp_error( $children ) && count( $children ) === 1 ) {
        $mvp_redirect_url = get_term_link( $children[0] );
        if ( ! is_wp_error( $mvp_redirect_url ) ) {
            wp_redirect( $mvp_redirect_url, 302 );
            exit;
        }
    }
}

/**
 * On mid-level category pages (Maxus > VIN > mid-category), show the
 * leaf sub-categories as clickable cards instead of a flat product listing.
 * Depth detected by ancestor count: exactly 2 ancestors = [VIN, Maxus].
 */
add_action( 'woocommerce_before_shop_loop', 'mvp_render_midlevel_subcat_grid', 4 );
function mvp_render_midlevel_subcat_grid() {
    if ( ! is_tax( 'product_cat' ) ) return;

    $term = get_queried_object();
    if ( ! ( $term instanceof WP_Term ) ) return;

    $maxus_id  = mvp_get_maxus_term_id();
    $ancestors = get_ancestors( $term->term_id, 'product_cat', 'taxonomy' );

    // Mid-level: exactly 2 ancestors = [VIN-id, Maxus-id]
    if ( count( $ancestors ) !== 2 || ! in_array( $maxus_id, $ancestors, true ) ) return;

    $children = get_terms( array(
        'taxonomy'   => 'product_cat',
        'parent'     => $term->term_id,
        'hide_empty' => false,
        'orderby'    => 'name',
        'order'      => 'ASC',
    ) );

    if ( is_wp_error( $children ) || empty( $children ) ) return;

    // Suppress the product loop, result count, and sort order that follow
    remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count',    20 );
    remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
    wc_set_loop_prop( 'total', 0 );
    add_filter( 'woocommerce_product_loop_start', '__return_empty_string' );
    add_filter( 'woocommerce_product_loop_end',   '__return_empty_string' );
    remove_all_actions( 'woocommerce_after_shop_loop' );
    remove_action( 'woocommerce_no_products_found', 'wc_no_products_found' );

    ?>
    <div class="mvp-subcat-grid">
        <?php foreach ( $children as $child ) :
            $link  = get_term_link( $child );
            if ( is_wp_error( $link ) ) continue;
            $count = (int) $child->count;
        ?>
        <?php
            $img_name = mvp_category_icon_file( $child->name );
            $has_img  = ( $img_name !== '' );
        ?>
        <a class="mvp-subcat-card<?php echo $has_img ? ' has-img' : ''; ?>" href="<?php echo esc_url( $link ); ?>">
            <?php if ( $has_img ) : ?>
            <span class="mvp-subcat-img"><img src="<?php echo esc_url( content_url( '/uploads/categories/' . $img_name ) ); ?>" alt="<?php echo esc_attr( $child->name ); ?>" /></span>
            <?php else : ?>
            <span class="mvp-subcat-icon">&#9741;</span>
            <?php endif; ?>
            <span class="mvp-subcat-name"><?php echo esc_html( $child->name ); ?></span>
            <?php if ( $count > 0 ) : ?>
            <span class="mvp-subcat-count"><?php echo esc_html( $count ); ?> part<?php echo $count !== 1 ? 's' : ''; ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <link rel="stylesheet" id="mvp-render-midlevel-subcat-grid" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-render-midlevel-subcat-grid.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-render-midlevel-subcat-grid.css' ); ?>">
    <?php
}
// ============================================================
// 16. CHECKOUT — Vehicle VIN / Registration Confirmation Field
// ============================================================
add_action( 'wp_footer', 'mvp_checkout_vehicle_field', 99 );
function mvp_checkout_vehicle_field() {
    if ( ! is_checkout() ) return;
    ?>
    <script id="mvp-checkout-vehicle-field-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-checkout-vehicle-field.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-checkout-vehicle-field.js' ); ?>"></script>
    <?php
}

// Save the vehicle VIN/Reg field to order meta
add_action( 'woocommerce_checkout_update_order_meta', 'mvp_save_vehicle_field' );
function mvp_save_vehicle_field( $order_id ) {
    if ( ! empty( $_POST['vehicle_reg'] ) || ! empty( $_POST['vehicle_vin'] ) ) {
        $regs = isset( $_POST['vehicle_reg'] ) ? array_map( 'sanitize_text_field', $_POST['vehicle_reg'] ) : array();
        $vins = isset( $_POST['vehicle_vin'] ) ? array_map( 'sanitize_text_field', $_POST['vehicle_vin'] ) : array();
        $vehicles = array();
        for ( $i = 0; $i < max( count( $regs ), count( $vins ) ); $i++ ) {
            $r = isset( $regs[$i] ) ? trim( $regs[$i] ) : '';
            $v = isset( $vins[$i] ) ? trim( $vins[$i] ) : '';
            if ( $r || $v ) $vehicles[] = array( 'reg' => $r, 'vin' => $v );
        }
        if ( $vehicles ) {
        update_post_meta( $order_id, '_vehicle_verification', $vehicles );
        }
    }
}

// Display vehicle VIN/Reg in admin order
add_action( 'woocommerce_admin_order_data_after_billing_address', 'mvp_display_vehicle_field_admin' );
function mvp_display_vehicle_field_admin( $order ) {
    $vehicles = get_post_meta( $order->get_id(), '_vehicle_verification', true );
    if ( $vehicles && is_array( $vehicles ) ) {
        echo '<p><strong>Vehicle Verification:</strong></p>';
        foreach ( $vehicles as $v ) {
            echo '<p>Reg: ' . esc_html( $v['reg'] ) . ' | VIN: ' . esc_html( $v['vin'] ) . '</p>';
        }
    }
}

/* ── Round product weight display to 2 decimal places ── */
add_filter( 'woocommerce_product_get_weight', function( $weight ) {
    return is_numeric( $weight ) ? round( (float) $weight, 2 ) : $weight;
});

/* ── Subcategory thumbnails: fall back to /categories/ folder images ── */
add_action( 'init', function() {
    remove_action( 'woocommerce_before_subcategory_title', 'mobex_enovathemes_subcategory_thumbnail', 10 );
    add_action( 'woocommerce_before_subcategory_title', 'mvp_subcategory_thumbnail_fallback', 10 );
}, 20 );
function mvp_subcategory_thumbnail_fallback( $category ) {
    $thumbnail_id = get_term_meta( $category->term_id, 'thumbnail_id', true );
    if ( $thumbnail_id ) {
        echo wp_get_attachment_image( $thumbnail_id, 'woocommerce_thumbnail' );
    } else {
        $img_name = mvp_category_icon_file( $category->name );
        if ( $img_name !== '' ) {
            $img_url = content_url( '/uploads/categories/' . $img_name );
            echo '<img src="' . esc_url( $img_url ) . '" alt="' . esc_attr( $category->name ) . '" style="background:#fff;object-fit:contain;width:100%;height:auto;padding:10px;" />';
        } else {
            $placeholder = wc_placeholder_img_src();
            if ( $placeholder ) {
                echo '<img src="' . esc_url( $placeholder ) . '" />';
            }
        }
    }
}

// ============================================================
// Mobile: Force vehicle filter form visible (override theme JS slideToggle)
// ============================================================
add_action( 'wp_footer', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <link rel="stylesheet" id="mvp-subcategory-thumbnail-fallback" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-subcategory-thumbnail-fallback.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-subcategory-thumbnail-fallback.css' ); ?>">
    <script id="mvp-subcategory-thumbnail-fallback-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-subcategory-thumbnail-fallback.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-subcategory-thumbnail-fallback.js' ); ?>"></script>
    <?php
}, 999 );

// ============================================================
// Favicon / Site Icon
// ============================================================
add_action( 'wp_head', function() {
    $site = home_url();
    echo '<link rel="icon" type="image/x-icon" href="' . $site . '/favicon.ico">';
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . $site . '/favicon-32.png">';
    echo '<link rel="icon" type="image/png" sizes="192x192" href="' . $site . '/favicon-192.png">';
    echo '<link rel="apple-touch-icon" sizes="180x180" href="' . $site . '/apple-touch-icon.png">';
}, 1 );


// ============================================================
// Featured Products section — homepage
// ============================================================
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <link rel="stylesheet" id="mvp-injectMobileFilter" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-injectMobileFilter.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-injectMobileFilter.css' ); ?>">
    <?php
}, 21 );

add_action( 'wp_footer', 'mvp_featured_products_section', 12 );
function mvp_featured_products_section() {
    if ( ! is_front_page() && ! is_home() ) return;

    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => 9,
        'post_status'    => 'publish',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'meta_query'     => array(
            array(
                'key'     => '_price',
                'value'   => '',
                'compare' => '!=',
            ),
            array(
                'key'     => '_thumbnail_id',
                'compare' => 'EXISTS',
            ),
        ),
        'tax_query' => array(
            array(
                'taxonomy' => 'product_visibility',
                'field'    => 'name',
                'terms'    => 'exclude-from-catalog',
                'operator' => 'NOT IN',
            ),
        ),
    );
    $products = new WP_Query( $args );
    if ( ! $products->have_posts() ) { wp_reset_postdata(); return; }
    ?>
    <div class="mvp-featured-section" id="mvp-featured-products" style="display:none;">
        <h2>Featured Products</h2>
        <div class="mvp-featured-grid">
            <?php while ( $products->have_posts() ) : $products->the_post();
                $product = wc_get_product( get_the_ID() );
                if ( ! $product ) continue;
                $img = wp_get_attachment_image_src( get_post_thumbnail_id(), 'thumbnail' );
            ?>
            <a href="<?php the_permalink(); ?>" class="mvp-feat-card">
                <div class="mvp-feat-card-img">
                    <?php if ( $img ) : ?>
                    <img src="<?php echo esc_url( $img[0] ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
                    <?php endif; ?>
                </div>
                <div class="mvp-feat-card-info">
                    <h3 class="mvp-feat-card-title"><?php the_title(); ?></h3>
                    <?php if ( $product->get_sku() ) : ?>
                    <p class="mvp-feat-card-sku">SKU: <?php echo esc_html( $product->get_sku() ); ?></p>
                    <?php endif; ?>
                    <p class="mvp-feat-card-price"><?php echo $product->get_price_html(); ?></p>
                    <span class="mvp-feat-card-btn">Add to cart</span>
                </div>
            </a>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
    </div>
    <script id="mvp-featured-products-section-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-featured-products-section.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-featured-products-section.js' ); ?>"></script>
    <?php
}


// ============================================================
// Product Diagram — show SVG with highlighted callout on product page
// ============================================================

// Show "Part number X in diagram" in product summary above Estimated Delivery
add_action( 'woocommerce_single_product_summary', function() {
    global $product;
    if ( ! $product ) return;
    $callout = get_post_meta( $product->get_id(), 'callout_number', true );
    if ( ! $callout ) return;
    echo '<div class="mvp-callout-badge">';
    echo '<span class="mvp-callout-label">Part number </span>';
    echo '<span class="mvp-callout-num">' . esc_html( $callout ) . '</span>';
    echo '<span class="mvp-callout-label"> in diagram</span>';
    echo '</div>';
}, 27 );

// Replace the product gallery image with the SAME contained, zoomable diagram
// widget used on category pages (see mvp_render_component_diagram). The old
// approach forced the A4-portrait SVG into the gallery slot at height:auto and
// then EXPANDED the viewBox to include any stray off-page geometry (common in
// the EPC exports), which left many products with a tall empty box and a
// drifting orange callout ring. Containing the SVG in a fixed, scrollable box
// with a fit-to-box viewBox fixes every affected product uniformly.
add_action( 'wp_footer', 'mvp_product_svg_gallery', 30 );
function mvp_product_svg_gallery() {
    if ( ! is_product() ) return;
    global $product;
    if ( ! $product ) return;

    $callout = get_post_meta( $product->get_id(), 'callout_number', true );
    if ( ! $callout ) return;

    // Find the leaf category that carries the diagram SVG
    $cats = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'all' ) );
    if ( is_wp_error( $cats ) || empty( $cats ) ) return;

    $svg_url = '';
    foreach ( $cats as $cat ) {
        $u = mvp_get_component_svg_url( $cat->term_id );
        if ( $u ) { $svg_url = $u; break; }
    }
    if ( ! $svg_url ) return;
    ?>
    <div id="mvp-pd-svg-source" style="display:none;" data-svg-src="<?php echo esc_url( $svg_url ); ?>"></div>
    <script id="mvp-product-svg-gallery-data">window.mvpData=window.mvpData||{};window.mvpData["mvp-product-svg-gallery"]=[<?php echo json_encode( $callout ); ?>];</script>
    <script id="mvp-product-svg-gallery-js" src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/js/mvp-product-svg-gallery.js' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/js/mvp-product-svg-gallery.js' ); ?>"></script>
    <?php
}

add_action( 'wp_head', function() {
    if ( ! is_product() ) return;
    ?>
    <link rel="stylesheet" id="mvp-applyBase" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-applyBase.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-applyBase.css' ); ?>">
    <?php
}, 22 );

// Featured products section removed — was breaking homepage layout

// Homepage products CSS removed

// ============================================================
// Performance: Hide PHP version header
// ============================================================
add_filter( 'wp_headers', function( $headers ) {
    unset( $headers['X-Powered-By'] );
    return $headers;
} );
if ( ! headers_sent() ) { header_remove( 'X-Powered-By' ); }

// ============================================================
// Performance: Only load CF7 JS/CSS on pages with forms
// ============================================================
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_page( array( 'contact', 'contact-us', 'trade-account' ) ) ) {
        wp_dequeue_script( 'contact-form-7' );
        wp_dequeue_style( 'contact-form-7' );
    }
}, 100 );

// ============================================================
// Performance: Only load Worldpay JS on checkout
// ============================================================
add_action( 'wp_enqueue_scripts', function() {
    if ( ! is_checkout() && ! is_cart() ) {
        wp_dequeue_script( 'worldpay-checkout' );
        wp_dequeue_script( 'worldpay-sdk' );
        wp_dequeue_style( 'worldpay-checkout' );
    }
}, 100 );

// Fix department sidebar icon black boxes on homepage
add_action( 'wp_head', function() {
    if ( ! is_front_page() && ! is_home() ) return;
    ?>
    <link rel="stylesheet" id="mvp-applyBase-2" href="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/css/mvp-applyBase-2.css' ); ?>?v=<?php echo filemtime( get_stylesheet_directory() . '/assets/css/mvp-applyBase-2.css' ); ?>">
    <?php
}, 20 );


if ( ! function_exists( 'mvp_category_icon_file' ) ) {
/**
 * Resolve a product_cat name to an icon file basename in /uploads/categories/.
 * Returns '' if none found. Exact current-convention match is tried FIRST, so any
 * card that already resolves keeps identical behaviour; the rest are fallbacks only.
 * Added 2026-07-06 (blank category-card fix). Safe to revert: delete this function
 * and this session's git commit restores the prior inline resolvers.
 */
function mvp_category_icon_file( $name ) {
    static $norm_index = null;
    $dir = WP_CONTENT_DIR . '/uploads/categories/';
    $d   = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
    // 1) exact current convention (entity-decoded name, spaces -> underscore)
    $try = str_replace( ' ', '_', $d ) . '.png';
    if ( is_file( $dir . $try ) ) return $try;
    // 2) & -> and
    $try_and = str_replace( '&', 'and', $try );
    if ( is_file( $dir . $try_and ) ) return $try_and;
    // 3) filesystem-sanitised (slash/backslash -> underscore) - fixes names with "/"
    $san = str_replace( array( ' ', '/', '\\' ), '_', $d ) . '.png';
    if ( $san !== $try && is_file( $dir . $san ) ) return $san;
    // 4) normalised alnum index (largest real file wins), built once per request
    if ( $norm_index === null ) {
        $norm_index = array();
        foreach ( (array) glob( $dir . '*.png' ) as $gf ) {
            $sz = @filesize( $gf );
            if ( $sz === false || $sz <= 1000 ) continue;
            if ( ! mb_check_encoding( $gf, 'UTF-8' ) ) continue; // skip corrupt-byte filenames that break URLs
            $b = preg_replace( '/_[A-Za-z0-9]{7}$/', '', basename( $gf, '.png' ) );
            $k = preg_replace( '/[^a-z0-9]/', '', strtolower( html_entity_decode( $b, ENT_QUOTES, 'UTF-8' ) ) );
            if ( $k === '' ) continue;
            if ( ! isset( $norm_index[ $k ] ) || $sz > (int) $norm_index[ $k ]['s'] ) {
                $norm_index[ $k ] = array( 'f' => basename( $gf ), 's' => $sz );
            }
        }
    }
    $key = preg_replace( '/[^a-z0-9]/', '', strtolower( $d ) );
    if ( $key !== '' && isset( $norm_index[ $key ] ) ) return $norm_index[ $key ]['f'];
    return '';
}

// ============================================================
// Custom Product Meta Fields
// ============================================================

/**
 * Add custom tab for Maxus product data in WooCommerce product editor
 */
add_filter( 'woocommerce_product_data_tabs', 'mvp_add_custom_product_data_tab' );
function mvp_add_custom_product_data_tab( $tabs ) {
    $tabs['mvp_custom_data'] = array(
        'label'    => __( 'Maxus Data', 'woocommerce' ),
        'target'   => 'mvp_custom_product_data',
        'class'    => array(),
        'priority' => 60,
    );
    return $tabs;
}

/**
 * Add custom fields to the Maxus Data tab
 */
add_action( 'woocommerce_product_data_panels', 'mvp_add_custom_product_data_fields' );
function mvp_add_custom_product_data_fields() {
    global $post;
    ?>
    <div id="mvp_custom_product_data" class="panel woocommerce_options_panel">
        <?php
        // Callout Number field
        woocommerce_wp_text_input( array(
            'id'          => 'callout_number',
            'label'       => __( 'Callout Number', 'woocommerce' ),
            'placeholder' => '',
            'desc_tip'    => true,
            'description' => __( 'Product callout number for identification.', 'woocommerce' ),
        ) );
        
        // Original SKU field
        woocommerce_wp_text_input( array(
            'id'          => 'original_sku',
            'label'       => __( 'Original SKU', 'woocommerce' ),
            'placeholder' => '',
            'desc_tip'    => true,
            'description' => __( 'Original SKU/part number (Oscar part number).', 'woocommerce' ),
        ) );
        
        // Replacement Available checkbox
        woocommerce_wp_checkbox( array(
            'id'          => 'replacement_avail',
            'label'       => __( 'Replacement Available', 'woocommerce' ),
            'description' => __( 'Check if a replacement product is available.', 'woocommerce' ),
        ) );
        
        // Replacement SKU field
        woocommerce_wp_text_input( array(
            'id'          => 'replacement_sku',
            'label'       => __( 'Replacement SKU', 'woocommerce' ),
            'placeholder' => '',
            'desc_tip'    => true,
            'description' => __( 'Replacement product SKU/part number.', 'woocommerce' ),
        ) );
        
        // Date Updated field
        woocommerce_wp_text_input( array(
            'id'          => 'date_updated',
            'label'       => __( 'Date Updated', 'woocommerce' ),
            'placeholder' => 'YYYY-MM-DD',
            'desc_tip'    => true,
            'description' => __( 'Date when product data was last updated (YYYY-MM-DD format).', 'woocommerce' ),
            'type'        => 'date',
        ) );
        ?>
    </div>
    <?php
}

/**
 * Save custom product meta fields
 */
add_action( 'woocommerce_process_product_meta', 'mvp_save_custom_product_data_fields' );
function mvp_save_custom_product_data_fields( $post_id ) {
    // Callout Number
    $callout_number = isset( $_POST['callout_number'] ) ? sanitize_text_field( $_POST['callout_number'] ) : '';
    update_post_meta( $post_id, 'callout_number', $callout_number );
    
    // Original SKU
    $original_sku = isset( $_POST['original_sku'] ) ? sanitize_text_field( $_POST['original_sku'] ) : '';
    update_post_meta( $post_id, 'original_sku', $original_sku );
    
    // Replacement Available (checkbox)
    $replacement_avail = isset( $_POST['replacement_avail'] ) ? 'yes' : 'no';
    update_post_meta( $post_id, 'replacement_avail', $replacement_avail );
    
    // Replacement SKU
    $replacement_sku = isset( $_POST['replacement_sku'] ) ? sanitize_text_field( $_POST['replacement_sku'] ) : '';
    update_post_meta( $post_id, 'replacement_sku', $replacement_sku );
    
    // Date Updated
    $date_updated = isset( $_POST['date_updated'] ) ? sanitize_text_field( $_POST['date_updated'] ) : '';
    update_post_meta( $post_id, 'date_updated', $date_updated );
}
}


// ---- Homepage vehicle-circle ordering: popular models first (added 18-Jul-2026) ----
// Lower number = shown earlier. Deliver 9 leads, then Deliver 3, then the rest.
function mvp_vehicle_priority( $term_id ) {
    $m = strtoupper( (string) get_term_meta( $term_id, 'vehicle_model', true ) );
    if ( strpos( $m, 'DELIVER 9' ) !== false ) return 10;   // incl. e-Deliver 9 / New Deliver 9 / RWD/FWD/LUX/STD/CHASSIS/2026
    if ( strpos( $m, 'DELIVER 3' ) !== false || strpos( $m, 'EV30' ) !== false ) return 20;
    if ( strpos( $m, 'DELIVER 7' ) !== false ) return 30;
    if ( strpos( $m, 'DELIVER 5' ) !== false ) return 40;
    if ( strpos( $m, 'T90' ) !== false )       return 50;
    if ( strpos( $m, 'T60' ) !== false )       return 55;
    if ( strpos( $m, 'ETERRON' ) !== false )   return 60;
    if ( strpos( $m, 'MIFA' ) !== false )      return 70;
    if ( strpos( $m, 'V80' ) !== false || strpos( $m, 'EV80' ) !== false ) return 80;
    if ( strpos( $m, 'A80' ) !== false )       return 85;
    return 100;
}
function mvp_vehicle_popularity_cmp( $a, $b ) {
    $pa = mvp_vehicle_priority( $a->term_id );
    $pb = mvp_vehicle_priority( $b->term_id );
    if ( $pa !== $pb ) return $pa - $pb;
    $ma = (string) get_term_meta( $a->term_id, 'vehicle_model', true );
    $mb = (string) get_term_meta( $b->term_id, 'vehicle_model', true );
    return strcasecmp( $ma, $mb );  // within a tier, alphabetical
}

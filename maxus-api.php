<?php
/**
 * Maxus fitment API  —  /wp-json/maxus/v1/
 *
 * Answers the one question a general-purpose AI assistant cannot answer on its own:
 * "will this part fit my van?". Built for agents (ChatGPT, Gemini, MCP clients) but
 * equally usable by trade customers and our own front end.
 *
 * Deliberate split, because the two halves have opposite economics:
 *   - Fitment and catalogue data is OURS and costs nothing to serve, so it is PUBLIC.
 *     Agents will not use an endpoint they need a key for, and every answer points at
 *     a product page, so open access drives sales.
 *   - Registration -> vehicle goes to checkcardetails, which BILLS PER LOOKUP, so it
 *     stays authenticated. Never make that one public.
 *
 * All prices are returned INCLUDING VAT, matching the product pages, the Meta feed and
 * the JSON-LD. An agent quoting a different number to the landing page is worse than useless.
 *
 * Added 28-Jul-2026.
 */

defined( 'ABSPATH' ) || exit;

const MAXUS_API_NS             = 'maxus/v1';
const MAXUS_API_RATE_PER_HOUR  = 300;   // per IP, public endpoints only
const MAXUS_API_CACHE_TTL      = HOUR_IN_SECONDS;
const MAXUS_API_MAX_PER_PAGE   = 100;

/**
 * Public endpoints are open but not a free scraping target. Generous enough that a
 * genuine assistant conversation never notices; low enough that nobody mirrors 36k
 * products through it.
 */
function maxus_api_public_gate() {
	$ip     = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
	$bucket = 'maxus_api_rl_' . md5( $ip );
	$used   = (int) get_transient( $bucket );
	if ( $used >= MAXUS_API_RATE_PER_HOUR ) {
		return new WP_Error(
			'maxus_rate_limited',
			'Rate limit reached. Please slow down, or contact us for a key with a higher quota.',
			array( 'status' => 429 )
		);
	}
	set_transient( $bucket, $used + 1, HOUR_IN_SECONDS );
	return true;
}

/** VAT-inclusive price + the fields an agent needs to present and link a product. */
function maxus_api_product_payload( $product_id ) {
	$p = wc_get_product( $product_id );
	if ( ! $p ) {
		return null;
	}
	$inc = wc_get_price_including_tax( $p );
	return array(
		'id'            => (int) $product_id,
		'name'          => $p->get_name(),
		'oem_part_number' => (string) get_post_meta( $product_id, 'original_sku', true ),
		'sku'           => $p->get_sku(),
		'side'          => (string) get_post_meta( $product_id, 'lr', true ),
		'price'         => ( '' === $inc || null === $inc ) ? null : (float) wc_format_decimal( $inc, 2 ),
		'currency'      => get_woocommerce_currency(),
		'price_includes_vat' => true,
		'in_stock'      => $p->is_in_stock(),
		'url'           => get_permalink( $product_id ),
		'image'         => wp_get_attachment_image_url( $p->get_image_id(), 'full' ) ?: null,
	);
}

/** The 29 real vehicles, from the term meta that is the source of truth. */
function maxus_api_vehicles() {
	$out = array();
	foreach ( mvp_get_vehicle_vins() as $slug => $v ) {
		$out[] = array(
			'slug'      => $slug,
			'model'     => $v['name'],
			'years'     => $v['year'],
			'vin_pattern' => $v['vin'],
			'image'     => $v['img'] ?: null,
			'url'       => home_url( '/vehicle/' . $slug . '/' ),
		);
	}
	usort( $out, function ( $a, $b ) { return strcmp( $a['model'], $b['model'] ); } );
	return $out;
}

add_action( 'rest_api_init', function () {

	// ---- GET /vehicles : the full model list -------------------------------------
	register_rest_route( MAXUS_API_NS, '/vehicles', array(
		'methods'             => 'GET',
		'permission_callback' => 'maxus_api_public_gate',
		'callback'            => function () {
			$cached = get_transient( 'maxus_api_vehicles' );
			if ( ! is_array( $cached ) ) {
				$cached = maxus_api_vehicles();
				set_transient( 'maxus_api_vehicles', $cached, MAXUS_API_CACHE_TTL );
			}
			return rest_ensure_response( array( 'count' => count( $cached ), 'vehicles' => $cached ) );
		},
	) );

	// ---- GET /vehicle?vin= : identify a van from its VIN -------------------------
	// Free: this is our own mapping, no external call. Matching is delegated to the
	// same helper the site uses so the API can never disagree with the website.
	register_rest_route( MAXUS_API_NS, '/vehicle', array(
		'methods'             => 'GET',
		'permission_callback' => 'maxus_api_public_gate',
		'args'                => array(
			'vin' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
		),
		'callback'            => function ( WP_REST_Request $r ) {
			$vin = strtoupper( preg_replace( '/\s+/', '', $r->get_param( 'vin' ) ) );
			if ( strlen( $vin ) < 8 ) {
				return new WP_Error( 'maxus_bad_vin', 'Please supply at least the first 8 characters of the VIN.', array( 'status' => 400 ) );
			}
			$matches = array();
			foreach ( mvp_get_vehicle_vins() as $slug => $v ) {
				// Vehicle terms are named by VIN pattern; several vans can share a prefix,
				// which is why this returns a list rather than guessing one.
				if ( $v['vin'] && 0 === strpos( $vin, substr( $v['vin'], 0, 8 ) ) ) {
					$matches[] = array(
						'slug'  => $slug,
						'model' => $v['name'],
						'years' => $v['year'],
						'url'   => home_url( '/vehicle/' . $slug . '/' ),
						'parts_api' => rest_url( MAXUS_API_NS . '/parts?vehicle=' . rawurlencode( $slug ) ),
					);
				}
			}
			return rest_ensure_response( array(
				'vin'      => $vin,
				'matched'  => count( $matches ),
				'vehicles' => $matches,
				'note'     => $matches ? null : 'No Maxus or LDV vehicle matched that VIN.',
			) );
		},
	) );

	// ---- GET /parts?vehicle=&q= : the parts that fit a given van -----------------
	register_rest_route( MAXUS_API_NS, '/parts', array(
		'methods'             => 'GET',
		'permission_callback' => 'maxus_api_public_gate',
		'args'                => array(
			'vehicle'  => array( 'required' => true, 'sanitize_callback' => 'sanitize_title' ),
			'q'        => array( 'required' => false, 'sanitize_callback' => 'sanitize_text_field' ),
			'page'     => array( 'required' => false, 'sanitize_callback' => 'absint' ),
			'per_page' => array( 'required' => false, 'sanitize_callback' => 'absint' ),
		),
		'callback'            => function ( WP_REST_Request $r ) {
			$slug     = $r->get_param( 'vehicle' );
			$q        = (string) $r->get_param( 'q' );
			$page     = max( 1, (int) $r->get_param( 'page' ) );
			$per_page = min( MAXUS_API_MAX_PER_PAGE, max( 1, (int) $r->get_param( 'per_page' ) ?: 20 ) );

			$vehicles = mvp_get_vehicle_vins();
			if ( ! isset( $vehicles[ $slug ] ) ) {
				return new WP_Error( 'maxus_unknown_vehicle', 'Unknown vehicle. Call /vehicles for the list.', array( 'status' => 404 ) );
			}

			$args = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'tax_query'      => array( array(
					'taxonomy'         => 'product_cat',
					'field'            => 'term_id',
					'terms'            => $vehicles[ $slug ]['term_id'],
					'include_children' => true,   // the vehicle's whole diagram tree
				) ),
			);
			if ( '' !== $q ) {
				$args['s'] = $q;
			}
			$query = new WP_Query( $args );
			$items = array();
			foreach ( $query->posts as $post ) {
				$payload = maxus_api_product_payload( $post->ID );
				if ( $payload ) { $items[] = $payload; }
			}
			return rest_ensure_response( array(
				'vehicle'     => array( 'slug' => $slug, 'model' => $vehicles[ $slug ]['name'] ),
				'query'       => $q,
				'page'        => $page,
				'per_page'    => $per_page,
				'total'       => (int) $query->found_posts,
				'total_pages' => (int) $query->max_num_pages,
				'parts'       => $items,
			) );
		},
	) );

	// ---- GET /part/<oem> : one part, and every van it fits -----------------------
	register_rest_route( MAXUS_API_NS, '/part/(?P<oem>[A-Za-z0-9\-_.]+)', array(
		'methods'             => 'GET',
		'permission_callback' => 'maxus_api_public_gate',
		'callback'            => function ( WP_REST_Request $r ) {
			global $wpdb;
			$oem = sanitize_text_field( $r->get_param( 'oem' ) );

			// Parts are replicated per vehicle with suffixed SKUs; original_sku is the
			// real manufacturer number, so one OEM number can map to several products.
			$ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT pm.post_id FROM {$wpdb->postmeta} pm
				 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = 'original_sku' AND pm.meta_value = %s
				   AND p.post_type = 'product' AND p.post_status = 'publish'
				 LIMIT 200", $oem ) );
			if ( ! $ids ) {
				return new WP_Error( 'maxus_part_not_found', 'No part with that OEM number.', array( 'status' => 404 ) );
			}

			// Which vehicles these products sit under = what the part fits.
			$fits = array();
			$vehicles = mvp_get_vehicle_vins();
			$byterm = array();
			foreach ( $vehicles as $slug => $v ) { $byterm[ (int) $v['term_id'] ] = array( $slug, $v['name'] ); }
			foreach ( $ids as $pid ) {
				foreach ( wp_get_post_terms( $pid, 'product_cat', array( 'fields' => 'ids' ) ) as $tid ) {
					$chain = array_merge( array( $tid ), get_ancestors( $tid, 'product_cat', 'taxonomy' ) );
					foreach ( $chain as $c ) {
						if ( isset( $byterm[ $c ] ) ) {
							$fits[ $byterm[ $c ][0] ] = $byterm[ $c ][1];
						}
					}
				}
			}
			ksort( $fits );

			$listings = array();
			foreach ( $ids as $pid ) {
				$payload = maxus_api_product_payload( $pid );
				if ( $payload ) { $listings[] = $payload; }
			}
			return rest_ensure_response( array(
				'oem_part_number' => $oem,
				'fits_vehicles'   => array_map(
					function ( $slug, $model ) { return array( 'slug' => $slug, 'model' => $model, 'url' => home_url( '/vehicle/' . $slug . '/' ) ); },
					array_keys( $fits ), array_values( $fits ) ),
				'listings'        => $listings,
			) );
		},
	) );

	// ---- GET /vehicle-by-reg?reg= : AUTHENTICATED, because it costs money --------
	register_rest_route( MAXUS_API_NS, '/vehicle-by-reg', array(
		'methods'             => 'GET',
		'permission_callback' => 'cvone_auth_check',   // WC consumer key or shared secret
		'args'                => array(
			'reg' => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
		),
		'callback'            => function ( WP_REST_Request $r ) {
			$reg = strtoupper( preg_replace( '/\s+/', '', $r->get_param( 'reg' ) ) );
			if ( strlen( $reg ) < 2 ) {
				return new WP_Error( 'maxus_bad_reg', 'Please supply a valid registration.', array( 'status' => 400 ) );
			}
			if ( ! defined( 'MAXUS_CCD_API_KEY' ) || '' === MAXUS_CCD_API_KEY ) {
				return new WP_Error( 'maxus_lookup_unavailable', 'Registration lookup is not configured.', array( 'status' => 503 ) );
			}
			// Shares the cache the website's own lookup fills, so a registration already
			// looked up on site costs nothing here (and vice versa).
			$cache_key = 'mvp_ccd_' . md5( $reg );
			$cached    = get_transient( $cache_key );
			if ( is_array( $cached ) && isset( $cached['body'] ) ) {
				$body = $cached['body'];
			} else {
				$resp = wp_remote_get(
					'https://api.checkcardetails.co.uk/vehicledata/vehicleregistration?apikey='
					. MAXUS_CCD_API_KEY . '&vrm=' . urlencode( $reg ),
					array( 'timeout' => 10 )
				);
				if ( is_wp_error( $resp ) ) {
					return new WP_Error( 'maxus_lookup_failed', 'Vehicle lookup service unavailable.', array( 'status' => 502 ) );
				}
				$code = wp_remote_retrieve_response_code( $resp );
				$body = json_decode( wp_remote_retrieve_body( $resp ), true );
				if ( 200 === $code || 404 === $code ) {
					set_transient( $cache_key, array( 'code' => $code, 'body' => $body ), 30 * DAY_IN_SECONDS );
				}
				if ( 200 !== $code ) {
					return new WP_Error( 'maxus_reg_not_found', 'No vehicle found for that registration.', array( 'status' => 404 ) );
				}
			}
			$make = isset( $body['make'] ) ? strtoupper( trim( $body['make'] ) ) : '';
			return rest_ensure_response( array(
				'registration' => $reg,
				'make'         => $make,
				'model'        => isset( $body['model'] ) ? trim( $body['model'] ) : '',
				'year'         => isset( $body['yearOfManufacture'] ) ? (int) $body['yearOfManufacture'] : null,
				'fuel'         => isset( $body['fuelType'] ) ? trim( $body['fuelType'] ) : '',
				'is_supported' => in_array( $make, array( 'MAXUS', 'LDV', 'SAIC', 'MG' ), true ),
				'next'         => 'Use /vehicles or /vehicle?vin= to pick the exact variant, then /parts?vehicle=<slug>.',
			) );
		},
	) );
} );

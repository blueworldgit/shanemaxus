<?php
/**
 * Maxus MCP server  —  POST /wp-json/maxus/v1/mcp
 *
 * Lets an AI assistant (ChatGPT, Claude, any MCP client) call our fitment lookups as
 * tools, instead of guessing which part fits a van. Thin wrapper over the same
 * functions the REST API uses, so the two can never disagree.
 *
 * Transport: Streamable HTTP - a single endpoint accepting POST, replying with a JSON
 * object. SSE is permitted but unnecessary here: every one of these tools returns in
 * one shot, and a plain JSON reply is explicitly allowed by the spec.
 *
 * Protocol era: the draft revision (2026-07-28) drops the `initialize` handshake and
 * sessions in favour of per-request metadata, but shipping connectors still speak the
 * 2025-03-26..2025-11-25 era that requires `initialize`. We answer BOTH, because
 * refusing the older flow would mean no current client could connect.
 *
 * Only the free lookups are exposed. Registration lookup is deliberately absent: it
 * calls a per-lookup billed service and belongs behind a key, not on an open tool.
 *
 * Added 28-Jul-2026.
 */

defined( 'ABSPATH' ) || exit;

const MAXUS_MCP_PROTOCOL_DEFAULT = '2025-06-18';

/** Tool catalogue. Descriptions are written for a model deciding which to call. */
function maxus_mcp_tools() {
	return array(
		array(
			'name'        => 'list_maxus_vehicles',
			'description' => 'List every Maxus and LDV van variant that Maxus Parts Direct stocks parts for. '
				. 'Use this to get the vehicle slug needed by find_maxus_parts, or when the customer knows their model but not their VIN.',
			'inputSchema' => array( 'type' => 'object', 'properties' => new stdClass(), 'required' => array() ),
		),
		array(
			'name'        => 'identify_maxus_vehicle_by_vin',
			'description' => 'Identify which Maxus or LDV variant a VIN belongs to. Fitment varies by build, not just model year, '
				. 'so always prefer this over inferring the vehicle from a model name. If it returns more than one candidate, '
				. 'ask the customer which they have rather than choosing one.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'vin' => array( 'type' => 'string', 'description' => 'Full VIN, or at least its first 8 characters.' ) ),
				'required'   => array( 'vin' ),
			),
		),
		array(
			'name'        => 'find_maxus_parts',
			'description' => 'Find genuine SAIC parts that fit a given Maxus/LDV variant, optionally filtered by a search term '
				. 'such as "brake disc" or "wing mirror". Returns OEM part numbers, VAT-inclusive prices and a URL to buy each part.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array(
					'vehicle'  => array( 'type' => 'string', 'description' => 'Vehicle slug from list_maxus_vehicles or identify_maxus_vehicle_by_vin.' ),
					'query'    => array( 'type' => 'string', 'description' => 'Optional free-text filter, e.g. "front brake pads".' ),
					'per_page' => array( 'type' => 'integer', 'description' => 'How many to return, max 50. Defaults to 20.' ),
				),
				'required'   => array( 'vehicle' ),
			),
		),
		array(
			'name'        => 'get_maxus_part',
			'description' => 'Look up one genuine part by its OEM part number and see every Maxus/LDV variant it fits, '
				. 'plus current listings with VAT-inclusive prices and buy links. Useful when a customer quotes a part number.',
			'inputSchema' => array(
				'type'       => 'object',
				'properties' => array( 'oem_part_number' => array( 'type' => 'string', 'description' => 'Genuine SAIC OEM part number, e.g. C00022603.' ) ),
				'required'   => array( 'oem_part_number' ),
			),
		),
	);
}

/** Run a tool. Returns MCP content blocks. */
function maxus_mcp_call_tool( $name, $args ) {
	$args = is_array( $args ) ? $args : array();

	switch ( $name ) {
		case 'list_maxus_vehicles':
			$data = maxus_api_vehicles();
			return array( 'count' => count( $data ), 'vehicles' => $data );

		case 'identify_maxus_vehicle_by_vin':
			$vin = strtoupper( preg_replace( '/\s+/', '', (string) ( $args['vin'] ?? '' ) ) );
			if ( strlen( $vin ) < 8 ) {
				return array( 'error' => 'Please supply at least the first 8 characters of the VIN.' );
			}
			$matches = array();
			foreach ( mvp_get_vehicle_vins() as $slug => $v ) {
				if ( $v['vin'] && 0 === strpos( $vin, substr( $v['vin'], 0, 8 ) ) ) {
					$matches[] = array( 'slug' => $slug, 'model' => $v['name'], 'years' => $v['year'], 'url' => home_url( '/vehicle/' . $slug . '/' ) );
				}
			}
			return array(
				'vin'      => $vin,
				'matched'  => count( $matches ),
				'vehicles' => $matches,
				'guidance' => count( $matches ) > 1
					? 'Several variants share this VIN prefix - ask the customer which one they have before recommending parts.'
					: ( $matches ? null : 'No Maxus or LDV vehicle matched that VIN.' ),
			);

		case 'find_maxus_parts':
			$slug     = sanitize_title( (string) ( $args['vehicle'] ?? '' ) );
			$q        = (string) ( $args['query'] ?? '' );
			$per_page = min( 50, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
			$vehicles = mvp_get_vehicle_vins();
			if ( ! isset( $vehicles[ $slug ] ) ) {
				return array( 'error' => 'Unknown vehicle slug. Call list_maxus_vehicles first.' );
			}
			$query_args = array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'tax_query'      => array( array(
					'taxonomy' => 'product_cat', 'field' => 'term_id',
					'terms' => $vehicles[ $slug ]['term_id'], 'include_children' => true,
				) ),
			);
			if ( '' !== $q ) { $query_args['s'] = $q; }
			$wpq   = new WP_Query( $query_args );
			$items = array();
			foreach ( $wpq->posts as $post ) {
				$p = maxus_api_product_payload( $post->ID );
				if ( $p ) { $items[] = $p; }
			}
			return array(
				'vehicle'       => array( 'slug' => $slug, 'model' => $vehicles[ $slug ]['name'] ),
				'query'         => $q,
				'total_matching'=> (int) $wpq->found_posts,
				'returned'      => count( $items ),
				'parts'         => $items,
				'note'          => 'Prices include UK VAT and match the product pages, so they are safe to quote.',
			);

		case 'get_maxus_part':
			$oem = sanitize_text_field( (string) ( $args['oem_part_number'] ?? '' ) );
			if ( '' === $oem ) { return array( 'error' => 'Supply an OEM part number.' ); }
			$req = new WP_REST_Request( 'GET' );
			$req->set_param( 'oem', $oem );
			// Reuse the REST handler so the two surfaces can never drift apart.
			$route = rest_get_server()->get_routes();
			$resp  = rest_do_request( new WP_REST_Request( 'GET', '/' . MAXUS_API_NS . '/part/' . rawurlencode( $oem ) ) );
			if ( $resp->is_error() ) {
				return array( 'error' => 'No part with that OEM number.' );
			}
			return $resp->get_data();
	}
	return array( 'error' => 'Unknown tool: ' . $name );
}

add_action( 'rest_api_init', function () {
	register_rest_route( MAXUS_API_NS, '/mcp', array(
		'methods'             => 'POST, GET, DELETE',
		'permission_callback' => '__return_true',   // read-only public tools; rate limited below
		'callback'            => 'maxus_mcp_handle',
	) );
} );

function maxus_mcp_handle( WP_REST_Request $request ) {
	// The draft revision removed the GET stream and DELETE session teardown.
	if ( 'POST' !== $request->get_method() ) {
		return new WP_REST_Response( null, 405 );
	}

	// Guard against DNS-rebinding: a browser page on another origin must not be able
	// to drive this endpoint. Non-browser clients send no Origin and are unaffected.
	$origin = $request->get_header( 'origin' );
	if ( $origin && ! maxus_mcp_origin_allowed( $origin ) ) {
		return new WP_REST_Response(
			array( 'jsonrpc' => '2.0', 'error' => array( 'code' => -32600, 'message' => 'Origin not allowed' ) ),
			403
		);
	}

	$gate = maxus_api_public_gate();
	if ( is_wp_error( $gate ) ) {
		return new WP_REST_Response(
			array( 'jsonrpc' => '2.0', 'error' => array( 'code' => -32000, 'message' => 'Rate limit reached.' ) ),
			429
		);
	}

	$body = json_decode( $request->get_body(), true );
	if ( ! is_array( $body ) || ! isset( $body['method'] ) ) {
		return new WP_REST_Response(
			array( 'jsonrpc' => '2.0', 'id' => null, 'error' => array( 'code' => -32700, 'message' => 'Parse error' ) ),
			400
		);
	}

	$method = (string) $body['method'];
	$id     = $body['id'] ?? null;
	$params = isset( $body['params'] ) && is_array( $body['params'] ) ? $body['params'] : array();

	// A notification has no id: acknowledge and send nothing back.
	if ( ! array_key_exists( 'id', $body ) ) {
		return new WP_REST_Response( null, 202 );
	}

	switch ( $method ) {
		case 'initialize':
			// Legacy-era handshake. Echo the client's protocol version when we recognise
			// the shape of it, so both eras of client get an answer they understand.
			$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : MAXUS_MCP_PROTOCOL_DEFAULT;
			return maxus_mcp_result( $id, array(
				'protocolVersion' => $requested,
				'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
				'serverInfo'      => array( 'name' => 'Maxus Parts Direct', 'version' => '1.0.0' ),
				'instructions'    => 'Fitment tools for genuine Maxus and LDV van parts. Fitment varies by build, so if the '
					. 'customer can give a VIN, identify the vehicle first rather than inferring it from the model name. '
					. 'Prices include UK VAT and match the website, so they are safe to quote.',
			) );

		case 'ping':
			return maxus_mcp_result( $id, new stdClass() );

		case 'tools/list':
			return maxus_mcp_result( $id, array( 'tools' => maxus_mcp_tools() ) );

		case 'tools/call':
			$name = isset( $params['name'] ) ? (string) $params['name'] : '';
			$args = isset( $params['arguments'] ) ? $params['arguments'] : array();
			$known = wp_list_pluck( maxus_mcp_tools(), 'name' );
			if ( ! in_array( $name, $known, true ) ) {
				return maxus_mcp_error( $id, -32602, 'Unknown tool: ' . $name, 400 );
			}
			$data    = maxus_mcp_call_tool( $name, $args );
			$isError = isset( $data['error'] );
			return maxus_mcp_result( $id, array(
				'content'           => array( array(
					'type' => 'text',
					'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ),
				) ),
				'structuredContent' => $data,
				'isError'           => (bool) $isError,
			) );
	}

	// Unknown method: the spec asks for 404 alongside -32601 so clients can tell this
	// apart from a 404 produced by a server that hosts no MCP endpoint at all.
	return maxus_mcp_error( $id, -32601, 'Method not found: ' . $method, 404 );
}

function maxus_mcp_origin_allowed( $origin ) {
	$host = wp_parse_url( $origin, PHP_URL_HOST );
	if ( ! $host ) { return false; }
	$site = wp_parse_url( home_url(), PHP_URL_HOST );
	return strtolower( $host ) === strtolower( (string) $site );
}

function maxus_mcp_result( $id, $result ) {
	return new WP_REST_Response( array( 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ), 200 );
}

function maxus_mcp_error( $id, $code, $message, $status ) {
	return new WP_REST_Response(
		array( 'jsonrpc' => '2.0', 'id' => $id, 'error' => array( 'code' => $code, 'message' => $message ) ),
		$status
	);
}

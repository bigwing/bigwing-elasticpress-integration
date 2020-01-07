<?php
/**
 * Plugin Name: BigWing ElasticPress Integration
 * Author: BigWing
 * Author URI: https://bigwing.com
 * Description: Integrate and extend BigWing's Elasticsearch service using the ElasticPress plugin.
 * Requires PHP: 7.0
 * Version: 0.5.0
 *
 * This program derives work from Nicolai's Elasticpress Autosuggest Endpoint plugin:
 * https://github.com/grossherr/elasticpress-autosuggest-endpoint
 *
 * BigWing ElasticPress Integration plugin for WordPress
 * Copyright (C) 2020  BigWing
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 * @package   BigWing\ElasticPress
 * @author    BigWing <https://bigwing.com>
 * @copyright 2020 BigWing
 * @license   GNU General Public License, version 2 or later
 */

declare( strict_types=1 );

namespace BigWing\ElasticPress;

use ElasticPress\Elasticsearch;
use ElasticPress\Indexables;

define( 'BW_EP_REST_NAMESPACE', 'bigwing/elasticpress' );
define( 'BW_EP_REST_CURRENT_VERSION', 1 );

register_activation_hook( __FILE__, 'flush_rewrite_rules' );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * Register ElasticPress Autosuggest endpoint with the site's WP REST API.
 */
add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			BW_EP_REST_NAMESPACE . '/v1',
			'autosuggest',
			array(
				'methods'  => \WP_REST_Server::CREATABLE,
				'callback' => __NAMESPACE__ . '\ep_autosuggest',
			)
		);
	}
);

/**
 * Initialize the plugin settings.
 */
add_action(
	'init',
	function () {
		// Wait until the REST API is loaded before defining the endpoint.
		define( 'EP_AUTOSUGGEST_ENDPOINT', get_autosuggest_endpoint() );
	}
);

/**
 * Filter the index with a unique prefix for this site.
 */
add_filter(
	'ep_index_prefix',
	function ( string $ep_index_prefix ): string {
		if ( ! empty( $ep_index_prefix ) ) {
			return $ep_index_prefix;
		}

		$options = get_option( 'bw_elasticpress' );

		if ( ! isset( $options['ep_index_prefix'] ) ) {
			$options['ep_index_prefix'] = wp_generate_uuid4();

			update_option( 'bw_elasticpress', $options );
		}

		return $options['ep_index_prefix'];
	}
);

/**
 * Filter the ElasticPress Autosuggest options passed to the JS 'epas' object.
 */
add_filter(
	'ep_autosuggest_options',
	function ( $options ): array {
		// Enables passing the search term through the REST API to the Elasticsearch query outside of ElasticPress.io.
		$options['addSearchTermHeader'] = true;

		return $options;
	}
);

/**
 * Handle REST API autosuggest endpoint requests.
 *
 * Fires a request to Elasticsearch for the selected index using ElasticPress internals. This avoids CORS issues when
 * making the request directly via JS, and allows the use of ES_SHIELD credentials without exposing the username and
 * password via the Autosuggest endpoint URL.
 *
 * @param \WP_REST_Request $request The current REST request.
 * @return \WP_REST_Response The Elasticsearch query response in a WP REST API wrapper.
 */
function ep_autosuggest( \WP_REST_Request $request ): \WP_REST_Response {
	// Use the full Elasticsearch response, not the regular search response of WP items.
	add_filter( 'ep_es_query_results', __NAMESPACE__ . '\filter_query_results', 10, 2 );

	/**
	 * The query response, filtered by the plugin. False if there's an error.
	 *
	 * @see  Elasticsearch::query
	 * @uses \BigWing\ElasticPress\filter_query_results
	 *
	 * @var bool|array $response
	 */
	$response = Elasticsearch::factory()->query(
		get_posts_index_name(),
		'post',
		$request->get_json_params(),
		array(
			// Flag this as a search in the query by passing the search string.
			's' => sanitize_text_field( $request->get_header( 'ep_search_term' ) ),
		)
	);

	// Disable the filter from being run outside of our requests.
	remove_filter( 'ep_es_query_results', __NAMESPACE__ . '\filter_query_results' );

	/*
	 * If the query fails, then generate a response that avoids breaking the script.
	 * rest_ensure_response() will return 'false' right back, but that throws an error in the JS, which expects certain
	 * properties in the response. Generating an error and using a valid-but-not-200 (which is the default code) lets
	 * us safely let someone know that there was a problem without throwing script errors that may block other search
	 * functionality.
	 */
	if ( false === $response || ! is_array( $response ) ) {
		$error = new \WP_Error( 'es_query_error', __( 'There was an unknown error with the Elasticsearch search query.', 'bigwing-elasticpress' ) );
		return new \WP_REST_Response( $error, \WP_Http::IM_A_TEAPOT, $request->get_header( 'ep_search_term' ) );
	}

	return new \WP_REST_Response( $response );
}

/**
 * Filter the search results to use the raw response instead of the ElasticPress search results.
 *
 * EP Autosuggest expects the Elasticsearch data, but ElasticPress returns a subset of those results for normal queries.
 * This forces the raw response instead of the EP one so the Autosuggest JavaScript library can do its thing.
 *
 * @param array $results  Results from Elasticsearch query, modified by ElasticPress.
 * @param array $response Raw response from Elasticsearch.
 * @return array New results.
 */
function filter_query_results( array $results, array $response ): array {
	return $response;
}

/**
 * Get the ES index used for posts.
 *
 * @return string The index name for posts for the site.
 */
function get_posts_index_name(): string {
	return Indexables::factory()->get( 'post' )->get_index_name();
}

/**
 * Gets the full URL to the REST API ElasticPearch Autosuggest endpoint.
 *
 * @return string The sanitized endpoint URL.
 */
function get_autosuggest_endpoint(): string {
	return esc_url_raw( rest_url( BW_EP_REST_NAMESPACE . '/v1/autosuggest/' ) );
}

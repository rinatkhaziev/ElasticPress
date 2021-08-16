<?php
/**
 * Autosuggest feature
 *
 * phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
 *
 * @package elasticpress
 */

namespace ElasticPress\Feature\Autosuggest;

use ElasticPress\Feature as Feature;
use ElasticPress\Features as Features;
use ElasticPress\Utils as Utils;
use ElasticPress\FeatureRequirementsStatus as FeatureRequirementsStatus;
use ElasticPress\Indexables as Indexables;
use ElasticPress\Elasticsearch;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Autosuggest feature class
 */
class Autosuggest extends Feature {

	/**
	 * Autosuggest query generated by intercept_search_request
	 *
	 * @var array
	 */
	public $autosuggest_query = [];

	/**
	 * Initialize feature setting it's config
	 *
	 * @since  3.0
	 */
	public function __construct() {
		$this->slug = 'autosuggest';

		$this->title = esc_html__( 'Autosuggest', 'elasticpress' );

		$this->requires_install_reindex = true;

		$this->default_settings = [
			'endpoint_url'         => '',
			'autosuggest_selector' => '',
			'trigger_ga_event'     => false,
		];

		parent::__construct();
	}

	/**
	 * Output feature box summary
	 *
	 * @since 2.4
	 */
	public function output_feature_box_summary() {
		?>
		<p><?php esc_html_e( 'Suggest relevant content as text is entered into the search field.', 'elasticpress' ); ?></p>
		<?php
	}

	/**
	 * Output feature box long
	 *
	 * @since 2.4
	 */
	public function output_feature_box_long() {
		?>
		<p><?php esc_html_e( 'Input fields of type "search" or with the CSS class "search-field" or "ep-autosuggest" will be enhanced with autosuggest functionality. As text is entered into the search field, suggested content will appear below it, based on top search results for the text. Suggestions link directly to the content.', 'elasticpress' ); ?></p>
		<?php
	}

	/**
	 * Setup feature functionality
	 *
	 * @since  2.4
	 */
	public function setup() {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_filter( 'ep_post_mapping', [ $this, 'mapping' ] );
		add_filter( 'ep_post_sync_args', [ $this, 'filter_term_suggest' ], 10 );
		add_filter( 'ep_fuzziness_arg', [ $this, 'set_fuzziness' ], 10, 3 );
		add_filter( 'ep_weighted_query_for_post_type', [ $this, 'adjust_fuzzy_fields' ], 10, 3 );
		add_filter( 'ep_saved_weighting_configuration', [ $this, 'epio_send_autosuggest_public_request' ] );
		add_filter( 'wp', [ $this, 'epio_send_autosuggest_allowed' ] );
		add_filter( 'ep_pre_dashboard_index', [ $this, 'epio_send_autosuggest_public_request' ] );
		add_filter( 'ep_wp_cli_pre_index', [ $this, 'epio_send_autosuggest_public_request' ] );
		add_filter( 'debug_information', [ $this, 'epio_autosuggest_health_check_info' ] );

		add_action( 'ep_cli_after_set_search_algorithm_version', [ $this, 'delete_cached_query' ] );
		add_action( 'ep_wp_cli_after_index', [ $this, 'delete_cached_query' ] );
		add_action( 'ep_after_dashboard_index', [ $this, 'delete_cached_query' ] );
		add_action( 'ep_after_update_feature', [ $this, 'delete_cached_query' ] );
		add_action( 'ep_cli_after_clear_index', [ $this, 'delete_cached_query' ] );
	}

	/**
	 * Display decaying settings on dashboard.
	 *
	 * @since 2.4
	 */
	public function output_feature_box_settings() {
		$settings = $this->get_settings();

		if ( ! $settings ) {
			$settings = [];
		}

		$settings = wp_parse_args( $settings, $this->default_settings );

		?>
		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status"><label for="feature_autosuggest_selector"><?php esc_html_e( 'Autosuggest Selector', 'elasticpress' ); ?></label></div>
			<div class="input-wrap">
				<input value="<?php echo empty( $settings['autosuggest_selector'] ) ? '.ep-autosuggest' : esc_attr( $settings['autosuggest_selector'] ); ?>" type="text" data-field-name="autosuggest_selector" class="setting-field" id="feature_autosuggest_selector">
				<p class="field-description"><?php esc_html_e( 'Input additional selectors where you would like to include autosuggest separated by a comma. Example: .custom-selector, #custom-id, input[type="text"]', 'elasticpress' ); ?></p>
			</div>
		</div>

		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status"><?php esc_html_e( 'Google Analytics Events', 'elasticpress' ); ?></div>
			<div class="input-wrap">
				<label for="trigger_ga_event_enabled"><input name="trigger_ga_event" id="trigger_ga_event_enabled" data-field-name="trigger_ga_event" class="setting-field" <?php checked( (bool) $settings['trigger_ga_event'] ); ?> type="radio" value="1"><?php esc_html_e( 'Enabled', 'elasticpress' ); ?></label><br>
				<label for="trigger_ga_event_disabled"><input name="trigger_ga_event" id="trigger_ga_event_disabled" data-field-name="trigger_ga_event" class="setting-field" <?php checked( (bool) $settings['trigger_ga_event'], false ); ?> type="radio" value="0"><?php esc_html_e( 'Disabled', 'elasticpress' ); ?></label>
				<p class="field-description"><?php esc_html_e( 'When enabled, a gtag tracking event is fired when an autosuggest result is clicked.', 'elasticpress' ); ?></p>
			</div>
		</div>
		<?php

		if ( Utils\is_epio() ) {
			$this->epio_allowed_parameters();
			return;
		}

		$endpoint_url = ( defined( 'EP_AUTOSUGGEST_ENDPOINT' ) && EP_AUTOSUGGEST_ENDPOINT ) ? EP_AUTOSUGGEST_ENDPOINT : $settings['endpoint_url'];
		?>

		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status"><label for="feature_autosuggest_endpoint_url"><?php esc_html_e( 'Endpoint URL', 'elasticpress' ); ?></label></div>
			<div class="input-wrap">
				<input
			<?php
			if ( defined( 'EP_AUTOSUGGEST_ENDPOINT' ) && EP_AUTOSUGGEST_ENDPOINT ) :
				?>
					disabled<?php endif; ?> value="<?php echo esc_url( $endpoint_url ); ?>" type="text" data-field-name="endpoint_url" class="setting-field" id="feature_autosuggest_endpoint_url">
				<?php
				if ( defined( 'EP_AUTOSUGGEST_ENDPOINT' ) && EP_AUTOSUGGEST_ENDPOINT ) {
					?>
					<p class="field-description"><?php esc_html_e( 'Your autosuggest endpoint is set in wp-config.php', 'elasticpress' ); ?></p>
					<?php
				}
				?>
				<p class="field-description"><?php esc_html_e( 'This address will be exposed to the public.', 'elasticpress' ); ?></p>

			</div>
		</div>

			<?php
	}

	/**
	 * Add mapping for suggest fields
	 *
	 * @param  array $mapping ES mapping.
	 * @since  2.4
	 * @return array
	 */
	public function mapping( $mapping ) {
		$mapping['settings']['analysis']['analyzer']['edge_ngram_analyzer'] = array(
			'type'      => 'custom',
			'tokenizer' => 'standard',
			'filter'    => array(
				'lowercase',
				'edge_ngram',
			),
		);

		if ( version_compare( Elasticsearch::factory()->get_elasticsearch_version(), '7.0', '<' ) ) {
			$text_type = $mapping['mappings']['post']['properties']['post_content']['type'];

			$mapping['mappings']['post']['properties']['post_title']['fields']['suggest'] = array(
				'type'            => $text_type,
				'analyzer'        => 'edge_ngram_analyzer',
				'search_analyzer' => 'standard',
			);

			$mapping['mappings']['post']['properties']['term_suggest'] = array(
				'type'            => $text_type,
				'analyzer'        => 'edge_ngram_analyzer',
				'search_analyzer' => 'standard',
			);
		} else {
			$text_type = $mapping['mappings']['properties']['post_content']['type'];

			$mapping['mappings']['properties']['post_title']['fields']['suggest'] = array(
				'type'            => $text_type,
				'analyzer'        => 'edge_ngram_analyzer',
				'search_analyzer' => 'standard',
			);

			$mapping['mappings']['properties']['term_suggest'] = array(
				'type'            => $text_type,
				'analyzer'        => 'edge_ngram_analyzer',
				'search_analyzer' => 'standard',
			);
		}

		return $mapping;
	}

	/**
	 * Ensure both search and autosuggest use fuziness with type auto
	 *
	 * @param integer $fuzziness Fuzziness
	 * @param array   $search_fields Search Fields
	 * @param array   $args Array of ES args
	 * @return array
	 */
	public function set_fuzziness( $fuzziness, $search_fields, $args ) {
		if ( Utils\is_integrated_request( $this->slug, [ 'public' ] ) && ! empty( $args['s'] ) ) {
			return 'auto';
		}
		return $fuzziness;
	}

	/**
	 * Handle ngram search fields for fuzziness fields
	 *
	 * @param array  $query ES Query arguments
	 * @param string $post_type Post Type
	 * @param array  $args WP_Query args
	 * @return array $query adjusted ES Query arguments
	 */
	public function adjust_fuzzy_fields( $query, $post_type, $args ) {
		if ( Utils\is_integrated_request( $this->slug, [ 'public' ] ) && ! empty( $args['s'] ) ) {
			/**
			 * Filter autosuggest ngram fields
			 *
			 * @hook ep_autosuggest_ngram_fields
			 * @param  {array} $fields Fields available to ngram
			 * @return  {array} New fields array
			 */
			$ngram_fields = apply_filters(
				'ep_autosuggest_ngram_fields',
				[
					'post_title' => 'post_title.suggest',
				]
			);

			if ( isset( $query['bool'] ) && isset( $query['bool']['must'] ) ) {
				foreach ( $query['bool']['must'] as $q_index => $must_query ) {
					if ( isset( $must_query['bool'] ) && isset( $must_query['bool']['should'] ) ) {
						foreach ( $must_query['bool']['should'] as $index => $current_bool_should ) {
							if (
								isset( $current_bool_should['multi_match'] ) &&
								isset( $current_bool_should['multi_match']['fields'] ) &&
								(
									(
										isset( $current_bool_should['multi_match']['fuzziness'] ) &&
										0 !== $current_bool_should['multi_match']['fuzziness']
									) ||
									(
										isset( $current_bool_should['multi_match']['slop'] ) &&
										0 !== $current_bool_should['multi_match']['slop']
									)
								)
							) {
								foreach ( $current_bool_should['multi_match']['fields'] as $key => $field ) {
									foreach ( $ngram_fields as $plain_field => $ngram_field ) {
										if ( preg_match( '/^(' . $plain_field . ')(\^(\d+))?$/', $field, $match ) ) {
											if ( isset( $match[3] ) && $match[3] > 1 ) {
												$weight = $match[3] - 1;
											} else {
												$weight = 1;
											}
											$query['bool']['must'][ $q_index ]['bool']['should'][ $index ]['multi_match']['fields'][] = $ngram_field . '^' . $weight;
										}
									}
								}
							}
						}
					}
				}
			}
		}
		return $query;
	}

	/**
	 * Add term suggestions to be indexed
	 *
	 * @param array $post_args Array of ES args.
	 * @since  2.4
	 * @return array
	 */
	public function filter_term_suggest( $post_args ) {
		$suggest = [];

		if ( ! empty( $post_args['terms'] ) ) {
			foreach ( $post_args['terms'] as $taxonomy ) {
				foreach ( $taxonomy as $term ) {
					$suggest[] = $term['name'];
				}
			}
		}

		if ( ! empty( $suggest ) ) {
			$post_args['term_suggest'] = $suggest;
		}

		return $post_args;
	}

	/**
	 * Enqueue our autosuggest script
	 *
	 * @since  2.4
	 */
	public function enqueue_scripts() {
		$host         = Utils\get_host();
		$endpoint_url = false;
		$settings     = $this->get_settings();

		if ( defined( 'EP_AUTOSUGGEST_ENDPOINT' ) && EP_AUTOSUGGEST_ENDPOINT ) {
			$endpoint_url = EP_AUTOSUGGEST_ENDPOINT;
		} else {
			if ( Utils\is_epio() ) {
				$endpoint_url = trailingslashit( $host ) . Indexables::factory()->get( 'post' )->get_index_name() . '/autosuggest';
			} else {
				if ( ! $settings ) {
					$settings = [];
				}

				$settings = wp_parse_args( $settings, $this->default_settings );

				if ( empty( $settings['endpoint_url'] ) ) {
					return;
				}

				$endpoint_url = $settings['endpoint_url'];
			}
		}

		wp_enqueue_script(
			'elasticpress-autosuggest',
			EP_URL . 'dist/js/autosuggest-script.min.js',
			[],
			EP_VERSION,
			true
		);

		wp_enqueue_style(
			'elasticpress-autosuggest',
			EP_URL . 'dist/css/autosuggest-styles.min.css',
			[],
			EP_VERSION
		);

		/** Features Class @var Features $features */
		$features = Features::factory();

		/** Search Feature @var Feature\Search\Search $search */
		$search = $features->get_registered_feature( 'search' );

		$post_types  = $search->get_searchable_post_types();
		$post_status = get_post_stati(
			[
				'public'              => true,
				'exclude_from_search' => false,
			]
		);

		$query = $this->generate_search_query();

		$epas_options = [
			'query'               => $query['body'],
			'placeholder'         => $query['placeholder'],
			'endpointUrl'         => esc_url( untrailingslashit( $endpoint_url ) ),
			'selector'            => empty( $settings['autosuggest_selector'] ) ? 'ep-autosuggest' : esc_html( $settings['autosuggest_selector'] ),
			'action'              => 'navigate',
			'mimeTypes'           => [],
			/**
			 * Filter autosuggest HTTP headers
			 *
			 * @hook ep_autosuggest_http_headers
			 * @param  {array} $headers Autosuggest HTTP headers in name => value format
			 * @return  {array} HTTP headers
			 */
			'http_headers'        => apply_filters( 'ep_autosuggest_http_headers', [] ),
			'triggerAnalytics'    => ! empty( $settings['trigger_ga_event'] ),
			'addSearchTermHeader' => false,
		];

		if ( Utils\is_epio() ) {
			$epas_options['addSearchTermHeader'] = true;
		}

		$search_settings = $search->get_settings();

		if ( ! $search_settings ) {
			$search_settings = [];
		}

		$search_settings = wp_parse_args( $search_settings, $search->default_settings );

		if ( ! empty( $search_settings ) && $search_settings['highlight_enabled'] ) {
			$epas_options['highlightingEnabled'] = true;
			$epas_options['highlightingTag']     = apply_filters( 'ep_highlighting_tag', $search_settings['highlight_tag'] );
			$epas_options['highlightingClass']   = apply_filters( 'ep_highlighting_class', 'ep-highlight' );
		}

		/**
		 * Output variables to use in Javascript
		 * index: the Elasticsearch index name
		 * endpointUrl:  the Elasticsearch autosuggest endpoint url
		 * postType: which post types to use for suggestions
		 * action: the action to take when selecting an item. Possible values are "search" and "navigate".
		 */
		wp_localize_script(
			'elasticpress-autosuggest',
			'epas',
			/**
			 * Filter autosuggest JavaScript options
			 *
			 * @hook ep_autosuggest_options
			 * @param  {array} $options Autosuggest options to be localized
			 * @return  {array} New options
			 */
			apply_filters(
				'ep_autosuggest_options',
				$epas_options
			)
		);
	}

	/**
	 * Build a default search request to pass to the autosuggest javascript.
	 * The request will include a placeholder that can then be replaced.
	 *
	 * @return array Generated ElasticSearch request array( 'placeholder'=> placeholderstring, 'body' => request body )
	 */
	public function generate_search_query() {

		/**
		 * Filter autosuggest query placeholder
		 *
		 * @hook ep_autosuggest_query_placeholder
		 * @param  {string} $placeholder Autosuggest placeholder to be replaced later
		 * @return  {string} New placeholder
		 */
		$placeholder = apply_filters( 'ep_autosuggest_query_placeholder', 'ep_autosuggest_placeholder' );

		/** Features Class @var Features $features */
		$features = Features::factory();

		$post_type = $features->get_registered_feature( 'search' )->get_searchable_post_types();

		/**
		 * Filter post types available to autosuggest
		 *
		 * @hook ep_term_suggest_post_type
		 * @param  {array} $post_types Post types
		 * @return  {array} New post types
		 */
		$post_type = apply_filters( 'ep_term_suggest_post_type', array_values( $post_type ) );

		$post_status = get_post_stati(
			[
				'public'              => true,
				'exclude_from_search' => false,
			]
		);

		/**
		 * Filter post statuses available to autosuggest
		 *
		 * @hook ep_term_suggest_post_status
		 * @param  {array} $post_statuses Post statuses
		 * @return  {array} New post statuses
		 */
		$post_status = apply_filters( 'ep_term_suggest_post_status', array_values( $post_status ) );

		add_filter( 'ep_intercept_remote_request', '__return_true' );
		add_filter( 'ep_weighting_configuration', [ $features->get_registered_feature( $this->slug ), 'apply_autosuggest_weighting' ], 10, 1 );

		add_filter( 'ep_do_intercept_request', [ $features->get_registered_feature( $this->slug ), 'intercept_search_request' ], 10, 4 );

		add_filter( 'posts_pre_query', [ $features->get_registered_feature( $this->slug ), 'return_empty_posts' ], 100, 1 ); // after ES Query to ensure we are not falling back to DB in any case

		$search = new \WP_Query(
			[
				'post_type'    => $post_type,
				'post_status'  => $post_status,
				's'            => $placeholder,
				'ep_integrate' => true,
			]
		);

		remove_filter( 'posts_pre_query', [ $features->get_registered_feature( $this->slug ), 'return_empty_posts' ], 100 );

		remove_filter( 'ep_do_intercept_request', [ $features->get_registered_feature( $this->slug ), 'intercept_search_request' ] );

		remove_filter( 'ep_weighting_configuration', [ $features->get_registered_feature( $this->slug ), 'apply_autosuggest_weighting' ] );

		remove_filter( 'ep_intercept_remote_request', '__return_true' );

		return [
			'body'        => $this->autosuggest_query,
			'placeholder' => $placeholder,
		];
	}

	/**
	 * Ensure we do not fallback to WPDB query for this request
	 *
	 * @param array $posts array of post objects
	 * @return array $posts
	 */
	public function return_empty_posts( $posts = [] ) {
		return [];
	}

	/**
	 * Allow applying custom weighting configuration for autosuggest
	 *
	 * @param array $config current configuration
	 * @return array $config desired configuration
	 */
	public function apply_autosuggest_weighting( $config = [] ) {
		/**
		 * Filter autosuggest weighting configuration
		 *
		 * @hook ep_weighting_configuration_for_autosuggest
		 * @param  {array} $config Configuration
		 * @return  {array} New config
		 */
		$config = apply_filters( 'ep_weighting_configuration_for_autosuggest', $config );
		return $config;
	}

	/**
	 * Store intercepted request value and return (cached) request result
	 *
	 * @param object $response Response
	 * @param array  $query Query
	 * @param array  $args WP_Query Argument array
	 * @param int    $failures Count of failures in request loop
	 * @return object $response Response
	 */
	public function intercept_search_request( $response, $query = [], $args = [], $failures = 0 ) {
		$this->autosuggest_query = $query['args']['body'];

		// Let's make sure we also fire off the dummy request if settings have changed.
		// But only fire this if we have object caching as otherwise this comes with a performance penalty.
		// If we do not have object caching we cache only one value for 5 minutes in a transient.
		if ( wp_using_ext_object_cache() ) {
			$cache_key = md5( wp_json_encode( $query['url'] ) . wp_json_encode( $args ) );
			$request   = wp_cache_get( $cache_key, 'ep_autosuggest' );
			if ( false === $request ) {
				$request = wp_remote_request( $query['url'], $args );
				if ( isset( $request->http_response ) && isset( $request->http_response->body ) ) {
					$request->http_response->body = '';
				}
				wp_cache_set( $cache_key, $request, 'ep_autosuggest' );
			}
		} else {
			$cache_key = 'ep_autosuggest_query_request_cache';
			$request   = get_transient( $cache_key );
			if ( false === $request ) {
				$request = wp_remote_request( $query['url'], $args );
				if ( isset( $request->http_response ) && isset( $request->http_response->body ) ) {
					$request->http_response->body = '';
				}
				set_transient( $cache_key, $request, 5 * MINUTE_IN_SECONDS );
			}
		}

		return $request;
	}

	/**
	 * Delete the cached query for autosuggest.
	 *
	 * @since 3.5.5
	 */
	public function delete_cached_query() {
		global $wp_object_cache;
		if ( wp_using_ext_object_cache() ) {
			// Delete the entire group.
			unset( $wp_object_cache->cache['ep_autosuggest'] );
		} else {
			delete_transient( 'ep_autosuggest_query_request_cache' );
		}
	}

	/**
	 * Tell user whether requirements for feature are met or not.
	 *
	 * @return array $status Status array
	 * @since 2.4
	 */
	public function requirements_status() {
		$status = new FeatureRequirementsStatus( 0 );

		$status->message = [];

		$status->message[] = esc_html__( 'This feature modifies the site’s default user experience by presenting a list of suggestions below detected search fields as text is entered into the field.', 'elasticpress' );

		if ( ! Utils\is_epio() ) {
			$status->code      = 1;
			$status->message[] = wp_kses_post( __( "You aren't using <a href='https://elasticpress.io'>ElasticPress.io</a> so we can't be sure your host is properly secured. Autosuggest requires a publicly accessible endpoint, which can expose private content and allow data modification if improperly configured.", 'elasticpress' ) );
		}

		return $status;
	}

	/**
	 * Do a non-blocking search query to force the autosuggest hash to update.
	 *
	 * This request has to happen in a public environment, so all code testing if `is_admin()`
	 * are properly executed.
	 *
	 * @param bool $blocking If the request should block the execution or not.
	 */
	public function epio_send_autosuggest_public_request( $blocking = false ) {
		if ( ! Utils\is_epio() ) {
			return;
		}

		$url = add_query_arg(
			[
				's'                       => 'search test',
				'ep_epio_set_autosuggest' => 1,
				'ep_epio_nonce'           => wp_create_nonce( 'ep-epio-set-autosuggest' ),
				'nocache'                 => time(), // Here just to avoid the request hitting a CDN.
			],
			home_url( '/' )
		);

		// Pass the same cookies, so the same authenticated user is used (and we can check the nonce).
		$cookies = [];
		foreach ( $_COOKIE as $name => $value ) {
			$cookies[] = new \WP_Http_Cookie(
				[
					'name'  => $name,
					'value' => $value,
				]
			);
		}

		wp_remote_get(
			$url,
			[
				'cookies'  => $cookies,
				'blocking' => (bool) $blocking,
			]
		);
	}

	/**
	 * Send the allowed parameters for autosuggest to ElasticPress.io.
	 */
	public function epio_send_autosuggest_allowed() {
		if ( empty( $_REQUEST['ep_epio_nonce'] ) || ! wp_verify_nonce( $_REQUEST['ep_epio_nonce'], 'ep-epio-set-autosuggest' ) ) {
			return;
		}
		if ( empty( $_GET['ep_epio_set_autosuggest'] ) ) {
			return;
		}

		/**
		 * Fires before the request is sent to EP.io to set Autosuggest allowed values.
		 *
		 * @hook ep_epio_pre_send_autosuggest_allowed
		 * @since  3.5.x
		 */
		do_action( 'ep_epio_pre_send_autosuggest_allowed' );

		/**
		 * The same ES query sent by autosuggest.
		 *
		 * Sometimes it'll be a string, sometimes it'll be already an array.
		 */
		$es_search_query = $this->generate_search_query()['body'];
		$es_search_query = ( is_array( $es_search_query ) ) ? $es_search_query : json_decode( $es_search_query, true );

		/**
		 * Filter autosuggest ES query
		 *
		 * @since  3.5.x
		 * @hook ep_epio_autosuggest_es_query
		 * @param  {array} The ES Query.
		 */
		$es_search_query = apply_filters( 'ep_epio_autosuggest_es_query', $es_search_query );

		/**
		 * Here is a chance to short-circuit the execution. Also, during the sync
		 * the query will be empty anyway.
		 */
		if ( empty( $es_search_query ) ) {
			return;
		}

		$index = Indexables::factory()->get( 'post' )->get_index_name();

		add_filter( 'ep_format_request_headers', [ $this, 'add_ep_set_autosuggest_header' ] );

		Elasticsearch::factory()->query( $index, 'post', $es_search_query, [] );

		remove_filter( 'ep_format_request_headers', [ $this, 'add_ep_set_autosuggest_header' ] );

		/**
		 * Fires after the request is sent to EP.io to set Autosuggest allowed values.
		 *
		 * @hook ep_epio_sent_autosuggest_allowed
		 * @since  3.5.x
		 */
		do_action( 'ep_epio_sent_autosuggest_allowed' );
	}

	/**
	 * Set a header so EP.io servers know this request contains the values
	 * that should be stored as allowed.
	 *
	 * @since 3.5.x
	 * @param array $headers The Request Headers.
	 * @return array
	 */
	public function add_ep_set_autosuggest_header( $headers ) {
		$headers['EP-Set-Autosuggest'] = true;
		return $headers;
	}

	/**
	 * Retrieve the allowed parameters for autosuggest from ElasticPress.io.
	 *
	 * @return array
	 */
	public function epio_retrieve_autosuggest_allowed() {
		$response = Elasticsearch::factory()->remote_request(
			Indexables::factory()->get( 'post' )->get_index_name() . '/get-autosuggest-allowed'
		);

		$body = wp_remote_retrieve_body( $response, true );
		return json_decode( $body, true );
	}

	/**
	 * Output the current allowed parameters for autosuggest stored in ElasticPress.io.
	 */
	public function epio_allowed_parameters() {
		global $wp_version;

		$allowed_params = $this->epio_autosuggest_set_and_get();
		if ( empty( $allowed_params ) ) {
			return;
		}
		?>
		<div class="field js-toggle-feature" data-feature="<?php echo esc_attr( $this->slug ); ?>">
			<div class="field-name status"><?php esc_html_e( 'Connection', 'elasticpress' ); ?></div>
			<div class="input-wrap">
				<?php
				$epio_link                = '';
				$epio_autosuggest_kb_link = 'https://elasticpress.zendesk.com/hc/en-us/articles/360055402791';

				// If WordPress 5.2+, show debug in Health Check. Otherwise, show it if WP_DEBUG is enabled.
				if ( version_compare( $wp_version, '5.2', '>=' ) || 0 === stripos( $wp_version, '5.2-' ) ) {
					printf(
						/* translators: 1: <a> tag (ElasticPress.io); 2. </a>; 3: <a> tag (KB article); 4. </a>; 5: <a> tag (Site Health Debug Section); 6. </a>; */
						esc_html__( 'You are directly connected to %1$sElasticPress.io%2$s, ensuring the most performant Autosuggest experience. %3$sLearn more about what this means%4$s or %5$sclick here for debug information%6$s.', 'elasticpress' ),
						'<a href="' . esc_url( $epio_link ) . '">',
						'</a>',
						'<a href="' . esc_url( $epio_autosuggest_kb_link ) . '">',
						'</a>',
						'<a href="' . esc_url( admin_url( 'site-health.php?tab=debug' ) ) . '">',
						'</a>'
					);
				} else {
					printf(
						/* translators: 1: <a> tag (ElasticPress.io); 2. </a>; 3: <a> tag (KB article); 4. </a>; */
						esc_html__( 'You are directly connected to %1$sElasticPress.io%2$s, ensuring the most performant Autosuggest experience. %1$sLearn more about what this means%2$s.', 'elasticpress' ),
						'<a href="' . esc_url( $epio_link ) . '">',
						'</a>',
						'<a href="' . esc_url( $epio_autosuggest_kb_link ) . '">',
						'</a>'
					);

					if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'WP_EP_DEBUG' ) && WP_EP_DEBUG ) ) {
						?>
						<p><?php esc_html_e( 'These are the allowed parameters stored in ElasticPress.io', 'elasticpress' ); ?></p>
						<?php
						$allowed_params = wp_parse_args(
							$allowed_params,
							[
								'postTypes'    => [],
								'postStatus'   => [],
								'searchFields' => [],
								'returnFields' => '',
							]
						);

						$fields = [
							wp_sprintf( esc_html__( 'Post Types: %l', 'elasticpress' ), $allowed_params['postTypes'] ),
							wp_sprintf( esc_html__( 'Post Status: %l', 'elasticpress' ), $allowed_params['postStatus'] ),
							wp_sprintf( esc_html__( 'Search Fields: %l', 'elasticpress' ), $allowed_params['searchFields'] ),
							wp_sprintf( esc_html__( 'Returned Fields: %s', 'elasticpress' ), var_export( $allowed_params['returnFields'], true ) ), // phpcs:ignore
						];

						echo implode( '<br>', $fields ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				}
				?>
				<p>
					<img width="150" src="<?php echo esc_url( plugins_url( '/images/logo-elasticpress-io.svg', EP_FILE ) ); ?>">
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Try to get the allowed parameters. If they are not set, set it and try to get them again.
	 *
	 * @since 3.5.x
	 * @return array
	 */
	public function epio_autosuggest_set_and_get() {
		$allowed_params = [];
		$errors_count   = 1;
		for ( $i = 0; $i <= $errors_count; $i++ ) {
			$allowed_params = $this->epio_retrieve_autosuggest_allowed();

			if ( is_wp_error( $allowed_params ) || ( isset( $allowed_params['status'] ) && 200 !== $allowed_params['status'] ) ) {
				$allowed_params = [];
				break;
			}

			if ( empty( $allowed_params ) ) {
				$this->epio_send_autosuggest_public_request( true );
			}
		}

		return $allowed_params;
	}

	/**
	 * Add Autosuggest info for EP.io Users in Health Check Info Screen.
	 *
	 * @since 3.5.x
	 * @param array $debug_info Debug Info set so far.
	 * @return array
	 */
	public function epio_autosuggest_health_check_info( $debug_info ) {
		if ( ! Utils\is_epio() ) {
			return $debug_info;
		}

		$debug_info['epio_autosuggest'] = array(
			'label'  => esc_html__( 'ElasticPress.io - Autosuggest', 'elasticpress' ),
			'fields' => [],
		);

		$allowed_params = $this->epio_autosuggest_set_and_get();

		if ( empty( $allowed_params ) ) {
			return $debug_info;
		}

		$allowed_params = wp_parse_args(
			$allowed_params,
			[
				'postTypes'    => [],
				'postStatus'   => [],
				'searchFields' => [],
				'returnFields' => '',
			]
		);

		$fields = [
			'Post Types'      => wp_sprintf( esc_html__( '%l', 'elasticpress' ), $allowed_params['postTypes'] ),
			'Post Status'     => wp_sprintf( esc_html__( '%l', 'elasticpress' ), $allowed_params['postStatus'] ),
			'Search Fields'   => wp_sprintf( esc_html__( '%l', 'elasticpress' ), $allowed_params['searchFields'] ),
			'Returned Fields' => wp_sprintf( esc_html( var_export( $allowed_params['returnFields'], true ) ) ),
		];

		foreach ( $fields as $label => $value ) {
			$debug_info['epio_autosuggest']['fields'][ sanitize_title( $label ) ] = [
				'label'   => $label,
				'value'   => $value,
				'private' => true,
			];
		}

		return $debug_info;
	}
}

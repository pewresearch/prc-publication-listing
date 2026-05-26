<?php
/**
 * Publication Listing
 *
 * @package PRC\Platform\Publication_Listing
 */

namespace PRC\Platform\Publication_Listing;

use WP_Error;

/**
 * Publication Listing Query
 *
 * The "Publication Listing" query is the default query handler for the core/query block
 * effectively, this is a WP_Query manager for the frontend. Ensuring
 * the correct post types, tax_query, and meta_query are applied.
 *
 * This class also provides a simple interface and taxonomy for controlling post visibility within the publication listing. This uses a taxonomy to tie some terms like ('hidden-on-index', 'hidden-on-search') to hide the post in a tax_query applied to the filtered args.
 *
 * @package PRC\Platform\Publication_Listing
 */
class Query {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize the class and set its properties.
		// Empty constructor.
	}

	/**
	 * Defaults the tax query arguments to OR instead of AND for relational match.
	 * We do this because we want most query blocks to be inclusive, not exclusive.
	 * Only when filtering by facets do we want to be exclusive.
	 *
	 * @hook block_type_metadata
	 * @param array $metadata Metadata.
	 * @return array
	 */
	public function default_tax_query_to_or( $metadata ) {
		if ( 'core/query' !== $metadata['name'] ) {
			return $metadata;
		}

		if ( ! array_key_exists( 'taxQuery', $metadata['attributes'] ) ) {
			$metadata['attributes']['taxQuery'] = array(
				'type'    => 'object',
				'default' => array(
					'relation' => 'OR',
					'data'     => array(),
				),
			);
		}

		return $metadata;
	}

	/**
	 * Register additional context for core/query blocks like prc-platform/block-area-context.
	 * This also addds postType and postId, useful for querying child objects.
	 *
	 * @hook block_type_metadata_settings 100, 2
	 * @param array $settings Settings.
	 * @param array $metadata Metadata.
	 * @return array
	 */
	public function update_context( array $settings, array $metadata ) {
		if ( 'core/query' === $metadata['name'] ) {
			$settings['uses_context'] = array_merge(
				array_key_exists( 'uses_context', $settings ) ? $settings['uses_context'] : array(),
				array(
					'prc-platform/block-area-context',
					'postId',
					'postType',
				)
			);
		}
		return $settings;
	}

	/**
	 * Register URL query var to show child posts in a publication listing query.
	 *
	 * @hook query_vars
	 *
	 * @param mixed $query_vars The query vars.
	 * @return mixed
	 */
	public function register_query_var( $query_vars ) {
		$query_vars[] = 'showChildPosts';
		return $query_vars;
	}

	/**
	 * Get the default filtered query args for a publication listing query.
	 *
	 * @uses prc_platform_pub_listing_default_args
	 *
	 * @param array $query_args Query args.
	 * @param mixed $query Query.
	 * @return array
	 */
	public static function get_filtered_query_args( $query_args, $query ) {
		if ( ! is_array( $query_args ) ) {
			$query_args = array();
		}
		$is_searching = array_key_exists( 's', $query_args ) && ! empty( $query_args['s'] );

		$show_child_posts = get_query_var( 'showChildPosts', false );
		$show_child_posts = rest_sanitize_boolean( $show_child_posts );
		// On non search pages, hide child posts, so long as the show child posts query var is not present and/or not true.
		if ( ! $is_searching && false === $show_child_posts ) {
			$query_args['post_parent'] = 0;
		}

		// Get post types that have declared support for publication listing.
		$supported_post_types              = self::get_enabled_post_types();
		$post_types                        = $query_args['post_type'] ?? array();
		$post_types                        = is_array( $post_types ) ? $post_types : array();
		$query_args['post_type']           = array_values( array_unique( array_merge( $post_types, $supported_post_types ) ) );
		$query_args['ignore_sticky_posts'] = true;

		$post_visibility = array( 'hidden-on-index' );
		if ( $is_searching ) {
			$post_visibility = array( 'hidden-on-search' );
		}

		$existing_tax_query = $query_args['tax_query'] ?? array();
		$has_visibility     = false;
		foreach ( $existing_tax_query as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			if (
				( isset( $clause['taxonomy'] ) && '_post_visibility' === $clause['taxonomy'] ) ||
				( isset( $clause[0]['taxonomy'] ) && '_post_visibility' === $clause[0]['taxonomy'] )
			) {
				$has_visibility = true;
				break;
			}
		}

		if ( ! $has_visibility ) {
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$query_args['tax_query'] = array_merge(
				$existing_tax_query,
				array(
					array(
						'relation' => 'OR',
						array(
							'taxonomy' => '_post_visibility',
							'field'    => 'slug',
							'terms'    => $post_visibility,
							'operator' => 'NOT IN',
						),
					),
				)
			);
		}

		// Enforce only published posts, this also helps enhance query performance.
		$query_args['post_status'] = 'publish';

		$query_args = apply_filters(
			'prc_platform_pub_listing_default_args',
			$query_args,
			$query
		);

		// On post type archives we want to respect the post type.
		if ( is_post_type_archive() ) {
			$post_type               = get_post_type();
			$query_args['post_type'] = array( $post_type );
		}

		return $query_args;
	}

	/**
	 * Get enabled post types.
	 *
	 * Returns all post types that have declared support for 'prc-publication-listing'.
	 *
	 * @return array
	 */
	public static function get_enabled_post_types() {
		return get_post_types_by_support( 'prc-publication-listing' );
	}

	/**
	 * Include all publication-listing-enabled post types in the main RSS/Atom feed.
	 *
	 * @hook pre_get_posts
	 *
	 * @param \WP_Query $query The query object.
	 * @return void
	 */
	public function include_in_main_feed( $query ) {
		if ( ! $query->is_feed() || $query->is_comment_feed() ) {
			return;
		}
		if ( ! $query->is_main_query() ) {
			return;
		}

		$enabled = self::get_enabled_post_types();
		if ( empty( $enabled ) ) {
			return;
		}

		$current = (array) $query->get( 'post_type' );
		if ( empty( $current ) ) {
			$current = array( 'post' );
		}

		$query->set(
			'post_type',
			array_values( array_unique( array_merge( $current, $enabled ) ) )
		);
	}

	/**
	 * Register post visibility taxonomy.
	 */
	public function register_post_visibility_taxonomy() {
		register_taxonomy(
			'_post_visibility',
			self::get_enabled_post_types(),
			array(
				'public'             => true,
				'publicly_queryable' => true,
				'label'              => 'Post Visibility',
				'hierarchical'       => true,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'show_in_nav_menus'  => false,
				'show_admin_column'  => true,
				'show_in_rest'       => true,
			)
		);
	}

	/**
	 * This filter will determine if we are in a "publication listing" context and if so, will set a flag, early on $query. This flag, `isPubListingQuery`, will be used later in other pre_get_posts filters to determine if we should be modifying the query.
	 *
	 * @hook pre_get_posts
	 * @param mixed $query Query.
	 * @return void
	 */
	public function init_pub_listing__wp_query( $query ) {
		// If we are not on the primary site, then we don't need to modify the query. Exit early.
		if ( PRC_PRIMARY_SITE_ID !== get_current_blog_id() ) {
			return;
		}
		// If we are in the admin, then we don't need to modify the query. Exit early.
		if ( is_admin() ) {
			return;
		}
		// If the query is empty, then we don't need to modify the query. Exit early.
		if ( empty( $query->query ) ) {
			return;
		}
		// If the query is not the main query, then we don't need to modify the query. Exit early.
		// We only want to modify the query that we would classify as the "loop" in WordPress parlance.
		if ( ! $query->is_main_query() ) {
			return;
		}

		// Sitemap requests resolve to is_home() but should not run pub listing logic.
		if ( get_query_var( 'sitemap' ) || get_query_var( 'sitemap-type' ) ) {
			return;
		}

		// Specific conditions that we do not want to modify the query and want to bail early.
		$taxonomies_to_exclude = $query->is_tax(
			array(
				'ngl_newsletter_cat',
				'areas-of-expertise',
				'decoded-category',
			)
		);
		if ( $taxonomies_to_exclude ) {
			return;
		}

		$is_pub_listing_query = false;

		// If we are on "home" i.e. the "blog" page or in our case "Publications" page,
		// then we are in a publication listing context. This is the primary way to access a "Pub Listing".
		if ( $query->is_home() ) {
			$is_pub_listing_query = true;
		}
		// If we're on a general archive page and not a specific post type archive then we should be in a publication listing context.
		if ( $query->is_archive() && ! $query->is_post_type_archive() ) {
			$is_pub_listing_query = true;
		}
		// If we're on a search page, we should also be in a publication listing context.
		if ( $query->is_search() ) {
			$is_pub_listing_query = true;
		}

		if ( true === $is_pub_listing_query ) {
			$query->set( 'isPubListingQuery', true );
		}
	}

	/**
	 * Hook the publication listing args into the WP_Query.
	 * This is the main filter for the publication listing especially on:
	 * /publications
	 * /search
	 * /topic/...
	 *
	 * @hook pre_get_posts
	 *
	 * @param mixed $query WP_Query.
	 */
	public function hook_pub_listing_args_into__wp_query( $query ) {
		if ( true === $query->get( 'isPubListingQuery' ) ) {
			$query_args = $query->query_vars;
			$args       = self::get_filtered_query_args( $query_args, $query );
			// loop through the filtered $args and set the args on the query.
			foreach ( $args as $key => $value ) {
				$query->set( $key, $value );
			}
		}
	}

	/**
	 * This happens early in the block rendering process,
	 * hooking onto the short-circuit filter so that we can add new filters scoped to just this namespace.
	 *
	 * This hooks pub listing query args into core/query blocks that are not inherited.
	 * For inherited blocks, the hook_pub_listing_args_into__wp_query will be used.
	 *
	 * @hook pre_render_block
	 * @param mixed $pre_render Pre render.
	 * @param mixed $parsed_block Parsed block.
	 * @param mixed $parent_block Parent block.
	 * @return mixed
	 */
	public function hook_pub_listing_args_into__core_query( $pre_render, $parsed_block, $parent_block ) {
		static $filter_added = false;

		if ( 'core/query' !== $parsed_block['blockName'] ) {
			return $pre_render;
		}

		$attributes = $parsed_block['attrs'] ?? array();

		// Check if the block has a namespace attribute, if not, return early.
		if ( ! array_key_exists( 'namespace', $attributes ) ) {
			return $pre_render;
		}

		// Check if the namespace is prc-block/pub-listing-query, if not, return early.
		if ( 'prc-block/pub-listing-query' !== $attributes['namespace'] ) {
			return $pre_render;
		}

		if ( $filter_added ) {
			return $pre_render;
		}
		$filter_added = true;

		add_filter(
			'query_loop_block_query_vars',
			function ( $query, $block ) {
				$block_query = $block->context['query'] ?? array();
				if ( empty( $block_query['isPubListingQuery'] ) ) {
					return $query;
				}
				return self::get_filtered_query_args( $query, null );
			},
			999,
			2
		);

		return $pre_render;
	}
	/**
	 *
	 * Sets starting default appropriate post_status arguments to restful queries.
	 *
	 * @hook rest_post_query
	 *
	 * @param mixed $args Args.
	 * @param mixed $request Request.
	 * @return mixed The args.
	 */
	public function hook_pub_listing_args_into__rest_query( $args, $request ) {
		if ( $request->get_param( 'isPubListingQuery' ) ) {
			$args = self::get_filtered_query_args( $args, null );
		}
		return $args;
	}
}

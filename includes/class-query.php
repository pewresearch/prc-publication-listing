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
			$post_visibility = self::get_visibility_terms( $query_args, $query );
			if ( ! empty( $post_visibility ) ) {
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
		}

		// Enforce only published posts, this also helps enhance query performance.
		$query_args['post_status'] = 'publish';

		$query_args = apply_filters(
			'prc_platform_pub_listing_default_args',
			$query_args,
			$query
		);

		// On post type archives we want to respect the post type.
		// Use the query's post_type — get_post_type() reads the global $post,
		// which is often unset/stale during pre_get_posts and would clobber the archive scope.
		if ( $query instanceof \WP_Query && $query->is_post_type_archive() ) {
			$post_type = $query->get( 'post_type' );
			if ( is_array( $post_type ) ) {
				$post_type = reset( $post_type );
			}
			if ( is_string( $post_type ) && '' !== $post_type ) {
				$query_args['post_type'] = array( $post_type );
			}
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
	 * Known `_post_visibility` term slugs that can be used as defaults.
	 *
	 * @return string[]
	 */
	public static function get_supported_visibility_slugs(): array {
		return array( 'hidden-on-index', 'hidden-on-search' );
	}

	/**
	 * Visibility slugs to exclude from a listing query.
	 *
	 * An empty list means do not add a default `_post_visibility` exclusion.
	 *
	 * @param array $args  Query args.
	 * @param mixed $query Query object or null.
	 * @return string[]
	 */
	public static function get_visibility_terms( array $args, $query ): array {
		$is_search = array_key_exists( 's', $args ) && ! empty( $args['s'] );
		if ( ! $is_search && $query instanceof \WP_Query && $query->is_search() ) {
			$is_search = true;
		}

		$terms = $is_search ? array( 'hidden-on-search' ) : array( 'hidden-on-index' );

		/**
		 * Filter the `_post_visibility` slugs a listing query excludes.
		 *
		 * Return an empty array to skip the default exclusion.
		 *
		 * @param string[] $terms Visibility slugs.
		 * @param array    $args  Query args.
		 * @param mixed    $query Query object or null.
		 */
		$terms = apply_filters( 'prc_platform_pub_listing_visibility_terms', $terms, $args, $query );
		if ( ! is_array( $terms ) ) {
			return array();
		}

		$supported = self::get_supported_visibility_slugs();
		$clean     = array();
		foreach ( $terms as $term ) {
			if ( ! is_string( $term ) ) {
				continue;
			}
			if ( ! in_array( $term, $supported, true ) ) {
				continue;
			}
			$clean[] = $term;
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Default `_post_visibility` term slugs keyed by post type.
	 *
	 * Only applied when a post currently has no `_post_visibility` terms
	 * (so editors can clear defaults and stay opted into listings).
	 *
	 * @return array<string, string[]> Map of post_type => term slug[].
	 */
	public static function get_default_visibility(): array {
		/**
		 * Filter default `_post_visibility` term slugs by post type.
		 *
		 * Supported slugs: `hidden-on-index`, `hidden-on-search`.
		 * Only post types with `prc-publication-listing` support are honored.
		 *
		 * @param array<string, string[]> $defaults Map of post_type => term slug[].
		 */
		$defaults = apply_filters( 'prc_platform_pub_listing_default_visibility', array() );
		if ( ! is_array( $defaults ) ) {
			return array();
		}

		$enabled_post_types = self::get_enabled_post_types();
		$supported_slugs    = self::get_supported_visibility_slugs();
		$sanitized          = array();

		foreach ( $defaults as $post_type => $slugs ) {
			if ( ! is_string( $post_type ) || '' === $post_type ) {
				continue;
			}
			if ( ! in_array( $post_type, $enabled_post_types, true ) ) {
				continue;
			}
			if ( ! is_array( $slugs ) ) {
				continue;
			}

			$clean_slugs = array();
			foreach ( $slugs as $slug ) {
				if ( ! is_string( $slug ) ) {
					continue;
				}
				if ( ! in_array( $slug, $supported_slugs, true ) ) {
					continue;
				}
				$clean_slugs[] = $slug;
			}

			$clean_slugs = array_values( array_unique( $clean_slugs ) );
			if ( ! empty( $clean_slugs ) ) {
				$sanitized[ $post_type ] = $clean_slugs;
			}
		}

		return $sanitized;
	}

	/**
	 * Default `_post_visibility` term slugs for a single post type.
	 *
	 * @param string $post_type Post type slug.
	 * @return string[]
	 */
	public static function get_default_visibility_for_post_type( string $post_type ): array {
		$defaults = self::get_default_visibility();
		return $defaults[ $post_type ] ?? array();
	}

	/**
	 * Apply configured default `_post_visibility` terms on first post insert.
	 *
	 * Covers auto-draft editor creates and direct draft inserts (e.g. REST
	 * `status: draft`) that never fire `prc_platform_on_post_init`.
	 *
	 * @hook wp_after_insert_post
	 *
	 * @param int          $post_id Post ID.
	 * @param object|mixed $post    Post object.
	 * @param bool         $update  Whether this is an existing post being updated.
	 */
	public function apply_default_post_visibility_on_insert( $post_id, $post = null, $update = false ): void {
		if ( true === $update ) {
			return;
		}

		$this->apply_default_post_visibility( $post );
	}

	/**
	 * Apply configured default `_post_visibility` terms when a post is first created.
	 *
	 * Skips when the post already has visibility terms so editors can uncheck
	 * a default and remain opted into publication listings.
	 *
	 * @param object|mixed $post Post object.
	 */
	public function apply_default_post_visibility( $post ): void {
		if ( ! is_object( $post ) || empty( $post->post_type ) || empty( $post->ID ) ) {
			return;
		}

		$defaults = self::get_default_visibility_for_post_type( (string) $post->post_type );
		if ( empty( $defaults ) ) {
			return;
		}

		$current = wp_get_object_terms( (int) $post->ID, '_post_visibility', array( 'fields' => 'ids' ) );
		if ( ! empty( $current ) || is_wp_error( $current ) ) {
			return;
		}

		wp_set_object_terms( (int) $post->ID, $defaults, '_post_visibility', false );
	}

	/**
	 * Whether the query is the global site feed at /feed/ (not archive, singular, search, or custom add_feed slugs).
	 *
	 * @param \WP_Query $query The query object.
	 * @return bool
	 */
	public static function is_default_site_feed( $query ): bool {
		if ( $query->is_archive() || $query->is_search() || $query->is_singular() ) {
			return false;
		}

		$feed_slug               = $query->get( 'feed' );
		$default_site_feed_slugs = array( '', 'feed', 'rss', 'rss2', 'atom', 'rdf' );

		if ( is_string( $feed_slug ) && ! in_array( $feed_slug, $default_site_feed_slugs, true ) ) {
			return false;
		}

		return true;
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
		if ( ! self::is_default_site_feed( $query ) ) {
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
	 * Ensure ElasticPress indexes the _post_visibility taxonomy.
	 *
	 * @hook ep_sync_taxonomies
	 *
	 * @param array $taxonomies Taxonomies ElasticPress will sync.
	 * @return array
	 */
	public function ensure_post_visibility_synced( $taxonomies ) {
		$taxonomy = get_taxonomy( '_post_visibility' );
		if ( $taxonomy ) {
			$taxonomies['_post_visibility'] = $taxonomy;
		}
		return $taxonomies;
	}

	/**
	 * Enforce _post_visibility as an ES must_not filter on ep_integrate queries.
	 *
	 * Closes the gap where REST/feed search paths set ep_integrate without
	 * running get_filtered_query_args(). SQL tax_query remains for MySQL fallback.
	 *
	 * @hook ep_post_formatted_args
	 *
	 * @param array    $formatted_args Formatted ES args.
	 * @param array    $args           WP_Query args.
	 * @param \WP_Query $wp_query       Query object.
	 * @return array
	 */
	public function enforce_post_visibility_in_es( $formatted_args, $args, $wp_query ) {
		if ( defined( 'PRC_PRIMARY_SITE_ID' ) && PRC_PRIMARY_SITE_ID !== get_current_blog_id() ) {
			return $formatted_args;
		}

		$is_rest = defined( 'REST_REQUEST' ) && REST_REQUEST;
		if ( is_admin() && ! $is_rest ) {
			return $formatted_args;
		}

		// List archives must show campaigns that stay hidden-on-index on /publications.
		if ( $wp_query instanceof \WP_Query && $wp_query->is_tax( 'prc_newsletter_list' ) ) {
			return $formatted_args;
		}

		$visibility_terms = self::get_visibility_terms( is_array( $args ) ? $args : array(), $wp_query );
		if ( empty( $visibility_terms ) ) {
			return $formatted_args;
		}

		if ( ! isset( $formatted_args['post_filter'] ) || ! is_array( $formatted_args['post_filter'] ) ) {
			$formatted_args['post_filter'] = array();
		}
		if ( ! isset( $formatted_args['post_filter']['bool'] ) || ! is_array( $formatted_args['post_filter']['bool'] ) ) {
			$formatted_args['post_filter']['bool'] = array();
		}
		if ( ! isset( $formatted_args['post_filter']['bool']['must_not'] ) || ! is_array( $formatted_args['post_filter']['bool']['must_not'] ) ) {
			$formatted_args['post_filter']['bool']['must_not'] = array();
		}

		$formatted_args['post_filter']['bool']['must_not'][] = array(
			'terms' => array(
				'terms._post_visibility.slug' => $visibility_terms,
			),
		);

		return $formatted_args;
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
				'prc_newsletter_list',
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

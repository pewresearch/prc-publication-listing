<?php
/**
 * Bootstrap class.
 *
 * @package    PRC\Platform\Publication_Listing
 */

namespace PRC\Platform\Publication_Listing;

use WP_Error;

/**
 * Bootstrap class.
 *
 * @package    PRC\Platform\Publication_Listing
 */
class Bootstrap {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the platform as initialized by hooks.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = PRC_PUBLICATION_LISTING_VERSION;
		$this->plugin_name = 'prc-publication-listing';

		$this->load_dependencies();
		$this->init_dependencies();
	}


	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		// Load plugin loading class.
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';

		// Initialize the loader.
		$this->loader = new Loader();

		// Load files...
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-query.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-archive-redirects.php';
	}

	/**
	 * Register post visibility assets.
	 */
	public function register_assets() {
		$post_visibility_asset_file = include PRC_PUBLICATION_LISTING_DIR . '/build/post-visibility/index.asset.php';
		$core_query_asset_file      = include PRC_PUBLICATION_LISTING_DIR . '/build/core-query/index.asset.php';

		$asset_slug = 'prc-platform-publication-listing__';

		$script = wp_register_script(
			$asset_slug . 'post-visibility',
			plugins_url( 'build/post-visibility/index.js', PRC_PUBLICATION_LISTING_FILE ),
			$post_visibility_asset_file['dependencies'],
			$post_visibility_asset_file['version'],
			true
		);

		$script = wp_register_script(
			$asset_slug . 'core-query',
			plugins_url( 'build/core-query/index.js', PRC_PUBLICATION_LISTING_FILE ),
			$core_query_asset_file['dependencies'],
			$core_query_asset_file['version'],
			true
		);
	}

	/**
	 * Enqueue post visibility assets.
	 *
	 * @hook enqueue_block_editor_assets
	 */
	public function enqueue_assets() {
		$this->register_assets();
		wp_enqueue_script( 'prc-platform-publication-listing__core-query' );
		if ( in_array( get_post_type(), Query::get_enabled_post_types(), true ) ) {
			wp_enqueue_script( 'prc-platform-publication-listing__post-visibility' );
		}
	}

	/**
	 * Register default post type support for publication listing.
	 *
	 * The 'post' type is supported by default. Other plugins can add support
	 * for their post types using add_post_type_support( 'my-type', 'prc-publication-listing' ).
	 *
	 * @hook init 5
	 */
	public function register_default_post_type_support() {
		add_post_type_support( 'post', 'prc-publication-listing' );
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$query = new Query();

		// Register default post type support early, before taxonomy registration.
		$this->loader->add_action( 'init', $this, 'register_default_post_type_support', 5 );

		// Hook into various actions and filters.
		$this->loader->add_action( 'init', $query, 'register_post_visibility_taxonomy', 10 );
		// Apply on any first insert (auto-draft, draft, etc.) — not only post_init.
		$this->loader->add_action( 'wp_after_insert_post', $query, 'apply_default_post_visibility_on_insert', 10, 3 );
		$this->loader->add_action( 'pre_get_posts', $query, 'init_pub_listing__wp_query', 1, 1 );
		$this->loader->add_filter( 'block_type_metadata_settings', $query, 'default_tax_query_to_or', 100, 2 );
		$this->loader->add_filter( 'block_type_metadata_settings', $query, 'update_context', 100, 2 );
		$this->loader->add_filter( 'query_vars', $query, 'register_query_var', 100, 1 );

		// Hook into various queries.
		$this->loader->add_action( 'pre_get_posts', $query, 'include_in_main_feed', 11, 1 );
		$this->loader->add_action( 'pre_get_posts', $query, 'hook_pub_listing_args_into__wp_query', 11, 1 );
		$this->loader->add_filter( 'pre_render_block', $query, 'hook_pub_listing_args_into__core_query', 11, 3 );
		$this->loader->add_filter( 'rest_post_query', $query, 'hook_pub_listing_args_into__rest_query', 11, 2 );
		$this->loader->add_filter( 'ep_post_formatted_args', $query, 'enforce_post_visibility_in_es', 20, 3 );
		$this->loader->add_filter( 'ep_sync_taxonomies', $query, 'ensure_post_visibility_synced', 10, 1 );
		$this->loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_assets', 11, 1 );

		$archive_redirects = new Archive_Redirects();
		$this->loader->add_action( 'template_redirect', $archive_redirects, 'redirect_years_archive' );
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    PRC\Platform\Publication_Listing\Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}

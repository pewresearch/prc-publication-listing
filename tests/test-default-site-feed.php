<?php
declare(strict_types=1);
/**
 * Regression coverage for main-feed-only post type expansion.
 *
 * Run with:
 * php plugins/prc-publication-listing/tests/test-default-site-feed.php
 */

namespace {
	/**
	 * Minimal WP_Query stand-in for feed classification tests.
	 */
	class Mock_WP_Query {
		public bool $is_archive   = false;
		public bool $is_search    = false;
		public bool $is_singular  = false;
		/** @var array<string, mixed> */
		private array $vars = array();

		public function is_archive(): bool {
			return $this->is_archive;
		}

		public function is_search(): bool {
			return $this->is_search;
		}

		public function is_singular(): bool {
			return $this->is_singular;
		}

		/**
		 * @param string $key Query var key.
		 * @return mixed
		 */
		public function get( string $key ) {
			return $this->vars[ $key ] ?? '';
		}

		/**
		 * @param string $key   Query var key.
		 * @param mixed  $value Query var value.
		 */
		public function set( string $key, $value ): void {
			$this->vars[ $key ] = $value;
		}
	}

	function assert_true( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}
}

namespace PRC\Platform\Publication_Listing {
	require_once dirname( __DIR__ ) . '/includes/class-query.php';

	$query = new Query();

	$main_feed = new \Mock_WP_Query();
	assert_true(
		Query::is_default_site_feed( $main_feed ),
		'bare /feed/ should be treated as the default site feed'
	);

	$rss2_feed = new \Mock_WP_Query();
	$rss2_feed->set( 'feed', 'rss2' );
	assert_true(
		Query::is_default_site_feed( $rss2_feed ),
		'/feed/rss2/ should be treated as the default site feed'
	);

	$cpt_archive_feed = new \Mock_WP_Query();
	$cpt_archive_feed->is_archive = true;
	$cpt_archive_feed->set( 'post_type', 'decoded' );
	assert_true(
		! Query::is_default_site_feed( $cpt_archive_feed ),
		'post type archive feeds should not be treated as the default site feed'
	);

	$custom_feed = new \Mock_WP_Query();
	$custom_feed->set( 'feed', 'homepage' );
	assert_true(
		! Query::is_default_site_feed( $custom_feed ),
		'custom add_feed() slugs should not be treated as the default site feed'
	);

	$spoken_feed = new \Mock_WP_Query();
	$spoken_feed->set( 'feed', 'spoken-articles' );
	assert_true(
		! Query::is_default_site_feed( $spoken_feed ),
		'spoken-articles feed should not be treated as the default site feed'
	);

	$search_feed = new \Mock_WP_Query();
	$search_feed->is_search = true;
	assert_true(
		! Query::is_default_site_feed( $search_feed ),
		'search feeds should not be treated as the default site feed'
	);

	fwrite( STDOUT, "PASS: default site feed classification\n" );
}

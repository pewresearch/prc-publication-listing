<?php
/**
 * Archive Redirects class for publication listing.
 *
 * @package PRC\Platform\Publication_Listing
 */

namespace PRC\Platform\Publication_Listing;

use function PRC\TDS\get_relationship;

/**
 * Handles year-archive redirects to publication listing URLs.
 *
 * @package PRC\Platform\Publication_Listing
 */
class Archive_Redirects {
	/**
	 * Redirect year archives to a custom publications URL format.
	 *
	 * @hook template_redirect
	 */
	public function redirect_years_archive() {
		if ( is_year() ) {
			$year = get_query_var( 'year' );
			if ( $year ) {
				$post_type = is_post_type_archive() ? get_post_type() : null;
				if ( $post_type ) {
					$taxonomy = get_relationship( $post_type );
					if ( $taxonomy ) {
						$tax = get_taxonomy( $taxonomy );
						if ( $tax ) {
							$target_url = get_bloginfo( 'url' ) . '/' . $tax->rewrite['slug'];
						}
					}
					$target_url = get_post_type_archive_link( $post_type );
				} else {
					$target_url = home_url( '/publications/' );
				}

				$target_url = add_query_arg( 'ep_filter_years', $year, $target_url );

				wp_redirect( $target_url, 301 );
				exit;
			}
		}
	}
}

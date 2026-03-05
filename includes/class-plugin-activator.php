<?php
/**
 * Fired during plugin activation.
 *
 * @package    PRC\Platform\Publication_Listing
 */

namespace PRC\Platform\Publication_Listing;

/**
 * The plugin activator class.
 *
 * @package    PRC\Platform\Publication_Listing
 */
class Plugin_Activator {

	/**
	 * Activate the plugin.
	 *
	 * @since    1.0.0
	 */
	public static function activate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'🔌 PRC Publication Listing Activated',
			'The PRC Publication Listing plugin has been activated on ' . get_site_url()
		);
	}
}

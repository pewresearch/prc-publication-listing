<?php
/**
 * Fired during plugin deactivation.
 *
 * @package    PRC\Platform\Publication_Listing
 */

namespace PRC\Platform\Publication_Listing;

/**
 * The plugin deactivator class.
 *
 * @package    PRC\Platform\Publication_Listing
 */
class Plugin_Deactivator {

	/**
	 * Deactivate the plugin.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'🔌 PRC Publication Listing Deactivated',
			'The PRC Publication Listing plugin has been deactivated on ' . get_site_url()
		);
	}
}

<?php
/**
 * PRC Publication Listing
 *
 * @package           PRC_Publication_Listing
 * @author            Seth Rubenstein
 * @copyright         2024 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Publication Listing
 * Plugin URI:        https://github.com/pewresearch/prc-publication-listing
 * Description:       A module for PRC Platform that provides the default query handler for publication listings and post visibility controls.
 * Version:           1.1.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-publication-listing
 * Requires Plugins:  prc-scripts
 */

namespace PRC\Platform\Publication_Listing;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'PRC_PRIMARY_SITE_ID' ) ) {
	define( 'PRC_PRIMARY_SITE_ID', 1 );
}
define( 'PRC_PUBLICATION_LISTING_FILE', __FILE__ );
define( 'PRC_PUBLICATION_LISTING_DIR', __DIR__ );
define( 'PRC_PUBLICATION_LISTING_VERSION', '1.1.0' );

/**
 * Helper utilities
 */
require plugin_dir_path( __FILE__ ) . 'includes/utils.php';

/**
 * The core bootstrap class that is used to define the hooks that initialize the various components.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-bootstrap.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_prc_publication_listing() {
	$plugin = new Bootstrap();
	$plugin->run();
}
run_prc_publication_listing();

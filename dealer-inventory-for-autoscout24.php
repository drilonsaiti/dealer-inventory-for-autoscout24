<?php
/**
 * Plugin Name:       Dealer Inventory for AutoScout24
 * Plugin URI:        https://github.com/drilonsaiti/dealer-inventory-for-autoscout24
 * Description:       Show a car dealer's AutoScout24 stock on their own WordPress site: synced locally, fast, crawlable and fully styleable.
 * Version:           1.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Drilon Saiti
 * Author URI:        https://drilonsaiti.github.io/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dealer-inventory-for-autoscout24
 * Domain Path:       /languages
 *
 * This plugin is not affiliated with, endorsed by or sponsored by AutoScout24.
 * AutoScout24 is a trademark of its respective owner.
 *
 * @package DealerInventory
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin constants.
 */
define( 'DINV_VERSION', '1.1.0' );
define( 'DINV_PLUGIN_FILE', __FILE__ );
define( 'DINV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DINV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader: DealerInventory\Foo_Bar -> includes/class-foo-bar.php,
 * DealerInventory\Providers\Foo -> includes/providers/class-foo.php
 * (or interface-foo.php).
 *
 * Classes are only parsed when a request actually needs them, which keeps
 * ordinary front-end requests light.
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'DealerInventory\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$name  = strtolower( str_replace( '_', '-', (string) array_pop( $parts ) ) );
		$dir   = DINV_PLUGIN_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' );

		foreach ( array( 'class-', 'interface-' ) as $type ) {
			if ( is_readable( $dir . $type . $name . '.php' ) ) {
				require_once $dir . $type . $name . '.php';
				return;
			}
		}
	}
);

/**
 * Register activation and deactivation hooks.
 */
register_activation_hook(
	__FILE__,
	array( 'DealerInventory\\Plugin', 'activate' )
);

register_deactivation_hook(
	__FILE__,
	array( 'DealerInventory\\Plugin', 'deactivate' )
);

/**
 * Boot the plugin.
 */
DealerInventory\Plugin::instance()->boot();

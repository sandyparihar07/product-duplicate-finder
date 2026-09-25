<?php
/**
 * SKUSN Dependencies class
 *
 * Checks WooCommerce and PHP version requirements,
 * loads optional dependencies, and handles compatibility.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Dependencies {
	/**
	 * Check if WooCommerce is installed and active.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_woocommerce_available() {
		if ( ! function_exists( 'wc_get_template' ) ) {
			return false;
		}

		if ( ! did_action( 'woocommerce_loaded' ) ) {
			return false;
		}

		$wc_version = defined( 'WC_VERSION' ) ? WC_VERSION : '0';
		if ( version_compare( $wc_version, '6.0', '<' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check if PHP version is sufficient.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function is_php_sufficient() {
		return version_compare( PHP_VERSION, '8.0', '>=' );
	}

	/**
	 * Get the minimum required WooCommerce version.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_min_woocommerce_version() {
		return '6.0';
	}

	/**
	 * Get the minimum required PHP version.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_min_php_version() {
		return '8.0';
	}

	/**
	 * Get the minimum required WordPress version.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public static function get_min_wordpress_version() {
		return '6.6';
	}
}

<?php
/**
 * SKUSN Capabilities class
 *
 * Handles custom capability creation and mapping.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Capabilities {
	/**
	 * Register custom capability.
	 *
	 * @since 1.0.0
	 */
	public static function register_capability() {
		$role = get_role( 'administrator' );
		if ( $role ) {
			$role->add_cap( SKUSN_CAPABILITY );
		}
	}

	/**
	 * Map capability to user roles.
	 *
	 * @since 1.0.0
	 * @param array $roles Associative array of role_name => capability_permissions
	 */
	public static function map_capability_to_roles( $roles = array() ) {
		if ( ! empty( $roles['administrator'] ) ) {
			$role = get_role( 'administrator' );
			if ( $role ) {
				$role->add_cap( SKUSN_CAPABILITY );
			}
		}

		if ( ! empty( $roles['shop_manager'] ) ) {
			$role = get_role( 'shop_manager' );
			if ( $role ) {
				$role->add_cap( SKUSN_CAPABILITY );
			}
		}
	}

	/**
	 * Check if current user has the required capability.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public static function current_user_can() {
		return current_user_can( SKUSN_CAPABILITY );
	}
}

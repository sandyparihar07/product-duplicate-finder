<?php
/**
 * SKUSN Database class
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Database {
	public $table_names = array();

	const CACHE_GROUP = 'skusn_db';

	public function __construct() {
		global $wpdb;
		$this->table_names = array(
			'scans'     => "{$wpdb->prefix}skusn_scans",
			'groups'    => "{$wpdb->prefix}skusn_groups",
			'items'     => "{$wpdb->prefix}skusn_items",
			'audit_log' => "{$wpdb->prefix}skusn_audit_log",
		);
	}

	/**
	 * Generate a UUID v4 (polyfill).
	 */
	public static function generate_uuid() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$data    = random_bytes( 16 );
		$data[6] = chr( ord( $data[6] ) & 0x0f | 0x40 );
		$data[8] = chr( ord( $data[8] ) & 0x3f | 0x80 );
		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}

	/**
	 * Flush all plugin caches.
	 */
	public function flush_cache() {
		wp_cache_flush_group( self::CACHE_GROUP );
	}

	/**
	 * Flush caches for a specific scan.
	 */
	private function flush_scan_cache( $scan_id ) {
		wp_cache_delete( 'scan_' . $scan_id, self::CACHE_GROUP );
		wp_cache_delete( 'groups_' . $scan_id, self::CACHE_GROUP );
		wp_cache_delete( 'stats', self::CACHE_GROUP );
	}

	/**
	 * Flush caches for groups (after group/item inserts).
	 */
	private function flush_groups_cache( $scan_id ) {
		wp_cache_delete( 'groups_' . $scan_id, self::CACHE_GROUP );
		wp_cache_delete( 'stats', self::CACHE_GROUP );
	}

	public function create_tables() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$scans           = $this->table_names['scans'];
		$groups          = $this->table_names['groups'];
		$items           = $this->table_names['items'];
		$audit_log       = $this->table_names['audit_log'];

		$sql = "CREATE TABLE {$scans} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid varchar(36) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'pending',
			scan_type varchar(20) NOT NULL DEFAULT 'sku',
			scope_json longtext NOT NULL,
			started_at datetime DEFAULT NULL,
			completed_at datetime DEFAULT NULL,
			processed_count bigint(20) unsigned NOT NULL DEFAULT 0,
			total_count bigint(20) unsigned NOT NULL DEFAULT 0,
			duplicate_group_count bigint(20) unsigned NOT NULL DEFAULT 0,
			affected_item_count bigint(20) unsigned NOT NULL DEFAULT 0,
			error_message text,
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uuid (uuid)
		) $charset_collate;

		CREATE TABLE {$groups} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			scan_id bigint(20) unsigned NOT NULL,
			duplicate_type varchar(50) NOT NULL DEFAULT 'sku',
			comparison_key varchar(255) NOT NULL DEFAULT '',
			display_value varchar(255) NOT NULL DEFAULT '',
			item_count bigint(20) unsigned NOT NULL DEFAULT 1,
			status varchar(20) NOT NULL DEFAULT 'open',
			reviewed_by bigint(20) unsigned DEFAULT NULL,
			reviewed_at datetime DEFAULT NULL,
			review_note text,
			created_at datetime DEFAULT NULL,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY scan_id (scan_id),
			KEY comparison_key (comparison_key)
		) $charset_collate;

		CREATE TABLE {$items} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			group_id bigint(20) unsigned NOT NULL,
			object_id bigint(20) unsigned NOT NULL,
			object_type varchar(50) NOT NULL DEFAULT 'product',
			parent_id bigint(20) unsigned DEFAULT NULL,
			original_value varchar(255) NOT NULL DEFAULT '',
			normalized_value varchar(255) NOT NULL DEFAULT '',
			recommended_keep bigint(20) unsigned DEFAULT NULL,
			resolution_status varchar(20) NOT NULL DEFAULT 'pending',
			resolved_at datetime DEFAULT NULL,
			resolved_by bigint(20) unsigned DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY group_id (group_id),
			KEY object_id (object_id),
			KEY normalized_value (normalized_value)
		) $charset_collate;

		CREATE TABLE {$audit_log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			action varchar(50) NOT NULL,
			object_type varchar(50) NOT NULL DEFAULT 'product',
			object_id bigint(20) unsigned NOT NULL DEFAULT 0,
			group_id bigint(20) unsigned DEFAULT NULL,
			before_json longtext,
			after_json longtext,
			result varchar(50) NOT NULL DEFAULT 'success',
			message text,
			created_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY user_id (user_id),
			KEY action (action),
			KEY object_id (object_id),
			KEY group_id (group_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		add_option( 'skusn_db_version', '1.0.0', '', 'yes' );
	}

	/**
	 * Check if tables exist.
	 */
	public function tables_exist() {
		global $wpdb;

		$cached = wp_cache_get( 'tables_exist', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$table  = $this->table_names['scans'];
		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s AND table_name = %s', $wpdb->dbname, $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.

		$result = (int) $exists > 0;
		wp_cache_set( 'tables_exist', $result, self::CACHE_GROUP, 10 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Insert a new scan record.
	 */
	public function insert_scan( $args ) {
		global $wpdb;

		$defaults = array(
			'uuid'       => self::generate_uuid(),
			'status'     => 'pending',
			'scan_type'  => 'sku',
			'scope_json' => '{}',
			'created_by' => get_current_user_id(),
		);
		$args     = wp_parse_args( $args, $defaults );

		$wpdb->insert( $this->table_names['scans'], $args ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin uses custom database tables.

		if ( false === $wpdb->insert_id || 0 === $wpdb->insert_id ) {
			return false;
		}

		$this->flush_cache();
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a scan record.
	 */
	public function update_scan( $scan_id, $updates ) {
		global $wpdb;

		$result = $wpdb->update( $this->table_names['scans'], $updates, array( 'id' => (int) $scan_id ), null, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin uses custom database tables.

		$this->flush_scan_cache( $scan_id );
		return $result;
	}

	/**
	 * Get a scan by ID (cached).
	 */
	public function get_scan_by_id( $scan_id ) {
		global $wpdb;

		$scan_id = (int) $scan_id;
		$cached  = wp_cache_get( 'scan_' . $scan_id, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_names['scans']} WHERE id = %d", $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.

		wp_cache_set( 'scan_' . $scan_id, $result, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Get a scan by UUID (cached).
	 */
	public function get_scan_by_uuid( $uuid ) {
		global $wpdb;

		$cache_key = 'scan_uuid_' . md5( (string) $uuid );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table_names['scans']} WHERE uuid = %s", $uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $result;
	}

	/**
	 * Get recent scans (cached).
	 *
	 * @param int    $limit  Number of scans to retrieve.
	 * @param string $status Optional status filter.
	 * @return array Array of scan objects.
	 */
	public function get_scans( $limit = 10, $status = '' ) {
		global $wpdb;

		$cache_key = 'scans_' . $limit . '_' . md5( $status );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		if ( $status ) {
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_names['scans']} WHERE status = %s ORDER BY id DESC LIMIT %d", $status, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.
		} else {
			$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_names['scans']} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.
		}

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $results;
	}

	/**
	 * Get dashboard stats (cached).
	 *
	 * @return array Stats array with keys: total_scans, completed, dupes_found, products_affected.
	 */
	public function get_stats() {
		$cached = wp_cache_get( 'stats', self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;
		$scans = $this->table_names['scans'];

		$stats = array(
			'total_scans'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$scans}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.
			'completed'         => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$scans} WHERE status = %s", 'completed' ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.
			'dupes_found'       => (int) $wpdb->get_var( "SELECT COALESCE(SUM(duplicate_group_count), 0) FROM {$scans} WHERE status = 'completed'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.
			'products_affected' => (int) $wpdb->get_var( "SELECT COALESCE(SUM(affected_item_count), 0) FROM {$scans} WHERE status = 'completed'" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.
		);

		wp_cache_set( 'stats', $stats, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $stats;
	}

	/**
	 * Insert a duplicate group.
	 */
	public function insert_group( $args ) {
		global $wpdb;

		$wpdb->insert( $this->table_names['groups'], $args ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin uses custom database tables.

		if ( false === $wpdb->insert_id || 0 === $wpdb->insert_id ) {
			return false;
		}

		if ( ! empty( $args['scan_id'] ) ) {
			$this->flush_groups_cache( (int) $args['scan_id'] );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get groups for a scan (cached).
	 *
	 * @param int $scan_id Scan ID.
	 * @return array Array of group objects.
	 */
	public function get_groups( $scan_id ) {
		global $wpdb;

		$scan_id = (int) $scan_id;
		$cached  = wp_cache_get( 'groups_' . $scan_id, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_names['groups']} WHERE scan_id = %d ORDER BY id DESC", $scan_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.

		wp_cache_set( 'groups_' . $scan_id, $results, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $results;
	}

	/**
	 * Insert a duplicate item.
	 */
	public function insert_item( $args ) {
		global $wpdb;

		$wpdb->insert( $this->table_names['items'], $args ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin uses custom database tables.

		if ( false === $wpdb->insert_id || 0 === $wpdb->insert_id ) {
			return false;
		}

		if ( ! empty( $args['group_id'] ) ) {
			$scan_id = $wpdb->get_var( $wpdb->prepare( "SELECT scan_id FROM {$this->table_names['groups']} WHERE id = %d", (int) $args['group_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.
			if ( $scan_id ) {
				$this->flush_groups_cache( (int) $scan_id );
			}
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Get items for a group (cached).
	 *
	 * @param int $group_id Group ID.
	 * @return array Array of item objects.
	 */
	public function get_items( $group_id ) {
		global $wpdb;

		$group_id = (int) $group_id;
		$cached   = wp_cache_get( 'items_' . $group_id, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$results = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table_names['items']} WHERE group_id = %d", $group_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name safely built from $wpdb->prefix; plugin uses custom database tables.

		wp_cache_set( 'items_' . $group_id, $results, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );
		return $results;
	}

	/**
	 * Insert an audit log entry.
	 */
	public function insert_audit_log( $args ) {
		global $wpdb;

		$wpdb->insert( $this->table_names['audit_log'], $args ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Plugin uses custom database tables.

		return (int) $wpdb->insert_id;
	}

	/**
	 * Drop all plugin tables.
	 */
	public function drop_tables() {
		global $wpdb;

		foreach ( $this->table_names as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names safely built from $wpdb->prefix; plugin uses custom database tables.
		}

		$this->flush_cache();
		delete_option( 'skusn_settings' );
		delete_option( 'skusn_db_version' );
	}
}

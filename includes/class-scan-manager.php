<?php
/**
 * SKUSN Scan Manager class
 *
 * Handles scan lifecycle: start, pause, resume, cancel, and status tracking.
 * Integrates with the database for persistent scan records.
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Scan_Manager {
	/**
	 * Database instance.
	 *
	 * @since 1.0.0
	 * @var SKUSN_Database
	 */
	protected $db;

	/**
	 * Current scan ID (if in progress).
	 *
	 * @since 1.0.0
	 * @var int
	 */
	protected $current_scan_id = 0;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param SKUSN_Database $db Database instance
	 */
	public function __construct( SKUSN_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Start a new duplicate SKU scan.
	 *
	 * @since 1.0.0
	 * @param array $config Scan configuration
	 * @return int|false Scan ID
	 */
	public function start_new_scan( $config ) {
		// Check if a scan is already in progress
		$active_scan = $this->get_active_scan();
		if ( $active_scan ) {
			return new WP_Error( 'scan_active', esc_html__( 'A scan is already in progress. Please wait for it to complete or cancel it.', 'product-duplicate-finder' ) );
		}

		// Create new scan record
		$scan_id = $this->db->insert_scan(
			array(
				'scan_type'  => 'sku',
				'scope_json' => isset( $config['scope'] ) ? wp_json_encode( $config['scope'] ) : '{}',
				'created_by' => get_current_user_id() ? get_current_user_id() : 0,
			)
		);

		if ( ! $scan_id ) {
			return new WP_Error( 'scan_creation_failed', esc_html__( 'Failed to create scan record.', 'product-duplicate-finder' ) );
		}

		// Run the detection
		$detector = new SKUSN_Duplicate_Detector( $this->db, new SKUSN_Sku_Normalizer() );
		$result   = $detector->start_scan( array() );

		return $scan_id;
	}

	/**
	 * Get active (in-progress) scan.
	 *
	 * @since 1.0.0
	 * @return int|false Scan ID or false
	 */
	protected function get_active_scan() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; plugin uses custom database tables.
		$scan = $wpdb->get_row( "SELECT id FROM {$this->db->table_names['scans']} WHERE status = 'in_progress' LIMIT 1" );

		return ! empty( $scan ) ? (int) $scan->id : false;
	}

	/**
	 * Resume a previously interrupted scan.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID to resume
	 * @return bool
	 */
	public function resume_scan( $scan_id ) {
		$scan_id = (int) $scan_id;

		$scan = $this->db->get_scan_by_id( $scan_id );

		if ( ! $scan ) {
			return false;
		}

		if ( 'completed' === $scan->status || 'cancelled' === $scan->status ) {
			return false;
		}

		$this->current_scan_id = $scan_id;
		$this->run_scan_batch( $scan_id );

		return true;
	}

	/**
	 * Cancel an in-progress scan.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID to cancel
	 * @return bool
	 */
	public function cancel_scan( $scan_id ) {
		$scan_id = (int) $scan_id;

		// Update scan status
		$this->db->update_scan(
			$scan_id,
			array(
				'status'       => 'cancelled',
				'completed_at' => current_time( 'mysql' ),
			)
		);

		// Clear current scan ID
		$this->current_scan_id = 0;

		return true;
	}

	/**
	 * Get scan summary.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID
	 * @return array Summary data
	 */
	public function get_scan_summary( $scan_id ) {
		$scan_id = (int) $scan_id;
		$scan    = $this->db->get_scan_by_id( $scan_id );

		if ( ! $scan ) {
			return array();
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; plugin uses custom database tables.
		$group_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->db->table_names['groups']} WHERE scan_id = %d", $scan_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; plugin uses custom database tables.
		$affected_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$this->db->table_names['items']} WHERE group_id IN (SELECT id FROM {$this->db->table_names['groups']} WHERE scan_id = %d)", $scan_id ) );

		return array(
			'scan_id'               => $scan_id,
			'status'                => $scan->status,
			'scan_type'             => $scan->scan_type,
			'total_count'           => $scan->total_count,
			'processed_count'       => $scan->processed_count,
			'duplicate_group_count' => $group_count,
			'affected_item_count'   => $affected_count,
			'started_at'            => $scan->started_at,
			'completed_at'          => $scan->completed_at,
		);
	}

	/**
	 * Process scan batches continuously.
	 *
	 * @since 1.0.0
	 * @param int $scan_id Scan ID
	 */
	protected function run_scan_batch( $scan_id ) {
		// This is called from the detector; in a real implementation,
		// this would use AJAX or Action Scheduler for background processing.
		// For now, we'll do a simple batch loop.
		global $wpdb;

		$batch_size = (int) apply_filters( 'skusn_scan_batch_size', 200 );

		// Get total count from scan record
		$scan_record = $this->db->get_scan_by_id( $scan_id );
		$total       = $scan_record ? (int) $scan_record->total_count : 0;

		if ( ! $total || 0 === $total ) {
			// Mark as completed with no items
			$this->db->update_scan(
				$scan_id,
				array(
					'status'                => 'completed',
					'completed_at'          => current_time( 'mysql' ),
					'processed_count'       => 0,
					'duplicate_group_count' => 0,
					'affected_item_count'   => 0,
				)
			);
			return;
		}

		// Mark as in progress
		$this->db->update_scan(
			$scan_id,
			array(
				'status'          => 'in_progress',
				'processed_count' => 0,
			)
		);

		// Process in batches
		$offset     = 0;
		$all_groups = array();

		do {
			// Get batch of products - simplified for now
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix, batch size cast to int; plugin uses custom database tables.
			$products = $wpdb->get_results( "SELECT p.ID, pm.meta_value as sku FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku' WHERE p.post_type = 'product' AND p.post_status != 'trash' LIMIT {$offset}, {$batch_size}" );

			if ( empty( $products ) ) {
				break;
			}

			// Detect duplicates in this batch
			$groups = $this->detect_duplicates_in_batch( $products );

			// Store groups
			foreach ( $groups as $key => $group ) {
				// Check if group already exists
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; plugin uses custom database tables.
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->db->table_names['groups']} WHERE scan_id = %d AND comparison_key = %s", $scan_id, $key ) );

				if ( ! $existing ) {
					$group_id = $this->db->insert_group(
						array(
							'scan_id'        => $scan_id,
							'duplicate_type' => 'sku',
							'comparison_key' => $key,
							'display_value'  => $groups[ $key ]['display_value'],
							'item_count'     => count( $groups[ $key ]['items'] ),
							'status'         => 'open',
						)
					);

					// Insert items
					foreach ( $groups[ $key ]['items'] as $item ) {
						$this->db->insert_item(
							array(
								'group_id'          => $group_id,
								'object_id'         => $item['object_id'],
								'object_type'       => $item['object_type'],
								'parent_id'         => $item['parent_id'],
								'original_value'    => $item['original_value'],
								'normalized_value'  => $key,
								'recommended_keep'  => null,
								'resolution_status' => 'pending',
							)
						);
					}
				}

				$all_groups[ $key ] = array(
					'group_id'       => isset( $existing ) ? (int) $existing : $group_id,
					'comparison_key' => $key,
					'display_value'  => $groups[ $key ]['display_value'],
					'item_count'     => count( $groups[ $key ]['items'] ),
				);
			}

			$offset += $batch_size;

			// Update progress
			$processed = min( $offset, $total );
			$this->db->update_scan(
				$scan_id,
				array(
					'processed_count' => $processed,
				)
			);

		} while ( $offset < $total );

		// Mark scan as completed
		$this->db->update_scan(
			$scan_id,
			array(
				'status'                => 'completed',
				'completed_at'          => current_time( 'mysql' ),
				'processed_count'       => $total,
				'duplicate_group_count' => count( $all_groups ),
				'affected_item_count'   => isset( $all_groups ) ? count( $all_groups ) * 2 : 0, // rough estimate
			)
		);
	}

	/**
	 * Detect duplicates in a batch of products.
	 *
	 * @since 1.0.0
	 * @param array $products Product data
	 * @return array Duplicate groups
	 */
	protected function detect_duplicates_in_batch( $products ) {
		$comparison_groups = array();

		foreach ( $products as $product ) {
			$sku        = ! empty( $product->sku ) ? $product->sku : '';
			$normalized = SKUSN_Sku_Normalizer::create_comparison_key(
				$sku,
				array(
					'trim_whitespace'  => true,
					'case_insensitive' => true,
				)
			);

			if ( 'empty-sku' === $normalized ) {
				continue;
			}

			if ( ! isset( $comparison_groups[ $normalized ] ) ) {
				$comparison_groups[ $normalized ] = array(
					'duplicate_type' => 'sku',
					'comparison_key' => $normalized,
					'display_value'  => $sku,
					'items'          => array(),
				);
			}

			$comparison_groups[ $normalized ]['items'][] = array(
				'object_id'   => $product->ID,
				'object_type' => 'product',
				'sku'         => $sku,
			);
		}

		// Filter groups with more than 1 item
		$duplicate_groups = array();
		foreach ( $comparison_groups as $key => $group ) {
			if ( count( $group['items'] ) > 1 ) {
				$duplicate_groups[ $key ] = $group;
			}
		}

		return $duplicate_groups;
	}
}

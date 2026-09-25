<?php
/**
 * SKUSN Duplicate Detector class
 *
 * Detects duplicates by:
 * - SKU (normalized per settings)
 * - Product name (normalized per settings)
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Duplicate_Detector {
	protected $db;
	protected $normalizer;

	public function __construct( SKUSN_Database $db, SKUSN_Sku_Normalizer $normalizer ) {
		$this->db         = $db;
		$this->normalizer = $normalizer;
	}

	/**
	 * Start a duplicate scan.
	 *
	 * @param array $scan_args Scan configuration.
	 * @return int|WP_Error Scan ID or error.
	 */
	public function start_scan( $scan_args ) {
		$scope = isset( $scan_args['scope'] ) ? $scan_args['scope'] : array();

		$scan_id = $this->db->insert_scan(
			array(
				'scan_type'  => 'all',
				'scope_json' => wp_json_encode( $scope ),
				'created_by' => get_current_user_id(),
			)
		);

		if ( ! $scan_id ) {
			return new WP_Error( 'scan_failed', __( 'Failed to create scan record.', 'product-duplicate-finder' ) );
		}

		$this->run_scan( $scan_id, $scope );

		return $scan_id;
	}

	/**
	 * Run the full scan for both SKU and name duplicates.
	 *
	 * @param int   $scan_id Scan record ID.
	 * @param array $scope   Scan scope and settings.
	 */
	protected function run_scan( $scan_id, $scope ) {
		global $wpdb;

		$settings = sku_sentinel_get_settings();

		$include_var   = ! empty( $scope['include_variations'] );
		$include_trash = ! empty( $scope['include_trashed'] );
		$trim_ws       = ! empty( $settings['trim_whitespace'] );
		$case_insens   = ! empty( $settings['case_insensitive'] );
		$excluded_cats = ! empty( $settings['excluded_categories'] ) ? array_map( 'absint', $settings['excluded_categories'] ) : array();

		$statuses = array( 'publish', 'draft', 'pending', 'private' );
		if ( $include_trash ) {
			$statuses[] = 'trash';
		}

		$placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$post_types = $include_var
			? array( 'product', 'product_variation' )
			: array( 'product' );

		$pt_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

		// Build category exclusion clause
		$cat_exclude_clause = '';
		$cat_exclude_args   = array();
		if ( ! empty( $excluded_cats ) ) {
			$cat_placeholders   = implode( ',', array_fill( 0, count( $excluded_cats ), '%d' ) );
			$cat_exclude_clause = "AND p.ID NOT IN (
				SELECT DISTINCT tr.object_id
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
				WHERE tt.taxonomy = 'product_cat'
				AND tt.term_id IN ({$cat_placeholders})
			)";
			$cat_exclude_args   = $excluded_cats;
		}

		// Count all eligible products
		$count_sql = "SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			WHERE p.post_type IN ({$pt_placeholders})
			AND p.post_status IN ({$placeholders})
			{$cat_exclude_clause}";

		$count_args = array_merge( $post_types, $statuses, $cat_exclude_args );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL built with safely constructed placeholders; plugin uses custom database tables.
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$count_args ) );

		if ( $total <= 0 ) {
			$this->complete_scan( $scan_id, 0 );
			return;
		}

		$this->db->update_scan(
			$scan_id,
			array(
				'status'      => 'in_progress',
				'total_count' => $total,
				'started_at'  => current_time( 'mysql' ),
			)
		);

		// Fetch all eligible products in batches
		$batch_size   = 200;
		$offset       = 0;
		$all_products = array();

		while ( $offset < $total ) {
			$sql = "SELECT p.ID, p.post_type, p.post_parent, p.post_title,
					pm_sku.meta_value as sku
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
				WHERE p.post_type IN ({$pt_placeholders})
				AND p.post_status IN ({$placeholders})
				{$cat_exclude_clause}
				ORDER BY p.ID ASC
				LIMIT %d, %d";

			$sql_args = array_merge( $post_types, $statuses, $cat_exclude_args, array( $offset, $batch_size ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- SQL built with safely constructed placeholders; plugin uses custom database tables.
			$products = $wpdb->get_results( $wpdb->prepare( $sql, ...$sql_args ) );

			if ( empty( $products ) ) {
				break;
			}

			foreach ( $products as $product ) {
				$all_products[] = array(
					'object_id'   => (int) $product->ID,
					'object_type' => $product->post_type,
					'parent_id'   => (int) $product->post_parent,
					'sku'         => $product->sku ? $product->sku : '',
					'name'        => $product->post_title ? $product->post_title : '',
				);
			}

			$offset += $batch_size;
		}

		// SKU comparison options from settings
		$sku_options = array(
			'trim_whitespace'  => $trim_ws,
			'case_insensitive' => $case_insens,
		);

		// --- Detect SKU duplicates ---
		$sku_groups = array();

		foreach ( $all_products as $product ) {
			$sku = $product['sku'];
			if ( '' === $sku ) {
				continue;
			}

			$normalized = SKUSN_Sku_Normalizer::create_comparison_key( $sku, $sku_options );

			if ( 'empty-sku' === $normalized ) {
				continue;
			}

			if ( ! isset( $sku_groups[ $normalized ] ) ) {
				$sku_groups[ $normalized ] = array();
			}

			$sku_groups[ $normalized ][] = $product;
		}

		$group_count = 0;
		$item_count  = 0;

		foreach ( $sku_groups as $key => $items ) {
			if ( count( $items ) < 2 ) {
				continue;
			}

			$group_id = $this->insert_group( $scan_id, 'sku', $key, $items[0]['sku'], $items );
			if ( $group_id ) {
				++$group_count;
				$item_count += count( $items );
			}
		}

		// --- Detect product name duplicates ---
		$name_groups = array();

		foreach ( $all_products as $product ) {
			$name = $product['name'];
			if ( '' === $name ) {
				continue;
			}

			$normalized = strtolower( trim( $name ) );

			if ( ! isset( $name_groups[ $normalized ] ) ) {
				$name_groups[ $normalized ] = array();
			}

			$name_groups[ $normalized ][] = $product;
		}

		foreach ( $name_groups as $key => $items ) {
			if ( count( $items ) < 2 ) {
				continue;
			}

			$group_id = $this->insert_group( $scan_id, 'name', $key, $items[0]['name'], $items );
			if ( $group_id ) {
				++$group_count;
				$item_count += count( $items );
			}
		}

		$this->db->update_scan(
			$scan_id,
			array(
				'status'                => 'completed',
				'completed_at'          => current_time( 'mysql' ),
				'processed_count'       => $total,
				'duplicate_group_count' => $group_count,
				'affected_item_count'   => $item_count,
			)
		);

		$this->db->flush_cache();
	}

	/**
	 * Insert a duplicate group and its items.
	 */
	protected function insert_group( $scan_id, $type, $comparison_key, $display_value, $items ) {
		$group_id = $this->db->insert_group(
			array(
				'scan_id'        => $scan_id,
				'duplicate_type' => $type,
				'comparison_key' => $comparison_key,
				'display_value'  => $display_value,
				'item_count'     => count( $items ),
				'status'         => 'open',
			)
		);

		if ( ! $group_id ) {
			return false;
		}

		foreach ( $items as $item ) {
			$this->db->insert_item(
				array(
					'group_id'          => $group_id,
					'object_id'         => $item['object_id'],
					'object_type'       => $item['object_type'],
					'parent_id'         => $item['parent_id'] ? $item['parent_id'] : null,
					'original_value'    => 'sku' === $type ? $item['sku'] : $item['name'],
					'normalized_value'  => $comparison_key,
					'resolution_status' => 'pending',
				)
			);
		}

		return $group_id;
	}

	/**
	 * Mark scan as completed with zero results.
	 */
	protected function complete_scan( $scan_id, $total ) {
		$this->db->update_scan(
			$scan_id,
			array(
				'status'                => 'completed',
				'completed_at'          => current_time( 'mysql' ),
				'total_count'           => $total,
				'processed_count'       => 0,
				'duplicate_group_count' => 0,
				'affected_item_count'   => 0,
			)
		);

		$this->db->flush_cache();
	}
}

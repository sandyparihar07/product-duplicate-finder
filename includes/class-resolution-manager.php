<?php
/**
 * SKUSN Resolution Manager class
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Resolution_Manager {
	protected $db;

	public function __construct( SKUSN_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Move selected products to Trash and clean up duplicate group entries.
	 *
	 * @param array $selected_ids Product IDs to trash.
	 * @return array Results with success/errors.
	 */
	public function move_to_trash( $selected_ids ) {
		global $wpdb;

		$results = array(
			'success' => array(),
			'errors'  => array(),
			'count'   => 0,
		);

		$trashed_ids = array();

		foreach ( $selected_ids as $product_id ) {
			$product_id = (int) $product_id;

			if ( $product_id <= 0 ) {
				continue;
			}

			$post = get_post( $product_id );
			if ( ! $post ) {
				$results['errors'][] = array(
					'product_id' => $product_id,
					'message'    => __( 'Post not found.', 'product-duplicate-finder' ),
				);
				continue;
			}

			if ( 'trash' === $post->post_status ) {
				$results['errors'][] = array(
					'product_id' => $product_id,
					'message'    => __( 'Already in Trash.', 'product-duplicate-finder' ),
				);
				continue;
			}

			$trashed = wp_trash_post( $product_id );

			if ( $trashed ) {
				$trashed_ids[]        = $product_id;
				$results['success'][] = $product_id;
				++$results['count'];
			} else {
				$results['errors'][] = array(
					'product_id' => $product_id,
					'message'    => __( 'Failed to move to Trash.', 'product-duplicate-finder' ),
				);
			}
		}

		if ( ! empty( $trashed_ids ) ) {
			$this->cleanup_duplicate_entries( $trashed_ids );
		}

		return $results;
	}

	/**
	 * Remove trashed product entries from duplicate groups and clean up empty groups.
	 *
	 * @param array $product_ids Product IDs that were trashed.
	 */
	protected function cleanup_duplicate_entries( $product_ids ) {
		global $wpdb;

		$items_table  = $this->db->table_names['items'];
		$groups_table = $this->db->table_names['groups'];
		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

		$wpdb->query( "DELETE FROM {$items_table} WHERE object_id IN ({$placeholders})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; placeholders pre-expanded via array_fill with %d; values are int-cast product IDs.

		$orphan_groups = $wpdb->get_col( "SELECT g.id FROM {$groups_table} g LEFT JOIN {$items_table} i ON g.id = i.group_id WHERE i.id IS NULL" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; plugin uses custom database tables.

		if ( ! empty( $orphan_groups ) ) {
			$group_placeholders = implode( ',', array_fill( 0, count( $orphan_groups ), '%d' ) );
			$wpdb->query( "DELETE FROM {$groups_table} WHERE id IN ({$group_placeholders})" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; placeholders pre-expanded via array_fill.
		}

		$this->db->flush_cache();
	}
}

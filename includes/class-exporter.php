<?php
/**
 * SKUSN Exporter class
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Exporter {
	protected $db;

	public function __construct( SKUSN_Database $db ) {
		$this->db = $db;
	}

	/**
	 * Export scan results as CSV.
	 *
	 * @param int $scan_id Scan ID.
	 * @return true Exits after output.
	 */
	public function export_csv( $scan_id ) {
		$scan_id = (int) $scan_id;

		$scan = $this->db->get_scan_by_id( $scan_id );

		if ( ! $scan ) {
			return new WP_Error( 'scan_not_found', __( 'Scan not found.', 'product-duplicate-finder' ) );
		}

		$groups = $this->db->get_groups( $scan_id );

		$header = array(
			'Group ID',
			'SKU',
			'Product ID',
			'Type',
			'Parent ID',
			'Product Name',
			'Price',
			'Stock',
			'Stock Status',
			'Order Count',
			'Edit URL',
		);

		$rows   = array();
		$rows[] = implode( ',', array_map( array( $this, 'csv_escape' ), $header ) );

		foreach ( $groups as $group ) {
			$items = $this->db->get_items( $group->id );

			foreach ( $items as $item ) {
				$product    = wc_get_product( $item->object_id );
				$name       = $product ? $product->get_name() : '';
				$price      = $product ? $product->get_price() : '';
				$stock      = $product ? $product->get_stock_quantity() : '';
				$stock_stat = $product ? $product->get_stock_status() : '';
				$order_cnt  = $this->count_product_orders( $item->object_id );
				$edit_url   = get_edit_post_link( $item->object_id, 'raw' );

				$rows[] = implode(
					',',
					array_map(
						array( $this, 'csv_escape' ),
						array(
							$group->id,
							$item->original_value,
							$item->object_id,
							$item->object_type,
							$item->parent_id,
							$name,
							$price,
							$stock,
							$stock_stat,
							$order_cnt,
							$edit_url,
						)
					)
				);
			}
		}

		$filename = 'sku-sentinel-scan-' . $scan_id . '-' . gmdate( 'Y-m-d' ) . '.csv';

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV export, data escaped via csv_escape().
		echo implode( "\n", $rows );
		exit;
	}

	protected function csv_escape( $value ) {
		if ( ! is_string( $value ) ) {
			return $value;
		}
		if ( preg_match( '/^[=+\@\#\-\-]/', $value ) ) {
			$value = "'" . $value;
		}
		if ( str_contains( $value, ',' ) || str_contains( $value, "\n" ) || str_contains( $value, '"' ) ) {
			$value = '"' . str_replace( '"', '""', $value ) . '"';
		}
		return $value;
	}

	protected function count_product_orders( $product_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table names safely built from $wpdb->prefix; plugin uses custom database tables.
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
			FROM {$wpdb->posts} o
			INNER JOIN {$wpdb->postmeta} pm ON o.ID = pm.post_id
			WHERE o.post_type = 'shop_order'
			AND o.post_status NOT IN ('trash', 'auto-draft')
			AND pm.meta_key = '_product_id'
			AND pm.meta_value = %d",
				$product_id
			)
		);

		return $count ? (int) $count : 0;
	}
}

<?php
/**
 * Results View
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$skusn_db = new SKUSN_Database();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no data modification.
$skusn_scan_id = isset( $_GET['scan_id'] ) ? absint( wp_unslash( $_GET['scan_id'] ) ) : 0;

// No scan_id — show list of recent scans
if ( ! $skusn_scan_id ) {
	$skusn_scans = $skusn_db->get_scans( 20 );
	?>
	<div class="wrap skusn-wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Scan Results', 'product-duplicate-finder' ); ?></h1>
		<hr class="wp-header-end">

		<?php if ( empty( $skusn_scans ) ) : ?>
			<div class="notice notice-info"><p><?php esc_html_e( 'No scans found. Run a scan from the dashboard.', 'product-duplicate-finder' ); ?></p></div>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=product-duplicate-finder' ) ); ?>" class="button button-primary button-hero">
					<?php esc_html_e( 'Go to Dashboard', 'product-duplicate-finder' ); ?>
				</a>
			</p>
		<?php else : ?>
			<table class="widefat striped skusn-table">
				<thead>
					<tr>
						<th class="col-id"><?php esc_html_e( 'ID', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Status', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Products Checked', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Duplicates Found', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Date', 'product-duplicate-finder' ); ?></th>
						<th class="col-actions"><?php esc_html_e( 'Actions', 'product-duplicate-finder' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $skusn_scans as $skusn_s ) : ?>
						<tr>
							<td class="col-id"><?php echo esc_html( $skusn_s->id ); ?></td>
							<td>
								<span class="skusn-status skusn-status-<?php echo esc_attr( $skusn_s->status ); ?>">
									<?php echo esc_html( ucfirst( $skusn_s->status ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( number_format_i18n( $skusn_s->processed_count ) ); ?></td>
							<td>
								<?php if ( $skusn_s->duplicate_group_count > 0 ) : ?>
									<strong><?php echo esc_html( $skusn_s->duplicate_group_count ); ?></strong>
								<?php else : ?>
									<?php esc_html_e( '0', 'product-duplicate-finder' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $skusn_s->started_at ); ?></td>
							<td class="col-actions">
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=sku-sentinel-results&scan_id=' . $skusn_s->id ) ); ?>" class="button button-small button-primary">
									<?php esc_html_e( 'View Results', 'product-duplicate-finder' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
	return;
}

// Single scan results
$skusn_scan = $skusn_db->get_scan_by_id( $skusn_scan_id );

if ( ! $skusn_scan ) {
	echo '<div class="wrap"><div class="notice notice-error"><p>' . esc_html__( 'Scan not found.', 'product-duplicate-finder' ) . '</p></div></div>';
	return;
}

$skusn_groups = $skusn_db->get_groups( $skusn_scan_id );

$skusn_csv_url = wp_nonce_url(
	admin_url( 'admin.php?page=sku-sentinel-export&scan_id=' . $skusn_scan_id . '&format=csv' ),
	'skusn_export_' . $skusn_scan_id
);
?>
<div class="wrap skusn-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Scan Results', 'product-duplicate-finder' ); ?></h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=sku-sentinel-results' ) ); ?>" class="page-title-action"><?php esc_html_e( 'All Scans', 'product-duplicate-finder' ); ?></a>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=product-duplicate-finder' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Dashboard', 'product-duplicate-finder' ); ?></a>
	<hr class="wp-header-end">

	<div class="skusn-card">
		<h2><?php esc_html_e( 'Scan Summary', 'product-duplicate-finder' ); ?></h2>
		<div class="skusn-summary-grid">
			<div class="skusn-summary-item">
				<span class="skusn-summary-label"><?php esc_html_e( 'Status', 'product-duplicate-finder' ); ?></span>
				<span class="skusn-status skusn-status-<?php echo esc_attr( $skusn_scan->status ); ?>"><?php echo esc_html( ucfirst( $skusn_scan->status ) ); ?></span>
			</div>
			<div class="skusn-summary-item">
				<span class="skusn-summary-label"><?php esc_html_e( 'Products Checked', 'product-duplicate-finder' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $skusn_scan->processed_count ) ); ?></strong>
			</div>
			<div class="skusn-summary-item">
				<span class="skusn-summary-label"><?php esc_html_e( 'Duplicate Groups', 'product-duplicate-finder' ); ?></span>
				<strong><?php echo esc_html( $skusn_scan->duplicate_group_count ); ?></strong>
			</div>
			<div class="skusn-summary-item">
				<span class="skusn-summary-label"><?php esc_html_e( 'Affected Products', 'product-duplicate-finder' ); ?></span>
				<strong><?php echo esc_html( number_format_i18n( $skusn_scan->affected_item_count ) ); ?></strong>
			</div>
			<div class="skusn-summary-item">
				<span class="skusn-summary-label"><?php esc_html_e( 'Date', 'product-duplicate-finder' ); ?></span>
				<strong><?php echo esc_html( $skusn_scan->started_at ); ?></strong>
			</div>
		</div>
	</div>

	<?php if ( ! empty( $skusn_groups ) ) : ?>

		<div class="skusn-card">
			<div class="skusn-card-header">
				<h2><?php esc_html_e( 'Duplicate Groups', 'product-duplicate-finder' ); ?></h2>
				<div class="skusn-card-actions">
					<button type="button" class="button button-large skusn-btn-trash" id="skusn-bulk-trash"><?php esc_html_e( 'Move to Trash', 'product-duplicate-finder' ); ?></button>
					<a href="<?php echo esc_url( $skusn_csv_url ); ?>" class="button button-large skusn-btn-export"><?php esc_html_e( 'Export CSV', 'product-duplicate-finder' ); ?></a>
				</div>
			</div>
			<p class="skusn-card-desc">
			<?php
			printf(
				/* translators: 1: number of duplicate groups, 2: number of affected products */
				esc_html( _n( 'Found %1$d duplicate group across %2$d product', 'Found %1$d duplicate groups across %2$d products', count( $skusn_groups ), 'product-duplicate-finder' ) ),
				(int) count( $skusn_groups ),
				(int) $skusn_scan->affected_item_count
			);
			?>
			</p>

			<?php foreach ( $skusn_groups as $skusn_group ) : ?>
				<?php
				$skusn_items = $skusn_db->get_items( $skusn_group->id );
				?>
				<div class="skusn-group" data-group-id="<?php echo esc_attr( $skusn_group->id ); ?>">
					<div class="skusn-group-header">
						<h3>
							<span class="skusn-tag skusn-tag-<?php echo esc_attr( $skusn_group->duplicate_type ); ?>">
								<?php echo esc_html( 'sku' === $skusn_group->duplicate_type ? __( 'SKU', 'product-duplicate-finder' ) : __( 'Name', 'product-duplicate-finder' ) ); ?>
							</span>
							<strong><?php echo esc_html( $skusn_group->display_value ); ?></strong>
							<span class="skusn-status skusn-status-<?php echo esc_attr( $skusn_group->status ); ?>">
								<?php echo esc_html( ucfirst( $skusn_group->status ) ); ?>
							</span>
							<span class="skusn-group-count">
								<?php
								printf(
									/* translators: %d: number of products in the duplicate group */
									esc_html( _n( '%d product', '%d products', $skusn_group->item_count, 'product-duplicate-finder' ) ),
									(int) $skusn_group->item_count
								);
								?>
							</span>
						</h3>
					</div>

					<table class="widefat skusn-table">
						<thead>
							<tr>
								<th class="check-column"><input type="checkbox" class="skusn-group-check" data-group="<?php echo esc_attr( $skusn_group->id ); ?>"></th>
								<th class="col-id"><?php esc_html_e( 'ID', 'product-duplicate-finder' ); ?></th>
								<th><?php esc_html_e( 'Type', 'product-duplicate-finder' ); ?></th>
								<th><?php esc_html_e( 'SKU', 'product-duplicate-finder' ); ?></th>
								<th><?php esc_html_e( 'Product Name', 'product-duplicate-finder' ); ?></th>
								<th class="col-actions"><?php esc_html_e( 'Actions', 'product-duplicate-finder' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $skusn_items as $skusn_item ) : ?>
								<?php
								$skusn_product      = wc_get_product( $skusn_item->object_id );
								$skusn_product_sku  = $skusn_product ? $skusn_product->get_sku() : '';
								$skusn_product_name = $skusn_product ? $skusn_product->get_name() : get_the_title( $skusn_item->object_id );
								?>
								<tr data-id="<?php echo esc_attr( $skusn_item->object_id ); ?>" data-group="<?php echo esc_attr( $skusn_group->id ); ?>">
									<td class="check-column"><input type="checkbox" class="skusn-item-check" name="selected_ids[]" value="<?php echo esc_attr( $skusn_item->object_id ); ?>" data-group="<?php echo esc_attr( $skusn_group->id ); ?>"></td>
									<td class="col-id"><?php echo esc_html( $skusn_item->object_id ); ?></td>
									<td><?php echo esc_html( ucfirst( str_replace( 'product_', '', $skusn_item->object_type ) ) ); ?></td>
									<td>
										<?php if ( $skusn_product_sku ) : ?>
											<code class="skusn-code"><?php echo esc_html( $skusn_product_sku ); ?></code>
										<?php else : ?>
											<span class="skusn-empty">&mdash;</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $skusn_product_name ) : ?>
											<a href="<?php echo esc_url( get_edit_post_link( $skusn_item->object_id ) ); ?>" target="_blank"><?php echo esc_html( $skusn_product_name ); ?></a>
										<?php else : ?>
											<span class="skusn-empty">&mdash;</span>
										<?php endif; ?>
									</td>
									<td class="col-actions">
										<a href="<?php echo esc_url( get_edit_post_link( $skusn_item->object_id ) ); ?>" class="button button-small" target="_blank">
											<?php esc_html_e( 'Edit', 'product-duplicate-finder' ); ?>
										</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
		</div>
	<?php else : ?>
		<div class="skusn-card skusn-card-empty">
			<div class="skusn-empty-icon">&#10003;</div>
			<h2><?php esc_html_e( 'No Duplicates Found', 'product-duplicate-finder' ); ?></h2>
			<p><?php esc_html_e( 'No duplicate products were found in this scan. Your catalog looks clean!', 'product-duplicate-finder' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=product-duplicate-finder' ) ); ?>" class="button button-primary button-hero">
					<?php esc_html_e( 'Run Another Scan', 'product-duplicate-finder' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>
</div>

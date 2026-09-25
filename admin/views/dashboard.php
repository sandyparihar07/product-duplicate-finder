<?php
/**
 * Dashboard View
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;
$skusn_settings          = sku_sentinel_get_settings();
$skusn_db                = new SKUSN_Database();
$skusn_stats             = $skusn_db->get_stats();
$skusn_total_scans       = $skusn_stats['total_scans'];
$skusn_completed         = $skusn_stats['completed'];
$skusn_dupes_found       = $skusn_stats['dupes_found'];
$skusn_products_affected = $skusn_stats['products_affected'];

$skusn_recent_scans = $skusn_db->get_scans( 10 );
?>
<div class="wrap skusn-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Duplicate Product Finder', 'product-duplicate-finder' ); ?></h1>
	<hr class="wp-header-end">

	<?php if ( ! SKUSN_Dependencies::is_woocommerce_available() ) : ?>
		<div class="notice notice-error"><p><?php esc_html_e( 'WooCommerce is required for this plugin. Please install and activate WooCommerce.', 'product-duplicate-finder' ); ?></p></div>
	<?php endif; ?>

	<div class="skusn-dashboard-grid">
		<div class="skusn-stat-card">
			<div class="skusn-stat-number"><?php echo esc_html( $skusn_total_scans ); ?></div>
			<div class="skusn-stat-label"><?php esc_html_e( 'Total Scans', 'product-duplicate-finder' ); ?></div>
		</div>
		<div class="skusn-stat-card">
			<div class="skusn-stat-number"><?php echo esc_html( $skusn_completed ); ?></div>
			<div class="skusn-stat-label"><?php esc_html_e( 'Completed', 'product-duplicate-finder' ); ?></div>
		</div>
		<div class="skusn-stat-card">
			<div class="skusn-stat-number"><?php echo esc_html( $skusn_dupes_found ); ?></div>
			<div class="skusn-stat-label"><?php esc_html_e( 'Duplicate Groups', 'product-duplicate-finder' ); ?></div>
		</div>
		<div class="skusn-stat-card">
			<div class="skusn-stat-number"><?php echo esc_html( $skusn_products_affected ); ?></div>
			<div class="skusn-stat-label"><?php esc_html_e( 'Affected Products', 'product-duplicate-finder' ); ?></div>
		</div>
	</div>

	<div class="skusn-card">
		<h2><?php esc_html_e( 'Run New Scan', 'product-duplicate-finder' ); ?></h2>

		<form id="skusn-scan-form">
			<?php wp_nonce_field( 'skusn_nonce', 'skusn_nonce_field' ); ?>

			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Scan Scope', 'product-duplicate-finder' ); ?></th>
					<td>
						<fieldset>
							<legend class="screen-reader-text"><?php esc_html_e( 'Scan Scope', 'product-duplicate-finder' ); ?></legend>
						<label>
							<input type="checkbox" name="include_variations" value="1" <?php checked( ! empty( $skusn_settings['include_variations'] ) ); ?>>
							<?php esc_html_e( 'Include product variations', 'product-duplicate-finder' ); ?>
						</label>
						<br>
						<label>
							<input type="checkbox" name="include_trashed" value="1" <?php checked( ! empty( $skusn_settings['include_trashed'] ) ); ?>>
							<?php esc_html_e( 'Include trashed products', 'product-duplicate-finder' ); ?>
						</label>
						</fieldset>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary button-hero" id="skusn-start-scan">
					<?php esc_html_e( 'Run Scan', 'product-duplicate-finder' ); ?>
				</button>
				<span id="skusn-scan-status" style="margin-left: 15px; display: none;"></span>
			</p>
		</form>
	</div>

	<div class="skusn-card">
		<h2><?php esc_html_e( 'Recent Scans', 'product-duplicate-finder' ); ?></h2>

		<?php if ( empty( $skusn_recent_scans ) ) : ?>
			<p class="skusn-card-desc"><?php esc_html_e( 'No scans yet. Run your first scan above.', 'product-duplicate-finder' ); ?></p>
		<?php else : ?>
			<table class="widefat striped skusn-table">
				<thead>
					<tr>
						<th class="col-id"><?php esc_html_e( 'ID', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Status', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Products Checked', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Duplicates', 'product-duplicate-finder' ); ?></th>
						<th><?php esc_html_e( 'Date', 'product-duplicate-finder' ); ?></th>
						<th class="col-actions"><?php esc_html_e( 'Actions', 'product-duplicate-finder' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $skusn_recent_scans as $skusn_scan ) : ?>
						<tr>
							<td class="col-id"><?php echo esc_html( $skusn_scan->id ); ?></td>
							<td>
								<span class="skusn-status skusn-status-<?php echo esc_attr( $skusn_scan->status ); ?>">
									<?php echo esc_html( ucfirst( $skusn_scan->status ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( number_format_i18n( $skusn_scan->processed_count ) ); ?></td>
							<td>
								<?php if ( $skusn_scan->duplicate_group_count > 0 ) : ?>
									<strong><?php echo esc_html( $skusn_scan->duplicate_group_count ); ?></strong>
								<?php else : ?>
									<?php esc_html_e( '0', 'product-duplicate-finder' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $skusn_scan->started_at ); ?></td>
							<td class="col-actions">
								<?php if ( $skusn_scan->duplicate_group_count > 0 ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=sku-sentinel-results&scan_id=' . $skusn_scan->id ) ); ?>" class="button button-small button-primary">
										<?php esc_html_e( 'View', 'product-duplicate-finder' ); ?>
									</a>
								<?php else : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=sku-sentinel-results&scan_id=' . $skusn_scan->id ) ); ?>" class="button button-small">
										<?php esc_html_e( 'View', 'product-duplicate-finder' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

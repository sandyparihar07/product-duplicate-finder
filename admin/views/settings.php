<?php
/**
 * Settings View
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$skusn_settings   = sku_sentinel_get_settings();
$skusn_categories = get_terms(
	array(
		'taxonomy'   => 'product_cat',
		'hide_empty' => false,
	)
);
if ( is_wp_error( $skusn_categories ) ) {
	$skusn_categories = array();
}
?>
<div class="wrap skusn-wrap">
	<h1 class="wp-heading-inline"><?php esc_html_e( 'Duplicate Product Finder Settings', 'product-duplicate-finder' ); ?></h1>
	<hr class="wp-header-end">

	<div id="skusn-settings-message" class="notice" style="display: none;"></div>

	<form id="skusn-settings-form">
		<?php wp_nonce_field( 'skusn_nonce', 'skusn_nonce_field' ); ?>

		<div class="skusn-card">
			<h2><?php esc_html_e( 'General Settings', 'product-duplicate-finder' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Include Variations', 'product-duplicate-finder' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="include_variations" value="1" <?php checked( ! empty( $skusn_settings['include_variations'] ) ); ?>>
							<?php esc_html_e( 'Include product variations in scans', 'product-duplicate-finder' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, product variations will be scanned for duplicate SKUs and names along with parent products.', 'product-duplicate-finder' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Include Trashed', 'product-duplicate-finder' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="include_trashed" value="1" <?php checked( ! empty( $skusn_settings['include_trashed'] ) ); ?>>
							<?php esc_html_e( 'Include trashed products in scans', 'product-duplicate-finder' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'When enabled, products in the trash will also be scanned.', 'product-duplicate-finder' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="skusn-card">
			<h2><?php esc_html_e( 'SKU Comparison', 'product-duplicate-finder' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Trim Whitespace', 'product-duplicate-finder' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="trim_whitespace" value="1" <?php checked( ! empty( $skusn_settings['trim_whitespace'] ) ); ?>>
							<?php esc_html_e( 'Trim leading and trailing whitespace from SKUs before comparison', 'product-duplicate-finder' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Case Insensitive', 'product-duplicate-finder' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="case_insensitive" value="1" <?php checked( ! empty( $skusn_settings['case_insensitive'] ) ); ?>>
							<?php esc_html_e( 'Compare SKUs without considering uppercase/lowercase differences', 'product-duplicate-finder' ); ?>
						</label>
					</td>
				</tr>
			</table>
		</div>

		<div class="skusn-card">
			<h2><?php esc_html_e( 'Safety Settings', 'product-duplicate-finder' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Default Action', 'product-duplicate-finder' ); ?></th>
					<td>
						<select name="default_action">
							<option value="trash" <?php selected( $skusn_settings['default_action'], 'trash' ); ?>><?php esc_html_e( 'Move to Trash', 'product-duplicate-finder' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Default action when resolving duplicate products.', 'product-duplicate-finder' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<div class="skusn-card">
			<h2><?php esc_html_e( 'Exclusions', 'product-duplicate-finder' ); ?></h2>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Excluded Categories', 'product-duplicate-finder' ); ?></th>
					<td>
						<select name="excluded_categories[]" multiple style="min-height: 120px;">
							<?php foreach ( $skusn_categories as $skusn_cat ) : ?>
								<option value="<?php echo esc_attr( $skusn_cat->term_id ); ?>"
									<?php selected( in_array( $skusn_cat->term_id, (array) $skusn_settings['excluded_categories'], true ) ); ?>>
									<?php echo esc_html( $skusn_cat->name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple categories to exclude from scans.', 'product-duplicate-finder' ); ?></p>
					</td>
				</tr>
			</table>
		</div>

		<?php submit_button( __( 'Save Settings', 'product-duplicate-finder' ), 'primary', 'skusn-save-settings' ); ?>
	</form>
</div>

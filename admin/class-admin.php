<?php
/**
 * SKUSN Admin class
 *
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SKUSN_Admin {
	protected $db;

	public function __construct() {
		$this->db = new SKUSN_Database();

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_scripts' ) );
		add_action( 'admin_footer', array( $this, 'admin_footer_scripts' ) );

		add_action( 'wp_ajax_skusn_start_scan', array( $this, 'handle_start_scan' ) );
		add_action( 'wp_ajax_skusn_resolve', array( $this, 'handle_resolve' ) );
		add_action( 'wp_ajax_skusn_save_settings', array( $this, 'handle_save_settings' ) );
	}

	public function admin_scripts( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no data modification.
		$page                = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$skusn_allowed_pages = array( 'product-duplicate-finder', 'sku-sentinel-settings', 'sku-sentinel-results', 'sku-sentinel-export' );
		if ( ! in_array( $page, $skusn_allowed_pages, true ) ) {
			return;
		}

		$plugin_url = plugin_dir_url( SKUSN_PLUGIN_FILE );

		wp_enqueue_style( 'skusn-admin', $plugin_url . 'admin/css/admin.css', array(), SKUSN_VERSION );
		wp_enqueue_script( 'skusn-admin', $plugin_url . 'admin/js/admin.js', array( 'jquery' ), SKUSN_VERSION, true );

		wp_localize_script(
			'skusn-admin',
			'skusn',
			array(
				'ajax_url'    => admin_url( 'admin-ajax.php' ),
				'results_url' => admin_url( 'admin.php?page=sku-sentinel-results' ),
				'nonce'       => wp_create_nonce( 'skusn_nonce' ),
				'i18n'        => array(
					'confirm_scan'   => __( 'Start a new duplicate scan?', 'product-duplicate-finder' ),
					'confirm_trash'  => __( 'Move selected products to Trash?', 'product-duplicate-finder' ),
					'no_selection'   => __( 'Please select at least one product.', 'product-duplicate-finder' ),
					'scanning'       => __( 'Scanning...', 'product-duplicate-finder' ),
					'error'          => __( 'An error occurred.', 'product-duplicate-finder' ),
					'settings_saved' => __( 'Settings saved.', 'product-duplicate-finder' ),
				),
			)
		);
	}

	public function admin_footer_scripts() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display, no data modification.
		$page                = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		$skusn_allowed_pages = array( 'product-duplicate-finder', 'sku-sentinel-settings', 'sku-sentinel-results', 'sku-sentinel-export' );
		if ( ! in_array( $page, $skusn_allowed_pages, true ) ) {
			return;
		}
		?>
		<script type="text/javascript">
		if(typeof skusn === 'undefined'){
			window.skusn = {
				ajax_url: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				results_url: <?php echo wp_json_encode( admin_url( 'admin.php?page=sku-sentinel-results' ) ); ?>,
				nonce: <?php echo wp_json_encode( wp_create_nonce( 'skusn_nonce' ) ); ?>,
				i18n: {
					confirm_scan: <?php echo wp_json_encode( __( 'Start a new duplicate scan?', 'product-duplicate-finder' ) ); ?>,
					confirm_trash: <?php echo wp_json_encode( __( 'Move selected products to Trash?', 'product-duplicate-finder' ) ); ?>,
					no_selection: <?php echo wp_json_encode( __( 'Please select at least one product.', 'product-duplicate-finder' ) ); ?>,
					scanning: <?php echo wp_json_encode( __( 'Scanning...', 'product-duplicate-finder' ) ); ?>,
					error: <?php echo wp_json_encode( __( 'An error occurred.', 'product-duplicate-finder' ) ); ?>,
					settings_saved: <?php echo wp_json_encode( __( 'Settings saved.', 'product-duplicate-finder' ) ); ?>
				}
			};
		}
		(function($){
			$(document).on('click', '#skusn-bulk-trash', function(){
				var ids = [];
				$('.skusn-item-check:checked').each(function(){
					ids.push($(this).val());
				});
				if(ids.length === 0){
					alert(skusn.i18n.no_selection);
					return;
				}
				if(!confirm(skusn.i18n.confirm_trash)){
					return;
				}
				var $btn = $(this);
				$btn.prop('disabled', true).text('...');
				$.post(skusn.ajax_url, {
					action: 'skusn_resolve',
					security: skusn.nonce,
					resolve_action: 'trash',
					selected_ids: ids
			}, function(response) {
				if(response.success){
					window.location.reload();
				} else {
						alert(response.data ? response.data.message : skusn.i18n.error);
						$btn.prop('disabled', false).text('Move to Trash');
					}
				}).fail(function(){
					alert(skusn.i18n.error);
					$btn.prop('disabled', false).text('Move to Trash');
				});
			});
			$(document).on('change', '.skusn-group-check', function(){
				var g = $(this).data('group');
				$('.skusn-item-check[data-group="'+g+'"]').prop('checked', $(this).is(':checked'));
			});
			$(document).on('change', '.skusn-item-check', function(){
				var g = $(this).data('group');
				var t = $('.skusn-item-check[data-group="'+g+'"]').length;
				var c = $('.skusn-item-check[data-group="'+g+'"]:checked').length;
				$('.skusn-group-check[data-group="'+g+'"]').prop('checked', t === c && t > 0);
			});
		})(jQuery);
		</script>
		<?php
	}

	public function handle_start_scan() {
		check_ajax_referer( 'skusn_nonce', 'security' );

		if ( ! current_user_can( SKUSN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'No permission.', 'product-duplicate-finder' ) ) );
		}

		$scope = array(
			'include_variations' => ! empty( $_POST['include_variations'] ),
			'include_trashed'    => ! empty( $_POST['include_trashed'] ),
		);

		try {
			$detector = new SKUSN_Duplicate_Detector( $this->db, new SKUSN_Sku_Normalizer() );
			$scan_id  = $detector->start_scan( array( 'scope' => $scope ) );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		if ( is_wp_error( $scan_id ) ) {
			wp_send_json_error( array( 'message' => $scan_id->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'scan_id' => (int) $scan_id,
				'message' => __( 'Scan completed.', 'product-duplicate-finder' ),
			)
		);
	}

	public function handle_resolve() {
		check_ajax_referer( 'skusn_nonce', 'security' );

		if ( ! current_user_can( SKUSN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'No permission.', 'product-duplicate-finder' ) ) );
		}

		$action       = isset( $_POST['resolve_action'] ) ? sanitize_text_field( wp_unslash( $_POST['resolve_action'] ) ) : '';
		$selected_ids = isset( $_POST['selected_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['selected_ids'] ) ) : array();
		$selected_ids = array_filter( $selected_ids );

		if ( 'trash' !== $action ) {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'product-duplicate-finder' ) ) );
		}

		if ( empty( $selected_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No products selected.', 'product-duplicate-finder' ) ) );
		}

		$resolution = new SKUSN_Resolution_Manager( $this->db );
		$results    = $resolution->move_to_trash( $selected_ids );

		wp_send_json_success( $results );
	}

	public function handle_save_settings() {
		check_ajax_referer( 'skusn_nonce', 'security' );

		if ( ! current_user_can( SKUSN_CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'No permission.', 'product-duplicate-finder' ) ) );
		}

		$settings = array(
			'include_variations'  => ! empty( $_POST['include_variations'] ),
			'include_trashed'     => ! empty( $_POST['include_trashed'] ),
			'trim_whitespace'     => ! empty( $_POST['trim_whitespace'] ),
			'case_insensitive'    => ! empty( $_POST['case_insensitive'] ),
			'default_action'      => isset( $_POST['default_action'] ) ? sanitize_text_field( wp_unslash( $_POST['default_action'] ) ) : 'trash',
			'excluded_categories' => isset( $_POST['excluded_categories'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['excluded_categories'] ) ) : array(),
		);

		update_option( 'skusn_settings', $settings );

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'product-duplicate-finder' ) ) );
	}
}

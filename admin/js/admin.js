(function($) {
	'use strict';

	var skusnApp = {

		init: function() {
			this.bindScanForm();
			this.bindSettingsForm();
			this.bindCheckboxes();
			this.bindBulkTrash();
		},

		bindScanForm: function() {
			$('#skusn-scan-form').on('submit', function(e) {
				e.preventDefault();

				if (!confirm(skusn.i18n.confirm_scan)) {
					return;
				}

				var $btn = $('#skusn-start-scan');
				var $status = $('#skusn-scan-status');

				$btn.prop('disabled', true).addClass('skusn-loading');
				$status.show().html('<span class="skusn-loading"></span> ' + skusn.i18n.scanning);

				$.post(skusn.ajax_url, {
					action: 'skusn_start_scan',
					security: skusn.nonce,
					include_variations: $('input[name="include_variations"]').is(':checked') ? 1 : 0,
					include_trashed: $('input[name="include_trashed"]').is(':checked') ? 1 : 0
				}, function(response) {
					$btn.prop('disabled', false).removeClass('skusn-loading');

					if (response.success) {
						$status.html('<span class="skusn-check">&#10004;</span> ' + response.data.message);
						if (response.data.scan_id) {
							window.location.href = skusn.results_url + '&scan_id=' + response.data.scan_id;
						}
					} else {
						$status.html('<span class="skusn-cross">&#10007;</span> ' + (response.data.message || skusn.i18n.error));
					}
				}).fail(function() {
					$btn.prop('disabled', false).removeClass('skusn-loading');
					$status.html('<span class="skusn-cross">&#10007;</span> ' + skusn.i18n.error);
				});
			});
		},

		bindSettingsForm: function() {
			$('#skusn-settings-form').on('submit', function(e) {
				e.preventDefault();

				var $btn = $('#skusn-save-settings');
				var $msg = $('#skusn-settings-message');

				$btn.prop('disabled', true).val('Saving...');

				$.post(skusn.ajax_url, {
					action: 'skusn_save_settings',
					security: skusn.nonce,
					include_variations: $('input[name="include_variations"]').is(':checked') ? 1 : 0,
					include_trashed: $('input[name="include_trashed"]').is(':checked') ? 1 : 0,
					trim_whitespace: $('input[name="trim_whitespace"]').is(':checked') ? 1 : 0,
					case_insensitive: $('input[name="case_insensitive"]').is(':checked') ? 1 : 0,
					default_action: $('select[name="default_action"]').val(),
					require_admin_delete: $('input[name="require_admin_delete"]').is(':checked') ? 1 : 0,
					scheduled_scans: $('input[name="scheduled_scans"]').is(':checked') ? 1 : 0,
					excluded_categories: $('select[name="excluded_categories[]"]').val() || []
				}, function(response) {
					$btn.prop('disabled', false).val('Save Settings');
					if (response.success) {
						$msg.show().removeClass('notice-error').addClass('notice-success').html('<p>' + response.data.message + '</p>');
					} else {
						$msg.show().removeClass('notice-success').addClass('notice-error').html('<p>' + (response.data.message || skusn.i18n.error) + '</p>');
					}
					setTimeout(function() { $msg.fadeOut(); }, 3000);
				}).fail(function() {
					$btn.prop('disabled', false).val('Save Settings');
					$msg.show().removeClass('notice-success').addClass('notice-error').html('<p>' + skusn.i18n.error + '</p>');
				});
			});
		},

		bindCheckboxes: function() {
			// Group checkbox — toggle all items in that group
			$(document).on('change', '.skusn-group-check', function() {
				var group = $(this).data('group');
				$('.skusn-item-check[data-group="' + group + '"]').prop('checked', $(this).is(':checked'));
			});

			// Individual item checkbox — update group checkbox state
			$(document).on('change', '.skusn-item-check', function() {
				var group = $(this).data('group');
				var total = $('.skusn-item-check[data-group="' + group + '"]').length;
				var checked = $('.skusn-item-check[data-group="' + group + '"]:checked').length;
				$('.skusn-group-check[data-group="' + group + '"]').prop('checked', total === checked && total > 0);
			});
		},

		bindBulkTrash: function() {
			$('#skusn-bulk-trash').on('click', function() {
				var ids = [];
				$('.skusn-item-check:checked').each(function() {
					ids.push($(this).val());
				});

				if (ids.length === 0) {
					alert(skusn.i18n.no_selection);
					return;
				}

				if (!confirm(skusn.i18n.confirm_trash)) {
					return;
				}

				var $btn = $(this);
				$btn.prop('disabled', true).text('...');

				$.post(skusn.ajax_url, {
					action: 'skusn_resolve',
					security: skusn.nonce,
					resolve_action: 'trash',
					selected_ids: ids,
					group_ids: []
			}, function(response) {
				if (response.success) {
					window.location.reload();
				} else {
						alert(response.data ? response.data.message : skusn.i18n.error);
						$btn.prop('disabled', false).text('Move to Trash');
					}
				}).fail(function() {
					alert(skusn.i18n.error);
					$btn.prop('disabled', false).text('Move to Trash');
				});
			});
		}
	};

	$(document).ready(function() {
		skusnApp.init();
	});

})(jQuery);

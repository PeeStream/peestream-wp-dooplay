/**
 * PeeStream WordPress & DooPlay Addon - Admin Scripts
 * File: assets/js/peestream-admin.js
 */

(function($) {
	'use strict';

	$(document).ready(function() {
		var $btnCheck = $('#pst-btn-check-api');
		var $feedback = $('#pst-api-feedback');
		var $dot = $('.peestream-status-dot');
		var $statusTitle = $('#pst-status-title');
		var $statusDesc = $('#pst-status-desc');


		var $btnRegister = $('#pst-btn-register-site');
		var $siteIdInput = $('#pst_site_id');
		var $secretInput = $('#pst_webhook_secret');
		var $webhookUrlInput = $('#pst_webhook_url');

		$btnRegister.on('click', function(e) {
			e.preventDefault();
			var apiKey = $('#pst_api_key').val().trim();

			if (!apiKey) {
				$feedback.removeClass('peestream-notice-success')
					.addClass('peestream-notice peestream-notice-error')
					.empty()
					.append($('<strong>').text('Error: '))
					.append(document.createTextNode(' Please enter an API key first.'))
					.slideDown();
				return;
			}

			$btnRegister.prop('disabled', true).empty()
				.append($('<span class="dashicons dashicons-update peestream-spin"></span>'))
				.append(document.createTextNode(' Registering...'));
			$feedback.slideUp();

			$.ajax({
				url: peestreamAdminData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_register_site',
					nonce: peestreamAdminData.nonce,
					api_key: apiKey
				},
				success: function(response) {
					$btnRegister.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-networking"></span>'))
						.append(document.createTextNode(' Register Site (V2)'));

					if (response.success && response.data) {
						$dot.addClass('active');
						$statusTitle.text('Site Registered (V2)');
						$statusDesc.empty()
							.append(document.createTextNode('Site ID: '))
							.append($('<strong>').text(response.data.site_id || ''))
							.append(document.createTextNode(' | Webhook URL: '))
							.append($('<strong>').text(response.data.webhook_url || ''));

						$feedback.removeClass('peestream-notice-error')
							.addClass('peestream-notice peestream-notice-success')
							.empty()
							.append($('<strong>').text('Success! '))
							.append(document.createTextNode(response.data.message || ''))
							.slideDown();

						// Update the settings fields with the returned data.
						if ($siteIdInput.length) {
							$siteIdInput.val(response.data.site_id || '');
						}
						if ($webhookUrlInput.length) {
							$webhookUrlInput.val(response.data.webhook_url || '');
						}

						// Trigger a form save so the site_id and webhook_secret persist.
						$('.peestream-settings-form').submit();
					} else {
						var err = (response.data && response.data.message) ? response.data.message : 'Unknown registration error.';
						$dot.removeClass('active');
						$statusTitle.text('Registration Failed');
						$statusDesc.text('Could not register site with PeeStream AutoPublish V2.');

						$feedback.removeClass('peestream-notice-success')
							.addClass('peestream-notice peestream-notice-error')
							.empty()
							.append($('<strong>').text('Error: '))
							.append(document.createTextNode(err))
							.slideDown();
					}
				},
				error: function(xhr, status, error) {
					$btnRegister.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-networking"></span>'))
						.append(document.createTextNode(' Register Site (V2)'));
					$dot.removeClass('active');
					$feedback.removeClass('peestream-notice-success')
						.addClass('peestream-notice peestream-notice-error')
						.empty()
						.append($('<strong>').text('AJAX Error: '))
						.append(document.createTextNode(error))
						.slideDown();
				}
			});
		});
		$btnCheck.on('click', function(e) {
			e.preventDefault();
			var apiKey = $('#pst_api_key').val().trim();

			if (!apiKey) {
				$feedback.removeClass('peestream-notice-success')
					.addClass('peestream-notice peestream-notice-error')
					.empty()
					.append($('<strong>').text('Error: '))
					.append(document.createTextNode(' Please enter an API key first.'))
					.slideDown();
				return;
			}

			$btnCheck.prop('disabled', true).empty()
				.append($('<span class="dashicons dashicons-update peestream-spin"></span>'))
				.append(document.createTextNode(' Connecting...'));
			$feedback.slideUp();

			$.ajax({
				url: peestreamAdminData.ajax_url,
				type: 'POST',
				dataType: 'json',
				data: {
					action: 'peestream_test_api',
					nonce: peestreamAdminData.nonce,
					api_key: apiKey
				},
				success: function(response) {
					$btnCheck.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-update"></span>'))
						.append(document.createTextNode(' Verify API Connection'));
					
					if (response.success && response.data) {
						$dot.addClass('active');
						$statusTitle.text('Connected to PeeStream Cloud');
						$statusDesc.empty()
							.append(document.createTextNode('Account: '))
							.append($('<strong>').text(response.data.email || ''))
							.append(document.createTextNode(' | Balance: '))
							.append($('<strong>').text(response.data.balance || ''))
							.append(document.createTextNode(' | Storage: '))
							.append($('<strong>').text(response.data.storage_used || ''))
							.append(document.createTextNode(' | Tier: '))
							.append($('<strong>').text(response.data.plan_tier || 'Early Adopter Promo (Full Access)'));

						$feedback.removeClass('peestream-notice-error')
							.addClass('peestream-notice peestream-notice-success')
							.empty()
							.append($('<strong>').text('Success! '))
							.append(document.createTextNode(response.data.message || ''))
							.slideDown();
					} else {
						var err = (response.data && response.data.message) ? response.data.message : 'Unknown API error.';
						$dot.removeClass('active');
						$statusTitle.text('API Connection Failed');
						$statusDesc.text('Could not authenticate with PeeStream API.');

						$feedback.removeClass('peestream-notice-success')
							.addClass('peestream-notice peestream-notice-error')
							.empty()
							.append($('<strong>').text('Error: '))
							.append(document.createTextNode(err))
							.slideDown();
					}
				},
				error: function(xhr, status, error) {
					$btnCheck.prop('disabled', false).empty()
						.append($('<span class="dashicons dashicons-update"></span>'))
						.append(document.createTextNode(' Verify API Connection'));
					$dot.removeClass('active');
					$feedback.removeClass('peestream-notice-success')
						.addClass('peestream-notice peestream-notice-error')
						.empty()
						.append($('<strong>').text('AJAX Error: '))
						.append(document.createTextNode(error))
						.slideDown();
				}
			});
		});
	});

	window.pstToggleSecret = function(inputId) {
		var input = document.getElementById(inputId);
		if (input) {
			input.type = (input.type === 'password') ? 'text' : 'password';
		}
	};
})(jQuery);

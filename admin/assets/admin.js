/* global wpR2Offload */
(function ($) {
	'use strict';

	// ── Helpers ──────────────────────────────────────────────────────────────

	function post(action, extra) {
		return $.post(wpR2Offload.ajaxUrl, $.extend({
			action: action,
			nonce: wpR2Offload.nonce,
		}, extra));
	}

	// ── Dashboard: Live status polling ────────────────────────────────────────

	var POLL_INTERVAL = 5000; // 5 s
	var pollTimer = null;

	function startPolling() {
		if (!$('#r2-active-list').length) return;
		pollTimer = setInterval(fetchStatus, POLL_INTERVAL);
		fetchStatus(); // immediate first tick
	}

	function fetchStatus() {
		post('r2_get_status').done(function (res) {
			if (!res.success) return;
			renderActive(res.data.active);
			renderStats(res.data.stats);
		});
	}

	function renderActive(active) {
		var $list = $('#r2-active-list');
		var $spinner = $('#r2-activity-spinner');
		var $idle = $('#r2-idle-icon');
		var $dot = $('#r2-live-dot');

		if (!active || active.length === 0) {
			$spinner.hide();
			$idle.show();
			$dot.removeClass('r2-live-dot--active');
			$list.html('<p class="r2-idle-text">' + wpR2Offload.i18n.idle + '</p>');
			return;
		}

		$spinner.show();
		$idle.hide();
		$dot.addClass('r2-live-dot--active');

		var html = '<ul class="r2-active-uploads">';
		$.each(active, function (_, item) {
			html += '<li><span class="r2-upload-icon dashicons dashicons-upload"></span>'
				+ '<strong>' + item.file + '</strong>'
				+ ' <span class="r2-elapsed">(' + item.elapsed + ')</span></li>';
		});
		html += '</ul>';
		$list.html(html);
	}

	function renderStats(stats) {
		if (!stats) return;
		$('.r2-stat-card--total .r2-stat-value').text(stats.success);
		$('.r2-stat-card--pending .r2-stat-value').text(stats.pending);
		$('.r2-stat-card--error .r2-stat-value').text(stats.errors);
	}

	// ── Dashboard: Bulk offload ───────────────────────────────────────────────

	var bulkRunning = false;

	$(document).on('click', '#r2-bulk-start', function () {
		if (bulkRunning) return;
		bulkRunning = true;

		var $btn = $(this);
		var $status = $('#r2-bulk-status');
		var $fill = $('#r2-progress-fill');
		var $label = $('#r2-progress-label');

		$btn.prop('disabled', true).text(wpR2Offload.i18n.startingBulk);
		$status.show();
		$fill.css('width', '0%');
		$label.text('');

		runBulkChunk(0, $btn, $fill, $label);
	});

	function runBulkChunk(offset, $btn, $fill, $label) {
		post('r2_bulk_offload', { offset: offset }).done(function (res) {
			if (!res.success) {
				$btn.prop('disabled', false).text('Upload Now');
				$label.text(res.data && res.data.message ? res.data.message : wpR2Offload.i18n.unknownError);
				bulkRunning = false;
				return;
			}

			var d = res.data;
			if (d.total > 0) {
				var pct = Math.min(100, Math.round((d.processed / d.total) * 100));
				$fill.css('width', pct + '%');
				$label.text(wpR2Offload.i18n.bulkProgress
					.replace('%1$s', d.processed)
					.replace('%2$s', d.total));
			}

			if (d.done) {
				$fill.css('width', '100%');
				$label.text(wpR2Offload.i18n.bulkDone);
				$btn.prop('disabled', false).text('Upload Now');
				bulkRunning = false;
				fetchStatus(); // refresh stats
			} else {
				setTimeout(function () {
					runBulkChunk(d.processed, $btn, $fill, $label);
				}, 200);
			}
		}).fail(function () {
			$btn.prop('disabled', false).text('Upload Now');
			bulkRunning = false;
		});
	}

	// ── Dashboard: Selective upload ──────────────────────────────────────────

	$(document).on('click', '#r2-selective-search-btn', function () {
		var q = $('#r2-selective-search').val().trim();
		loadSelectiveList(q);
	});

	$(document).on('keydown', '#r2-selective-search', function (e) {
		if (e.key === 'Enter') {
			e.preventDefault();
			var q = $(this).val().trim();
			loadSelectiveList(q);
		}
	});

	function loadSelectiveList(search) {
		var $container = $('#r2-selective-list');
		$container.html('<p class="r2-loading">Loading…</p>');

		post('r2_list_pending', { search: search }).done(function (res) {
			if (!res.success || !res.data.items.length) {
				$container.html('<p class="r2-idle-text">No unprocessed media found.</p>');
				return;
			}

			var html = '<table class="wp-list-table widefat striped r2-selective-table">'
				+ '<thead><tr>'
				+ '<th><input type="checkbox" id="r2-sel-all"> All</th>'
				+ '<th>File</th><th>Type</th><th>Date</th>'
				+ '</tr></thead><tbody>';

			$.each(res.data.items, function (_, item) {
				html += '<tr>'
					+ '<td><input type="checkbox" class="r2-sel-cb" value="' + item.id + '"></td>'
					+ '<td><strong>' + item.filename + '</strong></td>'
					+ '<td>' + item.mime + '</td>'
					+ '<td>' + item.date + '</td>'
					+ '</tr>';
			});
			html += '</tbody></table>'
				+ '<p class="r2-sel-actions">'
				+ '<button id="r2-sel-upload" class="button button-primary">Upload Selected</button> '
				+ '<span id="r2-sel-result" class="r2-test-result"></span>'
				+ '</p>';
			$container.html(html);
		});
	}

	// Toggle all checkboxes.
	$(document).on('change', '#r2-sel-all', function () {
		$('.r2-sel-cb').prop('checked', $(this).is(':checked'));
	});

	// Upload selected files.
	$(document).on('click', '#r2-sel-upload', function () {
		var ids = $('.r2-sel-cb:checked').map(function () {
			return $(this).val();
		}).get();

		if (!ids.length) {
			$('#r2-sel-result').text('No files selected.');
			return;
		}

		var $btn = $(this);
		var $res = $('#r2-sel-result');
		$btn.prop('disabled', true);
		$res.text('Uploading ' + ids.length + ' file(s)…').removeClass('is-success is-error');

		uploadSelectiveChunk(ids, 0, $btn, $res);
	});

	function uploadSelectiveChunk(ids, idx, $btn, $res) {
		if (idx >= ids.length) {
			$btn.prop('disabled', false);
			$res.text('✔ Done! ' + ids.length + ' file(s) uploaded.').addClass('is-success');
			fetchStatus();
			return;
		}

		post('r2_selective_upload', { attachment_id: ids[idx] }).always(function () {
			uploadSelectiveChunk(ids, idx + 1, $btn, $res);
		});
	}

	// ── Dashboard: Test connection (quick actions) ───────────────────────────

	$(document).on('click', '#r2-test-connection-dash', function () {
		var $btn = $(this);
		var $res = $('#r2-test-result-dash');
		$btn.prop('disabled', true);
		$res.text(wpR2Offload.i18n.testing).removeClass('is-success is-error');

		post('r2_test_connection').done(function (res) {
			if (res.success) {
				$res.text(wpR2Offload.i18n.connected).addClass('is-success');
			} else {
				var msg = res.data && res.data.message ? res.data.message : wpR2Offload.i18n.unknownError;
				$res.text(wpR2Offload.i18n.failed + msg).addClass('is-error');
			}
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ── Settings: Test Connection ────────────────────────────────────────────

	$(document).on('click', '#r2-test-connection', function () {
		var $btn = $(this);
		var $result = $('#r2-test-result');

		var formData = {
			account_id: $('input[name="wp_r2_offload_account_id"]').val().trim(),
			access_key: $('input[name="wp_r2_offload_access_key"]').val().trim(),
			secret_key: $('input[name="wp_r2_offload_secret_key"]').val().trim(),
			bucket: $('input[name="wp_r2_offload_bucket"]').val().trim(),
			cdn_domain: $('input[name="wp_r2_offload_cdn_domain"]').val().trim(),
		};

		if (!formData.account_id || !formData.access_key || !formData.secret_key || !formData.bucket) {
			$result.text(wpR2Offload.i18n.fillRequired).addClass('is-error').removeClass('is-success');
			return;
		}

		$btn.prop('disabled', true);
		$result.text(wpR2Offload.i18n.testing).removeClass('is-success is-error');

		post('r2_test_connection', formData).done(function (res) {
			if (res.success) {
				$result.text(wpR2Offload.i18n.connected).addClass('is-success').removeClass('is-error');
			} else {
				var msg = res.data && res.data.message ? res.data.message : wpR2Offload.i18n.unknownError;
				$result.text(wpR2Offload.i18n.failed + msg).addClass('is-error').removeClass('is-success');
			}
		}).fail(function (xhr) {
			$result.text(wpR2Offload.i18n.failed + 'HTTP ' + xhr.status).addClass('is-error');
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ── Logs: Clear logs ─────────────────────────────────────────────────────

	$(document).on('click', '#r2-clear-logs', function () {
		if (!window.confirm(wpR2Offload.i18n.confirmClear)) return;
		var $btn = $(this);
		$btn.prop('disabled', true);

		post('r2_clear_logs').done(function (res) {
			if (res.success) {
				window.location.reload();
			}
		}).always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ── Dashboard: Orphan scan & delete ──────────────────────────────────────

	$(document).on('click', '#r2-scan-orphans', function () {
		var $btn = $(this);
		var $results = $('#r2-orphan-results');

		$btn.prop('disabled', true).text('Scanning…');
		$results.html('<p class="r2-loading">Scanning R2-tracked files…</p>');

		post('r2_list_orphans').done(function (res) {
			$btn.prop('disabled', false).text('Scan for Orphaned Files');

			if (!res.success) {
				$results.html('<p class="r2-test-result is-error">' + (res.data && res.data.message ? res.data.message : 'Scan failed.') + '</p>');
				return;
			}

			var orphans = res.data.orphans;
			if (!orphans || orphans.length === 0) {
				$results.html('<p class="r2-idle-text">✔ No orphaned files found. Your R2 bucket is in sync with the server.</p>');
				return;
			}

			var html = '<p><strong>' + orphans.length + '</strong> orphaned file(s) found:</p>'
				+ '<table class="wp-list-table widefat striped r2-selective-table">'
				+ '<thead><tr>'
				+ '<th><input type="checkbox" id="r2-orphan-all"> All</th>'
				+ '<th>File</th>'
				+ '<th>R2 Key</th>'
				+ '<th>Reason</th>'
				+ '</tr></thead><tbody>';

			$.each(orphans, function (_, item) {
				html += '<tr data-key="' + item.r2_key + '" data-post-id="' + item.post_id + '">'
					+ '<td><input type="checkbox" class="r2-orphan-cb" value="' + item.r2_key + '"></td>'
					+ '<td><strong>' + item.filename + '</strong></td>'
					+ '<td><code>' + item.r2_key + '</code></td>'
					+ '<td><span class="r2-badge r2-badge--error">' + item.label + '</span></td>'
					+ '</tr>';
			});

			html += '</tbody></table>'
				+ '<p class="r2-sel-actions" style="margin-top:12px">'
				+ '<button id="r2-delete-orphans" class="button r2-btn-danger">Delete Selected from R2</button> '
				+ '<span id="r2-orphan-status" class="r2-test-result"></span>'
				+ '</p>';

			$results.html(html);
		}).fail(function () {
			$btn.prop('disabled', false).text('Scan for Orphaned Files');
			$results.html('<p class="r2-test-result is-error">Request failed.</p>');
		});
	});

	// Toggle all orphan checkboxes.
	$(document).on('change', '#r2-orphan-all', function () {
		$('.r2-orphan-cb').prop('checked', $(this).is(':checked'));
	});

	// Delete selected orphans — processes one at a time, removes row on success.
	$(document).on('click', '#r2-delete-orphans', function () {
		var selected = [];
		$('.r2-orphan-cb:checked').each(function () {
			var $tr = $(this).closest('tr');
			selected.push({ key: $tr.data('key'), postId: $tr.data('post-id'), $tr: $tr });
		});

		if (!selected.length) {
			$('#r2-orphan-status').text('Select at least one file.');
			return;
		}

		var $btn = $(this);
		var $st = $('#r2-orphan-status');
		$btn.prop('disabled', true);
		$st.text('Deleting 0 / ' + selected.length + '…').removeClass('is-success is-error');

		var done = 0;
		var failed = 0;

		function deleteNext(idx) {
			if (idx >= selected.length) {
				$btn.prop('disabled', false);
				if (failed === 0) {
					$st.text('✔ Deleted ' + done + ' file(s) from R2.').addClass('is-success');
				} else {
					$st.text(done + ' deleted, ' + failed + ' failed.').addClass('is-error');
				}
				return;
			}

			var item = selected[idx];
			post('r2_delete_orphan', { r2_key: item.key, post_id: item.postId })
				.done(function (res) {
					if (res.success) {
						item.$tr.fadeOut(300, function () { $(this).remove(); });
						done++;
					} else {
						failed++;
					}
					$st.text('Deleting ' + (done + failed) + ' / ' + selected.length + '…');
					deleteNext(idx + 1);
				})
				.fail(function () {
					failed++;
					deleteNext(idx + 1);
				});
		}

		deleteNext(0);
	});

	// ── Boot ─────────────────────────────────────────────────────────────────

	$(document).ready(function () {
		startPolling();

		// Auto-load pending list if the selective panel is present.
		if ($('#r2-selective-list').length) {
			loadSelectiveList('');
		}
	});

	// Cleanup poll on page unload.
	$(window).on('beforeunload', function () {
		clearInterval(pollTimer);
	});

}(jQuery));

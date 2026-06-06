/* global tgbotBroadcast, jQuery */
( function ( $ ) {
	'use strict';

	var cfg        = tgbotBroadcast;
	var pollTimer  = null;
	var activeJobId = null;

	// -------------------------------------------------------------------------
	// Locale filter
	// -------------------------------------------------------------------------
	$( '#tgbot-lang-filter' ).on( 'change', function () {
		var lang = $( this ).val();
		$( '#tgbot-user-table tbody tr' ).each( function () {
			if ( ! lang || $( this ).data( 'locale' ) === lang ) {
				$( this ).show();
			} else {
				$( this ).hide();
				// Uncheck hidden rows.
				$( this ).find( '.tgbot-user-cb' ).prop( 'checked', false );
			}
		} );
		updateCounter();
	} );

	// -------------------------------------------------------------------------
	// Check-all / deselect-all
	// -------------------------------------------------------------------------
	$( '#tgbot-check-all' ).on( 'change', function () {
		var checked = $( this ).prop( 'checked' );
		$( '#tgbot-user-table tbody tr:visible .tgbot-user-cb' ).prop( 'checked', checked );
		updateCounter();
	} );

	$( '#tgbot-select-all' ).on( 'click', function ( e ) {
		e.preventDefault();
		$( '#tgbot-user-table tbody tr:visible .tgbot-user-cb' ).prop( 'checked', true );
		$( '#tgbot-check-all' ).prop( 'checked', true );
		updateCounter();
	} );

	$( '#tgbot-deselect-all' ).on( 'click', function ( e ) {
		e.preventDefault();
		$( '#tgbot-user-table tbody tr .tgbot-user-cb' ).prop( 'checked', false );
		$( '#tgbot-check-all' ).prop( 'checked', false );
		updateCounter();
	} );

	// Individual checkbox change.
	$( '#tgbot-user-table' ).on( 'change', '.tgbot-user-cb', function () {
		updateCounter();
	} );

	function updateCounter() {
		var n = $( '.tgbot-user-cb:checked' ).length;
		$( '#tgbot-selected-count' ).text( cfg.i18n.selected.replace( '%d', n ) );
		$( '#tgbot-broadcast-btn' )
			.prop( 'disabled', n === 0 )
			.text( cfg.i18n.sendBtn.replace( '%d', n ) );
	}

	// -------------------------------------------------------------------------
	// Send button → open compose modal
	// -------------------------------------------------------------------------
	$( '#tgbot-broadcast-btn' ).on( 'click', function () {
		var selected = $( '.tgbot-user-cb:checked' );
		if ( ! selected.length ) {
			return;
		}

		// Collect unique locales.
		var locales = [];
		selected.each( function () {
			var loc = $( this ).data( 'locale' );
			if ( locales.indexOf( loc ) === -1 ) {
				locales.push( loc );
			}
		} );

		var n = selected.length;
		openModal( n, locales );
	} );

	// -------------------------------------------------------------------------
	// Modal
	// -------------------------------------------------------------------------
	function openModal( n, locales ) {
		var $overlay = $( '<div class="tgbot-modal-overlay"></div>' );
		var $modal   = $( '<div class="tgbot-modal" role="dialog" aria-modal="true"></div>' );

		// Header.
		var title = cfg.i18n.modalTitle.replace( '%d', n );
		var $hdr  = $(
			'<div class="tgbot-modal-header">' +
				'<h3>' + escHtml( title ) + '</h3>' +
				'<button type="button" class="tgbot-modal-close button" aria-label="' + escHtml( cfg.i18n.cancel ) + '">&times;</button>' +
			'</div>'
		);
		$modal.append( $hdr );

		// One textarea per locale.
		$.each( locales, function ( i, loc ) {
			var label    = cfg.i18n.localeLabel.replace( '%s', loc );
			var $block   = $(
				'<div class="tgbot-locale-block">' +
					'<label>' + escHtml( label ) + '</label>' +
					'<textarea class="tgbot-message-input large-text" rows="4" data-locale="' + escHtml( loc ) + '" placeholder="' + escHtml( cfg.i18n.messagePlaceholder ) + '"></textarea>' +
				'</div>'
			);
			$modal.append( $block );
		} );

		// Format selector.
		var $fmtRow = $(
			'<div class="tgbot-format-row">' +
				'<strong>' + escHtml( cfg.i18n.format ) + ':</strong>' +
				'<label><input type="radio" name="tgbot_fmt" value="plain" checked /> ' + escHtml( cfg.i18n.fmtPlain ) + '</label>' +
				'<label><input type="radio" name="tgbot_fmt" value="html" /> ' + escHtml( cfg.i18n.fmtHtml ) + '</label>' +
				'<label><input type="radio" name="tgbot_fmt" value="markdown" /> ' + escHtml( cfg.i18n.fmtMarkdown ) + '</label>' +
			'</div>'
		);
		$modal.append( $fmtRow );

		// Actions.
		var $actions = $(
			'<div class="tgbot-modal-actions">' +
				'<button type="button" id="tgbot-modal-cancel" class="button">' + escHtml( cfg.i18n.cancel ) + '</button>' +
				'<button type="button" id="tgbot-modal-send"  class="button button-primary">' + escHtml( cfg.i18n.send ) + '</button>' +
			'</div>'
		);
		$modal.append( $actions );

		$overlay.append( $modal );
		$( 'body' ).append( $overlay );
		$modal.find( '.tgbot-message-input' ).first().trigger( 'focus' );

		// Close handlers.
		$overlay.on( 'click', function ( e ) {
			if ( $( e.target ).is( $overlay ) ) {
				$overlay.remove();
			}
		} );
		$hdr.find( '.tgbot-modal-close' ).on( 'click', function () {
			$overlay.remove();
		} );
		$( '#tgbot-modal-cancel' ).on( 'click', function () {
			$overlay.remove();
		} );

		// Send.
		$( '#tgbot-modal-send' ).on( 'click', function () {
			var messages = {};
			var hasAny   = false;

			$modal.find( '.tgbot-message-input' ).each( function () {
				var loc  = $( this ).data( 'locale' );
				var text = $.trim( $( this ).val() );
				if ( text ) {
					messages[ loc ] = text;
					hasAny = true;
				}
			} );

			if ( ! hasAny ) {
				alert( cfg.i18n.noMessage );
				return;
			}

			var format  = $modal.find( 'input[name="tgbot_fmt"]:checked' ).val() || 'plain';
			var userIds = [];
			$( '.tgbot-user-cb:checked' ).each( function () {
				userIds.push( $( this ).val() );
			} );

			// Build POST data.
			var data = {
				action  : 'tgbot_broadcast_send',
				nonce   : cfg.nonce,
				format  : format,
			};
			$.each( userIds, function ( i, id ) {
				data[ 'user_ids[' + i + ']' ] = id;
			} );
			$.each( messages, function ( loc, text ) {
				data[ 'messages[' + loc + ']' ] = text;
			} );

			$( '#tgbot-modal-send' ).prop( 'disabled', true ).text( cfg.i18n.sending );

			$.post( cfg.ajaxUrl, data, function ( response ) {
				$overlay.remove();
				if ( response.success ) {
					activeJobId = response.data.job_id;
					showProgressBar();
					startPolling( activeJobId );
				} else {
					alert( response.data.message || cfg.i18n.error );
				}
			} ).fail( function () {
				$( '#tgbot-modal-send' ).prop( 'disabled', false ).text( cfg.i18n.send );
				alert( cfg.i18n.error );
			} );
		} );
	}

	// -------------------------------------------------------------------------
	// Progress bar
	// -------------------------------------------------------------------------
	function showProgressBar() {
		if ( ! $( '#tgbot-broadcast-active' ).length ) {
			var $bar = $(
				'<div id="tgbot-broadcast-active" class="tgbot-broadcast-bar">' +
					'<p>' + escHtml( cfg.i18n.sending ) + '</p>' +
					'<div id="tgbot-progress-bar-wrap" class="tgbot-progress-bar-wrap">' +
						'<div id="tgbot-progress-bar" class="tgbot-progress-bar" style="width:0%"></div>' +
					'</div>' +
					'<p id="tgbot-progress-text"></p>' +
				'</div>'
			);
			$( '.wrap h1' ).after( $bar );
		}
		$( '#tgbot-broadcast-active' ).show();
		$( '#tgbot-progress-bar' ).css( 'width', '0%' );
	}

	function updateProgressBar( data ) {
		$( '#tgbot-progress-bar' ).css( 'width', data.percent + '%' );
		var text = cfg.i18n.progressText
			.replace( '%sent', data.sent )
			.replace( '%total', data.total )
			.replace( '%failed', data.failed )
			.replace( '%min', data.est_minutes );
		$( '#tgbot-progress-text' ).text( text );
	}

	function hideProgressBar() {
		$( '#tgbot-broadcast-active' ).fadeOut( 400, function () {
			$( this ).remove();
		} );
	}

	// -------------------------------------------------------------------------
	// Progress polling
	// -------------------------------------------------------------------------
	function startPolling( jobId ) {
		stopPolling();
		pollTimer = setInterval( function () {
			doPoll( jobId );
		}, 5000 );
		// First poll immediately.
		doPoll( jobId );
	}

	function stopPolling() {
		if ( pollTimer ) {
			clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	function doPoll( jobId ) {
		$.get(
			cfg.ajaxUrl,
			{
				action  : 'tgbot_broadcast_progress',
				nonce   : cfg.nonce,
				job_id  : jobId,
			},
			function ( response ) {
				if ( ! response.success ) {
					stopPolling();
					return;
				}
				var d = response.data;
				updateProgressBar( d );

				if ( d.status === 'completed' || d.status === 'failed' || d.status === 'partial' ) {
					stopPolling();
					// Show final state for 3 seconds then hide.
					setTimeout( function () {
						hideProgressBar();
						// Reload to refresh history table.
						window.location.reload();
					}, 3000 );
				}
			}
		);
	}

	// -------------------------------------------------------------------------
	// Restart polling for pre-existing active jobs on page load.
	// -------------------------------------------------------------------------
	if ( $( '#tgbot-broadcast-active' ).length && cfg.activeJobId ) {
		activeJobId = cfg.activeJobId;
		startPolling( activeJobId );
	}

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------
	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

} )( jQuery );

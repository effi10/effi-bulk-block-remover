/**
 * Effi Bulk Block Remover : page d'outil (inventaire, simulation, suppression et restauration par lots).
 *
 * Données fournies par wp_localize_script() dans window.effiBbr :
 * ajaxUrl, nonce, canWrite ('1' ou ''), state (opérations), i18n (chaînes traduites).
 *
 * @package Effi_Bulk_Block_Remover
 */

/* global jQuery */
( function ( $ ) {
	'use strict';

	var cfg = window.effiBbr || {};
	var t = cfg.i18n || {};
	var canWrite = !! cfg.canWrite;
	var BLOCK_PATTERN = /^[a-z0-9-]+\/[a-z0-9-]+$/;
	var CUSTOM = '__custom';

	var busy = false; // false, 'read' (analyse) ou 'write' (suppression, restauration).
	var simulated = null; // Dernière simulation : { postType, blockName, posts }.
	var inventories = {}; // Blocs trouvés, par type de contenu.
	var state = cfg.state || { operations: [], restorable: null };
	var ui = {};

	/**
	 * sprintf minimal : %s, %d et %1$s.
	 */
	function sprintf( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( format || '' ).replace( /%(?:(\d+)\$)?([sd%])/g, function ( match, position, type ) {
			if ( '%' === type ) {
				return '%';
			}
			var value = position ? args[ parseInt( position, 10 ) - 1 ] : args[ index++ ];
			return undefined === value || null === value ? '' : String( value );
		} );
	}

	/**
	 * Requête AJAX : résolue avec response.data, rejetée avec un message lisible.
	 */
	function request( action, data ) {
		var deferred = $.Deferred();

		$.ajax( {
			url: cfg.ajaxUrl,
			type: 'POST',
			dataType: 'json',
			data: $.extend( { action: action, nonce: cfg.nonce }, data || {} )
		} )
			.done( function ( response ) {
				if ( response && true === response.success && response.data ) {
					deferred.resolve( response.data );
				} else if ( response && response.data && response.data.message ) {
					deferred.reject( response.data.message );
				} else {
					deferred.reject( t.unknownError );
				}
			} )
			.fail( function ( xhr ) {
				var json = xhr.responseJSON;
				if ( json && json.data && json.data.message ) {
					deferred.reject( json.data.message );
				} else if ( 403 === xhr.status || '-1' === String( xhr.responseText || '' ).trim() ) {
					deferred.reject( t.nonceError );
				} else if ( 0 === xhr.status ) {
					deferred.reject( t.networkError );
				} else {
					deferred.reject( sprintf( t.httpError, xhr.status ) );
				}
			} );

		return deferred.promise();
	}

	/**
	 * Enchaîne les lots jusqu'à data.done. onBatch( data ) renvoie les paramètres du lot suivant.
	 * La première erreur arrête tout.
	 */
	function runBatches( action, data, onBatch ) {
		var deferred = $.Deferred();

		function next( extra ) {
			request( action, $.extend( {}, data, extra ) )
				.done( function ( batch ) {
					var more;
					try {
						more = onBatch( batch ) || {};
					} catch ( error ) {
						deferred.reject( t.unknownError + ' (' + error.message + ')' );
						return;
					}
					if ( batch.done ) {
						deferred.resolve( batch );
					} else {
						next( more );
					}
				} )
				.fail( function ( message ) {
					deferred.reject( message );
				} );
		}

		next( {} );
		return deferred.promise();
	}

	/* ------------------------------------------------------------------ */
	/* Interface                                                           */
	/* ------------------------------------------------------------------ */

	function setBusy( value ) {
		busy = value;
		var disabled = !! value;
		ui.postType.prop( 'disabled', disabled );
		ui.blockSelect.prop( 'disabled', disabled );
		ui.blockCustom.prop( 'disabled', disabled );
		ui.preserve.prop( 'disabled', disabled );
		ui.inventory.prop( 'disabled', disabled );
		ui.simulate.prop( 'disabled', disabled );
		updateRemoveButton();
		renderOperations();
	}

	function updateRemoveButton() {
		var ready = simulated &&
			simulated.posts > 0 &&
			simulated.postType === ui.postType.val() &&
			simulated.blockName === selectedBlockName();
		ui.remove.prop( 'disabled', !! busy || ! canWrite || ! ready );
	}

	function invalidateSimulation() {
		simulated = null;
		updateRemoveButton();
	}

	function toggleCustom() {
		ui.customWrap.prop( 'hidden', CUSTOM !== ui.blockSelect.val() );
	}

	/** Nom de bloc choisi, ou '' s'il est vide ou invalide. */
	function selectedBlockName() {
		var value = ui.blockSelect.val() || '';
		if ( CUSTOM === value ) {
			value = String( ui.blockCustom.val() || '' ).trim().toLowerCase();
		}
		return BLOCK_PATTERN.test( value ) ? value : '';
	}

	/** Nom de bloc choisi, avec message si vide ou invalide. */
	function readBlockName() {
		var value = ui.blockSelect.val() || '';
		if ( CUSTOM === value ) {
			value = String( ui.blockCustom.val() || '' ).trim().toLowerCase();
			ui.blockCustom.val( value );
		}
		if ( '' === value ) {
			showNotice( 'error', t.chooseBlock );
			return '';
		}
		if ( ! BLOCK_PATTERN.test( value ) ) {
			showNotice( 'error', t.invalidBlock );
			return '';
		}
		return value;
	}

	function postTypeLabel() {
		return ui.postType.find( 'option:selected' ).text().trim();
	}

	function showProgress( label, done, total ) {
		ui.progress.prop( 'hidden', false );
		ui.progressLabel.text( label );
		if ( total > 0 ) {
			ui.progressBar.attr( 'max', total );
			ui.progressBar.val( Math.min( done, total ) );
			ui.progressText.text( sprintf( t.progress, done, total ) );
		} else {
			ui.progressBar.removeAttr( 'value' );
			ui.progressText.text( '' );
		}
	}

	function hideProgress() {
		ui.progress.prop( 'hidden', true );
	}

	function clearResults() {
		ui.results.empty();
	}

	function showNotice( type, message, $target ) {
		var $notice = $( '<div class="notice inline" />' ).addClass( 'notice-' + type );
		$notice.append( $( '<p />' ).text( message ) );
		( $target || ui.results ).append( $notice );
		return $notice;
	}

	function heading( text ) {
		return $( '<h3 />' ).text( text );
	}

	/** Tableau : les cellules sont du texte (échappé) ou des éléments jQuery déjà construits. */
	function table( headers, rows ) {
		var $table = $( '<table class="widefat striped" />' );
		var $head = $( '<tr />' );
		var $body = $( '<tbody />' );

		$.each( headers, function ( i, header ) {
			$head.append( $( '<th scope="col" />' ).text( header ) );
		} );
		$.each( rows, function ( i, cells ) {
			var $row = $( '<tr />' );
			$.each( cells, function ( j, cell ) {
				var $cell = $( '<td />' );
				if ( cell instanceof $ ) {
					$cell.append( cell );
				} else {
					$cell.text( undefined === cell || null === cell ? '' : String( cell ) );
				}
				$row.append( $cell );
			} );
			$body.append( $row );
		} );

		$table.append( $( '<thead />' ).append( $head ), $body );
		return $( '<div style="max-height:420px;overflow:auto;margin:0 0 1em;" />' ).append( $table );
	}

	function postLink( post ) {
		var title = post.title || '#' + post.id;
		if ( post.edit_link && /^https?:\/\//i.test( post.edit_link ) ) {
			return $( '<a target="_blank" rel="noopener noreferrer" />' ).attr( 'href', post.edit_link ).text( title );
		}
		return $( '<span />' ).text( title );
	}

	function button( label, className, onClick, disabled ) {
		return $( '<button type="button" class="button" />' )
			.addClass( className || '' )
			.text( label )
			.prop( 'disabled', !! disabled || !! busy )
			.on( 'click', onClick );
	}

	/* ------------------------------------------------------------------ */
	/* Inventaire des blocs présents (blocs non enregistrés compris)       */
	/* ------------------------------------------------------------------ */

	function mergeInventory( target, blocks ) {
		$.each( blocks || {}, function ( name, info ) {
			if ( ! target[ name ] ) {
				target[ name ] = { posts: 0, occurrences: 0, registered: !! info.registered, valid: !! info.valid };
			}
			target[ name ].posts += parseInt( info.posts, 10 ) || 0;
			target[ name ].occurrences += parseInt( info.occurrences, 10 ) || 0;
		} );
	}

	function sortedNames( inventory ) {
		return Object.keys( inventory ).sort( function ( a, b ) {
			return inventory[ b ].posts - inventory[ a ].posts || ( a < b ? -1 : ( a > b ? 1 : 0 ) );
		} );
	}

	/** Ajoute en tête de liste les blocs trouvés dans les contenus du type choisi. */
	function refreshFoundGroup() {
		var inventory = inventories[ ui.postType.val() ];
		var current = ui.blockSelect.val();
		var names;
		var $group;

		ui.blockSelect.find( '#effi-bbr-found-blocks' ).remove();

		if ( inventory ) {
			names = sortedNames( inventory );
			if ( names.length ) {
				$group = $( '<optgroup id="effi-bbr-found-blocks" />' ).attr( 'label', t.foundGroup );
				$.each( names, function ( i, name ) {
					var info = inventory[ name ];
					var label = sprintf( t.foundOption, name, info.posts );
					if ( ! info.registered ) {
						label += ' (' + t.notRegistered + ')';
					}
					if ( ! info.valid ) {
						label += ' (' + t.unsupportedName + ')';
					}
					$group.append( $( '<option />' ).val( name ).text( label ).prop( 'disabled', ! info.valid ) );
				} );
				ui.blockSelect.find( 'option' ).first().after( $group );
			}
		}

		ui.blockSelect.val( current );
		if ( null === ui.blockSelect.val() ) {
			ui.blockSelect.val( '' );
		}
		toggleCustom();
	}

	function chooseBlock( name ) {
		ui.blockSelect.val( name );
		if ( name !== ui.blockSelect.val() ) {
			ui.blockSelect.val( CUSTOM );
			ui.blockCustom.val( name );
		}
		toggleCustom();
		invalidateSimulation();
		ui.blockSelect.trigger( 'focus' );
	}

	function renderInventory( scanned, inventory ) {
		var names = sortedNames( inventory );
		var rows = [];

		showNotice( 'info', sprintf( t.inventorySummary, scanned, names.length ) );
		if ( ! names.length ) {
			showNotice( 'warning', t.inventoryEmpty );
			return;
		}

		$.each( names, function ( i, name ) {
			var info = inventory[ name ];
			rows.push( [
				$( '<code />' ).text( name ),
				info.registered ? t.yes : t.no,
				info.posts,
				info.occurrences,
				info.valid ? button( t.select, '', function () {
					chooseBlock( name );
				} ) : t.unsupportedName
			] );
		} );

		ui.results.append( table( [ t.colBlock, t.colRegistered, t.colPosts, t.colOccurrences, '' ], rows ) );
	}

	/* ------------------------------------------------------------------ */
	/* Analyse : inventaire ou simulation (aucune modification)            */
	/* ------------------------------------------------------------------ */

	function runScan( withBlock ) {
		var postType = ui.postType.val();
		var blockName = '';
		var total = 0;
		var scanned = 0;
		var matches = [];
		var inventory = {};

		clearResults();
		if ( withBlock ) {
			blockName = readBlockName();
			if ( ! blockName ) {
				return;
			}
		}

		invalidateSimulation();
		setBusy( 'read' );
		showProgress( t.scanning, 0, 0 );

		runBatches( 'effi_bbr_scan', { post_type: postType, block_name: blockName }, function ( batch ) {
			if ( undefined !== batch.total ) {
				total = parseInt( batch.total, 10 ) || 0;
			}
			scanned += parseInt( batch.scanned, 10 ) || 0;
			matches = matches.concat( batch.matches || [] );
			mergeInventory( inventory, batch.blocks );
			showProgress( t.scanning, scanned, total );
			return { cursor: batch.cursor };
		} )
			.done( function () {
				inventories[ postType ] = inventory;
				refreshFoundGroup();
				if ( withBlock ) {
					renderSimulation( postType, blockName, scanned, matches );
				} else {
					renderInventory( scanned, inventory );
				}
			} )
			.fail( function ( message ) {
				showNotice( 'error', sprintf( t.scanFailed, message ) );
			} )
			.always( function () {
				hideProgress();
				setBusy( false );
			} );
	}

	function renderSimulation( postType, blockName, scanned, matches ) {
		var occurrences = 0;
		var inner = 0;
		var blocked = 0;
		var rows = [];

		if ( ! matches.length ) {
			showNotice( 'info', sprintf( t.simulationNone, blockName ) );
			simulated = { postType: postType, blockName: blockName, posts: 0 };
			updateRemoveButton();
			return;
		}

		$.each( matches, function ( i, match ) {
			var remarks = [];
			occurrences += parseInt( match.found, 10 ) || 0;
			inner += parseInt( match.inner, 10 ) || 0;
			if ( match.blocking ) {
				blocked++;
			}
			$.each( match.issues || [], function ( j, issue ) {
				remarks.push( ( issue.blocking ? t.willBeSkipped + ' ' : '' ) + issue.message );
			} );
			rows.push( [ postLink( match ), match.status, match.found, match.inner, remarks.join( ' ' ) ] );
		} );

		showNotice( 'success', sprintf( t.simulationSummary, blockName, matches.length, scanned, occurrences ) );
		if ( inner > 0 ) {
			showNotice( 'warning', sprintf( t.simulationInner, inner ) );
		}
		if ( blocked > 0 ) {
			showNotice( 'warning', sprintf( t.simulationBlocked, blocked ) );
		}
		ui.results.append( table( [ t.colContent, t.colStatus, t.colFound, t.colInner, t.colRemarks ], rows ) );

		simulated = { postType: postType, blockName: blockName, posts: matches.length };
		updateRemoveButton();
	}

	/* ------------------------------------------------------------------ */
	/* Suppression                                                         */
	/* ------------------------------------------------------------------ */

	function runRemoval() {
		var blockName = selectedBlockName();
		var message;

		if ( ! canWrite || ! simulated || simulated.blockName !== blockName || simulated.postType !== ui.postType.val() ) {
			updateRemoveButton();
			return;
		}

		message = sprintf( t.confirmRemoval, blockName, simulated.posts, postTypeLabel() ) + '\n\n' + t.confirmReminder;
		if ( ! window.confirm( message ) ) {
			return;
		}

		clearResults();
		setBusy( 'write' );
		showProgress( t.removing, 0, 0 );

		request( 'effi_bbr_start_removal', {
			post_type: simulated.postType,
			block_name: blockName,
			preserve_date: ui.preserve.is( ':checked' ) ? '1' : '0'
		} )
			.done( function ( data ) {
				applyState( data.state );
				processRemoval( data.operation );
			} )
			.fail( function ( error ) {
				hideProgress();
				showNotice( 'error', error );
				setBusy( false );
			} );
	}

	/** Traite (ou reprend) une opération de suppression jusqu'au bout. */
	function processRemoval( operation ) {
		var modified = [];
		var errors = [];

		setBusy( 'write' );
		showProgress( t.removing, operation.processed, operation.total );

		runBatches( 'effi_bbr_removal_batch', { operation: operation.id }, function ( batch ) {
			modified = modified.concat( batch.report.modified || [] );
			errors = errors.concat( batch.report.errors || [] );
			operation = batch.operation;
			applyState( batch.state );
			showProgress( t.removing, operation.processed, operation.total );
		} )
			.done( function () {
				renderRemovalReport( operation, modified, errors, '' );
			} )
			.fail( function ( message ) {
				renderRemovalReport( operation, modified, errors, message );
			} )
			.always( function () {
				hideProgress();
				simulated = null;
				setBusy( false );
			} );
	}

	function renderRemovalReport( operation, modified, errors, stopMessage ) {
		var rows;

		if ( stopMessage ) {
			showNotice( 'error', sprintf( t.removalStopped, stopMessage ) );
			showNotice( 'warning', sprintf( t.removalPartial, operation.modified, operation.removed, operation.errors, operation.processed ) + ' ' + t.removalResume );
		} else {
			showNotice( operation.errors > 0 ? 'warning' : 'success', sprintf( t.removalSummary, operation.modified, operation.removed, operation.errors, operation.processed ) );
		}

		if ( errors.length ) {
			rows = $.map( errors, function ( post ) {
				return [ [ postLink( post ), post.status, post.message ] ];
			} );
			ui.results.append( heading( t.errorsTitle ), table( [ t.colContent, t.colStatus, t.colMessage ], rows ) );
		}
		if ( modified.length ) {
			rows = $.map( modified, function ( post ) {
				return [ [ postLink( post ), post.status, post.removed ] ];
			} );
			ui.results.append( heading( t.modifiedTitle ), table( [ t.colContent, t.colStatus, t.colRemoved ], rows ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Restauration et sauvegardes                                         */
	/* ------------------------------------------------------------------ */

	function runRestore( operation ) {
		var restored = 0;
		var skipped = [];
		var errors = [];
		var processed = 0;
		var total = 0;

		if ( ! window.confirm( t.confirmRestore ) ) {
			return;
		}

		clearResults();
		setBusy( 'write' );
		showProgress( t.restoring, 0, 0 );

		request( 'effi_bbr_start_restore', { operation: operation.id } )
			.done( function ( data ) {
				operation = data.operation;
				total = operation.backups;
				applyState( data.state );
				showProgress( t.restoring, 0, total );

				runBatches( 'effi_bbr_restore_batch', { operation: operation.id }, function ( batch ) {
					restored += ( batch.report.restored || [] ).length;
					skipped = skipped.concat( batch.report.skipped || [] );
					errors = errors.concat( batch.report.errors || [] );
					processed += parseInt( batch.processed, 10 ) || 0;
					operation = batch.operation;
					applyState( batch.state );
					showProgress( t.restoring, processed, total );
				} )
					.done( function () {
						renderRestoreReport( operation, skipped, errors, '' );
					} )
					.fail( function ( message ) {
						renderRestoreReport( operation, skipped, errors, message );
					} )
					.always( function () {
						hideProgress();
						setBusy( false );
					} );
			} )
			.fail( function ( message ) {
				hideProgress();
				showNotice( 'error', sprintf( t.restoreStopped, message ) );
				setBusy( false );
			} );
	}

	function renderRestoreReport( operation, skipped, errors, stopMessage ) {
		var rows;
		var problems = skipped.concat( errors );

		if ( stopMessage ) {
			showNotice( 'error', sprintf( t.restoreStopped, stopMessage ) );
		}
		showNotice( problems.length || stopMessage ? 'warning' : 'success', sprintf( t.restoreSummary, operation.restored, operation.skipped, operation.restore_errors ) );

		if ( problems.length ) {
			rows = $.map( problems, function ( post ) {
				return [ [ postLink( post ), post.status, post.message ] ];
			} );
			ui.results.append( heading( t.skippedTitle ), table( [ t.colContent, t.colStatus, t.colMessage ], rows ) );
		}
	}

	function runPurge( operation ) {
		if ( ! window.confirm( t.confirmPurge ) ) {
			return;
		}

		clearResults();
		setBusy( 'write' );

		request( 'effi_bbr_purge', { operation: operation.id } )
			.done( function ( data ) {
				applyState( data.state );
				showNotice( 'success', t.purgeDone );
			} )
			.fail( function ( message ) {
				showNotice( 'error', message );
			} )
			.always( function () {
				setBusy( false );
			} );
	}

	function applyState( newState ) {
		if ( newState && Array.isArray( newState.operations ) ) {
			state = newState;
		}
		renderOperations();
	}

	function renderOperations() {
		var current = state.restorable;
		var $box;
		var $actions;
		var rows;

		if ( ! ui.operations ) {
			return;
		}
		ui.operations.empty();

		if ( current ) {
			$box = $( '<div class="card" style="max-width:none;" />' );
			$box.append( $( '<p />' ).append( $( '<strong />' ).text( sprintf( t.restorableTitle, current.date, current.block_name, current.post_type_label ) ) ) );
			$box.append( $( '<p />' ).text( sprintf( t.restorableDetails, current.status_label, current.backups, current.updated_ago ) ) );

			$actions = $( '<p />' );
			if ( 'running' === current.status ) {
				$actions.append( button( t.resumeRemoval, 'button-primary', function () {
					clearResults();
					processRemoval( current );
				}, ! canWrite ), ' ' );
			}
			$actions.append( button( 'restoring' === current.status ? t.resumeRestore : t.restoreLast, 'running' === current.status ? '' : 'button-primary', function () {
				runRestore( current );
			}, ! canWrite || ( current.backups < 1 && 'restoring' !== current.status ) ), ' ' );
			$actions.append( button( t.purge, 'button-link-delete', function () {
				runPurge( current );
			} ) );
			$box.append( $actions );

			if ( ! canWrite ) {
				$box.append( $( '<p class="description" />' ).text( t.needsUnfiltered ) );
			}
			ui.operations.append( $box );
		} else {
			ui.operations.append( $( '<p />' ).text( t.noRestorable ) );
		}

		if ( state.operations && state.operations.length ) {
			rows = $.map( state.operations, function ( op ) {
				return [ [ op.date, op.user, op.post_type_label, $( '<code />' ).text( op.block_name ), op.status_label, op.modified, op.removed, op.errors, op.backups ] ];
			} );
			ui.operations.append(
				heading( t.historyTitle ),
				table( [ t.colDate, t.colUser, t.colType, t.colBlock, t.colStatus, t.colModified, t.colRemoved, t.colErrors, t.colBackups ], rows )
			);
		} else {
			ui.operations.append( $( '<p class="description" />' ).text( t.noOperation ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Initialisation                                                      */
	/* ------------------------------------------------------------------ */

	$( function () {
		var $root = $( '.effi-bbr' );
		if ( ! $root.length || ! cfg.ajaxUrl ) {
			return;
		}

		ui.postType = $( '#effi-bbr-post-type' );
		ui.blockSelect = $( '#effi-bbr-block-select' );
		ui.blockCustom = $( '#effi-bbr-block-custom' );
		ui.customWrap = $( '#effi-bbr-custom-wrap' );
		ui.preserve = $( '#effi-bbr-preserve-date' );
		ui.inventory = $( '#effi-bbr-inventory' );
		ui.simulate = $( '#effi-bbr-simulate' );
		ui.remove = $( '#effi-bbr-remove' );
		ui.progress = $( '#effi-bbr-progress' );
		ui.progressLabel = $( '#effi-bbr-progress-label' );
		ui.progressText = $( '#effi-bbr-progress-text' );
		ui.progressBar = $( '#effi-bbr-progress-bar' );
		ui.results = $( '#effi-bbr-results' );
		ui.operations = $( '#effi-bbr-operations' );

		ui.postType.on( 'change', function () {
			clearResults();
			refreshFoundGroup();
			invalidateSimulation();
		} );
		ui.blockSelect.on( 'change', function () {
			toggleCustom();
			invalidateSimulation();
			if ( CUSTOM === ui.blockSelect.val() ) {
				ui.blockCustom.trigger( 'focus' );
			}
		} );
		ui.blockCustom.on( 'input change', invalidateSimulation );
		ui.inventory.on( 'click', function () {
			runScan( false );
		} );
		ui.simulate.on( 'click', function () {
			runScan( true );
		} );
		ui.remove.on( 'click', runRemoval );

		$( window ).on( 'beforeunload', function ( event ) {
			if ( 'write' === busy ) {
				event.preventDefault();
				event.returnValue = t.leaveWarning;
				return t.leaveWarning;
			}
		} );

		toggleCustom();
		updateRemoveButton();
		renderOperations();
	} );
}( jQuery ) );

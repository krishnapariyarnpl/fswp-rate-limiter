( function () {
	var btn = document.getElementById( 'fswp-auto-crawl-start' );
	if ( ! btn ) {
		return;
	}

	var progress = document.getElementById( 'fswp-auto-crawl-progress' );
	var cfg = window.fswpCrawler || {};

	function post( data ) {
		var body = new FormData();
		for ( var key in data ) {
			if ( Object.prototype.hasOwnProperty.call( data, key ) ) {
				body.append( key, data[ key ] );
			}
		}
		return fetch( cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( res ) { return res.json(); } );
	}

	function fail( message ) {
		progress.textContent = message || cfg.error || '';
		btn.disabled = false;
	}

	function crawlNext( offset, total ) {
		post( { action: 'fswp_crawl_batch', nonce: cfg.nonce, offset: offset } ).then( function ( data ) {
			if ( ! data.success ) {
				fail( data.data && data.data.message );
				return;
			}

			var processed = data.data.processed;
			progress.textContent = cfg.progressLabel + ' ' + processed + ' ' + cfg.ofLabel + ' ' + total + ' ' + cfg.endpointsLabel;

			if ( processed < total ) {
				crawlNext( processed, total );
			} else {
				progress.textContent = cfg.doneLabel + ' ' + total + ' ' + cfg.endpointsLabel;
				window.location.reload();
			}
		} ).catch( function () { fail(); } );
	}

	btn.addEventListener( 'click', function () {
		btn.disabled = true;
		progress.textContent = cfg.discovering || '';

		post( { action: 'fswp_discover_endpoints', nonce: cfg.nonce } ).then( function ( data ) {
			if ( ! data.success ) {
				fail( data.data && data.data.message );
				return;
			}

			var total = data.data.total;
			if ( ! total ) {
				progress.textContent = cfg.noneFound || '';
				btn.disabled = false;
				return;
			}

			crawlNext( 0, total );
		} ).catch( function () { fail(); } );
	} );
} )();

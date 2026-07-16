(function () {

    var ajaxUrl = usAllocPay.ajax_url;
    var nonce   = usAllocPay.nonce;

    // ── Export modal ──────────────────────────────────────────
    document.getElementById( 'us-export-xlsx-btn' ).addEventListener( 'click', function () {
        document.getElementById( 'us-export-status' ).textContent = '';
        document.getElementById( 'us-export-modal' ).style.display = 'flex';
    } );

    document.getElementById( 'us-export-cancel' ).addEventListener( 'click', function () {
        document.getElementById( 'us-export-modal' ).style.display = 'none';
    } );

    document.getElementById( 'us-export-modal' ).addEventListener( 'click', function ( e ) {
        if ( e.target === this ) this.style.display = 'none';
    } );

    document.getElementById( 'us-export-confirm' ).addEventListener( 'click', function () {
        var month  = document.getElementById( 'us-export-month' ).value;
        var status = document.getElementById( 'us-export-status' );
        var btn    = this;

        if ( ! month ) {
            status.textContent = 'Please select a month.';
            status.style.color = '#d63638';
            return;
        }

        btn.disabled       = true;
        btn.textContent    = 'Generating...';
        status.textContent = 'Building your spreadsheet, please wait...';
        status.style.color = '#666';

        var form    = document.createElement( 'form' );
        form.method = 'post';
        form.action = ajaxUrl;

        var fields = {
            action:       'us_export_pay_xlsx',
            nonce:        nonce,
            export_month: month,
        };

        Object.keys( fields ).forEach( function ( key ) {
            var input   = document.createElement( 'input' );
            input.type  = 'hidden';
            input.name  = key;
            input.value = fields[ key ];
            form.appendChild( input );
        } );

        document.body.appendChild( form );
        form.submit();
        document.body.removeChild( form );

        setTimeout( function () {
            btn.disabled       = false;
            btn.textContent    = 'Download';
            status.textContent = 'Download started.';
        }, 2500 );
    } );

    // ── Mark as paid modal ────────────────────────────────────
    document.querySelectorAll( '.us-mark-paid-btn' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var earned    = parseFloat( btn.dataset.earned )    || 0;
            var paidSoFar = parseFloat( btn.dataset.paidSoFar ) || 0;
            var remaining = Math.max( 0, Math.round( ( earned - paidSoFar ) * 100 ) / 100 );

            document.getElementById( 'us-paid-umpire-id' ).value = btn.dataset.umpire;
            document.getElementById( 'us-paid-month' ).value     = btn.dataset.month;
            document.getElementById( 'us-paid-league' ).value    = btn.dataset.league || 0;
            document.getElementById( 'us-paid-type' ).value      = btn.dataset.type   || 'league';

            document.getElementById( 'us-paid-earned-display' ).textContent = '$' + earned.toFixed( 2 );

            var alreadyRow = document.getElementById( 'us-paid-already-row' );
            if ( paidSoFar > 0 ) {
                document.getElementById( 'us-paid-already-display' ).textContent   = '$' + paidSoFar.toFixed( 2 ) + ' paid';
                document.getElementById( 'us-paid-remaining-display' ).textContent = ' — $' + remaining.toFixed( 2 ) + ' remaining';
                alreadyRow.style.display = '';
            } else {
                alreadyRow.style.display = 'none';
            }

            document.getElementById( 'us-paid-amount' ).value = remaining.toFixed( 2 );

            document.getElementById( 'us-paid-modal-label' ).textContent    = btn.dataset.label;
            document.getElementById( 'us-paid-modal-sublabel' ).textContent = btn.dataset.sublabel || '';
            document.getElementById( 'us-paid-modal' ).style.display = 'flex';
        } );
    } );

    document.getElementById( 'us-paid-modal-cancel' ).addEventListener( 'click', function () {
        document.getElementById( 'us-paid-modal' ).style.display = 'none';
    } );

    document.getElementById( 'us-paid-modal' ).addEventListener( 'click', function ( e ) {
        if ( e.target === this ) this.style.display = 'none';
    } );

    // ── Expandable game rows ──────────────────────────────────
    document.querySelectorAll( '.us-pay-toggle-btn' ).forEach( function ( btn ) {
        btn.addEventListener( 'click', function () {
            var targetId  = btn.dataset.target;
            var targetRow = document.getElementById( targetId );
            var isOpen    = ! targetRow.hidden;

            var block = btn.closest( '.us-pay-block' );
            block.querySelectorAll( '.us-pay-games-row' ).forEach( function ( row ) {
                row.hidden = true;
            } );
            block.querySelectorAll( '.us-pay-toggle-btn' ).forEach( function ( b ) {
                b.setAttribute( 'aria-expanded', 'false' );
                b.innerHTML = '&#9660; View games';
            } );

            if ( ! isOpen ) {
                targetRow.hidden = false;
                btn.setAttribute( 'aria-expanded', 'true' );
                btn.innerHTML = '&#9650; Hide games';
            }
        } );
    } );

} )();
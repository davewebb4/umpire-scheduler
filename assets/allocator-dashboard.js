jQuery(function ($) {

    var nonce      = usAllocDash.nonce;
    var ajaxUrl    = usAllocDash.ajax_url;
    var pastCount  = usAllocDash.past_count;
    var postponeId = 0;

    // ── Toggle past games ─────────────────────────────────────
    $( '#us-toggle-past-games' ).on( 'click', function () {
        var $btn   = $( this );
        var $games = $( '#us-past-games' );
        var label  = pastCount + ' completed game' + ( pastCount !== 1 ? 's' : '' );
        if ( $games.is( ':visible' ) ) {
            $games.slideUp( 200 );
            $btn.html( '&#x25BC; Show ' + label );
        } else {
            $games.slideDown( 200 );
            $btn.html( '&#x25B2; Hide ' + label );
        }
    } );

    // ── Assign dropdown enable/disable ────────────────────────
    $( document ).on( 'change', 'select[data-uid]', function () {
        var uid  = $( this ).data( 'uid' );
        var $btn = $( '#btn_' + uid );
        if ( $( this ).val() ) {
            $btn.prop( 'disabled', false ).css( { opacity: '1', cursor: 'pointer' } );
        } else {
            $btn.prop( 'disabled', true ).css( { opacity: '0.4', cursor: 'not-allowed' } );
        }
    } );

    // ── Assign & Confirm ──────────────────────────────────────
    $( document ).on( 'click', '.us-alloc-assign-btn', function () {
        var uid      = $( this ).data( 'uid' );
        var game_id  = $( this ).data( 'game' );
        var position = $( this ).data( 'position' );
        var $sel     = $( '#sel_' + uid );
        var $btn     = $( '#btn_' + uid );
        var $status  = $( '#status_' + uid );
        var uid_val  = $sel.val();
        var uname    = $sel.find( 'option:selected' ).text();

        if ( ! uid_val ) return;

        $btn.prop( 'disabled', true ).text( 'Saving...' );
        $sel.prop( 'disabled', true );

        $.post( ajaxUrl, {
            action: 'us_allocator_assign_umpire', nonce: nonce, game_id: game_id, umpire_id: uid_val, position: position
        }, function ( res ) {
            if ( res.success ) {
                // Replace the entire dropdown wrapper with a confirmed label — no page reload
                $btn.parent().parent().replaceWith(
                    '<span class="us-status-confirmed">&#10003; ' + res.data.label + '</span>'
                );
            } else {
                $sel.prop( 'disabled', false );
                $btn.prop( 'disabled', false ).text( 'Assign & Confirm' ).css( { opacity: '1', cursor: 'pointer' } );
                $status.show().text( 'Error — try again' ).css( 'color', '#d63638' );
            }
        } );
    } );

    // ── Approve pending request ───────────────────────────────
    $( document ).on( 'click', '.us-req-approve-btn', function () {
        var $btn          = $( this );
        var assignment_id = $btn.data( 'assignment' );
        var reqNonce      = $btn.data( 'nonce' );
        var $card         = $btn.closest( '.us-req-card' );

        $btn.prop( 'disabled', true ).text( 'Saving...' );
        $card.find( '.us-req-deny-btn' ).prop( 'disabled', true );

        $.post( ajaxUrl, {
            action: 'us_alloc_approve_request', nonce: reqNonce, assignment_id: assignment_id
        }, function ( res ) {
            if ( res.success ) {
                $card.slideUp( 300, function () { $( this ).remove(); } );
            } else {
                $btn.prop( 'disabled', false ).text( '&#10003; Confirm' );
                $card.find( '.us-req-deny-btn' ).prop( 'disabled', false );
            }
        } );
    } );

    // ── Deny pending request ──────────────────────────────────
    $( document ).on( 'click', '.us-req-deny-btn', function () {
        var $btn          = $( this );
        var assignment_id = $btn.data( 'assignment' );
        var reqNonce      = $btn.data( 'nonce' );
        var $row          = $btn.closest( '.us-req-card__umpire-row' );
        var $card         = $btn.closest( '.us-req-card' );

        $btn.prop( 'disabled', true ).text( 'Saving...' );

        $.post( ajaxUrl, {
            action: 'us_alloc_deny_request', nonce: reqNonce, assignment_id: assignment_id
        }, function ( res ) {
            if ( res.success ) {
                $row.slideUp( 200, function () {
                    $( this ).remove();
                    if ( $card.find( '.us-req-card__umpire-row' ).length === 0 ) {
                        $card.slideUp( 300, function () { $( this ).remove(); } );
                    }
                } );
            } else {
                $btn.prop( 'disabled', false ).text( '&#10005; Deny' );
            }
        } );
    } );

    // ── No-show ───────────────────────────────────────────────
    $( document ).on( 'click', '.us-alloc-noshow-btn', function () {
        var $btn          = $( this );
        var assignment_id = $btn.data( 'assignment' );
        if ( ! confirm( 'Mark this umpire as a no-show? Pay will be set to $0 and they will be notified.' ) ) return;
        $btn.prop( 'disabled', true ).text( 'Saving...' );
        $.post( ajaxUrl, { action: 'us_mark_noshow', nonce: nonce, assignment_id: assignment_id }, function ( res ) {
            if ( res.success ) {
                setTimeout( function () { location.reload(); }, 800 );
            } else {
                $btn.prop( 'disabled', false ).text( 'No-show' );
                alert( 'Error — could not mark no-show.' );
            }
        } );
    } );

    // ── Postpone modal open ───────────────────────────────────
    $( document ).on( 'click', '.us-alloc-postpone-btn', function () {
        postponeId = $( this ).data( 'game' );
        $( '#us-alloc-postpone-label' ).text( $( this ).data( 'label' ) );
        $( '#us-alloc-postpone-pay' ).prop( 'checked', false );
        $( '#us-alloc-postpone-modal' ).css( 'display', 'flex' );
    } );

    $( '#us-alloc-postpone-cancel' ).on( 'click', function () { $( '#us-alloc-postpone-modal' ).hide(); } );
    $( '#us-alloc-postpone-modal' ).on( 'click', function ( e ) { if ( e.target === this ) $( this ).hide(); } );

    // ── Postpone confirm ──────────────────────────────────────
    $( '#us-alloc-postpone-confirm' ).on( 'click', function () {
        var $btn    = $( this );
        var payUmps = $( '#us-alloc-postpone-pay' ).is( ':checked' ) ? '1' : '0';
        $btn.prop( 'disabled', true ).text( 'Saving...' );
        $.post( ajaxUrl, { action: 'us_postpone_game', nonce: nonce, game_id: postponeId, pay_umpires: payUmps }, function ( res ) {
            if ( res.success ) {
                $( '#us-alloc-postpone-modal' ).hide();
                setTimeout( function () { location.reload(); }, 600 );
            } else {
                alert( 'Error: ' + ( res.data || 'Could not postpone game.' ) );
                $btn.prop( 'disabled', false ).text( 'Confirm postponement' );
            }
        } );
    } );

} );

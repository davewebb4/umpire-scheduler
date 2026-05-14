jQuery(function ($) {

    var nonce      = usAllocGames.nonce;
    var ajaxUrl    = usAllocGames.ajax_url;
    var postponeId = 0;
    var cancelId   = 0;

    // ── Assign select enable/disable ──────────────────────────
    $( document ).on( 'change', '.us-games-assign-select', function () {
        var uid  = $( this ).data( 'uid' );
        var $btn = $( '.us-games-assign-btn[data-uid="' + uid + '"]' );
        $btn.prop( 'disabled', ! $( this ).val() ).toggleClass( 'us-btn--disabled', ! $( this ).val() );
    } );

    // ── Confirm assignment ────────────────────────────────────
    $( document ).on( 'click', '.us-games-assign-btn', function () {
        var $btn      = $( this );
        var uid       = $btn.data( 'uid' );
        var game_id   = $btn.data( 'game' );
        var position  = $btn.data( 'position' );
        var $sel      = $( '.us-games-assign-select[data-uid="' + uid + '"]' );
        var umpire_id = $sel.val();
        var uname     = $sel.find( 'option:selected' ).text();

        if ( ! umpire_id ) return;
        if ( ! confirm( 'Confirm ' + uname + ' as ' + position + ' umpire? Any pending requests will be denied.' ) ) return;

        $btn.prop( 'disabled', true ).text( 'Saving...' );
        $sel.prop( 'disabled', true );

        $.post( ajaxUrl, { action: 'us_games_assign_umpire', nonce, game_id, umpire_id, position }, function ( res ) {
            if ( res.success ) {
                $btn.closest( '.us-alloc-games__assign-cell' ).replaceWith(
                    '<div class="us-alloc-games__assigned-cell">' +
                    '<span class="us-status-confirmed">&#10003; ' + res.data.umpire_name + '</span>' +
                    '<button type="button" class="us-games-clear-btn us-alloc__noshow-btn" data-game="' + game_id + '" data-position="' + position + '">Clear</button>' +
                    '</div>'
                );
            } else {
                alert( res.data || 'Error \u2014 could not assign umpire.' );
                $sel.prop( 'disabled', false );
                $btn.prop( 'disabled', false ).text( 'Confirm' ).removeClass( 'us-btn--disabled' );
            }
        } );
    } );

    // ── Clear assignment ──────────────────────────────────────
    $( document ).on( 'click', '.us-games-clear-btn', function () {
        var $btn     = $( this );
        var game_id  = $btn.data( 'game' );
        var position = $btn.data( 'position' );
        if ( ! confirm( 'Clear the ' + position + ' umpire assignment?' ) ) return;
        $btn.prop( 'disabled', true ).text( 'Clearing...' );
        $.post( ajaxUrl, { action: 'us_games_clear_assignment', nonce, game_id, position }, function ( res ) {
            if ( res.success ) { location.reload(); }
            else { alert( res.data || 'Error.' ); $btn.prop( 'disabled', false ).text( 'Clear' ); }
        } );
    } );

    // ── Add game modal ────────────────────────────────────────
    $( '#us-add-game-btn' ).on( 'click', function () {
        $( '#us-game-modal-title' ).text( 'Add New Game' );
        $( '#us-game-modal-id' ).val( '0' );
        $( '#us-game-away, #us-game-home, #us-game-date, #us-game-time, #us-game-field' ).val( '' );
        $( '#us-game-two-umps, #us-game-dh' ).prop( 'checked', false );
        $( '#us-game-modal-error' ).prop( 'hidden', true );
        $( '#us-game-modal' ).css( 'display', 'flex' );
    } );

    // ── Edit game modal ───────────────────────────────────────
    $( document ).on( 'click', '.us-alloc-game-edit-btn', function () {
        var btn = $( this );
        $( '#us-game-modal-title' ).text( 'Edit Game' );
        $( '#us-game-modal-id' ).val( btn.data( 'game' ) );
        $( '#us-game-league' ).val( btn.data( 'league' ) );
        $( '#us-game-away' ).val( btn.data( 'away' ) );
        $( '#us-game-home' ).val( btn.data( 'home' ) );
        $( '#us-game-date' ).val( btn.data( 'date' ) );
        $( '#us-game-time' ).val( btn.data( 'time' ) );
        $( '#us-game-field' ).val( btn.data( 'field' ) );
        $( '#us-game-two-umps' ).prop( 'checked', btn.data( 'two' ) === 1 || btn.data( 'two' ) === '1' );
        $( '#us-game-dh' ).prop( 'checked', btn.data( 'dh' ) === 1 || btn.data( 'dh' ) === '1' );
        $( '#us-game-modal-error' ).prop( 'hidden', true );
        $( '#us-game-modal' ).css( 'display', 'flex' );
    } );

    $( '#us-game-modal-cancel' ).on( 'click', function () { $( '#us-game-modal' ).hide(); } );
    $( '#us-game-modal' ).on( 'click', function ( e ) { if ( e.target === this ) $( this ).hide(); } );

    // ── Save game (add or edit) ───────────────────────────────
    $( '#us-game-modal-save' ).on( 'click', function () {
        var $btn    = $( this );
        var game_id = $( '#us-game-modal-id' ).val();
        var isEdit  = game_id !== '0';
        var data    = {
            nonce,
            league_id:     $( '#us-game-league' ).val(),
            game_date:     $( '#us-game-date' ).val(),
            game_time:     $( '#us-game-time' ).val(),
            home_team:     $( '#us-game-home' ).val(),
            away_team:     $( '#us-game-away' ).val(),
            game_field:    $( '#us-game-field' ).val(),
            two_umpires:   $( '#us-game-two-umps' ).is( ':checked' ) ? '1' : '0',
            double_header: $( '#us-game-dh' ).is( ':checked' ) ? '1' : '0',
        };

        if ( ! data.league_id || ! data.game_date || ! data.home_team || ! data.away_team ) {
            $( '#us-game-modal-error' ).text( 'Please fill in all required fields.' ).prop( 'hidden', false );
            return;
        }

        data.action = isEdit ? 'us_allocator_update_game' : 'us_allocator_add_game';
        if ( isEdit ) data.game_id = game_id;

        $btn.prop( 'disabled', true ).text( 'Saving...' );
        $.post( ajaxUrl, data, function ( res ) {
            if ( res.success ) {
                $( '#us-game-modal' ).hide();
                location.reload();
            } else {
                $( '#us-game-modal-error' ).text( res.data || 'Error saving game.' ).prop( 'hidden', false );
                $btn.prop( 'disabled', false ).text( 'Save game' );
            }
        } );
    } );

    // ── Delete game ───────────────────────────────────────────
    $( document ).on( 'click', '.us-alloc-game-delete-btn', function () {
        var game_id = $( this ).data( 'game' );
        var label   = $( this ).data( 'label' );
        if ( ! confirm( 'Delete "' + label + '"? Assigned umpires will be notified. This cannot be undone.' ) ) return;
        $.post( ajaxUrl, { action: 'us_allocator_delete_game', nonce, game_id }, function ( res ) {
            if ( res.success ) { location.reload(); }
            else { alert( 'Error: could not delete game.' ); }
        } );
    } );

    // ── Cancel modal open ─────────────────────────────────────
    $( document ).on( 'click', '.us-alloc-game-cancel-btn', function () {
        cancelId = $( this ).data( 'game' );
        $( '#us-games-cancel-label' ).text( $( this ).data( 'label' ) );
        $( '#us-games-cancel-modal' ).css( 'display', 'flex' );
    } );

    $( '#us-games-cancel-close' ).on( 'click', function () { $( '#us-games-cancel-modal' ).hide(); } );
    $( '#us-games-cancel-modal' ).on( 'click', function ( e ) { if ( e.target === this ) $( this ).hide(); } );

    // ── Cancel confirm ────────────────────────────────────────
    $( '#us-games-cancel-confirm' ).on( 'click', function () {
        var $btn = $( this );
        $btn.prop( 'disabled', true ).text( 'Cancelling...' );
        $.post( ajaxUrl, { action: 'us_allocator_cancel_game', nonce, game_id: cancelId }, function ( res ) {
            if ( res.success ) {
                $( '#us-games-cancel-modal' ).hide();
                location.reload();
            } else {
                alert( 'Error: ' + ( res.data || 'Could not cancel game.' ) );
                $btn.prop( 'disabled', false ).text( 'Confirm cancellation' );
            }
        } );
    } );

    // ── Postpone modal open ───────────────────────────────────
    $( document ).on( 'click', '.us-alloc-game-postpone-btn', function () {
        postponeId = $( this ).data( 'game' );
        $( '#us-games-postpone-label' ).text( $( this ).data( 'label' ) );
        $( '#us-games-postpone-pay' ).prop( 'checked', false );
        $( '#us-games-postpone-modal' ).css( 'display', 'flex' );
    } );

    $( '#us-games-postpone-cancel' ).on( 'click', function () { $( '#us-games-postpone-modal' ).hide(); } );
    $( '#us-games-postpone-modal' ).on( 'click', function ( e ) { if ( e.target === this ) $( this ).hide(); } );

    // ── Postpone confirm ──────────────────────────────────────
    $( '#us-games-postpone-confirm' ).on( 'click', function () {
        var $btn    = $( this );
        var payUmps = $( '#us-games-postpone-pay' ).is( ':checked' ) ? '1' : '0';
        $btn.prop( 'disabled', true ).text( 'Saving...' );
        $.post( ajaxUrl, { action: 'us_postpone_game', nonce, game_id: postponeId, pay_umpires: payUmps }, function ( res ) {
            if ( res.success ) {
                $( '#us-games-postpone-modal' ).hide();
                location.reload();
            } else {
                alert( 'Error: ' + ( res.data || 'Could not postpone game.' ) );
                $btn.prop( 'disabled', false ).text( 'Confirm postponement' );
            }
        } );
    } );

} );
(function () {

    var umpireId   = usAvailability.umpire_id;
    var fetchNonce = usAvailability.fetch_nonce;
    var saveNonce  = usAvailability.save_nonce;
    var ajaxUrl    = usAvailability.ajax_url;
    var gameDates  = usAvailability.game_dates;
    var unavail    = [];

    var today      = new Date();
    today.setHours( 0, 0, 0, 0 );
    var curYear    = today.getFullYear();
    var curMonth   = today.getMonth();

    var isDragging = false;
    var dragAction = null;
    var dragDates  = [];

    function pad( n ) { return n < 10 ? '0' + n : n; }
    function dateStr( y, m, d ) { return y + '-' + pad( m + 1 ) + '-' + pad( d ); }
    function isUnavail( str ) { return unavail.indexOf( str ) > -1; }
    function isGame( str )    { return gameDates.indexOf( str ) > -1; }
    function isPast( y, m, d ){ return new Date( y, m, d ) < today; }

    // ── Fetch unavailable dates fresh from server ─────────────
    function fetchAndRender() {
        var xhr = new XMLHttpRequest();
        xhr.open( 'POST', ajaxUrl );
        xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
        xhr.onload = function () {
            try {
                var res = JSON.parse( xhr.responseText );
                if ( res.success ) {
                    unavail = res.data.dates || [];
                }
            } catch ( e ) {}
            render();
        };
        xhr.onerror = function () { render(); };
        xhr.send(
            'action=us_fetch_availability' +
            '&nonce='     + encodeURIComponent( fetchNonce ) +
            '&umpire_id=' + umpireId
        );
    }

    function render() {
        var grid   = document.getElementById( 'us-cal-grid' );
        var label  = document.querySelector( '.us-cal-month-label' );
        var months = [ 'January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December' ];

        label.textContent = months[ curMonth ] + ' ' + curYear;
        grid.innerHTML    = '';

        var firstDay    = new Date( curYear, curMonth, 1 ).getDay();
        var daysInMonth = new Date( curYear, curMonth + 1, 0 ).getDate();

        for ( var i = 0; i < firstDay; i++ ) {
            var empty       = document.createElement( 'div' );
            empty.className = 'us-cal-cell us-cal-cell--empty';
            grid.appendChild( empty );
        }

        for ( var d = 1; d <= daysInMonth; d++ ) {
            var str  = dateStr( curYear, curMonth, d );
            var cell = document.createElement( 'div' );
            cell.className    = 'us-cal-cell';
            cell.textContent  = d;
            cell.dataset.date = str;

            if ( isPast( curYear, curMonth, d ) ) {
                cell.classList.add( 'us-cal-cell--past' );
            } else if ( isGame( str ) ) {
                cell.classList.add( 'us-cal-cell--game' );
            } else if ( isUnavail( str ) ) {
                cell.classList.add( 'us-cal-cell--unavail' );
            } else {
                cell.classList.add( 'us-cal-cell--avail' );
            }

            if ( ! isPast( curYear, curMonth, d ) && ! isGame( str ) ) {
                cell.addEventListener( 'mousedown',  onMouseDown );
                cell.addEventListener( 'mouseover',  onMouseOver );
                cell.addEventListener( 'touchstart', onTouchStart, { passive: false } );
                cell.addEventListener( 'touchmove',  onTouchMove,  { passive: false } );
            }

            grid.appendChild( cell );
        }
    }

    function onMouseDown( e ) {
        e.preventDefault();
        var str    = this.dataset.date;
        isDragging = true;
        dragDates  = [ str ];
        dragAction = isUnavail( str ) ? 'remove' : 'add';
        updateCell( this, dragAction );
        document.addEventListener( 'mouseup', onMouseUp, { once: true } );
    }

    function onMouseUp() {
        if ( ! isDragging ) return;
        isDragging = false;
        save();
    }

    function onMouseOver( e ) {
        if ( ! isDragging ) return;
        var str = this.dataset.date;
        if ( dragDates.indexOf( str ) === -1 ) {
            dragDates.push( str );
            updateCell( this, dragAction );
        }
    }

    function onTouchStart( e ) {
        e.preventDefault();
        var touch = e.touches[0];
        var el    = document.elementFromPoint( touch.clientX, touch.clientY );
        if ( ! el || ! el.dataset.date ) return;
        var str    = el.dataset.date;
        isDragging = true;
        dragDates  = [ str ];
        dragAction = isUnavail( str ) ? 'remove' : 'add';
        updateCell( el, dragAction );
    }

    function onTouchMove( e ) {
        e.preventDefault();
        if ( ! isDragging ) return;
        var touch = e.touches[0];
        var el    = document.elementFromPoint( touch.clientX, touch.clientY );
        if ( ! el || ! el.dataset.date ) return;
        var str = el.dataset.date;
        if ( dragDates.indexOf( str ) === -1 ) {
            dragDates.push( str );
            updateCell( el, dragAction );
        }
    }

    document.addEventListener( 'mouseup',  function () { if ( ! isDragging ) return; isDragging = false; save(); } );
    document.addEventListener( 'touchend', function () { if ( ! isDragging ) return; isDragging = false; save(); } );

    function updateCell( cell, action ) {
        if ( action === 'add' ) {
            cell.classList.remove( 'us-cal-cell--avail' );
            cell.classList.add( 'us-cal-cell--unavail' );
            if ( unavail.indexOf( cell.dataset.date ) === -1 ) {
                unavail.push( cell.dataset.date );
            }
        } else {
            cell.classList.remove( 'us-cal-cell--unavail' );
            cell.classList.add( 'us-cal-cell--avail' );
            var idx = unavail.indexOf( cell.dataset.date );
            if ( idx > -1 ) unavail.splice( idx, 1 );
        }
    }

    function save() {
        var status       = document.getElementById( 'us-cal-status' );
        status.textContent = 'Saving...';
        status.className   = 'us-cal-status us-cal-status--saving';

        var xhr = new XMLHttpRequest();
        xhr.open( 'POST', ajaxUrl );
        xhr.setRequestHeader( 'Content-Type', 'application/x-www-form-urlencoded' );
        xhr.onload = function () {
            try {
                var res = JSON.parse( xhr.responseText );
                if ( res.success ) {
                    status.textContent = 'Saved \u2014 ' + res.data.count + ' unavailable date(s)';
                    status.className   = 'us-cal-status us-cal-status--saved';
                } else {
                    status.textContent = 'Error saving. Please try again.';
                    status.className   = 'us-cal-status us-cal-status--error';
                }
            } catch ( e ) {
                status.textContent = 'Error saving. Please try again.';
                status.className   = 'us-cal-status us-cal-status--error';
            }
            setTimeout( function () {
                status.textContent = '';
                status.className   = 'us-cal-status';
            }, 3000 );
        };
        xhr.send(
            'action=us_save_availability' +
            '&nonce='     + encodeURIComponent( saveNonce ) +
            '&umpire_id=' + umpireId +
            '&dates='     + encodeURIComponent( JSON.stringify( unavail ) )
        );
    }

    document.querySelector( '.us-cal-prev' ).addEventListener( 'click', function () {
        curMonth--;
        if ( curMonth < 0 ) { curMonth = 11; curYear--; }
        render();
    } );

    document.querySelector( '.us-cal-next' ).addEventListener( 'click', function () {
        curMonth++;
        if ( curMonth > 11 ) { curMonth = 0; curYear++; }
        render();
    } );

    fetchAndRender();

})();
(function() {
    var btn     = document.getElementById('us-menu-btn');
    var flyout  = document.getElementById('us-flyout');
    var overlay = document.getElementById('us-flyout-overlay');
    var close   = document.getElementById('us-flyout-close');

    if ( ! btn ) return;

    function openMenu() {
        flyout.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeMenu() {
        flyout.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    btn.addEventListener('click', openMenu);
    close.addEventListener('click', closeMenu);
    overlay.addEventListener('click', closeMenu);

    document.addEventListener('keydown', function(e) {
        if ( e.key === 'Escape' ) closeMenu();
    });
})();
// ── Open game request ─────────────────────────────────────────
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.us-request-game');
        if ( ! btn ) return;

        var gameId   = btn.dataset.game;
        var position = btn.dataset.position;
        var nonce    = btn.dataset.nonce;
        var label    = btn.dataset.label || 'Request';

        btn.disabled    = true;
        btn.textContent = 'Requesting\u2026';

        fetch(usFront.ajax_url, {
            method  : 'POST',
            headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body    : new URLSearchParams({
                action   : 'us_request_game',
                game_id  : gameId,
                position : position,
                nonce    : nonce,
            }),
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if ( data.success ) {
                var assignmentId = data.data.assignment_id;
                var posLabel     = position.charAt(0).toUpperCase() + position.slice(1);
                var wrapper      = btn.parentElement;

                var pending         = document.createElement('span');
                pending.className   = 'us-og-btn us-og-btn--pending';
                pending.textContent = '\u25CF ' + posLabel + ' pending';

                var cancel               = document.createElement('button');
                cancel.className         = 'us-og-btn us-og-btn--cancel us-cancel-request';
                cancel.dataset.assignment = assignmentId;
                cancel.textContent       = 'Cancel';

                wrapper.replaceChild(pending, btn);
                wrapper.appendChild(cancel);
            } else {
                btn.disabled    = false;
                btn.textContent = label;
                alert(data.data || 'Could not submit request. Please try again.');
            }
        })
        .catch(function () {
            btn.disabled    = false;
            btn.textContent = label;
            alert('Network error. Please try again.');
        });
    });
})();

// ── Umpire list contact toggle ────────────────────────────────
(function () {
    document.querySelectorAll('.us-contact-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('us-contact-' + btn.dataset.umpire);
            if ( form ) form.hidden = ! form.hidden;
        });
    });

    document.querySelectorAll('.us-contact-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var form = document.getElementById('us-contact-' + btn.dataset.umpire);
            if ( form ) form.hidden = true;
        });
    });
})();
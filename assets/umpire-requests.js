(function () {

    // ── Inject confirm modal ──────────────────────────────────
    var modalHTML =
        '<div id="us-confirm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:99999;align-items:center;justify-content:center">' +
            '<div style="background:#fff;border-radius:10px;padding:24px;max-width:320px;width:90%;box-shadow:0 4px 24px rgba(0,0,0,0.2);text-align:center">' +
                '<p id="us-confirm-msg" style="margin:0 0 20px;font-size:15px;color:#333;line-height:1.5"></p>' +
                '<div style="display:flex;gap:10px;justify-content:center">' +
                    '<button id="us-confirm-ok" style="background:#d63638;color:#fff;border:none;padding:10px 24px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer">Confirm</button>' +
                    '<button id="us-confirm-cancel" style="background:#f0f0f0;color:#333;border:none;padding:10px 24px;border-radius:6px;font-size:14px;font-weight:600;cursor:pointer">Cancel</button>' +
                '</div>' +
            '</div>' +
        '</div>';

    document.addEventListener('DOMContentLoaded', function () {
        document.body.insertAdjacentHTML('beforeend', modalHTML);
    });

    function usConfirm(message, onConfirm) {
        var modal  = document.getElementById('us-confirm-modal');
        var msg    = document.getElementById('us-confirm-msg');
        var okBtn  = document.getElementById('us-confirm-ok');
        var canBtn = document.getElementById('us-confirm-cancel');

        msg.textContent  = message;
        modal.style.display = 'flex';

        function cleanup() {
            modal.style.display = 'none';
            okBtn.removeEventListener('click', handleOk);
            canBtn.removeEventListener('click', handleCancel);
            modal.removeEventListener('click', handleBackdrop);
        }

        function handleOk() {
            cleanup();
            onConfirm();
        }

        function handleCancel() {
            cleanup();
        }

        function handleBackdrop(e) {
            if (e.target === modal) cleanup();
        }

        okBtn.addEventListener('click', handleOk);
        canBtn.addEventListener('click', handleCancel);
        modal.addEventListener('click', handleBackdrop);
    }

    // ── Cancel request ────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.us-cancel-request');
        if (!btn) return;

        var assignmentId = btn.dataset.assignment;
        var row          = btn.closest('tr') || btn.closest('li') || btn.parentElement;

        usConfirm('Cancel your request for this game?', function () {
            btn.disabled    = true;
            btn.textContent = 'Cancelling\u2026';

            fetch(usFront.ajax_url, {
                method  : 'POST',
                headers : { 'Content-Type': 'application/x-www-form-urlencoded' },
                body    : new URLSearchParams({
                    action        : 'us_cancel_request',
                    assignment_id : assignmentId,
                    nonce         : usFront.nonce,
                }),
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success) {
                    if (row) {
                        row.style.transition = 'opacity 0.3s';
                        row.style.opacity    = '0';
                        setTimeout(function () { row.remove(); }, 300);
                    }
                } else {
                    usConfirm(data.data || 'Could not cancel request. Please try again.', function(){});
                    btn.disabled    = false;
                    btn.textContent = 'Cancel request';
                }
            })
            .catch(function () {
                usConfirm('Network error. Please try again.', function(){});
                btn.disabled    = false;
                btn.textContent = 'Cancel request';
            });
        });
    });

})();
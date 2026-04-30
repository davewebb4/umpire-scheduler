jQuery(function($){

    var nonce = usAssign.nonce;

    // ── Assign umpire ─────────────────────────────────────────
    $(document).on('change', '.us-assign-select', function(){
        var $select   = $(this);
        var game_id   = $select.data('game');
        var position  = $select.data('position');
        var umpire_id = $select.val();
        var $row      = $select.closest('tr');

        $select.prop('disabled', true);

        $.post(usAssign.ajax_url, {
            action:    'us_assign_umpire',
            nonce:     nonce,
            game_id:   game_id,
            umpire_id: umpire_id,
            position:  position,
        }, function(res){
            $select.prop('disabled', false);
            if ( res.success ) {
                $select.data('assignment', res.data.assignment_id || '');
                updateStatusCell( $row );
                location.reload();
            }
        });
    });

    // ── Admin manually confirms ───────────────────────────────
    $(document).on('click', '.us-confirm-btn', function(){
        var $btn          = $(this);
        var assignment_id = $btn.data('assignment');

        $btn.prop('disabled', true).text('Saving...');

        $.post(usAssign.ajax_url, {
            action:        'us_confirm_assignment',
            nonce:         nonce,
            assignment_id: assignment_id,
        }, function(res){
            if ( res.success ) {
                location.reload();
            }
        });
    });

    // ── No-show ───────────────────────────────────────────────
    $(document).on('click', '.us-noshow-btn', function(){
        var $btn          = $(this);
        var assignment_id = $btn.data('assignment');

        if ( ! confirm('Mark this umpire as a no-show? Pay will be set to $0.') ) return;

        $btn.prop('disabled', true);

        $.post(usAssign.ajax_url, {
            action:        'us_mark_noshow',
            nonce:         nonce,
            assignment_id: assignment_id,
        }, function(res){
            if ( res.success ) {
                location.reload();
            }
        });
    });

    // ── Delete game ───────────────────────────────────────────
    $(document).on('click', '.us-delete-game-btn', function(){
        var $btn    = $(this);
        var game_id = $btn.data('game');

        if ( ! confirm('Delete this game?') ) return;

        $btn.prop('disabled', true).text('...');

        $.post(usAssign.ajax_url, {
            action:  'us_delete_game',
            nonce:   nonce,
            game_id: game_id,
            force:   '0',
        }, function(res){
            if ( res.success ) {
                $btn.closest('tr').fadeOut(300, function(){ $(this).remove(); });
            } else if ( res.data && res.data.code === 'has_assignments' ) {
                if ( confirm( res.data.msg ) ) {
                    $.post(usAssign.ajax_url, {
                        action:  'us_delete_game',
                        nonce:   nonce,
                        game_id: game_id,
                        force:   '1',
                    }, function(res2){
                        if ( res2.success ) {
                            $btn.closest('tr').fadeOut(300, function(){ $(this).remove(); });
                        } else {
                            $btn.prop('disabled', false).text('Delete');
                            alert('Could not delete game.');
                        }
                    });
                } else {
                    $btn.prop('disabled', false).text('Delete');
                }
            } else {
                $btn.prop('disabled', false).text('Delete');
                alert('Could not delete game.');
            }
        });
    });

    // ── Edit game inline ──────────────────────────────────────
    $(document).on('click', '.us-edit-game-btn', function(){
        var $btn    = $(this);
        var game_id = $btn.data('game');
        var $row    = $btn.closest('tr');

        // Toggle if already open
        if ( $row.next('.us-edit-game-row').length ) {
            $row.next('.us-edit-game-row').remove();
            $btn.text('Edit');
            return;
        }

        var date  = $btn.data('date')  || '';
        var time  = $btn.data('time')  || '';
        var field = $btn.data('field') || '';
        var dh    = $btn.data('dh')    || '0';
        var cols  = $row.find('td').length;

        var $editRow = $('<tr class="us-edit-game-row" style="background:#f0f5fa"><td colspan="' + cols + '" style="padding:12px 16px"></td></tr>');
        var $inner   = $('<div style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap"></div>');

        $inner.append(
            '<div><label style="display:block;font-size:11px;font-weight:600;color:#091b33;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Date</label>' +
            '<input type="date" class="us-edit-date" value="' + date + '" style="padding:5px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px"></div>'
        );
        $inner.append(
            '<div><label style="display:block;font-size:11px;font-weight:600;color:#091b33;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Time</label>' +
            '<input type="time" class="us-edit-time" value="' + time + '" style="padding:5px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px"></div>'
        );
        $inner.append(
            '<div style="flex:1;min-width:160px"><label style="display:block;font-size:11px;font-weight:600;color:#091b33;margin-bottom:4px;text-transform:uppercase;letter-spacing:.04em">Field</label>' +
            '<input type="text" class="us-edit-field" value="' + field + '" style="width:100%;padding:5px 8px;border:1px solid #ddd;border-radius:4px;font-size:13px"></div>'
        );
        $inner.append(
            '<div style="align-self:center;padding-bottom:2px">' +
            '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px;color:#091b33;font-weight:500">' +
            '<input type="checkbox" class="us-edit-dh" value="1"' + ( dh === '1' ? ' checked' : '' ) + ' style="width:15px;height:15px;cursor:pointer">' +
            'Optional pay rate</label>' +
            '<span style="display:block;font-size:11px;color:#92400e;margin-top:3px;margin-left:21px">Uses optional pay rate</span>' +
            '</div>'
        );
        $inner.append(
            '<div style="display:flex;gap:6px;align-items:center">' +
            '<button class="us-save-game-btn button button-primary" data-game="' + game_id + '" style="font-size:12px">Save</button>' +
            '<button class="us-cancel-edit-btn button" style="font-size:12px">Cancel</button>' +
            '<span class="us-edit-msg" style="font-size:12px;color:#666;margin-left:4px"></span>' +
            '</div>'
        );

        $editRow.find('td').append( $inner );
        $row.after( $editRow );
        $btn.text('Close');
    });

    // ── Cancel edit ───────────────────────────────────────────
    $(document).on('click', '.us-cancel-edit-btn', function(){
        var $editRow = $(this).closest('.us-edit-game-row');
        $editRow.prev('tr').find('.us-edit-game-btn').text('Edit');
        $editRow.remove();
    });

    // ── Save game changes ─────────────────────────────────────
    $(document).on('click', '.us-save-game-btn', function(){
        var $btn      = $(this);
        var $editRow  = $btn.closest('.us-edit-game-row');
        var $gameRow  = $editRow.prev('tr');
        var game_id   = $btn.data('game');
        var new_date  = $editRow.find('.us-edit-date').val();
        var new_time  = $editRow.find('.us-edit-time').val();
        var new_field = $editRow.find('.us-edit-field').val();
        var new_dh    = $editRow.find('.us-edit-dh').is(':checked') ? '1' : '0';
        var $msg      = $editRow.find('.us-edit-msg');

        $btn.prop('disabled', true).text('Saving...');
        $msg.text('').css('color', '#666');

        $.post(usAssign.ajax_url, {
            action:          'us_update_game',
            nonce:           nonce,
            game_id:         game_id,
            game_date:       new_date,
            game_time:       new_time,
            game_field:      new_field,
            game_dh:         new_dh,
        }, function(res){
            $btn.prop('disabled', false).text('Save');
            if ( res.success ) {
                $msg.text( res.data.msg ).css('color', res.data.changed ? '#00a32a' : '#666');

                if ( res.data.changed ) {
                    var $editBtn = $gameRow.find('.us-edit-game-btn');
                    $editBtn.data('date',  new_date);
                    $editBtn.data('time',  new_time);
                    $editBtn.data('field', new_field);
                    $editBtn.data('dh',    new_dh);

                    if ( new_time ) {
                        var d    = new Date( '1970-01-01T' + new_time );
                        var hrs  = d.getHours();
                        var mins = d.getMinutes().toString().padStart(2, '0');
                        var ampm = hrs >= 12 ? 'pm' : 'am';
                        hrs      = hrs % 12 || 12;
                        $gameRow.find('td:first').text( hrs + ':' + mins + ' ' + ampm );
                    }

                    // Update DH badge in game name cell
                    var $gameCell = $gameRow.find('td:eq(1)');
                    $gameCell.find('.us-dh-badge').remove();
                    if ( new_dh === '1' ) {
                        $gameCell.append('<span class="us-dh-badge" style="font-size:11px;background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:3px;margin-left:4px">DH</span>');
                    }

                    setTimeout(function(){
                        $editBtn.text('Edit');
                        $editRow.remove();
                    }, 1500);
                }
            } else {
                $msg.text('Error saving. Please try again.').css('color', '#d63638');
            }
        });
    });

    // ── Status cell update helper ─────────────────────────────
    function updateStatusCell( $row ) {
        var plateVal = $row.find('.us-assign-select[data-position="plate"]').val();
        var baseVal  = $row.find('.us-assign-select[data-position="base"]').val();
        var $status  = $row.find('.us-status-cell');

        if ( ! plateVal && ! baseVal ) {
            $status.html('<span style="color:#d63638;font-weight:500">Open</span>');
        } else if ( plateVal && baseVal ) {
            $status.html('<span style="color:#00a32a;font-weight:500">Staffed</span>');
        } else {
            $status.html('<span style="color:#dba617;font-weight:500">Pending</span>');
        }
    }

});
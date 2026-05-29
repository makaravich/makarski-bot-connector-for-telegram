jQuery(function ($) {

    // --- Token show/hide toggle ---
    $(document).on('click', '.tgbot-token-toggle', function () {
        var $btn   = $(this);
        var $input = $('#' + $btn.data('target'));
        var $icon  = $btn.find('.dashicons');

        if ($input.attr('type') === 'password') {
            $input.attr('type', 'text');
            $icon.removeClass('dashicons-visibility').addClass('dashicons-hidden');
        } else {
            $input.attr('type', 'password');
            $icon.removeClass('dashicons-hidden').addClass('dashicons-visibility');
        }
    });

    // --- Webhook panel ---

    var $status  = $('#tgbot-webhook-status');
    var $btnSet  = $('#tgbot-set-webhook');
    var $btnCheck = $('#tgbot-check-webhook');
    var $btnDel  = $('#tgbot-delete-webhook');

    if (!$status.length) return;

    function webhookRequest(action, onDone) {
        $status.html('<span class="spinner is-active tgbot-webhook-spinner"></span>');
        setButtonsDisabled(true);

        $.post(tgbotAdmin.ajaxUrl, {
            action:         'tgbot_webhook_action',
            nonce:          tgbotAdmin.nonce,
            webhook_action: action
        })
        .done(function (res) {
            if (res.success) {
                onDone(res.data);
            } else {
                showError(res.data && res.data.message ? res.data.message : 'Error');
            }
        })
        .fail(function () {
            showError('Request failed. Check your connection.');
        })
        .always(function () {
            setButtonsDisabled(false);
        });
    }

    function setButtonsDisabled(state) {
        $btnSet.prop('disabled', state);
        $btnCheck.prop('disabled', state);
        $btnDel.prop('disabled', state);
    }

    function showStatus(data) {
        var url          = data.url || '';
        var pendingCount = data.pending_update_count || 0;
        var lastError    = data.last_error_message || '';
        var configured   = tgbotAdmin.endpoint ? tgbotAdmin.siteUrl.replace(/\/$/, '') + '/' + tgbotAdmin.endpoint.replace(/^\//, '') : '';

        var isActive  = !!url;
        var isMatched = url && configured && url === configured;
        var hasError  = !!lastError;

        var icon, cls, text;

        if (!isActive) {
            icon = 'dashicons-minus';
            cls  = 'tgbot-status--inactive';
            text = 'Webhook not set';
        } else if (hasError) {
            icon = 'dashicons-warning';
            cls  = 'tgbot-status--error';
            text = url + ' — <strong>' + escHtml(lastError) + '</strong>';
        } else if (!isMatched && configured) {
            icon = 'dashicons-warning';
            cls  = 'tgbot-status--mismatch';
            text = escHtml(url) + ' <em>(does not match configured endpoint)</em>';
        } else {
            icon = 'dashicons-yes-alt';
            cls  = 'tgbot-status--ok';
            text = escHtml(url);
        }

        if (pendingCount > 0) {
            text += ' &nbsp;<span class="tgbot-pending">Pending: ' + pendingCount + '</span>';
        }

        $status.html(
            '<span class="tgbot-status ' + cls + '">' +
            '<span class="dashicons ' + icon + '"></span> ' + text +
            '</span>'
        );
    }

    function showError(msg) {
        $status.html(
            '<span class="tgbot-status tgbot-status--error">' +
            '<span class="dashicons dashicons-dismiss"></span> ' + escHtml(msg) +
            '</span>'
        );
    }

    function escHtml(str) {
        return $('<div>').text(str).html();
    }

    // Auto-check on page load
    webhookRequest('check', showStatus);

    $btnCheck.on('click', function () {
        webhookRequest('check', showStatus);
    });

    $btnSet.on('click', function () {
        webhookRequest('set', function (data) {
            // After set, always re-check to get full info
            webhookRequest('check', showStatus);
        });
    });

    $btnDel.on('click', function () {
        if (!confirm('Delete webhook?')) return;
        webhookRequest('delete', function () {
            webhookRequest('check', showStatus);
        });
    });

});

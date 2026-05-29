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

});

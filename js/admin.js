/**
 * NB Chain Link - Admin JS
 * Version 1.1.0 - December 22, 2025
 */

(function($) {
    'use strict';

    $(document).ready(function() {

        // Toggle invite code field based on ring type
        $('#ring_type').on('change', function() {
            var type = $(this).val();
            if (type === 'private') {
                $('#secret_row').show();
            } else {
                $('#secret_row').hide();
            }
        }).trigger('change');

        // Media uploader for banner image
        $('.nb-media-upload').on('click', function(e) {
            e.preventDefault();

            var button = $(this);
            var targetId = button.data('target');
            var targetInput = $('#' + targetId);

            var frame = wp.media({
                title: 'Select Banner Image',
                button: { text: 'Use this image' },
                multiple: false
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                targetInput.val(attachment.url);

                // Update preview if exists
                var preview = button.siblings('img');
                if (preview.length) {
                    preview.attr('src', attachment.url);
                } else {
                    button.after('<img src="' + attachment.url + '" style="max-width:200px;margin-top:10px;display:block;">');
                }
            });

            frame.open();
        });

        // Toggle member list in joined rings
        $('.nb-toggle-members').on('click', function() {
            var btn = $(this);
            var content = btn.closest('.nb-member-list').find('.nb-members-content');

            content.slideToggle(200, function() {
                btn.text(content.is(':visible') ? 'Hide' : 'Show');
            });
        });

        // Auto-generate ring ID from name
        $('input[name="ring_name"]').on('input', function() {
            var name = $(this).val();
            var id = name.toLowerCase()
                .replace(/[^a-z0-9\s-]/g, '')
                .replace(/\s+/g, '-')
                .substring(0, 30);

            var idInput = $('input[name="ring_id"]');
            if (!idInput.data('manual')) {
                idInput.val(id);
            }
        });

        // Mark ring ID as manually edited
        $('input[name="ring_id"]').on('input', function() {
            $(this).data('manual', true);
        });

        // Copy shortcode to clipboard
        $('.nb-shortcode').on('click', function() {
            var code = $(this).text();

            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function() {
                    alert('Shortcode copied to clipboard!');
                });
            } else {
                // Fallback
                var temp = $('<input>').val(code).appendTo('body').select();
                document.execCommand('copy');
                temp.remove();
                alert('Shortcode copied to clipboard!');
            }
        });

    });

})(jQuery);

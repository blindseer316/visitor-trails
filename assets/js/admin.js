/* Visitor Trails — Admin JS v2.6.1 */
jQuery(function ($) {

    // ── Trail expand / collapse ───────────────────────────────────────────────

    $(document).on('click', '.vt-trail-toggle', function () {
        var id   = $(this).data('id');
        var $row = $('#vt-trail-' + id);
        var open = $row.is(':visible');

        $row.toggle(!open);
        $(this).text(open ? 'Trail ▾' : 'Trail ▴');
    });

    // ── Conversion star toggle ────────────────────────────────────────────────

    $(document).on('click', '.vt-conv-btn', function () {
        var $btn       = $(this);
        var session_id = $btn.data('id');

        $btn.prop('disabled', true);

        $.post(VT_Admin.ajaxurl, {
            action:     'vt_convert',
            nonce:      VT_Admin.nonce,
            session_id: session_id,
        })
        .done(function (res) {
            if (res.success) {
                var converted = res.data.converted;
                $btn.text(converted ? '★' : '☆')
                    .toggleClass('vt-conv-yes', !!converted)
                    .attr('title', converted ? 'Mark unconverted' : 'Mark converted');

                // Highlight the whole row
                $btn.closest('tr').toggleClass('vt-converted', !!converted);
            }
        })
        .always(function () {
            $btn.prop('disabled', false);
        });
    });

    // ── Technical details toggle (element id/classes on click events) ────────

    var TECH_KEY = 'vt_show_tech_details';

    function setTechState(show) {
        $('.vt-wrap').toggleClass('vt-show-tech', show);
        $('#vt-toggle-tech').text(show ? '🔧 Hide technical details' : '🔧 Show technical details');
        try { localStorage.setItem(TECH_KEY, show ? '1' : '0'); } catch (e) {}
    }

    var techInitial = false;
    try { techInitial = localStorage.getItem(TECH_KEY) === '1'; } catch (e) {}
    setTechState(techInitial);

    $(document).on('click', '#vt-toggle-tech', function () {
        setTechState(!$('.vt-wrap').hasClass('vt-show-tech'));
    });

    // ── Tag description inline edit ───────────────────────────────────────────
    // Click a tag badge to attach/edit a freeform note (e.g. what "fbgrpreal"
    // actually refers to), shown as its tooltip afterward. Saves on blur/Enter,
    // Escape cancels. All badges sharing the same tag update together since
    // the description belongs to the tag string, not any one session row.

    $(document).on('click', '.vt-tag-editable', function () {
        var $badge = $(this);
        if ($badge.data('editing')) return;
        $badge.data('editing', true);

        var tag  = $badge.data('tag');
        var desc = $badge.attr('data-desc') || '';

        var $input = $('<input>', {
            type:        'text',
            class:       'vt-tag-desc-input',
            val:         desc,
            placeholder: 'Add a description…',
            maxlength:   255,
        });

        $badge.hide().after($input);
        $input.trigger('focus').select();

        function cleanup() {
            $input.remove();
            $badge.show().data('editing', false);
        }

        function save() {
            var newDesc = $input.val();
            cleanup();
            if (newDesc === desc) return;

            $.post(VT_Admin.ajaxurl, {
                action:      'vt_save_tag_desc',
                nonce:       VT_Admin.nonce,
                tag:         tag,
                description: newDesc,
            }).done(function (res) {
                if (!res.success) return;
                var saved = res.data.description;
                $('.vt-tag-editable[data-tag="' + tag + '"]').attr('data-desc', saved);
            });
        }

        $input.on('blur', save);
        $input.on('keydown', function (e) {
            if (e.key === 'Enter')  { e.preventDefault(); $input.trigger('blur'); }
            if (e.key === 'Escape') { $input.off('blur'); cleanup(); }
        });
    });

});

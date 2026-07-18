/* Visitor Trails — Admin JS v2.4.0 */
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

});

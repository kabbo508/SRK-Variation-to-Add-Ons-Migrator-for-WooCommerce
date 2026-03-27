jQuery(function($){
    let isRunning = false;

    function currentProductId() {
        return parseInt($('#srk-vtam-product-search').val(), 10) || 0;
    }

    function setCounts(payload) {
        if (!payload || !payload.counts || !payload.state) return;
        $('#srk-count-scope').text(payload.counts.scope_total || 0);
        $('#srk-count-default').text(payload.counts.variable_with_default || 0);
        $('#srk-count-without').text(payload.counts.variable_without_addons || 0);
        $('#srk-count-with').text(payload.counts.variable_with_addons || 0);
        $('#srk-count-converted').text(payload.state.converted || 0);
        $('#srk-count-skipped').text(payload.state.skipped || 0);
        $('#srk-count-failed').text(payload.state.failed || 0);

        const logLines = payload.state.log_lines || [];
        $('#srk-vtam-log').text(logLines.join("\n"));

        let failedLines = [];
        if (payload.state.failed_products) {
            failedLines = Object.values(payload.state.failed_products);
        }
        if (!failedLines.length && payload.counts.remaining_names) {
            failedLines = payload.counts.remaining_names;
        }
        $('#srk-vtam-failed-list').text(failedLines.join("\n"));

        let status = '';
        if (payload.state.running) {
            status = 'Batch is running...';
        } else if (payload.state.done) {
            status = 'Run completed.';
        } else {
            status = 'Ready.';
        }
        $('#srk-vtam-status-text').text(status);
    }

    function getStatus() {
        return $.post(SRKVTAM.ajaxurl, {
            action: 'srk_vtam_get_status',
            nonce: SRKVTAM.nonce
        });
    }

    function runBatch(reset) {
        if (isRunning) return;
        isRunning = true;
        $('#srk-vtam-run').prop('disabled', true);

        const batchSize = parseInt($('#srk-vtam-batch-size').val(), 10) || 10;
        const selectedProductId = currentProductId();

        function step(firstPass) {
            $.post(SRKVTAM.ajaxurl, {
                action: 'srk_vtam_run_batch',
                nonce: SRKVTAM.nonce,
                batch_size: batchSize,
                selected_product_id: selectedProductId,
                reset: firstPass ? 1 : 0
            }).done(function(resp){
                if (resp.success && resp.data) {
                    setCounts(resp.data);
                    if (resp.data.state && !resp.data.state.done) {
                        step(false);
                    } else {
                        isRunning = false;
                        $('#srk-vtam-run').prop('disabled', false);
                    }
                } else {
                    isRunning = false;
                    $('#srk-vtam-run').prop('disabled', false);
                    alert(resp.data && resp.data.message ? resp.data.message : SRKVTAM.i18n.batchFailed);
                }
            }).fail(function(){
                isRunning = false;
                $('#srk-vtam-run').prop('disabled', false);
                alert(SRKVTAM.i18n.requestFailed);
            });
        }

        step(!!reset);
    }

    $('#srk-vtam-product-search').selectWoo({
        allowClear: true,
        placeholder: SRKVTAM.i18n.searching,
        ajax: {
            url: SRKVTAM.ajaxurl,
            dataType: 'json',
            delay: 250,
            data: function(params) {
                return {
                    action: 'srk_vtam_search_products',
                    nonce: SRKVTAM.nonce,
                    term: params.term || ''
                };
            },
            processResults: function(data) {
                return { results: data || [] };
            },
            cache: true
        },
        minimumInputLength: 1
    });

    $('#srk-vtam-product-search').on('change', function(){
        $('#srk-vtam-status-text').text('Selection changed. Click Run to start a fresh scoped run.');
    });

    $('#srk-vtam-run').on('click', function(){
        runBatch(true);
    });

    $('#srk-vtam-reset').on('click', function(){
        $.post(SRKVTAM.ajaxurl, {
            action: 'srk_vtam_reset_run',
            nonce: SRKVTAM.nonce,
            selected_product_id: currentProductId()
        }).done(function(resp){
            if (resp.success && resp.data) {
                setCounts(resp.data);
            }
        });
    });

    getStatus().done(function(resp){
        if (resp.success && resp.data) {
            setCounts(resp.data);
        }
    });
});

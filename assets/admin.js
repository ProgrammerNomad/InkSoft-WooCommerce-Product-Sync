(function($){
    function log(msg){
        var $log = $('#inksoft-sync-log');
        $log.append(msg + "\n");
        $log.scrollTop($log[0].scrollHeight);
    }

    $('#inksoft-start-sync').on('click', function(e){
        e.preventDefault();
        var btn = $(this);
        btn.prop('disabled', true).text('Starting...');

        $.post(InkSoftWoo.ajax_url, { action: 'inksoft_woo_sync_start', nonce: InkSoftWoo.nonce }, function(resp){
            if (!resp.success){
                log('Start failed: ' + JSON.stringify(resp));
                btn.prop('disabled', false).text('Start Sync (AJAX)');
                return;
            }

            var stores = resp.data.stores || [];
            if (stores.length === 0){
                log('No stores configured. Please add store URIs in settings.');
                btn.prop('disabled', false).text('Start Sync (AJAX)');
                return;
            }

            // process stores sequentially
            var pageSize = InkSoftWoo.settings.page_size || 100;
            function processStore(i){
                if (i >= stores.length){
                    log('All stores processed');
                    btn.prop('disabled', false).text('Start Sync (AJAX)');
                    return;
                }
                var store = stores[i];
                log('Starting store: ' + store);
                processPage(store, 0, function(){
                    log('Finished store: ' + store);
                    processStore(i+1);
                });
            }

            function processPage(store, page, cb){
                log('Processing ' + store + ' page ' + page);
                $.post(InkSoftWoo.ajax_url, { action: 'inksoft_woo_sync_process_chunk', nonce: InkSoftWoo.nonce, store: store, page: page, page_size: pageSize }, function(res){
                    if (!res.success){
                        log('Error: ' + JSON.stringify(res));
                        cb();
                        return;
                    }
                    var data = res;
                    if (data.logs && data.logs.length){
                        data.logs.forEach(function(l){ log(l); });
                    }
                    if (data.nextPage !== null && data.nextPage !== undefined){
                        // continue to next page
                        setTimeout(function(){ processPage(store, data.nextPage, cb); }, 200);
                    } else {
                        cb();
                    }
                }).fail(function(xhr){
                    log('AJAX error: ' + xhr.statusText);
                    cb();
                });
            }

            processStore(0);
        }).fail(function(xhr){
            log('Start request failed: ' + xhr.statusText);
            btn.prop('disabled', false).text('Start Sync (AJAX)');
        });
    });
})(jQuery);

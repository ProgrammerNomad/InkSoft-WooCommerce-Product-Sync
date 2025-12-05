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

            var totalProcessed = 0;
            var totalProducts = 0;

            function processStore(i){
                if (i >= stores.length){
                    log('All stores processed. Total products: ' + totalProcessed);
                    btn.prop('disabled', false).text('Start Sync (AJAX)');
                    return;
                }
                var store = stores[i];
                log('Starting store: ' + store);
                log('Fetching product list...');
                
                $.post(InkSoftWoo.ajax_url, { 
                    action: 'inksoft_woo_get_product_list', 
                    nonce: InkSoftWoo.nonce, 
                    store: store 
                }, function(resp){
                    if (!resp.success){
                        log('Failed to fetch product list: ' + (resp.data || 'Unknown error'));
                        processStore(i+1);
                        return;
                    }

                    var products = resp.data.products || [];
                    totalProducts = resp.data.total || products.length;
                    log('Found ' + totalProducts + ' products in store ' + store);

                    if (products.length === 0){
                        log('No products to sync in store ' + store);
                        processStore(i+1);
                        return;
                    }

                    processProducts(store, products, 0, function(){
                        log('Finished store: ' + store);
                        processStore(i+1);
                    });
                }).fail(function(xhr){
                    log('Failed to fetch product list: ' + xhr.statusText);
                    processStore(i+1);
                });
            }

            function processProducts(store, products, index, cb){
                if (index >= products.length){
                    cb();
                    return;
                }

                var product = products[index];
                var progress = (index + 1) + '/' + products.length;
                log('[' + progress + '] Syncing product: ' + product.name + ' (ID: ' + product.id + ')');

                $.post(InkSoftWoo.ajax_url, {
                    action: 'inksoft_woo_sync_single_product',
                    nonce: InkSoftWoo.nonce,
                    store: store,
                    product_id: product.id
                }, function(resp){
                    if (resp.success){
                        var logs = resp.data.logs || [];
                        logs.forEach(function(l){ log('  ' + l); });
                        log('[' + progress + '] Success: ' + product.name);
                        totalProcessed++;
                    } else {
                        log('[' + progress + '] Failed: ' + product.name + ' - ' + (resp.data.message || 'Unknown error'));
                        if (resp.data.logs){
                            resp.data.logs.forEach(function(l){ log('  ' + l); });
                        }
                    }
                    setTimeout(function(){ processProducts(store, products, index + 1, cb); }, 100);
                }).fail(function(xhr){
                    log('[' + progress + '] AJAX error for ' + product.name + ': ' + xhr.statusText);
                    setTimeout(function(){ processProducts(store, products, index + 1, cb); }, 100);
                });
            }

            processStore(0);
        }).fail(function(xhr){
            log('Start request failed: ' + xhr.statusText);
            btn.prop('disabled', false).text('Start Sync (AJAX)');
        });
    });
})(jQuery);

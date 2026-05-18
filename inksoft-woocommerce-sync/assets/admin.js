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

    // =========================================================================
    // Danger Zone - Purge synced products
    // =========================================================================

    function purgeLog(msg) {
        var $log = $('#inksoft-purge-log');
        $log.show().append(msg + "\n");
        $log.scrollTop($log[0].scrollHeight);
    }

    $('#inksoft-purge-check').on('click', function(e) {
        e.preventDefault();
        var btn = $(this);
        btn.prop('disabled', true).text('Checking...');

        $.post(InkSoftWoo.ajax_url, {
            action: 'inksoft_woo_purge_check',
            nonce: InkSoftWoo.nonce
        }, function(resp) {
            btn.prop('disabled', false).text('Preview what will be deleted');
            if (!resp.success) {
                alert('Error checking synced data: ' + JSON.stringify(resp.data));
                return;
            }
            var d = resp.data;
            $('#count-products').text(d.products);
            $('#count-images').text(d.images);
            $('#count-categories').text(d.categories);
            $('#count-tags').text(d.tags);
            $('#count-attributes').text(d.attribute_terms);

            $('#inksoft-purge-counts').html(
                '<strong>Found ' + d.products + ' InkSoft-synced products</strong> with ' +
                d.images + ' images, ' + d.categories + ' categories, ' +
                d.tags + ' product tags, and ' + d.attribute_terms + ' attribute terms.'
            ).show();
            $('#inksoft-purge-form').show();
        }).fail(function() {
            btn.prop('disabled', false).text('Preview what will be deleted');
            alert('Request failed. Please try again.');
        });
    });

    $('#inksoft-purge-execute').on('click', function(e) {
        e.preventDefault();

        var items = [];
        if ($('#purge_products').is(':checked'))   items.push($('#count-products').text()   + ' products (+ variations)');
        if ($('#purge_images').is(':checked'))     items.push($('#count-images').text()     + ' product images');
        if ($('#purge_categories').is(':checked')) items.push('up to ' + $('#count-categories').text() + ' categories');
        if ($('#purge_tags').is(':checked'))       items.push('up to ' + $('#count-tags').text()       + ' product tags');
        if ($('#purge_attributes').is(':checked')) items.push('up to ' + $('#count-attributes').text() + ' attribute terms');

        if (items.length === 0) {
            alert('Please select at least one item type to delete.');
            return;
        }

        $('#inksoft-purge-modal-message').html(
            'You are about to permanently delete:<ul style="margin:8px 0 8px 20px;list-style:disc"><li>' +
            items.join('</li><li>') +
            '</li></ul>'
        );
        $('#inksoft-purge-modal').css('display', 'flex');
    });

    $('#inksoft-purge-cancel').on('click', function() {
        $('#inksoft-purge-modal').hide();
    });

    $('#inksoft-purge-confirm').on('click', function() {
        $('#inksoft-purge-modal').hide();

        var delProducts   = $('#purge_products').is(':checked');
        var delImages     = $('#purge_images').is(':checked');
        var delCategories = $('#purge_categories').is(':checked');
        var delTags       = $('#purge_tags').is(':checked');
        var delAttributes = $('#purge_attributes').is(':checked');
        var batchSize     = 25;

        var btn = $('#inksoft-purge-execute');
        btn.prop('disabled', true).text('Deleting...');
        $('#inksoft-purge-log').show().text('Fetching product IDs...\n');

        // ------------------------------------------------------------------
        // Step 1: get all IDs (also caches term data server-side)
        // ------------------------------------------------------------------
        $.post(InkSoftWoo.ajax_url, {
            action: 'inksoft_woo_purge_get_ids',
            nonce:  InkSoftWoo.nonce
        }, function(resp) {
            if (!resp.success) {
                purgeLog('ERROR fetching IDs: ' + JSON.stringify(resp.data));
                btn.prop('disabled', false).text('Delete Selected Items');
                return;
            }

            var allIds        = resp.data.product_ids;
            var total         = resp.data.total;
            var totalDeleted  = 0;
            var totalImages   = 0;

            purgeLog('Found ' + total + ' products. Processing in batches of ' + batchSize + '...\n');

            // Build batches
            var batches = [];
            for (var i = 0; i < allIds.length; i += batchSize) {
                batches.push(allIds.slice(i, i + batchSize));
            }

            // ------------------------------------------------------------------
            // Step 2: delete each batch sequentially
            // ------------------------------------------------------------------
            function deleteBatch(idx) {
                if (!delProducts || idx >= batches.length) {
                    // All batches done (or skipping product deletion) - run cleanup
                    runCleanup(totalDeleted, totalImages);
                    return;
                }

                var batch    = batches[idx];
                var postData = {
                    action:        'inksoft_woo_purge_delete_batch',
                    nonce:         InkSoftWoo.nonce,
                    delete_images: delImages ? 1 : 0
                };
                $.each(batch, function(i, id) { postData['product_ids[' + i + ']'] = id; });

                $.post(InkSoftWoo.ajax_url, postData, function(resp) {
                    if (resp.success) {
                        totalDeleted += resp.data.deleted;
                        totalImages  += resp.data.images_deleted;
                    } else {
                        purgeLog('Batch ' + (idx + 1) + ' error: ' + JSON.stringify(resp.data));
                    }
                    var progress = totalDeleted + '/' + total;
                    btn.text('Deleting... (' + progress + ')');
                    purgeLog('Batch ' + (idx + 1) + '/' + batches.length + ' done - ' + progress + ' products deleted.');
                    setTimeout(function() { deleteBatch(idx + 1); }, 50);
                }).fail(function() {
                    purgeLog('Batch ' + (idx + 1) + ' AJAX failed - retrying in 1s...');
                    setTimeout(function() { deleteBatch(idx); }, 1000); // retry same batch
                });
            }

            deleteBatch(0);

        }).fail(function() {
            purgeLog('Failed to fetch product IDs. Please try again.');
            btn.prop('disabled', false).text('Delete Selected Items');
        });

        // ------------------------------------------------------------------
        // Step 3: clean up terms and verify DB
        // ------------------------------------------------------------------
        function runCleanup(productsDeleted, imagesDeleted) {
            purgeLog('\nRunning cleanup (terms, options)...');
            $.post(InkSoftWoo.ajax_url, {
                action:            'inksoft_woo_purge_cleanup',
                nonce:             InkSoftWoo.nonce,
                delete_categories: delCategories ? 1 : 0,
                delete_tags:       delTags       ? 1 : 0,
                delete_attributes: delAttributes ? 1 : 0
            }, function(resp) {
                btn.prop('disabled', false).text('Delete Selected Items');

                if (!resp.success) {
                    purgeLog('Cleanup error: ' + JSON.stringify(resp.data));
                    return;
                }

                var d = resp.data;
                purgeLog([
                    '',
                    '--- Final Results ---',
                    'Products deleted:        ' + productsDeleted,
                    'Images deleted:          ' + imagesDeleted,
                    'Categories deleted:      ' + d.categories_deleted,
                    'Tags deleted:            ' + d.tags_deleted,
                    'Attribute terms deleted: ' + d.attribute_terms_deleted,
                    '',
                    '--- Database Verification ---',
                    'InkSoft markers remaining in DB: ' + d.verification.remaining_products
                ].join('\n'));

                if (d.verification.remaining_products === 0) {
                    $('#inksoft-purge-counts').hide();
                    $('#inksoft-purge-form').hide();
                }
            }).fail(function() {
                btn.prop('disabled', false).text('Delete Selected Items');
                purgeLog('Cleanup AJAX request failed.');
            });
        }
    });
})(jQuery);

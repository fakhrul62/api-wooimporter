/* global APIROSYNC, jQuery */
(function ($) {
    'use strict';

    /* 
       STATE
     */
    let activeConnId  = null;   // currently selected connection ID
    let activeConn    = null;   // full settings object of active conn
    let allConns      = [];     // summary array from dashboard
    let allProducts   = [];     // preview products for active conn
    let apirosync_analysis  = null;   // last analyze result

    /* 
       HELPERS
     */
    const ajax = (action, data = {}) =>
        $.post(APIROSYNC.ajax_url, { action, nonce: APIROSYNC.nonce, ...data });

    const connAjax = (action, data = {}) =>
        ajax(action, { conn_id: activeConnId, ...data });

    function showNotice(sel, msg, type = 'info') {
        $(sel).attr('class', 'apirosync-notice ' + type).text(msg).show();
    }
    function showNoticeHtml(sel, html, type = 'info') {
        $(sel).attr('class', 'apirosync-notice ' + type).html(html).show();
    }
    function hideNotice(sel) { $(sel).hide(); }

    function spin($btn, on) {
        if (on) $btn.data('orig', $btn.html()).html('<span class="apirosync-spinner"></span> Working').prop('disabled', true);
        else     $btn.html($btn.data('orig') || '').prop('disabled', false);
    }

    function esc(str) {
        if (str == null) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function brandIconHtml(size = 56) {
        const iconUrl = APIROSYNC.branding && APIROSYNC.branding.icon_url ? APIROSYNC.branding.icon_url : '';

        if (!iconUrl) {
            return '<div class="apirosync-empty-icon">API</div>';
        }

        return `<img class="apirosync-brand-icon apirosync-brand-icon-empty" src="${esc(iconUrl)}" alt="" width="${size}" height="${size}" loading="eager" decoding="async">`;
    }

    function safeHttpUrl(value) {
        try {
            const url = new URL(String(value));
            return url.protocol === 'http:' || url.protocol === 'https:' ? url.href : '';
        } catch (e) {
            return '';
        }
    }

    function humanTime(mysqlDate) {
        if (!mysqlDate) return 'Never';
        const d = new Date(mysqlDate.replace(' ','T'));
        const diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 60)   return 'Just now';
        if (diff < 3600) return Math.floor(diff/60) + ' min ago';
        if (diff < 86400) return Math.floor(diff/3600) + ' hr ago';
        return Math.floor(diff/86400) + ' days ago';
    }

    /* 
       SIDEBAR  CONNECTION LIST
     */

    function loadConnections() {
        ajax('apirosync_get_dashboard').done(function(res) {
            if (!res.success) return;
            allConns = res.data.connections || [];
            renderSidebar();
        });
    }

    function renderSidebar() {
        const $list = $('#apirosync-conn-list');
        $('#apirosync-conn-count-pill').text(allConns.length + ' connection' + (allConns.length !== 1 ? 's' : ''));

        if (!allConns.length) {
            $list.html('<div class="apirosync-sidebar-loading">No connections yet.<br>Click  to add one.</div>');
            return;
        }
        let html = '';
        allConns.forEach(function(c) {
            const sync  = `<div class="apirosync-sync-dot ${c.sync_enabled?'active':''}" title="Auto-sync ${c.sync_enabled?'on':'off'}"></div>`;
            const count = `<span class="apirosync-conn-item-count">${Number.parseInt(c.wc_count, 10) || 0} products</span>`;
            const url   = c.api_url ? c.api_url.replace(/^https?:\/\//,'').substring(0,30)+'' : 'Not configured';
            html += `
            <div class="apirosync-conn-item${c.id===activeConnId?' active':''}" data-id="${esc(c.id)}">
              <div class="apirosync-conn-item-info">
                <div class="apirosync-conn-item-label">${esc(c.label)}</div>
                <div class="apirosync-conn-item-url">${esc(url)}</div>
                <div class="apirosync-conn-item-meta">${sync}${count}</div>
              </div>
            </div>`;
        });
        $list.html(html);
    }

    $(document).on('click', '.apirosync-conn-item', function() {
        const id = $(this).data('id');
        selectConnection(id);
    });

    function selectConnection(id) {
        activeConnId = id;
        // Find in allConns
        const meta = allConns.find(c => c.id === id);

        // Highlight sidebar
        $('.apirosync-conn-item').removeClass('active');
        $(`.apirosync-conn-item[data-id="${id}"]`).addClass('active');

        // Show editor
        $('#apirosync-editor-empty').hide();
        $('#apirosync-conn-editor').show();

        // Reset state
        apirosync_analysis = null;
        allProducts  = [];
        activeConn   = null;

        // Load full connection settings from allConns cache
        const conn = allConns.find(c => c.id === id);
        if (!conn) return;
        activeConn = conn;
        populateEditor(conn);

        // Load logs
        loadLogs();

        // Reset tabs to connection tab
        switchTab('connection');
    }

    function populateEditor(conn) {
        // Header
        $('#apirosync-conn-label').val(conn.label || '');

        // Connection tab
        $('#apirosync-api-url').val(conn.api_url || '');
        $('#apirosync-api-method').val(conn.api_method || 'GET');
        $('#apirosync-api-bearer').val(conn.api_bearer || '');
        $('#apirosync-api-basic-user').val(conn.api_basic_user || '');
        $('#apirosync-api-basic-pass').val(conn.api_basic_pass || '');
        $('#apirosync-api-key-header').val(conn.api_key_header || '');
        $('#apirosync-api-key-param').val(conn.api_key_param || '');
        $('#apirosync-api-key-value').val(conn.api_key_value || '');
        $('#apirosync-api-extra-params').val(conn.api_extra_params || '');
        $('#apirosync-api-body').val(conn.api_body || '');
        $('#apirosync-webhook-secret').val(conn.webhook_secret || '');
        $('#apirosync-webhook-url').val(conn.id ? window.location.origin + '/wp-json/apirosync/v1/webhook/' + conn.id : '');

        // Options tab
        $('#apirosync-publish-status').val(conn.publish_status || 'publish');
        $('#apirosync-wc-category').val(conn.wc_category || '');
        $('#apirosync-tag-prefix').val(conn.tag_prefix || '');
        $('#apirosync-import-images').prop('checked', !!conn.import_images);
        $('#apirosync-update-existing').prop('checked', !!conn.update_existing);
        $('#apirosync-conflict-strategy').val(conn.conflict_strategy || 'update');
        $('#apirosync-pagination-style').val(conn.pagination_style || 'auto');
        $('#apirosync-pagination-param').val(conn.pagination_param || 'page');
        $('#apirosync-perpage-param').val(conn.perpage_param || 'per_page');
        $('#apirosync-perpage-size').val(conn.perpage_size || 100);

        // Schedule tab
        $('#apirosync-sync-enabled').prop('checked', !!conn.sync_enabled);
        $('#apirosync-sync-interval').val(conn.sync_interval || 'hourly');
        $('#apirosync-next-run').text(conn.next_run || '');
        $('#apirosync-last-sync').text(humanTime(conn.last_sync));
        $('#apirosync-last-sync-count').text(conn.last_sync_count || 0);

        // Hide notices from previous session
        hideNotice('#apirosync-analysis-result');
        hideNotice('#apirosync-options-notice');
        hideNotice('#apirosync-schedule-notice');
        hideNotice('#apirosync-map-notice');
        hideNotice('#apirosync-import-result');

        // Reset mapping and products pane
        $('#apirosync-mapping-table-wrap').html('<div class="apirosync-map-loading"><span>Run Auto-Detect or configure your API first.</span></div>');
        $('#apirosync-sample-card').hide();
        $('#apirosync-products-grid').html(`<div class="apirosync-products-empty">${brandIconHtml()}<div>Click <strong>Refresh</strong> to fetch products.</div></div>`);
        $('#apirosync-product-count').text('');
    }

    /* 
       ADD / DELETE / DUPLICATE
     */

    function addConnection() {
        const label = prompt('Connection name:', 'New API Connection');
        if (label === null) return;
        ajax('apirosync_create_connection', { label: label || 'New API Connection' }).done(function(res) {
            if (!res.success) return alert('Error: ' + res.data.message);
            loadConnections();
            setTimeout(() => selectConnection(res.data.id), 400);
        });
    }

    $('#apirosync-btn-add-conn, #apirosync-btn-add-conn-center').on('click', addConnection);

    $('#apirosync-btn-delete-conn').on('click', function() {
        if (!activeConnId) return;
        const label = $('#apirosync-conn-label').val() || 'this connection';
        if (!confirm(`Delete "${label}"? All settings will be removed. Products already imported into WooCommerce will NOT be deleted.`)) return;
        ajax('apirosync_delete_connection', { conn_id: activeConnId }).done(function() {
            activeConnId = null;
            activeConn   = null;
            $('#apirosync-conn-editor').hide();
            $('#apirosync-editor-empty').show();
            loadConnections();
        });
    });

    $('#apirosync-btn-duplicate-conn').on('click', function() {
        if (!activeConnId) return;
        connAjax('apirosync_duplicate_connection').done(function(res) {
            if (!res.success) return alert('Error: ' + res.data.message);
            loadConnections();
            setTimeout(() => selectConnection(res.data.id), 400);
        });
    });

    /* 
       TABS
     */

    function switchTab(tab) {
        $('.apirosync-tab').removeClass('active');
        $('.apirosync-panel').removeClass('active');
        $(`.apirosync-tab[data-tab="${tab}"]`).addClass('active');
        $(`#tab-${tab}`).addClass('active');
    }

    $(document).on('click', '.apirosync-tab', function() {
        switchTab($(this).data('tab'));
    });

    /* 
       CONNECTION TAB
     */

    function getConnectionFields() {
        return {
            api_url:          $('#apirosync-api-url').val().trim(),
            api_method:       $('#apirosync-api-method').val(),
            api_bearer:       $('#apirosync-api-bearer').val().trim(),
            api_basic_user:   $('#apirosync-api-basic-user').val().trim(),
            api_basic_pass:   $('#apirosync-api-basic-pass').val().trim(),
            api_key_header:   $('#apirosync-api-key-header').val().trim(),
            api_key_param:    $('#apirosync-api-key-param').val().trim(),
            api_key_value:    $('#apirosync-api-key-value').val().trim(),
            api_extra_params: $('#apirosync-api-extra-params').val().trim(),
            api_body:         $('#apirosync-api-body').val().trim(),
            webhook_secret:   $('#apirosync-webhook-secret').val().trim(),
        };
    }

    $('#apirosync-btn-analyze').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        const fields = getConnectionFields();
        if (!fields.api_url) {
            showNotice('#apirosync-analysis-result', ' Please enter the API Endpoint URL.', 'warning');
            return;
        }
        spin($btn, true);
        connAjax('apirosync_analyze_api', fields)
            .done(function(res) {
                if (!res.success) {
                    showNotice('#apirosync-analysis-result', ' ' + res.data.message, 'error');
                    return;
                }
                const d = res.data;
                apirosync_analysis = d;
                const totalFound = Number.parseInt(d.total_found, 10) || 0;
                const productsKey = d.products_key === '__root__' ? 'root array' : '"' + esc(d.products_key) + '"';
                let html = `Connected! Found <strong>${totalFound}</strong> products in <code>${productsKey}</code>. `;
                html += `Auto-detected <strong>${Object.keys(d.map).length}</strong> field mappings. `;
                html += `<a href="#" class="apirosync-goto-map"> Review Mapping</a>`;
                showNoticeHtml('#apirosync-analysis-result', html, 'success');
            })
            .fail(() => showNotice('#apirosync-analysis-result', ' AJAX error  check your browser console.', 'error'))
            .always(() => spin($btn, false));
    });

    $(document).on('click', '.apirosync-goto-map', function(e) {
        e.preventDefault();
        switchTab('mapping');
    });

    $('#apirosync-btn-save-connection').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('apirosync_save_connection', {
            label:     $('#apirosync-conn-label').val().trim(),
            ...getConnectionFields(),
        }).done(function(res) {
            showNotice('#apirosync-analysis-result', res.success ? ' Connection saved.' : ' ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    $('#apirosync-btn-save-options').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('apirosync_save_connection', {
            publish_status:  $('#apirosync-publish-status').val(),
            wc_category:     $('#apirosync-wc-category').val().trim(),
            tag_prefix:      $('#apirosync-tag-prefix').val().trim(),
            import_images:   $('#apirosync-import-images').is(':checked') ? '1' : '0',
            update_existing: $('#apirosync-update-existing').is(':checked') ? '1' : '0',
            conflict_strategy: $('#apirosync-conflict-strategy').val(),
            pagination_style: $('#apirosync-pagination-style').val(),
            pagination_param: $('#apirosync-pagination-param').val().trim(),
            perpage_param:    $('#apirosync-perpage-param').val().trim(),
            perpage_size:     $('#apirosync-perpage-size').val().trim(),
        }).done(function(res) {
            showNotice('#apirosync-options-notice', res.success ? ' Options saved.' : ' ' + res.data.message, res.success ? 'success' : 'error');
        }).always(() => spin($btn, false));
    });

    $('#apirosync-btn-delete-imported').on('click', function() {
        if (!activeConnId) return;
        const label = $('#apirosync-conn-label').val() || 'this connection';
        if (!confirm(`Permanently delete ALL products imported from "${label}"?\n\nThis cannot be undone.`)) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('apirosync_delete_imported').done(function(res) {
            showNotice('#apirosync-options-notice', res.success ? ' ' + res.data.message : ' ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    // Live label sync
    $('#apirosync-conn-label').on('input', function() {
        const $item = $(`.apirosync-conn-item[data-id="${activeConnId}"] .apirosync-conn-item-label`);
        $item.text($(this).val());
    });

    /* 
       FIELD MAPPING TAB
     */

    function autoMap(forceRefetch) {
        const $wrap = $('#apirosync-mapping-table-wrap');
        $wrap.html('<div class="apirosync-map-loading"> Analyzing API</div>');

        if (apirosync_analysis && !forceRefetch) {
            renderMappingTable(apirosync_analysis);
            return;
        }

        const fields = getConnectionFields();
        if (!fields.api_url && activeConn) fields.api_url = activeConn.api_url || '';
        if (!fields.api_url) {
            $wrap.html('<div class="apirosync-map-loading"> Configure your API connection first.</div>');
            return;
        }

        connAjax('apirosync_analyze_api', fields)
            .done(function(res) {
                if (!res.success) {
                    $wrap.html('<div class="apirosync-map-loading">' + esc(res.data.message) + '</div>');
                    return;
                }
                apirosync_analysis = res.data;
                renderMappingTable(res.data);
            })
            .fail(() => $wrap.html('<div class="apirosync-map-loading"> AJAX error.</div>'));
    }

    function renderMappingTable(data) {
        const { all_keys, map, sample } = data;
        const savedMap     = (activeConn && activeConn.field_map) ? activeConn.field_map : {};
        const effectiveMap = Object.assign({}, map, savedMap);
        const wcFields     = APIROSYNC.wc_fields;

        let html = '<table class="apirosync-map-table">';
        html += '<thead><tr><th>WooCommerce Field</th><th>API Field</th><th>Status</th></tr></thead><tbody>';

        for (const [wcKey, meta] of Object.entries(wcFields)) {
            const selected   = effectiveMap[wcKey] || '';
            const required   = meta.required ? '<span class="apirosync-required-badge">REQUIRED</span>' : '';
            const confidence = selected ? (map[wcKey] === selected ? ' Auto' : ' Manual') : '';
            const confClass  = selected && map[wcKey] === selected ? 'apirosync-map-confidence' : '';

            html += `<tr>
              <td class="apirosync-wc-field">${meta.label}${required}</td>
              <td>
                <select class="apirosync-map-select" data-wc="${wcKey}">
                  <option value=""> skip </option>`;
            for (const k of all_keys) {
                html += `<option value="${esc(k)}"${k === selected ? ' selected' : ''}>${esc(k)}</option>`;
            }
            const hasTransforms = effectiveMap[wcKey] && activeConn && activeConn.field_transforms && activeConn.field_transforms[wcKey] && activeConn.field_transforms[wcKey].length > 0;
            const btnColor = hasTransforms ? '#6366f1' : 'inherit';
            html += `</select>
            <button class="apirosync-btn-icon-primary apirosync-btn-transform" data-wc="${wcKey}" title="Add transforms" style="color:${btnColor};border:1px solid #ccc;background:#f9f9f9;border-radius:4px;padding:2px 6px;margin-left:4px;cursor:pointer;"></button>
            </td>
              <td class="${confClass}" style="font-size:11px;">${confidence}</td>
            </tr>`;
        }
        html += '</tbody></table>';

        $('#apirosync-mapping-table-wrap').html(html);
        $('#apirosync-sample-json').text(JSON.stringify(sample, null, 2));
        $('#apirosync-sample-card').show();
        window.apirosync_productsKey = data.products_key;
    }

    $('#apirosync-btn-automap').on('click', function() {
        apirosync_analysis = null;
        autoMap(true);
    });

    $('#apirosync-btn-save-map').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        const map  = {};
        $('.apirosync-map-select').each(function() {
            const wc  = $(this).data('wc');
            const val = $(this).val();
            if (val) map[wc] = val;
        });
        if (!map.external_id && !map.title) {
            showNotice('#apirosync-map-notice', ' Map at least "External ID" or "Product Title".', 'warning');
            return;
        }
        spin($btn, true);
        connAjax('apirosync_save_field_map', {
            field_map:    JSON.stringify(map),
            products_key: window.apirosync_productsKey || 'auto',
        }).done(function(res) {
            showNotice('#apirosync-map-notice', res.success ? ' Field mapping saved!' : ' ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success && activeConn) activeConn.field_map = map;
        }).always(() => spin($btn, false));
    });

    $(document).on('click', '.apirosync-btn-transform', function(e) {
        e.preventDefault();
        const wcKey = $(this).data('wc');
        const transforms = activeConn && activeConn.field_transforms ? activeConn.field_transforms[wcKey] || [] : [];
        const json = prompt('Enter transform rules as JSON array (e.g. [{"type":"multiply","arg":"1.2"}]):', JSON.stringify(transforms));
        if (json !== null) {
            try {
                const parsed = JSON.parse(json || '[]');
                if (!activeConn.field_transforms) activeConn.field_transforms = {};
                activeConn.field_transforms[wcKey] = parsed;
                connAjax('apirosync_save_transforms', { field_transforms: JSON.stringify(activeConn.field_transforms) })
                    .done(res => {
                        showNotice('#apirosync-map-notice', res.success ? ' Transforms saved!' : ' Error', res.success ? 'success' : 'error');
                        if (res.success && parsed.length > 0) $(this).css('color', '#6366f1');
                        else $(this).css('color', 'inherit');
                    });
            } catch(e) {
                alert('Invalid JSON format. Must be an array of objects.');
            }
        }
    });

    /* 
       PRODUCTS TAB
     */

    function loadPreview() {
        const $grid = $('#apirosync-products-grid');
        $grid.html(`<div class="apirosync-products-empty">${brandIconHtml()}<div>Fetching products</div></div>`);
        hideNotice('#apirosync-import-result');

        connAjax('apirosync_fetch_preview').done(function(res) {
            if (!res.success) {
                $grid.html(`<div class="apirosync-products-empty">${brandIconHtml()}<div>${esc(res.data.message)}</div></div>`);
                return;
            }
            allProducts = res.data.products;
            $('#apirosync-product-count').text(allProducts.length + ' products');
            renderProducts(allProducts);
        }).fail(() => {
            $grid.html(`<div class="apirosync-products-empty">${brandIconHtml()}<div>AJAX error loading preview.</div></div>`);
        });
    }

    function renderProducts(products) {
        if (!products.length) {
            $('#apirosync-products-grid').html(`<div class="apirosync-products-empty">${brandIconHtml()}<div>No products match.</div></div>`);
            return;
        }
        let html = '<div class="apirosync-products-grid-inner">';
        products.forEach(function(p, i) {
            const imported = p.imported ? '<div class="apirosync-imported-tag"> Imported</div>' : '';
            const imageUrl = safeHttpUrl(p.image);
            const imgHtml  = imageUrl
                ? `<img class="apirosync-product-image" src="${esc(imageUrl)}" loading="lazy" alt="">`
                : '<div class="apirosync-product-no-img"></div>';
            const numericPrice = Number.parseFloat(p.price);
            const price = Number.isFinite(numericPrice) ? `<span class="apirosync-product-price">$${numericPrice.toFixed(2)}</span>` : '';
            const cat   = p.cat ? `<span class="apirosync-product-cat">${esc(p.cat)}</span>` : '';
            const stock = p.stock !== '' && p.stock !== null ? `<span class="apirosync-product-stock">Stock: ${esc(p.stock)}</span>` : '';
            html += `
            <div class="apirosync-product-card${p.imported?' already-imported':''}" data-ext-id="${esc(p.ext_id)}" data-idx="${i}">
              ${imported}
              <input type="checkbox" class="apirosync-product-checkbox" value="${esc(p.ext_id)}" aria-label="${esc(p.title)}">
              <div class="apirosync-product-img-wrap">${imgHtml}</div>
              <div class="apirosync-product-body">
                <div class="apirosync-product-title">${esc(p.title||'Untitled')}</div>
                <div class="apirosync-product-meta">${price}${cat}${stock}</div>
              </div>
            </div>`;
        });
        html += '</div>';
        $('#apirosync-products-grid').html(html);
        $('.apirosync-product-image').on('error', function() {
            $(this).replaceWith('<div class="apirosync-product-no-img"></div>');
        });
        updateSelectedCount();
    }

    $(document).on('click', '.apirosync-product-card', function(e) {
        if ($(e.target).is('input')) return;
        const $cb = $(this).find('.apirosync-product-checkbox');
        $cb.prop('checked', !$cb.prop('checked'));
        $(this).toggleClass('selected', $cb.prop('checked'));
        updateSelectedCount();
    });
    $(document).on('change', '.apirosync-product-checkbox', function() {
        $(this).closest('.apirosync-product-card').toggleClass('selected', $(this).prop('checked'));
        updateSelectedCount();
    });

    function updateSelectedCount() {
        const n = $('.apirosync-product-checkbox:checked').length;
        $('#apirosync-selected-count').text(n);
        $('#apirosync-btn-import-selected').prop('disabled', n === 0);
    }

    $('#apirosync-product-search').on('input', function() {
        const q = $(this).val().toLowerCase();
        if (!q) { renderProducts(allProducts); return; }
        renderProducts(allProducts.filter(p => String(p.title || '').toLowerCase().includes(q) || String(p.cat || '').toLowerCase().includes(q)));
    });
    $('#apirosync-btn-select-all').on('click', function() {
        $('.apirosync-product-checkbox').prop('checked', true);
        $('.apirosync-product-card').addClass('selected');
        updateSelectedCount();
    });
    $('#apirosync-btn-select-none').on('click', function() {
        $('.apirosync-product-checkbox').prop('checked', false);
        $('.apirosync-product-card').removeClass('selected');
        updateSelectedCount();
    });
    $('#apirosync-btn-refresh-preview').on('click', loadPreview);

    $('#apirosync-btn-import-selected').on('click', function() {
        const ids = [];
        $('.apirosync-product-checkbox:checked').each(function() { ids.push($(this).val()); });
        if (!ids.length) return;
        runImport('apirosync_run_import_selected', { ids: JSON.stringify(ids) });
    });

    $('#apirosync-btn-import-all').on('click', function() {
        if (!allProducts.length) return;
        if (!confirm('Import all ' + allProducts.length + ' products?')) return;
        runImport('apirosync_run_import');
    });

    let pollInterval = null;
    function pollProgress() {
        if (!activeConnId) return;
        connAjax('apirosync_get_progress').done(function(res) {
            if (!res.success) return;
            const d = res.data;
            const $progress = $('#apirosync-import-progress');
            const $fill = $('.apirosync-progress-fill');
            const $label = $('.apirosync-progress-label');
            $progress.show();
            $fill.css('width', d.percent + '%');
            $label.text(`Importing... ${d.percent}% (${d.processed} / ${d.total})`);
            
            if (d.status === 'done' || d.status === 'error' || d.percent >= 100) {
                clearInterval(pollInterval);
                $label.text(d.status === 'done' ? 'Import complete!' : 'Import stopped.');
                setTimeout(() => { $progress.hide(); loadPreview(); loadConnections(); }, 3000);
            }
        });
    }

    function runImport(action, extra = {}) {
        const $progress = $('#apirosync-import-progress');
        const $fill     = $('.apirosync-progress-fill');
        const $label    = $('.apirosync-progress-label');
        $progress.show();
        $fill.css('width','10%');
        $label.text('Starting background import');
        hideNotice('#apirosync-import-result');

        connAjax(action, extra)
            .done(function(res) {
                if (!res.success) {
                    $label.text('Failed to start.');
                    showNotice('#apirosync-import-result', ' ' + res.data.message, 'error');
                    return;
                }
                showNotice('#apirosync-import-result', res.data.message, 'success');
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(pollProgress, 2000);
            })
            .fail(() => showNotice('#apirosync-import-result', ' AJAX error.', 'error'));
    }

    /* 
       SCHEDULE TAB
     */

    $('#apirosync-btn-save-schedule').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('apirosync_save_connection', {
            sync_enabled:  $('#apirosync-sync-enabled').is(':checked') ? '1' : '0',
            sync_interval: $('#apirosync-sync-interval').val(),
        }).done(function(res) {
            showNotice('#apirosync-schedule-notice', res.success ? ' Schedule saved.' : ' ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    $('#apirosync-btn-run-now').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('apirosync_run_import')
            .done(function(res) {
                showNotice('#apirosync-schedule-notice',
                    res.success ? ' ' + res.data.message : ' ' + res.data.message,
                    res.success ? 'success' : 'error');
                if (res.success) { loadConnections(); }
            })
            .fail(() => showNotice('#apirosync-schedule-notice', ' AJAX error.', 'error'))
            .always(() => spin($btn, false));
    });

    /* 
       LOGS TAB
     */

    function loadLogs() {
        if (!activeConnId) return;
        connAjax('apirosync_get_logs').done(function(res) {
            if (!res.success) return;
            renderLogs(res.data.logs || []);
        });
    }

    function renderLogs(logs) {
        if (!logs.length) {
            $('#apirosync-log-list').html('<div class="apirosync-log-empty">No log entries yet.</div>');
            return;
        }
        let html = '';
        logs.forEach(function(log) {
            html += `<div class="apirosync-log-row ${esc(log.type)}">
              <span class="log-time">${esc(log.time)}</span>
              <span class="log-badge ${esc(log.type)}">${esc((log.type||'').toUpperCase())}</span>
              <span class="log-msg">${esc(log.message)}</span>
            </div>`;
        });
        $('#apirosync-log-list').html(html);
    }

    $('#apirosync-btn-clear-logs').on('click', function() {
        if (!activeConnId) return;
        if (!confirm('Clear logs for this connection?')) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('apirosync_clear_logs').done(function(res) {
            if (res.success) renderLogs([]);
        }).always(() => spin($btn, false));
    });

    // Reload logs when switching to logs tab
    $(document).on('click', '.apirosync-tab[data-tab="logs"]', function() {
        loadLogs();
    });

    /* 
       HISTORY TAB
     */

    function loadHistory() {
        if (!activeConnId) return;
        $('#apirosync-history-list').html('<div class="apirosync-log-empty">Loading history...</div>');
        connAjax('apirosync_get_history').done(function(res) {
            if (!res.success) return;
            renderHistory(res.data.history || []);
        });
    }

    function renderHistory(history) {
        if (!history.length) {
            $('#apirosync-history-list').html('<div class="apirosync-log-empty">No import history yet.</div>');
            return;
        }
        let html = '';
        history.forEach(function(run) {
            const imported = Number.parseInt(run.imported, 10) || 0;
            const updated = Number.parseInt(run.updated, 10) || 0;
            const failed = Number.parseInt(run.failed, 10) || 0;
            html += `<div class="apirosync-log-row" style="display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid #eee;">
              <div>
                  <span class="log-time" style="font-weight:600;color:#333;">${esc(humanTime(run.date))}</span>
                  <span style="margin-left:15px;color:#666;">Imported: <strong>${imported}</strong>, Updated: <strong>${updated}</strong>, Failed: <strong>${failed}</strong></span>
              </div>
              <button class="apirosync-btn danger-ghost apirosync-btn-rollback" data-run="${esc(run.id)}" style="padding:4px 8px;font-size:12px;">Rollback</button>
            </div>`;
        });
        $('#apirosync-history-list').html(html);
    }

    $('#apirosync-btn-refresh-history').on('click', loadHistory);

    $(document).on('click', '.apirosync-btn-rollback', function() {
        const runId = $(this).data('run');
        if (!confirm('Rollback this import? This will PERMANENTLY delete these products from WooCommerce!')) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('apirosync_rollback_import', { run_id: runId }).done(function(res) {
            if (res.success) {
                alert(res.data.message);
                loadHistory();
                loadConnections();
            } else {
                alert('Error: ' + res.data.message);
            }
        }).always(() => spin($btn, false));
    });

    // Reload history when switching to history tab
    $(document).on('click', '.apirosync-tab[data-tab="history"]', function() {
        loadHistory();
    });

    /* 
       INIT
     */
    loadConnections();

})(jQuery);

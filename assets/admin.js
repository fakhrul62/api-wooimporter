/* global FAPI, jQuery */
(function ($) {
    'use strict';

    /* ══════════════════════════════════════════════════════════
       STATE
    ══════════════════════════════════════════════════════════ */
    let activeConnId  = null;   // currently selected connection ID
    let activeConn    = null;   // full settings object of active conn
    let allConns      = [];     // summary array from dashboard
    let allProducts   = [];     // preview products for active conn
    let FAPI_analysis  = null;   // last analyze result

    /* ══════════════════════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════════════════════ */
    const ajax = (action, data = {}) =>
        $.post(FAPI.ajax_url, { action, nonce: FAPI.nonce, ...data });

    const connAjax = (action, data = {}) =>
        ajax(action, { conn_id: activeConnId, ...data });

    function showNotice(sel, msg, type = 'info') {
        $(sel).attr('class', 'fapi-notice ' + type).html(msg).show();
    }
    function hideNotice(sel) { $(sel).hide(); }

    function spin($btn, on) {
        if (on) $btn.data('orig', $btn.html()).html('<span class="fapi-spinner"></span> Working…').prop('disabled', true);
        else     $btn.html($btn.data('orig') || '').prop('disabled', false);
    }

    function esc(str) {
        if (str == null) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
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

    /* ══════════════════════════════════════════════════════════
       SIDEBAR — CONNECTION LIST
    ══════════════════════════════════════════════════════════ */

    function loadConnections() {
        ajax('fapi_get_dashboard').done(function(res) {
            if (!res.success) return;
            allConns = res.data.connections || [];
            renderSidebar();
        });
    }

    function renderSidebar() {
        const $list = $('#fapi-conn-list');
        $('#fapi-conn-count-pill').text(allConns.length + ' connection' + (allConns.length !== 1 ? 's' : ''));

        if (!allConns.length) {
            $list.html('<div class="fapi-sidebar-loading">No connections yet.<br>Click ＋ to add one.</div>');
            return;
        }
        let html = '';
        allConns.forEach(function(c) {
            const sync  = `<div class="fapi-sync-dot ${c.sync_enabled?'active':''}" title="Auto-sync ${c.sync_enabled?'on':'off'}"></div>`;
            const count = `<span class="fapi-conn-item-count">${c.wc_count||0} products</span>`;
            const url   = c.api_url ? c.api_url.replace(/^https?:\/\//,'').substring(0,30)+'…' : 'Not configured';
            html += `
            <div class="fapi-conn-item${c.id===activeConnId?' active':''}" data-id="${esc(c.id)}">
              <div class="fapi-conn-item-info">
                <div class="fapi-conn-item-label">${esc(c.label)}</div>
                <div class="fapi-conn-item-url">${esc(url)}</div>
                <div class="fapi-conn-item-meta">${sync}${count}</div>
              </div>
            </div>`;
        });
        $list.html(html);
    }

    $(document).on('click', '.fapi-conn-item', function() {
        const id = $(this).data('id');
        selectConnection(id);
    });

    function selectConnection(id) {
        activeConnId = id;
        // Find in allConns
        const meta = allConns.find(c => c.id === id);

        // Highlight sidebar
        $('.fapi-conn-item').removeClass('active');
        $(`.fapi-conn-item[data-id="${id}"]`).addClass('active');

        // Show editor
        $('#fapi-editor-empty').hide();
        $('#fapi-conn-editor').show();

        // Reset state
        FAPI_analysis = null;
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
        $('#fapi-conn-label').val(conn.label || '');

        // Connection tab
        $('#fapi-api-url').val(conn.api_url || '');
        $('#fapi-api-method').val(conn.api_method || 'GET');
        $('#fapi-api-bearer').val(conn.api_bearer || '');
        $('#fapi-api-basic-user').val(conn.api_basic_user || '');
        $('#fapi-api-basic-pass').val(conn.api_basic_pass || '');
        $('#fapi-api-key-header').val(conn.api_key_header || '');
        $('#fapi-api-key-param').val(conn.api_key_param || '');
        $('#fapi-api-key-value').val(conn.api_key_value || '');
        $('#fapi-api-extra-params').val(conn.api_extra_params || '');
        $('#fapi-api-body').val(conn.api_body || '');
        $('#fapi-webhook-secret').val(conn.webhook_secret || '');
        $('#fapi-webhook-url').val(conn.id ? window.location.origin + '/wp-json/fapi/v1/webhook/' + conn.id : '');

        // Options tab
        $('#fapi-publish-status').val(conn.publish_status || 'publish');
        $('#fapi-wc-category').val(conn.wc_category || '');
        $('#fapi-tag-prefix').val(conn.tag_prefix || '');
        $('#fapi-import-images').prop('checked', !!conn.import_images);
        $('#fapi-update-existing').prop('checked', !!conn.update_existing);
        $('#fapi-conflict-strategy').val(conn.conflict_strategy || 'update');
        $('#fapi-pagination-style').val(conn.pagination_style || 'auto');
        $('#fapi-pagination-param').val(conn.pagination_param || 'page');
        $('#fapi-perpage-param').val(conn.perpage_param || 'per_page');
        $('#fapi-perpage-size').val(conn.perpage_size || 100);

        // Schedule tab
        $('#fapi-sync-enabled').prop('checked', !!conn.sync_enabled);
        $('#fapi-sync-interval').val(conn.sync_interval || 'hourly');
        $('#fapi-next-run').text(conn.next_run || '—');
        $('#fapi-last-sync').text(humanTime(conn.last_sync));
        $('#fapi-last-sync-count').text(conn.last_sync_count || 0);

        // Hide notices from previous session
        hideNotice('#fapi-analysis-result');
        hideNotice('#fapi-options-notice');
        hideNotice('#fapi-schedule-notice');
        hideNotice('#fapi-map-notice');
        hideNotice('#fapi-import-result');

        // Reset mapping and products pane
        $('#fapi-mapping-table-wrap').html('<div class="fapi-map-loading"><span>Run Auto-Detect or configure your API first.</span></div>');
        $('#fapi-sample-card').hide();
        $('#fapi-products-grid').html('<div class="fapi-products-empty"><div class="fapi-empty-icon">📦</div><div>Click <strong>Refresh</strong> to fetch products.</div></div>');
        $('#fapi-product-count').text('—');
    }

    /* ══════════════════════════════════════════════════════════
       ADD / DELETE / DUPLICATE
    ══════════════════════════════════════════════════════════ */

    function addConnection() {
        const label = prompt('Connection name:', 'New API Connection');
        if (label === null) return;
        ajax('fapi_create_connection', { label: label || 'New API Connection' }).done(function(res) {
            if (!res.success) return alert('Error: ' + res.data.message);
            loadConnections();
            setTimeout(() => selectConnection(res.data.id), 400);
        });
    }

    $('#fapi-btn-add-conn, #fapi-btn-add-conn-center').on('click', addConnection);

    $('#fapi-btn-delete-conn').on('click', function() {
        if (!activeConnId) return;
        const label = $('#fapi-conn-label').val() || 'this connection';
        if (!confirm(`Delete "${label}"? All settings will be removed. Products already imported into WooCommerce will NOT be deleted.`)) return;
        ajax('fapi_delete_connection', { conn_id: activeConnId }).done(function() {
            activeConnId = null;
            activeConn   = null;
            $('#fapi-conn-editor').hide();
            $('#fapi-editor-empty').show();
            loadConnections();
        });
    });

    $('#fapi-btn-duplicate-conn').on('click', function() {
        if (!activeConnId) return;
        connAjax('fapi_duplicate_connection').done(function(res) {
            if (!res.success) return alert('Error: ' + res.data.message);
            loadConnections();
            setTimeout(() => selectConnection(res.data.id), 400);
        });
    });

    /* ══════════════════════════════════════════════════════════
       TABS
    ══════════════════════════════════════════════════════════ */

    function switchTab(tab) {
        $('.fapi-tab').removeClass('active');
        $('.fapi-panel').removeClass('active');
        $(`.fapi-tab[data-tab="${tab}"]`).addClass('active');
        $(`#tab-${tab}`).addClass('active');
    }

    $(document).on('click', '.fapi-tab', function() {
        switchTab($(this).data('tab'));
    });

    /* ══════════════════════════════════════════════════════════
       CONNECTION TAB
    ══════════════════════════════════════════════════════════ */

    function getConnectionFields() {
        return {
            api_url:          $('#fapi-api-url').val().trim(),
            api_method:       $('#fapi-api-method').val(),
            api_bearer:       $('#fapi-api-bearer').val().trim(),
            api_basic_user:   $('#fapi-api-basic-user').val().trim(),
            api_basic_pass:   $('#fapi-api-basic-pass').val().trim(),
            api_key_header:   $('#fapi-api-key-header').val().trim(),
            api_key_param:    $('#fapi-api-key-param').val().trim(),
            api_key_value:    $('#fapi-api-key-value').val().trim(),
            api_extra_params: $('#fapi-api-extra-params').val().trim(),
            api_body:         $('#fapi-api-body').val().trim(),
            webhook_secret:   $('#fapi-webhook-secret').val().trim(),
        };
    }

    $('#fapi-btn-analyze').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        const fields = getConnectionFields();
        if (!fields.api_url) {
            showNotice('#fapi-analysis-result', '⚠ Please enter the API Endpoint URL.', 'warning');
            return;
        }
        spin($btn, true);
        connAjax('fapi_analyze_api', fields)
            .done(function(res) {
                if (!res.success) {
                    showNotice('#fapi-analysis-result', '❌ ' + res.data.message, 'error');
                    return;
                }
                const d = res.data;
                FAPI_analysis = d;
                let html = `✅ Connected! Found <strong>${d.total_found}</strong> products in <code>${d.products_key === '__root__' ? 'root array' : '"' + d.products_key + '"'}</code>. `;
                html += `Auto-detected <strong>${Object.keys(d.map).length}</strong> field mappings. `;
                html += `<a href="#" class="fapi-goto-map">→ Review Mapping</a>`;
                showNotice('#fapi-analysis-result', html, 'success');
            })
            .fail(() => showNotice('#fapi-analysis-result', '❌ AJAX error — check your browser console.', 'error'))
            .always(() => spin($btn, false));
    });

    $(document).on('click', '.fapi-goto-map', function(e) {
        e.preventDefault();
        switchTab('mapping');
    });

    $('#fapi-btn-save-connection').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('fapi_save_connection', {
            label:     $('#fapi-conn-label').val().trim(),
            ...getConnectionFields(),
        }).done(function(res) {
            showNotice('#fapi-analysis-result', res.success ? '✅ Connection saved.' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    $('#fapi-btn-save-options').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('fapi_save_connection', {
            publish_status:  $('#fapi-publish-status').val(),
            wc_category:     $('#fapi-wc-category').val().trim(),
            tag_prefix:      $('#fapi-tag-prefix').val().trim(),
            import_images:   $('#fapi-import-images').is(':checked') ? '1' : '0',
            update_existing: $('#fapi-update-existing').is(':checked') ? '1' : '0',
            conflict_strategy: $('#fapi-conflict-strategy').val(),
            pagination_style: $('#fapi-pagination-style').val(),
            pagination_param: $('#fapi-pagination-param').val().trim(),
            perpage_param:    $('#fapi-perpage-param').val().trim(),
            perpage_size:     $('#fapi-perpage-size').val().trim(),
        }).done(function(res) {
            showNotice('#fapi-options-notice', res.success ? '✅ Options saved.' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
        }).always(() => spin($btn, false));
    });

    $('#fapi-btn-delete-imported').on('click', function() {
        if (!activeConnId) return;
        const label = $('#fapi-conn-label').val() || 'this connection';
        if (!confirm(`Permanently delete ALL products imported from "${label}"?\n\nThis cannot be undone.`)) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('fapi_delete_imported').done(function(res) {
            showNotice('#fapi-options-notice', res.success ? '✅ ' + res.data.message : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    // Live label sync
    $('#fapi-conn-label').on('input', function() {
        const $item = $(`.fapi-conn-item[data-id="${activeConnId}"] .fapi-conn-item-label`);
        $item.text($(this).val());
    });

    /* ══════════════════════════════════════════════════════════
       FIELD MAPPING TAB
    ══════════════════════════════════════════════════════════ */

    function autoMap(forceRefetch) {
        const $wrap = $('#fapi-mapping-table-wrap');
        $wrap.html('<div class="fapi-map-loading">⏳ Analyzing API…</div>');

        if (FAPI_analysis && !forceRefetch) {
            renderMappingTable(FAPI_analysis);
            return;
        }

        const fields = getConnectionFields();
        if (!fields.api_url && activeConn) fields.api_url = activeConn.api_url || '';
        if (!fields.api_url) {
            $wrap.html('<div class="fapi-map-loading">⚠ Configure your API connection first.</div>');
            return;
        }

        connAjax('fapi_analyze_api', fields)
            .done(function(res) {
                if (!res.success) {
                    $wrap.html('<div class="fapi-map-loading">❌ ' + res.data.message + '</div>');
                    return;
                }
                FAPI_analysis = res.data;
                renderMappingTable(res.data);
            })
            .fail(() => $wrap.html('<div class="fapi-map-loading">❌ AJAX error.</div>'));
    }

    function renderMappingTable(data) {
        const { all_keys, map, sample } = data;
        const savedMap     = (activeConn && activeConn.field_map) ? activeConn.field_map : {};
        const effectiveMap = Object.assign({}, map, savedMap);
        const wcFields     = FAPI.wc_fields;

        let html = '<table class="fapi-map-table">';
        html += '<thead><tr><th>WooCommerce Field</th><th>API Field</th><th>Status</th></tr></thead><tbody>';

        for (const [wcKey, meta] of Object.entries(wcFields)) {
            const selected   = effectiveMap[wcKey] || '';
            const required   = meta.required ? '<span class="fapi-required-badge">REQUIRED</span>' : '';
            const confidence = selected ? (map[wcKey] === selected ? '✓ Auto' : '✏ Manual') : '—';
            const confClass  = selected && map[wcKey] === selected ? 'fapi-map-confidence' : '';

            html += `<tr>
              <td class="fapi-wc-field">${meta.label}${required}</td>
              <td>
                <select class="fapi-map-select" data-wc="${wcKey}">
                  <option value="">— skip —</option>`;
            for (const k of all_keys) {
                html += `<option value="${esc(k)}"${k === selected ? ' selected' : ''}>${esc(k)}</option>`;
            }
            const hasTransforms = effectiveMap[wcKey] && activeConn && activeConn.field_transforms && activeConn.field_transforms[wcKey] && activeConn.field_transforms[wcKey].length > 0;
            const btnColor = hasTransforms ? '#6366f1' : 'inherit';
            html += `</select>
            <button class="fapi-btn-icon-primary fapi-btn-transform" data-wc="${wcKey}" title="Add transforms" style="color:${btnColor};border:1px solid #ccc;background:#f9f9f9;border-radius:4px;padding:2px 6px;margin-left:4px;cursor:pointer;">⚙️</button>
            </td>
              <td class="${confClass}" style="font-size:11px;">${confidence}</td>
            </tr>`;
        }
        html += '</tbody></table>';

        $('#fapi-mapping-table-wrap').html(html);
        $('#fapi-sample-json').text(JSON.stringify(sample, null, 2));
        $('#fapi-sample-card').show();
        window.FAPI_productsKey = data.products_key;
    }

    $('#fapi-btn-automap').on('click', function() {
        FAPI_analysis = null;
        autoMap(true);
    });

    $('#fapi-btn-save-map').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        const map  = {};
        $('.fapi-map-select').each(function() {
            const wc  = $(this).data('wc');
            const val = $(this).val();
            if (val) map[wc] = val;
        });
        if (!map.external_id && !map.title) {
            showNotice('#fapi-map-notice', '⚠ Map at least "External ID" or "Product Title".', 'warning');
            return;
        }
        spin($btn, true);
        connAjax('fapi_save_field_map', {
            field_map:    JSON.stringify(map),
            products_key: window.FAPI_productsKey || 'auto',
        }).done(function(res) {
            showNotice('#fapi-map-notice', res.success ? '✅ Field mapping saved!' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success && activeConn) activeConn.field_map = map;
        }).always(() => spin($btn, false));
    });

    $(document).on('click', '.fapi-btn-transform', function(e) {
        e.preventDefault();
        const wcKey = $(this).data('wc');
        const transforms = activeConn && activeConn.field_transforms ? activeConn.field_transforms[wcKey] || [] : [];
        const json = prompt('Enter transform rules as JSON array (e.g. [{"type":"multiply","arg":"1.2"}]):', JSON.stringify(transforms));
        if (json !== null) {
            try {
                const parsed = JSON.parse(json || '[]');
                if (!activeConn.field_transforms) activeConn.field_transforms = {};
                activeConn.field_transforms[wcKey] = parsed;
                connAjax('fapi_save_transforms', { field_transforms: JSON.stringify(activeConn.field_transforms) })
                    .done(res => {
                        showNotice('#fapi-map-notice', res.success ? '✅ Transforms saved!' : '❌ Error', res.success ? 'success' : 'error');
                        if (res.success && parsed.length > 0) $(this).css('color', '#6366f1');
                        else $(this).css('color', 'inherit');
                    });
            } catch(e) {
                alert('Invalid JSON format. Must be an array of objects.');
            }
        }
    });

    /* ══════════════════════════════════════════════════════════
       PRODUCTS TAB
    ══════════════════════════════════════════════════════════ */

    function loadPreview() {
        const $grid = $('#fapi-products-grid');
        $grid.html('<div class="fapi-products-empty"><div class="fapi-empty-icon">⏳</div><div>Fetching products…</div></div>');
        hideNotice('#fapi-import-result');

        connAjax('fapi_fetch_preview').done(function(res) {
            if (!res.success) {
                $grid.html(`<div class="fapi-products-empty"><div class="fapi-empty-icon">❌</div><div>${res.data.message}</div></div>`);
                return;
            }
            allProducts = res.data.products;
            $('#fapi-product-count').text(allProducts.length + ' products');
            renderProducts(allProducts);
        }).fail(() => {
            $grid.html('<div class="fapi-products-empty"><div class="fapi-empty-icon">❌</div><div>AJAX error loading preview.</div></div>');
        });
    }

    function renderProducts(products) {
        if (!products.length) {
            $('#fapi-products-grid').html('<div class="fapi-products-empty"><div class="fapi-empty-icon">🔍</div><div>No products match.</div></div>');
            return;
        }
        let html = '<div class="fapi-products-grid-inner">';
        products.forEach(function(p, i) {
            const imported = p.imported ? '<div class="fapi-imported-tag">✓ Imported</div>' : '';
            const imgHtml  = p.image
                ? `<img src="${esc(p.image)}" loading="lazy" alt="" onerror="this.parentNode.innerHTML='<div class=\\'fapi-product-no-img\\'>📦</div>'">`
                : '<div class="fapi-product-no-img">📦</div>';
            const price = p.price !== '' && p.price !== null ? `<span class="fapi-product-price">$${parseFloat(p.price).toFixed(2)}</span>` : '';
            const cat   = p.cat ? `<span class="fapi-product-cat">${esc(p.cat)}</span>` : '';
            const stock = p.stock !== '' && p.stock !== null ? `<span class="fapi-product-stock">Stock: ${p.stock}</span>` : '';
            html += `
            <div class="fapi-product-card${p.imported?' already-imported':''}" data-ext-id="${esc(p.ext_id)}" data-idx="${i}">
              ${imported}
              <input type="checkbox" class="fapi-product-checkbox" value="${esc(p.ext_id)}" aria-label="${esc(p.title)}">
              <div class="fapi-product-img-wrap">${imgHtml}</div>
              <div class="fapi-product-body">
                <div class="fapi-product-title">${esc(p.title||'Untitled')}</div>
                <div class="fapi-product-meta">${price}${cat}${stock}</div>
              </div>
            </div>`;
        });
        html += '</div>';
        $('#fapi-products-grid').html(html);
        updateSelectedCount();
    }

    $(document).on('click', '.fapi-product-card', function(e) {
        if ($(e.target).is('input')) return;
        const $cb = $(this).find('.fapi-product-checkbox');
        $cb.prop('checked', !$cb.prop('checked'));
        $(this).toggleClass('selected', $cb.prop('checked'));
        updateSelectedCount();
    });
    $(document).on('change', '.fapi-product-checkbox', function() {
        $(this).closest('.fapi-product-card').toggleClass('selected', $(this).prop('checked'));
        updateSelectedCount();
    });

    function updateSelectedCount() {
        const n = $('.fapi-product-checkbox:checked').length;
        $('#fapi-selected-count').text(n);
        $('#fapi-btn-import-selected').prop('disabled', n === 0);
    }

    $('#fapi-product-search').on('input', function() {
        const q = $(this).val().toLowerCase();
        if (!q) { renderProducts(allProducts); return; }
        renderProducts(allProducts.filter(p => (p.title||'').toLowerCase().includes(q)||(p.cat||'').toLowerCase().includes(q)));
    });
    $('#fapi-btn-select-all').on('click', function() {
        $('.fapi-product-checkbox').prop('checked', true);
        $('.fapi-product-card').addClass('selected');
        updateSelectedCount();
    });
    $('#fapi-btn-select-none').on('click', function() {
        $('.fapi-product-checkbox').prop('checked', false);
        $('.fapi-product-card').removeClass('selected');
        updateSelectedCount();
    });
    $('#fapi-btn-refresh-preview').on('click', loadPreview);

    $('#fapi-btn-import-selected').on('click', function() {
        const ids = [];
        $('.fapi-product-checkbox:checked').each(function() { ids.push($(this).val()); });
        if (!ids.length) return;
        runImport('fapi_run_import_selected', { ids: JSON.stringify(ids) });
    });

    $('#fapi-btn-import-all').on('click', function() {
        if (!allProducts.length) return;
        if (!confirm('Import all ' + allProducts.length + ' products?')) return;
        runImport('fapi_run_import');
    });

    let pollInterval = null;
    function pollProgress() {
        if (!activeConnId) return;
        connAjax('fapi_get_progress').done(function(res) {
            if (!res.success) return;
            const d = res.data;
            const $progress = $('#fapi-import-progress');
            const $fill = $('.fapi-progress-fill');
            const $label = $('.fapi-progress-label');
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
        const $progress = $('#fapi-import-progress');
        const $fill     = $('.fapi-progress-fill');
        const $label    = $('.fapi-progress-label');
        $progress.show();
        $fill.css('width','10%');
        $label.text('Starting background import…');
        hideNotice('#fapi-import-result');

        connAjax(action, extra)
            .done(function(res) {
                if (!res.success) {
                    $label.text('Failed to start.');
                    showNotice('#fapi-import-result', '❌ ' + res.data.message, 'error');
                    return;
                }
                showNotice('#fapi-import-result', `✅ <strong>${res.data.message}</strong>`, 'success');
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(pollProgress, 2000);
            })
            .fail(() => showNotice('#fapi-import-result', '❌ AJAX error.', 'error'));
    }

    /* ══════════════════════════════════════════════════════════
       SCHEDULE TAB
    ══════════════════════════════════════════════════════════ */

    $('#fapi-btn-save-schedule').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('fapi_save_connection', {
            sync_enabled:  $('#fapi-sync-enabled').is(':checked') ? '1' : '0',
            sync_interval: $('#fapi-sync-interval').val(),
        }).done(function(res) {
            showNotice('#fapi-schedule-notice', res.success ? '✅ Schedule saved.' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    $('#fapi-btn-run-now').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('fapi_run_import')
            .done(function(res) {
                showNotice('#fapi-schedule-notice',
                    res.success ? '✅ ' + res.data.message : '❌ ' + res.data.message,
                    res.success ? 'success' : 'error');
                if (res.success) { loadConnections(); }
            })
            .fail(() => showNotice('#fapi-schedule-notice', '❌ AJAX error.', 'error'))
            .always(() => spin($btn, false));
    });

    /* ══════════════════════════════════════════════════════════
       LOGS TAB
    ══════════════════════════════════════════════════════════ */

    function loadLogs() {
        if (!activeConnId) return;
        connAjax('fapi_get_logs').done(function(res) {
            if (!res.success) return;
            renderLogs(res.data.logs || []);
        });
    }

    function renderLogs(logs) {
        if (!logs.length) {
            $('#fapi-log-list').html('<div class="fapi-log-empty">No log entries yet.</div>');
            return;
        }
        let html = '';
        logs.forEach(function(log) {
            html += `<div class="fapi-log-row ${esc(log.type)}">
              <span class="log-time">${esc(log.time)}</span>
              <span class="log-badge ${esc(log.type)}">${esc((log.type||'').toUpperCase())}</span>
              <span class="log-msg">${esc(log.message)}</span>
            </div>`;
        });
        $('#fapi-log-list').html(html);
    }

    $('#fapi-btn-clear-logs').on('click', function() {
        if (!activeConnId) return;
        if (!confirm('Clear logs for this connection?')) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('fapi_clear_logs').done(function(res) {
            if (res.success) renderLogs([]);
        }).always(() => spin($btn, false));
    });

    // Reload logs when switching to logs tab
    $(document).on('click', '.fapi-tab[data-tab="logs"]', function() {
        loadLogs();
    });

    /* ══════════════════════════════════════════════════════════
       HISTORY TAB
    ══════════════════════════════════════════════════════════ */

    function loadHistory() {
        if (!activeConnId) return;
        $('#fapi-history-list').html('<div class="fapi-log-empty">Loading history...</div>');
        connAjax('fapi_get_history').done(function(res) {
            if (!res.success) return;
            renderHistory(res.data.history || []);
        });
    }

    function renderHistory(history) {
        if (!history.length) {
            $('#fapi-history-list').html('<div class="fapi-log-empty">No import history yet.</div>');
            return;
        }
        let html = '';
        history.forEach(function(run) {
            html += `<div class="fapi-log-row" style="display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid #eee;">
              <div>
                  <span class="log-time" style="font-weight:600;color:#333;">${humanTime(run.date)}</span>
                  <span style="margin-left:15px;color:#666;">Imported: <strong>${run.imported}</strong>, Updated: <strong>${run.updated}</strong>, Failed: <strong>${run.failed}</strong></span>
              </div>
              <button class="fapi-btn danger-ghost fapi-btn-rollback" data-run="${esc(run.id)}" style="padding:4px 8px;font-size:12px;">Rollback</button>
            </div>`;
        });
        $('#fapi-history-list').html(html);
    }

    $('#fapi-btn-refresh-history').on('click', loadHistory);

    $(document).on('click', '.fapi-btn-rollback', function() {
        const runId = $(this).data('run');
        if (!confirm('Rollback this import? This will PERMANENTLY delete these products from WooCommerce!')) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('fapi_rollback_import', { run_id: runId }).done(function(res) {
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
    $(document).on('click', '.fapi-tab[data-tab="history"]', function() {
        loadHistory();
    });

    /* ══════════════════════════════════════════════════════════
       INIT
    ══════════════════════════════════════════════════════════ */
    loadConnections();

})(jQuery);

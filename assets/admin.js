/* global AWI, jQuery */
(function ($) {
    'use strict';

    /* ══════════════════════════════════════════════════════════
       STATE
    ══════════════════════════════════════════════════════════ */
    let activeConnId  = null;   // currently selected connection ID
    let activeConn    = null;   // full settings object of active conn
    let allConns      = [];     // summary array from dashboard
    let allProducts   = [];     // preview products for active conn
    let AWI_analysis  = null;   // last analyze result

    /* ══════════════════════════════════════════════════════════
       HELPERS
    ══════════════════════════════════════════════════════════ */
    const ajax = (action, data = {}) =>
        $.post(AWI.ajax_url, { action, nonce: AWI.nonce, ...data });

    const connAjax = (action, data = {}) =>
        ajax(action, { conn_id: activeConnId, ...data });

    function showNotice(sel, msg, type = 'info') {
        $(sel).attr('class', 'awi-notice ' + type).html(msg).show();
    }
    function hideNotice(sel) { $(sel).hide(); }

    function spin($btn, on) {
        if (on) $btn.data('orig', $btn.html()).html('<span class="awi-spinner"></span> Working…').prop('disabled', true);
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
        ajax('awi_get_dashboard').done(function(res) {
            if (!res.success) return;
            allConns = res.data.connections || [];
            renderSidebar();
        });
    }

    function renderSidebar() {
        const $list = $('#awi-conn-list');
        $('#awi-conn-count-pill').text(allConns.length + ' connection' + (allConns.length !== 1 ? 's' : ''));

        if (!allConns.length) {
            $list.html('<div class="awi-sidebar-loading">No connections yet.<br>Click ＋ to add one.</div>');
            return;
        }
        let html = '';
        allConns.forEach(function(c) {
            const sync  = `<div class="awi-sync-dot ${c.sync_enabled?'active':''}" title="Auto-sync ${c.sync_enabled?'on':'off'}"></div>`;
            const count = `<span class="awi-conn-item-count">${c.wc_count||0} products</span>`;
            const url   = c.api_url ? c.api_url.replace(/^https?:\/\//,'').substring(0,30)+'…' : 'Not configured';
            html += `
            <div class="awi-conn-item${c.id===activeConnId?' active':''}" data-id="${esc(c.id)}">
              <div class="awi-conn-item-info">
                <div class="awi-conn-item-label">${esc(c.label)}</div>
                <div class="awi-conn-item-url">${esc(url)}</div>
                <div class="awi-conn-item-meta">${sync}${count}</div>
              </div>
            </div>`;
        });
        $list.html(html);
    }

    $(document).on('click', '.awi-conn-item', function() {
        const id = $(this).data('id');
        selectConnection(id);
    });

    function selectConnection(id) {
        activeConnId = id;
        // Find in allConns
        const meta = allConns.find(c => c.id === id);

        // Highlight sidebar
        $('.awi-conn-item').removeClass('active');
        $(`.awi-conn-item[data-id="${id}"]`).addClass('active');

        // Show editor
        $('#awi-editor-empty').hide();
        $('#awi-conn-editor').show();

        // Reset state
        AWI_analysis = null;
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
        $('#awi-conn-label').val(conn.label || '');

        // Connection tab
        $('#awi-api-url').val(conn.api_url || '');
        $('#awi-api-method').val(conn.api_method || 'GET');
        $('#awi-api-bearer').val(conn.api_bearer || '');
        $('#awi-api-basic-user').val(conn.api_basic_user || '');
        $('#awi-api-basic-pass').val(conn.api_basic_pass || '');
        $('#awi-api-key-header').val(conn.api_key_header || '');
        $('#awi-api-key-param').val(conn.api_key_param || '');
        $('#awi-api-key-value').val(conn.api_key_value || '');
        $('#awi-api-extra-params').val(conn.api_extra_params || '');
        $('#awi-api-body').val(conn.api_body || '');
        $('#awi-webhook-secret').val(conn.webhook_secret || '');
        $('#awi-webhook-url').val(conn.id ? window.location.origin + '/wp-json/awi/v1/webhook/' + conn.id : '');

        // Options tab
        $('#awi-publish-status').val(conn.publish_status || 'publish');
        $('#awi-wc-category').val(conn.wc_category || '');
        $('#awi-tag-prefix').val(conn.tag_prefix || '');
        $('#awi-import-images').prop('checked', !!conn.import_images);
        $('#awi-update-existing').prop('checked', !!conn.update_existing);
        $('#awi-conflict-strategy').val(conn.conflict_strategy || 'update');
        $('#awi-pagination-style').val(conn.pagination_style || 'auto');
        $('#awi-pagination-param').val(conn.pagination_param || 'page');
        $('#awi-perpage-param').val(conn.perpage_param || 'per_page');
        $('#awi-perpage-size').val(conn.perpage_size || 100);

        // Schedule tab
        $('#awi-sync-enabled').prop('checked', !!conn.sync_enabled);
        $('#awi-sync-interval').val(conn.sync_interval || 'hourly');
        $('#awi-next-run').text(conn.next_run || '—');
        $('#awi-last-sync').text(humanTime(conn.last_sync));
        $('#awi-last-sync-count').text(conn.last_sync_count || 0);

        // Hide notices from previous session
        hideNotice('#awi-analysis-result');
        hideNotice('#awi-options-notice');
        hideNotice('#awi-schedule-notice');
        hideNotice('#awi-map-notice');
        hideNotice('#awi-import-result');

        // Reset mapping and products pane
        $('#awi-mapping-table-wrap').html('<div class="awi-map-loading"><span>Run Auto-Detect or configure your API first.</span></div>');
        $('#awi-sample-card').hide();
        $('#awi-products-grid').html('<div class="awi-products-empty"><div class="awi-empty-icon">📦</div><div>Click <strong>Refresh</strong> to fetch products.</div></div>');
        $('#awi-product-count').text('—');
    }

    /* ══════════════════════════════════════════════════════════
       ADD / DELETE / DUPLICATE
    ══════════════════════════════════════════════════════════ */

    function addConnection() {
        const label = prompt('Connection name:', 'New API Connection');
        if (label === null) return;
        ajax('awi_create_connection', { label: label || 'New API Connection' }).done(function(res) {
            if (!res.success) return alert('Error: ' + res.data.message);
            loadConnections();
            setTimeout(() => selectConnection(res.data.id), 400);
        });
    }

    $('#awi-btn-add-conn, #awi-btn-add-conn-center').on('click', addConnection);

    $('#awi-btn-delete-conn').on('click', function() {
        if (!activeConnId) return;
        const label = $('#awi-conn-label').val() || 'this connection';
        if (!confirm(`Delete "${label}"? All settings will be removed. Products already imported into WooCommerce will NOT be deleted.`)) return;
        ajax('awi_delete_connection', { conn_id: activeConnId }).done(function() {
            activeConnId = null;
            activeConn   = null;
            $('#awi-conn-editor').hide();
            $('#awi-editor-empty').show();
            loadConnections();
        });
    });

    $('#awi-btn-duplicate-conn').on('click', function() {
        if (!activeConnId) return;
        connAjax('awi_duplicate_connection').done(function(res) {
            if (!res.success) return alert('Error: ' + res.data.message);
            loadConnections();
            setTimeout(() => selectConnection(res.data.id), 400);
        });
    });

    /* ══════════════════════════════════════════════════════════
       TABS
    ══════════════════════════════════════════════════════════ */

    function switchTab(tab) {
        $('.awi-tab').removeClass('active');
        $('.awi-panel').removeClass('active');
        $(`.awi-tab[data-tab="${tab}"]`).addClass('active');
        $(`#tab-${tab}`).addClass('active');
    }

    $(document).on('click', '.awi-tab', function() {
        switchTab($(this).data('tab'));
    });

    /* ══════════════════════════════════════════════════════════
       CONNECTION TAB
    ══════════════════════════════════════════════════════════ */

    function getConnectionFields() {
        return {
            api_url:          $('#awi-api-url').val().trim(),
            api_method:       $('#awi-api-method').val(),
            api_bearer:       $('#awi-api-bearer').val().trim(),
            api_basic_user:   $('#awi-api-basic-user').val().trim(),
            api_basic_pass:   $('#awi-api-basic-pass').val().trim(),
            api_key_header:   $('#awi-api-key-header').val().trim(),
            api_key_param:    $('#awi-api-key-param').val().trim(),
            api_key_value:    $('#awi-api-key-value').val().trim(),
            api_extra_params: $('#awi-api-extra-params').val().trim(),
            api_body:         $('#awi-api-body').val().trim(),
            webhook_secret:   $('#awi-webhook-secret').val().trim(),
        };
    }

    $('#awi-btn-analyze').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        const fields = getConnectionFields();
        if (!fields.api_url) {
            showNotice('#awi-analysis-result', '⚠ Please enter the API Endpoint URL.', 'warning');
            return;
        }
        spin($btn, true);
        connAjax('awi_analyze_api', fields)
            .done(function(res) {
                if (!res.success) {
                    showNotice('#awi-analysis-result', '❌ ' + res.data.message, 'error');
                    return;
                }
                const d = res.data;
                AWI_analysis = d;
                let html = `✅ Connected! Found <strong>${d.total_found}</strong> products in <code>${d.products_key === '__root__' ? 'root array' : '"' + d.products_key + '"'}</code>. `;
                html += `Auto-detected <strong>${Object.keys(d.map).length}</strong> field mappings. `;
                html += `<a href="#" class="awi-goto-map">→ Review Mapping</a>`;
                showNotice('#awi-analysis-result', html, 'success');
            })
            .fail(() => showNotice('#awi-analysis-result', '❌ AJAX error — check your browser console.', 'error'))
            .always(() => spin($btn, false));
    });

    $(document).on('click', '.awi-goto-map', function(e) {
        e.preventDefault();
        switchTab('mapping');
    });

    $('#awi-btn-save-connection').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('awi_save_connection', {
            label:     $('#awi-conn-label').val().trim(),
            ...getConnectionFields(),
        }).done(function(res) {
            showNotice('#awi-analysis-result', res.success ? '✅ Connection saved.' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    $('#awi-btn-save-options').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('awi_save_connection', {
            publish_status:  $('#awi-publish-status').val(),
            wc_category:     $('#awi-wc-category').val().trim(),
            tag_prefix:      $('#awi-tag-prefix').val().trim(),
            import_images:   $('#awi-import-images').is(':checked') ? '1' : '0',
            update_existing: $('#awi-update-existing').is(':checked') ? '1' : '0',
            conflict_strategy: $('#awi-conflict-strategy').val(),
            pagination_style: $('#awi-pagination-style').val(),
            pagination_param: $('#awi-pagination-param').val().trim(),
            perpage_param:    $('#awi-perpage-param').val().trim(),
            perpage_size:     $('#awi-perpage-size').val().trim(),
        }).done(function(res) {
            showNotice('#awi-options-notice', res.success ? '✅ Options saved.' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
        }).always(() => spin($btn, false));
    });

    $('#awi-btn-delete-imported').on('click', function() {
        if (!activeConnId) return;
        const label = $('#awi-conn-label').val() || 'this connection';
        if (!confirm(`Permanently delete ALL products imported from "${label}"?\n\nThis cannot be undone.`)) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('awi_delete_imported').done(function(res) {
            showNotice('#awi-options-notice', res.success ? '✅ ' + res.data.message : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    // Live label sync
    $('#awi-conn-label').on('input', function() {
        const $item = $(`.awi-conn-item[data-id="${activeConnId}"] .awi-conn-item-label`);
        $item.text($(this).val());
    });

    /* ══════════════════════════════════════════════════════════
       FIELD MAPPING TAB
    ══════════════════════════════════════════════════════════ */

    function autoMap(forceRefetch) {
        const $wrap = $('#awi-mapping-table-wrap');
        $wrap.html('<div class="awi-map-loading">⏳ Analyzing API…</div>');

        if (AWI_analysis && !forceRefetch) {
            renderMappingTable(AWI_analysis);
            return;
        }

        const fields = getConnectionFields();
        if (!fields.api_url && activeConn) fields.api_url = activeConn.api_url || '';
        if (!fields.api_url) {
            $wrap.html('<div class="awi-map-loading">⚠ Configure your API connection first.</div>');
            return;
        }

        connAjax('awi_analyze_api', fields)
            .done(function(res) {
                if (!res.success) {
                    $wrap.html('<div class="awi-map-loading">❌ ' + res.data.message + '</div>');
                    return;
                }
                AWI_analysis = res.data;
                renderMappingTable(res.data);
            })
            .fail(() => $wrap.html('<div class="awi-map-loading">❌ AJAX error.</div>'));
    }

    function renderMappingTable(data) {
        const { all_keys, map, sample } = data;
        const savedMap     = (activeConn && activeConn.field_map) ? activeConn.field_map : {};
        const effectiveMap = Object.assign({}, map, savedMap);
        const wcFields     = AWI.wc_fields;

        let html = '<table class="awi-map-table">';
        html += '<thead><tr><th>WooCommerce Field</th><th>API Field</th><th>Status</th></tr></thead><tbody>';

        for (const [wcKey, meta] of Object.entries(wcFields)) {
            const selected   = effectiveMap[wcKey] || '';
            const required   = meta.required ? '<span class="awi-required-badge">REQUIRED</span>' : '';
            const confidence = selected ? (map[wcKey] === selected ? '✓ Auto' : '✏ Manual') : '—';
            const confClass  = selected && map[wcKey] === selected ? 'awi-map-confidence' : '';

            html += `<tr>
              <td class="awi-wc-field">${meta.label}${required}</td>
              <td>
                <select class="awi-map-select" data-wc="${wcKey}">
                  <option value="">— skip —</option>`;
            for (const k of all_keys) {
                html += `<option value="${esc(k)}"${k === selected ? ' selected' : ''}>${esc(k)}</option>`;
            }
            const hasTransforms = effectiveMap[wcKey] && activeConn && activeConn.field_transforms && activeConn.field_transforms[wcKey] && activeConn.field_transforms[wcKey].length > 0;
            const btnColor = hasTransforms ? '#6366f1' : 'inherit';
            html += `</select>
            <button class="awi-btn-icon-primary awi-btn-transform" data-wc="${wcKey}" title="Add transforms" style="color:${btnColor};border:1px solid #ccc;background:#f9f9f9;border-radius:4px;padding:2px 6px;margin-left:4px;cursor:pointer;">⚙️</button>
            </td>
              <td class="${confClass}" style="font-size:11px;">${confidence}</td>
            </tr>`;
        }
        html += '</tbody></table>';

        $('#awi-mapping-table-wrap').html(html);
        $('#awi-sample-json').text(JSON.stringify(sample, null, 2));
        $('#awi-sample-card').show();
        window.AWI_productsKey = data.products_key;
    }

    $('#awi-btn-automap').on('click', function() {
        AWI_analysis = null;
        autoMap(true);
    });

    $('#awi-btn-save-map').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        const map  = {};
        $('.awi-map-select').each(function() {
            const wc  = $(this).data('wc');
            const val = $(this).val();
            if (val) map[wc] = val;
        });
        if (!map.external_id && !map.title) {
            showNotice('#awi-map-notice', '⚠ Map at least "External ID" or "Product Title".', 'warning');
            return;
        }
        spin($btn, true);
        connAjax('awi_save_field_map', {
            field_map:    JSON.stringify(map),
            products_key: window.AWI_productsKey || 'auto',
        }).done(function(res) {
            showNotice('#awi-map-notice', res.success ? '✅ Field mapping saved!' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success && activeConn) activeConn.field_map = map;
        }).always(() => spin($btn, false));
    });

    $(document).on('click', '.awi-btn-transform', function(e) {
        e.preventDefault();
        const wcKey = $(this).data('wc');
        const transforms = activeConn && activeConn.field_transforms ? activeConn.field_transforms[wcKey] || [] : [];
        const json = prompt('Enter transform rules as JSON array (e.g. [{"type":"multiply","arg":"1.2"}]):', JSON.stringify(transforms));
        if (json !== null) {
            try {
                const parsed = JSON.parse(json || '[]');
                if (!activeConn.field_transforms) activeConn.field_transforms = {};
                activeConn.field_transforms[wcKey] = parsed;
                connAjax('awi_save_transforms', { field_transforms: JSON.stringify(activeConn.field_transforms) })
                    .done(res => {
                        showNotice('#awi-map-notice', res.success ? '✅ Transforms saved!' : '❌ Error', res.success ? 'success' : 'error');
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
        const $grid = $('#awi-products-grid');
        $grid.html('<div class="awi-products-empty"><div class="awi-empty-icon">⏳</div><div>Fetching products…</div></div>');
        hideNotice('#awi-import-result');

        connAjax('awi_fetch_preview').done(function(res) {
            if (!res.success) {
                $grid.html(`<div class="awi-products-empty"><div class="awi-empty-icon">❌</div><div>${res.data.message}</div></div>`);
                return;
            }
            allProducts = res.data.products;
            $('#awi-product-count').text(allProducts.length + ' products');
            renderProducts(allProducts);
        }).fail(() => {
            $grid.html('<div class="awi-products-empty"><div class="awi-empty-icon">❌</div><div>AJAX error loading preview.</div></div>');
        });
    }

    function renderProducts(products) {
        if (!products.length) {
            $('#awi-products-grid').html('<div class="awi-products-empty"><div class="awi-empty-icon">🔍</div><div>No products match.</div></div>');
            return;
        }
        let html = '<div class="awi-products-grid-inner">';
        products.forEach(function(p, i) {
            const imported = p.imported ? '<div class="awi-imported-tag">✓ Imported</div>' : '';
            const imgHtml  = p.image
                ? `<img src="${esc(p.image)}" loading="lazy" alt="" onerror="this.parentNode.innerHTML='<div class=\\'awi-product-no-img\\'>📦</div>'">`
                : '<div class="awi-product-no-img">📦</div>';
            const price = p.price !== '' && p.price !== null ? `<span class="awi-product-price">$${parseFloat(p.price).toFixed(2)}</span>` : '';
            const cat   = p.cat ? `<span class="awi-product-cat">${esc(p.cat)}</span>` : '';
            const stock = p.stock !== '' && p.stock !== null ? `<span class="awi-product-stock">Stock: ${p.stock}</span>` : '';
            html += `
            <div class="awi-product-card${p.imported?' already-imported':''}" data-ext-id="${esc(p.ext_id)}" data-idx="${i}">
              ${imported}
              <input type="checkbox" class="awi-product-checkbox" value="${esc(p.ext_id)}" aria-label="${esc(p.title)}">
              <div class="awi-product-img-wrap">${imgHtml}</div>
              <div class="awi-product-body">
                <div class="awi-product-title">${esc(p.title||'Untitled')}</div>
                <div class="awi-product-meta">${price}${cat}${stock}</div>
              </div>
            </div>`;
        });
        html += '</div>';
        $('#awi-products-grid').html(html);
        updateSelectedCount();
    }

    $(document).on('click', '.awi-product-card', function(e) {
        if ($(e.target).is('input')) return;
        const $cb = $(this).find('.awi-product-checkbox');
        $cb.prop('checked', !$cb.prop('checked'));
        $(this).toggleClass('selected', $cb.prop('checked'));
        updateSelectedCount();
    });
    $(document).on('change', '.awi-product-checkbox', function() {
        $(this).closest('.awi-product-card').toggleClass('selected', $(this).prop('checked'));
        updateSelectedCount();
    });

    function updateSelectedCount() {
        const n = $('.awi-product-checkbox:checked').length;
        $('#awi-selected-count').text(n);
        $('#awi-btn-import-selected').prop('disabled', n === 0);
    }

    $('#awi-product-search').on('input', function() {
        const q = $(this).val().toLowerCase();
        if (!q) { renderProducts(allProducts); return; }
        renderProducts(allProducts.filter(p => (p.title||'').toLowerCase().includes(q)||(p.cat||'').toLowerCase().includes(q)));
    });
    $('#awi-btn-select-all').on('click', function() {
        $('.awi-product-checkbox').prop('checked', true);
        $('.awi-product-card').addClass('selected');
        updateSelectedCount();
    });
    $('#awi-btn-select-none').on('click', function() {
        $('.awi-product-checkbox').prop('checked', false);
        $('.awi-product-card').removeClass('selected');
        updateSelectedCount();
    });
    $('#awi-btn-refresh-preview').on('click', loadPreview);

    $('#awi-btn-import-selected').on('click', function() {
        const ids = [];
        $('.awi-product-checkbox:checked').each(function() { ids.push($(this).val()); });
        if (!ids.length) return;
        runImport('awi_run_import_selected', { ids: JSON.stringify(ids) });
    });

    $('#awi-btn-import-all').on('click', function() {
        if (!allProducts.length) return;
        if (!confirm('Import all ' + allProducts.length + ' products?')) return;
        runImport('awi_run_import');
    });

    let pollInterval = null;
    function pollProgress() {
        if (!activeConnId) return;
        connAjax('awi_get_progress').done(function(res) {
            if (!res.success) return;
            const d = res.data;
            const $progress = $('#awi-import-progress');
            const $fill = $('.awi-progress-fill');
            const $label = $('.awi-progress-label');
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
        const $progress = $('#awi-import-progress');
        const $fill     = $('.awi-progress-fill');
        const $label    = $('.awi-progress-label');
        $progress.show();
        $fill.css('width','10%');
        $label.text('Starting background import…');
        hideNotice('#awi-import-result');

        connAjax(action, extra)
            .done(function(res) {
                if (!res.success) {
                    $label.text('Failed to start.');
                    showNotice('#awi-import-result', '❌ ' + res.data.message, 'error');
                    return;
                }
                showNotice('#awi-import-result', `✅ <strong>${res.data.message}</strong>`, 'success');
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(pollProgress, 2000);
            })
            .fail(() => showNotice('#awi-import-result', '❌ AJAX error.', 'error'));
    }

    /* ══════════════════════════════════════════════════════════
       SCHEDULE TAB
    ══════════════════════════════════════════════════════════ */

    $('#awi-btn-save-schedule').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('awi_save_connection', {
            sync_enabled:  $('#awi-sync-enabled').is(':checked') ? '1' : '0',
            sync_interval: $('#awi-sync-interval').val(),
        }).done(function(res) {
            showNotice('#awi-schedule-notice', res.success ? '✅ Schedule saved.' : '❌ ' + res.data.message, res.success ? 'success' : 'error');
            if (res.success) loadConnections();
        }).always(() => spin($btn, false));
    });

    $('#awi-btn-run-now').on('click', function() {
        if (!activeConnId) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('awi_run_import')
            .done(function(res) {
                showNotice('#awi-schedule-notice',
                    res.success ? '✅ ' + res.data.message : '❌ ' + res.data.message,
                    res.success ? 'success' : 'error');
                if (res.success) { loadConnections(); }
            })
            .fail(() => showNotice('#awi-schedule-notice', '❌ AJAX error.', 'error'))
            .always(() => spin($btn, false));
    });

    /* ══════════════════════════════════════════════════════════
       LOGS TAB
    ══════════════════════════════════════════════════════════ */

    function loadLogs() {
        if (!activeConnId) return;
        connAjax('awi_get_logs').done(function(res) {
            if (!res.success) return;
            renderLogs(res.data.logs || []);
        });
    }

    function renderLogs(logs) {
        if (!logs.length) {
            $('#awi-log-list').html('<div class="awi-log-empty">No log entries yet.</div>');
            return;
        }
        let html = '';
        logs.forEach(function(log) {
            html += `<div class="awi-log-row ${esc(log.type)}">
              <span class="log-time">${esc(log.time)}</span>
              <span class="log-badge ${esc(log.type)}">${esc((log.type||'').toUpperCase())}</span>
              <span class="log-msg">${esc(log.message)}</span>
            </div>`;
        });
        $('#awi-log-list').html(html);
    }

    $('#awi-btn-clear-logs').on('click', function() {
        if (!activeConnId) return;
        if (!confirm('Clear logs for this connection?')) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('awi_clear_logs').done(function(res) {
            if (res.success) renderLogs([]);
        }).always(() => spin($btn, false));
    });

    // Reload logs when switching to logs tab
    $(document).on('click', '.awi-tab[data-tab="logs"]', function() {
        loadLogs();
    });

    /* ══════════════════════════════════════════════════════════
       HISTORY TAB
    ══════════════════════════════════════════════════════════ */

    function loadHistory() {
        if (!activeConnId) return;
        $('#awi-history-list').html('<div class="awi-log-empty">Loading history...</div>');
        connAjax('awi_get_history').done(function(res) {
            if (!res.success) return;
            renderHistory(res.data.history || []);
        });
    }

    function renderHistory(history) {
        if (!history.length) {
            $('#awi-history-list').html('<div class="awi-log-empty">No import history yet.</div>');
            return;
        }
        let html = '';
        history.forEach(function(run) {
            html += `<div class="awi-log-row" style="display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid #eee;">
              <div>
                  <span class="log-time" style="font-weight:600;color:#333;">${humanTime(run.date)}</span>
                  <span style="margin-left:15px;color:#666;">Imported: <strong>${run.imported}</strong>, Updated: <strong>${run.updated}</strong>, Failed: <strong>${run.failed}</strong></span>
              </div>
              <button class="awi-btn danger-ghost awi-btn-rollback" data-run="${esc(run.id)}" style="padding:4px 8px;font-size:12px;">Rollback</button>
            </div>`;
        });
        $('#awi-history-list').html(html);
    }

    $('#awi-btn-refresh-history').on('click', loadHistory);

    $(document).on('click', '.awi-btn-rollback', function() {
        const runId = $(this).data('run');
        if (!confirm('Rollback this import? This will PERMANENTLY delete these products from WooCommerce!')) return;
        const $btn = $(this);
        spin($btn, true);
        connAjax('awi_rollback_import', { run_id: runId }).done(function(res) {
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
    $(document).on('click', '.awi-tab[data-tab="history"]', function() {
        loadHistory();
    });

    /* ══════════════════════════════════════════════════════════
       INIT
    ══════════════════════════════════════════════════════════ */
    loadConnections();

})(jQuery);

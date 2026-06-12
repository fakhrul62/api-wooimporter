<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class FAPI_Admin {

    private static $instance;

    public static function get_instance() {
        if ( ! self::$instance ) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',             [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue' ] );
    }

    public function register_menu() {
        add_menu_page(
            'ApiroSync Product Sync',
            'ApiroSync',
            'manage_woocommerce',
            'fakhrulalam16-api-product-sync',
            [ $this, 'render_page' ],
            'dashicons-cloud-upload',
            56
        );
    }

    public function enqueue( $hook ) {
        if ( strpos( $hook, 'fakhrulalam16-api-product-sync' ) === false ) return;
        wp_enqueue_style(  'fapi-admin', FAPI_URL . 'assets/admin.css', [],             FAPI_VERSION );
        wp_enqueue_script( 'fapi-admin', FAPI_URL . 'assets/admin.js',  ['jquery'], FAPI_VERSION, true );
        wp_localize_script( 'fapi-admin', 'FAPI', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'fapi_nonce' ),
            'wc_fields'   => FAPI_Field_Mapper::WC_FIELDS,
            'wc_active'   => class_exists( 'WooCommerce' ),
            'version'     => FAPI_VERSION,
        ]);
    }

    public function render_page() {
        ?>
        <div id="fapi-app">

        <!-- ── HEADER ── -->
        <div class="fapi-header">
            <div class="fapi-header-inner">
                <div class="fapi-logo">
                    <span class="fapi-logo-icon">⚡</span>
                    <div>
                        <div class="fapi-logo-title">ApiroSync Product Sync <span class="fapi-version-badge">v<?php echo esc_html(FAPI_VERSION); ?></span></div>
                        <div class="fapi-logo-sub">Multiple REST APIs to WooCommerce, fully isolated per connection</div>
                    </div>
                </div>
                <div class="fapi-header-meta">
                    <div class="fapi-status-pill ok">🔌 Built-in Fetcher</div>
                    <div class="fapi-status-pill <?php echo class_exists('WooCommerce') ? 'ok' : 'err'; ?>">
                        <?php echo class_exists('WooCommerce') ? '✓ WooCommerce Active' : '✗ WooCommerce Missing'; ?>
                    </div>
                    <div class="fapi-status-pill info" id="fapi-conn-count-pill">— connections</div>
                </div>
            </div>
        </div>

        <!-- ── MAIN LAYOUT ── -->
        <div class="fapi-layout">

            <!-- LEFT: Connection Sidebar -->
            <div class="fapi-sidebar" id="fapi-sidebar">
                <div class="fapi-sidebar-header">
                    <span class="fapi-sidebar-title">API Connections</span>
                    <button class="fapi-btn-icon-primary" id="fapi-btn-add-conn" title="Add new connection">＋</button>
                </div>
                <div id="fapi-conn-list">
                    <div class="fapi-sidebar-loading">Loading…</div>
                </div>
            </div>

            <!-- RIGHT: Editor Area -->
            <div class="fapi-editor" id="fapi-editor">
                <div class="fapi-editor-empty" id="fapi-editor-empty">
                    <div class="fapi-empty-icon">🔌</div>
                    <div class="fapi-empty-title">No connection selected</div>
                    <div class="fapi-empty-sub">Select a connection from the sidebar or create a new one to get started.</div>
                    <button class="fapi-btn primary" id="fapi-btn-add-conn-center">＋ Add First API Connection</button>
                </div>

                <!-- ── PER-CONNECTION EDITOR (hidden until a conn is selected) ── -->
                <div id="fapi-conn-editor" style="display:none;">

                    <!-- Editor header -->
                    <div class="fapi-editor-header">
                        <div class="fapi-editor-header-left">
                            <input type="text" id="fapi-conn-label" class="fapi-conn-label-input" placeholder="Connection name" />
                        </div>
                        <div class="fapi-editor-header-right">
                            <button class="fapi-btn danger-ghost" id="fapi-btn-delete-conn">🗑 Delete</button>
                            <button class="fapi-btn ghost" id="fapi-btn-duplicate-conn">⧉ Duplicate</button>
                        </div>
                    </div>

                    <!-- Editor tabs -->
                    <div class="fapi-tabs">
                        <button class="fapi-tab active" data-tab="connection">🔌 Connection</button>
                        <button class="fapi-tab" data-tab="mapping">🗺️ Field Mapping</button>
                        <button class="fapi-tab" data-tab="products">📦 Products</button>
                        <button class="fapi-tab" data-tab="schedule">⏰ Auto Sync</button>
                        <button class="fapi-tab" data-tab="logs">📋 Logs</button>
                        <button class="fapi-tab" data-tab="history">⏪ History</button>
                    </div>

                    <!-- ══ TAB: CONNECTION ══ -->
                    <div class="fapi-panel active" id="tab-connection">
                        <div class="fapi-card">
                            <h2 class="fapi-card-title">API Connection</h2>
                            <p class="fapi-card-desc">Configure the REST API endpoint for this connection. Each connection is fully isolated — products from different connections never merge.</p>

                            <div class="fapi-form-grid" style="grid-template-columns:1fr;">
                                <div class="fapi-field">
                                    <label>API Endpoint URL</label>
                                    <input type="text" id="fapi-api-url" placeholder="https://api.example.com/v1/products" style="font-family:monospace;" />
                                    <span class="fapi-hint">Full REST endpoint that returns your product list.</span>
                                </div>
                            </div>

                            <div class="fapi-form-grid">
                                <div class="fapi-field">
                                    <label>HTTP Method</label>
                                    <select id="fapi-api-method">
                                        <option value="GET">GET</option>
                                        <option value="POST">POST</option>
                                    </select>
                                </div>
                                <div class="fapi-field">
                                    <label>Bearer Token (optional)</label>
                                    <input type="text" id="fapi-api-bearer" placeholder="eyJhbGciOiJIUzI1NiIs…" />
                                    <span class="fapi-hint">Sent as <code>Authorization: Bearer …</code></span>
                                </div>
                            </div>

                            <details class="fapi-advanced-toggle">
                                <summary>⚙ Advanced Auth &amp; Options</summary>
                                <div class="fapi-form-grid" style="margin-top:16px;">
                                    <div class="fapi-field">
                                        <label>Basic Auth — Username</label>
                                        <input type="text" id="fapi-api-basic-user" placeholder="username" />
                                    </div>
                                    <div class="fapi-field">
                                        <label>Basic Auth — Password</label>
                                        <input type="password" id="fapi-api-basic-pass" placeholder="password" />
                                    </div>
                                    <div class="fapi-field">
                                        <label>API Key Header Name</label>
                                        <input type="text" id="fapi-api-key-header" placeholder="X-API-Key" />
                                    </div>
                                    <div class="fapi-field">
                                        <label>API Key / Query Param Name</label>
                                        <input type="text" id="fapi-api-key-param" placeholder="api_key" />
                                    </div>
                                    <div class="fapi-field">
                                        <label>API Key Value</label>
                                        <input type="text" id="fapi-api-key-value" placeholder="your-secret-key" />
                                    </div>
                                    <div class="fapi-field">
                                        <label>Extra Query Params</label>
                                        <textarea id="fapi-api-extra-params" rows="3" placeholder="limit=100&#10;locale=en" style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px;font-family:monospace;font-size:12px;color:var(--text);resize:vertical;"></textarea>
                                        <span class="fapi-hint">One <code>key=value</code> per line.</span>
                                    </div>
                                    <div class="fapi-field">
                                        <label>POST Body (JSON)</label>
                                        <textarea id="fapi-api-body" rows="3" placeholder='{"filter":"active"}' style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px;font-family:monospace;font-size:12px;color:var(--text);resize:vertical;"></textarea>
                                        <span class="fapi-hint">Only used when Method is POST.</span>
                                    </div>
                                    <div class="fapi-field">
                                        <label>Webhook Secret</label>
                                        <input type="text" id="fapi-webhook-secret" placeholder="Optional secret token" />
                                    </div>
                                    <div class="fapi-field">
                                        <label>Webhook Endpoint (Read-only)</label>
                                        <input type="text" id="fapi-webhook-url" readonly style="font-family:monospace;background:#f3f4f6;" />
                                        <span class="fapi-hint">Send a POST JSON payload. Append <code>?secret=...</code> or send <code>x-fapi-secret</code> header if using secret.</span>
                                    </div>
                                </div>
                            </details>

                            <div class="fapi-actions" style="margin-top:20px;">
                                <button class="fapi-btn primary" id="fapi-btn-analyze">
                                    <span class="btn-icon">🔍</span> Test &amp; Analyze API
                                </button>
                                <button class="fapi-btn ghost" id="fapi-btn-save-connection">
                                    <span class="btn-icon">💾</span> Save Connection
                                </button>
                            </div>
                            <div id="fapi-analysis-result" class="fapi-notice" style="display:none;"></div>
                        </div>

                        <!-- Import Options -->
                        <div class="fapi-card">
                            <h2 class="fapi-card-title">Import Options</h2>
                            <div class="fapi-form-grid">
                                <div class="fapi-field">
                                    <label>Publish Status</label>
                                    <select id="fapi-publish-status">
                                        <option value="publish">Published</option>
                                        <option value="draft">Draft</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                                <div class="fapi-field">
                                    <label>Conflict Strategy</label>
                                    <select id="fapi-conflict-strategy">
                                        <option value="update">Update (Overwrite existing)</option>
                                        <option value="skip">Skip (Do nothing if exists)</option>
                                        <option value="merge">Merge (Fill missing only)</option>
                                    </select>
                                </div>
                                <div class="fapi-field">
                                    <label>Default Category <span class="fapi-hint-inline">(fallback if API has none)</span></label>
                                    <input type="text" id="fapi-wc-category" placeholder="e.g. Uncategorized" />
                                </div>
                                <div class="fapi-field">
                                    <label>Tag Prefix <span class="fapi-hint-inline">(prepended to all tags)</span></label>
                                    <input type="text" id="fapi-tag-prefix" placeholder="e.g. supplier-a-" />
                                </div>
                                <div class="fapi-field">
                                    <label>Pagination Style</label>
                                    <select id="fapi-pagination-style">
                                        <option value="auto">Auto-detect</option>
                                        <option value="header">Headers (X-Total-Count / Link)</option>
                                        <option value="body">Body (total / count / pages)</option>
                                        <option value="empty-page">Iterate until empty page</option>
                                    </select>
                                </div>
                                <div class="fapi-field">
                                    <label>Pagination Param Name</label>
                                    <input type="text" id="fapi-pagination-param" placeholder="page" />
                                </div>
                                <div class="fapi-field">
                                    <label>Per-Page Param Name</label>
                                    <input type="text" id="fapi-perpage-param" placeholder="per_page" />
                                </div>
                                <div class="fapi-field">
                                    <label>Batch Size</label>
                                    <input type="number" id="fapi-perpage-size" placeholder="100" />
                                </div>
                                <div class="fapi-field fapi-toggle-field">
                                    <label>Import Product Images</label>
                                    <label class="fapi-toggle">
                                        <input type="checkbox" id="fapi-import-images">
                                        <span class="fapi-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="fapi-field fapi-toggle-field">
                                    <label>Update Existing Products</label>
                                    <label class="fapi-toggle">
                                        <input type="checkbox" id="fapi-update-existing">
                                        <span class="fapi-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="fapi-actions">
                                <button class="fapi-btn ghost" id="fapi-btn-save-options">💾 Save Options</button>
                                <button class="fapi-btn danger-ghost" id="fapi-btn-delete-imported">🗑 Delete All Imported Products</button>
                            </div>
                            <div id="fapi-options-notice" class="fapi-notice" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- ══ TAB: FIELD MAPPING ══ -->
                    <div class="fapi-panel" id="tab-mapping">
                        <div class="fapi-card">
                            <h2 class="fapi-card-title">Field Mapping</h2>
                            <p class="fapi-card-desc">Map API response fields to WooCommerce product fields. Each connection has its own independent mapping.</p>
                            <div class="fapi-actions">
                                <button class="fapi-btn primary" id="fapi-btn-automap">✨ Auto-Detect Fields</button>
                                <button class="fapi-btn success" id="fapi-btn-save-map">💾 Save Mapping</button>
                            </div>
                            <div id="fapi-map-notice" class="fapi-notice" style="display:none;"></div>
                            <div id="fapi-mapping-table-wrap">
                                <div class="fapi-map-loading"><span>Run Auto-Detect or configure your API connection first.</span></div>
                            </div>
                        </div>
                        <div class="fapi-card" id="fapi-sample-card" style="display:none;">
                            <h2 class="fapi-card-title">API Sample Response</h2>
                            <p class="fapi-card-desc">First item returned by the API — used to build the field map.</p>
                            <pre id="fapi-sample-json" class="fapi-code-block"></pre>
                        </div>
                    </div>

                    <!-- ══ TAB: PRODUCTS ══ -->
                    <div class="fapi-panel" id="tab-products">
                        <div class="fapi-card">
                            <div class="fapi-products-toolbar">
                                <div class="fapi-products-toolbar-left">
                                    <h2 class="fapi-card-title" style="margin:0;">Product Preview</h2>
                                    <span class="fapi-badge" id="fapi-product-count">—</span>
                                </div>
                                <div class="fapi-products-toolbar-right">
                                    <input type="text" id="fapi-product-search" placeholder="🔍 Search…" class="fapi-search-input" />
                                    <button class="fapi-btn ghost" id="fapi-btn-refresh-preview">🔄 Refresh</button>
                                    <button class="fapi-btn ghost" id="fapi-btn-select-all">☑ All</button>
                                    <button class="fapi-btn ghost" id="fapi-btn-select-none">☐ Clear</button>
                                    <button class="fapi-btn primary" id="fapi-btn-import-selected" disabled>
                                        ⬇ Import (<span id="fapi-selected-count">0</span>)
                                    </button>
                                    <button class="fapi-btn success" id="fapi-btn-import-all">⬇ All</button>
                                </div>
                            </div>
                            <div id="fapi-import-progress" class="fapi-progress-bar-wrap" style="display:none;">
                                <div class="fapi-progress-bar"><div class="fapi-progress-fill"></div></div>
                                <span class="fapi-progress-label">Importing…</span>
                            </div>
                            <div id="fapi-import-result" class="fapi-notice" style="display:none;"></div>
                            <div id="fapi-products-grid">
                                <div class="fapi-products-empty">
                                    <div class="fapi-empty-icon">📦</div>
                                    <div>Click <strong>Refresh</strong> to fetch products from the API</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ TAB: SCHEDULE ══ -->
                    <div class="fapi-panel" id="tab-schedule">
                        <div class="fapi-card">
                            <h2 class="fapi-card-title">Auto Sync Schedule</h2>
                            <p class="fapi-card-desc">Automatically import new and updated products on a schedule using WP-Cron. Each connection runs independently.</p>
                            <div class="fapi-form-grid">
                                <div class="fapi-field fapi-toggle-field">
                                    <label>Enable Auto Sync</label>
                                    <label class="fapi-toggle">
                                        <input type="checkbox" id="fapi-sync-enabled">
                                        <span class="fapi-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="fapi-field">
                                    <label>Sync Interval</label>
                                    <select id="fapi-sync-interval">
                                        <option value="hourly">Every Hour</option>
                                        <option value="twicedaily">Twice Daily</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                    </select>
                                </div>
                            </div>
                            <div class="fapi-schedule-info" id="fapi-schedule-info">
                                <div class="fapi-info-row">
                                    <span class="info-label">Next run:</span>
                                    <span class="info-val" id="fapi-next-run">—</span>
                                </div>
                                <div class="fapi-info-row">
                                    <span class="info-label">Last sync:</span>
                                    <span class="info-val" id="fapi-last-sync">—</span>
                                </div>
                                <div class="fapi-info-row">
                                    <span class="info-label">Last sync count:</span>
                                    <span class="info-val" id="fapi-last-sync-count">—</span>
                                </div>
                            </div>
                            <div class="fapi-actions">
                                <button class="fapi-btn primary" id="fapi-btn-save-schedule">💾 Save Schedule</button>
                                <button class="fapi-btn ghost" id="fapi-btn-run-now">▶ Run Import Now</button>
                            </div>
                            <div id="fapi-schedule-notice" class="fapi-notice" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- ══ TAB: LOGS ══ -->
                    <div class="fapi-panel" id="tab-logs">
                        <div class="fapi-card">
                            <div class="fapi-logs-toolbar" style="display:flex;justify-content:space-between;">
                                <h2 class="fapi-card-title" style="margin:0;">Activity Log</h2>
                                <button class="fapi-btn ghost danger" id="fapi-btn-clear-logs">🗑 Clear</button>
                            </div>
                            <div id="fapi-log-list">
                                <div class="fapi-log-empty">Select a connection to see its logs.</div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ TAB: HISTORY ══ -->
                    <div class="fapi-panel" id="tab-history">
                        <div class="fapi-card">
                            <div class="fapi-history-toolbar" style="display:flex;justify-content:space-between;margin-bottom:20px;">
                                <h2 class="fapi-card-title" style="margin:0;">Import History (Last 20 runs)</h2>
                                <button class="fapi-btn ghost" id="fapi-btn-refresh-history">🔄 Refresh</button>
                            </div>
                            <div id="fapi-history-list">
                                <div class="fapi-log-empty">Loading history...</div>
                            </div>
                        </div>
                    </div>

                </div><!-- #fapi-conn-editor -->
            </div><!-- .fapi-editor -->
        </div><!-- .fapi-layout -->

        </div><!-- #fapi-app -->
        <?php
    }
}

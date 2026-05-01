<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class AWI_Admin {

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
            'API WooImporter',
            'API Importer',
            'manage_woocommerce',
            'api-woo-importer',
            [ $this, 'render_page' ],
            'dashicons-cloud-upload',
            56
        );
    }

    public function enqueue( $hook ) {
        if ( strpos( $hook, 'api-woo-importer' ) === false ) return;
        wp_enqueue_style(  'awi-admin', AWI_URL . 'assets/admin.css', [],             AWI_VERSION );
        wp_enqueue_script( 'awi-admin', AWI_URL . 'assets/admin.js',  ['jquery'], AWI_VERSION, true );
        wp_localize_script( 'awi-admin', 'AWI', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'awi_nonce' ),
            'wc_fields'   => AWI_Field_Mapper::WC_FIELDS,
            'wc_active'   => class_exists( 'WooCommerce' ),
            'version'     => AWI_VERSION,
        ]);
    }

    public function render_page() {
        ?>
        <div id="awi-app">

        <!-- ── HEADER ── -->
        <div class="awi-header">
            <div class="awi-header-inner">
                <div class="awi-logo">
                    <span class="awi-logo-icon">⚡</span>
                    <div>
                        <div class="awi-logo-title">API WooImporter <span class="awi-version-badge">v<?php echo esc_html(AWI_VERSION); ?></span></div>
                        <div class="awi-logo-sub">Multiple REST APIs → WooCommerce, fully isolated · by Fakhrul Alam</div>
                    </div>
                </div>
                <div class="awi-header-meta">
                    <div class="awi-status-pill ok">🔌 Built-in Fetcher</div>
                    <div class="awi-status-pill <?php echo class_exists('WooCommerce') ? 'ok' : 'err'; ?>">
                        <?php echo class_exists('WooCommerce') ? '✓ WooCommerce Active' : '✗ WooCommerce Missing'; ?>
                    </div>
                    <div class="awi-status-pill info" id="awi-conn-count-pill">— connections</div>
                </div>
            </div>
        </div>

        <!-- ── MAIN LAYOUT ── -->
        <div class="awi-layout">

            <!-- LEFT: Connection Sidebar -->
            <div class="awi-sidebar" id="awi-sidebar">
                <div class="awi-sidebar-header">
                    <span class="awi-sidebar-title">API Connections</span>
                    <button class="awi-btn-icon-primary" id="awi-btn-add-conn" title="Add new connection">＋</button>
                </div>
                <div id="awi-conn-list">
                    <div class="awi-sidebar-loading">Loading…</div>
                </div>
            </div>

            <!-- RIGHT: Editor Area -->
            <div class="awi-editor" id="awi-editor">
                <div class="awi-editor-empty" id="awi-editor-empty">
                    <div class="awi-empty-icon">🔌</div>
                    <div class="awi-empty-title">No connection selected</div>
                    <div class="awi-empty-sub">Select a connection from the sidebar or create a new one to get started.</div>
                    <button class="awi-btn primary" id="awi-btn-add-conn-center">＋ Add First API Connection</button>
                </div>

                <!-- ── PER-CONNECTION EDITOR (hidden until a conn is selected) ── -->
                <div id="awi-conn-editor" style="display:none;">

                    <!-- Editor header -->
                    <div class="awi-editor-header">
                        <div class="awi-editor-header-left">
                            <input type="text" id="awi-conn-label" class="awi-conn-label-input" placeholder="Connection name" />
                        </div>
                        <div class="awi-editor-header-right">
                            <button class="awi-btn danger-ghost" id="awi-btn-delete-conn">🗑 Delete</button>
                            <button class="awi-btn ghost" id="awi-btn-duplicate-conn">⧉ Duplicate</button>
                        </div>
                    </div>

                    <!-- Editor tabs -->
                    <div class="awi-tabs">
                        <button class="awi-tab active" data-tab="connection">🔌 Connection</button>
                        <button class="awi-tab" data-tab="mapping">🗺️ Field Mapping</button>
                        <button class="awi-tab" data-tab="products">📦 Products</button>
                        <button class="awi-tab" data-tab="schedule">⏰ Auto Sync</button>
                        <button class="awi-tab" data-tab="logs">📋 Logs</button>
                        <button class="awi-tab" data-tab="history">⏪ History</button>
                    </div>

                    <!-- ══ TAB: CONNECTION ══ -->
                    <div class="awi-panel active" id="tab-connection">
                        <div class="awi-card">
                            <h2 class="awi-card-title">API Connection</h2>
                            <p class="awi-card-desc">Configure the REST API endpoint for this connection. Each connection is fully isolated — products from different connections never merge.</p>

                            <div class="awi-form-grid" style="grid-template-columns:1fr;">
                                <div class="awi-field">
                                    <label>API Endpoint URL</label>
                                    <input type="text" id="awi-api-url" placeholder="https://api.example.com/v1/products" style="font-family:monospace;" />
                                    <span class="awi-hint">Full REST endpoint that returns your product list.</span>
                                </div>
                            </div>

                            <div class="awi-form-grid">
                                <div class="awi-field">
                                    <label>HTTP Method</label>
                                    <select id="awi-api-method">
                                        <option value="GET">GET</option>
                                        <option value="POST">POST</option>
                                    </select>
                                </div>
                                <div class="awi-field">
                                    <label>Bearer Token (optional)</label>
                                    <input type="text" id="awi-api-bearer" placeholder="eyJhbGciOiJIUzI1NiIs…" />
                                    <span class="awi-hint">Sent as <code>Authorization: Bearer …</code></span>
                                </div>
                            </div>

                            <details class="awi-advanced-toggle">
                                <summary>⚙ Advanced Auth &amp; Options</summary>
                                <div class="awi-form-grid" style="margin-top:16px;">
                                    <div class="awi-field">
                                        <label>Basic Auth — Username</label>
                                        <input type="text" id="awi-api-basic-user" placeholder="username" />
                                    </div>
                                    <div class="awi-field">
                                        <label>Basic Auth — Password</label>
                                        <input type="password" id="awi-api-basic-pass" placeholder="password" />
                                    </div>
                                    <div class="awi-field">
                                        <label>API Key Header Name</label>
                                        <input type="text" id="awi-api-key-header" placeholder="X-API-Key" />
                                    </div>
                                    <div class="awi-field">
                                        <label>API Key / Query Param Name</label>
                                        <input type="text" id="awi-api-key-param" placeholder="api_key" />
                                    </div>
                                    <div class="awi-field">
                                        <label>API Key Value</label>
                                        <input type="text" id="awi-api-key-value" placeholder="your-secret-key" />
                                    </div>
                                    <div class="awi-field">
                                        <label>Extra Query Params</label>
                                        <textarea id="awi-api-extra-params" rows="3" placeholder="limit=100&#10;locale=en" style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px;font-family:monospace;font-size:12px;color:var(--text);resize:vertical;"></textarea>
                                        <span class="awi-hint">One <code>key=value</code> per line.</span>
                                    </div>
                                    <div class="awi-field">
                                        <label>POST Body (JSON)</label>
                                        <textarea id="awi-api-body" rows="3" placeholder='{"filter":"active"}' style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px;font-family:monospace;font-size:12px;color:var(--text);resize:vertical;"></textarea>
                                        <span class="awi-hint">Only used when Method is POST.</span>
                                    </div>
                                    <div class="awi-field">
                                        <label>Webhook Secret</label>
                                        <input type="text" id="awi-webhook-secret" placeholder="Optional secret token" />
                                    </div>
                                    <div class="awi-field">
                                        <label>Webhook Endpoint (Read-only)</label>
                                        <input type="text" id="awi-webhook-url" readonly style="font-family:monospace;background:#f3f4f6;" />
                                        <span class="awi-hint">Send a POST JSON payload. Append <code>?secret=...</code> or send <code>X-AWI-Secret</code> header if using secret.</span>
                                    </div>
                                </div>
                            </details>

                            <div class="awi-actions" style="margin-top:20px;">
                                <button class="awi-btn primary" id="awi-btn-analyze">
                                    <span class="btn-icon">🔍</span> Test &amp; Analyze API
                                </button>
                                <button class="awi-btn ghost" id="awi-btn-save-connection">
                                    <span class="btn-icon">💾</span> Save Connection
                                </button>
                            </div>
                            <div id="awi-analysis-result" class="awi-notice" style="display:none;"></div>
                        </div>

                        <!-- Import Options -->
                        <div class="awi-card">
                            <h2 class="awi-card-title">Import Options</h2>
                            <div class="awi-form-grid">
                                <div class="awi-field">
                                    <label>Publish Status</label>
                                    <select id="awi-publish-status">
                                        <option value="publish">Published</option>
                                        <option value="draft">Draft</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                                <div class="awi-field">
                                    <label>Conflict Strategy</label>
                                    <select id="awi-conflict-strategy">
                                        <option value="update">Update (Overwrite existing)</option>
                                        <option value="skip">Skip (Do nothing if exists)</option>
                                        <option value="merge">Merge (Fill missing only)</option>
                                    </select>
                                </div>
                                <div class="awi-field">
                                    <label>Default Category <span class="awi-hint-inline">(fallback if API has none)</span></label>
                                    <input type="text" id="awi-wc-category" placeholder="e.g. Uncategorized" />
                                </div>
                                <div class="awi-field">
                                    <label>Tag Prefix <span class="awi-hint-inline">(prepended to all tags)</span></label>
                                    <input type="text" id="awi-tag-prefix" placeholder="e.g. supplier-a-" />
                                </div>
                                <div class="awi-field">
                                    <label>Pagination Style</label>
                                    <select id="awi-pagination-style">
                                        <option value="auto">Auto-detect</option>
                                        <option value="header">Headers (X-Total-Count / Link)</option>
                                        <option value="body">Body (total / count / pages)</option>
                                        <option value="empty-page">Iterate until empty page</option>
                                    </select>
                                </div>
                                <div class="awi-field">
                                    <label>Pagination Param Name</label>
                                    <input type="text" id="awi-pagination-param" placeholder="page" />
                                </div>
                                <div class="awi-field">
                                    <label>Per-Page Param Name</label>
                                    <input type="text" id="awi-perpage-param" placeholder="per_page" />
                                </div>
                                <div class="awi-field">
                                    <label>Batch Size</label>
                                    <input type="number" id="awi-perpage-size" placeholder="100" />
                                </div>
                                <div class="awi-field awi-toggle-field">
                                    <label>Import Product Images</label>
                                    <label class="awi-toggle">
                                        <input type="checkbox" id="awi-import-images">
                                        <span class="awi-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="awi-field awi-toggle-field">
                                    <label>Update Existing Products</label>
                                    <label class="awi-toggle">
                                        <input type="checkbox" id="awi-update-existing">
                                        <span class="awi-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="awi-actions">
                                <button class="awi-btn ghost" id="awi-btn-save-options">💾 Save Options</button>
                                <button class="awi-btn danger-ghost" id="awi-btn-delete-imported">🗑 Delete All Imported Products</button>
                            </div>
                            <div id="awi-options-notice" class="awi-notice" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- ══ TAB: FIELD MAPPING ══ -->
                    <div class="awi-panel" id="tab-mapping">
                        <div class="awi-card">
                            <h2 class="awi-card-title">Field Mapping</h2>
                            <p class="awi-card-desc">Map API response fields to WooCommerce product fields. Each connection has its own independent mapping.</p>
                            <div class="awi-actions">
                                <button class="awi-btn primary" id="awi-btn-automap">✨ Auto-Detect Fields</button>
                                <button class="awi-btn success" id="awi-btn-save-map">💾 Save Mapping</button>
                            </div>
                            <div id="awi-map-notice" class="awi-notice" style="display:none;"></div>
                            <div id="awi-mapping-table-wrap">
                                <div class="awi-map-loading"><span>Run Auto-Detect or configure your API connection first.</span></div>
                            </div>
                        </div>
                        <div class="awi-card" id="awi-sample-card" style="display:none;">
                            <h2 class="awi-card-title">API Sample Response</h2>
                            <p class="awi-card-desc">First item returned by the API — used to build the field map.</p>
                            <pre id="awi-sample-json" class="awi-code-block"></pre>
                        </div>
                    </div>

                    <!-- ══ TAB: PRODUCTS ══ -->
                    <div class="awi-panel" id="tab-products">
                        <div class="awi-card">
                            <div class="awi-products-toolbar">
                                <div class="awi-products-toolbar-left">
                                    <h2 class="awi-card-title" style="margin:0;">Product Preview</h2>
                                    <span class="awi-badge" id="awi-product-count">—</span>
                                </div>
                                <div class="awi-products-toolbar-right">
                                    <input type="text" id="awi-product-search" placeholder="🔍 Search…" class="awi-search-input" />
                                    <button class="awi-btn ghost" id="awi-btn-refresh-preview">🔄 Refresh</button>
                                    <button class="awi-btn ghost" id="awi-btn-select-all">☑ All</button>
                                    <button class="awi-btn ghost" id="awi-btn-select-none">☐ Clear</button>
                                    <button class="awi-btn primary" id="awi-btn-import-selected" disabled>
                                        ⬇ Import (<span id="awi-selected-count">0</span>)
                                    </button>
                                    <button class="awi-btn success" id="awi-btn-import-all">⬇ All</button>
                                </div>
                            </div>
                            <div id="awi-import-progress" class="awi-progress-bar-wrap" style="display:none;">
                                <div class="awi-progress-bar"><div class="awi-progress-fill"></div></div>
                                <span class="awi-progress-label">Importing…</span>
                            </div>
                            <div id="awi-import-result" class="awi-notice" style="display:none;"></div>
                            <div id="awi-products-grid">
                                <div class="awi-products-empty">
                                    <div class="awi-empty-icon">📦</div>
                                    <div>Click <strong>Refresh</strong> to fetch products from the API</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ TAB: SCHEDULE ══ -->
                    <div class="awi-panel" id="tab-schedule">
                        <div class="awi-card">
                            <h2 class="awi-card-title">Auto Sync Schedule</h2>
                            <p class="awi-card-desc">Automatically import new and updated products on a schedule using WP-Cron. Each connection runs independently.</p>
                            <div class="awi-form-grid">
                                <div class="awi-field awi-toggle-field">
                                    <label>Enable Auto Sync</label>
                                    <label class="awi-toggle">
                                        <input type="checkbox" id="awi-sync-enabled">
                                        <span class="awi-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="awi-field">
                                    <label>Sync Interval</label>
                                    <select id="awi-sync-interval">
                                        <option value="hourly">Every Hour</option>
                                        <option value="twicedaily">Twice Daily</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                    </select>
                                </div>
                            </div>
                            <div class="awi-schedule-info" id="awi-schedule-info">
                                <div class="awi-info-row">
                                    <span class="info-label">Next run:</span>
                                    <span class="info-val" id="awi-next-run">—</span>
                                </div>
                                <div class="awi-info-row">
                                    <span class="info-label">Last sync:</span>
                                    <span class="info-val" id="awi-last-sync">—</span>
                                </div>
                                <div class="awi-info-row">
                                    <span class="info-label">Last sync count:</span>
                                    <span class="info-val" id="awi-last-sync-count">—</span>
                                </div>
                            </div>
                            <div class="awi-actions">
                                <button class="awi-btn primary" id="awi-btn-save-schedule">💾 Save Schedule</button>
                                <button class="awi-btn ghost" id="awi-btn-run-now">▶ Run Import Now</button>
                            </div>
                            <div id="awi-schedule-notice" class="awi-notice" style="display:none;"></div>
                        </div>
                    </div>

                    <!-- ══ TAB: LOGS ══ -->
                    <div class="awi-panel" id="tab-logs">
                        <div class="awi-card">
                            <div class="awi-logs-toolbar" style="display:flex;justify-content:space-between;">
                                <h2 class="awi-card-title" style="margin:0;">Activity Log</h2>
                                <button class="awi-btn ghost danger" id="awi-btn-clear-logs">🗑 Clear</button>
                            </div>
                            <div id="awi-log-list">
                                <div class="awi-log-empty">Select a connection to see its logs.</div>
                            </div>
                        </div>
                    </div>

                    <!-- ══ TAB: HISTORY ══ -->
                    <div class="awi-panel" id="tab-history">
                        <div class="awi-card">
                            <div class="awi-history-toolbar" style="display:flex;justify-content:space-between;margin-bottom:20px;">
                                <h2 class="awi-card-title" style="margin:0;">Import History (Last 20 runs)</h2>
                                <button class="awi-btn ghost" id="awi-btn-refresh-history">🔄 Refresh</button>
                            </div>
                            <div id="awi-history-list">
                                <div class="awi-log-empty">Loading history...</div>
                            </div>
                        </div>
                    </div>

                </div><!-- #awi-conn-editor -->
            </div><!-- .awi-editor -->
        </div><!-- .awi-layout -->

        </div><!-- #awi-app -->
        <?php
    }
}

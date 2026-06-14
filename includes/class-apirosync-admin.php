<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APIROSYNC_Admin {

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
            'apirosync-product-sync',
            [ $this, 'render_page' ],
            'dashicons-cloud-upload',
            56
        );
    }

    public function enqueue( $hook ) {
        if ( strpos( $hook, 'apirosync-product-sync' ) === false ) return;
        wp_enqueue_style(  'apirosync-admin', APIROSYNC_URL . 'assets/admin.css', [],             APIROSYNC_VERSION );
        wp_enqueue_script( 'apirosync-admin', APIROSYNC_URL . 'assets/admin.js',  ['jquery'], APIROSYNC_VERSION, true );
        wp_localize_script( 'apirosync-admin', 'APIROSYNC', [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'apirosync_nonce' ),
            'wc_fields'   => APIROSYNC_Field_Mapper::WC_FIELDS,
            'wc_active'   => class_exists( 'WooCommerce' ),
            'version'     => APIROSYNC_VERSION,
        ]);
    }

    public function render_page() {
        ?>
        <div id="apirosync-app">

        <!--  HEADER  -->
        <div class="apirosync-header">
            <div class="apirosync-header-inner">
                <div class="apirosync-logo">
                    <span class="apirosync-logo-icon">API</span>
                    <div>
                        <div class="apirosync-logo-title">ApiroSync Product Sync <span class="apirosync-version-badge">v<?php echo esc_html(APIROSYNC_VERSION); ?></span></div>
                        <div class="apirosync-logo-sub">Multiple REST APIs to WooCommerce, fully isolated per connection</div>
                    </div>
                </div>
                <div class="apirosync-header-meta">
                    <div class="apirosync-status-pill ok"> Built-in Fetcher</div>
                    <div class="apirosync-status-pill <?php echo class_exists('WooCommerce') ? 'ok' : 'err'; ?>">
                        <?php echo class_exists('WooCommerce') ? ' WooCommerce Active' : ' WooCommerce Missing'; ?>
                    </div>
                    <div class="apirosync-status-pill info" id="apirosync-conn-count-pill"> connections</div>
                </div>
            </div>
        </div>

        <!--  MAIN LAYOUT  -->
        <div class="apirosync-layout">

            <!-- LEFT: Connection Sidebar -->
            <div class="apirosync-sidebar" id="apirosync-sidebar">
                <div class="apirosync-sidebar-header">
                    <span class="apirosync-sidebar-title">API Connections</span>
                    <button class="apirosync-btn-icon-primary" id="apirosync-btn-add-conn" title="Add new connection">+</button>
                </div>
                <div id="apirosync-conn-list">
                    <div class="apirosync-sidebar-loading">Loading</div>
                </div>
            </div>

            <!-- RIGHT: Editor Area -->
            <div class="apirosync-editor" id="apirosync-editor">
                <div class="apirosync-editor-empty" id="apirosync-editor-empty">
                    <div class="apirosync-empty-icon">API</div>
                    <div class="apirosync-empty-title">No connection selected</div>
                    <div class="apirosync-empty-sub">Select a connection from the sidebar or create a new one to get started.</div>
                    <button class="apirosync-btn primary" id="apirosync-btn-add-conn-center"> Add First API Connection</button>
                </div>

                <!--  PER-CONNECTION EDITOR (hidden until a conn is selected)  -->
                <div id="apirosync-conn-editor" style="display:none;">

                    <!-- Editor header -->
                    <div class="apirosync-editor-header">
                        <div class="apirosync-editor-header-left">
                            <input type="text" id="apirosync-conn-label" class="apirosync-conn-label-input" placeholder="Connection name" />
                        </div>
                        <div class="apirosync-editor-header-right">
                            <button class="apirosync-btn danger-ghost" id="apirosync-btn-delete-conn"> Delete</button>
                            <button class="apirosync-btn ghost" id="apirosync-btn-duplicate-conn"> Duplicate</button>
                        </div>
                    </div>

                    <!-- Editor tabs -->
                    <div class="apirosync-tabs">
                        <button class="apirosync-tab active" data-tab="connection"> Connection</button>
                        <button class="apirosync-tab" data-tab="mapping"> Field Mapping</button>
                        <button class="apirosync-tab" data-tab="products"> Products</button>
                        <button class="apirosync-tab" data-tab="schedule"> Auto Sync</button>
                        <button class="apirosync-tab" data-tab="logs"> Logs</button>
                        <button class="apirosync-tab" data-tab="history"> History</button>
                    </div>

                    <!--  TAB: CONNECTION  -->
                    <div class="apirosync-panel active" id="tab-connection">
                        <div class="apirosync-card">
                            <h2 class="apirosync-card-title">API Connection</h2>
                            <p class="apirosync-card-desc">Configure the REST API endpoint for this connection. Each connection is fully isolated  products from different connections never merge.</p>

                            <div class="apirosync-form-grid" style="grid-template-columns:1fr;">
                                <div class="apirosync-field">
                                    <label>API Endpoint URL</label>
                                    <input type="text" id="apirosync-api-url" placeholder="https://api.example.com/v1/products" style="font-family:monospace;" />
                                    <span class="apirosync-hint">Full REST endpoint that returns your product list.</span>
                                </div>
                            </div>

                            <div class="apirosync-form-grid">
                                <div class="apirosync-field">
                                    <label>HTTP Method</label>
                                    <select id="apirosync-api-method">
                                        <option value="GET">GET</option>
                                        <option value="POST">POST</option>
                                    </select>
                                </div>
                                <div class="apirosync-field">
                                    <label>Bearer Token (optional)</label>
                                    <input type="text" id="apirosync-api-bearer" placeholder="eyJhbGciOiJIUzI1NiIs" />
                                    <span class="apirosync-hint">Sent as <code>Authorization: Bearer </code></span>
                                </div>
                            </div>

                            <details class="apirosync-advanced-toggle">
                                <summary> Advanced Auth &amp; Options</summary>
                                <div class="apirosync-form-grid" style="margin-top:16px;">
                                    <div class="apirosync-field">
                                        <label>Basic Auth  Username</label>
                                        <input type="text" id="apirosync-api-basic-user" placeholder="username" />
                                    </div>
                                    <div class="apirosync-field">
                                        <label>Basic Auth  Password</label>
                                        <input type="password" id="apirosync-api-basic-pass" placeholder="password" />
                                    </div>
                                    <div class="apirosync-field">
                                        <label>API Key Header Name</label>
                                        <input type="text" id="apirosync-api-key-header" placeholder="X-API-Key" />
                                    </div>
                                    <div class="apirosync-field">
                                        <label>API Key / Query Param Name</label>
                                        <input type="text" id="apirosync-api-key-param" placeholder="api_key" />
                                    </div>
                                    <div class="apirosync-field">
                                        <label>API Key Value</label>
                                        <input type="text" id="apirosync-api-key-value" placeholder="your-secret-key" />
                                    </div>
                                    <div class="apirosync-field">
                                        <label>Extra Query Params</label>
                                        <textarea id="apirosync-api-extra-params" rows="3" placeholder="limit=100&#10;locale=en" style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px;font-family:monospace;font-size:12px;color:var(--text);resize:vertical;"></textarea>
                                        <span class="apirosync-hint">One <code>key=value</code> per line.</span>
                                    </div>
                                    <div class="apirosync-field">
                                        <label>POST Body (JSON)</label>
                                        <textarea id="apirosync-api-body" rows="3" placeholder='{"filter":"active"}' style="width:100%;background:var(--bg-input);border:1px solid var(--border);border-radius:var(--radius-sm);padding:8px 10px;font-family:monospace;font-size:12px;color:var(--text);resize:vertical;"></textarea>
                                        <span class="apirosync-hint">Only used when Method is POST.</span>
                                    </div>
                                    <div class="apirosync-field">
                                        <label>Webhook Secret</label>
                                        <input type="text" id="apirosync-webhook-secret" placeholder="Optional secret token" />
                                    </div>
                                    <div class="apirosync-field">
                                        <label>Webhook Endpoint (Read-only)</label>
                                        <input type="text" id="apirosync-webhook-url" readonly style="font-family:monospace;background:#f3f4f6;" />
                                        <span class="apirosync-hint">Send a POST JSON payload. Append <code>?secret=...</code> or send <code>x-apirosync-secret</code> header if using secret.</span>
                                    </div>
                                </div>
                            </details>

                            <div class="apirosync-actions" style="margin-top:20px;">
                                <button class="apirosync-btn primary" id="apirosync-btn-analyze">
                                    <span class="btn-icon"></span> Test &amp; Analyze API
                                </button>
                                <button class="apirosync-btn ghost" id="apirosync-btn-save-connection">
                                    <span class="btn-icon"></span> Save Connection
                                </button>
                            </div>
                            <div id="apirosync-analysis-result" class="apirosync-notice" style="display:none;"></div>
                        </div>

                        <!-- Import Options -->
                        <div class="apirosync-card">
                            <h2 class="apirosync-card-title">Import Options</h2>
                            <div class="apirosync-form-grid">
                                <div class="apirosync-field">
                                    <label>Publish Status</label>
                                    <select id="apirosync-publish-status">
                                        <option value="publish">Published</option>
                                        <option value="draft">Draft</option>
                                        <option value="pending">Pending</option>
                                    </select>
                                </div>
                                <div class="apirosync-field">
                                    <label>Conflict Strategy</label>
                                    <select id="apirosync-conflict-strategy">
                                        <option value="update">Update (Overwrite existing)</option>
                                        <option value="skip">Skip (Do nothing if exists)</option>
                                        <option value="merge">Merge (Fill missing only)</option>
                                    </select>
                                </div>
                                <div class="apirosync-field">
                                    <label>Default Category <span class="apirosync-hint-inline">(fallback if API has none)</span></label>
                                    <input type="text" id="apirosync-wc-category" placeholder="e.g. Uncategorized" />
                                </div>
                                <div class="apirosync-field">
                                    <label>Tag Prefix <span class="apirosync-hint-inline">(prepended to all tags)</span></label>
                                    <input type="text" id="apirosync-tag-prefix" placeholder="e.g. supplier-a-" />
                                </div>
                                <div class="apirosync-field">
                                    <label>Pagination Style</label>
                                    <select id="apirosync-pagination-style">
                                        <option value="auto">Auto-detect</option>
                                        <option value="header">Headers (X-Total-Count / Link)</option>
                                        <option value="body">Body (total / count / pages)</option>
                                        <option value="empty-page">Iterate until empty page</option>
                                    </select>
                                </div>
                                <div class="apirosync-field">
                                    <label>Pagination Param Name</label>
                                    <input type="text" id="apirosync-pagination-param" placeholder="page" />
                                </div>
                                <div class="apirosync-field">
                                    <label>Per-Page Param Name</label>
                                    <input type="text" id="apirosync-perpage-param" placeholder="per_page" />
                                </div>
                                <div class="apirosync-field">
                                    <label>Batch Size</label>
                                    <input type="number" id="apirosync-perpage-size" placeholder="100" />
                                </div>
                                <div class="apirosync-field apirosync-toggle-field">
                                    <label>Import Product Images</label>
                                    <label class="apirosync-toggle">
                                        <input type="checkbox" id="apirosync-import-images">
                                        <span class="apirosync-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="apirosync-field apirosync-toggle-field">
                                    <label>Update Existing Products</label>
                                    <label class="apirosync-toggle">
                                        <input type="checkbox" id="apirosync-update-existing">
                                        <span class="apirosync-toggle-slider"></span>
                                    </label>
                                </div>
                            </div>
                            <div class="apirosync-actions">
                                <button class="apirosync-btn ghost" id="apirosync-btn-save-options"> Save Options</button>
                                <button class="apirosync-btn danger-ghost" id="apirosync-btn-delete-imported"> Delete All Imported Products</button>
                            </div>
                            <div id="apirosync-options-notice" class="apirosync-notice" style="display:none;"></div>
                        </div>
                    </div>

                    <!--  TAB: FIELD MAPPING  -->
                    <div class="apirosync-panel" id="tab-mapping">
                        <div class="apirosync-card">
                            <h2 class="apirosync-card-title">Field Mapping</h2>
                            <p class="apirosync-card-desc">Map API response fields to WooCommerce product fields. Each connection has its own independent mapping.</p>
                            <div class="apirosync-actions">
                                <button class="apirosync-btn primary" id="apirosync-btn-automap"> Auto-Detect Fields</button>
                                <button class="apirosync-btn success" id="apirosync-btn-save-map"> Save Mapping</button>
                            </div>
                            <div id="apirosync-map-notice" class="apirosync-notice" style="display:none;"></div>
                            <div id="apirosync-mapping-table-wrap">
                                <div class="apirosync-map-loading"><span>Run Auto-Detect or configure your API connection first.</span></div>
                            </div>
                        </div>
                        <div class="apirosync-card" id="apirosync-sample-card" style="display:none;">
                            <h2 class="apirosync-card-title">API Sample Response</h2>
                            <p class="apirosync-card-desc">First item returned by the API  used to build the field map.</p>
                            <pre id="apirosync-sample-json" class="apirosync-code-block"></pre>
                        </div>
                    </div>

                    <!--  TAB: PRODUCTS  -->
                    <div class="apirosync-panel" id="tab-products">
                        <div class="apirosync-card">
                            <div class="apirosync-products-toolbar">
                                <div class="apirosync-products-toolbar-left">
                                    <h2 class="apirosync-card-title" style="margin:0;">Product Preview</h2>
                                    <span class="apirosync-badge" id="apirosync-product-count"></span>
                                </div>
                                <div class="apirosync-products-toolbar-right">
                                    <input type="text" id="apirosync-product-search" placeholder=" Search" class="apirosync-search-input" />
                                    <button class="apirosync-btn ghost" id="apirosync-btn-refresh-preview"> Refresh</button>
                                    <button class="apirosync-btn ghost" id="apirosync-btn-select-all"> All</button>
                                    <button class="apirosync-btn ghost" id="apirosync-btn-select-none"> Clear</button>
                                    <button class="apirosync-btn primary" id="apirosync-btn-import-selected" disabled>
                                         Import (<span id="apirosync-selected-count">0</span>)
                                    </button>
                                    <button class="apirosync-btn success" id="apirosync-btn-import-all"> All</button>
                                </div>
                            </div>
                            <div id="apirosync-import-progress" class="apirosync-progress-bar-wrap" style="display:none;">
                                <div class="apirosync-progress-bar"><div class="apirosync-progress-fill"></div></div>
                                <span class="apirosync-progress-label">Importing</span>
                            </div>
                            <div id="apirosync-import-result" class="apirosync-notice" style="display:none;"></div>
                            <div id="apirosync-products-grid">
                                <div class="apirosync-products-empty">
                                    <div class="apirosync-empty-icon">API</div>
                                    <div>Click <strong>Refresh</strong> to fetch products from the API</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!--  TAB: SCHEDULE  -->
                    <div class="apirosync-panel" id="tab-schedule">
                        <div class="apirosync-card">
                            <h2 class="apirosync-card-title">Auto Sync Schedule</h2>
                            <p class="apirosync-card-desc">Automatically import new and updated products on a schedule using WP-Cron. Each connection runs independently.</p>
                            <div class="apirosync-form-grid">
                                <div class="apirosync-field apirosync-toggle-field">
                                    <label>Enable Auto Sync</label>
                                    <label class="apirosync-toggle">
                                        <input type="checkbox" id="apirosync-sync-enabled">
                                        <span class="apirosync-toggle-slider"></span>
                                    </label>
                                </div>
                                <div class="apirosync-field">
                                    <label>Sync Interval</label>
                                    <select id="apirosync-sync-interval">
                                        <option value="hourly">Every Hour</option>
                                        <option value="twicedaily">Twice Daily</option>
                                        <option value="daily">Daily</option>
                                        <option value="weekly">Weekly</option>
                                    </select>
                                </div>
                            </div>
                            <div class="apirosync-schedule-info" id="apirosync-schedule-info">
                                <div class="apirosync-info-row">
                                    <span class="info-label">Next run:</span>
                                    <span class="info-val" id="apirosync-next-run"></span>
                                </div>
                                <div class="apirosync-info-row">
                                    <span class="info-label">Last sync:</span>
                                    <span class="info-val" id="apirosync-last-sync"></span>
                                </div>
                                <div class="apirosync-info-row">
                                    <span class="info-label">Last sync count:</span>
                                    <span class="info-val" id="apirosync-last-sync-count"></span>
                                </div>
                            </div>
                            <div class="apirosync-actions">
                                <button class="apirosync-btn primary" id="apirosync-btn-save-schedule"> Save Schedule</button>
                                <button class="apirosync-btn ghost" id="apirosync-btn-run-now"> Run Import Now</button>
                            </div>
                            <div id="apirosync-schedule-notice" class="apirosync-notice" style="display:none;"></div>
                        </div>
                    </div>

                    <!--  TAB: LOGS  -->
                    <div class="apirosync-panel" id="tab-logs">
                        <div class="apirosync-card">
                            <div class="apirosync-logs-toolbar" style="display:flex;justify-content:space-between;">
                                <h2 class="apirosync-card-title" style="margin:0;">Activity Log</h2>
                                <button class="apirosync-btn ghost danger" id="apirosync-btn-clear-logs"> Clear</button>
                            </div>
                            <div id="apirosync-log-list">
                                <div class="apirosync-log-empty">Select a connection to see its logs.</div>
                            </div>
                        </div>
                    </div>

                    <!--  TAB: HISTORY  -->
                    <div class="apirosync-panel" id="tab-history">
                        <div class="apirosync-card">
                            <div class="apirosync-history-toolbar" style="display:flex;justify-content:space-between;margin-bottom:20px;">
                                <h2 class="apirosync-card-title" style="margin:0;">Import History (Last 20 runs)</h2>
                                <button class="apirosync-btn ghost" id="apirosync-btn-refresh-history"> Refresh</button>
                            </div>
                            <div id="apirosync-history-list">
                                <div class="apirosync-log-empty">Loading history...</div>
                            </div>
                        </div>
                    </div>

                </div><!-- #apirosync-conn-editor -->
            </div><!-- .apirosync-editor -->
        </div><!-- .apirosync-layout -->

        </div><!-- #apirosync-app -->
        <?php
    }
}

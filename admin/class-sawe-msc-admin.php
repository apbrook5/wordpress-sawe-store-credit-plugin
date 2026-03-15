<?php
/**
 * Admin Interface for SAWE Membership Store Credits
 *
 * This class owns everything visible to site administrators in the WordPress
 * back-end. It is only instantiated when is_admin() is true (see the main
 * plugin bootstrap), so none of its code runs on front-end requests.
 *
 * ============================================================================
 * WHAT THIS CLASS CREATES
 * ============================================================================
 *
 * 1. TOP-LEVEL ADMIN MENU  "Store Credits"  (dashicons-tickets-alt, position 58)
 *    └── Sub-menu: "Settings"  (renders the plugin's global options)
 *    └── Sub-menu: "Store Credits" (the CPT list table — auto-registered by WP
 *        because the CPT uses show_in_menu => 'sawe-msc-settings')
 *
 * 2. SETTINGS PAGE  (WP Settings API)
 *    Currently contains one option:
 *      sawe_msc_remove_tables_on_uninstall  (bool, default false)
 *    Add new global options here if needed.
 *
 * 3. META BOX  "Store Credit Settings"  on the sawe_store_credit edit screen
 *    Fields: Admin Notes, Credit Amount, Renewal Date, Eligible Roles,
 *            Qualifying Product Categories, Qualifying Individual Products.
 *    The Roles / Categories / Products fields use a JavaScript-driven tag-chip
 *    UI (see admin/js/sawe-msc-admin.js) so admins can build lists without
 *    holding Ctrl/Cmd to multi-select.
 *
 * 4. CUSTOM LIST TABLE COLUMNS  on the sawe_store_credit list screen
 *    Amount | Renewal Date | Eligible Roles
 *    These give admins a quick overview without opening each credit.
 *
 * ============================================================================
 * ADDING A NEW GLOBAL SETTING
 * ============================================================================
 *
 * 1. Add register_setting() + add_settings_field() calls in register_settings().
 * 2. Add a render_{field_name}_field() method.
 * 3. Read the option wherever needed with get_option( 'sawe_msc_{option_name}' ).
 * 4. Document the new option in docs/DEVELOPER-GUIDE.md §9 (Option Keys).
 *
 * ============================================================================
 * ADDING A NEW META FIELD TO THE CREDIT POST TYPE
 * ============================================================================
 *
 * 1. Register the meta key in SAWE_MSC_Credit_Post_Type::register_meta().
 * 2. Add the field HTML inside render_settings_metabox().
 * 3. Add sanitisation + update_post_meta() in save_meta_boxes().
 * 4. Update get_credit_meta() in SAWE_MSC_Credit_Post_Type to return the new key.
 * 5. Document the key in docs/DEVELOPER-GUIDE.md §4 (Post Meta Reference).
 *
 * @package SAWE_MSC
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class SAWE_MSC_Admin {

    /**
     * Singleton instance.
     *
     * @var SAWE_MSC_Admin|null
     */
    private static ?self $instance = null;

    // =========================================================================
    // Singleton
    // =========================================================================

    /**
     * Return the singleton instance, creating it on first call.
     *
     * @return self
     */
    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — registers all admin hooks.
     *
     * Intentionally lean: no logic here beyond hook registration.
     * All work happens in the individual callback methods.
     */
    private function __construct() {
        // Register the top-level and sub-page admin menus.
        add_action( 'admin_menu',            [ $this, 'register_menus' ] );

        // Register settings with the WP Settings API (handles options.php POST).
        add_action( 'admin_init',            [ $this, 'register_settings' ] );

        // Add the "Store Credit Settings" meta box to the CPT edit screen.
        add_action( 'add_meta_boxes',        [ $this, 'add_meta_boxes' ] );

        // Save meta box data when the post is saved (also on Quick Edit + autosave
        // — we guard against those in save_meta_boxes()).
        add_action( 'save_post',             [ $this, 'save_meta_boxes' ], 10, 2 );

        // Enqueue admin CSS + JS. Only loaded on sawe_store_credit screens.
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );

        // ── Custom list-table columns ──────────────────────────────────────
        // Filter: add our custom column headers to the CPT list table.
        add_filter( 'manage_sawe_store_credit_posts_columns',
                    [ $this, 'columns' ] );

        // Action: render the data inside each custom column row.
        add_action( 'manage_sawe_store_credit_posts_custom_column',
                    [ $this, 'column_content' ], 10, 2 );
    }

    // =========================================================================
    // Admin menus
    // =========================================================================

    /**
     * Register the top-level "Store Credits" admin menu and "Settings" sub-page.
     *
     * Menu hierarchy after this method runs:
     *   Store Credits  (top-level, slug: sawe-msc-settings)
     *   ├── Settings          (renders render_settings_page)
     *   └── Store Credits     (CPT list — auto-added by WP via show_in_menu)
     *
     * The CPT list sub-menu is NOT explicitly registered here. WordPress
     * automatically adds it because the CPT is registered with:
     *   'show_in_menu' => 'sawe-msc-settings'
     * …which tells WP to nest the CPT's admin pages under our menu slug.
     *
     * Capability: 'manage_woocommerce' — the same cap WC uses for its own menus,
     * ensuring shop managers can access this without needing full admin access.
     *
     * Icon: dashicons-tickets-alt (a ticket/coupon icon, semantically appropriate).
     * Position 58: between WooCommerce (~55) and Appearance (~60) in the sidebar.
     *
     * Hook: admin_menu
     *
     * @return void
     */
    public function register_menus(): void {
        add_menu_page(
            __( 'Store Credits', 'sawe-msc' ),   // Page <title>
            __( 'Store Credits', 'sawe-msc' ),   // Sidebar menu label
            'manage_woocommerce',                 // Required capability
            'sawe-msc-settings',                 // Menu slug (also used as page slug)
            [ $this, 'render_settings_page' ],   // Callback for this page's content
            'dashicons-tickets-alt',             // Sidebar icon
            58                                   // Menu position
        );

        // Add the Settings sub-page explicitly so it appears as the first child.
        // Without this, the first child would default to a duplicate of the top-
        // level item with no custom label.
        add_submenu_page(
            'sawe-msc-settings',                 // Parent menu slug
            __( 'Settings', 'sawe-msc' ),        // Page <title>
            __( 'Settings', 'sawe-msc' ),        // Sub-menu label
            'manage_woocommerce',
            'sawe-msc-settings',                 // Same slug = replaces parent item
            [ $this, 'render_settings_page' ]
        );
    }

    // =========================================================================
    // Settings page (WP Settings API)
    // =========================================================================

    /**
     * Register the plugin's global options using the WordPress Settings API.
     *
     * The Settings API handles:
     *   - Sanitising option values via 'sanitize_callback'.
     *   - Generating the correct nonce field in settings_fields().
     *   - Processing the POST request via options.php.
     *
     * Currently registered options:
     *   sawe_msc_remove_tables_on_uninstall  (boolean)
     *
     * To add a new option:
     *   1. Call register_setting() with the 'sawe_msc_settings' group name.
     *   2. Call add_settings_field() pointing to a new render callback.
     *   3. Create the render callback method.
     *
     * Hook: admin_init
     *
     * @return void
     */
    public function register_settings(): void {
        // ── Option: remove tables on uninstall ────────────────────────────────
        register_setting(
            'sawe_msc_settings',                   // Option group (used in settings_fields())
            'sawe_msc_remove_tables_on_uninstall', // Option name (used in get_option())
            [
                'type'              => 'boolean',
                'sanitize_callback' => 'rest_sanitize_boolean', // Converts 1/"1"/true → true
                'default'           => false,
            ]
        );

        // Section: Uninstall Options
        add_settings_section(
            'sawe_msc_uninstall_section',          // Section ID
            __( 'Uninstall Options', 'sawe-msc' ), // Section heading
            '__return_false',                       // No description paragraph
            'sawe_msc_settings'                    // Settings page slug
        );

        // Field: Remove tables checkbox
        add_settings_field(
            'sawe_msc_remove_tables_on_uninstall', // Field ID
            __( 'Remove database tables on uninstall', 'sawe-msc' ), // Label
            [ $this, 'render_remove_tables_field' ], // Render callback
            'sawe_msc_settings',                   // Settings page slug
            'sawe_msc_uninstall_section'           // Section ID
        );
    }

    /**
     * Render the "Remove database tables on uninstall" checkbox field.
     *
     * Uses WordPress's checked() helper to conditionally add the 'checked'
     * attribute. The description warns admins this action is irreversible.
     *
     * Called by: WP Settings API via do_settings_sections() in render_settings_page().
     *
     * @return void
     */
    public function render_remove_tables_field(): void {
        $val = get_option( 'sawe_msc_remove_tables_on_uninstall', false );
        printf(
            '<input type="checkbox" name="sawe_msc_remove_tables_on_uninstall" value="1" %s> <span class="description">%s</span>',
            checked( 1, (int) $val, false ),
            esc_html__( 'When the plugin is deleted, remove the sawe_msc_user_credits table from the database. All balance history will be permanently lost.', 'sawe-msc' )
        );
    }

    /**
     * Render the Settings page HTML.
     *
     * Uses the WP Settings API pattern:
     *   settings_fields()    → outputs hidden nonce + action fields
     *   do_settings_sections() → outputs all registered sections + fields
     *   submit_button()      → outputs the Save Changes button
     *
     * The page also includes a convenience link to the Store Credits list table
     * so admins can navigate between settings and credit definitions in one click.
     *
     * Capability check: redundant with the menu registration cap, but good practice
     * to guard the render callback directly in case it's called elsewhere.
     *
     * @return void
     */
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', 'sawe-msc' ) );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'SAWE Membership Store Credits — Settings', 'sawe-msc' ); ?></h1>
            <p>
                <?php
                printf(
                    /* translators: %s = URL to the CPT list screen */
                    wp_kses(
                        __( 'Manage individual store credit definitions on the <a href="%s">Store Credits list</a>.', 'sawe-msc' ),
                        [ 'a' => [ 'href' => [] ] ]
                    ),
                    esc_url( admin_url( 'edit.php?post_type=sawe_store_credit' ) )
                );
                ?>
            </p>
            <form method="post" action="options.php">
                <?php
                // Output the nonce, action, and option_page hidden fields.
                settings_fields( 'sawe_msc_settings' );
                // Output all sections and fields registered to sawe_msc_settings.
                do_settings_sections( 'sawe_msc_settings' );
                // Output the Submit button.
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // Meta boxes
    // =========================================================================

    /**
     * Register the "Store Credit Settings" meta box on the sawe_store_credit
     * edit screen.
     *
     * Placement: 'normal' context (below the post editor), 'high' priority
     * (appears first among normal-context boxes, directly below the editor).
     *
     * Hook: add_meta_boxes
     *
     * @return void
     */
    public function add_meta_boxes(): void {
        add_meta_box(
            'sawe_msc_credit_settings',          // Meta box ID (used in HTML id attribute)
            __( 'Store Credit Settings', 'sawe-msc' ), // Title displayed in box header
            [ $this, 'render_settings_metabox' ], // Render callback
            'sawe_store_credit',                 // Post type
            'normal',                            // Context: 'normal', 'side', 'advanced'
            'high'                               // Priority within context: 'high', 'default', 'low'
        );
    }

    /**
     * Render the "Store Credit Settings" meta box HTML.
     *
     * Fields rendered (in order):
     *   1. Admin Notes        — textarea, internal only
     *   2. Credit Amount ($)  — number input, dollar value
     *   3. Renewal Date       — month + day selects
     *   4. Eligible Roles     — tag-chip list manager
     *   5. Qualifying Product Categories — tag-chip list manager
     *   6. Qualifying Individual Products — tag-chip list manager
     *
     * Tag-chip list managers:
     *   Each list manager consists of:
     *     - A .sawe-msc-selected-list div containing existing chips (one per saved value).
     *     - A <select> dropdown populated with all available options.
     *     - An "Add" button (data-target identifies which manager to update).
     *
     *   The PHP renders chips for already-saved values. The JS (sawe-msc-admin.js)
     *   handles the add/remove interaction client-side. The form submits all chip
     *   values as hidden inputs (name="sawe_msc_roles[]" etc.) which save_meta_boxes()
     *   reads and sanitises.
     *
     * Nonce: wp_nonce_field() outputs a hidden input verified in save_meta_boxes().
     *
     * Product list performance note:
     *   wc_get_products( limit: -1 ) loads ALL published products. For stores with
     *   thousands of products, consider replacing this with a Select2 AJAX search.
     *   See docs/DEVELOPER-GUIDE.md §14 for extension guidance.
     *
     * @param \WP_Post $post  The post being edited.
     *
     * @return void
     */
    public function render_settings_metabox( \WP_Post $post ): void {
        // Output a nonce field. save_meta_boxes() verifies this.
        wp_nonce_field( 'sawe_msc_save_meta', 'sawe_msc_nonce' );

        // Load current saved values for this post.
        $meta = SAWE_MSC_Credit_Post_Type::get_credit_meta( $post->ID );

        // All registered WP roles (slug => display name).
        $roles = wp_roles()->get_names();

        // Month names for the renewal date selects.
        $months = [
            1  => 'January',  2  => 'February', 3  => 'March',
            4  => 'April',    5  => 'May',       6  => 'June',
            7  => 'July',     8  => 'August',    9  => 'September',
            10 => 'October',  11 => 'November',  12 => 'December',
        ];

        // All product categories (hide_empty: false so unused cats are still available).
        $all_cats = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
        ] );

        // All published products for the individual products picker.
        // Return 'objects' to call get_name() and get_id() cleanly.
        $all_products = wc_get_products( [
            'status'  => 'publish',
            'limit'   => -1,
            'orderby' => 'title',
            'order'   => 'ASC',
            'return'  => 'objects',
        ] );
        ?>
        <div class="sawe-msc-metabox">

            <!-- ── Admin Notes ─────────────────────────────────────────────── -->
            <!--  Saved to: _sawe_msc_admin_notes                              -->
            <!--  Shown to: admins only. Never rendered on the front-end.      -->
            <div class="sawe-msc-field">
                <label for="sawe_msc_admin_notes">
                    <strong><?php esc_html_e( 'Admin Notes', 'sawe-msc' ); ?></strong>
                </label>
                <p class="description"><?php esc_html_e( 'Internal notes — not shown to members.', 'sawe-msc' ); ?></p>
                <textarea
                    id="sawe_msc_admin_notes"
                    name="sawe_msc_admin_notes"
                    rows="3"
                    style="width:100%"
                ><?php echo esc_textarea( $meta['admin_notes'] ); ?></textarea>
            </div>

            <hr>

            <!-- ── Credit Amount ───────────────────────────────────────────── -->
            <!--  Saved to: _sawe_msc_initial_amount                           -->
            <!--  Dollar value awarded on first grant and on each renewal.     -->
            <div class="sawe-msc-field">
                <label for="sawe_msc_initial_amount">
                    <strong><?php esc_html_e( 'Credit Amount ($)', 'sawe-msc' ); ?></strong>
                </label>
                <p class="description"><?php esc_html_e( 'Dollar value awarded to each eligible member and reset to this amount on the annual renewal date.', 'sawe-msc' ); ?></p>
                <input
                    type="number"
                    id="sawe_msc_initial_amount"
                    name="sawe_msc_initial_amount"
                    value="<?php echo esc_attr( $meta['initial_amount'] ); ?>"
                    min="0"
                    step="0.01"
                    style="width:150px"
                >
            </div>

            <!-- ── Renewal Date ────────────────────────────────────────────── -->
            <!--  Saved to: _sawe_msc_expiry_month, _sawe_msc_expiry_day       -->
            <!--  The daily cron resets balances for this credit on this day.  -->
            <div class="sawe-msc-field">
                <label><strong><?php esc_html_e( 'Renewal Date', 'sawe-msc' ); ?></strong></label>
                <p class="description"><?php esc_html_e( 'Each year on this date, all eligible members\' balances are reset to the Credit Amount above.', 'sawe-msc' ); ?></p>

                <!-- Month select -->
                <select name="sawe_msc_expiry_month">
                    <?php foreach ( $months as $num => $name ) : ?>
                        <option value="<?php echo esc_attr( $num ); ?>" <?php selected( $meta['expiry_month'], $num ); ?>>
                            <?php echo esc_html( $name ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Day select (1–31; dbDelta cannot know which months have 31 days,
                     so validation of the specific month/day combo is left to the admin) -->
                <select name="sawe_msc_expiry_day">
                    <?php for ( $d = 1; $d <= 31; $d++ ) : ?>
                        <option value="<?php echo esc_attr( $d ); ?>" <?php selected( $meta['expiry_day'], $d ); ?>>
                            <?php echo esc_html( $d ); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </div>

            <hr>

            <!-- ── Eligible Member Roles ───────────────────────────────────── -->
            <!--  Saved to: _sawe_msc_roles (JSON array of slug strings)       -->
            <!--  Users holding ANY of these roles receive this credit.        -->
            <!--  JS target key: 'roles'  (see sawe-msc-admin.js managers map) -->
            <div class="sawe-msc-field">
                <label><strong><?php esc_html_e( 'Eligible Member Roles', 'sawe-msc' ); ?></strong></label>
                <p class="description"><?php esc_html_e( 'Users with any of these WordPress roles will be awarded this store credit.', 'sawe-msc' ); ?></p>

                <div class="sawe-msc-list-manager" id="roles-manager">
                    <!-- Existing chips (pre-populated from saved meta) -->
                    <div class="sawe-msc-selected-list" id="roles-list">
                        <?php foreach ( $meta['roles'] as $role_slug ) : ?>
                            <span class="sawe-msc-tag" data-value="<?php echo esc_attr( $role_slug ); ?>">
                                <?php echo esc_html( $roles[ $role_slug ] ?? $role_slug ); ?>
                                <button type="button" class="sawe-msc-remove-tag" aria-label="<?php esc_attr_e( 'Remove', 'sawe-msc' ); ?>">×</button>
                                <!-- Hidden input carries the value on form submit -->
                                <input type="hidden" name="sawe_msc_roles[]" value="<?php echo esc_attr( $role_slug ); ?>">
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <!-- Dropdown to pick a new role to add -->
                    <select id="roles-dropdown" class="sawe-msc-add-dropdown">
                        <option value=""><?php esc_html_e( '— Add a role —', 'sawe-msc' ); ?></option>
                        <?php foreach ( $roles as $slug => $name ) : ?>
                            <option value="<?php echo esc_attr( $slug ); ?>" data-label="<?php echo esc_attr( $name ); ?>">
                                <?php echo esc_html( $name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <!-- data-target='roles' tells sawe-msc-admin.js which manager to update -->
                    <button type="button" class="button sawe-msc-add-tag-btn" data-target="roles">
                        <?php esc_html_e( 'Add Role', 'sawe-msc' ); ?>
                    </button>
                </div>
            </div>

            <hr>

            <!-- ── Qualifying Product Categories ──────────────────────────── -->
            <!--  Saved to: _sawe_msc_product_categories (JSON array of ints)  -->
            <!--  Products in these WooCommerce categories qualify for credit. -->
            <!--  JS target key: 'cats'                                        -->
            <div class="sawe-msc-field">
                <label><strong><?php esc_html_e( 'Qualifying Product Categories', 'sawe-msc' ); ?></strong></label>
                <p class="description"><?php esc_html_e( 'Products in these categories will qualify for the store credit discount. A product qualifies if it matches ANY listed category OR is listed individually below.', 'sawe-msc' ); ?></p>

                <div class="sawe-msc-list-manager" id="cats-manager">
                    <div class="sawe-msc-selected-list" id="cats-list">
                        <?php foreach ( $meta['product_categories'] as $term_id ) :
                            $term = get_term( $term_id, 'product_cat' );
                            // Skip terms that have been deleted since saving.
                            if ( ! $term || is_wp_error( $term ) ) {
                                continue;
                            }
                        ?>
                            <span class="sawe-msc-tag" data-value="<?php echo esc_attr( $term_id ); ?>">
                                <?php echo esc_html( $term->name ); ?>
                                <button type="button" class="sawe-msc-remove-tag" aria-label="<?php esc_attr_e( 'Remove', 'sawe-msc' ); ?>">×</button>
                                <input type="hidden" name="sawe_msc_product_categories[]" value="<?php echo esc_attr( $term_id ); ?>">
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <select id="cats-dropdown" class="sawe-msc-add-dropdown">
                        <option value=""><?php esc_html_e( '— Add a category —', 'sawe-msc' ); ?></option>
                        <?php if ( ! is_wp_error( $all_cats ) ) :
                            foreach ( $all_cats as $term ) : ?>
                            <option value="<?php echo esc_attr( $term->term_id ); ?>" data-label="<?php echo esc_attr( $term->name ); ?>">
                                <?php echo esc_html( $term->name ); ?>
                            </option>
                        <?php endforeach; endif; ?>
                    </select>

                    <button type="button" class="button sawe-msc-add-tag-btn" data-target="cats">
                        <?php esc_html_e( 'Add Category', 'sawe-msc' ); ?>
                    </button>
                </div>
            </div>

            <hr>

            <!-- ── Qualifying Individual Products ─────────────────────────── -->
            <!--  Saved to: _sawe_msc_products (JSON array of product post IDs) -->
            <!--  These specific products qualify regardless of their category. -->
            <!--  JS target key: 'products'                                    -->
            <div class="sawe-msc-field">
                <label><strong><?php esc_html_e( 'Qualifying Individual Products', 'sawe-msc' ); ?></strong></label>
                <p class="description"><?php esc_html_e( 'Individual products that qualify for the store credit discount, in addition to any qualifying categories above.', 'sawe-msc' ); ?></p>

                <div class="sawe-msc-list-manager" id="products-manager">
                    <div class="sawe-msc-selected-list" id="products-list">
                        <?php foreach ( $meta['products'] as $prod_id ) :
                            $prod = wc_get_product( $prod_id );
                            // Skip products that have been deleted since saving.
                            if ( ! $prod ) {
                                continue;
                            }
                        ?>
                            <span class="sawe-msc-tag" data-value="<?php echo esc_attr( $prod_id ); ?>">
                                <?php echo esc_html( $prod->get_name() ); ?>
                                <button type="button" class="sawe-msc-remove-tag" aria-label="<?php esc_attr_e( 'Remove', 'sawe-msc' ); ?>">×</button>
                                <input type="hidden" name="sawe_msc_products[]" value="<?php echo esc_attr( $prod_id ); ?>">
                            </span>
                        <?php endforeach; ?>
                    </div>

                    <select id="products-dropdown" class="sawe-msc-add-dropdown">
                        <option value=""><?php esc_html_e( '— Add a product —', 'sawe-msc' ); ?></option>
                        <?php foreach ( $all_products as $prod ) : ?>
                            <option value="<?php echo esc_attr( $prod->get_id() ); ?>" data-label="<?php echo esc_attr( $prod->get_name() ); ?>">
                                <?php echo esc_html( $prod->get_name() ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <button type="button" class="button sawe-msc-add-tag-btn" data-target="products">
                        <?php esc_html_e( 'Add Product', 'sawe-msc' ); ?>
                    </button>
                </div>
            </div>

        </div><!-- .sawe-msc-metabox -->
        <?php
    }

    // =========================================================================
    // Save meta boxes
    // =========================================================================

    /**
     * Sanitise and persist meta box field values when a sawe_store_credit post is saved.
     *
     * Security guards (all checked before any data is written):
     *   1. Nonce missing               → bail (e.g. WP heartbeat, REST save).
     *   2. Nonce invalid               → bail (CSRF protection).
     *   3. DOING_AUTOSAVE              → bail (autosave doesn't include our fields).
     *   4. Wrong post type             → bail (save_post fires for ALL post types).
     *   5. Insufficient capability     → bail (user can't edit this post).
     *
     * Sanitisation strategy:
     *   - Strings   → sanitize_textarea_field() / sanitize_text_field() with wp_unslash()
     *   - Floats    → (float) cast + max(0, ...) to prevent negatives
     *   - Integers  → (int) cast + min/max range clamp
     *   - Arrays    → array_map( 'sanitize_text_field' ) or array_map( 'absint' )
     *                 + array_unique() to prevent duplicates before JSON encoding
     *
     * Hook: save_post (priority 10)
     *
     * @param int       $post_id  The post ID being saved.
     * @param \WP_Post  $post     The post object being saved.
     *
     * @return void
     */
    public function save_meta_boxes( int $post_id, \WP_Post $post ): void {
        // ── Guard: nonce ──────────────────────────────────────────────────────
        if ( ! isset( $_POST['sawe_msc_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_POST['sawe_msc_nonce'] ) ),
            'sawe_msc_save_meta'
        ) ) {
            return;
        }

        // ── Guard: autosave ───────────────────────────────────────────────────
        // WordPress autosave POSTs do not include our meta box fields.
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // ── Guard: post type ──────────────────────────────────────────────────
        // save_post fires for EVERY post type including WC orders, pages, etc.
        if ( $post->post_type !== 'sawe_store_credit' ) {
            return;
        }

        // ── Guard: capability ─────────────────────────────────────────────────
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // ── Save: Admin Notes ─────────────────────────────────────────────────
        // wp_unslash removes WordPress's magic quotes; sanitize_textarea_field
        // strips tags and normalises whitespace while preserving newlines.
        update_post_meta(
            $post_id,
            '_sawe_msc_admin_notes',
            sanitize_textarea_field( wp_unslash( $_POST['sawe_msc_admin_notes'] ?? '' ) )
        );

        // ── Save: Credit Amount ───────────────────────────────────────────────
        // max(0, ...) prevents negative dollar values.
        update_post_meta(
            $post_id,
            '_sawe_msc_initial_amount',
            max( 0, (float) ( $_POST['sawe_msc_initial_amount'] ?? 0 ) )
        );

        // ── Save: Renewal Month ───────────────────────────────────────────────
        // Clamped to 1–12 to match valid month numbers.
        update_post_meta(
            $post_id,
            '_sawe_msc_expiry_month',
            min( 12, max( 1, (int) ( $_POST['sawe_msc_expiry_month'] ?? 1 ) ) )
        );

        // ── Save: Renewal Day ─────────────────────────────────────────────────
        // Clamped to 1–31. The cron will simply not fire on Feb 30 etc.
        update_post_meta(
            $post_id,
            '_sawe_msc_expiry_day',
            min( 31, max( 1, (int) ( $_POST['sawe_msc_expiry_day'] ?? 1 ) ) )
        );

        // ── Save: Eligible Roles ──────────────────────────────────────────────
        // Each value is a WP role slug (alphanumeric + underscores).
        // sanitize_text_field strips tags and extra whitespace.
        // array_unique prevents the same role being saved twice.
        // array_values re-indexes the array so JSON encodes as [] not {}.
        $roles = array_map(
            'sanitize_text_field',
            (array) ( $_POST['sawe_msc_roles'] ?? [] )
        );
        update_post_meta(
            $post_id,
            '_sawe_msc_roles',
            wp_json_encode( array_values( array_unique( $roles ) ) )
        );

        // ── Save: Qualifying Product Categories ───────────────────────────────
        // absint ensures these are positive integers (term IDs).
        $cats = array_map( 'absint', (array) ( $_POST['sawe_msc_product_categories'] ?? [] ) );
        update_post_meta(
            $post_id,
            '_sawe_msc_product_categories',
            wp_json_encode( array_values( array_unique( $cats ) ) )
        );

        // ── Save: Qualifying Individual Products ──────────────────────────────
        // absint ensures these are positive integers (product post IDs).
        $products = array_map( 'absint', (array) ( $_POST['sawe_msc_products'] ?? [] ) );
        update_post_meta(
            $post_id,
            '_sawe_msc_products',
            wp_json_encode( array_values( array_unique( $products ) ) )
        );
    }

    // =========================================================================
    // CPT list table columns
    // =========================================================================

    /**
     * Add custom columns to the sawe_store_credit post list table.
     *
     * Inserts three columns immediately after the Title column:
     *   sawe_msc_amount   → Credit Amount
     *   sawe_msc_renewal  → Renewal Date
     *   sawe_msc_roles    → Eligible Roles
     *
     * The insertion-after-title approach preserves the order of built-in columns
     * (date, status) at the end of the header row.
     *
     * Filter: manage_sawe_store_credit_posts_columns
     *
     * @param array $columns  Existing column definitions: [ column_key => label ]
     *
     * @return array  Updated column definitions with our three columns added.
     */
    public function columns( array $columns ): array {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( $key === 'title' ) {
                // Insert after title.
                $new['sawe_msc_amount']  = __( 'Amount',       'sawe-msc' );
                $new['sawe_msc_renewal'] = __( 'Renewal Date', 'sawe-msc' );
                $new['sawe_msc_roles']   = __( 'Eligible Roles', 'sawe-msc' );
            }
        }
        return $new;
    }

    /**
     * Render the data for each custom column row in the list table.
     *
     * Column: sawe_msc_amount
     *   Outputs the initial_amount formatted as a WC price string.
     *
     * Column: sawe_msc_renewal
     *   Outputs the renewal month/day in abbreviated format (e.g. "May 1").
     *
     * Column: sawe_msc_roles
     *   Outputs a comma-separated list of role display names.
     *   If a role slug exists in the saved data but is no longer registered
     *   with WordPress, the slug itself is shown as a fallback.
     *
     * Action: manage_sawe_store_credit_posts_custom_column (priority 10)
     *
     * @param string $column   The column key being rendered.
     * @param int    $post_id  The current row's post ID.
     *
     * @return void
     */
    public function column_content( string $column, int $post_id ): void {
        $meta = SAWE_MSC_Credit_Post_Type::get_credit_meta( $post_id );

        switch ( $column ) {

            case 'sawe_msc_amount':
                // wc_price() formats with the store's currency symbol and decimal places.
                echo wc_price( $meta['initial_amount'] );
                break;

            case 'sawe_msc_renewal':
                // Abbreviated month names for compact display.
                $months = [
                    1  => 'Jan',  2  => 'Feb',  3  => 'Mar',
                    4  => 'Apr',  5  => 'May',  6  => 'Jun',
                    7  => 'Jul',  8  => 'Aug',  9  => 'Sep',
                    10 => 'Oct',  11 => 'Nov',  12 => 'Dec',
                ];
                echo esc_html(
                    ( $months[ $meta['expiry_month'] ] ?? '?' ) . ' ' . $meta['expiry_day']
                );
                break;

            case 'sawe_msc_roles':
                if ( empty( $meta['roles'] ) ) {
                    esc_html_e( '(none)', 'sawe-msc' );
                } else {
                    // Map slugs to display names; fall back to slug if role was deleted.
                    $all_roles = wp_roles()->get_names();
                    $labels    = array_map(
                        fn( string $slug ) => $all_roles[ $slug ] ?? $slug,
                        $meta['roles']
                    );
                    echo esc_html( implode( ', ', $labels ) );
                }
                break;
        }
    }

    // =========================================================================
    // Asset enqueueing
    // =========================================================================

    /**
     * Enqueue admin CSS and JavaScript for the sawe_store_credit edit screens.
     *
     * We scope these assets tightly using get_current_screen() so they only
     * load on the CPT list table and edit screens. This avoids polluting other
     * admin pages with our class names or JS global variables.
     *
     * Both assets use SAWE_MSC_VERSION as the cache-buster.
     *
     * sawe-msc-admin.css: tag-chip component styles for the list managers.
     * sawe-msc-admin.js:  add/remove chip behaviour; depends on jQuery only.
     *
     * Hook: admin_enqueue_scripts
     *
     * @param string $hook  The current admin page hook suffix (unused — we check
     *                      the screen object instead for more reliable targeting).
     *
     * @return void
     */
    public function enqueue_scripts( string $hook ): void {
        $screen = get_current_screen();

        // Bail if we're not on a sawe_store_credit screen (list or edit).
        if ( ! $screen || $screen->post_type !== 'sawe_store_credit' ) {
            return;
        }

        wp_enqueue_style(
            'sawe-msc-admin',                                    // Handle
            SAWE_MSC_PLUGIN_URL . 'admin/css/sawe-msc-admin.css', // URL
            [],                                                   // No dependencies
            SAWE_MSC_VERSION                                      // Cache buster
        );

        wp_enqueue_script(
            'sawe-msc-admin',                                    // Handle
            SAWE_MSC_PLUGIN_URL . 'admin/js/sawe-msc-admin.js', // URL
            [ 'jquery' ],                                        // Dependency
            SAWE_MSC_VERSION,                                    // Cache buster
            true                                                 // Load in footer
        );
    }
}

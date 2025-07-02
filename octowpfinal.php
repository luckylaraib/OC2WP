<?php
/**
 * Plugin Name: OpenCart to WooCommerce Variations & Attributes Sync
 * Description: Fetches products from OpenCart and imports them into WooCommerce as variable products, complete with attributes and variations.
 * Version:     1.9
 * Author:      Laraib Rabbani
 * Author URI:  https://laraibrabbani.net
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OC_Variations_Sync {
    private $oc_db;
    private $total;
    private $variation_chunk_size = 20;

    public function __construct() {
        if ( is_admin() ) {
            add_action( 'admin_menu',            [ $this, 'admin_menu' ] );
            add_action( 'admin_init',            [ $this, 'register_settings' ] );
            add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
            add_action( 'wp_ajax_oc_sync_variations', [ $this, 'ajax_process_one' ] );
        }
    }

    /**
     * Register DB credential settings
     */
    public function register_settings() {
        register_setting( 'oc2wp_settings_group', 'oc2wp_host' );
        register_setting( 'oc2wp_settings_group', 'oc2wp_dbname' );
        register_setting( 'oc2wp_settings_group', 'oc2wp_user' );
        register_setting( 'oc2wp_settings_group', 'oc2wp_password' );

        add_settings_section(
            'oc2wp_db_section',
            'OpenCart Database Settings',
            fn() => print '<p>Enter your OpenCart database credentials below:</p>',
            'oc2wp_settings_page'
        );

        $fields = [
            'oc2wp_host'     => 'DB Host',
            'oc2wp_dbname'   => 'DB Name',
            'oc2wp_user'     => 'DB User',
            'oc2wp_password' => 'DB Password',
        ];

        foreach ( $fields as $id => $label ) {
            add_settings_field(
                $id,
                $label,
                [ $this, $id === 'oc2wp_password' ? 'settings_password_callback' : 'settings_text_callback' ],
                'oc2wp_settings_page',
                'oc2wp_db_section',
                [ 'label_for' => $id ]
            );
        }
    }

    public function settings_text_callback( $args ) {
        $val = get_option( $args['label_for'], '' );
        printf(
            '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text">',
            esc_attr( $args['label_for'] ),
            esc_attr( $val )
        );
    }

    public function settings_password_callback( $args ) {
        $val = get_option( $args['label_for'], '' );
        printf(
            '<input type="password" id="%1$s" name="%1$s" value="%2$s" class="regular-text">',
            esc_attr( $args['label_for'] ),
            esc_attr( $val )
        );
    }

    /**
     * Add top‐level menu + Settings submenu
     */
    public function admin_menu() {
        add_menu_page(
            'OC2WP Sync',
            'OC2WP Sync',
            'manage_options',
            'oc-vars-sync',
            [ $this, 'sync_page' ],
            'dashicons-update'
        );
        add_submenu_page(
            'oc-vars-sync',
            'OC2WP Settings',
            'Settings',
            'manage_options',
            'oc2wp_settings_page',
            [ $this, 'settings_page' ]
        );
    }

    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>OC2WP Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'oc2wp_settings_group' );
                do_settings_sections( 'oc2wp_settings_page' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the Sync page (logo + controls + log)
     */
    public function sync_page() {
        // Try to connect; if creds missing, show warning and bail.
        if ( ! $this->maybe_connect() ) {
            return;
        }

        // Count total products with options
        $this->total = (int) $this->oc_db
            ->query( "SELECT COUNT(DISTINCT product_id) AS cnt FROM oc_product_option" )
            ->fetch_assoc()['cnt'];

        $ajaxurl  = admin_url( 'admin-ajax.php' );
        $logo_url = plugin_dir_url( __FILE__ ) . 'images/oc2wp.png';

        ?>
        <div class="wrap">
            <img src="<?php echo esc_url( $logo_url ); ?>" alt="OC2WP Logo" style="max-width:150px;margin-bottom:1em;">
            <h1>OC2WP Sync</h1>
            <p>Found <strong><?php echo esc_html( $this->total ); ?></strong> products with options.</p>

            <label for="start-product">Start at product #:</label>
            <input type="number" id="start-product" value="1"
                   min="1" max="<?php echo esc_attr( $this->total ); ?>"
                   style="width:60px;margin:0 12px;">

            <button id="start-sync" class="button button-primary">Start Sync</button>

            <pre id="sync-log"
                 style="background:#000;color:#0f0;height:300px;overflow:auto;margin-top:1em;"></pre>
        </div>
        <script>
        jQuery(function($){
            $('#start-sync').click(function(){
                $('#sync-log').text('');
                processOne(parseInt($('#start-product').val(),10)-1,0);
            });
            function processOne(offset,vo){
                log('Product '+(offset+1)+' — var offset '+vo);
                $.post('<?php echo $ajaxurl; ?>',{
                    action:'oc_sync_variations',
                    offset:offset,
                    variation_offset:vo
                })
                .done(res=>{
                    if(!res.success){
                        log('Error: '+res.data.message);
                        return;
                    }
                    log(res.data.message);
                    if(res.data.has_more_variations){
                        setTimeout(()=>processOne(res.data.offset,res.data.variation_offset),300);
                    } else if(res.data.has_more_products){
                        setTimeout(()=>processOne(res.data.offset,0),300);
                    } else {
                        log('✅ All done');
                    }
                })
                .fail((xhr,s,e)=>{
                    log('AJAX error (‘'+s+'’): '+e+' — retrying…');
                    setTimeout(()=>processOne(offset,vo),5000);
                });
            }
            function log(m){ $('#sync-log').append(m+"\n").scrollTop(1e9); }
        });
        </script>
        <?php
    }

    /**
     * Prevent WP from timing out or aborting during our AJAX
     */
    public function enqueue_scripts( $hook ) {
        if ( $hook === 'toplevel_page_oc-vars-sync' ) {
            wp_dequeue_script( 'heartbeat' );
            wp_dequeue_script( 'wp-auth-check' );
            wp_enqueue_script( 'jquery' );
            wp_add_inline_script( 'jquery', 'jQuery.noConflict(); jQuery.ajaxSetup({timeout:0});' );
        }
    }

    /**
     * AJAX handler: process one variation‐chunk or product
     */
    public function ajax_process_one() {
        header('Content-Type: application/json');

        if ( ! $this->maybe_connect() ) {
            wp_send_json_error([ 'message'=>'Missing DB credentials. Please configure settings.' ]);
            return;
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $off = max(0,intval($_POST['offset'] ?? 0));
            $vo  = max(0,intval($_POST['variation_offset'] ?? 0));

            // fetch one OC product_id
            $r = $this->oc_db->query("
                SELECT DISTINCT product_id
                  FROM oc_product_option
                 ORDER BY product_id
                 LIMIT 1 OFFSET {$off}
            ");
            $row = $r->fetch_assoc();
            if ( ! $row ) {
                wp_send_json_success([
                    'message'             => 'No more products',
                    'has_more_variations' => false,
                    'variation_offset'    => 0,
                    'offset'              => 0,
                    'has_more_products'   => false
                ]);
                return;
            }
            $oc_id = (int) $row['product_id'];

            $res = $this->process_product( $oc_id, $vo );

            $hasVar = $res['has_more_variations'];
            if ( ! $hasVar ) {
                $moreProd = ($off+1) < $this->total;
                $nextOff  = $off + 1;
                $nextVO   = 0;
            } else {
                $moreProd = true;
                $nextOff  = $off;
                $nextVO   = $res['next_variation_offset'];
            }

            wp_send_json_success([
                'message'             => $res['message'],
                'has_more_variations' => $hasVar,
                'variation_offset'    => $nextVO,
                'offset'              => $nextOff,
                'has_more_products'   => $moreProd,
            ]);

        } catch ( \Throwable $e ) {
            error_log( 'OC Sync Error: ' . $e->getMessage() );
            wp_send_json_error([ 'message' => $e->getMessage() ]);
        }
    }

    /**
     * Attempt DB connection; if any field is blank, queue an admin_notice.
     * Returns true on success, false on missing creds (or dies on real connect error).
     */
    private function maybe_connect() {
        $h = get_option('oc2wp_host','');
        $u = get_option('oc2wp_user','');
        $p = get_option('oc2wp_password','');
        $d = get_option('oc2wp_dbname','');
        if ( ! $h || ! $u || ! $d ) {
            add_action('admin_notices', function(){
                echo '<div class="notice notice-warning"><p>'
                   . 'OC2WP requires your OpenCart DB credentials. Go to '
                   . '<strong>OC2WP Sync → Settings</strong> to enter them.'
                   . '</p></div>';
            });
            return false;
        }
        $this->oc_db = @new mysqli( $h, $u, $p, $d );
        if ( $this->oc_db->connect_error ) {
            wp_die( 'OpenCart DB Error: ' . esc_html( $this->oc_db->connect_error ) );
        }
        return true;
    }

    /**
     * Core import logic: create/update product + chunk of variations
     */
    public function process_product( $oc_id, $variationOffset = 0 ) {
        global $wpdb;

        // 1) Locate or create WC product
        $wc_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
               WHERE meta_key = 'oc_product_id' AND meta_value = %d
               LIMIT 1",
            $oc_id
        ) );
        if ( ! $wc_id ) {
            $p = $this->oc_db->query(
                "SELECT model, price, image
                   FROM oc_product
                  WHERE product_id = {$oc_id}
                  LIMIT 1"
            )->fetch_assoc();
            if ( ! $p ) {
                return [
                    'message'               => "OC #{$oc_id} not found",
                    'has_more_variations'   => false,
                    'next_variation_offset' => 0
                ];
            }
            $wc_id = wp_insert_post([
                'post_type'   => 'product',
                'post_title'  => sanitize_text_field( $p['model'] ),
                'post_status' => 'publish',
            ]);
            update_post_meta( $wc_id, 'oc_product_id',   $oc_id );
            update_post_meta( $wc_id, '_price',          floatval( $p['price'] ) );
            update_post_meta( $wc_id, '_regular_price',  floatval( $p['price'] ) );
            if ( ! empty( $p['image'] ) ) {
                $img      = rtrim( 'https://guitarampsusa.com/image/', '/' ) . '/' . ltrim( $p['image'], '/' );
                $attachId = media_sideload_image( $img, $wc_id, null, 'id' );
                if ( is_numeric( $attachId ) ) {
                    set_post_thumbnail( $wc_id, $attachId );
                }
            }
        }

        // 2) Descriptions
        $desc = $this->oc_db->query(
            "SELECT description, meta_description
               FROM oc_product_description
              WHERE product_id = {$oc_id}
                AND language_id = 1"
        )->fetch_assoc();
        wp_update_post([
            'ID'           => $wc_id,
            'post_content' => wp_kses_post( $desc['description'] ),
            'post_excerpt' => wp_kses_post( $desc['meta_description'] ),
        ]);

        // 3) Categories
        $catRes = $this->oc_db->query("
            SELECT cd.name
              FROM oc_product_to_category pc
              JOIN oc_category_description cd
                ON pc.category_id = cd.category_id
             WHERE pc.product_id = {$oc_id}
               AND cd.language_id = 1
        ");
        $catNames = [];
        while ( $c = $catRes->fetch_assoc() ) {
            $term = sanitize_text_field( $c['name'] );
            if ( ! term_exists( $term, 'product_cat' ) ) {
                wp_insert_term( $term, 'product_cat' );
            }
            $catNames[] = $term;
        }
        if ( $catNames ) {
            wp_set_object_terms( $wc_id, $catNames, 'product_cat', false );
        }

        // 4) Brand → pa_brand
        $manId = intval(
            $this->oc_db
                 ->query("SELECT manufacturer_id FROM oc_product WHERE product_id = {$oc_id}")
                 ->fetch_object()
                 ->manufacturer_id
        );
        $brand = $this->oc_db
                      ->query("SELECT name FROM oc_manufacturer WHERE manufacturer_id = {$manId}")
                      ->fetch_object()
                      ->name;
        $this->register_global_attribute( 'brand', 'Brand' );
        wp_set_object_terms( $wc_id, sanitize_text_field( $brand ), 'pa_brand', true );

        // 5) Build option map
        $option_map = [];
        $optRes = $this->oc_db->query(
            "SELECT po.product_option_id, od.name AS option_name
               FROM oc_product_option po
               JOIN oc_option_description od
                 ON po.option_id = od.option_id
              WHERE po.product_id = {$oc_id}
                AND od.language_id = 1"
        );
        while ( $opt = $optRes->fetch_assoc() ) {
            $pid    = (int) $opt['product_option_id'];
            $valRes = $this->oc_db->query(
                "SELECT ovd.name, pov.price, pov.price_prefix
                   FROM oc_product_option_value pov
                   JOIN oc_option_value_description ovd
                     ON pov.option_value_id = ovd.option_value_id
                  WHERE pov.product_option_id = {$pid}
                    AND ovd.language_id = 1"
            );
            $vals   = [];
            $prices = [];
            while ( $v = $valRes->fetch_assoc() ) {
                $term           = $v['name'];
                $vals[]         = $term;
                $prices[ $term ] = ( $v['price_prefix'] === '-' ? -1 : 1 ) * floatval( $v['price'] );
            }
            $option_map[ $opt['option_name'] ] = [
                'values' => $vals,
                'prices' => $prices,
            ];
        }
        if ( empty( $option_map ) ) {
            return [
                'message'               => "No options for OC #{$oc_id}",
                'has_more_variations'   => false,
                'next_variation_offset' => 0
            ];
        }

        // 6) Register + assign attribute terms
        foreach ( $option_map as $optName => $data ) {
            $slug = sanitize_title( $optName );
            $tax  = "pa_{$slug}";
            $this->register_global_attribute( $slug, $optName );
            foreach ( $data['values'] as $term ) {
                if ( ! term_exists( $term, $tax ) ) {
                    wp_insert_term( $term, $tax );
                }
                wp_set_object_terms( $wc_id, $term, $tax, true );
            }
        }

        // 7) Initialize variable product on first chunk
        if ( $variationOffset === 0 ) {
            wp_set_object_terms( $wc_id, 'variable', 'product_type', false );
            $variable = new WC_Product_Variable( $wc_id );
            $attrs    = [];
            foreach ( $option_map as $optName => $data ) {
                $slug = sanitize_title( $optName );
                $attr = new WC_Product_Attribute();
                $attr->set_id( wc_attribute_taxonomy_id_by_name( $slug ) );
                $attr->set_name( "pa_{$slug}" );
                $attr->set_options( $data['values'] );
                $attr->set_visible( true );
                $attr->set_variation( true );
                $attrs[] = $attr;
            }
            $variable->set_attributes( $attrs );
            $variable->save();
            // remove old variations
            foreach ( $variable->get_children() as $child ) {
                wp_delete_post( $child, true );
            }
        } else {
            $variable = new WC_Product_Variable( $wc_id );
        }

        // 8) Generate combos, slice chunk, create variations
        $arrays      = array_map( fn($d) => $d['values'], $option_map );
        $combos      = $this->cartesian( $arrays );
        $totalCombos = count( $combos );
        $start       = $variationOffset;
        $chunk       = array_slice( $combos, $start, $this->variation_chunk_size );

        $base = floatval( $variable->get_regular_price() );
        foreach ( $chunk as $combo ) {
            $var      = new WC_Product_Variation();
            $var->set_parent_id( $wc_id );
            $price    = $base;
            $i        = 0;
            $attrData = [];
            foreach ( $option_map as $optName => $d ) {
                $slug             = sanitize_title( $optName );
                $selected         = $combo[ $i++ ];
                $attrData[ $slug ] = $selected;
                $price           += $d['prices'][ $selected ];
            }
            $var->set_attributes( $attrData );
            $var->set_regular_price( $price );
            $var->save();
        }

        unset( $combos );

        // 9) Return progress
        $nextOffset = $start + count( $chunk );
        $hasMore    = $nextOffset < $totalCombos;
        $message    = $hasMore
            ? "Variations {$start}–" . ( $nextOffset - 1 ) . " of {$totalCombos} done for OC #{$oc_id}"
            : "All {$totalCombos} variations done for OC #{$oc_id}";

        return [
            'message'               => $message,
            'has_more_variations'   => $hasMore,
            'next_variation_offset' => $hasMore ? $nextOffset : 0,
        ];
    }

    /**
     * Ensure global attribute taxonomy exists
     */
    private function register_global_attribute( $slug, $label ) {
        $tax = "pa_{$slug}";
        if ( ! taxonomy_exists( $tax ) ) {
            $args = [
                'name'         => $label,
                'slug'         => $slug,
                'type'         => 'select',
                'order_by'     => 'menu_order',
                'has_archives' => false,
            ];
            wc_create_attribute( $args );
            register_taxonomy(
                $tax,
                [ 'product', 'product_variation' ],
                [
                    'hierarchical' => true,
                    'show_ui'      => false,
                    'query_var'    => true,
                    'rewrite'      => false,
                ]
            );
        }
    }

    /**
     * Cartesian product helper
     */
    private function cartesian( $arrays ) {
        $result = [[]];
        foreach ( $arrays as $vals ) {
            $tmp = [];
            foreach ( $result as $res ) {
                foreach ( $vals as $v ) {
                    $tmp[] = array_merge( $res, [ $v ] );
                }
            }
            $result = $tmp;
        }
        return $result;
    }
}

// Initialize the plugin
new OC_Variations_Sync();

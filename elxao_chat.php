<?php
/*
Plugin Name: ELXAO Cloud Automation
Description: Auto-creates Projects on paid orders; provisions Nextcloud folders via WebDAV; creates/stores Ably chat room IDs; issues scoped Ably tokens; minimal chat UI shortcode; admin backfill for legacy projects.
Version: 1.22.1
Author: ELXAO
*/

if ( ! defined( 'ABSPATH' ) ) exit;

/* ===========================================================
   NEXTCLOUD CONFIG  (prefer setting NC PASS in wp-config.php)
   =========================================================== */
if ( ! defined('ELXAO_NC_BASE') ) {
    // WebDAV base MUST include your NC username and end with a slash
    define('ELXAO_NC_BASE', 'https://cloud.elxao.com/remote.php/dav/files/itselxao/');
}
if ( ! defined('ELXAO_NC_USER') ) {
    define('ELXAO_NC_USER', 'itselxao'); // your Nextcloud login
}
if ( ! defined('ELXAO_NC_PASS') ) {
    // Put this in wp-config.php for security:
    // define('ELXAO_NC_PASS','YOUR_NEXTCLOUD_APP_PASSWORD');
    define('ELXAO_NC_PASS', 'REPLACE_IN_WP_CONFIG');
}
if ( ! defined('ELXAO_NC_FILES_APP_BASE') ) {
    define('ELXAO_NC_FILES_APP_BASE', 'https://cloud.elxao.com/apps/files/?dir=/');
}

/* ===========================================================
   ABLY CONFIG  (set your key in wp-config.php)
   =========================================================== */
if ( ! defined('ELXAO_ABLY_KEY') ) {
    // Put this in wp-config.php for security:
    // define('ELXAO_ABLY_KEY','KEYID:KEYSECRET');
    define('ELXAO_ABLY_KEY', '');
}

/** DEBUG: set true briefly to log to error_log */
function elxao_log($msg){ $debug=false; if($debug){ error_log('[ELXAO] '.(is_scalar($msg)?$msg:wp_json_encode($msg))); }}

/* ---------------- ACF helpers (field-key safe) ---------------- */

function elxao_get_acf_field_key( $field_name, $post_id ){
    if ( function_exists('get_field_object') ) {
        $fo = get_field_object($field_name, $post_id, false, false);
        if ( is_array($fo) && !empty($fo['key']) ) return $fo['key'];
    }
    if ( function_exists('acf_get_field_groups') && function_exists('acf_get_fields') ) {
        $groups = acf_get_field_groups([ 'post_id' => $post_id ]);
        foreach ( (array)$groups as $g ) {
            $fields = acf_get_fields($g);
            if ( is_array($fields) ) {
                foreach ( $fields as $f ) {
                    if ( !empty($f['name']) && $f['name'] === $field_name && !empty($f['key']) ) return $f['key'];
                }
            }
        }
    }
    if ( function_exists('acf_get_field') ) {
        $f = acf_get_field($field_name);
        if ( is_array($f) && !empty($f['key']) ) return $f['key'];
    }
    return '';
}

function elxao_update_acf( $field_name, $value, $post_id ){
    $key = elxao_get_acf_field_key($field_name, $post_id);

    if ( $key && function_exists('update_field') ) { update_field($key, $value, $post_id); return; }
    if ( function_exists('update_field') ) {
        update_field($field_name, $value, $post_id);
        if ( $key ) update_post_meta($post_id, '_' . $field_name, $key);
        return;
    }
    update_post_meta($post_id, $field_name, $value);
    if ( $key ) update_post_meta($post_id, '_' . $field_name, $key);
}

/* ---------------- Subscription detection & lookup ---------------- */

function elxao_subscription_cpts(){ return ['shop_subscription','subscription','fs_subscription','wpdesk_subscription','wc_subscription']; }
function elxao_relation_keys(){ return ['_order_id','_parent_order_id','_initial_order_id','_origin_order_id','order_id','_order_key']; }

function elxao_build_relation_meta_query( int $order_id, string $order_key ) : array {
    $keys = elxao_relation_keys(); $rel  = [ 'relation' => 'OR' ];
    foreach ( $keys as $k ) {
        if ( $k === '_order_key' ) { if ( $order_key !== '' ) $rel[] = [ 'key' => $k, 'value' => $order_key, 'compare' => '=' ]; continue; }
        $rel[] = [ 'key' => $k, 'value' => $order_id, 'compare' => '=' ];
        $rel[] = [ 'key' => $k, 'value' => '"' . $order_id . '"', 'compare' => 'LIKE' ];
        $rel[] = [ 'key' => $k, 'value' => 'i:' . $order_id . ';',  'compare' => 'LIKE' ];
    }
    return $rel;
}

/** A line item is a subscription iff product (or parent) is in product_cat = 'sla-gmaas'. */
function elxao_is_subscription_item( WC_Order_Item_Product $item ) : bool {
    $product = $item->get_product(); if ( ! $product ) return false;
    $product_id = $product->get_id();
    $parent_id  = method_exists($product,'get_parent_id') ? (int)$product->get_parent_id() : 0;
    $slug       = 'sla-gmaas';
    $in_cat = static function( $pid ) use ( $slug ){ return $pid ? has_term( $slug, 'product_cat', $pid ) : false; };
    return $in_cat($product_id) || ( $parent_id && $in_cat($parent_id) );
}

function elxao_find_subscription_post_id_for_order( WC_Order $order ) : string {
    $order_id  = (int) $order->get_id();
    $customer  = (int) $order->get_user_id();
    $order_key = (string) $order->get_order_key();
    $cpts      = elxao_subscription_cpts();
    $rel       = elxao_build_relation_meta_query( $order_id, $order_key );

    $q1 = new WP_Query([
        'post_type' => $cpts, 'post_status' => 'any', 'posts_per_page' => 1, 'no_found_rows' => true, 'fields' => 'ids',
        'meta_query'=> [ 'relation'=>'AND', [ 'key'=>'_customer_user','value'=>$customer,'compare'=>'=' ], $rel ],
        'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'ignore_sticky_posts' => true,
    ]);
    if ( ! empty($q1->posts) ) return (string) $q1->posts[0];

    $q2 = new WP_Query([
        'post_type' => $cpts, 'post_status' => 'any', 'posts_per_page' => 1, 'no_found_rows' => true, 'fields' => 'ids',
        'meta_query'=> $rel, 'update_post_meta_cache' => false, 'update_post_term_cache' => false, 'ignore_sticky_posts' => true,
    ]);
    if ( ! empty($q2->posts) ) return (string) $q2->posts[0];

    $q3 = new WP_Query([
        'post_type' => $cpts, 'post_status' => 'any', 'posts_per_page' => 1, 'no_found_rows' => true, 'fields' => 'ids',
        'post_parent' => $order_id, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
    ]);
    if ( ! empty($q3->posts) ) return (string) $q3->posts[0];

    if ( function_exists('wcs_get_subscriptions_for_order') ) {
        $subs = wcs_get_subscriptions_for_order( $order, [ 'order_type' => 'any' ] );
        if ( ! empty( $subs ) ) {
            $first = reset( $subs );
            if ( $first && is_object( $first ) ) {
                if ( method_exists( $first, 'get_id' ) ) return (string) $first->get_id();
                if ( isset( $first->id ) ) return (string) (int) $first->id;
            }
        }
    }

    foreach ( $order->get_items() as $item ) {
        if ( ! ($item instanceof WC_Order_Item_Product) ) continue;
        foreach ( ['_subscription_id','subscription_id','_fs_subscription_id','fs_subscription_id'] as $meta_key ) {
            $meta_val = $item->get_meta( $meta_key, true );
            if ( $meta_val ) return (string) $meta_val;
        }
    }
    return '';
}

/* ---------------- Project creation ---------------- */

add_action('woocommerce_order_status_processing','elxao_create_projects_from_order',10,1);
add_action('woocommerce_order_status_completed','elxao_create_projects_from_order',10,1);
add_action('woocommerce_thankyou','elxao_backfill_on_thankyou', 10, 1);
add_action('woocommerce_checkout_subscription_created','elxao_on_checkout_subscription_created',20,2);

function elxao_create_projects_from_order($order_id){
    if ( ! function_exists('wc_get_order') ) return;
    if ( get_post_meta($order_id,'_elxao_projects_created',true) ) return;

    $order = wc_get_order($order_id);
    if ( ! $order || ( method_exists($order,'is_paid') && ! $order->is_paid() ) ) return;

    $client_id = (int) $order->get_user_id();
    $items = $order->get_items();
    if ( empty($items) ) return;

    $prefill_sub_id = elxao_find_subscription_post_id_for_order($order); // may be ''

    foreach ( $items as $item_id => $item ) {
        if ( ! ($item instanceof WC_Order_Item_Product) ) continue;

        $product      = $item->get_product();
        $product_name = $item->get_name();
        $qty          = (int) $item->get_quantity();

        $is_sub_item    = elxao_is_subscription_item($item);
        $project_type    = $is_sub_item ? 'subscription' : 'one_shot';
        $subscription_id = $is_sub_item ? (string) $prefill_sub_id : '';

        $project_post_id = wp_insert_post([
            'post_title'  => sanitize_text_field($product_name),
            'post_type'   => 'project',
            'post_status' => 'publish',
            'post_content'=> '',
        ]);
        if ( is_wp_error($project_post_id) ) continue;

        // 19 ACF fields
        $now_mysql = current_time('mysql');
        $now_date  = current_time('Y-m-d');
        elxao_update_acf('order_id',          $order_id,        $project_post_id);
        elxao_update_acf('project_type',      $project_type,    $project_post_id);
        elxao_update_acf('subscription_id',   $subscription_id, $project_post_id);
        elxao_update_acf('parent_project',    '',               $project_post_id);
        elxao_update_acf('project_id',        $project_post_id, $project_post_id);
        elxao_update_acf('creation_date',     $now_date,        $project_post_id);
        elxao_update_acf('project_name',      $product_name,    $project_post_id);
        elxao_update_acf('units_purchased',   $qty,             $project_post_id);
        elxao_update_acf('client_user',       $client_id,       $project_post_id);
        elxao_update_acf('pm_user',           '',               $project_post_id);
        elxao_update_acf('status',            'new',            $project_post_id);
        elxao_update_acf('summary',           '',               $project_post_id);
        elxao_update_acf('progress',          0,                $project_post_id);
        elxao_update_acf('deadline',          '',               $project_post_id);
        elxao_update_acf('cloud_folder_id',   '',               $project_post_id);
        elxao_update_acf('action_required',   0,                $project_post_id);
        elxao_update_acf('action_type',       '',               $project_post_id);
        elxao_update_acf('action_message',    '',               $project_post_id);
        elxao_update_acf('latest_message_at', $now_mysql,       $project_post_id);

        // Internal mapping (per-item)
        update_post_meta($project_post_id,'_elxao_origin_order_id',(int)$order_id);
        update_post_meta($project_post_id,'_elxao_origin_item_id',(int)$item_id);
        update_post_meta($project_post_id,'_elxao_product_id',$product ? (int)$product->get_id() : 0);
        update_post_meta($project_post_id,'_elxao_variation_id',$product && method_exists($product,'get_parent_id') ? (int)$product->get_parent_id() : 0);
        update_post_meta($project_post_id,'_elxao_is_subscription_item',$is_sub_item ? 1 : 0);

        // Hooks: Nextcloud provisioning + Chat
        do_action('elxao_drive_create_folder',$project_post_id,$client_id);
        do_action('elxao_chat_create_room',$project_post_id,$client_id);

        $msg = 'Project automatically created from order #'.$order_id.'.';
        if ( $subscription_id ) $msg .= ' Subscription ID: '.$subscription_id.'.';
        do_action('elxao_chat_system_message',$project_post_id,$msg);
    }
    update_post_meta($order_id,'_elxao_projects_created',1);
}

/** Backfill after thankyou */
function elxao_backfill_on_thankyou( $order_id ){
    $order = wc_get_order( $order_id ); if ( ! $order ) return;
    $sub_id = elxao_find_subscription_post_id_for_order( $order );
    if ( $sub_id ) elxao_assign_subscription_to_order_projects( (int)$order_id, (int)$sub_id );
}

/** On subscription creation during checkout */
function elxao_on_checkout_subscription_created( $subscription, $order ){
    if ( ! $order instanceof WC_Order ) {
        $order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : (int) $order;
        $order    = $order_id ? wc_get_order( $order_id ) : null;
    }
    if ( ! $order instanceof WC_Order ) return;

    $subscription_id = 0;
    if ( is_object( $subscription ) ) {
        if ( method_exists( $subscription, 'get_id' ) ) $subscription_id = (int) $subscription->get_id();
        elseif ( isset( $subscription->id ) )          $subscription_id = (int) $subscription->id;
        if ( ! $subscription_id && method_exists( $subscription, 'get_parent_id' ) ) {
            $maybe = (int) $subscription->get_parent_id();
            if ( ! $order->get_id() && $maybe ) $order = wc_get_order( $maybe );
        }
    } else { $subscription_id = (int) $subscription; }

    if ( ! $subscription_id ) return;
    elxao_assign_subscription_to_order_projects( (int) $order->get_id(), $subscription_id );
}

/* ---------------- Deterministic backfill targets ---------------- */

function elxao_assign_subscription_to_order_projects( $origin_order_id, $subscription_post_id ){
    $q = new WP_Query([
        'post_type' => 'project', 'post_status'=>'any', 'posts_per_page'=>200, 'no_found_rows'=>true, 'fields'=>'ids',
        'meta_query'=> [ [ 'key'=>'order_id', 'value'=>$origin_order_id, 'compare'=>'=' ] ],
        'update_post_meta_cache'=>false, 'update_post_term_cache'=>false, 'ignore_sticky_posts'=>true,
    ]);
    if ( empty($q->posts) ) return;

    foreach ( $q->posts as $pid ) {
        $is_sub = (int) get_post_meta($pid,'_elxao_is_subscription_item',true) === 1;
        if ( ! $is_sub ) continue;

        $current = function_exists('get_field') ? (string) get_field('subscription_id', $pid) : (string) get_post_meta($pid, 'subscription_id', true);
        if ( $current === '' ) elxao_update_acf('subscription_id', (string)$subscription_post_id, $pid);

        $ptype = function_exists('get_field') ? (string) get_field('project_type', $pid) : (string) get_post_meta($pid, 'project_type', true);
        if ( $ptype !== 'subscription' ) elxao_update_acf('project_type','subscription',$pid);
    }
}

/* ---------------- Backfill triggers: save_post + URL visit ---------------- */

add_action('save_post', function( $post_id, $post ){
    $cpts = elxao_subscription_cpts();
    if ( ! in_array($post->post_type, $cpts, true) ) return;

    $subscription_post_id = (int) $post_id;

    $origin_order_id = 0;
    foreach ( elxao_relation_keys() as $k ) {
        if ( $k === '_order_key' ) continue;
        $origin_order_id = (int) get_post_meta( $subscription_post_id, $k, true );
        if ( $origin_order_id ) break;
    }
    if ( $origin_order_id ) { elxao_assign_subscription_to_order_projects( $origin_order_id, $subscription_post_id ); return; }

    $customer_user = (int) get_post_meta( $subscription_post_id, '_customer_user', true );
    if ( ! $customer_user && is_user_logged_in() ) $customer_user = get_current_user_id();
    if ( $customer_user ) {
        $q = new WP_Query([
            'post_type'=>'project','post_status'=>'any','posts_per_page'=>10,'orderby'=>'date','order'=>'DESC',
            'no_found_rows'=>true,'fields'=>'ids',
            'meta_query'=>[
                'relation'=>'AND',
                [ 'key'=>'client_user','value'=>$customer_user,'compare'=>'=' ],
                [ 'key'=>'project_type','value'=>'subscription','compare'=>'=' ],
            ],
            'update_post_meta_cache'=>false,'update_post_term_cache'=>false,
        ]);
        if ( ! empty($q->posts) ) {
            foreach ( $q->posts as $pid ) {
                $current = function_exists('get_field') ? (string) get_field('subscription_id', $pid) : (string) get_post_meta($pid, 'subscription_id', true);
                if ( $current === '' ) elxao_update_acf('subscription_id',(string)$subscription_post_id,$pid);
            }
        }
    }
}, 10, 2 );

add_action('template_redirect', function(){
    if ( ! function_exists('is_account_page') || ! is_account_page() ) return;
    $path = trim( parse_url( add_query_arg([]), PHP_URL_PATH ), '/' ); if ( ! $path ) return;
    if ( ! preg_match('#/(?:view-subscription|subscription|fs-subscription)/(\d+)/?$#i', '/'.$path, $m) ) return;
    $subscription_post_id = (int) $m[1]; if ( ! $subscription_post_id ) return;

    $origin_order_id = 0;
    foreach ( elxao_relation_keys() as $k ) {
        if ( $k === '_order_key' ) continue;
        $origin_order_id = (int) get_post_meta( $subscription_post_id, $k, true );
        if ( $origin_order_id ) break;
    }
    if ( $origin_order_id ) elxao_assign_subscription_to_order_projects( $origin_order_id, $subscription_post_id );
});

/* -------- Admin: colonne ID triable pour Projects -------- */
add_filter('manage_project_posts_columns', function($cols){
    $new = [];
    foreach ($cols as $k => $v) { $new[$k] = $v; if ($k === 'title') $new['elxao_id'] = 'ID'; }
    return $new;
});
add_action('manage_project_posts_custom_column', function($col, $post_id){
    if ($col === 'elxao_id') echo (int)$post_id;
}, 10, 2);
add_filter('manage_edit-project_sortable_columns', function($cols){ $cols['elxao_id'] = 'elxao_id'; return $cols; });
add_action('pre_get_posts', function($q){
    if (!is_admin() || !$q->is_main_query()) return;
    if ($q->get('post_type') !== 'project') return;
    if ($q->get('orderby') === 'elxao_id') $q->set('orderby', 'ID');
});

/* ===========================================================
   NEXTCLOUD WEBdav PROVISIONING (folder tree + ACF URL save)
   =========================================================== */

function elxao_slug($str){
    $s = remove_accents( (string) $str );
    $s = preg_replace('~[^\pL\d]+~u', '-', $s);
    $s = trim($s, '-');
    $s = preg_replace('~[^-\w]+~', '', $s);
    $s = strtolower($s);
    return $s ?: 'n-a';
}

/** MKCOL via WebDAV. Success if created or already exists. */
function elxao_nc_mkcol($relative_path){
    $url = rtrim(ELXAO_NC_BASE, '/') . '/' . ltrim($relative_path, '/');
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_USERPWD, ELXAO_NC_USER . ':' . ELXAO_NC_PASS);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'MKCOL');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    $resp  = curl_exec($ch);
    $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return in_array($code, [201, 405], true);
}

/** Create each path segment progressively (ELXAO/Client/Project/…) */
function elxao_nc_mkcol_recursive(array $segments){
    $accum = '';
    foreach($segments as $seg){
        $accum .= ($accum==='' ? '' : '/') . $seg;
        if ( ! elxao_nc_mkcol($accum) ) { elxao_log('MKCOL failed at: '.$accum); return false; }
    }
    return true;
}

/** Build a Nextcloud Files UI deep-link for ACF. */
function elxao_nc_build_files_url(array $segments){
    $encoded = array_map('rawurlencode', $segments);
    return rtrim(ELXAO_NC_FILES_APP_BASE, '/?&') . '/'. implode('/', $encoded) . '/';
}

/**
 * Provision Nextcloud folder tree and save ACF cloud_folder_id.
 * Hook: do_action('elxao_drive_create_folder', $project_post_id, $client_user_id)
 */
add_action('elxao_drive_create_folder', function( $project_id, $client_user_id ){
    // Client slug
    $client_slug = 'client-unknown';
    if ( $client_user_id ) {
        $u = get_userdata( (int) $client_user_id );
        if ( $u ) {
            $display = $u->display_name ?: $u->user_nicename ?: $u->user_login;
            $client_slug = elxao_slug($display);
        }
    }

    // Project fields
    $project_ref  = function_exists('get_field') ? (string) get_field('project_id', $project_id) : (string) $project_id;
    $project_name = function_exists('get_field') ? (string) get_field('project_name', $project_id) : (string) get_the_title($project_id);
    $project_slug = elxao_slug($project_name ?: ('project-'.$project_id));

    // Base path segments
    $base_segments = ['ELXAO', $client_slug, $project_ref . '_' . $project_slug];

    // Create main tree
    if ( ! elxao_nc_mkcol_recursive($base_segments) ) return;

    // Subfolders
    foreach(['Documents','Project Planning Documentation','Deliverables','Reports','ClientUploads'] as $s){
        elxao_nc_mkcol( implode('/', array_merge($base_segments, [$s])) );
    }

    // Files app URL → ACF
    $folder_url = elxao_nc_build_files_url($base_segments);
    elxao_update_acf('cloud_folder_id', $folder_url, $project_id);
    elxao_log(['project'=>$project_id,'cloud_folder_id'=>$folder_url]);
}, 10, 2);

/* ===========================================================
   ABLY REALTIME CHAT (room IDs, token endpoint, UI shortcode)
   =========================================================== */

/** Default room id if none saved in ACF */
function elxao_default_room_id( $project_id ){ return 'project_' . (int) $project_id; }

/** Minimal Ably REST publisher for server-side (system) messages. */
function elxao_ably_publish( string $channel, array $payload ){
    if ( ! defined('ELXAO_ABLY_KEY') || ! ELXAO_ABLY_KEY ) return false;
    $endpoint = 'https://rest.ably.io/channels/' . rawurlencode($channel) . '/messages';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode( ELXAO_ABLY_KEY ), // keyId:keySecret
        ],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => wp_json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ( $code < 200 || $code >= 300 ) { elxao_log(['ably_publish_failed'=>$code, 'resp'=>$resp]); return false; }
    return true;
}

/** Create/store chat_room_id on project creation and warm channel */
add_action('elxao_chat_create_room', function( $project_id, $client_user_id ){
    $room_id = function_exists('get_field') ? (string) get_field('chat_room_id', $project_id)
                                            : (string) get_post_meta($project_id,'chat_room_id',true);
    if ( $room_id === '' ) { $room_id = elxao_default_room_id($project_id); elxao_update_acf('chat_room_id', $room_id, $project_id); }

    elxao_ably_publish($room_id, [
        'name' => 'system',
        'data' => ['type' => 'system', 'message' => 'Project room created for project #'.$project_id, 'project' => (int) $project_id],
    ]);
}, 10, 2);

/** Publish later system messages via do_action('elxao_chat_system_message', $project_id, $text) */
add_action('elxao_chat_system_message', function( $project_id, $text ){
    $room_id = function_exists('get_field') ? (string) get_field('chat_room_id', $project_id)
                                            : (string) get_post_meta($project_id,'chat_room_id',true);
    if ( $room_id === '' ) $room_id = elxao_default_room_id($project_id);
    elxao_ably_publish($room_id, ['name'=>'system','data'=>['type'=>'system','message'=>(string)$text,'project'=>(int)$project_id]]);
}, 10, 2);

/** REST: Ably token scoped to ONE project room (client/pm/admin only) */
add_action('rest_api_init', function(){
    register_rest_route('elxao/v1', '/chat-token', [
        'methods'  => 'GET',
        'callback' => 'elxao_rest_chat_token',
        'permission_callback' => function(){ return is_user_logged_in(); },
        'args' => ['project_id' => ['required' => true, 'validate_callback' => function($v){ return (int)$v > 0; }]],
    ]);
});

function elxao_rest_chat_token( WP_REST_Request $req ){
    if ( ! defined('ELXAO_ABLY_KEY') || ! ELXAO_ABLY_KEY ) return new WP_Error('no_ably_key','Ably key not configured', ['status'=>500]);

    $project_id = (int) $req->get_param('project_id');

    // Participant check
    $user_id  = get_current_user_id();
    $is_admin = user_can($user_id, 'administrator');
    $client   = function_exists('get_field') ? (int) get_field('client_user', $project_id) : (int) get_post_meta($project_id,'client_user',true);
    $pm       = function_exists('get_field') ? (int) get_field('pm_user',    $project_id) : (int) get_post_meta($project_id,'pm_user',true);
    $is_participant = $is_admin || $user_id === $client || ( $pm ? $user_id === $pm : false );
    if ( ! $is_participant ) return new WP_Error('forbidden','You are not a participant of this project', ['status'=>403]);

    // Room id
    $room_id = function_exists('get_field') ? (string) get_field('chat_room_id', $project_id) : (string) get_post_meta($project_id,'chat_room_id',true);
    if ( $room_id === '' ) $room_id = elxao_default_room_id($project_id);

    // Capability
    $capability = [ $room_id => ['publish','subscribe','presence'] ];
    $key_id  = strtok(ELXAO_ABLY_KEY, ':'); // part before ':'
    $endpoint = 'https://rest.ably.io/keys/' . $key_id . '/requestToken';
    $body = ['capability' => wp_json_encode($capability), 'clientId' => 'wpuser_' . $user_id, 'ttl' => 60 * 60 * 1000];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json','Authorization: Basic ' . base64_encode( ELXAO_ABLY_KEY )],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => wp_json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ( $code < 200 || $code >= 300 ) { elxao_log(['ably_token_failed'=>$code, 'resp'=>$resp]); return new WP_Error('token_error','Unable to obtain Ably token', ['status'=>500]); }

    return new WP_REST_Response( json_decode($resp, true), 200 );
}

/* -------- Shortcode with auto-detect Project ID -------- */

function elxao_detect_current_project_id(){
    if ( function_exists('is_singular') && is_singular('project') ) {
        $qid = get_queried_object_id(); if ( $qid ) return (int) $qid;
    }
    global $post;
    if ( $post && isset($post->ID) && get_post_type($post->ID) === 'project' ) return (int) $post->ID;
    return 0;
}

/**
 * [elxao_chat_window] or [elxao_chat_window project_id="123"]
 * Minimal UI to test end-to-end.
 */
remove_shortcode('elxao_chat_window');
add_shortcode('elxao_chat_window', function($atts){
    $atts = shortcode_atts(['project_id'=>0], $atts, 'elxao_chat_window');
    $project_id = (int) $atts['project_id']; if ( ! $project_id ) $project_id = elxao_detect_current_project_id();
    if ( ! $project_id ) return '<div>Missing project_id (not on a Project page).</div>';
    if ( ! is_user_logged_in() ) return '<div>Please log in to view the chat.</div>';

    // Ensure chat_room_id exists
    $room_id = function_exists('get_field') ? (string) get_field('chat_room_id', $project_id) : (string) get_post_meta($project_id,'chat_room_id',true);
    if ( $room_id === '' ) { $room_id = elxao_default_room_id($project_id); elxao_update_acf('chat_room_id', $room_id, $project_id); }

    ob_start(); ?>
    <div id="elxao-chat" data-project="<?php echo esc_attr($project_id); ?>" data-room="<?php echo esc_attr($room_id); ?>"></div>
    <style>
      #elxao-chat{border:1px solid #2b2f36;padding:12px;border-radius:8px;max-height:460px;overflow:auto;
        font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu;background:#0f1115;color:#e5e7eb}
      #elxao-chat .msg{margin:6px 0}
      #elxao-chat .sys{opacity:.7}
      #elxao-chat input{width:100%;padding:10px;border:1px solid #3a3f47;border-radius:6px;margin-top:8px;background:#0f1115;color:#e5e7eb}
      #elxao-chat .muted{opacity:.7}
    </style>
    <script>
    (function(){
      var s = document.createElement('script');
      s.src = 'https://cdn.ably.io/lib/ably.min-1.js';
      s.onload = function(){
        var el = document.getElementById('elxao-chat');
        var projectId = el.getAttribute('data-project');
        var roomId    = el.getAttribute('data-room');

        function addLine(txt, cls){
          var p=document.createElement('div'); p.className='msg '+(cls||''); p.textContent=txt;
          el.appendChild(p);
          el.scrollTop = el.scrollHeight;
        }

        // input (disabled until connected)
        var input = document.createElement('input');
        input.type = 'text';
        input.placeholder = 'Connecting…';
        input.disabled = true;
        el.appendChild(input);

        fetch('<?php echo esc_url( site_url('/wp-json/elxao/v1/chat-token?project_id=') ); ?>'+projectId, { credentials: 'same-origin' })
          .then(function(r){ return r.json(); })
          .then(function(tokenDetails){
            if(tokenDetails.error) throw new Error(tokenDetails.message || 'Token error');

            var client  = new Ably.Realtime.Promise({ tokenDetails: tokenDetails, echoMessages: true });
            var channel = client.channels.get(roomId);

            channel.subscribe(function(msg){
              var body = (typeof msg.data === 'string') ? msg.data : JSON.stringify(msg.data);
              var isSys = (msg.name === 'system') || (msg.data && msg.data.type === 'system');
              addLine((msg.name || 'message') + ': ' + body, isSys ? 'sys' : '');
            });

            // Show brief history
            channel.history({limit:20}).then(function(page){
              var items = [];
              page.items.reverse().forEach(function(m){
                var body = (typeof m.data === 'string') ? m.data : JSON.stringify(m.data);
                var isSys = (m.name === 'system') || (m.data && m.data.type === 'system');
                items.push((m.name || 'message')+': '+body+(isSys?' (system)':''));
              });
              // wipe any "connecting" lines above input
              var children = Array.prototype.slice.call(el.querySelectorAll('.msg'));
              children.forEach(function(c){ el.removeChild(c); });
              items.forEach(function(t){ addLine(t); });
            }).catch(function(){});

            // Enable sending
            input.disabled = false;
            input.placeholder = 'Type a message and press Enter';
            input.addEventListener('keydown', function(e){
              if(e.key === 'Enter' && input.value.trim()){
                var toSend = input.value.trim();
                input.value = '';
                channel.publish('text', toSend).then(function(){
                  addLine('you: ' + toSend, 'muted'); // local echo
                }).catch(function(err){
                  addLine('send failed: ' + (err && err.message ? err.message : 'unknown'), 'sys');
                });
              }
            });
          })
          .catch(function(err){
            console.error(err);
            addLine('Chat error: '+(err && err.message ? err.message : 'failed to connect'), 'sys');
            input.placeholder = 'Chat unavailable';
            input.disabled = true;
          });
      };
      document.head.appendChild(s);
    })();
    </script>
    <?php
    return ob_get_clean();
});

/* ================================================
   LOOP-FRIENDLY CHAT CARD (auto-connect)
   Shortcode: [elxao_chat_card]
   Optional: height="320"
================================================== */

function elxao_detect_loop_project_id(){
    global $post;
    if ( $post && isset($post->ID) && get_post_type($post->ID) === 'project' ) {
        return (int)$post->ID;
    }
    if ( function_exists('is_singular') && is_singular('project') ) {
        $qid = get_queried_object_id();
        if ( $qid ) return (int)$qid;
    }
    return 0;
}

add_shortcode('elxao_chat_card', function($atts){
    if ( ! is_user_logged_in() ) return '<div>Please log in to view chats.</div>';

    $a = shortcode_atts([
        'project_id' => 0,
        'height'     => '320',
    ], $atts, 'elxao_chat_card');

    $pid = (int)$a['project_id'];
    if ( ! $pid ) $pid = elxao_detect_loop_project_id();
    if ( ! $pid ) return '<div>Chat unavailable (no project).</div>';

    // Ensure we have a room id stored
    $room_id = function_exists('get_field') ? (string) get_field('chat_room_id', $pid)
                                            : (string) get_post_meta($pid,'chat_room_id',true);
    if ($room_id === '') { $room_id = 'project_'.$pid; elxao_update_acf('chat_room_id', $room_id, $pid); }

    $height = preg_replace('~[^0-9]~','', $a['height']) ?: '320';

    ob_start(); ?>
    <div class="elxao-chat-card"
         data-project="<?php echo esc_attr($pid); ?>"
         data-room="<?php echo esc_attr($room_id); ?>"
         style="border:1px solid #2b2f36;border-radius:10px;padding:10px;display:flex;flex-direction:column;gap:8px;background:#0f1115;color:#e5e7eb">

      <div class="elxao-chat-msgs"
           style="height:<?php echo esc_attr($height); ?>px; overflow:auto; border:1px solid #3a3f47; border-radius:8px; padding:8px; background:#0f1115;">
        <div class="elxao-chat-empty" style="opacity:.7; text-align:center; padding:6px 0;">Connecting to chat…</div>
      </div>

      <input class="elxao-chat-input"
             type="text"
             placeholder="Connecting…"
             disabled
             style="width:100%;padding:10px;border:1px solid #3a3f47;border-radius:8px;background:#0f1115;color:#e5e7eb" />
    </div>

    <script>
    (function(){
      var INITIAL_LIMIT = 50;
      var PAGE_LIMIT    = 30;
      var DOM_LIMIT     = 300;
      var TOP_THRESHOLD = 0.1; // 10%

      function loadAbly(){
        if(window.Ably){ return Promise.resolve(); }
        if(window.__elxaoAblyPromise){ return window.__elxaoAblyPromise; }
        window.__elxaoAblyPromise = new Promise(function(resolve, reject){
          var s = document.createElement('script');
          s.src = 'https://cdn.ably.io/lib/ably.min-1.js';
          s.onload = function(){ resolve(); };
          s.onerror = function(){ reject(new Error('Failed to load Ably SDK')); };
          document.head.appendChild(s);
        });
        return window.__elxaoAblyPromise;
      }

      function fetchToken(projectId){
        return fetch('<?php echo esc_url( site_url('/wp-json/elxao/v1/chat-token?project_id=') ); ?>'+projectId, { credentials: 'same-origin' })
          .then(function(res){
            if(!res.ok){ return res.json().then(function(err){ throw new Error(err && err.message ? err.message : 'Token request failed'); }); }
            return res.json();
          })
          .then(function(body){
            if(body && body.error){ throw new Error(body.message || 'Token error'); }
            if(!body || !body.token){ throw new Error('Invalid token response'); }
            return body;
          });
      }

      function createSpinner(text){
        var d = document.createElement('div');
        d.className = 'elxao-chat-spinner';
        d.textContent = text || 'Loading…';
        d.style.cssText = 'text-align:center;padding:6px 0;font-size:12px;opacity:.7;';
        return d;
      }

      function formatTimestamp(ms){
        var date = new Date(ms);
        return date.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'});
      }

      function renderMessage(msg, options){
        var wrap = document.createElement('div');
        wrap.className = 'elxao-chat-item';
        wrap.style.margin = '6px 0';
        if(options && options.system){
          wrap.className += ' elxao-chat-item--system';
          wrap.style.opacity = '.7';
        }
        if(options && options.pending){
          wrap.className += ' elxao-chat-item--pending';
          wrap.style.opacity = '.7';
        }

        var header = document.createElement('div');
        header.style.fontSize = '11px';
        header.style.opacity = '.65';
        header.style.marginBottom = '2px';
        header.textContent = (msg.author || 'message') + ' • ' + formatTimestamp(msg.timestamp || Date.now());

        var body = document.createElement('div');
        body.textContent = msg.text;
        body.style.whiteSpace = 'pre-wrap';

        wrap.appendChild(header);
        wrap.appendChild(body);
        return wrap;
      }

      function maintainDomLimit(container){
        var items = container.querySelectorAll('.elxao-chat-item');
        if(items.length <= DOM_LIMIT) return;
        var excess = items.length - DOM_LIMIT;
        for(var i = 0; i < items.length && excess > 0; i++){
          var el = items[i];
          if(!el || !el.parentNode) continue;
          el.parentNode.removeChild(el);
          excess--;
        }
      }

      function setupCard(card){
        if(card.__elxaoInitialized){ return; }
        card.__elxaoInitialized = true;

        var projectId = card.getAttribute('data-project');
        var roomId    = card.getAttribute('data-room');
        var msgsEl    = card.querySelector('.elxao-chat-msgs');
        var inputEl   = card.querySelector('.elxao-chat-input');
        var empty     = card.querySelector('.elxao-chat-empty');
        var topLoader = createSpinner('Loading messages…');
        topLoader.style.display = 'block';
        topLoader.className += ' elxao-chat-top-loader';
        msgsEl.insertBefore(topLoader, msgsEl.firstChild);

        var noMoreLabel = document.createElement('div');
        noMoreLabel.className = 'elxao-chat-no-more';
        noMoreLabel.textContent = 'No more messages';
        noMoreLabel.style.cssText = 'text-align:center;padding:6px 0;font-size:12px;opacity:.6;display:none;';
        msgsEl.insertBefore(noMoreLabel, topLoader.nextSibling);

        var state = {
          channel: null,
          loadingHistory: false,
          reachedStart: false,
          pendingCount: 0,
          oldestTimestamp: null
        };

        function isNearBottom(){
          return (msgsEl.scrollTop + msgsEl.clientHeight + 40) >= msgsEl.scrollHeight;
        }

        function stickToBottom(){ msgsEl.scrollTop = msgsEl.scrollHeight; }

        function addMessages(items, prepend){
          if(!items || !items.length) return;
          var fragment = document.createDocumentFragment();
          items.forEach(function(item){
            var data = item.data;
            var text;
            if(typeof data === 'string'){ text = data; }
            else if(data && typeof data === 'object' && data.message){ text = data.message; }
            else { text = JSON.stringify(data); }

            var author = item.clientId || item.name || 'message';
            if(item.name === 'system' || (data && data.type === 'system')){
              author = 'system';
            }

            var node = renderMessage({
              text: text,
              author: author,
              timestamp: item.timestamp
            }, { system: item.name === 'system' || (data && data.type === 'system') });

            node.dataset.messageId = item.id || '';
            fragment.appendChild(node);
          });

          if(prepend){
            msgsEl.insertBefore(fragment, noMoreLabel.nextSibling);
          } else {
            msgsEl.appendChild(fragment);
          }

          maintainDomLimit(msgsEl);
        }

        function handleHistory(page, isInitial){
          if(!page){ return; }
          if(isInitial){ msgsEl.querySelectorAll('.elxao-chat-item').forEach(function(n){ n.remove(); }); }
          var items = page.items.slice().reverse();
          var stick = isInitial || isNearBottom();
          addMessages(items, !isInitial);
          if(isInitial){ stickToBottom(); }
          else if(stick){ stickToBottom(); }

          if(items.length){
            var earliest = items[0].timestamp;
            if(typeof earliest === 'number'){ state.oldestTimestamp = earliest; }
          }

          var expected = isInitial ? INITIAL_LIMIT : PAGE_LIMIT;
          if(items.length < expected || !page.hasNext()){ state.reachedStart = true; noMoreLabel.style.display = 'block'; }
          showHistoryLoader(false);
        }

        function showHistoryLoader(show){
          topLoader.style.display = show ? 'block' : 'none';
        }

        function loadOlder(){
          if(state.loadingHistory || state.reachedStart || !state.channel){ return; }
          state.loadingHistory = true;
          showHistoryLoader(true);
          var opts = { limit: PAGE_LIMIT };
          if(state.oldestTimestamp){ opts.end = state.oldestTimestamp - 1; }
          state.channel.history(opts).then(function(nextPage){
            var items = nextPage.items.slice().reverse();
            addMessages(items, true);
            if(items.length){
              var earliest = items[0].timestamp;
              if(typeof earliest === 'number'){ state.oldestTimestamp = earliest; }
            }
            if(items.length < PAGE_LIMIT || !nextPage.hasNext()){ state.reachedStart = true; noMoreLabel.style.display = 'block'; }
          }).catch(function(err){ console.error(err); })
            .finally(function(){ state.loadingHistory = false; showHistoryLoader(false); });
        }

        msgsEl.addEventListener('scroll', function(){
          if(msgsEl.scrollHeight <= 0){ return; }
          var threshold = msgsEl.scrollHeight * TOP_THRESHOLD;
          if(msgsEl.scrollTop <= threshold || msgsEl.scrollTop <= 300){
            loadOlder();
          }
        });

        inputEl.placeholder = 'Connecting…';
        inputEl.disabled = true;

        Promise.all([loadAbly(), fetchToken(projectId)])
          .then(function(results){
            var tokenDetails = results[1];
            var client = new Ably.Realtime.Promise({ tokenDetails: tokenDetails, echoMessages: false });
            card.__ablyClient = client;
            return new Promise(function(resolve){ client.connection.once('connected', resolve); })
              .then(function(){ return client.channels.get(roomId); })
              .then(function(channel){
                state.channel = channel;
                return channel.attach().then(function(){ return channel.history({ limit: INITIAL_LIMIT }); })
                  .then(function(page){
                    if(empty){ empty.remove(); }
                    handleHistory(page, true);
                  })
                  .catch(function(err){
                    console.error(err);
                    showHistoryLoader(false);
                    state.reachedStart = true;
                    if(empty){ empty.textContent = 'Unable to load history'; }
                  });
              })
              .then(function(){ return state.channel; });
          })
          .then(function(channel){
            if(!channel){ throw new Error('Missing channel'); }

            channel.subscribe(function(msg){
              var stick = isNearBottom();
              addMessages([msg], false);
              if(stick){ stickToBottom(); }
            });

            inputEl.disabled = false;
            inputEl.placeholder = 'Type a message and press Enter';

            inputEl.addEventListener('keydown', function(e){
              if(e.key !== 'Enter'){ return; }
              var text = inputEl.value.trim();
              if(!text){ return; }
              inputEl.value = '';

              var localId = 'local-'+Date.now()+'-'+(++state.pendingCount);
              var pendingNode = renderMessage({ text: text, author: 'you', timestamp: Date.now() }, { pending: true });
              pendingNode.dataset.localId = localId;
              msgsEl.appendChild(pendingNode);
              stickToBottom();

              channel.publish({ name: 'text', data: text }).then(function(){
                pendingNode.classList.remove('elxao-chat-item--pending');
                pendingNode.style.opacity = '';
              }).catch(function(err){
                pendingNode.classList.add('elxao-chat-item--system');
                pendingNode.style.opacity = '.7';
                pendingNode.querySelector('div:last-child').textContent = 'send failed: ' + (err && err.message ? err.message : 'unknown');
              });
            });
          })
          .catch(function(err){
            console.error(err);
            var message = (err && err.message) ? err.message : 'failed to connect';
            if(empty){ empty.textContent = 'Chat error: ' + message; }
            inputEl.placeholder = 'Chat unavailable';
            inputEl.disabled = true;
            showHistoryLoader(false);
          });
      }

      function init(){
        var cards = document.querySelectorAll('.elxao-chat-card');
        cards.forEach(setupCard);
      }

      if(!window.__elxaoChatCardsInit){
        window.__elxaoChatCardsInit = true;
        document.addEventListener('DOMContentLoaded', init);
        if(document.readyState === 'interactive' || document.readyState === 'complete'){ init(); }
      }
    })();
    </script>
    <?php
    return ob_get_clean();
});

/* ===========================================================
   ADMIN BACKFILL (one-time): set chat_room_id + warm channels
   =========================================================== */
/**
 * Visit as admin: /wp-admin/?elxao_backfill_chat=1
 * Populates missing chat_room_id and publishes a backfill system message.
 */
add_action('admin_init', function(){
    if ( ! is_admin() || ! current_user_can('manage_options') || empty($_GET['elxao_backfill_chat']) ) return;

    $paged = 1; $updated = 0;
    do {
        $q = new WP_Query([
            'post_type'      => 'project',
            'post_status'    => 'any',
            'posts_per_page' => 100,
            'paged'          => $paged,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => 'chat_room_id', 'compare' => 'NOT EXISTS' ],
                [ 'key' => 'chat_room_id', 'value' => '', 'compare' => '=' ],
            ],
        ]);
        if ( ! $q->have_posts() ) break;

        foreach ( $q->posts as $pid ) {
            $room_id = elxao_default_room_id($pid);
            elxao_update_acf('chat_room_id', $room_id, $pid);

            elxao_ably_publish($room_id, [
                'name' => 'system',
                'data' => ['type'=>'system','message'=>'Project room created (backfilled) for project #'.$pid,'project'=>(int)$pid],
            ]);
            $updated++;
        }
        $paged++;
        wp_reset_postdata();
    } while ( true );

    add_action('admin_notices', function() use ($updated){
        echo '<div class="notice notice-success"><p>ELXAO backfill: updated '.$updated.' project chat rooms.</p></div>';
    });
});

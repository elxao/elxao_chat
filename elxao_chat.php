<?php
/*
Plugin Name: ELXAO Chat
Description: Per-project chat storage (MySQL), REST API for send/history, Ably realtime fan-out, and inbox ordering via latest_message_at (ACF). Colors by ROLE: client, pm, admin. Includes Inbox view.
Version: 1.45.0
Author: ELXAO
*/

if ( ! defined('ABSPATH') ) exit;

/* =========================
   COLOR SETTINGS — EDIT HERE
   ========================= */
if ( ! defined('ELXAO_CHAT_COLOR_BASE') )    define('ELXAO_CHAT_COLOR_BASE',   '#FFFFFF'); // base/fallback
if ( ! defined('ELXAO_CHAT_COLOR_CLIENT') )  define('ELXAO_CHAT_COLOR_CLIENT', '#22c55e'); // client messages
if ( ! defined('ELXAO_CHAT_COLOR_PM') )      define('ELXAO_CHAT_COLOR_PM',     '#e5e7eb'); // PM messages
if ( ! defined('ELXAO_CHAT_COLOR_ADMIN') )   define('ELXAO_CHAT_COLOR_ADMIN',  '#60a5fa'); // admin messages
if ( ! defined('ELXAO_CHAT_COLOR_SYS') )     define('ELXAO_CHAT_COLOR_SYS',    '#94a3b8'); // system lines
if ( ! defined('ELXAO_CHAT_COLOR_READ_UNREAD') )      define('ELXAO_CHAT_COLOR_READ_UNREAD',      '#6b7280'); // unread indicator
if ( ! defined('ELXAO_CHAT_COLOR_READ_ALL') )         define('ELXAO_CHAT_COLOR_READ_ALL',         ELXAO_CHAT_COLOR_CLIENT); // fully read indicator
if ( ! defined('ELXAO_CHAT_COLOR_READ_PM_ONLY') )     define('ELXAO_CHAT_COLOR_READ_PM_ONLY',     '#f472b6'); // admin message read by PM only
if ( ! defined('ELXAO_CHAT_COLOR_READ_CLIENT_ONLY') ) define('ELXAO_CHAT_COLOR_READ_CLIENT_ONLY', ELXAO_CHAT_COLOR_ADMIN); // admin message read by client only
if ( ! defined('ELXAO_CHAT_READ_META_KEY') ) define('ELXAO_CHAT_READ_META_KEY','_elxao_chat_last_reads');

/* ===========================================================
   ABLY CONFIG (set in wp-config.php if you want)
   define('ELXAO_ABLY_KEY','KEYID:KEYSECRET');
   =========================================================== */
if ( ! defined('ELXAO_ABLY_KEY') ) define('ELXAO_ABLY_KEY','');

/* ===========================================================
   DB TABLE
   =========================================================== */
register_activation_hook(__FILE__,'elxao_chat_install');
function elxao_chat_install(){
    global $wpdb;
    $t = $wpdb->prefix.'elxao_chat_messages';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS `$t`(
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        project_id BIGINT UNSIGNED NOT NULL,
        user_id BIGINT UNSIGNED NOT NULL,
        content LONGTEXT NOT NULL,
        content_type VARCHAR(20) NOT NULL DEFAULT 'text',
        published_at DATETIME NOT NULL,
        PRIMARY KEY(id),
        KEY project_time(project_id,published_at),
        KEY user_idx(user_id)
    ) $charset;";
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

/* ===========================================================
   HELPERS
   =========================================================== */
function elxao_user_id_from_acf($v){
    if (is_numeric($v)) return (int)$v;
    if ($v instanceof WP_User) return (int)$v->ID;
    if (is_array($v)) {
        if (isset($v['ID'])) return (int)$v['ID'];
        if (isset($v['id'])) return (int)$v['id'];
        foreach ($v as $item){
            if (is_numeric($item)) return (int)$item;
            if (is_array($item) && isset($item['ID'])) return (int)$item['ID'];
            if ($item instanceof WP_User) return (int)$item->ID;
        }
    }
    if (is_string($v) && preg_match('/^\d+$/',$v)) return (int)$v;
    return 0;
}

function elxao_chat_get_client_pm_ids($pid){
    if (function_exists('get_field')) {
        $client_raw = get_field('client_user',$pid);
        $pm_raw     = get_field('pm_user',$pid);
    } else {
        $client_raw = get_post_meta($pid,'client_user',true);
        $pm_raw     = get_post_meta($pid,'pm_user',true);
    }
    return [
        'client' => elxao_user_id_from_acf($client_raw),
        'pm'     => elxao_user_id_from_acf($pm_raw),
    ];
}

function elxao_chat_role_for_user($pid,$uid){
    if (!$uid) return 'other';
    if (user_can($uid,'administrator')) return 'admin';
    $ids = elxao_chat_get_client_pm_ids($pid);
    if ($ids['client'] && $uid === $ids['client']) return 'client';
    if ($ids['pm'] && $uid === $ids['pm']) return 'pm';
    return 'other';
}

function elxao_chat_normalize_datetime_value($value){
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d H:i:s');
    if (is_numeric($value)) {
        $timestamp = (int)$value;
        if ($timestamp <= 0) return '';
        return wp_date('Y-m-d H:i:s',$timestamp);
    }
    if (is_string($value)) {
        $str = trim($value);
        if ($str === '') return '';
        $timestamp = strtotime($str);
        if (!$timestamp) return '';
        return wp_date('Y-m-d H:i:s',$timestamp);
    }
    return '';
}

function elxao_chat_parse_datetime($value){
    if ($value instanceof DateTimeInterface) return $value->getTimestamp();
    if (is_numeric($value)) return (int)$value;
    if (is_string($value)) {
        $str = trim($value);
        if ($str === '') return 0;
        $timestamp = strtotime($str);
        return $timestamp ? (int)$timestamp : 0;
    }
    return 0;
}

function elxao_chat_get_last_reads($pid){
    $stored = get_post_meta($pid,ELXAO_CHAT_READ_META_KEY,true);
    $result = ['client'=>'','pm'=>'','admin'=>''];
    if (is_array($stored)) {
        foreach ($result as $role => $_) {
            if (!empty($stored[$role])) {
                $normalized = elxao_chat_normalize_datetime_value($stored[$role]);
                if ($normalized) $result[$role] = $normalized;
            }
        }
    }
    return $result;
}

function elxao_chat_update_last_read($pid,$role,$timestamp=null){
    if (!in_array($role,['client','pm','admin'],true)) return false;
    $normalized = elxao_chat_normalize_datetime_value(
        $timestamp === null ? current_time('timestamp') : $timestamp
    );
    if (!$normalized) return false;

    $reads = elxao_chat_get_last_reads($pid);
    $current = $reads[$role] ?? '';
    $current_ts = $current ? elxao_chat_parse_datetime($current) : 0;
    $new_ts = elxao_chat_parse_datetime($normalized);
    if ($current_ts && $new_ts && $current_ts >= $new_ts) return false;

    $reads[$role] = $normalized;
    update_post_meta($pid,ELXAO_CHAT_READ_META_KEY,$reads);
    return $reads;
}

function elxao_chat_build_message_read_status($pid,$message_role,$published_at,?array $reads=null,?array $participants=null){
    if ($reads === null) $reads = elxao_chat_get_last_reads($pid);
    if ($participants === null) $participants = elxao_chat_get_client_pm_ids($pid);

    $published_ts = elxao_chat_parse_datetime($published_at);
    $client_ts    = elxao_chat_parse_datetime($reads['client'] ?? '');
    $pm_ts        = elxao_chat_parse_datetime($reads['pm'] ?? '');
    $admin_ts     = elxao_chat_parse_datetime($reads['admin'] ?? '');

    $status = ['client'=>false,'pm'=>false,'admin'=>false];
    if ($message_role === 'client') $status['client'] = true;
    if ($message_role === 'pm')     $status['pm']     = true;
    if ($message_role === 'admin')  $status['admin']  = true;

    if (empty($participants['client'])) $status['client'] = true;
    if (empty($participants['pm']))     $status['pm']     = true;

    if ($published_ts) {
        if ($client_ts && $client_ts >= $published_ts) $status['client'] = true;
        if ($pm_ts && $pm_ts >= $published_ts)         $status['pm']     = true;
        if ($admin_ts && $admin_ts >= $published_ts)   $status['admin']  = true;
    }

    return $status;
}

function elxao_chat_publish_read_receipt($pid,array $reads,$user_id=0){
    $role_flags = [
        'client' => elxao_chat_parse_datetime($reads['client'] ?? '') > 0,
        'pm'     => elxao_chat_parse_datetime($reads['pm'] ?? '') > 0,
        'admin'  => elxao_chat_parse_datetime($reads['admin'] ?? '') > 0,
    ];

    elxao_chat_publish_to_ably(elxao_chat_room_id($pid),[
        'name' => 'read_receipt',
        'data' => [
            'type'        => 'read_receipt',
            'project'     => $pid,
            'user'        => $user_id,
            'read_times'  => $reads,
            'read_status' => $role_flags,
            'reads'       => [
                'roles' => $role_flags,
                'times' => $reads,
            ],
            'at'          => current_time('mysql'),
        ],
    ]);
}

/* ===========================================================
   ACCESS + ROOM HELPERS
   =========================================================== */
function elxao_chat_user_can_access_project($pid,$uid){
    if (!$uid) return false;
    if (user_can($uid,'administrator')) return true;
    $ids = elxao_chat_get_client_pm_ids($pid);
    return ($uid === $ids['client']) || ($ids['pm'] && $uid === $ids['pm']);
}

function elxao_chat_room_id($pid){
    $rid = function_exists('get_field')?(string)get_field('chat_room_id',$pid):(string)get_post_meta($pid,'chat_room_id',true);
    return $rid?:'project_'.$pid;
}

function elxao_chat_project_post_type(){
    return apply_filters('elxao_chat_project_post_type','project');
}

function elxao_chat_collect_rooms_for_user($uid){
    if(!$uid) return [];

    $args=[
        'post_type'=>elxao_chat_project_post_type(),
        'post_status'=>['publish','private'],
        'posts_per_page'=>-1,
        'meta_key'=>'latest_message_at',
        'orderby'=>'meta_value',
        'order'=>'DESC',
        'fields'=>'ids',
        'no_found_rows'=>true,
        'update_post_meta_cache'=>false,
        'update_post_term_cache'=>false,
    ];
    $args=apply_filters('elxao_chat_inbox_query_args',$args,$uid);

    $query=new WP_Query($args);
    $ids=$query->posts;
    wp_reset_postdata();

    $rooms=[];
    foreach($ids as $pid){
        $pid=(int)$pid;
        if(!elxao_chat_user_can_access_project($pid,$uid)) continue;

        $latest=function_exists('get_field')?get_field('latest_message_at',$pid):get_post_meta($pid,'latest_message_at',true);
        if($latest instanceof DateTimeInterface){
            $latest=$latest->format('Y-m-d H:i:s');
        }
        if(!$latest){
            $fallback=get_post_meta($pid,'latest_message_at',true);
            $latest=$fallback?:'';
        }
        if(!$latest){
            $latest=get_post_modified_time('Y-m-d H:i:s',true,$pid);
        }
        $timestamp=$latest?strtotime($latest):0;

        $rooms[]=[
            'id'=>$pid,
            'title'=>get_the_title($pid),
            'latest'=>$latest,
            'timestamp'=>$timestamp?:0,
        ];
    }

    usort($rooms,function($a,$b){
        return ($b['timestamp']<=>$a['timestamp']);
    });

    return $rooms;
}

function elxao_chat_format_activity($datetime){
    if(!$datetime) return '';
    $ts=strtotime($datetime);
    if(!$ts) return '';
    return date_i18n('M j, H:i',$ts);
}

/* ===========================================================
   REST API
   =========================================================== */
add_action('rest_api_init',function(){
    register_rest_route('elxao/v1','/messages',[
        'methods'=>'POST','callback'=>'elxao_chat_rest_send',
        'permission_callback'=>fn()=>is_user_logged_in()
    ]);
    register_rest_route('elxao/v1','/messages',[
        'methods'=>'GET','callback'=>'elxao_chat_rest_history',
        'permission_callback'=>fn()=>is_user_logged_in()
    ]);
    register_rest_route('elxao/v1','/messages/read',[
        'methods'=>'POST','callback'=>'elxao_chat_rest_mark_read',
        'permission_callback'=>fn()=>is_user_logged_in()
    ]);
    register_rest_route('elxao/v1','/chat-token',[
        'methods'=>'GET','callback'=>'elxao_chat_rest_token',
        'permission_callback'=>fn()=>is_user_logged_in()
    ]);
    register_rest_route('elxao/v1','/inbox-token',[
        'methods'=>'GET','callback'=>'elxao_chat_rest_inbox_token',
        'permission_callback'=>fn()=>is_user_logged_in()
    ]);
    register_rest_route('elxao/v1','/chat-window',[
        'methods'=>'GET','callback'=>'elxao_chat_rest_window',
        'permission_callback'=>fn()=>is_user_logged_in()
    ]);
});

function elxao_chat_request_ably_token($uid,array $capability,$ttl_ms=3600000){
    if ( ! defined('ELXAO_ABLY_KEY') || ! ELXAO_ABLY_KEY )
        return new WP_Error('no_ably','No Ably key',['status'=>500]);

    if(empty($capability))
        return new WP_Error('no_capability','No capability',['status'=>400]);

    $parts=explode(':',ELXAO_ABLY_KEY,2);
    $key_id=$parts[0]??'';
    if(!$key_id)
        return new WP_Error('token_fail','Token error',['status'=>500]);

    $endpoint='https://rest.ably.io/keys/'.$key_id.'/requestToken';
    $body=[
        'capability'=>wp_json_encode($capability),
        'clientId'=>'wpuser_'.$uid,
    ];
    if($ttl_ms){
        $body['ttl']=(int)$ttl_ms;
    }

    $ch=curl_init($endpoint);
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Basic '.base64_encode(ELXAO_ABLY_KEY)],
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>wp_json_encode($body),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10
    ]);
    $resp=curl_exec($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($code<200||$code>=300)
        return new WP_Error('token_fail','Token error',['status'=>500]);

    $data=json_decode($resp,true);
    if(!is_array($data))
        return new WP_Error('token_fail','Token error',['status'=>500]);

    return $data;
}

function elxao_chat_rest_send(WP_REST_Request $r){
    global $wpdb; $t=$wpdb->prefix.'elxao_chat_messages';
    $pid=(int)$r['project_id']; $uid=get_current_user_id();
    if(!elxao_chat_user_can_access_project($pid,$uid))
        return new WP_Error('forbidden','Not allowed',['status'=>403]);

    $msg = trim((string)$r['content']);
    $msg = wp_strip_all_tags($msg,false); // keep newlines
    if($msg==='') return new WP_Error('empty','Empty',['status'=>400]);

    $now=current_time('mysql');
    $wpdb->insert($t,[
        'project_id'=>$pid,'user_id'=>$uid,'content'=>$msg,'content_type'=>'text','published_at'=>$now
    ]);
    $message_id = (int)$wpdb->insert_id;

    // Update latest_message_at for inbox ordering
    if(function_exists('update_field')){
        if(function_exists('elxao_get_acf_field_key'))
            update_field(elxao_get_acf_field_key('latest_message_at',$pid),$now,$pid);
        else update_field('latest_message_at',$now,$pid);
    } else update_post_meta($pid,'latest_message_at',$now);

    // Publish to Ably with display name + ROLE
    $user    = get_userdata($uid);
    $display = $user ? $user->display_name : ('User '.$uid);
    $role    = elxao_chat_role_for_user($pid,$uid);

    $participants = elxao_chat_get_client_pm_ids($pid);
    $reads = elxao_chat_update_last_read($pid,$role,$now);
    if (!$reads) $reads = elxao_chat_get_last_reads($pid);
    $read_status = elxao_chat_build_message_read_status($pid,$role,$now,$reads,$participants);

    elxao_chat_publish_to_ably(elxao_chat_room_id($pid),[
        'name'=>'text',
        'data'=>[
            'type'=>'text',
            'id'=>$message_id,
            'message'=>$msg,
            'project'=>$pid,
            'user'=>$uid,
            'user_display'=>$display,
            'role'=>$role,
            'at'=>$now,
            'read_status'=>$read_status,
            'read_times'=>$reads,
            'reads'=>[
                'roles'=>$read_status,
                'times'=>$reads,
            ],
        ]
    ]);
    return new WP_REST_Response([
        'ok'          => true,
        'project_id'  => $pid,
        'id'          => $message_id,
        'at'          => $now,
        'read_status' => $read_status,
        'read_times'  => $reads,
        'reads'       => [
            'roles' => $read_status,
            'times' => $reads,
        ],
    ],200);
}

function elxao_chat_rest_history(WP_REST_Request $r){
    global $wpdb; $t=$wpdb->prefix.'elxao_chat_messages';
    $pid=(int)$r['project_id']; $uid=get_current_user_id();
    if(!elxao_chat_user_can_access_project($pid,$uid))
        return new WP_Error('forbidden','Not allowed',['status'=>403]);

    $limit = min(200,max(1,(int)($r['limit']?:50)));

    $after_raw = $r->get_param('after');
    $after_id  = (int)$r->get_param('after_id');
    $after     = elxao_chat_normalize_datetime_value($after_raw);

    $where_clauses = ['project_id=%d'];
    $params = [$pid];

    if ($after) {
        if ($after_id > 0) {
            $where_clauses[] = '(published_at > %s OR (published_at = %s AND id > %d))';
            $params[] = $after;
            $params[] = $after;
            $params[] = $after_id;
        } else {
            $where_clauses[] = 'published_at > %s';
            $params[] = $after;
        }
    } elseif ($after_id > 0) {
        $where_clauses[] = 'id > %d';
        $params[] = $after_id;
    }

    $where_sql = implode(' AND ',$where_clauses);
    $params[] = $limit;

    $sql = "SELECT id,project_id,user_id,content,content_type,published_at
            FROM $t WHERE $where_sql ORDER BY published_at ASC,id ASC LIMIT %d";

    $rows = $wpdb->get_results($wpdb->prepare($sql,...$params),ARRAY_A);

    foreach($rows as &$row){
        $u=get_userdata($row['user_id']);
        $row['user_display']=$u?$u->display_name:'System';
        $row['user_id']=(int)$row['user_id'];
        $row['role']=elxao_chat_role_for_user($pid,(int)$row['user_id']); // client | pm | admin | other
    }
    unset($row);

    $role = elxao_chat_role_for_user($pid,$uid);
    $participants = elxao_chat_get_client_pm_ids($pid);
    $reads = elxao_chat_get_last_reads($pid);
    $updated_reads = false;

    if(in_array($role,['client','pm','admin'],true)){
        $maybe = elxao_chat_update_last_read($pid,$role,current_time('timestamp'));
        if($maybe){
            $reads = $maybe;
            $updated_reads = true;
        }
    }

    foreach($rows as &$row){
        $status = elxao_chat_build_message_read_status($pid,$row['role'],$row['published_at'],$reads,$participants);
        $row['read_status']=$status;
        $row['reads']=[
            'roles'=>$status,
            'times'=>$reads,
        ];
    }
    unset($row);

    if($updated_reads){
        elxao_chat_publish_read_receipt($pid,$reads,$uid);
    }

    return new WP_REST_Response([
        'items'=>$rows,
        'reads'=>$reads,
        'role'=>$role,
    ],200);
}

function elxao_chat_rest_mark_read(WP_REST_Request $r){
    $pid=(int)$r['project_id'];
    $uid=get_current_user_id();
    if(!elxao_chat_user_can_access_project($pid,$uid))
        return new WP_Error('forbidden','Not allowed',['status'=>403]);

    $role = elxao_chat_role_for_user($pid,$uid);
    $reads = elxao_chat_get_last_reads($pid);
    $participants = elxao_chat_get_client_pm_ids($pid);
    $updated = false;

    if(in_array($role,['client','pm','admin'],true)){
        $maybe = elxao_chat_update_last_read($pid,$role,current_time('timestamp'));
        if($maybe){
            $reads = $maybe;
            $updated = true;
        }
    }

    if($updated){
        elxao_chat_publish_read_receipt($pid,$reads,$uid);
    }

    $role_flags = [
        'client' => elxao_chat_parse_datetime($reads['client'] ?? '') > 0 || empty($participants['client']),
        'pm'     => elxao_chat_parse_datetime($reads['pm'] ?? '') > 0 || empty($participants['pm']),
        'admin'  => elxao_chat_parse_datetime($reads['admin'] ?? '') > 0,
    ];

    return new WP_REST_Response([
        'project'=>$pid,
        'role'=>$role,
        'reads'=>[
            'roles'=>$role_flags,
            'times'=>$reads,
        ],
        'read_times'=>$reads,
        'read_status'=>$role_flags,
        'updated'=>$updated,
        'type'=>'read_receipt',
        'user'=>$uid,
    ],200);
}

function elxao_chat_rest_token(WP_REST_Request $r){
    $pid=(int)$r['project_id']; $uid=get_current_user_id();
    if(!elxao_chat_user_can_access_project($pid,$uid))
        return new WP_Error('forbidden','Not allowed',['status'=>403]);

    $room=elxao_chat_room_id($pid);
    $token=elxao_chat_request_ably_token($uid,[$room=>['subscribe','presence']]);
    if(is_wp_error($token)) return $token;
    return new WP_REST_Response($token,200);
}

function elxao_chat_rest_inbox_token(WP_REST_Request $r){
    $uid=get_current_user_id();
    $ids=$r->get_param('project_ids');
    if(is_null($ids)) $ids=[];
    if(!is_array($ids)) $ids=[$ids];

    $ids=array_unique(array_map('intval',$ids));

    $capability=[];
    foreach($ids as $pid){
        if(!$pid) continue;
        if(!elxao_chat_user_can_access_project($pid,$uid)) continue;
        $room=elxao_chat_room_id($pid);
        $capability[$room]=['subscribe'];
    }

    if(empty($capability))
        return new WP_Error('no_rooms','No accessible rooms',['status'=>403]);

    $token=elxao_chat_request_ably_token($uid,$capability);
    if(is_wp_error($token)) return $token;
    return new WP_REST_Response($token,200);
}

function elxao_chat_rest_window(WP_REST_Request $r){
    $pid=(int)$r['project_id'];
    $uid=get_current_user_id();
    if(!elxao_chat_user_can_access_project($pid,$uid))
        return new WP_Error('forbidden','Not allowed',['status'=>403]);

    $html=elxao_chat_render_window($pid);
    return new WP_REST_Response(['html'=>$html],200);
}

/* ===========================================================
   Ably PUBLISHER
   =========================================================== */
function elxao_chat_publish_to_ably($chName,$payload){
    if ( ! defined('ELXAO_ABLY_KEY') || ! ELXAO_ABLY_KEY ) return false;
    $url='https://rest.ably.io/channels/'.rawurlencode($chName).'/messages';
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Basic '.base64_encode(ELXAO_ABLY_KEY)],
        CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>wp_json_encode($payload),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10
    ]);
    curl_exec($ch); curl_close($ch);
}

/* ===========================================================
   CORE CHAT WINDOW (loop card + inbox right pane) — ROLE COLORS
   =========================================================== */
function elxao_chat_render_window($pid){
    if(!is_user_logged_in())return'<div>Please log in.</div>';
    if(!$pid)return'<div>Missing project_id</div>';

    $room       = elxao_chat_room_id($pid);
    $rest_nonce = wp_create_nonce('wp_rest');
    $rest_base  = rest_url();
    $me         = wp_get_current_user();
    $meName     = esc_js($me->display_name);
    $meId       = (int)$me->ID;

    $ids = elxao_chat_get_client_pm_ids($pid);
    $myRole = elxao_chat_role_for_user($pid,$meId);

    // CSS variables from hard-coded constants
    $style_vars = sprintf(
        '--chat-color:%s; --chat-client:%s; --chat-pm:%s; --chat-admin:%s; --chat-sys:%s; --chat-read-unread:%s; --chat-read-read:%s; --chat-read-client:%s; --chat-read-pm:%s;',
        esc_attr(ELXAO_CHAT_COLOR_BASE),
        esc_attr(ELXAO_CHAT_COLOR_CLIENT),
        esc_attr(ELXAO_CHAT_COLOR_PM),
        esc_attr(ELXAO_CHAT_COLOR_ADMIN),
        esc_attr(ELXAO_CHAT_COLOR_SYS),
        esc_attr(ELXAO_CHAT_COLOR_READ_UNREAD),
        esc_attr(ELXAO_CHAT_COLOR_READ_ALL),
        esc_attr(ELXAO_CHAT_COLOR_READ_CLIENT_ONLY),
        esc_attr(ELXAO_CHAT_COLOR_READ_PM_ONLY)
    );

ob_start();?>
<div id="elxao-chat-<?php echo $pid;?>"
     class="elxao-chat"
     data-project="<?php echo $pid;?>"
     data-room="<?php echo esc_attr($room);?>"
     data-rest="<?php echo esc_url($rest_base);?>"
     data-nonce="<?php echo esc_attr($rest_nonce);?>"
     data-myid="<?php echo (int)$meId; ?>"
     data-myrole="<?php echo esc_attr($myRole); ?>"
     data-client="<?php echo (int)$ids['client'];?>"
     data-pm="<?php echo (int)$ids['pm'];?>"
     style="<?php echo $style_vars; ?>">
  <div class="list" aria-live="polite"></div>
  <div class="composer">
    <textarea rows="2" placeholder="Type your message..."></textarea>
    <button class="send" type="button" aria-label="Send message" title="Send">
      <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
        <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
      </svg>
    </button>
  </div>
</div>
<style>
#elxao-chat-<?php echo $pid;?>{
  border:1px solid #4b5563;border-radius:12px;
  display:flex;flex-direction:column;height:460px;
  font:14px/1.45 system-ui;background:transparent;
  color:var(--chat-color);
}
#elxao-chat-<?php echo $pid;?> .list{flex:1;overflow:auto;padding:12px;color:inherit}
#elxao-chat-<?php echo $pid;?> .sys    { color:var(--chat-sys);opacity:.9 }
#elxao-chat-<?php echo $pid;?> .client { color:var(--chat-client) }
#elxao-chat-<?php echo $pid;?> .pm     { color:var(--chat-pm) }
#elxao-chat-<?php echo $pid;?> .admin  { color:var(--chat-admin) }
#elxao-chat-<?php echo $pid;?> .chat-line{display:flex;gap:8px;align-items:flex-start;margin-bottom:8px}
#elxao-chat-<?php echo $pid;?> .chat-line:last-child{margin-bottom:0}
#elxao-chat-<?php echo $pid;?> .chat-line .chat-text{flex:1;color:inherit;word-break:break-word;white-space:pre-wrap}
#elxao-chat-<?php echo $pid;?> .chat-read-indicator{width:10px;height:10px;border-radius:999px;background:var(--chat-read-unread);margin-top:6px;flex:0 0 10px;box-shadow:0 0 0 1px rgba(0,0,0,0.35)}
#elxao-chat-<?php echo $pid;?> .chat-read-indicator.chat-read-indicator--unread{background:var(--chat-read-unread)}
#elxao-chat-<?php echo $pid;?> .chat-read-indicator.is-hidden{opacity:0;visibility:hidden}
#elxao-chat-<?php echo $pid;?> .chat-read-indicator.chat-read-indicator--read{background:var(--chat-read-read)}
#elxao-chat-<?php echo $pid;?> .chat-read-indicator.chat-read-indicator--client{background:var(--chat-read-client)}
#elxao-chat-<?php echo $pid;?> .chat-read-indicator.chat-read-indicator--pm{background:var(--chat-read-pm)}
#elxao-chat-<?php echo $pid;?> .composer{display:flex;gap:8px;border-top:1px solid #4b5563;padding:10px}
#elxao-chat-<?php echo $pid;?> textarea{flex:1;resize:none;background:transparent;border:1px solid #6b7280;border-radius:8px;padding:10px;color:inherit}
#elxao-chat-<?php echo $pid;?> .send{display:inline-flex;align-items:center;justify-content:center;border:1px solid #6b7280;border-radius:10px;padding:0 10px;background:transparent;cursor:pointer;min-width:44px;color:inherit}
#elxao-chat-<?php echo $pid;?> .send:hover{background:rgba(255,255,255,.08);border-color:#9ca3af}
#elxao-chat-<?php echo $pid;?> .send:disabled{opacity:.5;cursor:not-allowed}
</style>
<script>
(function(){
const root=document.getElementById('elxao-chat-<?php echo $pid;?>'); if(!root) return;
const list=root.querySelector('.list');
const ta=root.querySelector('textarea');
const btn=root.querySelector('.send');
const pid=root.dataset.project,room=root.dataset.room,rest=root.dataset.rest,nonce=root.dataset.nonce;
const myId=parseInt(root.dataset.myid,10)||0;
const myRole=(root.dataset.myrole||'other');
const hdr={'X-WP-Nonce':nonce};
const projectId=parseInt(pid,10)||0;
const clientId=parseInt(root.dataset.client,10)||0;
const pmId=parseInt(root.dataset.pm,10)||0;
const localEchoes=new Map();
let realtimeCleanup=null;
let busCleanup=null;
let readSyncTimer=null;
let readSyncPending=false;
let readSyncInFlight=false;
let isRenderingHistory=false;
let latestAt='';
let latestId=0;
let fallbackActive=false;
let fallbackTimer=null;
let fallbackInFlight=false;
const FALLBACK_INTERVAL=4000;
const currentReadTimes={client:'',pm:'',admin:''};
const DEBUG=false;

/* ---------- Shared helpers (guarded singletons) ---------- */
if(!window.ELXAO_CHAT_BUS){
  (function(){
    const origin=Math.random().toString(36).slice(2);
    let channel=null;
    if('BroadcastChannel' in window){ try{ channel=new BroadcastChannel('elxao-chat'); }catch(e){} }
    const state={
      origin, channel,
      emit(detail){
        const msg=Object.assign({},detail,{originId:origin});
        if(channel){ try{ channel.postMessage(msg); }catch(e){} }
        window.dispatchEvent(new CustomEvent('elxao:chat',{detail:msg}));
      }
    };
    if(channel){ window.addEventListener('beforeunload',()=>{ try{ channel.close(); }catch(e){} },{once:true}); }
    window.ELXAO_CHAT_BUS=state;
  })();
}
if(!window.ELXAO_CHAT_RESOLVE_PROJECT_ID){
  window.ELXAO_CHAT_RESOLVE_PROJECT_ID=function(){
    const args=Array.prototype.slice.call(arguments);
    const parseValue=function(value){
      if(value===undefined||value===null) return 0;
      if(typeof value==='number' && isFinite(value)){ const num=value<0?-value:value; return num>0?Math.floor(num):0; }
      if(typeof value==='string'){
        const trimmed=value.trim();
        if(!trimmed) return 0;
        if(/^-?\d+$/.test(trimmed)) return Math.abs(parseInt(trimmed,10));
        const match=trimmed.match(/(-?\d+)/);
        return match?Math.abs(parseInt(match[1],10)):0;
      }
      if(Array.isArray(value)){
        for(let i=0;i<value.length;i++){ const result=parseValue(value[i]); if(result) return result; }
        return 0;
      }
      if(typeof value==='object'){
        const keys=['project','project_id','projectId','projectID','id','room','room_id','roomId'];
        for(let i=0;i<keys.length;i++){
          const key=keys[i];
          if(Object.prototype.hasOwnProperty.call(value,key)){
            const result=parseValue(value[key]);
            if(result) return result;
          }
        }
      }
      return 0;
    };
    for(let i=0;i<args.length;i++){
      const resolved=parseValue(args[i]);
      if(resolved) return resolved;
    }
    return 0;
  };
}
if(!window.ELXAO_CHAT_BUILD_NORMALIZER){
  window.ELXAO_CHAT_BUILD_NORMALIZER=function(){
    const roles=new Set(['client','pm','admin','other','sys']);
    const fd=(...a)=>a.find(v=>v!==undefined&&v!==null);
    const toISO=v=>!v&&v!==0?new Date().toISOString():(v instanceof Date?v.toISOString():(typeof v==='number'?new Date(v).toISOString():String(v)));
    const canonicalRole=function(role){
      if(role===undefined||role===null) return '';
      let str=String(role).trim().toLowerCase();
      if(!str) return '';
      str=str.replace(/\s+/g,'_').replace(/-+/g,'_');
      if(str==='project_manager'||str==='projectmanager'||str==='manager'||str==='pm_user'||str==='pmid'||str==='pm') return 'pm';
      if(str==='customer'||str==='client_user'||str==='clientid'||str==='customer_user'||str==='client') return 'client';
      if(str==='administrator'||str==='admin_user'||str==='adminid'||str==='admin') return 'admin';
      return str;
    };
    const boolish=function(value){
      if(value===undefined||value===null) return false;
      if(typeof value==='boolean') return value;
      if(typeof value==='number') return value>0;
      if(typeof value==='string'){
        const str=value.trim().toLowerCase();
        if(!str) return false;
        if(str==='false'||str==='0'||str==='no'||str==='off'||str==='null'||str==='undefined') return false;
        return true;
      }
      if(value instanceof Date) return true;
      if(typeof value==='object'){
        if('read' in value) return boolish(value.read);
        if('value' in value) return boolish(value.value);
        if('state' in value) return boolish(value.state);
        if('status' in value) return boolish(value.status);
        if('seen' in value) return boolish(value.seen);
        if('at' in value) return boolish(value.at);
        if('timestamp' in value) return boolish(value.timestamp);
      }
      return !!value;
    };
    const mergeRole=function(map,role,value){
      const key=canonicalRole(role);
      if(!key) return;
      const bool=boolish(value);
      if(!(key in map)) map[key]=bool;
      else map[key]=map[key]||bool;
    };
    const collectSource=function(source,userSet,roleMap){
      if(source===undefined||source===null) return;
      if(Array.isArray(source)){
        source.forEach(item=>collectSource(item,userSet,roleMap));
        return;
      }
      if(typeof source==='object'){
        if(source){
          if('role' in source && source.role!==undefined && 'value' in source) mergeRole(roleMap,source.role,source.value);
          if(source.roles && typeof source.roles==='object') Object.entries(source.roles).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.byRole && typeof source.byRole==='object') Object.entries(source.byRole).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.read_roles && typeof source.read_roles==='object') Object.entries(source.read_roles).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.readRoles && typeof source.readRoles==='object') Object.entries(source.readRoles).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.read_status && typeof source.read_status==='object') Object.entries(source.read_status).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.readStatus && typeof source.readStatus==='object') Object.entries(source.readStatus).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.status && typeof source.status==='object') Object.entries(source.status).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.client!==undefined) mergeRole(roleMap,'client',source.client);
          if(source.customer!==undefined) mergeRole(roleMap,'client',source.customer);
          if(source.pm!==undefined) mergeRole(roleMap,'pm',source.pm);
          if(source.project_manager!==undefined) mergeRole(roleMap,'pm',source.project_manager);
          if(source.manager!==undefined) mergeRole(roleMap,'pm',source.manager);
          if(source.admin!==undefined) mergeRole(roleMap,'admin',source.admin);
          if(source.administrator!==undefined) mergeRole(roleMap,'admin',source.administrator);
          if('id' in source) collectSource(source.id,userSet,roleMap);
          if('user_id' in source) collectSource(source.user_id,userSet,roleMap);
          if('user' in source) collectSource(source.user,userSet,roleMap);
          if('users' in source) collectSource(source.users,userSet,roleMap);
          if('user_ids' in source) collectSource(source.user_ids,userSet,roleMap);
          if('ids' in source) collectSource(source.ids,userSet,roleMap);
          if('readers' in source) collectSource(source.readers,userSet,roleMap);
          if('entries' in source) collectSource(source.entries,userSet,roleMap);
          if('list' in source) collectSource(source.list,userSet,roleMap);
          if('byUser' in source) collectSource(source.byUser,userSet,roleMap);
          Object.keys(source).forEach(key=>{ if(/^\d+$/.test(key)) collectSource(parseInt(key,10),userSet,roleMap); });
        }
        return;
      }
      const num=Number(source);
      if(Number.isFinite(num) && num>0) userSet.add(num);
    };
    const directRoleKeys={
      client:['client_read','read_client','client_seen','seen_client','client_read_at','client_seen_at','read_by_client','clientRead','readClient','clientViewed','client_viewed','client_seenAt','seen_by_client','client_has_read','clientReadAt'],
      pm:['pm_read','read_pm','pm_seen','seen_pm','pm_read_at','pm_seen_at','read_by_pm','pmRead','readPm','pmViewed','pm_viewed','pm_seenAt','seen_by_pm','project_manager_read','read_project_manager','project_manager_seen','seen_project_manager','project_manager_read_at','project_manager_seen_at','manager_read','read_manager','manager_seen','seen_manager'],
      admin:['admin_read','read_admin','admin_seen','seen_admin','admin_read_at','admin_seen_at','read_by_admin','adminRead','readAdmin','adminViewed','admin_viewed','admin_seenAt','seen_by_admin','administrator_read','read_administrator','administrator_seen','seen_administrator']
    };
    return function(data,fallbackProject){
      const s=(data&&typeof data==='object')?data:{};
      const p=Number(fd(s.project,s.project_id,fallbackProject,0))||0;
      const type=String(fd(s.type,s.content_type,'text')||'text');
      const msg=String(fd(s.message,s.content,'')||'');
      const user=Number(fd(s.user,s.user_id,0))||0;
      const display=String(fd(s.user_display,s.userDisplay,s.user_name,s.username,user?('User '+user):'User'));
      let role=(s.role||'').toString().toLowerCase();
      if(!roles.has(role)) role=(type==='system')?'sys':'other';
      const at=toISO(fd(s.at,s.published_at,s.created_at,null));
      const result=Object.assign({},s,{type:type,message:msg,project:p,user:user,user_display:display,role:role,at:at});
      const readUsers=new Set();
      const roleFlags={};
      collectSource(s.reads,readUsers,roleFlags);
      collectSource(s.read_receipts,readUsers,roleFlags);
      collectSource(s.readReceipts,readUsers,roleFlags);
      collectSource(s.read_status,readUsers,roleFlags);
      collectSource(s.readStatus,readUsers,roleFlags);
      collectSource(s.readBy,readUsers,roleFlags);
      collectSource(s.read_by,readUsers,roleFlags);
      collectSource(s.readers,readUsers,roleFlags);
      collectSource(s.receipts,readUsers,roleFlags);
      collectSource(s.seen_by,readUsers,roleFlags);
      collectSource(s.seenBy,readUsers,roleFlags);
      collectSource(s.seen_status,readUsers,roleFlags);
      ['read_by','readBy','readers','read_receipts','readReceipts','read_entries','readEntries','seen_by','seenBy'].forEach(key=>{ if(key in s) collectSource(s[key],readUsers,roleFlags); });
      Object.entries(directRoleKeys).forEach(([roleKey,keys])=>{ keys.forEach(key=>{ if(Object.prototype.hasOwnProperty.call(s,key)) mergeRole(roleFlags,roleKey,s[key]); }); });
      const normalizedReads={ users:Array.from(readUsers), roles:{} };
      Object.entries(roleFlags).forEach(([k,v])=>{ normalizedReads.roles[k]=!!v; });
      if(s.reads && typeof s.reads==='object' && !Array.isArray(s.reads)) normalizedReads.raw=s.reads;
      result.reads=normalizedReads;
      return result;
    };
  };
}
if(!window.ELXAO_CHAT_NORMALIZE){
  window.ELXAO_CHAT_NORMALIZE=window.ELXAO_CHAT_BUILD_NORMALIZER();
}
if(!window.ELXAO_CHAT_BROADCAST){
  window.ELXAO_CHAT_BROADCAST=function(projectHint,data,extras){
    let source=data;
    if(typeof source==='string'){
      const trimmed=source.trim();
      if(trimmed){
        try{ source=JSON.parse(trimmed); }
        catch(e){ source={message:source}; }
      } else {
        source={};
      }
    }
    const payload=window.ELXAO_CHAT_NORMALIZE(source,projectHint);
    if(!payload.project && projectHint) payload.project=Number(projectHint)||0;
    const detail=Object.assign({project:payload.project,payload:payload},(extras&&typeof extras==='object')?extras:{});
    window.ELXAO_CHAT_BUS.emit(detail);
    if(payload.project){
      const payloadType=(payload.type||'').toString().toLowerCase();
      if(payloadType!=='read_receipt'){
        const bumpAt=payload.at||Date.now();
        window.dispatchEvent(new CustomEvent('elxao:room-bump',{detail:{projectId:payload.project,at:bumpAt}}));
      }
    }
    return payload;
  };
}
if(!window.ELXAO_ABLY){
  window.ELXAO_ABLY={client:null,clientPromise:null,libraryPromise:null,channels:new Map()};
  window.ELXAO_ABLY.loadLibrary=function(){
    const st=window.ELXAO_ABLY;
    if(st.libraryPromise) return st.libraryPromise;
    if(window.Ably&&window.Ably.Realtime&&window.Ably.Realtime.Promise){ st.libraryPromise=Promise.resolve(); return st.libraryPromise; }
    st.libraryPromise=new Promise((res,rej)=>{
      const ex=document.querySelector('script[data-elxao-ably]');
      if(ex){ ex.addEventListener('load',()=>res(),{once:true}); ex.addEventListener('error',()=>rej(new Error('Ably load failed')),{once:true}); return; }
      const s=document.createElement('script'); s.src='https://cdn.ably.io/lib/ably.min-1.js'; s.async=true; s.setAttribute('data-elxao-ably','1');
      s.onload=()=>res(); s.onerror=()=>rej(new Error('Ably load failed')); document.head.appendChild(s);
    });
    return st.libraryPromise;
  };
  window.ELXAO_ABLY.ensureClient=function(tokenDetails){
    const st=window.ELXAO_ABLY;
    if(st.clientPromise){
      return st.clientPromise.then(client=>{
        if(tokenDetails){ return client.auth.authorize(null,{tokenDetails}).then(()=>client); }
        return client;
      });
    }
    st.clientPromise=st.loadLibrary().then(()=>{
      if(!window.Ably||!window.Ably.Realtime||!window.Ably.Realtime.Promise) throw new Error('Ably unavailable');
      st.client=new window.Ably.Realtime.Promise({tokenDetails});
      return st.client;
    }).catch(err=>{ st.client=null; st.clientPromise=null; throw err; });
    return st.clientPromise;
  };
  window.ELXAO_ABLY.registerChannel=function(client,channelName,project,onMessage){
    if(!channelName) return function(){};
    const st=window.ELXAO_ABLY; const map=st.channels;
    let entry=map.get(channelName);
    if(!entry){ entry={refCount:0,handler:null,channel:client.channels.get(channelName),projectId:project,callbacks:new Set()}; map.set(channelName,entry); }
    else {
      if(!entry.channel) entry.channel=client.channels.get(channelName);
      if(project && !entry.projectId) entry.projectId=project;
      if(!entry.callbacks) entry.callbacks=new Set();
    }
    if(onMessage && typeof onMessage==='function') entry.callbacks.add(onMessage);
    entry.refCount++;
    if(!entry.handler){
      entry.handler=function(msg){
        let data=(msg&&msg.data)?msg.data:{};
        if(typeof data==='string'){
          const trimmed=data.trim();
          if(trimmed){
            try{ data=JSON.parse(trimmed); }
            catch(e){ data={message:data}; }
          } else {
            data={};
          }
        }
        const resolve=window.ELXAO_CHAT_RESOLVE_PROJECT_ID;
        const hintedProject=resolve(
          data && typeof data==='object' ? data.project : (data&&data.project),
          data && typeof data==='object' ? data.project_id : undefined,
          data && typeof data==='object' ? data.projectId : undefined,
          data && typeof data==='object' ? data.projectID : undefined,
          entry && entry.projectId,
          project
        );
        const projectId=hintedProject || resolve(entry && entry.projectId, project);
        const extras={}; if(msg && 'id' in msg) extras.id=msg.id; if(msg && 'name' in msg) extras.name=msg.name;
        let payload=data;
        if(payload && typeof payload==='object' && !Array.isArray(payload)){
          if(projectId && resolve(payload.project)!==projectId){
            payload=Object.assign({},payload,{project:projectId});
          } else if(projectId && !('project' in payload)){
            payload=Object.assign({},payload,{project:projectId});
          }
        }
        const normalizer=window.ELXAO_CHAT_NORMALIZE||null;
        const normalized=normalizer?normalizer(payload,projectId):payload;
        if(entry.callbacks && entry.callbacks.size){
          entry.callbacks.forEach(function(cb){ try{ cb(normalized,msg,extras); }catch(e){} });
        }
        window.ELXAO_CHAT_BROADCAST(projectId,normalized,extras);
      };
      if(entry.channel){
        entry.channel.attach()
          .then(()=>entry.channel.subscribe(entry.handler))
          .catch(()=>{});
      }
    }
    else if(entry.channel){
      entry.channel.attach().catch(()=>{});
    }
    return function(){
      if(onMessage && entry.callbacks) entry.callbacks.delete(onMessage);
      entry.refCount--;
      if(entry.refCount<=0){ if(entry.channel&&entry.handler) entry.channel.unsubscribe(entry.handler); map.delete(channelName); }
    };
  };
}
/* ---------- end helpers ---------- */

function normalizeContent(value){ return String(value||'').replace(/<[^>]*?>/g,''); }
function createTextFragment(text){
  const fragment=document.createDocumentFragment();
  String(text||'').split('\n').forEach((part,index)=>{
    if(index) fragment.appendChild(document.createElement('br'));
    fragment.appendChild(document.createTextNode(part));
  });
  return fragment;
}
function extractReadTimes(source){
  if(!source || typeof source!=='object') return null;
  if(source.read_times && typeof source.read_times==='object') return source.read_times;
  if(source.readTimes && typeof source.readTimes==='object') return source.readTimes;
  if(source.reads && typeof source.reads==='object'){
    if(source.reads.times && typeof source.reads.times==='object') return source.reads.times;
    if('client' in source.reads || 'pm' in source.reads || 'admin' in source.reads) return source.reads;
  }
  return null;
}
function updateCurrentReadTimes(times){
  if(!times || typeof times!=='object') return;
  ['client','pm','admin'].forEach(function(role){
    if(Object.prototype.hasOwnProperty.call(times,role)){
      const value=times[role];
      currentReadTimes[role]=(value!==undefined && value!==null)?value:'';
    }
  });
}
function updateLatestFromPayload(data){
  if(!data || typeof data!=='object') return;
  const stampRaw=data.at||data.published_at||data.created_at||data.timestamp||'';
  const stamp=stampRaw?String(stampRaw):'';
  const idValue=('id' in data)?data.id:('message_id' in data?data.message_id:null);
  const numericId=(idValue!==undefined&&idValue!==null)?parseInt(idValue,10):0;
  const shouldUpdate=function(currentStamp,currentId,newStamp,newId){
    if(!currentStamp && !currentId) return true;
    if(newStamp && !currentStamp) return true;
    if(newStamp && currentStamp && newStamp>currentStamp) return true;
    if(newStamp && currentStamp && newStamp===currentStamp && newId>currentId) return true;
    if(!newStamp && newId>currentId) return true;
    return false;
  };
  if(shouldUpdate(latestAt,latestId,stamp,numericId)){
    if(stamp) latestAt=stamp;
    if(numericId>0) latestId=numericId;
  }
}
function parseReadTime(value){
  if(value===undefined || value===null || value==='') return 0;
  if(value instanceof Date) return value.getTime();
  if(typeof value==='number'){
    const num=value;
    return num>1e12?num:Math.round(num*1000);
  }
  const str=String(value).trim();
  if(!str) return 0;
  if(/^-?\d+$/.test(str)){
    const num=parseInt(str,10);
    return str.length<=10?num*1000:num;
  }
  const normalized=str.replace(' ','T');
  const date=new Date(normalized);
  const ms=date.getTime();
  return Number.isFinite(ms)?ms:0;
}
function buildStatusFromTimes(payload,times){
  const status={client:false,pm:false,admin:false};
  const role=(payload&&payload.role)||'other';
  if(role==='client') status.client=true;
  if(role==='pm') status.pm=true;
  if(role==='admin') status.admin=true;
  if(!clientId) status.client=true;
  if(!pmId) status.pm=true;
  const messageTime= parseReadTime(payload&&payload.at) || parseReadTime(payload&&payload.published_at) || parseReadTime(payload&&payload.created_at);
  const pickTime=function(map,keys){
    if(!map || typeof map!=='object') return undefined;
    for(let i=0;i<keys.length;i++){
      const key=keys[i];
      if(Object.prototype.hasOwnProperty.call(map,key)) return map[key];
    }
    return undefined;
  };
  const clientValue=pickTime(times,['client','customer']);
  const pmValue=pickTime(times,['pm','manager','project_manager']);
  const adminValue=pickTime(times,['admin','administrator']);
  const clientTime=parseReadTime(clientValue);
  const pmTime=parseReadTime(pmValue);
  const adminTime=parseReadTime(adminValue);
  if(messageTime){
    if(clientTime && clientTime>=messageTime) status.client=true;
    if(pmTime && pmTime>=messageTime) status.pm=true;
    if(adminTime && adminTime>=messageTime) status.admin=true;
  }
  return status;
}
function applyIndicatorState(indicator,info){
  indicator.className='chat-read-indicator';
  indicator.removeAttribute('aria-hidden');
  indicator.removeAttribute('aria-label');
  indicator.removeAttribute('title');
  indicator.removeAttribute('role');
  if(!info){
    indicator.classList.add('is-hidden');
    indicator.setAttribute('aria-hidden','true');
    return;
  }
  const classes=(info.className||'').split(/\s+/).filter(Boolean);
  classes.forEach(cls=>indicator.classList.add(cls));
  indicator.setAttribute('role','img');
  const label=info.label||'';
  if(label){
    indicator.setAttribute('aria-label',label);
  }
  const title=info.title||label||'';
  if(title){
    indicator.title=title;
  }
}
function buildReadState(data){
  const reads=(data&&data.reads&&typeof data.reads==='object')?data.reads:{};
  const roleMap=(reads.roles&&typeof reads.roles==='object')?reads.roles:{};
  const userSet=new Set();
  const addUser=function(value){
    if(value===undefined||value===null) return;
    if(Array.isArray(value)){ value.forEach(addUser); return; }
    if(typeof value==='object'){
      if('id' in value) addUser(value.id);
      if('user_id' in value) addUser(value.user_id);
      if('user' in value) addUser(value.user);
      if(Array.isArray(value.users)) addUser(value.users);
      if(Array.isArray(value.user_ids)) addUser(value.user_ids);
      if(Array.isArray(value.ids)) addUser(value.ids);
      return;
    }
    const num=parseInt(value,10);
    if(!Number.isNaN(num) && num>0) userSet.add(num);
  };
  if(Array.isArray(reads.users)) addUser(reads.users);
  if(Array.isArray(reads.user_ids)) addUser(reads.user_ids);
  if(Array.isArray(reads.ids)) addUser(reads.ids);
  [data&&data.read_by,data&&data.readBy,data&&data.readers,data&&data.read_receipts,data&&data.readReceipts].forEach(src=>{ if(src) addUser(src); });
  const roleValue=function(keys){
    for(const key of keys){
      if(key && Object.prototype.hasOwnProperty.call(roleMap,key)) return !!roleMap[key];
    }
    return undefined;
  };
  const resolved={
    client:roleValue(['client','customer']),
    pm:roleValue(['pm','project_manager','manager']),
    admin:roleValue(['admin','administrator'])
  };
  const fallbackIds={ client:clientId, pm:pmId, admin:(myRole==='admin'&&myId)?myId:0 };
  Object.keys(fallbackIds).forEach(role=>{
    if(resolved[role]===undefined && fallbackIds[role]) resolved[role]=userSet.has(fallbackIds[role]);
  });
  Object.keys(resolved).forEach(role=>{ resolved[role]=!!resolved[role]; });
  return resolved;
}
function determineIndicator(data,role){
  if(role==='sys') return null;
  const reads=buildReadState(data);
  const clientRead=!!reads.client;
  const pmRead=!!reads.pm;
  const viewerRole=(myRole||'other');
  if(role==='client'){
    return {className:pmRead?'chat-read-indicator--read':'chat-read-indicator--unread',label:pmRead?'Read by PM':'Unread by PM'};
  }
  if(role==='pm'){
    return {className:clientRead?'chat-read-indicator--read':'chat-read-indicator--unread',label:clientRead?'Read by client':'Unread by client'};
  }
  if(role==='admin'){
    if(viewerRole==='admin'){
      if(clientRead && pmRead) return {className:'chat-read-indicator--read',label:'Read by client and PM'};
      if(pmRead && !clientRead) return {className:'chat-read-indicator--pm',label:'Read by PM'};
      if(clientRead && !pmRead) return {className:'chat-read-indicator--client',label:'Read by client'};
      return {className:'chat-read-indicator--unread',label:'Unread by client and PM'};
    }
    if(viewerRole==='client'){
      return {className:clientRead?'chat-read-indicator--read':'chat-read-indicator--unread',label:clientRead?'Read by client':'Unread by client'};
    }
    if(viewerRole==='pm'){
      return {className:pmRead?'chat-read-indicator--read':'chat-read-indicator--unread',label:pmRead?'Read by PM':'Unread by PM'};
    }
  }
  const anyRead=clientRead||pmRead;
  return {className:anyRead?'chat-read-indicator--read':'chat-read-indicator--unread',label:anyRead?'Read':'Unread'};
}
function applyReadReceipt(data){
  const times=extractReadTimes(data);
  if(times) updateCurrentReadTimes(times);
  const effectiveTimes={
    client:currentReadTimes.client,
    pm:currentReadTimes.pm,
    admin:currentReadTimes.admin
  };
  if(!list) return;
  Array.from(list.children||[]).forEach(function(line){
    if(!line || !line.__chatPayload) return;
    const payload=line.__chatPayload;
    const status=buildStatusFromTimes(payload,effectiveTimes);
    payload.read_status=Object.assign({},status);
    if(!payload.reads || typeof payload.reads!=='object') payload.reads={};
    payload.reads.roles=Object.assign({},status);
    payload.reads.times=Object.assign({},effectiveTimes);
    const indicator=line.querySelector('.chat-read-indicator');
    if(indicator) applyIndicatorState(indicator,determineIndicator(payload,payload.role||line.dataset.role||'other'));
  });
}
function sendReadSync(){
  if(readSyncInFlight) return;
  const targetProject=projectId||parseInt(pid,10)||0;
  if(!targetProject || !rest || !myId) return;
  readSyncPending=false;
  readSyncInFlight=true;
  fetch(rest+'elxao/v1/messages/read',{
    method:'POST',
    credentials:'same-origin',
    headers:Object.assign({'Content-Type':'application/json'},hdr),
    body:JSON.stringify({project_id:targetProject})
  }).then(r=>{ if(!r.ok) throw new Error(''+r.status); return r.json(); })
    .then(resp=>{
      if(!resp) return;
      if(!resp.project) resp.project=targetProject;
      if(!resp.type) resp.type='read_receipt';
      if(!resp.user) resp.user=myId;
      window.ELXAO_CHAT_BROADCAST(resp.project,resp);
    })
    .catch(()=>{})
    .finally(()=>{
      readSyncInFlight=false;
      if(readSyncPending){
        scheduleReadSync();
      }
    });
}
function scheduleReadSync(){
  if(!projectId || !rest || !myId) return;
  if(myRole==='other') return;
  readSyncPending=true;
  if(readSyncInFlight) return;
  if(readSyncTimer) return;
  readSyncTimer=setTimeout(()=>{
    readSyncTimer=null;
    if(readSyncPending) sendReadSync();
  },400);
}
function appendChatLine(data){
  if(!data) return;
  const times=extractReadTimes(data);
  if(times) updateCurrentReadTimes(times);
  const effectiveTimes={
    client:currentReadTimes.client,
    pm:currentReadTimes.pm,
    admin:currentReadTimes.admin
  };
  if(!data.read_status || typeof data.read_status!=='object'){
    const computed=buildStatusFromTimes(data,effectiveTimes);
    data.read_status=Object.assign({},computed);
    if(!data.reads || typeof data.reads!=='object') data.reads={};
    data.reads.roles=Object.assign({},computed);
    data.reads.times=Object.assign({},effectiveTimes);
  } else {
    if(!data.reads || typeof data.reads!=='object') data.reads={};
    if(!data.reads.roles || typeof data.reads.roles!=='object') data.reads.roles=Object.assign({},data.read_status);
    if(times) data.reads.times=Object.assign({},times);
    else if(!data.reads.times) data.reads.times=Object.assign({},effectiveTimes);
  }
  const role=(data.type==='system')?'sys':(data.role||'other');
  data.role=role;
  const messageText=normalizeContent(data.message);
  if(!messageText && role!=='sys') return;
  const display=data.user_display||('User '+(data.user||''));
  const fullText=(role==='sys')?messageText:(display+': '+messageText);
  const line=document.createElement('div');
  line.className='chat-line '+role;
  const indicator=document.createElement('span');
  const indicatorInfo=determineIndicator(data,role);
  applyIndicatorState(indicator,indicatorInfo);
  line.appendChild(indicator);
  const textNode=document.createElement('div');
  textNode.className='chat-text';
  textNode.appendChild(createTextFragment(fullText));
  line.appendChild(textNode);
  line.__chatPayload=data;
  line.dataset.role=role;
  const stamp=data.at||data.published_at||data.created_at||'';
  if(stamp) line.dataset.at=String(stamp);
  else delete line.dataset.at;
  if('id' in data && data.id!==undefined && data.id!==null) line.dataset.messageId=String(data.id);
  if('user' in data && data.user!==undefined && data.user!==null) line.dataset.user=String(data.user);
  list.appendChild(line);
  list.scrollTop=list.scrollHeight;
  updateLatestFromPayload(data);
  if(!isRenderingHistory && role!=='sys' && (data.user||0)!==myId){
    scheduleReadSync();
  }
}
function fingerprint(project,user,content){
  if(!project||!user) return '';
  const normalized=normalizeContent(content).replace(/\r\n?/g,'\n').trim();
  return normalized ? (project+'|'+user+'|'+normalized) : '';
}
function rememberLocal(fp){
  if(!fp) return; const now=Date.now(); localEchoes.set(fp,now);
  const cutoff=now-5000; localEchoes.forEach((ts,key)=>{ if(ts<cutoff) localEchoes.delete(key); });
}
function isRecentLocal(fp){
  if(!fp||!localEchoes.has(fp)) return false; const ts=localEchoes.get(fp);
  localEchoes.delete(fp); return (Date.now()-ts<=5000);
}

function load(params){
  const query=['project_id='+encodeURIComponent(pid||'')];
  if(params && params.after){ query.push('after='+encodeURIComponent(params.after)); }
  if(params && params.after_id){ query.push('after_id='+encodeURIComponent(params.after_id)); }
  if(params && params.limit){ query.push('limit='+encodeURIComponent(params.limit)); }
  const url=rest+'elxao/v1/messages?'+query.join('&');
  return fetch(url,{credentials:'same-origin',headers:hdr}).then(r=>r.json());
}
function token(){ return fetch(rest+'elxao/v1/chat-token?project_id='+pid,{credentials:'same-origin',headers:hdr}).then(r=>r.json()); }
function send(content){
  return fetch(rest+'elxao/v1/messages',{ method:'POST', credentials:'same-origin',
    headers:Object.assign({'Content-Type':'application/json'},hdr),
    body:JSON.stringify({project_id:projectId||parseInt(pid,10),content:content})
  }).then(r=>r.json());
}

function handleChatPayload(payload){
  if(!payload) return;
  const data=window.ELXAO_CHAT_NORMALIZE(payload,projectId);
  if(!data) return;
  if(data.type==='read_receipt'){
    applyReadReceipt(data);
    return;
  }
  appendChatLine(data);
}

function handleRealtimePayload(payload){
  if(DEBUG) console.log('[ELXAO realtime] incoming',payload);
  if(!payload) return;
  const data=window.ELXAO_CHAT_NORMALIZE(payload,projectId);
  if(!data) return;
  if(data.project && data.project!==projectId) return;
  if(data.type==='read_receipt'){
    applyReadReceipt(data);
    return;
  }
  const fp=fingerprint(projectId,data.user||0,data.message||'');
  if(fp && isRecentLocal(fp)){ rememberLocal(fp); return; }
  if(fp) rememberLocal(fp);
  appendChatLine(data);
}

function onChatEvent(ev){
  const detail=ev&&ev.detail?ev.detail:{}; const project=detail.project?parseInt(detail.project,10):0;
  if(project!==projectId) return;
  const payload=window.ELXAO_CHAT_NORMALIZE(detail.payload||{},projectId);
  const fp=fingerprint(projectId,payload.user||0,payload.message||''); if(isRecentLocal(fp)) return;
  handleChatPayload(payload);
}

function subscribeRealtime(tokenDetails){
  if(!isValidRealtimeToken(tokenDetails)){
    return Promise.reject(new Error('Invalid realtime token'));
  }
  return window.ELXAO_ABLY.ensureClient(tokenDetails).then(function(client){
    if(realtimeCleanup){ try{ realtimeCleanup(); }catch(e){} realtimeCleanup=null; }
    const unsubscribe=window.ELXAO_ABLY.registerChannel(client,room,projectId,handleRealtimePayload);
    realtimeCleanup=unsubscribe;
    stopFallbackPolling();
  });
}

function cleanup(){
  if(realtimeCleanup){ try{ realtimeCleanup(); }catch(e){} realtimeCleanup=null; }
  if(busCleanup){ try{ busCleanup(); }catch(e){} busCleanup=null; }
  if(readSyncTimer){ clearTimeout(readSyncTimer); readSyncTimer=null; }
  readSyncPending=false;
  stopFallbackPolling();
  window.removeEventListener('elxao:chat',onChatEvent);
  window.removeEventListener('beforeunload',cleanup);
}

const observer=new MutationObserver(function(){ if(!document.body.contains(root)){ observer.disconnect(); cleanup(); }});
observer.observe(document.body,{childList:true,subtree:true});
window.addEventListener('elxao:chat',onChatEvent);
if(window.ELXAO_CHAT_BUS.channel && window.ELXAO_CHAT_BUS.channel.addEventListener){
  const busHandler=function(ev){ const detail=ev&&ev.data?ev.data:null; if(!detail) return; if(detail.originId && detail.originId===window.ELXAO_CHAT_BUS.origin) return; onChatEvent({detail:detail}); };
  window.ELXAO_CHAT_BUS.channel.addEventListener('message',busHandler);
  busCleanup=function(){ window.ELXAO_CHAT_BUS.channel.removeEventListener('message',busHandler); };
}
window.addEventListener('beforeunload',cleanup,{once:true});

function mapHistoryItem(item){
  if(!item || typeof item!=='object') return null;
  return {
    type:item.content_type,
    message:item.content,
    project:item.project_id||projectId,
    user:item.user_id,
    user_display:item.user_display||item.user_name,
    role:item.role,
    at:item.published_at,
    id:item.id
  };
}
function renderHistory(items){
  if(!items||!Array.isArray(items)) return;
  isRenderingHistory=true;
  latestAt='';
  latestId=0;
  try{
    if(list) list.innerHTML='';
    items.forEach(function(m){
      const payload=mapHistoryItem(m);
      if(payload) handleChatPayload(payload);
    });
  } finally {
    isRenderingHistory=false;
  }
}
function processIncrementalHistory(items){
  if(!items||!Array.isArray(items)) return;
  items.forEach(function(m){
    const payload=mapHistoryItem(m);
    if(payload) handleChatPayload(payload);
  });
}
function pollForUpdates(){
  if(fallbackInFlight) return Promise.resolve();
  if(!pid || !rest) return Promise.resolve();
  const params={limit:100};
  if(latestAt) params.after=latestAt;
  if(latestId) params.after_id=latestId;
  fallbackInFlight=true;
  return load(params)
    .then(function(r){
      if(r && typeof r==='object'){
        if(r.reads) updateCurrentReadTimes(r.reads);
        if(r.items && Array.isArray(r.items) && r.items.length){
          processIncrementalHistory(r.items);
        }
      }
    })
    .catch(function(){})
    .finally(function(){ fallbackInFlight=false; });
}
function isValidRealtimeToken(token){
  if(!token || typeof token!=='object') return false;
  if(token.error || token.code) return false;
  if(token.token || token.keyName || token.expires || token.issued) return true;
  return false;
}
function scheduleFallback(delay){
  if(!fallbackActive) return;
  if(fallbackTimer){ clearTimeout(fallbackTimer); fallbackTimer=null; }
  const wait=(typeof delay==='number' && delay>=0)?delay:FALLBACK_INTERVAL;
  fallbackTimer=setTimeout(function(){
    fallbackTimer=null;
    pollForUpdates().finally(function(){ if(fallbackActive) scheduleFallback(FALLBACK_INTERVAL); });
  },wait);
}
function startFallbackPolling(initialDelay){
  if(fallbackActive) return;
  fallbackActive=true;
  scheduleFallback(initialDelay!==undefined?initialDelay:FALLBACK_INTERVAL);
}
function stopFallbackPolling(){
  fallbackActive=false;
  if(fallbackTimer){ clearTimeout(fallbackTimer); fallbackTimer=null; }
  fallbackInFlight=false;
}
function applyServerAck(resp){
  if(!resp || !list) return;
  const ackId=('id' in resp && resp.id!==undefined && resp.id!==null)?parseInt(resp.id,10):0;
  const ackAt=resp.at||resp.published_at||'';
  const ackTimes=extractReadTimes(resp);
  const ackStatus=(resp && resp.read_status && typeof resp.read_status==='object')?resp.read_status:null;
  const lines=list.children?Array.from(list.children):[];
  for(let i=lines.length-1;i>=0;i--){
    const line=lines[i];
    if(!line||!line.__chatPayload) continue;
    const payload=line.__chatPayload;
    if((payload.user||0)!==myId) continue;
    if(ackId && payload.id && parseInt(payload.id,10)!==ackId) continue;
    if(ackId){
      payload.id=ackId;
      line.dataset.messageId=String(ackId);
    }
    if(ackAt){
      payload.at=ackAt;
      line.dataset.at=String(ackAt);
    }
    if(ackTimes){
      payload.reads=payload.reads||{};
      payload.reads.times=Object.assign({},ackTimes);
      updateCurrentReadTimes(ackTimes);
    }
    if(ackStatus){
      payload.read_status=Object.assign({},ackStatus);
      payload.reads=payload.reads||{};
      payload.reads.roles=Object.assign({},ackStatus);
    }
    const indicator=line.querySelector('.chat-read-indicator');
    if(indicator) applyIndicatorState(indicator,determineIndicator(payload,payload.role||line.dataset.role||'other'));
    updateLatestFromPayload(payload);
    break;
  }
}

/* Subscribe first, then load history (avoid race) */
token()
  .then(function(tk){ if(!isValidRealtimeToken(tk)) throw (tk&&tk.message)?new Error(tk.message):new Error('token'); return subscribeRealtime(tk); })
  .catch(function(err){ console.warn('ELXAO chat realtime unavailable',err); startFallbackPolling(1000); })
  .then(function(){ return load(); })
  .then(function(r){
    if(r && typeof r==='object'){
      if(r.reads) updateCurrentReadTimes(r.reads);
      if(r.items && Array.isArray(r.items)) renderHistory(r.items);
    }
  })
  .catch(function(err){ console.error('ELXAO chat history unavailable',err); if(!fallbackActive) startFallbackPolling(FALLBACK_INTERVAL); });

btn.addEventListener('click', function(){
  const v = ta.value.replace(/\s+$/,''); if(!v.trim()) return;
  const meName = '<?php echo $meName;?>';
  handleChatPayload({ type:'text', message:v, project:projectId||parseInt(pid,10), user:myId, user_display:meName, role:myRole });
  rememberLocal(fingerprint(projectId,myId,v)); ta.value=''; btn.disabled=true;
  send(v).then(function(resp){
    if(resp && resp.ok){
      const resolvedProject=resp.project_id?parseInt(resp.project_id,10):projectId;
      if(resolvedProject){
        window.ELXAO_CHAT_BROADCAST(resolvedProject,{ type:'text', message:v, project:resolvedProject, user:myId, user_display:meName, role:myRole, at:resp.at||new Date().toISOString(), id:resp.id });
      }
      applyServerAck(resp);
    }
  }).catch(function(err){ console.error('Failed to send chat message',err); })
    .finally(()=>{ btn.disabled=false; ta.focus(); });
});

ta.addEventListener('keydown', function(e){
  if ((e.key === 'Enter') && (e.ctrlKey || e.metaKey)) { e.preventDefault(); btn.click(); }
});
})();
</script>
<?php
return ob_get_clean();
}

/* ===========================================================
   INBOX SHORTCODE (WhatsApp-style: rooms left, chat right)
   =========================================================== */
function elxao_chat_render_inbox(){
    if(!is_user_logged_in()) return '<div>Please log in.</div>';

    $uid=get_current_user_id();
    $rooms=elxao_chat_collect_rooms_for_user($uid);
    $rest_nonce=wp_create_nonce('wp_rest');
    $rest_base=rest_url();
    $container_id='elxao-chat-inbox-'.wp_rand(1000,999999);

    ob_start();
?>
<div id="<?php echo esc_attr($container_id); ?>"
     class="elxao-chat-inbox"
     data-rest="<?php echo esc_url($rest_base); ?>"
     data-nonce="<?php echo esc_attr($rest_nonce); ?>">
  <div class="inbox-shell">
    <div class="room-list" role="navigation" aria-label="Chat rooms">
      <?php if($rooms){ foreach($rooms as $idx=>$room){
        $label=sprintf('#%d · %s',$room['id'],$room['title']);
        $activity=elxao_chat_format_activity($room['latest']);
        $room_id=elxao_chat_room_id($room['id']);
        $timestamp_attr=$room['timestamp']? (int)$room['timestamp'] : 0;
      ?>
      <button type="button"
              class="room<?php echo $idx===0?' active':''; ?>"
              data-project="<?php echo (int)$room['id']; ?>"
              data-room="<?php echo esc_attr($room_id); ?>"
              data-latest="<?php echo esc_attr($room['latest']); ?>"
              data-timestamp="<?php echo esc_attr($timestamp_attr); ?>">
        <span class="label"><?php echo esc_html($label); ?></span>
        <span class="meta"><?php echo esc_html($activity?:'—'); ?></span>
      </button>
      <?php }} else { ?>
      <div class="empty">No chat rooms available.</div>
      <?php } ?>
    </div>
    <div class="chat-pane">
      <div class="placeholder"><?php echo $rooms ? 'Select a room to load chat.' : 'You do not have access to any chat rooms.'; ?></div>
    </div>
  </div>
</div>
<style>
#<?php echo esc_attr($container_id); ?>{display:flex;flex-direction:column;font:14px/1.45 system-ui;color:#f3f4f6}
#<?php echo esc_attr($container_id); ?> .inbox-shell{display:flex;gap:0;border:1px solid #4b5563;border-radius:12px;overflow:hidden;min-height:460px;background:#111827}
#<?php echo esc_attr($container_id); ?> .room-list{width:220px;max-width:260px;border-right:1px solid #374151;background:#1f2937;display:flex;flex-direction:column}
#<?php echo esc_attr($container_id); ?> .room-list .room{all:unset;cursor:pointer;padding:12px 14px;border-bottom:1px solid rgba(255,255,255,0.05);display:flex;flex-direction:column;gap:4px;color:inherit}
#<?php echo esc_attr($container_id); ?> .room-list .room:focus-visible{outline:2px solid rgba(96,165,250,0.9);outline-offset:-2px}
#<?php echo esc_attr($container_id); ?> .room-list .room:hover{background:rgba(148,163,184,0.12)}
#<?php echo esc_attr($container_id); ?> .room-list .room.active{background:rgba(96,165,250,0.18)}
#<?php echo esc_attr($container_id); ?> .room-list .label{font-weight:600}
#<?php echo esc_attr($container_id); ?> .room-list .meta{font-size:12px;color:#9ca3af}
#<?php echo esc_attr($container_id); ?> .room-list .empty{padding:20px;color:#9ca3af;font-style:italic}
#<?php echo esc_attr($container_id); ?> .chat-pane{flex:1;min-width:0;background:transparent;display:flex;align-items:center;justify-content:center;padding:20px}
#<?php echo esc_attr($container_id); ?> .chat-pane .placeholder{color:#9ca3af;font-style:italic;text-align:center}
#<?php echo esc_attr($container_id); ?> .chat-pane .placeholder.error{color:#f87171;font-style:normal}
@media (max-width: 900px){
  #<?php echo esc_attr($container_id); ?> .inbox-shell{flex-direction:column}
  #<?php echo esc_attr($container_id); ?> .room-list{width:100%;max-width:none;display:flex;flex-direction:row;overflow-x:auto}
  #<?php echo esc_attr($container_id); ?> .room-list .room{flex:1;min-width:200px;border-bottom:none;border-right:1px solid rgba(255,255,255,0.05)}
  #<?php echo esc_attr($container_id); ?> .chat-pane{min-height:360px}
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function(){
const root=document.getElementById('<?php echo esc_js($container_id); ?>'); if(!root) return;

const rest=root.dataset.rest||'';
const nonce=root.dataset.nonce||'';
const chat=root.querySelector('.chat-pane');
const roomList=root.querySelector('.room-list');
const rooms=Array.from(roomList?roomList.querySelectorAll('.room'):[]);
const headers={'X-WP-Nonce':nonce,'Accept':'application/json'};
const roomMap=new Map();
const channelCleanups=new Map();
const cleanupFns=[];
let active=null;
let busCleanup=null;

/* ---- shared helpers (guarded; may already exist from chat window) ---- */
if(!window.ELXAO_CHAT_BUS){
  const origin=Math.random().toString(36).slice(2);
  let channel=null; if('BroadcastChannel' in window){ try{ channel=new BroadcastChannel('elxao-chat'); }catch(e){} }
  window.ELXAO_CHAT_BUS={
    origin, channel,
    emit(detail){ const msg=Object.assign({},detail,{originId:origin}); if(channel){ try{ channel.postMessage(msg); }catch(e){} } window.dispatchEvent(new CustomEvent('elxao:chat',{detail:msg})); }
  };
  if(channel){ window.addEventListener('beforeunload',()=>{ try{ channel.close(); }catch(e){} },{once:true}); }
}
if(!window.ELXAO_CHAT_BUILD_NORMALIZER){
  window.ELXAO_CHAT_BUILD_NORMALIZER=function(){
    const roles=new Set(['client','pm','admin','other','sys']);
    const fd=(...a)=>a.find(v=>v!==undefined&&v!==null);
    const toISO=v=>!v&&v!==0?new Date().toISOString():(v instanceof Date?v.toISOString():(typeof v==='number'?new Date(v).toISOString():String(v)));
    const canonicalRole=function(role){
      if(role===undefined||role===null) return '';
      let str=String(role).trim().toLowerCase();
      if(!str) return '';
      str=str.replace(/\s+/g,'_').replace(/-+/g,'_');
      if(str==='project_manager'||str==='projectmanager'||str==='manager'||str==='pm_user'||str==='pmid'||str==='pm') return 'pm';
      if(str==='customer'||str==='client_user'||str==='clientid'||str==='customer_user'||str==='client') return 'client';
      if(str==='administrator'||str==='admin_user'||str==='adminid'||str==='admin') return 'admin';
      return str;
    };
    const boolish=function(value){
      if(value===undefined||value===null) return false;
      if(typeof value==='boolean') return value;
      if(typeof value==='number') return value>0;
      if(typeof value==='string'){
        const str=value.trim().toLowerCase();
        if(!str) return false;
        if(str==='false'||str==='0'||str==='no'||str==='off'||str==='null'||str==='undefined') return false;
        return true;
      }
      if(value instanceof Date) return true;
      if(typeof value==='object'){
        if('read' in value) return boolish(value.read);
        if('value' in value) return boolish(value.value);
        if('state' in value) return boolish(value.state);
        if('status' in value) return boolish(value.status);
        if('seen' in value) return boolish(value.seen);
        if('at' in value) return boolish(value.at);
        if('timestamp' in value) return boolish(value.timestamp);
      }
      return !!value;
    };
    const mergeRole=function(map,role,value){
      const key=canonicalRole(role);
      if(!key) return;
      const bool=boolish(value);
      if(!(key in map)) map[key]=bool;
      else map[key]=map[key]||bool;
    };
    const collectSource=function(source,userSet,roleMap){
      if(source===undefined||source===null) return;
      if(Array.isArray(source)){
        source.forEach(item=>collectSource(item,userSet,roleMap));
        return;
      }
      if(typeof source==='object'){
        if(source){
          if('role' in source && source.role!==undefined && 'value' in source) mergeRole(roleMap,source.role,source.value);
          if(source.roles && typeof source.roles==='object') Object.entries(source.roles).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.byRole && typeof source.byRole==='object') Object.entries(source.byRole).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.read_roles && typeof source.read_roles==='object') Object.entries(source.read_roles).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.readRoles && typeof source.readRoles==='object') Object.entries(source.readRoles).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.read_status && typeof source.read_status==='object') Object.entries(source.read_status).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.readStatus && typeof source.readStatus==='object') Object.entries(source.readStatus).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.status && typeof source.status==='object') Object.entries(source.status).forEach(([k,v])=>mergeRole(roleMap,k,v));
          if(source.client!==undefined) mergeRole(roleMap,'client',source.client);
          if(source.customer!==undefined) mergeRole(roleMap,'client',source.customer);
          if(source.pm!==undefined) mergeRole(roleMap,'pm',source.pm);
          if(source.project_manager!==undefined) mergeRole(roleMap,'pm',source.project_manager);
          if(source.manager!==undefined) mergeRole(roleMap,'pm',source.manager);
          if(source.admin!==undefined) mergeRole(roleMap,'admin',source.admin);
          if(source.administrator!==undefined) mergeRole(roleMap,'admin',source.administrator);
          if('id' in source) collectSource(source.id,userSet,roleMap);
          if('user_id' in source) collectSource(source.user_id,userSet,roleMap);
          if('user' in source) collectSource(source.user,userSet,roleMap);
          if('users' in source) collectSource(source.users,userSet,roleMap);
          if('user_ids' in source) collectSource(source.user_ids,userSet,roleMap);
          if('ids' in source) collectSource(source.ids,userSet,roleMap);
          if('readers' in source) collectSource(source.readers,userSet,roleMap);
          if('entries' in source) collectSource(source.entries,userSet,roleMap);
          if('list' in source) collectSource(source.list,userSet,roleMap);
          if('byUser' in source) collectSource(source.byUser,userSet,roleMap);
          Object.keys(source).forEach(key=>{ if(/^\d+$/.test(key)) collectSource(parseInt(key,10),userSet,roleMap); });
        }
        return;
      }
      const num=Number(source);
      if(Number.isFinite(num) && num>0) userSet.add(num);
    };
    const directRoleKeys={
      client:['client_read','read_client','client_seen','seen_client','client_read_at','client_seen_at','read_by_client','clientRead','readClient','clientViewed','client_viewed','client_seenAt','seen_by_client','client_has_read','clientReadAt'],
      pm:['pm_read','read_pm','pm_seen','seen_pm','pm_read_at','pm_seen_at','read_by_pm','pmRead','readPm','pmViewed','pm_viewed','pm_seenAt','seen_by_pm','project_manager_read','read_project_manager','project_manager_seen','seen_project_manager','project_manager_read_at','project_manager_seen_at','manager_read','read_manager','manager_seen','seen_manager'],
      admin:['admin_read','read_admin','admin_seen','seen_admin','admin_read_at','admin_seen_at','read_by_admin','adminRead','readAdmin','adminViewed','admin_viewed','admin_seenAt','seen_by_admin','administrator_read','read_administrator','administrator_seen','seen_administrator']
    };
    return function(data,fallbackProject){
      const s=(data&&typeof data==='object')?data:{};
      const p=Number(fd(s.project,s.project_id,fallbackProject,0))||0;
      const type=String(fd(s.type,s.content_type,'text')||'text');
      const msg=String(fd(s.message,s.content,'')||'');
      const user=Number(fd(s.user,s.user_id,0))||0;
      const display=String(fd(s.user_display,s.userDisplay,s.user_name,s.username,user?('User '+user):'User'));
      let role=(s.role||'').toString().toLowerCase();
      if(!roles.has(role)) role=(type==='system')?'sys':'other';
      const at=toISO(fd(s.at,s.published_at,s.created_at,null));
      const result=Object.assign({},s,{type:type,message:msg,project:p,user:user,user_display:display,role:role,at:at});
      const readUsers=new Set();
      const roleFlags={};
      collectSource(s.reads,readUsers,roleFlags);
      collectSource(s.read_receipts,readUsers,roleFlags);
      collectSource(s.readReceipts,readUsers,roleFlags);
      collectSource(s.read_status,readUsers,roleFlags);
      collectSource(s.readStatus,readUsers,roleFlags);
      collectSource(s.readBy,readUsers,roleFlags);
      collectSource(s.read_by,readUsers,roleFlags);
      collectSource(s.readers,readUsers,roleFlags);
      collectSource(s.receipts,readUsers,roleFlags);
      collectSource(s.seen_by,readUsers,roleFlags);
      collectSource(s.seenBy,readUsers,roleFlags);
      collectSource(s.seen_status,readUsers,roleFlags);
      ['read_by','readBy','readers','read_receipts','readReceipts','read_entries','readEntries','seen_by','seenBy'].forEach(key=>{ if(key in s) collectSource(s[key],readUsers,roleFlags); });
      Object.entries(directRoleKeys).forEach(([roleKey,keys])=>{ keys.forEach(key=>{ if(Object.prototype.hasOwnProperty.call(s,key)) mergeRole(roleFlags,roleKey,s[key]); }); });
      const normalizedReads={ users:Array.from(readUsers), roles:{} };
      Object.entries(roleFlags).forEach(([k,v])=>{ normalizedReads.roles[k]=!!v; });
      if(s.reads && typeof s.reads==='object' && !Array.isArray(s.reads)) normalizedReads.raw=s.reads;
      result.reads=normalizedReads;
      return result;
    };
  };
}
if(!window.ELXAO_CHAT_NORMALIZE){
  window.ELXAO_CHAT_NORMALIZE=window.ELXAO_CHAT_BUILD_NORMALIZER();
}
if(!window.ELXAO_CHAT_RESOLVE_PROJECT_ID){
  window.ELXAO_CHAT_RESOLVE_PROJECT_ID=function(){
    const args=Array.prototype.slice.call(arguments);
    const parseValue=function(value){
      if(value===undefined||value===null) return 0;
      if(typeof value==='number' && isFinite(value)){ const num=value<0?-value:value; return num>0?Math.floor(num):0; }
      if(typeof value==='string'){
        const trimmed=value.trim();
        if(!trimmed) return 0;
        if(/^-?\d+$/.test(trimmed)) return Math.abs(parseInt(trimmed,10));
        const match=trimmed.match(/(-?\d+)/);
        return match?Math.abs(parseInt(match[1],10)):0;
      }
      if(Array.isArray(value)){
        for(let i=0;i<value.length;i++){ const result=parseValue(value[i]); if(result) return result; }
        return 0;
      }
      if(typeof value==='object'){
        const keys=['project','project_id','projectId','projectID','id','room','room_id','roomId'];
        for(let i=0;i<keys.length;i++){
          const key=keys[i];
          if(Object.prototype.hasOwnProperty.call(value,key)){
            const result=parseValue(value[key]);
            if(result) return result;
          }
        }
      }
      return 0;
    };
    for(let i=0;i<args.length;i++){
      const resolved=parseValue(args[i]);
      if(resolved) return resolved;
    }
    return 0;
  };
}
if(!window.ELXAO_ABLY){
  window.ELXAO_ABLY={client:null,clientPromise:null,libraryPromise:null,channels:new Map()};
  window.ELXAO_ABLY.loadLibrary=function(){
    const st=window.ELXAO_ABLY;
    if(st.libraryPromise) return st.libraryPromise;
    if(window.Ably&&window.Ably.Realtime&&window.Ably.Realtime.Promise){ st.libraryPromise=Promise.resolve(); return st.libraryPromise; }
    st.libraryPromise=new Promise((res,rej)=>{
      const ex=document.querySelector('script[data-elxao-ably]');
      if(ex){ ex.addEventListener('load',()=>res(),{once:true}); ex.addEventListener('error',()=>rej(new Error('Ably load failed')),{once:true}); return; }
      const s=document.createElement('script'); s.src='https://cdn.ably.io/lib/ably.min-1.js'; s.async=true; s.setAttribute('data-elxao-ably','1');
      s.onload=()=>res(); s.onerror=()=>rej(new Error('Ably load failed')); document.head.appendChild(s);
    });
    return st.libraryPromise;
  };
  window.ELXAO_ABLY.ensureClient=function(tokenDetails){
    const st=window.ELXAO_ABLY;
    if(st.clientPromise){
      return st.clientPromise.then(client=>{
        if(tokenDetails){ return client.auth.authorize(null,{tokenDetails}).then(()=>client); }
        return client;
      });
    }
    st.clientPromise=st.loadLibrary().then(()=>{
      if(!window.Ably||!window.Ably.Realtime||!window.Ably.Realtime.Promise) throw new Error('Ably unavailable');
      st.client=new window.Ably.Realtime.Promise({tokenDetails});
      return st.client;
    }).catch(err=>{ st.client=null; st.clientPromise=null; throw err; });
    return st.clientPromise;
  };
  window.ELXAO_ABLY.registerChannel=function(client,channelName,project,onMessage){
    if(!channelName) return function(){};
    const st=window.ELXAO_ABLY; const map=st.channels;
    let entry=map.get(channelName);
    if(!entry){ entry={refCount:0,handler:null,channel:client.channels.get(channelName),projectId:project,callbacks:new Set()}; map.set(channelName,entry); }
    else {
      if(!entry.channel) entry.channel=client.channels.get(channelName);
      if(project && !entry.projectId) entry.projectId=project;
      if(!entry.callbacks) entry.callbacks=new Set();
    }
    if(onMessage && typeof onMessage==='function') entry.callbacks.add(onMessage);
    entry.refCount++;
    if(!entry.handler){
      entry.handler=function(msg){
        let data=(msg&&msg.data)?msg.data:{};
        if(typeof data==='string'){
          const trimmed=data.trim();
          if(trimmed){
            try{ data=JSON.parse(trimmed); }
            catch(e){ data={message:data}; }
          } else {
            data={};
          }
        }
        const resolve=window.ELXAO_CHAT_RESOLVE_PROJECT_ID;
        const hintedProject=resolve(
          data && data.project,
          data && data.project_id,
          data && data.projectId,
          data && data.projectID,
          entry && entry.projectId,
          project
        );
        const projectId=hintedProject || resolve(entry && entry.projectId, project);
        let payload=data;
        if(payload && typeof payload==='object' && !Array.isArray(payload)){
          if(projectId && resolve(payload.project)!==projectId){
            payload=Object.assign({},payload,{project:projectId});
          } else if(projectId && !('project' in payload)){
            payload=Object.assign({},payload,{project:projectId});
          }
        }
        const normalizer=window.ELXAO_CHAT_NORMALIZE||null;
        const normalized=normalizer?normalizer(payload,projectId):payload;
        if(entry.callbacks && entry.callbacks.size){
          entry.callbacks.forEach(function(cb){ try{ cb(normalized,msg,{projectId:projectId,channel:channelName}); }catch(e){} });
        }
        window.ELXAO_CHAT_BUS.emit({project:projectId,payload:normalized});
        if(projectId){
          const bumpAt=(normalized&&normalized.at)|| (payload&&payload.at) || data.at || data.published_at || Date.now();
          window.dispatchEvent(new CustomEvent('elxao:room-bump',{detail:{projectId:projectId,at:bumpAt}}));
        }
      };
      entry.channel.subscribe(entry.handler);
    }
    entry.channel.attach().catch(()=>{});
    return function(){
      if(onMessage && entry.callbacks) entry.callbacks.delete(onMessage);
      entry.refCount--;
      if(entry.refCount<=0){ if(entry.channel&&entry.handler) entry.channel.unsubscribe(entry.handler); map.delete(channelName); }
    };
  };
}
/* ---- end shared helpers ---- */

function registerCleanup(fn){ if(typeof fn==='function') cleanupFns.push(fn); }
function runCleanup(){ while(cleanupFns.length){ const fn=cleanupFns.pop(); try{ fn(); }catch(e){} } }

rooms.forEach(function(room){
  const pid=room.getAttribute('data-project');
  if(pid) roomMap.set(String(pid),room);
  const latestAttr=room.getAttribute('data-latest');
  const tsAttr=room.getAttribute('data-timestamp');
  const tsNumeric=tsAttr?parseInt(tsAttr,10):0;
  if(latestAttr){ setRoomActivity(room,latestAttr); }
  else if(tsNumeric>0){ setRoomActivity(room,tsAttr); }
  else { room.dataset.timestamp=room.dataset.timestamp||'0'; room.dataset.latest=room.dataset.latest||''; }
});

function parseTimestamp(value){
  if(value instanceof Date) return value;
  if(typeof value==='number' && isFinite(value)) return new Date(value);
  const str=String(value||'').trim(); if(!str) return null;
  if(/^-?\d+$/.test(str)){ const num=parseInt(str,10); return new Date(str.length<=10?num*1000:num); }
  const normalized=str.replace(' ','T'); const date=new Date(normalized);
  return isNaN(date.getTime())?null:date;
}
function pad(v){ return v<10?'0'+v:''+v; }
function formatTimestamp(date){ if(!(date instanceof Date) || isNaN(date.getTime())) return '—';
  const months=['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec']; return months[date.getMonth()]+' '+date.getDate()+', '+pad(date.getHours())+':'+pad(date.getMinutes()); }
function updateMetaText(room,date){ const meta=room.querySelector('.meta'); if(meta && date instanceof Date && !isNaN(date.getTime())) meta.textContent=formatTimestamp(date); else if(meta && !meta.textContent) meta.textContent='—'; }
function setRoomActivity(room,source){ const date=parseTimestamp(source); if(!date) return; room.dataset.timestamp=String(date.getTime()); room.dataset.latest=date.toISOString(); updateMetaText(room,date); }
function bumpRoom(projectId,activity){
  if(!projectId) return; const key=String(projectId); const room=roomMap.get(key); if(!room) return;
  const date=(activity instanceof Date)?activity:(parseTimestamp(activity)||new Date());
  const newTime=date.getTime(); const currentTime=parseInt(room.dataset.timestamp||'0',10);
  if(currentTime && newTime && newTime<currentTime) return; setRoomActivity(room,date);
  const parent=room.parentElement; if(parent && parent.firstElementChild!==room){ parent.insertBefore(room,parent.firstElementChild); }
}

function renderHTML(html){
  chat.innerHTML='';
  if(!html){ chat.innerHTML='<div class="placeholder error">Unable to load chat.</div>'; return; }
  const tmp=document.createElement('div'); tmp.innerHTML=html;
  while(tmp.firstChild){
    const node=tmp.firstChild; tmp.removeChild(node);
    if(node.nodeName==='SCRIPT'){ const s=document.createElement('script'); s.textContent=node.textContent; chat.appendChild(s); }
    else { chat.appendChild(node); }
  }
}

function loadRoom(room){
  if(!room||room===active) return;
  if(active) active.classList.remove('active');
  active=room; room.classList.add('active');
  chat.innerHTML='<div class="placeholder">Loading…</div>';
  const pid=room.getAttribute('data-project'); if(!pid) return;
  fetch(rest+'elxao/v1/chat-window?project_id='+encodeURIComponent(pid),{ credentials:'same-origin', headers:headers })
  .then(r=>{ if(!r.ok) throw new Error(''+r.status); return r.json(); })
  .then(data=>{ renderHTML((data&&typeof data==='object'&&'html' in data)?data.html:''); })
  .catch(()=>{ chat.innerHTML='<div class="placeholder error">Unable to load chat.</div>'; });
}

/* attach click listeners */
rooms.forEach(function(room){ room.addEventListener('click',function(){ loadRoom(room); }); });

/* Ably subscribe for bumps */
function subscribeInbox(){
  if(!rooms.length || !rest) return;
  const params=[]; const seen=new Set();
  rooms.forEach(function(room){ const pid=room.getAttribute('data-project'); if(!pid || seen.has(pid)) return; seen.add(pid); params.push('project_ids[]='+encodeURIComponent(pid)); });
  if(!params.length) return;
  fetch(rest+'elxao/v1/inbox-token?'+params.join('&'),{ credentials:'same-origin', headers:headers })
    .then(r=>{ if(!r.ok) throw new Error(''+r.status); return r.json(); })
    .then(token=>{
      if(!token || token.error) return;
      return window.ELXAO_ABLY.ensureClient(token).then(function(client){
        const seenChannels=new Set();
        rooms.forEach(function(room){
          const channelName=room.getAttribute('data-room'); if(!channelName || seenChannels.has(channelName)) return;
          seenChannels.add(channelName); if(channelCleanups.has(channelName)) return;
          const pid=room.getAttribute('data-project'); const projectId=pid?parseInt(pid,10):0;
          const unsubscribe=window.ELXAO_ABLY.registerChannel(client,channelName,projectId);
          if(typeof unsubscribe==='function'){ channelCleanups.set(channelName,unsubscribe); }
        });
      });
    })
    .catch(err=>{ console.warn('ELXAO inbox realtime unavailable',err); });
}

function onChatEvent(ev){
  const detail=ev&&ev.detail?ev.detail:{}; if(!detail || typeof detail.project==='undefined') return;
  const payload=window.ELXAO_CHAT_NORMALIZE(detail.payload||{},detail.project);
  const projectId=payload.project||parseInt(detail.project,10)||0; if(!projectId) return;
  const bumpAt=payload.at||Date.now(); bumpRoom(projectId,bumpAt);
}

window.addEventListener('elxao:chat',onChatEvent);
registerCleanup(function(){ window.removeEventListener('elxao:chat',onChatEvent); });

if(window.ELXAO_CHAT_BUS.channel && window.ELXAO_CHAT_BUS.channel.addEventListener){
  const busHandler=function(ev){ const detail=ev&&ev.data?ev.data:null; if(!detail) return; if(detail.originId && detail.originId===window.ELXAO_CHAT_BUS.origin) return; onChatEvent({detail:detail}); };
  window.ELXAO_CHAT_BUS.channel.addEventListener('message',busHandler);
  busCleanup=function(){ window.ELXAO_CHAT_BUS.channel.removeEventListener('message',busHandler); };
  registerCleanup(function(){ if(busCleanup){ try{ busCleanup(); }catch(e){} busCleanup=null; }});
}

registerCleanup(function(){ channelCleanups.forEach(function(unsub){ try{ unsub(); }catch(e){} }); channelCleanups.clear(); });

const observer=new MutationObserver(function(){ if(!document.body.contains(root)){ observer.disconnect(); runCleanup(); } });
observer.observe(document.body,{childList:true,subtree:true});
registerCleanup(function(){ observer.disconnect(); });
window.addEventListener('beforeunload',runCleanup,{once:true});

/* Auto-load first room AFTER paint */
if(rooms.length){ requestAnimationFrame(()=>loadRoom(rooms[0])); subscribeInbox(); }
});
</script>
<?php
    return ob_get_clean();
}

/* ===========================================================
   SHORTCODES
   =========================================================== */
add_shortcode('elxao_chat_card',function($a){
    $a=shortcode_atts(['project_id'=>0],$a,'elxao_chat_card');
    $pid=(int)$a['project_id']; if(!$pid) $pid=(int)get_the_ID();
    return elxao_chat_render_window($pid);
});
add_shortcode('elxao_chat_inbox','elxao_chat_render_inbox');

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

    elxao_chat_publish_to_ably(elxao_chat_room_id($pid),[
        'name'=>'text',
        'data'=>[
            'type'=>'text',
            'message'=>$msg,
            'project'=>$pid,
            'user'=>$uid,
            'user_display'=>$display,
            'role'=>$role,
            'at'=>$now
        ]
    ]);
    return new WP_REST_Response(['ok'=>true,'project_id'=>$pid,'at'=>$now],200);
}

function elxao_chat_rest_history(WP_REST_Request $r){
    global $wpdb; $t=$wpdb->prefix.'elxao_chat_messages';
    $pid=(int)$r['project_id']; $uid=get_current_user_id();
    if(!elxao_chat_user_can_access_project($pid,$uid))
        return new WP_Error('forbidden','Not allowed',['status'=>403]);

    $limit=min(200,max(1,(int)($r['limit']?:50)));
    $rows=$wpdb->get_results($wpdb->prepare(
        "SELECT id,project_id,user_id,content,content_type,published_at
         FROM $t WHERE project_id=%d ORDER BY published_at ASC,id ASC LIMIT %d",$pid,$limit),ARRAY_A);

    foreach($rows as &$row){
        $u=get_userdata($row['user_id']);
        $row['user_display']=$u?$u->display_name:'System';
        $row['user_id']=(int)$row['user_id'];
        $row['role']=elxao_chat_role_for_user($pid,(int)$row['user_id']); // client | pm | admin | other
    }
    return new WP_REST_Response(['items'=>$rows],200);
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
        '--chat-color:%s; --chat-client:%s; --chat-pm:%s; --chat-admin:%s; --chat-sys:%s;',
        esc_attr(ELXAO_CHAT_COLOR_BASE),
        esc_attr(ELXAO_CHAT_COLOR_CLIENT),
        esc_attr(ELXAO_CHAT_COLOR_PM),
        esc_attr(ELXAO_CHAT_COLOR_ADMIN),
        esc_attr(ELXAO_CHAT_COLOR_SYS)
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
const localEchoes=new Map();
let realtimeCleanup=null;
let busCleanup=null;

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
if(!window.ELXAO_CHAT_NORMALIZE){
  window.ELXAO_CHAT_NORMALIZE=(function(){
    const roles=new Set(['client','pm','admin','other','sys']);
    const fd=(...a)=>a.find(v=>v!==undefined&&v!==null);
    const toISO=v=>!v&&v!==0?new Date().toISOString():(v instanceof Date?v.toISOString():(typeof v==='number'?new Date(v).toISOString():String(v)));
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
      return {type:type,message:msg,project:p,user:user,user_display:display,role:role,at:at};
    };
  })();
}
if(!window.ELXAO_CHAT_BROADCAST){
  window.ELXAO_CHAT_BROADCAST=function(projectHint,data,extras){
    const payload=window.ELXAO_CHAT_NORMALIZE(data,projectHint);
    if(!payload.project && projectHint) payload.project=Number(projectHint)||0;
    const detail=Object.assign({project:payload.project,payload:payload},(extras&&typeof extras==='object')?extras:{});
    window.ELXAO_CHAT_BUS.emit(detail);
    if(payload.project){
      const bumpAt=payload.at||Date.now();
      window.dispatchEvent(new CustomEvent('elxao:room-bump',{detail:{projectId:payload.project,at:bumpAt}}));
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
  window.ELXAO_ABLY.registerChannel=function(client,channelName,project){
    if(!channelName) return function(){};
    const st=window.ELXAO_ABLY; const map=st.channels;
    let entry=map.get(channelName);
    if(!entry){ entry={refCount:0,handler:null,channel:client.channels.get(channelName),projectId:project}; map.set(channelName,entry); }
    else { if(!entry.channel) entry.channel=client.channels.get(channelName); if(project && !entry.projectId) entry.projectId=project; }
    entry.refCount++;
    if(!entry.handler){
      entry.handler=function(msg){
        const data=(msg&&msg.data)?msg.data:{};
        const projectId=data.project||entry.projectId||project||0;
        const extras={}; if(msg && 'id' in msg) extras.id=msg.id; if(msg && 'name' in msg) extras.name=msg.name;
        window.ELXAO_CHAT_BROADCAST(projectId,data,extras);
      };
      entry.channel.subscribe(entry.handler);
    }
    entry.channel.attach().catch(()=>{});
    return function(){
      entry.refCount--; if(entry.refCount<=0){ if(entry.channel&&entry.handler) entry.channel.unsubscribe(entry.handler); map.delete(channelName); }
    };
  };
}
/* ---------- end helpers ---------- */

function normalizeContent(value){ return String(value||'').replace(/<[^>]*?>/g,''); }
function addLine(text, cls){
  const d=document.createElement('div'); d.className=cls||'';
  d.textContent='';
  String(text).split('\n').forEach((p,i)=>{ d.appendChild(document.createTextNode(p)); if(i) d.insertBefore(document.createElement('br'), d.lastChild); });
  list.appendChild(d); list.scrollTop=list.scrollHeight;
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

function load(){ return fetch(rest+'elxao/v1/messages?project_id='+pid,{credentials:'same-origin',headers:hdr}).then(r=>r.json()); }
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
  const name=data.user_display||('User '+(data.user||'')); const role=(data.type==='system')?'sys':(data.role||'other');
  const message=normalizeContent(data.message); if(!message && role!=='sys') return;
  addLine(name+': '+message, role);
}

function onChatEvent(ev){
  const detail=ev&&ev.detail?ev.detail:{}; const project=detail.project?parseInt(detail.project,10):0;
  if(project!==projectId) return;
  const payload=window.ELXAO_CHAT_NORMALIZE(detail.payload||{},projectId);
  const fp=fingerprint(projectId,payload.user||0,payload.message||''); if(isRecentLocal(fp)) return;
  handleChatPayload(payload);
}

function subscribeRealtime(tokenDetails){
  return window.ELXAO_ABLY.ensureClient(tokenDetails).then(function(client){
    if(realtimeCleanup){ try{ realtimeCleanup(); }catch(e){} realtimeCleanup=null; }
    const unsubscribe=window.ELXAO_ABLY.registerChannel(client,room,projectId); realtimeCleanup=unsubscribe;
  });
}

function cleanup(){
  if(realtimeCleanup){ try{ realtimeCleanup(); }catch(e){} realtimeCleanup=null; }
  if(busCleanup){ try{ busCleanup(); }catch(e){} busCleanup=null; }
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

function renderHistory(items){
  if(!items||!Array.isArray(items)) return;
  items.forEach(function(m){
    const payload=window.ELXAO_CHAT_NORMALIZE({ type:m.content_type, message:m.content, project:m.project_id||projectId, user:m.user_id, user_display:m.user_display||m.user_name, role:m.role, at:m.published_at },projectId);
    handleChatPayload(payload);
  });
}

/* Subscribe first, then load history (avoid race) */
token()
  .then(function(tk){ if(!tk||tk.error) throw tk||new Error('token'); return subscribeRealtime(tk); })
  .catch(function(err){ console.warn('ELXAO chat realtime unavailable',err); })
  .then(function(){ return load(); })
  .then(function(r){ if(r&&r.items) renderHistory(r.items); })
  .catch(function(err){ console.error('ELXAO chat history unavailable',err); });

btn.addEventListener('click', function(){
  const v = ta.value.replace(/\s+$/,''); if(!v.trim()) return;
  const meName = '<?php echo $meName;?>'; addLine(meName+': '+v, myRole);
  rememberLocal(fingerprint(projectId,myId,v)); ta.value=''; btn.disabled=true;
  send(v).then(function(resp){
    if(resp && resp.ok){
      const resolvedProject=resp.project_id?parseInt(resp.project_id,10):projectId;
      if(resolvedProject){
        window.ELXAO_CHAT_BROADCAST(resolvedProject,{ type:'text', message:v, project:resolvedProject, user:myId, user_display:meName, role:myRole, at:resp.at||new Date().toISOString()});
      }
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
if(!window.ELXAO_CHAT_NORMALIZE){
  const roles=new Set(['client','pm','admin','other','sys']);
  const fd=(...a)=>a.find(v=>v!==undefined&&v!==null);
  const toISO=v=>!v&&v!==0?new Date().toISOString():(v instanceof Date?v.toISOString():(typeof v==='number'?new Date(v).toISOString():String(v)));
  window.ELXAO_CHAT_NORMALIZE=function(data,fallbackProject){
    const s=(data&&typeof data==='object')?data:{};
    const p=Number(fd(s.project,s.project_id,fallbackProject,0))||0;
    const type=String(fd(s.type,s.content_type,'text')||'text');
    const msg=String(fd(s.message,s.content,'')||'');
    const user=Number(fd(s.user,s.user_id,0))||0;
    const display=String(fd(s.user_display,s.userDisplay,s.user_name,s.username,user?('User '+user):'User'));
    let role=(s.role||'').toString().toLowerCase(); if(!roles.has(role)) role=(type==='system')?'sys':'other';
    const at=toISO(fd(s.at,s.published_at,s.created_at,null));
    return {type:type,message:msg,project:p,user:user,user_display:display,role:role,at:at};
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
  window.ELXAO_ABLY.registerChannel=function(client,channelName,project){
    if(!channelName) return function(){};
    const st=window.ELXAO_ABLY; const map=st.channels;
    let entry=map.get(channelName);
    if(!entry){ entry={refCount:0,handler:null,channel:client.channels.get(channelName),projectId:project}; map.set(channelName,entry); }
    else { if(!entry.channel) entry.channel=client.channels.get(channelName); if(project && !entry.projectId) entry.projectId=project; }
    entry.refCount++;
    if(!entry.handler){
      entry.handler=function(msg){
        const data=(msg&&msg.data)?msg.data:{};
        const projectId=data.project||entry.projectId||project||0;
        window.ELXAO_CHAT_BUS.emit({project:projectId,payload:window.ELXAO_CHAT_NORMALIZE(data,projectId)});
        if(projectId){
          const bumpAt=data.at||data.published_at||Date.now();
          window.dispatchEvent(new CustomEvent('elxao:room-bump',{detail:{projectId:projectId,at:bumpAt}}));
        }
      };
      entry.channel.subscribe(entry.handler);
    }
    entry.channel.attach().catch(()=>{});
    return function(){ entry.refCount--; if(entry.refCount<=0){ if(entry.channel&&entry.handler) entry.channel.unsubscribe(entry.handler); map.delete(channelName); } };
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

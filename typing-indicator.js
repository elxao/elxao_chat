/*
 * Typing indicator for ELXAO Chat. This script listens to local typing
 * activity and communicates it via Ably presence. Other users typing in
 * the same chat room will cause a typing indicator to appear.
 */
(function(){
  function ready(fn){
    if(document.readyState !== 'loading'){
      fn();
    }else{
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  function ensureIndicatorStyles(){
    if(document.getElementById('elxao-typing-indicator-style')) return;
    const style = document.createElement('style');
    style.id = 'elxao-typing-indicator-style';
    style.textContent = [
      '.typing-indicator{display:flex;align-items:center;gap:10px;padding:4px 20px 8px 20px;font-style:italic;color:#64748b;font-size:14px;transition:opacity .18s ease;opacity:1}',
      '.typing-indicator--hidden{opacity:0;pointer-events:none;height:0;overflow:hidden;padding-top:0;padding-bottom:0;margin:0}',
      '.typing-indicator__bubble{display:flex;align-items:center;gap:4px;min-width:28px;justify-content:center;height:18px}',
      '.typing-indicator__bubble span{display:block;width:6px;height:6px;border-radius:999px;background:#94a3b8;opacity:.4;animation:typing-indicator-bounce 1.2s infinite ease-in-out}',
      '.typing-indicator__bubble span:nth-child(2){animation-delay:.16s}',
      '.typing-indicator__bubble span:nth-child(3){animation-delay:.32s}',
      '@keyframes typing-indicator-bounce{0%,80%,100%{transform:translateY(0);opacity:.35;}40%{transform:translateY(-4px);opacity:.9;}}',
      '.typing-indicator__text{flex:1;min-width:0;white-space:nowrap;text-overflow:ellipsis;overflow:hidden}'
    ].join('');
    document.head.appendChild(style);
  }

  function buildIndicator(chat){
    ensureIndicatorStyles();
    const indicator = document.createElement('div');
    indicator.className = 'typing-indicator typing-indicator--hidden';
    indicator.setAttribute('role', 'status');
    indicator.setAttribute('aria-live', 'polite');

    const bubble = document.createElement('div');
    bubble.className = 'typing-indicator__bubble';
    bubble.setAttribute('aria-hidden', 'true');
    for(let i = 0; i < 3; i++){
      bubble.appendChild(document.createElement('span'));
    }

    const text = document.createElement('span');
    text.className = 'typing-indicator__text';

    indicator.appendChild(bubble);
    indicator.appendChild(text);

    const composer = chat.querySelector('.composer');
    if(composer && composer.parentNode){
      chat.insertBefore(indicator, composer);
    }else{
      chat.appendChild(indicator);
    }

    return { indicator: indicator, text: text };
  }

  function safeName(value){
    const str = (value || '').toString().trim();
    if(str) return str;
    return 'Someone';
  }

  function joinNames(names){
    const list = names.slice();
    if(list.length <= 1) return list[0] || '';
    if(typeof Intl !== 'undefined' && Intl.ListFormat){
      try{
        const formatter = new Intl.ListFormat(undefined,{ style:'long', type:'conjunction' });
        return formatter.format(list);
      }catch(err){/* ignore */}
    }
    if(list.length === 2) return list[0] + ' and ' + list[1];
    const head = list.slice(0, -1).join(', ');
    return head + ', and ' + list[list.length - 1];
  }

  function formatTypingMessage(names){
    const uniqueNames = Array.from(new Set(names));
    if(uniqueNames.length === 1){
      return uniqueNames[0] + ' is typing…';
    }
    if(uniqueNames.length === 2){
      return joinNames(uniqueNames) + ' are typing…';
    }
    const primary = uniqueNames.slice(0, 2);
    const remaining = uniqueNames.length - primary.length;
    if(remaining <= 1){
      const visible = uniqueNames.slice(0, 3);
      return joinNames(visible) + ' are typing…';
    }
    return joinNames(primary) + ' and ' + remaining + ' others are typing…';
  }

  const initializedChats = new WeakSet();
  const retryTimers = new WeakMap();
  const pendingChats = window.ELXAO_CHAT_PENDING_TYPING = window.ELXAO_CHAT_PENDING_TYPING || [];

  function scheduleRetry(chat){
    if(!chat || initializedChats.has(chat)) return;
    if(retryTimers.has(chat)) return;
    const timer = setTimeout(function(){
      retryTimers.delete(chat);
      setupChat(chat);
    }, 400);
    retryTimers.set(chat, timer);
  }

  function ensureIndicator(chat){
    if(chat.__elxaoTypingIndicator) return chat.__elxaoTypingIndicator;
    const indicatorElements = buildIndicator(chat);
    chat.__elxaoTypingIndicator = indicatorElements;
    return indicatorElements;
  }

  function setupChat(chat){
    if(!chat || initializedChats.has(chat)) return;
    if(!chat.isConnected){
      scheduleRetry(chat);
      return;
    }

    const room = chat.dataset.room;
    if(!room) return;

    const restBase = chat.dataset.rest || '';
    const projectId = parseInt(chat.dataset.project || '0', 10) || 0;
    if(!restBase || !projectId){
      return;
    }

    const ably = window.ELXAO_ABLY;
    if(!ably || typeof ably.ensureClient !== 'function'){
      scheduleRetry(chat);
      return;
    }

    if(retryTimers.has(chat)){
      clearTimeout(retryTimers.get(chat));
      retryTimers.delete(chat);
    }

    const indicatorElements = ensureIndicator(chat);
    const indicator = indicatorElements.indicator;
    const indicatorText = indicatorElements.text;

    const myName = chat.dataset.myname || '';
    const myId = parseInt(chat.dataset.myid || '0', 10) || 0;
    const restNonce = chat.dataset.nonce || '';

    const typingUsers = new Map();
    const TYPING_DELAY_MS = 3000;
    const TYPING_STALE_MS = 5000;
    const CLEANUP_INTERVAL_MS = 1500;
    let typingState = false;
    let desiredTypingState = false;
    let typingTimeoutId = null;
    let cleanupTimerId = null;
    let channel = null;
    let clientPromise = null;
    let localConnectionId = null;
    let syncingTypingState = false;
    let resyncRequested = false;

    function updateIndicator(){
      const names = [];
      const seen = new Set();
      typingUsers.forEach(function(entry){
        if(!entry) return;
        const label = safeName(entry.name);
        if(seen.has(label)) return;
        seen.add(label);
        names.push(label);
      });
      if(!names.length){
        indicatorText.textContent = '';
        indicator.classList.add('typing-indicator--hidden');
        return;
      }
      indicatorText.textContent = formatTypingMessage(names);
      indicator.classList.remove('typing-indicator--hidden');
    }

    function pruneTypingUsers(){
      const now = Date.now();
      let changed = false;
      typingUsers.forEach(function(entry, key){
        if(!entry){
          typingUsers.delete(key);
          changed = true;
          return;
        }
        if(entry.updatedAt && now - entry.updatedAt > TYPING_STALE_MS){
          typingUsers.delete(key);
          changed = true;
        }
      });
      if(changed) updateIndicator();
    }

    function startPruneTimer(){
      if(cleanupTimerId) return;
      cleanupTimerId = setInterval(pruneTypingUsers, CLEANUP_INTERVAL_MS);
    }

    function stopPruneTimer(){
      if(cleanupTimerId){
        clearInterval(cleanupTimerId);
        cleanupTimerId = null;
      }
    }

    function presenceKeyFor(source){
      if(!source || typeof source !== 'object') return '';
      const connectionId = source.connectionId || (source.connection && source.connection.id) || '';
      if(connectionId) return 'conn:' + connectionId;
      const clientId = source.clientId || '';
      if(clientId) return 'client:' + clientId;
      if(source.id) return 'id:' + source.id;
      return '';
    }

    function setTypingForKey(key, name, isTyping){
      if(!key) return;
      if(isTyping){
        typingUsers.set(key, { name: safeName(name), updatedAt: Date.now() });
      }else{
        typingUsers.delete(key);
      }
      updateIndicator();
    }

    function clearTypingUsers(){
      typingUsers.clear();
      updateIndicator();
    }

    function refreshLocalConnectionId(client){
      if(!client || !client.connection) return;
      const connection = client.connection;
      if(connection && connection.id) localConnectionId = connection.id;
    }

    function watchConnection(client){
      if(!client || !client.connection || !client.connection.on) return;
      try{
        client.connection.on(function(event){
          if(!event) return;
          if(typeof event === 'string'){
            if(event === 'connected') refreshLocalConnectionId(client);
            if(event === 'failed' || event === 'suspended') clearTypingUsers();
            return;
          }
          if(event.current === 'connected' || event.current === 'update'){
            refreshLocalConnectionId(client);
          }
          if(event.current === 'suspended' || event.current === 'failed'){
            clearTypingUsers();
          }
        });
      }catch(err){/* ignore */}
    }

    function updateIndicatorFromPresence(msg){
      if(!msg) return;
      const myClientId = myId ? 'wpuser_' + myId : '';
      const connectionId = msg.connectionId || '';
      if(connectionId && localConnectionId && connectionId === localConnectionId) return;
      if(!connectionId){
        const clientId = msg.clientId;
        if(clientId && myClientId && clientId === myClientId) return;
      }
      const data = msg.data || {};
      const key = presenceKeyFor(msg) || (msg.clientId ? 'client:' + msg.clientId : (msg.id ? 'id:' + msg.id : 'anon'));
      if(data.typing){
        setTypingForKey(key, data.name, true);
      }else{
        setTypingForKey(key, data.name, false);
      }
    }

    function scheduleTypingResync(){
      if(resyncRequested) return;
      resyncRequested = true;
      setTimeout(function(){
        resyncRequested = false;
        syncTypingState();
      }, 200);
    }

    function handleTypingUpdateResult(promise, nextState){
      if(promise && typeof promise.then === 'function'){
        promise.then(function(){
          typingState = nextState;
        }, function(){
          scheduleTypingResync();
        }).then(function(){
          syncingTypingState = false;
          if(typingState !== desiredTypingState){
            syncTypingState();
          }
        });
      }else{
        typingState = nextState;
        syncingTypingState = false;
        if(typingState !== desiredTypingState){
          syncTypingState();
        }
      }
    }

    function syncTypingState(){
      if(!channel) return;
      if(syncingTypingState){
        scheduleTypingResync();
        return;
      }
      if(typingState === desiredTypingState) return;

      const nextState = desiredTypingState;
      syncingTypingState = true;
      let result;
      try{
        result = channel.presence.update({ typing: nextState, name: myName });
      }catch(err){
        syncingTypingState = false;
        scheduleTypingResync();
        return;
      }
      handleTypingUpdateResult(result, nextState);
    }

    function setDesiredTypingState(isTyping){
      const nextState = !!isTyping;
      if(desiredTypingState === nextState && channel){
        syncTypingState();
        return;
      }
      desiredTypingState = nextState;
      syncTypingState();
    }

    function onLocalInput(){
      setDesiredTypingState(true);
      if(typingTimeoutId) clearTimeout(typingTimeoutId);
      typingTimeoutId = setTimeout(function(){ setDesiredTypingState(false); }, TYPING_DELAY_MS);
    }

    const rest = /\/\s*$/.test(restBase) ? restBase : restBase + '/';
    const headers = { 'Accept': 'application/json' };
    if(restNonce) headers['X-WP-Nonce'] = restNonce;

    function fetchRealtimeToken(){
      const url = rest + 'elxao/v1/chat-token?project_id=' + encodeURIComponent(projectId);
      return fetch(url, {
        credentials: 'same-origin',
        headers: headers
      }).then(function(resp){
        if(!resp || !resp.ok) throw new Error('token');
        return resp.json();
      });
    }

    function isValidToken(token){
      if(!token || typeof token !== 'object') return false;
      if(token.error || token.code) return false;
      return !!(token.token || token.keyName || token.expires || token.issued);
    }

    function ensureRealtimeClient(){
      if(clientPromise) return clientPromise;
      clientPromise = fetchRealtimeToken()
        .then(function(token){
          if(!isValidToken(token)) throw new Error('token');
          return ably.ensureClient(token);
        })
        .catch(function(err){
          clientPromise = null;
          throw err;
        });
      return clientPromise;
    }

    startPruneTimer();

    ensureRealtimeClient().then(function(client){
      refreshLocalConnectionId(client);
      watchConnection(client);
      channel = client.channels.get(room);
      if(channel && typeof channel.on === 'function'){
        try{
          channel.on(function(stateChange){
            if(!stateChange) return;
            if(stateChange.current === 'detached' || stateChange.current === 'failed' || stateChange.current === 'suspended'){
              clearTypingUsers();
            }
          });
        }catch(err){/* ignore */}
      }
      channel.presence.enter({ typing: false, name: myName })
        .then(function(){ syncTypingState(); })
        .catch(function(){});
      syncTypingState();
      channel.presence.subscribe(function(msg){
        updateIndicatorFromPresence(msg);
      });
      channel.presence.get(function(err, members){
        if(members){
          members.forEach(function(member){
            if(!member) return;
            const clientId = member.clientId || '';
            const myClientId = myId ? 'wpuser_' + myId : '';
            const connectionId = member.connectionId || '';
            if(connectionId && localConnectionId && connectionId === localConnectionId) return;
            if(!connectionId){
              if(myClientId && clientId === myClientId) return;
            }
            const data = member.data || {};
            if(data.typing){
              const key = presenceKeyFor(member) || (clientId ? 'client:' + clientId : (member.id ? 'id:' + member.id : 'anon'));
              setTypingForKey(key, data.name, true);
            }
          });
          updateIndicator();
        }
      });
    }).catch(function(){
      stopPruneTimer();
    });

    const textarea = chat.querySelector('textarea');
    if(textarea){
      textarea.addEventListener('input', onLocalInput);
      textarea.addEventListener('focus', onLocalInput);
      textarea.addEventListener('blur', function(){ setDesiredTypingState(false); });
      textarea.addEventListener('keydown', function(event){
        if(event.key === 'Enter' && (event.ctrlKey || event.metaKey)){
          setTimeout(function(){ setDesiredTypingState(false); }, 60);
        }
      });
    }

    const sendButton = chat.querySelector('.composer .send');
    if(sendButton){
      sendButton.addEventListener('click', function(){
        setDesiredTypingState(false);
      });
    }

    window.addEventListener('beforeunload', function(){
      stopPruneTimer();
      desiredTypingState = false;
      syncTypingState();
      if(channel){
        try{
          channel.presence.leave();
        }catch(err){
          /* ignore */
        }
      }
    });

    initializedChats.add(chat);
  }

  function processPending(){
    if(!pendingChats.length) return;
    const batch = pendingChats.splice(0, pendingChats.length);
    batch.forEach(function(chat){
      setupChat(chat);
    });
  }

  ready(function(){
    const chats = document.querySelectorAll('.elxao-chat[data-room]');
    if(chats && chats.length){
      chats.forEach(function(chat){ setupChat(chat); });
    }
    processPending();
  });

  window.ELXAO_CHAT_INIT_TYPING = function(chat){
    if(!chat || typeof chat !== 'object') return;
    if(document.readyState === 'loading'){
      pendingChats.push(chat);
    }else{
      setupChat(chat);
    }
  };

  processPending();
})();

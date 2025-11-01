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

  ready(function(){
    const chats = document.querySelectorAll('.elxao-chat[data-room]');
    if(!chats || !chats.length) return;

    chats.forEach(function(chat){
      const room = chat.dataset.room;
      const myName = chat.dataset.myname || '';
      const myId = parseInt(chat.dataset.myid || '0', 10) || 0;
      const restBase = chat.dataset.rest || '';
      const restNonce = chat.dataset.nonce || '';
      const projectId = parseInt(chat.dataset.project || '0', 10) || 0;
      if(!room) return;

      const indicator = document.createElement('div');
      indicator.className = 'typing-indicator';
      indicator.style.display = 'none';
      chat.insertBefore(indicator, chat.querySelector('.composer'));

      (function(){
        if(!document.getElementById('elxao-typing-indicator-style')){
          const style = document.createElement('style');
          style.id = 'elxao-typing-indicator-style';
          style.textContent = '.typing-indicator{padding:0 20px 10px 20px;font-style:italic;color:#64748b;font-size:14px}';
          document.head.appendChild(style);
        }
      })();

      const typingUsers = new Map();
      let typingState = false;
      let desiredTypingState = false;
      let typingTimeoutId = null;
      const TYPING_DELAY_MS = 3000;
      let channel = null;
      let clientPromise = null;
      let localConnectionId = null;
      let syncingTypingState = false;
      let resyncRequested = false;

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
              return;
            }
            if(event.current === 'connected' || event.current === 'update'){
              refreshLocalConnectionId(client);
            }
          });
        }catch(err){/* ignore */}
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
      
      function updateIndicator(){
        const names = Array.from(typingUsers.values());
        if(names.length > 0){
          const label = names.join(', ') + (names.length === 1 ? ' is typing…' : ' are typing…');
          indicator.textContent = label;
          indicator.style.display = '';
        }else{
          indicator.textContent = '';
          indicator.style.display = 'none';
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

      const ably = window.ELXAO_ABLY;
      if(!ably || !ably.ensureClient) return;
      if(!restBase || !projectId) return;

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

      ensureRealtimeClient().then(function(client){
        refreshLocalConnectionId(client);
        watchConnection(client);
        channel = client.channels.get(room);
        channel.presence.enter({ typing: false, name: myName })
          .then(function(){ syncTypingState(); })
          .catch(function(){});
        syncTypingState();
        channel.presence.subscribe(function(msg){
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
            typingUsers.set(key, data.name || 'Someone');
          }else{
            typingUsers.delete(key);
          }
          updateIndicator();
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
                typingUsers.set(key, data.name || 'Someone');
              }
            });
            updateIndicator();
          }
        });
      }).catch(function(){});

      const textarea = chat.querySelector('textarea');
      if(textarea) textarea.addEventListener('input', onLocalInput);

      window.addEventListener('beforeunload', function(){
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
    });
  });
})();

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

      function syncTypingState(){
        if(!channel) return;
        if(typingState === desiredTypingState) return;
        typingState = desiredTypingState;
        try{
          channel.presence.update({ typing: typingState, name: myName });
        }catch(err){/* ignore */}
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
        channel = client.channels.get(room);
        channel.presence.enter({ typing: false, name: myName })
          .then(function(){ syncTypingState(); })
          .catch(function(){});
        syncTypingState();
        channel.presence.subscribe(function(msg){
          if(!msg) return;
          const clientId = msg.clientId;
          const myClientId = myId ? 'wpuser_' + myId : '';
          if(clientId && myClientId && clientId === myClientId) return;
          const data = msg.data || {};
          if(data.typing){
            typingUsers.set(clientId, data.name || 'Someone');
          }else{
            typingUsers.delete(clientId);
          }
          updateIndicator();
        });
        channel.presence.get(function(err, members){
          if(members){
            members.forEach(function(member){
              if(!member || !member.clientId) return;
              const clientId = member.clientId;
              const myClientId = myId ? 'wpuser_' + myId : '';
              if(myClientId && clientId === myClientId) return;
              const data = member.data || {};
              if(data.typing){
                typingUsers.set(clientId, data.name || 'Someone');
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

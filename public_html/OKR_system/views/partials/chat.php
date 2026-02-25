<?php
// partials/chat.php — Centralized AI chat component with history + typing indicator
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$userId = $_SESSION['user_id'] ?? '';
$avatarUrl = '/OKR_system/assets/img/avatars/avatar_IA.png';
?>

<style>
/* Botão flutuante para abrir chat */
#chat_open_btn {
    position: fixed;
    top: 100px;
    right: 20px;
    width: 50px;
    height: 50px;
    background: #128C7E;
    border: none;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    z-index: 1000;
}
#chat_open_btn img {
    width: 32px;
    height: 32px;
    border-radius: 50%;
}

/* Container do chat */
.chat-container {
    position: fixed;
    right: 20px;
    bottom: 20px;
    width: 340px;
    height: 80vh;
    background: #ffffffff;
    box-shadow: -2px 0 5px rgba(0,0,0,0.1);
    border-radius: 8px;
    display: flex;
    flex-direction: column;
    overflow: hidden;
    z-index: 999;
}
.chat-container.hidden {
    display: none;
}

/* Estrutura do chat */
.chat-box {
    display: flex;
    flex-direction: column;
    height: 100%;
}
.chat-header {
    padding: 10px;
    background: #128C7E;
    font-weight: bold;
    color: #fff;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 6px;
}
.chat-header-title {
    flex: 1;
    font-size: .9rem;
}
.chat-header button {
    background: transparent;
    color: #fff;
    border: none;
    font-size: 1rem;
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 4px;
    transition: background .15s;
}
.chat-header button:hover {
    background: rgba(255,255,255,.15);
}
.chat-messages {
    flex: 1;
    padding: 10px;
    background: #202021;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.chat-messages .bot,
.chat-messages .user {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}
.chat-avatar,
.user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    flex-shrink: 0;
}
.bot-message,
.user-message {
    padding: 8px 12px;
    border-radius: 8px;
    max-width: 80%;
    word-wrap: break-word;
    font-size: 0.75rem;
}
.bot-message {
    background: #fff;
}
.user {
    justify-content: flex-end;
}
.user-message {
    background: #dcf8c6;
}
.chat-input {
    display: flex;
    align-items: center;
    padding: 10px;
    border-top: 1px solid #747474ff;
    background: #202021;
}
.chat-input input {
    flex: 1;
    padding: 0.75rem 1rem;
    font-size: 0.75rem;
    border: 1px solid #808080ff;
    background: #dcf8c6;
    border-radius: 20px;
    outline: none;
    margin-right: 0.5rem;
}
.chat-input button {
    width: 40px;
    height: 40px;
    border: none;
    background: #128C7E;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    cursor: pointer;
}

/* Typing indicator */
.chat-typing {
    display: none;
    align-items: center;
    gap: 0.5rem;
    padding: 4px 0;
}
.chat-typing.active {
    display: flex;
}
.chat-typing .dots {
    display: flex;
    gap: 3px;
}
.chat-typing .dots span {
    width: 6px;
    height: 6px;
    background: #888;
    border-radius: 50%;
    animation: chatDotPulse 1.2s infinite ease-in-out;
}
.chat-typing .dots span:nth-child(2) { animation-delay: .2s; }
.chat-typing .dots span:nth-child(3) { animation-delay: .4s; }
@keyframes chatDotPulse {
    0%, 60%, 100% { opacity: .3; transform: scale(.8); }
    30% { opacity: 1; transform: scale(1); }
}
.chat-typing-label {
    color: #aaa;
    font-size: .7rem;
}
</style>

<button id="chat_open_btn">
    <img src="<?php echo $avatarUrl; ?>" alt="Abrir chat">
</button>

<div class="chat-container hidden" id="chat_container">
    <div class="chat-box">
        <div class="chat-header">
            <span class="chat-header-title">OKR Master (IA Especializada)</span>
            <button id="chat_clear" title="Limpar conversa"><i class="fas fa-trash-alt"></i></button>
            <button id="chat_hide" title="Fechar chat">&#9654;</button>
        </div>
        <div class="chat-messages" id="chat_messages">
            <div class="bot">
                <img src="<?php echo $avatarUrl; ?>" alt="Avatar" class="chat-avatar">
                <div class="bot-message">Se quiser compartilhar o que deseja ou houver dúvidas, me chame aqui 😉.</div>
            </div>
        </div>
        <div class="chat-typing" id="chat_typing">
            <img src="<?php echo $avatarUrl; ?>" alt="" class="chat-avatar" style="width:24px;height:24px;">
            <div class="dots"><span></span><span></span><span></span></div>
            <span class="chat-typing-label">OKR Master digitando...</span>
        </div>
        <div class="chat-input">
            <input type="text" id="chat_message" placeholder="Digite uma mensagem...">
            <button id="chat_send"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
</div>

<script>
(function() {
    const container = document.getElementById('chat_container');
    const openBtn = document.getElementById('chat_open_btn');
    const hideBtn = document.getElementById('chat_hide');
    const clearBtn = document.getElementById('chat_clear');
    const main = document.getElementById('main-content');
    const avatar = '<?php echo addslashes($avatarUrl); ?>';
    const messagesContainer = document.getElementById('chat_messages');
    const chatInput = document.getElementById('chat_message');
    const chatSendBtn = document.getElementById('chat_send');
    const typingEl = document.getElementById('chat_typing');

    let historyLoaded = false;

    // --- Utilities ---
    function escapeHTML(s) {
        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function formatBotReply(text) {
        let t = escapeHTML(String(text ?? ''));
        t = t.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        t = t.replace(/(^|\s)\*([^*\n]+?)\*(?=\s|$)/g, '$1<strong>$2</strong>');
        t = t.replace(/\n/g, '<br>');
        return t;
    }

    function appendMessage(role, text) {
        const msgEl = document.createElement('div');
        msgEl.className = role;

        if (role === 'bot' || role === 'assistant') {
            msgEl.className = 'bot';
            msgEl.innerHTML = `
              <img src="${avatar}" class="chat-avatar" alt="Assistente">
              <div class="bot-message">${formatBotReply(text)}</div>`;
        } else {
            msgEl.className = 'user';
            const safe = escapeHTML(String(text ?? ''));
            msgEl.innerHTML = `
              <div class="user-message">${safe}</div>
              <img src="${avatar}" class="user-avatar" alt="Você">`;
        }

        messagesContainer.appendChild(msgEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function showTyping(on) {
        if (typingEl) {
            typingEl.classList.toggle('active', on);
            if (on) messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
    }

    // --- Load history from server ---
    async function loadHistory() {
        if (historyLoaded) return;
        historyLoaded = true;
        try {
            const res = await fetch(`${window.location.origin}/OKR_system/api/chat_history.php`);
            const data = await res.json();
            if (data?.success && Array.isArray(data.messages) && data.messages.length > 0) {
                // Clear welcome message when history exists
                messagesContainer.innerHTML = '';
                data.messages.forEach(m => {
                    appendMessage(m.role, m.content);
                });
            }
        } catch (e) {
            console.error('Failed to load chat history:', e);
        }
    }

    // --- Send message ---
    async function sendMessage(text) {
        appendMessage('user', text);
        showTyping(true);
        try {
            const res = await fetch(`${window.location.origin}/OKR_system/api/chat_api.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ message: text })
            });

            const raw = await res.text();
            let data = {};
            try { data = JSON.parse(raw); } catch { data = { raw }; }

            showTyping(false);

            const msg =
                (data && typeof data.error === 'string' && data.error.trim()) ? `⚠️ ${data.error}` :
                (data && typeof data.reply === 'string' && data.reply.trim()) ? data.reply :
                (raw && raw.trim()) ? `⚠️ ${raw}` :
                `⚠️ HTTP ${res.status}`;

            appendMessage('bot', msg);
        } catch (e) {
            showTyping(false);
            console.error(e);
            appendMessage('bot', '⚠️ Falha de rede ao chamar o servidor.');
        }
    }

    // --- Clear conversation ---
    async function clearConversation() {
        try {
            await fetch(`${window.location.origin}/OKR_system/api/chat_api.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ action: 'clear' })
            });
            messagesContainer.innerHTML = '';
            appendMessage('bot', 'Conversa limpa. Como posso ajudar?');
            historyLoaded = true; // prevent reloading old messages
        } catch (e) {
            console.error('Failed to clear chat:', e);
        }
    }

    // --- Chat width management (centralized, no duplication needed in views) ---
    const CHAT_SELECTORS = ['#chatPanel','.chat-panel','.chat-container','#chat','.drawer-chat'];
    const TOGGLE_SELECTORS = ['#chatToggle','.chat-toggle','.btn-chat-toggle','.chat-icon','.chat-open'];

    function findChatEl() {
        for (const s of CHAT_SELECTORS) {
            const el = document.querySelector(s);
            if (el) return el;
        }
        return null;
    }
    function isChatOpen(el) {
        const st = getComputedStyle(el);
        const vis = st.display !== 'none' && st.visibility !== 'hidden';
        const w = el.offsetWidth;
        return (vis && w > 0) || el.classList.contains('open') || el.classList.contains('show');
    }
    function updateChatWidth() {
        const el = findChatEl();
        const w = (el && isChatOpen(el)) ? el.offsetWidth : 0;
        document.documentElement.style.setProperty('--chat-w', (w || 0) + 'px');
    }
    function setupChatObservers() {
        const chat = findChatEl();
        if (!chat) return;
        const mo = new MutationObserver(() => updateChatWidth());
        mo.observe(chat, { attributes: true, attributeFilter: ['style', 'class', 'aria-expanded'] });
        window.addEventListener('resize', updateChatWidth);
        TOGGLE_SELECTORS.forEach(s =>
            document.querySelectorAll(s).forEach(btn =>
                btn.addEventListener('click', () => setTimeout(updateChatWidth, 200))
            )
        );
        updateChatWidth();
    }

    // --- Event listeners ---
    openBtn.addEventListener('click', () => {
        container.classList.remove('hidden');
        openBtn.style.display = 'none';
        updateChatWidth();
        loadHistory();
        chatInput.focus();
    });

    hideBtn.addEventListener('click', () => {
        container.classList.add('hidden');
        openBtn.style.display = 'flex';
        updateChatWidth();
    });

    clearBtn.addEventListener('click', clearConversation);

    chatSendBtn.addEventListener('click', () => {
        const text = chatInput.value.trim();
        if (!text) return;
        chatInput.value = '';
        sendMessage(text);
    });

    chatInput.addEventListener('keypress', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            chatSendBtn.click();
        }
    });

    // --- Initialize chat observers on DOMContentLoaded ---
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setupChatObservers);
    } else {
        setupChatObservers();
    }

    // Expose updateChatWidth globally so any view can call it if needed
    window.__chatUpdateWidth = updateChatWidth;
})();
</script>

<?php
// Tutorial component (loaded once per page, alongside chat)
include_once __DIR__ . '/tutorial.php';
?>

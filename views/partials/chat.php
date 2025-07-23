<?php
// partials/chat.php
// Inicia sessão apenas se ainda não iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// Captura ID do usuário da sessão
$userId = $_SESSION['user_id'] ?? '';

// Detecta avatar com qualquer extensão disponível
$avatarDir = $_SERVER['DOCUMENT_ROOT'] . '/OKR_system/assets/img/avatars/';
$files = glob($avatarDir . $userId . '.*');
if (!empty($files)) {
    $avatarUrl = str_replace($_SERVER['DOCUMENT_ROOT'], '', $files[0]);
} else {
    // avatar padrão
    $avatarUrl = '/OKR_system/assets/img/avatars/user-avatar.png';
}
?>

<style>
/* Botão flutuante para abrir chat */
#chat_open_btn {
    position: fixed;
    top: 100px; /* Ajuste conforme necessário */
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
    width: 300px;
    height: 80vh;
    background: #fff;
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
    background: #ededed;
    font-weight: bold;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.chat-header button {
    background: transparent;
    border: none;
    font-size: 1rem;
    cursor: pointer;
}
.chat-messages {
    flex: 1;
    padding: 10px;
    background: #f9f9f9;
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
    border-top: 1px solid #ccc;
    background: #fff;
}
.chat-input input {
    flex: 1;
    padding: 0.75rem 1rem;
    font-size: 0.75rem;
    border: 1px solid #ccc;
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
</style>

<button id="chat_open_btn">
    <img src="<?php echo $avatarUrl; ?>" alt="Abrir chat">
</button>

<div class="chat-container hidden" id="chat_container">
    <div class="chat-box">
        <div class="chat-header">
            OKR Master
            <button id="chat_hide">&#9654;</button>
        </div>
        <div class="chat-messages" id="chat_messages">
            <div class="bot">
                <img src="<?php echo $avatarUrl; ?>" alt="Avatar" class="chat-avatar">
                <div class="bot-message">Se quiser compartilhar o que deseja ou houver dúvidas, me chame aqui 😉.</div>
            </div>
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
    const main = document.getElementById('main-content');
    const avatar = '<?php echo addslashes($avatarUrl); ?>';
    const messagesContainer = document.getElementById('chat_messages');
    const chatInput = document.getElementById('chat_message');
    const chatSendBtn = document.getElementById('chat_send');

    function appendMessage(role, text) {
        const msgEl = document.createElement('div');
        msgEl.className = role;
        if (role === 'bot') {
            msgEl.innerHTML = `<img src="${avatar}" class="chat-avatar" alt="Assistente"><div class="bot-message">${text}</div>`;
        } else {
            msgEl.innerHTML = `<div class="user-message">${text}</div><img src="${avatar}" class="user-avatar" alt="Você">`;
        }
        messagesContainer.appendChild(msgEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    async function sendMessage(text) {
        appendMessage('user', text);
        try {
            const res = await fetch(`${window.location.origin}/OKR_system/api/chat_api.php`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({message: text})
            });
            const data = await res.json();
            appendMessage('bot', data.reply || 'Sem resposta do servidor.');
        } catch (e) {
            console.error(e);
            appendMessage('bot', 'Erro ao processar sua mensagem.');
        }
    }

    openBtn.addEventListener('click', () => {
        container.classList.remove('hidden');
        openBtn.style.display = 'none';
        if (main) main.style.marginRight = '340px';
    });

    hideBtn.addEventListener('click', () => {
        container.classList.add('hidden');
        openBtn.style.display = 'flex';
        if (main) main.style.marginRight = '';
    });

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
})();
</script>

<!-- acabou -->

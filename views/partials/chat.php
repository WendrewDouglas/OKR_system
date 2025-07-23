<?php
// partials/chat.php
// Inicia sessão apenas se ainda não iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
// Captura o ID de usuário da sessão
$userId = $_SESSION['user_id'] ?? '';

// Detecta avatar com qualquer extensão disponível
$avatarDir = $_SERVER['DOCUMENT_ROOT'] . '/OKR_system/assets/img/avatars/';
$files = glob($avatarDir . $userId . '.*');
if (!empty($files)) {
    // usa o primeiro arquivo encontrado
    $avatarUrl = str_replace($_SERVER['DOCUMENT_ROOT'], '', $files[0]);
} else {
    // avatar padrão caso não exista
    $avatarUrl = '/OKR_system/assets/img/avatars/user-avatar.png';
}
?>

<style>
/* Chat container */
.chat-container {
    flex: 1;
    height: 80vh;
    position: sticky;
    top: 5rem;
    align-self: flex-start;
    display: flex;
    flex-direction: column;
}

/* Chat box structure */
.chat-box {
    flex: 1;
    display: flex;
    flex-direction: column;
    border: 1px solid #ccc;
    border-radius: 8px;
    overflow: hidden;
}

.chat-header {
    padding: 10px;
    background: #ededed;
    font-weight: bold;
}

.chat-messages {
    flex: 1;
    padding: 10px;
    background: #f9f9f9;
    overflow-y: auto;
}

.chat-messages .bot {
    margin-bottom: 10px;
    font-size: 0.85rem;
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
}

.chat-avatar, .user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    flex-shrink: 0;
}

.bot-message {
    background: #fff;
    padding: 8px 12px;
    border-radius: 8px;
    max-width: 80%;
}

.chat-box .user {
    display: flex;
    justify-content: flex-end;
    align-items: flex-start;
    gap: 0.5rem;
    margin-bottom: 10px;
}

.user-message {
    background: #dcf8c6;
    padding: 8px 12px;
    border-radius: 8px;
    max-width: 80%;
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
    font-size: 1rem;
    border: 1px solid #ccc;
    border-radius: 20px;
    outline: none;
    margin-right: 0.5rem;
}

.chat-input button {
    width: 40px;
    height: 40px;
    padding: 0;
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

<div class="chat-container">
    <div class="chat-box">
        <div class="chat-header">Assistente</div>
        <div class="chat-messages">
            <div class="bot">
                <img src="/OKR_system/assets/img/avatars/1.png" alt="Avatar" class="chat-avatar">
                <div class="bot-message">Se quiser compartilhar o que deseja ou dúvidas, me chame aqui e podemos montar o objetivo juntos.</div>
            </div>
        </div>
        <div class="chat-input">
            <input type="text" id="chat_message" class="form-control" placeholder="Digite uma mensagem...">
            <button id="chat_send" class="btn btn-primary"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
</div>

<script>
(async function() {
    const userAvatarUrl = '<?php echo addslashes($avatarUrl); ?>';
    const messagesContainer = document.querySelector('.chat-messages');
    const chatInput = document.getElementById('chat_message');
    const chatSendBtn = document.getElementById('chat_send');

    function appendBotMessage(text) {
        const msgEl = document.createElement('div');
        msgEl.className = 'bot';
        msgEl.innerHTML = `
            <img src="/OKR_system/assets/img/avatars/1.png" class="chat-avatar">
            <div class="bot-message">${text}</div>
        `;
        messagesContainer.appendChild(msgEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function appendUserMessage(text) {
        const msgEl = document.createElement('div');
        msgEl.className = 'user';
        msgEl.innerHTML = `
            <div class="user-message">${text}</div>
            <img src="${userAvatarUrl}" class="user-avatar">
        `;
        messagesContainer.appendChild(msgEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    async function sendMessage(text) {
        appendUserMessage(text);
        try {
            const res = await fetch('/OKR_system/api/chat_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });
            const { reply } = await res.json();
            appendBotMessage(reply);
        } catch (err) {
            console.error(err);
            appendBotMessage('Desculpe, ocorreu um erro ao processar sua mensagem.');
        }
    }

    chatSendBtn.addEventListener('click', () => {
        const text = chatInput.value.trim();
        if (!text) return;
        chatInput.value = '';
        sendMessage(text);
    });

    chatInput.addEventListener('keypress', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            chatSendBtn.click();
        }
    });
})();
</script>
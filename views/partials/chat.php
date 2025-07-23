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
/* Chat container */
.chat-container {
    flex: 1;
    height: 80vh;
    position: sticky;
    top: 5rem;
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

.chat-avatar, .user-avatar {
    width: 32px;
    height: 32px;
    border-radius: 50%;
    flex-shrink: 0;
}

.bot .bot-message,
.user .user-message {
    padding: 8px 12px;
    border-radius: 8px;
    max-width: 80%;
    word-wrap: break-word;
}

.bot .bot-message {
    background: #fff;
    font-size: 0.75rem;
}

.user {
    justify-content: flex-end;
}

.user .user-message {
    background: #dcf8c6;
    font-size: 0.75rem;
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

<div class="chat-container">
    <div class="chat-box">
        <div class="chat-header">OKR Master</div>
        <div class="chat-messages" id="chat_messages">
            <div class="bot">
                <img src="/OKR_system/assets/img/avatars/1.png" alt="Avatar" class="chat-avatar">
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
(async function() {
    const userAvatarUrl = '<?php echo addslashes($avatarUrl); ?>';
    const messagesContainer = document.getElementById('chat_messages');
    const chatInput = document.getElementById('chat_message');
    const chatSendBtn = document.getElementById('chat_send');

    function appendMessage(role, text) {
        const msgEl = document.createElement('div');
        msgEl.className = role;
        if (role === 'bot') {
            msgEl.innerHTML = `
                <img src="/OKR_system/assets/img/avatars/1.png" class="chat-avatar" alt="Assistente">
                <div class="bot-message">${text}</div>
            `;
        } else {
            msgEl.innerHTML = `
                <div class="user-message">${text}</div>
                <img src="${userAvatarUrl}" class="user-avatar" alt="Você">
            `;
        }
        messagesContainer.appendChild(msgEl);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    async function sendMessage(text) {
        appendMessage('user', text);
        try {
            // Usa caminho absoluto baseado no domínio atual
            const apiUrl = `${window.location.origin}/OKR_system/api/chat_api.php`;
            const res = await fetch(apiUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text })
            });
            const data = await res.json();
            appendMessage('bot', data.reply || 'Sem resposta do servidor.');
        } catch (err) {
            console.error(err);
            appendMessage('bot', 'Erro ao processar sua mensagem.');
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

<!-- acabou -->
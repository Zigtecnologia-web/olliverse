function appendMessage(text, sender, renderMarkdown = false, responseDurationMs = null) {
    const container = document.getElementById('chatMessages');
    const messageGroup = document.createElement('div');
    const messageDiv = document.createElement('div');

    messageGroup.className = `message-group ${sender}`;
    messageDiv.className = `message ${sender}`;

    if (sender === 'assistant') {
        messageGroup.appendChild(createOlliverseMessageAvatar());
    }

    messageGroup.appendChild(messageDiv);

    if (sender === 'user') {
        renderUserMessage(messageGroup, messageDiv, text);
    } else if (renderMarkdown) {
        renderAssistantMessage(messageGroup, messageDiv, text, responseDurationMs);
    } else {
        messageDiv.textContent = text;
    }

    container.appendChild(messageGroup);
    scrollToBottom();
}

function createStreamingAssistantMessage() {
    const container = document.getElementById('chatMessages');
    const messageGroup = document.createElement('div');
    const messageDiv = document.createElement('div');

    messageGroup.className = 'message-group assistant';
    messageDiv.className = 'message assistant';
    messageDiv.innerHTML = '<div class="typing-indicator"><div class="typing-dot"></div><div class="typing-dot"></div><div class="typing-dot"></div></div>';
    messageGroup.appendChild(createOlliverseMessageAvatar());
    messageGroup.appendChild(messageDiv);
    container.appendChild(messageGroup);
    scrollToBottom();

    return {
        group: messageGroup,
        message: messageDiv,
    };
}

function createOlliverseMessageAvatar() {
    const avatar = document.createElement('span');

    avatar.className = 'message-avatar olliverse-message-avatar';
    avatar.setAttribute('aria-hidden', 'true');
    avatar.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" stroke="#00F576" stroke-width="2.5" stroke-dasharray="4 2"></circle><circle cx="12" cy="12" r="4" fill="#00F576"></circle></svg>';

    return avatar;
}

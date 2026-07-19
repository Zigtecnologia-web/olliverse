function appendMessage(text, sender, renderMarkdown = false) {
    const container = document.getElementById('chatMessages');
    const messageGroup = document.createElement('div');
    const messageDiv = document.createElement('div');

    messageGroup.className = `message-group ${sender}`;
    messageDiv.className = `message ${sender}`;
    messageGroup.appendChild(messageDiv);

    if (sender === 'user') {
        renderUserMessage(messageGroup, messageDiv, text);
    } else if (renderMarkdown) {
        renderAssistantMessage(messageGroup, messageDiv, text);
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
    messageGroup.appendChild(messageDiv);
    container.appendChild(messageGroup);
    scrollToBottom();

    return {
        group: messageGroup,
        message: messageDiv,
    };
}

const {
    initialAssistantMessage,
    hasAvailableModels,
    initialContextUsage,
    skillPresets,
} = window.OlliverseConfig;
marked.setOptions({ breaks: true });
updateContextUsage(initialContextUsage);
initModelPicker();

document.getElementById('chatForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const inputEl = document.getElementById('userInput');
    const modelSelect = document.getElementById('modelSelect');
    const modelMenuButton = document.getElementById('modelMenuButton');
    const modelInfoBtn = document.getElementById('modelInfoBtn');
    const sendBtn = document.getElementById('sendBtn');
    const newChatBtn = document.getElementById('newChatBtn');
    const messagesContainer = document.getElementById('chatMessages');
    const prompt = inputEl.value.trim();
    const model = modelSelect.value;

    if (!prompt || !model || !hasAvailableModels) return;

    // 1. Adiciona a mensagem do usuário na interface
    appendMessage(prompt, 'user');
    inputEl.value = '';
    
    // Desativa os campos enquanto a IA pensa
    inputEl.disabled = true;
    modelSelect.disabled = true;
    modelMenuButton.disabled = true;
    modelInfoBtn.disabled = true;
    sendBtn.disabled = true;
    newChatBtn.disabled = true;

    const assistantMessage = createStreamingAssistantMessage();
    let assistantText = '';

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'prompt=' + encodeURIComponent(prompt) + '&model=' + encodeURIComponent(model)
    })
    .then(response => {
        if (!response.ok) throw new Error('Erro na requisição.');
        return streamAssistantResponse(response, assistantMessage, (chunk) => {
            assistantText += chunk;
        }, (payload) => {
            if (payload.type === 'meta' && payload.context_usage) {
                updateContextUsage(payload.context_usage);
            }

            if (payload.type === 'error') {
                if (payload.context_usage) {
                    updateContextUsage(payload.context_usage);
                }

                throw new Error(payload.message || 'Erro ao processar a resposta.');
            }
        });
    })
    .then(() => {
        finalizeStreamingAssistantMessage(assistantMessage, assistantText);
    })
    .catch(error => {
        assistantMessage.group.remove();
        appendMessage(error.message || 'Erro ao processar a resposta.', 'error');
        console.error(error);
    })
    .finally(() => {
        // Reativa os campos de entrada
        inputEl.disabled = false;
        modelSelect.disabled = !hasAvailableModels;
        modelMenuButton.disabled = !hasAvailableModels;
        modelInfoBtn.disabled = !hasAvailableModels;
        sendBtn.disabled = false;
        newChatBtn.disabled = false;
        inputEl.focus();
        scrollToBottom();
    });
});

document.getElementById('newChatBtn').addEventListener('click', function() {
    window.location.href = '?clear=1';
});

document.getElementById('modelInfoBtn').addEventListener('click', openModelInfoModal);
document.getElementById('closeModelInfoModalBtn').addEventListener('click', closeModelInfoModal);
document.getElementById('modelInfoModal').addEventListener('click', function(event) {
    if (event.target === event.currentTarget) {
        closeModelInfoModal();
    }
});
document.getElementById('settingsBtn').addEventListener('click', openSkillModal);
document.getElementById('closeSkillModalBtn').addEventListener('click', closeSkillModal);
document.getElementById('skillModal').addEventListener('click', function(event) {
    if (event.target === event.currentTarget) {
        closeSkillModal();
    }
});
document.getElementById('skillPreset').addEventListener('change', function(event) {
    const presetValue = event.target.value;

    if (skillPresets[presetValue]) {
        document.getElementById('systemPromptInput').value = skillPresets[presetValue];
    }
});
document.getElementById('systemPromptInput').addEventListener('input', function() {
    document.getElementById('skillPreset').value = 'custom';
});
document.getElementById('skillForm').addEventListener('submit', function(event) {
    event.preventDefault();
    saveSkillConfig();
});
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('skillModal').classList.contains('open')) {
        closeSkillModal();
    }

    if (event.key === 'Escape' && document.getElementById('modelInfoModal').classList.contains('open')) {
        closeModelInfoModal();
    }

    if (event.key === 'Escape') {
        closeModelMenu();
    }
});

document.addEventListener('click', function(event) {
    const picker = document.getElementById('modelPicker');

    if (picker && !picker.contains(event.target)) {
        closeModelMenu();
    }
});

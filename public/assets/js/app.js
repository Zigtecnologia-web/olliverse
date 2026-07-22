const {
    initialAssistantMessage,
    hasAvailableModels,
    initialContextUsage,
} = window.OlliverseConfig;
marked.setOptions({ breaks: true });
updateContextUsage(initialContextUsage);
renderPersistedAssistantMessages();
initModelPicker();
initPersonaControls();
initHistoryPanel();
initRagPanel();
initPluginPanel();

function submitChatMessage(overridePrompt = null) {
    const inputEl = document.getElementById('userInput');
    const modelSelect = document.getElementById('modelSelect');
    const modelMenuButton = document.getElementById('modelMenuButton');
    const modelInfoBtn = document.getElementById('modelInfoBtn');
    const personaSelect = document.getElementById('personaSelect');
    const sendBtn = document.getElementById('sendBtn');
    const newChatBtn = document.getElementById('newChatBtn');
    const ragToggle = document.getElementById('ragToggle');
    const ragPickFileBtn = document.getElementById('ragPickFileBtn');
    const ragUploadBtn = document.getElementById('ragUploadBtn');
    const ragAllDocuments = document.getElementById('ragAllDocuments');
    const prompt = String(overridePrompt ?? inputEl.value).trim();
    const model = modelSelect.value;
    const ragEnabled = ragToggle?.checked ? '1' : '0';
    const body = new URLSearchParams({
        prompt,
        model,
        rag_enabled: ragEnabled,
        rag_all_documents: ragAllDocuments?.checked ? '1' : '0',
    });

    getSelectedRagDocumentIds().forEach((documentId) => {
        body.append('rag_document_ids[]', String(documentId));
    });

    if (!prompt || !model || !hasAvailableModels) return;

    // 1. Adiciona a mensagem do usuário na interface
    appendMessage(prompt, 'user');
    inputEl.value = '';
    
    // Desativa os campos enquanto a IA pensa
    inputEl.disabled = true;
    modelSelect.disabled = true;
    modelMenuButton.disabled = true;
    modelInfoBtn.disabled = true;
    personaSelect.disabled = true;
    if (ragToggle) ragToggle.disabled = true;
    if (ragPickFileBtn) ragPickFileBtn.disabled = true;
    if (ragUploadBtn) ragUploadBtn.disabled = true;
    setRagDocumentControlsDisabled(true);
    sendBtn.disabled = true;
    newChatBtn.disabled = true;

    const assistantMessage = createStreamingAssistantMessage();
    let assistantText = '';
    let ragSources = [];

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString()
    })
    .then(response => {
        if (!response.ok) throw new Error('Erro na requisição.');
        return streamAssistantResponse(response, assistantMessage, (chunk) => {
            assistantText += chunk;
        }, (payload) => {
            if (payload.type === 'meta' && payload.context_usage) {
                updateContextUsage(payload.context_usage);
            }

            if (payload.type === 'rag_metadata') {
                ragSources = payload.sources || [];
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
        appendRagSources(assistantMessage.group, ragSources);
        refreshChatHistory();
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
        personaSelect.disabled = false;
        if (ragToggle) ragToggle.disabled = false;
        if (ragPickFileBtn) ragPickFileBtn.disabled = false;
        if (ragUploadBtn) ragUploadBtn.disabled = false;
        setRagDocumentControlsDisabled(false);
        sendBtn.disabled = false;
        newChatBtn.disabled = false;
        inputEl.focus();
    });
}

window.OlliverseSubmitMessage = submitChatMessage;

document.getElementById('chatForm').addEventListener('submit', function(e) {
    e.preventDefault();
    submitChatMessage();
});

const newChatButton = document.getElementById('newChatBtn');
newChatButton.addEventListener('click', createNewChat);
attachActionTooltip(newChatButton);

function createNewChat() {
    const newChatBtn = document.getElementById('newChatBtn');
    const inputEl = document.getElementById('userInput');
    const modelSelect = document.getElementById('modelSelect');
    const url = new URL(window.location.href);
    const body = new URLSearchParams({
        chat_id: String(window.OlliverseConfig.chatId || 0),
        model: modelSelect.value || '',
    });

    url.search = '';
    url.searchParams.set('action', 'new_chat');
    newChatBtn.disabled = true;

    fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'application/json',
        },
        body: body.toString(),
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao criar conversa.');
        }

        return payload;
    }))
    .then((payload) => {
        const chatId = Number(payload.chat?.id || 0);

        if (!chatId) {
            throw new Error('A nova conversa não retornou um identificador válido.');
        }

        window.OlliverseConfig.chatId = chatId;
        window.OlliverseConfig.activePersona = payload.active_persona || window.OlliverseConfig.activePersona;
        window.OlliverseConfig.initialChatHistory = payload.chats || window.OlliverseConfig.initialChatHistory;
        window.OlliverseState.historySearchQuery = '';
        window.OlliverseState.historySearchChatIds = null;
        window.clearTimeout(window.OlliverseState.historySearchTimer);
        document.getElementById('historySearchInput').value = '';
        renderLoadedChatMessages(payload.messages || []);
        updateContextUsage(payload.context_usage);
        renderPersonaSelect();
        fillPersonaForm(Number(window.OlliverseConfig.activePersona.id));
        updateExportLink();
        renderHistoryList(window.OlliverseConfig.initialChatHistory || []);
        window.history.pushState({}, '', `${window.location.pathname}?chat_id=${chatId}`);
        inputEl.value = '';
        inputEl.focus();
    })
    .catch((error) => {
        appendMessage(error.message || 'Erro ao criar conversa.', 'error');
        console.error(error);
    })
    .finally(() => {
        newChatBtn.disabled = false;
    });
}

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
document.getElementById('skillForm').addEventListener('submit', function(event) {
    event.preventDefault();
    saveSkillConfig();
});
document.getElementById('personaSelect').addEventListener('change', function(event) {
    selectPersona(Number(event.target.value));
});
document.getElementById('personaLibrarySelect').addEventListener('change', function(event) {
    fillPersonaForm(Number(event.target.value));
});
document.getElementById('newPersonaBtn').addEventListener('click', startNewPersona);
document.getElementById('deletePersonaBtn').addEventListener('click', deleteSelectedPersona);
const generatePromptBtn = document.getElementById('generatePromptBtn');
generatePromptBtn.addEventListener('click', generatePersonaPrompt);
attachActionTooltip(generatePromptBtn);
document.getElementById('personaNameInput').addEventListener('input', updateGeneratePromptButton);
document.getElementById('personaDescriptionInput').addEventListener('input', updateGeneratePromptButton);
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape' && document.getElementById('skillModal').classList.contains('open')) {
        closeSkillModal();
    }

    if (event.key === 'Escape' && document.getElementById('modelInfoModal').classList.contains('open')) {
        closeModelInfoModal();
    }

    if (event.key === 'Escape' && document.getElementById('deleteChatModal').classList.contains('open')) {
        closeDeleteChatModal();
    }

    if (event.key === 'Escape') {
        closeModelMenu();
        closeHistoryMenus();
        closeExportMenu();
        closePluginsMenu();
    }
});

document.addEventListener('click', function(event) {
    const picker = document.getElementById('modelPicker');

    if (picker && !picker.contains(event.target)) {
        closeModelMenu();
    }
});

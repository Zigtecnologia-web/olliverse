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
initProviderSelector();

function submitChatMessage(overridePrompt = null) {
    const inputEl = document.getElementById('userInput');
    const modelSelect = document.getElementById('modelSelect');
    const modelMenuButton = document.getElementById('modelMenuButton');
    const modelInfoBtn = document.getElementById('modelInfoBtn');
    const personaSelect = document.getElementById('personaSelect');
    const providerSelect = document.getElementById('providerSelect');
    const sendBtn = document.getElementById('sendBtn');
    const newChatBtn = document.getElementById('newChatBtn');
    const ragPickFileBtn = document.getElementById('ragPickFileBtn');
    const ragUploadBtn = document.getElementById('ragUploadBtn');
    const prompt = String(overridePrompt ?? inputEl.value).trim();
    const model = modelSelect.value;
    const selectedRagDocumentIds = getSelectedRagDocumentIds();
    const body = new URLSearchParams({
        prompt,
        model,
    });

    selectedRagDocumentIds.forEach((documentId) => {
        body.append('rag_document_ids[]', String(documentId));
    });

    if (!prompt) return;

    if (providerSelect?.value === 'web_ai') {
        submitWebAiMessage(prompt);
        return;
    }

    if (!model || !hasAvailableModels) return;

    // 1. Adiciona a mensagem do usuário na interface
    appendMessage(prompt, 'user');
    inputEl.value = '';
    
    // Desativa os campos enquanto a IA pensa
    inputEl.disabled = true;
    modelSelect.disabled = true;
    modelMenuButton.disabled = true;
    modelInfoBtn.disabled = true;
    personaSelect.disabled = true;
    providerSelect.disabled = true;
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
        providerSelect.disabled = false;
        if (ragPickFileBtn) ragPickFileBtn.disabled = false;
        if (ragUploadBtn) ragUploadBtn.disabled = false;
        setRagDocumentControlsDisabled(false);
        sendBtn.disabled = false;
        newChatBtn.disabled = false;
        inputEl.focus();
    });
}

window.OlliverseSubmitMessage = submitChatMessage;

function initProviderSelector() {
    const providerSelect = document.getElementById('providerSelect');
    const storedProvider = localStorage.getItem(window.OlliverseConfig.webAi.storageKey);

    if (!providerSelect) {
        return;
    }

    if (storedProvider === 'web_ai' || storedProvider === 'ollama') {
        providerSelect.value = storedProvider;
    }

    updateProviderMode();
    providerSelect.addEventListener('change', function() {
        localStorage.setItem(window.OlliverseConfig.webAi.storageKey, providerSelect.value);
        updateProviderMode();
    });
}

function updateProviderMode() {
    const providerSelect = document.getElementById('providerSelect');
    const modelMenuButton = document.getElementById('modelMenuButton');
    const modelInfoBtn = document.getElementById('modelInfoBtn');
    const isWebAi = providerSelect?.value === 'web_ai';

    if (modelMenuButton) {
        modelMenuButton.disabled = isWebAi || !hasAvailableModels;
    }

    if (modelInfoBtn) {
        modelInfoBtn.disabled = isWebAi || !hasAvailableModels;
    }
}

function setWebAiStatus(message, isError = false) {
    const status = document.getElementById('webAiStatus');
    const statusText = document.getElementById('webAiStatusText');
    const text = friendlyWebAiStatus(message);

    if (!status || !statusText) {
        return;
    }

    status.hidden = text === '';
    statusText.textContent = text;
    status.classList.toggle('error', isError);
    status.classList.toggle('complete', !isError && isCompleteWebAiStatus(text));
}

function friendlyWebAiStatus(message) {
    const text = String(message || '').trim();
    const lower = text.toLowerCase();
    const cacheMatch = text.match(/\[(\d+)\s*\/\s*(\d+)\]/);
    const sizeMatch = text.match(/(\d+(?:\.\d+)?)\s*([kmgt]b?|m)\b/i);

    if (text === '') {
        return '';
    }

    if (lower.includes('fetch') || lower.includes('param') || lower.includes('cache') || lower.includes('download')) {
        const step = cacheMatch ? ` Parte ${cacheMatch[1]} de ${cacheMatch[2]}.` : '';
        const size = sizeMatch ? ` Baixando aproximadamente ${sizeMatch[1]} MB.` : '';

        return `Baixando os arquivos da Web AI para este navegador.${step}${size} Aguarde, a conversa fica pausada ate terminar.`;
    }

    if (lower.includes('loading') || lower.includes('load') || lower.includes('init') || lower.includes('prefill')) {
        return 'Preparando a Web AI no navegador. Aguarde, a conversa fica pausada ate terminar.';
    }

    return text;
}

function isCompleteWebAiStatus(message) {
    const lower = String(message || '').toLowerCase();

    return lower.includes('pronta') || lower.includes('respondeu');
}

function webAiMessagesForPrompt(prompt, systemPromptOverride = null) {
    const messages = [];
    const systemPrompt = webAiSystemPrompt(systemPromptOverride);

    if (systemPrompt !== '') {
        messages.push({
            role: 'system',
            content: systemPrompt,
        });
    }

    (window.OlliverseConfig.initialMessages || []).forEach((message) => {
        const role = message.role === 'assistant' ? 'assistant' : 'user';
        const content = String(message.content || '').trim();

        if (content !== '') {
            messages.push({ role, content });
        }
    });

    messages.push({
        role: 'user',
        content: prompt,
    });

    return trimWebAiMessages(messages);
}

function webAiSystemPrompt(systemPromptOverride = null) {
    const prompts = [
        ...(window.OlliverseConfig.activePluginPrompts || []).map((prompt) => String(prompt || '').trim()),
        String(systemPromptOverride ?? window.OlliverseConfig.systemPrompt ?? '').trim(),
    ].filter(Boolean);

    return prompts.join('\n\n');
}

function trimWebAiMessages(messages) {
    const limit = Number(window.OlliverseConfig.webAi?.contextTokenLimit || 3400);
    const trimmedMessages = messages.map((message) => ({ ...message }));
    const systemMessage = trimmedMessages.find((message) => message.role === 'system');
    const userMessage = trimmedMessages[trimmedMessages.length - 1];

    window.OlliverseState.webAiContextTrimmed = false;

    while (webAiMessagesTokenCount(trimmedMessages) > limit && trimmedMessages.length > 2) {
        const firstConversationIndex = trimmedMessages.findIndex((message) => message.role !== 'system');

        if (firstConversationIndex < 0 || trimmedMessages[firstConversationIndex] === userMessage) {
            break;
        }

        trimmedMessages.splice(firstConversationIndex, 1);
        window.OlliverseState.webAiContextTrimmed = true;
    }

    if (webAiMessagesTokenCount(trimmedMessages) <= limit || !systemMessage) {
        return trimmedMessages;
    }

    const reservedTokens = estimateWebAiTokens(userMessage?.content || '') + 420;
    const maxSystemTokens = Math.max(350, limit - reservedTokens);
    const maxSystemChars = maxSystemTokens * 3;

    if (systemMessage.content.length > maxSystemChars) {
        systemMessage.content = compactWebAiSystemPrompt(systemMessage.content, maxSystemChars);
        window.OlliverseState.webAiContextTrimmed = true;
    }

    return trimmedMessages;
}

function webAiMessagesTokenCount(messages) {
    return messages.reduce((total, message) => total + estimateWebAiTokens(message.content || ''), 0);
}

function estimateWebAiTokens(text) {
    return Math.ceil(String(text || '').length / 3) + 8;
}

function compactWebAiSystemPrompt(text, maxChars) {
    const content = String(text || '').trim();

    if (content.length <= maxChars) {
        return content;
    }

    const headSize = Math.max(120, Math.floor(maxChars * 0.35));
    const tailSize = Math.max(120, maxChars - headSize - 90);

    return `${content.slice(0, headSize).trim()}\n\n[Contexto reduzido para caber na Web AI]\n\n${content.slice(-tailSize).trim()}`;
}

function submitWebAiMessage(prompt) {
    const inputEl = document.getElementById('userInput');
    const modelSelect = document.getElementById('modelSelect');
    const modelMenuButton = document.getElementById('modelMenuButton');
    const modelInfoBtn = document.getElementById('modelInfoBtn');
    const personaSelect = document.getElementById('personaSelect');
    const providerSelect = document.getElementById('providerSelect');
    const sendBtn = document.getElementById('sendBtn');
    const newChatBtn = document.getElementById('newChatBtn');
    const ragPickFileBtn = document.getElementById('ragPickFileBtn');

    appendMessage(prompt, 'user');
    inputEl.value = '';
    inputEl.disabled = true;
    inputEl.placeholder = 'Aguarde a Web AI terminar...';
    modelSelect.disabled = true;
    modelMenuButton.disabled = true;
    modelInfoBtn.disabled = true;
    personaSelect.disabled = true;
    providerSelect.disabled = true;
    if (ragPickFileBtn) ragPickFileBtn.disabled = true;
    setRagDocumentControlsDisabled(true);
    sendBtn.disabled = true;
    newChatBtn.disabled = true;

    const assistantMessage = createStreamingAssistantMessage();
    let assistantText = '';
    let ragSources = [];

    webAiRagContext(prompt)
    .then((ragContext) => {
        ragSources = ragContext.sources || [];

        if (ragSources.length > 0) {
            setWebAiStatus('Documentos recuperados para a Web AI.');
        }

        const messages = webAiMessagesForPrompt(prompt, ragContext.systemPrompt);

        if (window.OlliverseState.webAiContextTrimmed) {
            setWebAiStatus('Contexto ajustado para caber na Web AI.');
        }

        return window.OlliverseWebAI.generate(messages, (chunk) => {
            assistantText += chunk;
            renderAssistantMessageContent(assistantMessage.message, assistantText);
        }, setWebAiStatus);
    })
    .then((fullText) => persistWebAiExchange(prompt, fullText || assistantText))
    .then((payload) => {
        finalizeStreamingAssistantMessage(assistantMessage, assistantText);
        appendRagSources(assistantMessage.group, ragSources);
        window.OlliverseConfig.initialMessages = payload.messages || [
            ...(window.OlliverseConfig.initialMessages || []),
            { role: 'user', content: prompt },
            { role: 'assistant', content: assistantText },
        ];
        if (payload.context_usage) {
            updateContextUsage(payload.context_usage);
        }
        refreshChatHistory();
        setWebAiStatus(ragSources.length > 0 ? 'Web AI respondeu usando documentos.' : 'Web AI respondeu no navegador.');
    })
    .catch((error) => {
        assistantMessage.group.remove();
        appendMessage(error.message || 'Erro ao processar Web AI.', 'error');
        setWebAiStatus(error.message || 'Erro ao processar Web AI.', true);
        console.error(error);
    })
    .finally(() => {
        inputEl.disabled = false;
        inputEl.placeholder = 'Escreva sua mensagem aqui...';
        modelSelect.disabled = !hasAvailableModels;
        personaSelect.disabled = false;
        providerSelect.disabled = false;
        sendBtn.disabled = false;
        newChatBtn.disabled = false;
        if (ragPickFileBtn) ragPickFileBtn.disabled = false;
        setRagDocumentControlsDisabled(false);
        updateProviderMode();
        inputEl.focus();
    });
}

function webAiRagContext(prompt) {
    const selectedDocumentIds = getSelectedRagDocumentIds();

    if (selectedDocumentIds.length === 0) {
        return Promise.resolve({
            systemPrompt: window.OlliverseConfig.systemPrompt,
            sources: [],
        });
    }

    const url = new URL(window.location.href);
    const body = new URLSearchParams({
        prompt,
    });

    selectedDocumentIds.forEach((documentId) => {
        body.append('rag_document_ids[]', String(documentId));
    });

    url.searchParams.set('action', 'rag_context');
    setWebAiStatus('Buscando nos documentos...');

    return fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'application/json',
        },
        body: body.toString(),
    }).then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao recuperar documentos.');
        }

        return {
            systemPrompt: payload.system_prompt || window.OlliverseConfig.systemPrompt,
            sources: payload.sources || [],
        };
    }));
}

function persistWebAiExchange(prompt, assistantResponse) {
    const url = new URL(window.location.href);
    const body = new URLSearchParams({
        prompt,
        assistant_response: assistantResponse,
        web_ai_model: window.OlliverseConfig.webAi.modelId,
    });

    url.searchParams.set('action', 'web_ai_persist');

    return fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'application/json',
        },
        body: body.toString(),
    }).then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao salvar resposta Web AI.');
        }

        return payload;
    }));
}

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
        window.OlliverseConfig.initialMessages = payload.messages || [];
        window.OlliverseConfig.systemPrompt = String(window.OlliverseConfig.activePersona?.prompt_content || window.OlliverseConfig.systemPrompt || '');
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

    if (event.key === 'Escape' && document.getElementById('deleteRagDocumentModal').classList.contains('open')) {
        closeDeleteRagDocumentModal();
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

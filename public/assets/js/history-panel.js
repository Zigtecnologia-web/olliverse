function initHistoryPanel() {
    renderHistoryList(window.OlliverseConfig.initialChatHistory || []);
    updateExportLink();
    setHistoryOpen(Boolean(window.OlliverseState.historyOpen));

    document.querySelectorAll('.workspace-tools [data-tooltip], #historyCloseBtn').forEach((button) => {
        if (typeof attachActionTooltip === 'function') {
            attachActionTooltip(button);
        }
    });
    document.getElementById('exportChatBtn').addEventListener('click', function(event) {
        event.stopPropagation();
        toggleExportMenu();
    });
    document.getElementById('exportMarkdownLink').addEventListener('click', closeExportMenu);
    document.getElementById('exportPdfLink').addEventListener('click', function(event) {
        event.preventDefault();
        closeExportMenu();
        exportPdfWithCharts(this);
    });
    document.getElementById('historyToggleBtn').addEventListener('click', function() {
        setHistoryOpen(true);
    });
    document.getElementById('historyCloseBtn').addEventListener('click', function() {
        setHistoryOpen(false);
    });
    document.getElementById('historySearchInput').addEventListener('input', function(event) {
        queueHistorySearch(event.target.value.trim());
    });
    document.getElementById('closeDeleteChatModalBtn').addEventListener('click', closeDeleteChatModal);
    document.getElementById('cancelDeleteChatBtn').addEventListener('click', closeDeleteChatModal);
    document.getElementById('confirmDeleteChatBtn').addEventListener('click', deletePendingChat);
    document.getElementById('deleteChatModal').addEventListener('click', function(event) {
        if (event.target === event.currentTarget) {
            closeDeleteChatModal();
        }
    });
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.history-chat-actions')) {
            closeHistoryMenus();
        }

        if (!event.target.closest('.export-menu')) {
            closeExportMenu();
        }
    });
}

function setHistoryOpen(isOpen) {
    const toggleButton = document.getElementById('historyToggleBtn');

    window.OlliverseState.historyOpen = isOpen;
    document.getElementById('appShell').classList.toggle('history-collapsed', !isOpen);
    toggleButton.hidden = isOpen;
    toggleButton.setAttribute('aria-hidden', isOpen ? 'true' : 'false');
}

function queueHistorySearch(query) {
    window.OlliverseState.historySearchQuery = query;
    window.clearTimeout(window.OlliverseState.historySearchTimer);
    window.OlliverseState.historySearchTimer = window.setTimeout(() => {
        searchChatHistory(query);
    }, 300);
}

function clearHistorySearch() {
    const searchInput = document.getElementById('historySearchInput');

    window.OlliverseState.historySearchQuery = '';
    window.OlliverseState.historySearchChatIds = null;
    window.OlliverseState.historySearchResults = null;
    window.clearTimeout(window.OlliverseState.historySearchTimer);

    if (searchInput) {
        searchInput.value = '';
    }

    setHistoryStatus('');
}

function searchChatHistory(query) {
    if (!query) {
        window.OlliverseState.historySearchChatIds = null;
        window.OlliverseState.historySearchResults = null;
        renderHistoryList(window.OlliverseConfig.initialChatHistory || []);
        setHistoryStatus('');
        return;
    }

    setHistoryStatus('Buscando...');

    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('action', 'search_history');
    url.searchParams.set('q', query);

    fetch(url.toString(), {
        headers: {
            Accept: 'application/json',
        },
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao buscar no histórico.');
        }

        return payload;
    }))
    .then((payload) => {
        const chats = Array.isArray(payload.chats) ? payload.chats : [];

        window.OlliverseState.historySearchResults = chats;
        window.OlliverseState.historySearchChatIds = new Set((payload.chat_ids || chats.map((chat) => chat.id)).map(Number));
        renderHistoryList(chats);
        setHistoryStatus(chats.length === 0 ? 'Nenhuma conversa encontrada.' : '');
    })
    .catch((error) => {
        setHistoryStatus(error.message || 'Erro ao buscar no histórico.', true);
    });
}

function refreshChatHistory() {
    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('action', 'history');

    return fetch(url.toString(), {
        headers: {
            Accept: 'application/json',
        },
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao atualizar histórico.');
        }

        return payload;
    }))
    .then((payload) => {
        window.OlliverseConfig.initialChatHistory = payload.chats || [];

        if (window.OlliverseState.historySearchQuery) {
            searchChatHistory(window.OlliverseState.historySearchQuery);
            return;
        }

        renderHistoryList(window.OlliverseConfig.initialChatHistory);
    })
    .catch((error) => {
        setHistoryStatus(error.message || 'Erro ao atualizar histórico.', true);
    });
}

function renderHistoryList(chats) {
    const list = document.getElementById('historyList');
    const filteredChats = sortHistoryChats(filteredHistoryChats(chats));
    const groups = groupHistoryChats(filteredChats);

    list.innerHTML = '';
    document.getElementById('historyCount').textContent = `${filteredChats.length} ${filteredChats.length === 1 ? 'conversa' : 'conversas'}`;

    Object.entries(groups).forEach(([label, groupChats]) => {
        if (groupChats.length === 0) {
            return;
        }

        const groupItem = document.createElement('li');
        const groupTitle = document.createElement('div');
        const groupList = document.createElement('ul');

        groupItem.className = 'history-group';
        groupTitle.className = 'history-group-title';
        groupTitle.textContent = label;
        groupList.className = 'history-group-list';
        groupItem.appendChild(groupTitle);
        groupItem.appendChild(groupList);

        groupChats.forEach((chat) => {
            groupList.appendChild(createHistoryChatItem(chat));
        });

        list.appendChild(groupItem);
    });
}

function filteredHistoryChats(chats) {
    if (Array.isArray(window.OlliverseState.historySearchResults)) {
        return window.OlliverseState.historySearchResults;
    }

    const chatIds = window.OlliverseState.historySearchChatIds;

    if (!(chatIds instanceof Set)) {
        return chats;
    }

    return chats.filter((chat) => chatIds.has(Number(chat.id)));
}

function replaceHistoryChat(updatedChat) {
    const chatId = Number(updatedChat?.id || 0);

    if (!chatId) {
        return;
    }

    window.OlliverseConfig.initialChatHistory = upsertHistoryChat(
        window.OlliverseConfig.initialChatHistory || [],
        updatedChat
    );

    if (Array.isArray(window.OlliverseState.historySearchResults)) {
        window.OlliverseState.historySearchResults = upsertHistoryChat(
            window.OlliverseState.historySearchResults,
            updatedChat
        );
    }

    renderHistoryList(window.OlliverseConfig.initialChatHistory || []);
}

function showHistoryChatAtTop(updatedChat) {
    clearHistorySearch();
    replaceHistoryChat(updatedChat);
}

function upsertHistoryChat(chats, updatedChat) {
    const chatId = Number(updatedChat?.id || 0);
    let found = false;
    const nextChats = (chats || []).map((chat) => {
        if (Number(chat.id) !== chatId) {
            return chat;
        }

        found = true;
        return updatedChat;
    });

    if (!found) {
        nextChats.push(updatedChat);
    }

    return sortHistoryChats(nextChats);
}

function sortHistoryChats(chats) {
    return [...(chats || [])].sort((left, right) => {
        const rightTime = historySortTime(right);
        const leftTime = historySortTime(left);

        if (rightTime !== leftTime) {
            return rightTime - leftTime;
        }

        return Number(right.id || 0) - Number(left.id || 0);
    });
}

function historySortTime(chat) {
    const updatedAt = parseSqliteDate(chat?.updated_at);
    const createdAt = parseSqliteDate(chat?.created_at);

    return updatedAt.getTime() || createdAt.getTime() || 0;
}

function groupHistoryChats(chats) {
    const groups = {
        Hoje: [],
        Ontem: [],
        'Últimos 7 dias': [],
        Anteriores: [],
    };
    const today = startOfDay(new Date());
    const yesterday = new Date(today);
    const lastWeek = new Date(today);

    yesterday.setDate(today.getDate() - 1);
    lastWeek.setDate(today.getDate() - 7);

    chats.forEach((chat) => {
        const createdAt = parseSqliteDate(chat.created_at);
        const day = startOfDay(createdAt);

        if (day >= today) {
            groups.Hoje.push(chat);
        } else if (day.getTime() === yesterday.getTime()) {
            groups.Ontem.push(chat);
        } else if (day >= lastWeek) {
            groups['Últimos 7 dias'].push(chat);
        } else {
            groups.Anteriores.push(chat);
        }
    });

    return groups;
}

function createHistoryChatItem(chat) {
    const item = document.createElement('li');
    const row = document.createElement('div');
    const button = document.createElement('button');
    const actions = document.createElement('div');
    const menuButton = document.createElement('button');
    const menu = document.createElement('div');
    const markdownLink = document.createElement('a');
    const pdfLink = document.createElement('a');
    const deleteButton = document.createElement('button');
    const title = document.createElement('span');
    const model = document.createElement('span');
    const date = document.createElement('span');
    const metadata = document.createElement('span');

    item.className = 'history-chat-item';
    row.className = 'history-chat-row';
    button.type = 'button';
    button.className = 'history-chat-btn';
    button.classList.toggle('active', Number(chat.id) === Number(window.OlliverseConfig.chatId));
    button.addEventListener('click', function() {
        loadChatFromHistory(Number(chat.id));
    });

    title.className = 'history-chat-title';
    title.textContent = chat.title || 'Nova conversa';
    model.className = 'history-chat-model';
    model.textContent = chat.model_used || 'modelo';
    date.className = 'history-chat-date';
    date.textContent = formatChatDate(chat.created_at);
    date.title = chat.created_at ? `Criada em ${formatChatDateTime(chat.created_at)}` : '';
    metadata.className = 'history-chat-metadata';
    metadata.appendChild(model);
    metadata.appendChild(date);

    actions.className = 'history-chat-actions';
    menuButton.type = 'button';
    menuButton.className = 'history-menu-btn';
    menuButton.setAttribute('aria-label', 'Abrir menu da conversa');
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.innerHTML = iconSvg('ellipsis');
    menuButton.addEventListener('click', function(event) {
        event.stopPropagation();
        toggleHistoryMenu(actions, menuButton);
    });

    menu.className = 'history-action-menu';
    markdownLink.className = 'history-action-menu-item';
    markdownLink.href = `${window.location.pathname}?action=export_md&chat_id=${Number(chat.id)}`;
    markdownLink.innerHTML = `${iconSvg('file-text')}<span>Exportar .md</span>`;
    markdownLink.addEventListener('click', function() {
        closeHistoryMenus();
    });
    pdfLink.className = 'history-action-menu-item';
    pdfLink.href = `${window.location.pathname}?action=export_pdf&chat_id=${Number(chat.id)}`;
    pdfLink.innerHTML = `${iconSvg('file')}<span>Exportar .pdf</span>`;
    pdfLink.addEventListener('click', function(event) {
        if (Number(chat.id) === Number(window.OlliverseConfig.chatId || 0)) {
            event.preventDefault();
            closeHistoryMenus();
            exportPdfWithCharts(this);
            return;
        }

        closeHistoryMenus();
        markPdfExportLoading(this);
    });

    deleteButton.type = 'button';
    deleteButton.className = 'history-action-menu-item danger';
    deleteButton.innerHTML = `${iconSvg('trash-2')}<span>Excluir</span>`;
    deleteButton.addEventListener('click', function(event) {
        event.stopPropagation();
        closeHistoryMenus();
        openDeleteChatModal(chat);
    });
    menu.appendChild(markdownLink);
    menu.appendChild(pdfLink);
    menu.appendChild(deleteButton);
    actions.appendChild(menuButton);
    actions.appendChild(menu);

    button.appendChild(title);
    button.appendChild(metadata);
    row.appendChild(button);
    row.appendChild(actions);
    item.appendChild(row);

    return item;
}

function toggleHistoryMenu(actions, menuButton) {
    const shouldOpen = !actions.classList.contains('open');

    closeHistoryMenus();
    actions.classList.toggle('open', shouldOpen);
    menuButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
}

function closeHistoryMenus() {
    document.querySelectorAll('.history-chat-actions.open').forEach((actions) => {
        actions.classList.remove('open');
        actions.querySelector('.history-menu-btn')?.setAttribute('aria-expanded', 'false');
    });
}

function toggleExportMenu() {
    const exportMenu = document.getElementById('exportMenu');
    const exportButton = document.getElementById('exportChatBtn');
    const shouldOpen = !exportMenu.classList.contains('open');

    exportMenu.classList.toggle('open', shouldOpen);
    exportButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
}

function closeExportMenu() {
    const exportMenu = document.getElementById('exportMenu');
    const exportButton = document.getElementById('exportChatBtn');

    if (!exportMenu || !exportButton) {
        return;
    }

    exportMenu.classList.remove('open');
    exportButton.setAttribute('aria-expanded', 'false');
}

function markPdfExportLoading(link) {
    link.classList.add('loading');
    link.setAttribute('aria-disabled', 'true');
    window.setTimeout(() => {
        link.classList.remove('loading');
        link.removeAttribute('aria-disabled');
    }, 3000);
}

function openDeleteChatModal(chat) {
    const modal = document.getElementById('deleteChatModal');
    const confirmButton = document.getElementById('confirmDeleteChatBtn');

    window.OlliverseState.pendingDeleteChat = chat;
    document.getElementById('deleteChatModalText').textContent = `Deseja realmente deletar a conversa "${chat.title || 'Nova conversa'}"?`;
    document.getElementById('deleteChatStatus').textContent = '';
    confirmButton.disabled = false;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    confirmButton.focus();
}

function closeDeleteChatModal() {
    const modal = document.getElementById('deleteChatModal');

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    window.OlliverseState.pendingDeleteChat = null;
}

function deletePendingChat() {
    const chat = window.OlliverseState.pendingDeleteChat;
    const status = document.getElementById('deleteChatStatus');
    const confirmButton = document.getElementById('confirmDeleteChatBtn');

    if (!chat?.id) {
        return;
    }

    status.classList.remove('error');
    status.textContent = 'Deletando...';
    confirmButton.disabled = true;

    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('action', 'delete_chat');

    fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Accept: 'application/json',
        },
        body: new URLSearchParams({
            chat_id: String(chat.id),
        }).toString(),
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao deletar conversa.');
        }

        return payload;
    }))
    .then((payload) => {
        const deletedCurrentChat = Number(chat.id) === Number(window.OlliverseConfig.chatId);

        window.OlliverseConfig.initialChatHistory = payload.chats || [];
        closeDeleteChatModal();

        if (window.OlliverseState.historySearchQuery) {
            searchChatHistory(window.OlliverseState.historySearchQuery);
        } else {
            renderHistoryList(window.OlliverseConfig.initialChatHistory);
        }

        if (deletedCurrentChat) {
            const nextChat = window.OlliverseConfig.initialChatHistory[0];

            if (nextChat?.id) {
                loadChatFromHistory(Number(nextChat.id));
            } else {
                window.location.href = `${window.location.pathname}?new=1`;
            }
        }
    })
    .catch((error) => {
        status.classList.add('error');
        status.textContent = error.message || 'Erro ao deletar conversa.';
        confirmButton.disabled = false;
    });
}

function loadChatFromHistory(chatId) {
    if (!chatId || Number(chatId) === Number(window.OlliverseConfig.chatId)) {
        return;
    }

    setHistoryStatus('Carregando conversa...');

    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('action', 'chat_data');
    url.searchParams.set('chat_id', String(chatId));

    fetch(url.toString(), {
        headers: {
            Accept: 'application/json',
        },
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao carregar conversa.');
        }

        return payload;
    }))
    .then((payload) => {
        window.OlliverseConfig.chatId = chatId;
        window.OlliverseConfig.activePersona = payload.active_persona || window.OlliverseConfig.activePersona;
        window.OlliverseConfig.initialMessages = payload.messages || [];
        window.OlliverseConfig.systemPrompt = String(window.OlliverseConfig.activePersona?.prompt_content || window.OlliverseConfig.systemPrompt || '');
        renderLoadedChatMessages(payload.messages || []);
        updateContextUsage(payload.context_usage);
        if (String(payload.chat?.model_used || '').startsWith('web_ai:')) {
            document.getElementById('providerSelect').value = 'web_ai';
            localStorage.setItem(window.OlliverseConfig.webAi.storageKey, 'web_ai');
            updateProviderMode();
        } else if (payload.chat?.model_used) {
            document.getElementById('providerSelect').value = 'ollama';
            localStorage.setItem(window.OlliverseConfig.webAi.storageKey, 'ollama');
            updateProviderMode();
            selectModel(payload.chat.model_used);
        }
        renderPersonaSelect();
        fillPersonaForm(Number(window.OlliverseConfig.activePersona.id));
        updateExportLink();
        renderHistoryList(window.OlliverseConfig.initialChatHistory || []);
        window.history.pushState({}, '', `${window.location.pathname}?chat_id=${chatId}`);
        setHistoryStatus('');
    })
    .catch((error) => {
        setHistoryStatus(error.message || 'Erro ao carregar conversa.', true);
    });
}

function renderLoadedChatMessages(messages) {
    const container = document.getElementById('chatMessages');

    window.OlliverseConfig.initialMessages = messages || [];
    container.innerHTML = '';

    if (messages.length === 0) {
        appendMessage(window.OlliverseConfig.initialAssistantMessage, 'assistant');
        return;
    }

    messages.forEach((message) => {
        const role = message.role === 'user' ? 'user' : 'assistant';
        appendMessage(message.content || '', role, role === 'assistant');
    });

    scrollToBottom();
}

function updateExportLink() {
    const exportButton = document.getElementById('exportChatBtn');
    const markdownLink = document.getElementById('exportMarkdownLink');
    const pdfLink = document.getElementById('exportPdfLink');
    const chatId = Number(window.OlliverseConfig.chatId || 0);

    markdownLink.href = `${window.location.pathname}?action=export_md&chat_id=${chatId}`;
    pdfLink.href = `${window.location.pathname}?action=export_pdf&chat_id=${chatId}`;
    exportButton.classList.toggle('disabled', chatId <= 0);
    exportButton.disabled = chatId <= 0;
    markdownLink.classList.toggle('disabled', chatId <= 0);
    pdfLink.classList.toggle('disabled', chatId <= 0);
}

function exportPdfWithCharts(link) {
    if (!link || link.classList.contains('disabled')) {
        return;
    }

    markPdfExportLoading(link);

    const chartImages = collectActiveChartImages();
    const form = document.createElement('form');
    const imageInput = document.createElement('input');

    form.method = 'POST';
    form.action = link.href;
    form.hidden = true;

    imageInput.type = 'hidden';
    imageInput.name = 'chart_images';
    imageInput.value = JSON.stringify(chartImages);

    form.appendChild(imageInput);
    document.body.appendChild(form);
    form.submit();

    window.setTimeout(() => {
        form.remove();
        link.classList.remove('loading');
    }, 1400);
}

function collectActiveChartImages() {
    return Array.from(document.querySelectorAll('#chatMessages .plugin-chart-wrapper'))
        .map((wrapper) => {
            const canvas = wrapper.querySelector('canvas.dynamic-chart-canvas');
            const chart = wrapper._olliverseChartInstance;

            if (canvas?.hidden || canvas?.offsetParent === null) {
                return '';
            }

            try {
                if (chart && typeof chart.toBase64Image === 'function') {
                    return chart.toBase64Image('image/png', 1);
                }

                if (!canvas) {
                    return '';
                }

                return canvas.toDataURL('image/png');
            } catch (error) {
                return '';
            }
        });
}

function setHistoryStatus(message, isError = false) {
    const status = document.getElementById('historyStatus');

    status.textContent = message;
    status.classList.toggle('error', isError);
}

function parseSqliteDate(value) {
    const normalized = String(value || '').replace(' ', 'T');
    const date = new Date(normalized);

    return Number.isNaN(date.getTime()) ? new Date(0) : date;
}

function formatChatDate(value) {
    const date = parseSqliteDate(value);

    if (date.getTime() === 0) {
        return 'Sem data';
    }

    return date.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    });
}

function formatChatDateTime(value) {
    const date = parseSqliteDate(value);

    if (date.getTime() === 0) {
        return 'sem data';
    }

    return date.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function startOfDay(date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

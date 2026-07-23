function initRagPanel() {
    renderRagDocuments(window.OlliverseConfig.initialRagDocuments || []);
    initRagDeleteModal();

    const form = document.getElementById('ragUploadForm');
    const fileInput = document.getElementById('ragFileInput');
    const pickFileBtn = document.getElementById('ragPickFileBtn');

    if (typeof attachActionTooltip === 'function') {
        attachActionTooltip(pickFileBtn);
    }

    pickFileBtn.addEventListener('click', function() {
        fileInput.click();
    });

    fileInput.addEventListener('change', function() {
        const fileName = fileInput.files?.[0]?.name || '';

        if (!fileName) {
            setRagStatus('');
            return;
        }

        uploadRagDocument();
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        uploadRagDocument();
    });

    document.addEventListener('click', function(event) {
        if (!event.target.closest('.rag-documents-menu')) {
            closeRagDocumentsMenu();
        }
    });
}

function initRagDeleteModal() {
    const modal = document.getElementById('deleteRagDocumentModal');
    const closeButton = document.getElementById('closeDeleteRagDocumentModalBtn');
    const cancelButton = document.getElementById('cancelDeleteRagDocumentBtn');
    const confirmButton = document.getElementById('confirmDeleteRagDocumentBtn');

    if (!modal || !closeButton || !cancelButton || !confirmButton) {
        return;
    }

    closeButton.addEventListener('click', closeDeleteRagDocumentModal);
    cancelButton.addEventListener('click', closeDeleteRagDocumentModal);
    confirmButton.addEventListener('click', deletePendingRagDocument);
    modal.addEventListener('click', function(event) {
        if (event.target === event.currentTarget) {
            closeDeleteRagDocumentModal();
        }
    });
}

function getSelectedRagDocumentIds() {
    return Array.from(document.querySelectorAll('.rag-document-checkbox:checked'))
        .map((checkbox) => Number(checkbox.value))
        .filter((documentId) => documentId > 0);
}

function setRagDocumentControlsDisabled(disabled) {
    document
        .querySelectorAll('.rag-document-checkbox, .rag-delete-btn, .rag-documents-menu-btn')
        .forEach((control) => {
            control.disabled = disabled;
        });
}

function uploadRagDocument() {
    const fileInput = document.getElementById('ragFileInput');
    const pickFileBtn = document.getElementById('ragPickFileBtn');
    const file = fileInput.files?.[0] || null;

    if (!file) {
        setRagStatus('Selecione um arquivo para adicionar.', true);
        return;
    }

    pickFileBtn.disabled = true;
    fileInput.disabled = true;
    setRagStatus('Preparando documento...');

    const url = new URL(window.location.href);
    url.searchParams.set('action', 'rag_ingest');

    prepareRagUploadFile(file)
    .then((uploadFile) => {
        const body = new FormData();
        body.append('document', uploadFile, file.name);

        return fetch(url.toString(), {
            method: 'POST',
            body,
        });
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Não foi possível preparar o documento.');
        }

        return payload;
    }))
    .then((payload) => {
        fileInput.value = '';
        renderRagDocuments(payload.documents || []);
        setRagStatus(`${payload.document.source_name} pronto para consulta.`);
    })
    .catch((error) => {
        setRagStatus(error.message || 'Erro ao preparar documento.', true);
    })
    .finally(() => {
        pickFileBtn.disabled = false;
        fileInput.disabled = false;
    });
}

function prepareRagUploadFile(file) {
    if (!isSpreadsheetFile(file)) {
        return Promise.resolve(file);
    }

    if (!window.XLSX) {
        return Promise.reject(new Error('Leitor de planilhas indisponível. Verifique a conexão e tente novamente.'));
    }

    setRagStatus('Convertendo planilha...');

    return file.arrayBuffer()
        .then((buffer) => {
            const workbook = window.XLSX.read(buffer, { type: 'array' });
            const content = spreadsheetWorkbookToText(workbook, file.name);

            if (content.trim() === '') {
                throw new Error('Não foi possível ler texto da planilha.');
            }

            return new File([content], file.name, { type: 'text/plain' });
        });
}

function isSpreadsheetFile(file) {
    const name = String(file?.name || '').toLowerCase();

    return name.endsWith('.xlsx') || name.endsWith('.xls');
}

function spreadsheetWorkbookToText(workbook, fileName) {
    return workbook.SheetNames.map((sheetName) => {
        const worksheet = workbook.Sheets[sheetName];
        const rows = window.XLSX.utils.sheet_to_json(worksheet, {
            header: 1,
            blankrows: false,
            defval: '',
            raw: false,
        });
        const table = rows
            .map((row) => row.map(csvCell).join(','))
            .filter((line) => line.trim() !== '')
            .join('\n');

        if (table === '') {
            return '';
        }

        return [
            `Arquivo: ${fileName}`,
            `Aba: ${sheetName}`,
            table,
        ].join('\n');
    }).filter(Boolean).join('\n\n');
}

function csvCell(value) {
    const text = String(value ?? '');

    if (!/[",\n\r]/.test(text)) {
        return text;
    }

    return `"${text.replaceAll('"', '""')}"`;
}

function deleteRagDocument(documentId, sourceName) {
    openDeleteRagDocumentModal(documentId, sourceName);
}

function openDeleteRagDocumentModal(documentId, sourceName) {
    const modal = document.getElementById('deleteRagDocumentModal');
    const confirmButton = document.getElementById('confirmDeleteRagDocumentBtn');
    const status = document.getElementById('deleteRagDocumentStatus');

    if (!modal || !confirmButton || !status) {
        deleteRagDocumentRequest(documentId, sourceName);
        return;
    }

    window.OlliverseState.pendingDeleteRagDocument = {
        id: Number(documentId),
        sourceName,
    };
    document.getElementById('deleteRagDocumentModalText').textContent = `Deseja realmente excluir "${sourceName}" dos documentos adicionados?`;
    status.classList.remove('error');
    status.textContent = '';
    confirmButton.disabled = false;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    confirmButton.focus();
}

function closeDeleteRagDocumentModal() {
    const modal = document.getElementById('deleteRagDocumentModal');

    if (!modal) {
        return;
    }

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    window.OlliverseState.pendingDeleteRagDocument = null;
}

function deletePendingRagDocument() {
    const pendingDocument = window.OlliverseState.pendingDeleteRagDocument;
    const status = document.getElementById('deleteRagDocumentStatus');
    const confirmButton = document.getElementById('confirmDeleteRagDocumentBtn');

    if (!pendingDocument?.id) {
        return;
    }

    status.classList.remove('error');
    status.textContent = 'Excluindo documento...';
    confirmButton.disabled = true;
    deleteRagDocumentRequest(pendingDocument.id, pendingDocument.sourceName, {
        onSuccess: closeDeleteRagDocumentModal,
        onError(error) {
            status.classList.add('error');
            status.textContent = error.message || 'Erro ao excluir documento.';
            confirmButton.disabled = false;
        },
    });
}

function deleteRagDocumentRequest(documentId, sourceName, callbacks = {}) {
    const body = new URLSearchParams({
        document_id: String(documentId),
    });
    const url = new URL(window.location.href);
    url.searchParams.set('action', 'rag_delete');

    setRagStatus('Excluindo documento...');

    fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || !payload.success) {
            throw new Error(payload.error || 'Não foi possível excluir o documento.');
        }

        return payload;
    }))
    .then((payload) => {
        renderRagDocuments(payload.documents || []);
        setRagStatus(`${sourceName} excluído dos documentos adicionados.`);
        callbacks.onSuccess?.();
    })
    .catch((error) => {
        setRagStatus(error.message || 'Erro ao excluir documento.', true);
        callbacks.onError?.(error);
    });
}

function renderRagDocuments(documents) {
    const container = document.getElementById('ragDocumentList');

    if (!container) {
        return;
    }

    const previousCheckboxes = getDocumentCheckboxes();
    const previousDocumentIds = new Set(previousCheckboxes.map((checkbox) => Number(checkbox.value)));
    const previousSelectedIds = new Set(getSelectedRagDocumentIds());

    container.innerHTML = '';

    if (!documents.length) {
        const empty = document.createElement('span');
        empty.className = 'rag-document-empty';
        empty.textContent = 'Nenhum documento adicionado';
        container.appendChild(empty);
        closeRagDocumentsMenu();
        document.dispatchEvent(new CustomEvent('olliverse:rag-documents-rendered'));
        return;
    }

    const menu = document.createElement('div');
    const menuButton = document.createElement('button');
    const popover = document.createElement('div');

    menu.className = 'rag-documents-menu';
    menuButton.type = 'button';
    menuButton.className = 'rag-documents-menu-btn';
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Gerenciar documentos ativos');
    popover.className = 'rag-documents-popover';
    popover.setAttribute('role', 'menu');
    menuButton.addEventListener('click', function(event) {
        event.stopPropagation();
        toggleRagDocumentsMenu(menu, menuButton);
    });

    documents.forEach((documentInfo) => {
        const item = document.createElement('label');
        const checkbox = document.createElement('input');
        const text = document.createElement('span');
        const deleteButton = document.createElement('button');
        const documentId = Number(documentInfo.id || 0);
        const sourceName = documentInfo.source_name || 'Documento';

        item.className = 'rag-document-chip';
        checkbox.type = 'checkbox';
        checkbox.className = 'rag-document-checkbox';
        checkbox.value = String(documentId);
        checkbox.checked = previousCheckboxes.length === 0
            || previousSelectedIds.has(documentId)
            || !previousDocumentIds.has(documentId);
        checkbox.addEventListener('change', function() {
            updateRagDocumentBadge();
        });
        text.className = 'rag-document-name';
        text.textContent = `${sourceName} (${documentInfo.chunks})`;
        deleteButton.type = 'button';
        deleteButton.className = 'rag-delete-btn';
        deleteButton.setAttribute('aria-label', `Excluir ${sourceName}`);
        deleteButton.title = `Excluir ${sourceName}`;
        deleteButton.textContent = 'x';
        deleteButton.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            deleteRagDocument(documentId, sourceName);
        });

        item.appendChild(checkbox);
        item.appendChild(text);
        item.appendChild(deleteButton);
        popover.appendChild(item);
    });

    menu.appendChild(menuButton);
    menu.appendChild(popover);
    container.appendChild(menu);
    updateRagDocumentBadge();
    document.dispatchEvent(new CustomEvent('olliverse:rag-documents-rendered'));
}

function getSelectedDocumentCheckboxes() {
    return Array.from(document.querySelectorAll('.rag-document-checkbox:checked'));
}

function getDocumentCheckboxes() {
    return Array.from(document.querySelectorAll('.rag-document-checkbox'));
}

function toggleRagDocumentsMenu(menu, menuButton) {
    const shouldOpen = !menu.classList.contains('open');

    closeRagDocumentsMenu();
    menu.classList.toggle('open', shouldOpen);
    menuButton.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
}

function closeRagDocumentsMenu() {
    document.querySelectorAll('.rag-documents-menu.open').forEach((menu) => {
        menu.classList.remove('open');
        menu.querySelector('.rag-documents-menu-btn')?.setAttribute('aria-expanded', 'false');
    });
}

function updateRagDocumentBadge() {
    const button = document.querySelector('.rag-documents-menu-btn');
    const documentCheckboxes = getDocumentCheckboxes();
    const selectedCount = getSelectedDocumentCheckboxes().length;
    const label = `${selectedCount} ${selectedCount === 1 ? 'documento ativo' : 'documentos ativos'}`;

    if (button) {
        button.textContent = label;
        button.classList.toggle('empty', documentCheckboxes.length > 0 && selectedCount === 0);
    }
}

function setRagStatus(message, isError = false) {
    const status = document.getElementById('ragStatus');

    status.textContent = message;
    status.classList.toggle('error', isError);
}

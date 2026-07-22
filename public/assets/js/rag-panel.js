function initRagPanel() {
    renderRagDocuments(window.OlliverseConfig.initialRagDocuments || []);

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
}

function getSelectedRagDocumentIds() {
    const allDocumentsCheckbox = document.getElementById('ragAllDocuments');

    if (allDocumentsCheckbox?.checked) {
        return [];
    }

    return Array.from(document.querySelectorAll('.rag-document-checkbox:checked'))
        .map((checkbox) => Number(checkbox.value))
        .filter((documentId) => documentId > 0);
}

function setRagDocumentControlsDisabled(disabled) {
    document
        .querySelectorAll('.rag-document-checkbox, #ragAllDocuments, .rag-delete-btn')
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
    if (!window.confirm(`Excluir "${sourceName}" dos documentos adicionados?`)) {
        return;
    }

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
    })
    .catch((error) => {
        setRagStatus(error.message || 'Erro ao excluir documento.', true);
    });
}

function renderRagDocuments(documents) {
    const container = document.getElementById('ragDocumentList');
    const ragToggleField = document.querySelector('.rag-toggle-field');
    const ragToggle = document.getElementById('ragToggle');

    if (!container) {
        return;
    }

    container.innerHTML = '';
    if (ragToggleField) {
        ragToggleField.hidden = !documents.length;
    }

    if (!documents.length && ragToggle) {
        ragToggle.checked = false;
    }

    if (!documents.length) {
        const empty = document.createElement('span');
        empty.className = 'rag-document-empty';
        empty.textContent = 'Nenhum documento adicionado';
        container.appendChild(empty);
        document.dispatchEvent(new CustomEvent('olliverse:rag-documents-rendered'));
        return;
    }

    container.appendChild(createAllDocumentsChip());

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
        checkbox.checked = document.getElementById('ragAllDocuments')?.checked === true;
        checkbox.addEventListener('change', syncAllDocumentsSelection);
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
        container.appendChild(item);
    });

    document.dispatchEvent(new CustomEvent('olliverse:rag-documents-rendered'));
}

function createAllDocumentsChip() {
    const item = document.createElement('label');
    const checkbox = document.createElement('input');
    const text = document.createElement('span');

    item.className = 'rag-document-chip rag-document-chip-all';
    checkbox.type = 'checkbox';
    checkbox.id = 'ragAllDocuments';
    checkbox.checked = true;
    text.className = 'rag-document-name';
    text.textContent = 'Todos';
    checkbox.addEventListener('change', function() {
        setAllDocumentCheckboxes(checkbox.checked);
    });

    item.appendChild(checkbox);
    item.appendChild(text);

    return item;
}

function syncAllDocumentsSelection() {
    const allDocumentsCheckbox = document.getElementById('ragAllDocuments');
    const documentCheckboxes = getDocumentCheckboxes();

    if (!allDocumentsCheckbox) {
        return;
    }

    allDocumentsCheckbox.checked = documentCheckboxes.length > 0
        && documentCheckboxes.every((checkbox) => checkbox.checked);
}

function getSelectedDocumentCheckboxes() {
    return Array.from(document.querySelectorAll('.rag-document-checkbox:checked'));
}

function getDocumentCheckboxes() {
    return Array.from(document.querySelectorAll('.rag-document-checkbox'));
}

function setAllDocumentCheckboxes(checked) {
    getDocumentCheckboxes().forEach((documentCheckbox) => {
        documentCheckbox.checked = checked;
    });
}

function setRagStatus(message, isError = false) {
    const status = document.getElementById('ragStatus');

    status.textContent = message;
    status.classList.toggle('error', isError);
}

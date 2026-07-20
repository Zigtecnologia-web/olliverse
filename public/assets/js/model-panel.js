function initModelPicker() {
    const picker = document.getElementById('modelPicker');
    const menuButton = document.getElementById('modelMenuButton');

    if (!picker || !menuButton) {
        return;
    }

    menuButton.addEventListener('click', function() {
        if (menuButton.disabled) {
            return;
        }

        picker.classList.toggle('open');
    });

    document.querySelectorAll('.model-option[data-model]').forEach((option) => {
        option.addEventListener('click', function() {
            selectModel(option.dataset.model || '');
        });
    });
}

function closeModelMenu() {
    const picker = document.getElementById('modelPicker');

    if (picker) {
        picker.classList.remove('open');
    }
}

function selectModel(model) {
    if (!model) {
        return;
    }

    document.getElementById('modelSelect').value = model;
    document.getElementById('selectedModelLabel').textContent = model;
    document.getElementById('modelInfoTitle').textContent = model;

    document.querySelectorAll('.model-option[data-model]').forEach((option) => {
        const isSelected = option.dataset.model === model;

        option.classList.toggle('active', isSelected);
        option.setAttribute('aria-selected', isSelected ? 'true' : 'false');
    });

    closeModelMenu();

    if (document.getElementById('modelInfoModal').classList.contains('open')) {
        loadModelMetadata(model);
    }
}

function openModelInfoModal() {
    const modal = document.getElementById('modelInfoModal');
    const model = document.getElementById('modelSelect').value;

    document.getElementById('modelInfoTitle').textContent = model || 'Sem modelo';
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    loadModelMetadata(model);
    document.getElementById('closeModelInfoModalBtn').focus();
}

function closeModelInfoModal() {
    const modal = document.getElementById('modelInfoModal');

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('modelInfoBtn').focus();
}

function loadModelMetadata(model) {
    if (!model || !window.OlliverseConfig.hasAvailableModels) {
        showModelMetadataError('Nenhum modelo disponível.');
        return;
    }

    if (window.OlliverseState.modelMetadataCache.has(model)) {
        renderModelMetadata(window.OlliverseState.modelMetadataCache.get(model));
        return;
    }

    setModelMetadataLoading();

    const url = new URL(window.location.href);
    url.search = '';
    url.searchParams.set('action', 'model_metadata');
    url.searchParams.set('model', model);

    fetch(url.toString(), {
        headers: {
            'Accept': 'application/json',
        },
    })
    .then((response) => {
        if (!response.ok) {
            throw new Error('Não foi possível carregar os metadados.');
        }

        return response.json();
    })
    .then((metadata) => {
        window.OlliverseState.modelMetadataCache.set(model, metadata);
        renderModelMetadata(metadata);
    })
    .catch((error) => {
        showModelMetadataError(error.message || 'Erro ao carregar metadados.');
    });
}

function setModelMetadataLoading() {
    const status = document.getElementById('modelInfoStatus');

    document.getElementById('modelInfoGrid').hidden = true;
    status.classList.remove('error');
    status.hidden = false;
    status.textContent = 'Carregando metadados...';
}

function renderModelMetadata(metadata) {
    document.getElementById('modelInfoTitle').textContent = metadata.model || document.getElementById('modelSelect').value;
    document.getElementById('modelInfoSize').textContent = formatSizeGb(metadata.size_gb);
    document.getElementById('modelInfoFamily').textContent = metadata.family || '-';
    document.getElementById('modelInfoContext').textContent = formatNumber(metadata.context_length);
    document.getElementById('modelInfoQuantization').textContent = metadata.quantization || '-';

    document.getElementById('modelInfoStatus').hidden = true;
    document.getElementById('modelInfoGrid').hidden = false;
}

function showModelMetadataError(message) {
    const status = document.getElementById('modelInfoStatus');

    document.getElementById('modelInfoGrid').hidden = true;
    status.hidden = false;
    status.classList.add('error');
    status.textContent = message;
}

function formatSizeGb(sizeGb) {
    const value = Number(sizeGb);

    if (!Number.isFinite(value) || value <= 0) {
        return '-';
    }

    return `${value.toLocaleString('pt-BR', {
        minimumFractionDigits: value < 10 ? 1 : 0,
        maximumFractionDigits: 1,
    })} GB`;
}

function formatNumber(value) {
    const number = Number(value);

    if (!Number.isFinite(number) || number <= 0) {
        return '-';
    }

    return number.toLocaleString('pt-BR');
}

function openSkillModal() {
    const modal = document.getElementById('skillModal');

    document.getElementById('skillStatus').textContent = '';
    renderPersonaLibraryOptions();
    fillPersonaForm(Number(window.OlliverseConfig.activePersona.id));
    updateGeneratePromptButton();
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('personaNameInput').focus();
}

function closeSkillModal() {
    const modal = document.getElementById('skillModal');

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('settingsBtn').focus();
}

function saveSkillConfig() {
    const statusEl = document.getElementById('skillStatus');
    const selectedId = Number(document.getElementById('personaLibrarySelect').value);
    const isNewPersona = document.getElementById('personaLibrarySelect').dataset.mode === 'new';
    const action = isNewPersona ? 'create' : 'update';
    const nameValue = document.getElementById('personaNameInput').value.trim();
    const descriptionValue = document.getElementById('personaDescriptionInput').value.trim();
    const promptValue = document.getElementById('systemPromptInput').value.trim();

    statusEl.textContent = 'Salvando...';

    const body = new URLSearchParams({
        persona_action: action,
        name: nameValue,
        description: descriptionValue,
        prompt_content: promptValue,
    });

    if (!isNewPersona) {
        body.set('persona_id', String(selectedId));
    }

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString()
    })
    .then(response => {
        return response.json().then((payload) => {
            if (!response.ok || payload.success === false) {
                throw new Error(payload.error || 'Erro ao salvar persona.');
            }

            return payload;
        });
    })
    .then((payload) => {
        syncPersonaState(payload);
        statusEl.textContent = 'Persona salva.';
        showPersonaToast(`Persona alterada para ${payload.persona.name}`);
    })
    .catch(error => {
        statusEl.textContent = error.message;
    });
}

function initPersonaControls() {
    renderPersonaSelect();
    renderPersonaLibraryOptions();
}

function selectPersona(personaId) {
    const persona = findPersona(personaId);
    const personaSelect = document.getElementById('personaSelect');

    if (!persona) {
        return;
    }

    personaSelect.disabled = true;

    const body = new URLSearchParams({
        persona_action: 'select',
        persona_id: String(personaId),
    });

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao alterar persona.');
        }

        return payload;
    }))
    .then((payload) => {
        syncPersonaState(payload);
        showPersonaToast(`Persona alterada para ${payload.persona.name}`);
    })
    .catch((error) => {
        personaSelect.value = String(window.OlliverseConfig.activePersona.id);
        showPersonaToast(error.message || 'Erro ao alterar persona.', true);
    })
    .finally(() => {
        personaSelect.disabled = false;
    });
}

function deleteSelectedPersona() {
    const statusEl = document.getElementById('skillStatus');
    const personaId = Number(document.getElementById('personaLibrarySelect').value);
    const persona = findPersona(personaId);

    if (!persona) {
        return;
    }

    statusEl.textContent = 'Excluindo...';

    const body = new URLSearchParams({
        persona_action: 'delete',
        persona_id: String(personaId),
    });

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: body.toString(),
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao excluir persona.');
        }

        return payload;
    }))
    .then((payload) => {
        syncPersonaState(payload);
        statusEl.textContent = 'Persona excluída.';
        showPersonaToast(`Persona alterada para ${payload.persona.name}`);
    })
    .catch((error) => {
        statusEl.textContent = error.message;
    });
}

function syncPersonaState(payload) {
    window.OlliverseConfig.personas = payload.personas || window.OlliverseConfig.personas;
    window.OlliverseConfig.activePersona = payload.persona || window.OlliverseConfig.activePersona;

    if (payload.context_usage) {
        updateContextUsage(payload.context_usage);
    }

    renderPersonaSelect();
    renderPersonaLibraryOptions();
    fillPersonaForm(Number(window.OlliverseConfig.activePersona.id));
}

function renderPersonaSelect() {
    const select = document.getElementById('personaSelect');

    select.innerHTML = '';

    window.OlliverseConfig.personas.forEach((persona) => {
        const option = document.createElement('option');

        option.value = String(persona.id);
        option.textContent = persona.name;
        option.selected = Number(persona.id) === Number(window.OlliverseConfig.activePersona.id);
        select.appendChild(option);
    });
}

function renderPersonaLibraryOptions() {
    const select = document.getElementById('personaLibrarySelect');

    select.innerHTML = '';
    select.dataset.mode = 'edit';

    window.OlliverseConfig.personas.forEach((persona) => {
        const option = document.createElement('option');

        option.value = String(persona.id);
        option.textContent = persona.name;
        option.selected = Number(persona.id) === Number(window.OlliverseConfig.activePersona.id);
        select.appendChild(option);
    });
}

function fillPersonaForm(personaId) {
    const persona = findPersona(personaId) || window.OlliverseConfig.activePersona;
    const librarySelect = document.getElementById('personaLibrarySelect');

    librarySelect.dataset.mode = 'edit';
    librarySelect.value = String(persona.id);
    document.getElementById('personaNameInput').value = persona.name || '';
    document.getElementById('personaDescriptionInput').value = persona.description || '';
    document.getElementById('systemPromptInput').value = persona.prompt_content || '';
    document.getElementById('deletePersonaBtn').disabled = Number(persona.id) === Number(window.OlliverseConfig.activePersona.id)
        && window.OlliverseConfig.personas.length <= 1;
    updateGeneratePromptButton();
}

function startNewPersona() {
    const librarySelect = document.getElementById('personaLibrarySelect');

    librarySelect.dataset.mode = 'new';
    librarySelect.value = '';
    document.getElementById('personaNameInput').value = '';
    document.getElementById('personaDescriptionInput').value = '';
    document.getElementById('systemPromptInput').value = '';
    document.getElementById('deletePersonaBtn').disabled = true;
    document.getElementById('skillStatus').textContent = '';
    updateGeneratePromptButton();
    document.getElementById('personaNameInput').focus();
}

function updateGeneratePromptButton() {
    const button = document.getElementById('generatePromptBtn');
    const nameValue = document.getElementById('personaNameInput').value.trim();
    const descriptionValue = document.getElementById('personaDescriptionInput').value.trim();
    const isGenerating = button.dataset.loading === '1';

    button.disabled = isGenerating || !window.OlliverseConfig.hasAvailableModels || nameValue === '' || descriptionValue === '';
}

function generatePersonaPrompt() {
    const button = document.getElementById('generatePromptBtn');
    const statusEl = document.getElementById('skillStatus');
    const nameValue = document.getElementById('personaNameInput').value.trim();
    const descriptionValue = document.getElementById('personaDescriptionInput').value.trim();
    const model = document.getElementById('modelSelect').value;
    const promptInput = document.getElementById('systemPromptInput');
    const url = new URL(window.location.href);

    if (button.disabled || !nameValue || !descriptionValue) {
        return;
    }

    url.searchParams.set('action', 'prompt_generate');
    button.dataset.loading = '1';
    button.querySelector('span').textContent = 'Gerando...';
    statusEl.classList.remove('error');
    statusEl.textContent = 'Gerando prompt...';
    updateGeneratePromptButton();

    fetch(url.toString(), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
            name: nameValue,
            description: descriptionValue,
            model,
        }).toString(),
    })
    .then((response) => response.json().then((payload) => {
        if (!response.ok || payload.success === false) {
            throw new Error(payload.error || 'Erro ao gerar prompt.');
        }

        return payload;
    }))
    .then((payload) => {
        promptInput.value = payload.prompt_content || '';
        statusEl.textContent = 'Prompt gerado.';
        promptInput.focus();
    })
    .catch((error) => {
        statusEl.classList.add('error');
        statusEl.textContent = error.message || 'Erro ao gerar prompt.';
    })
    .finally(() => {
        button.dataset.loading = '0';
        button.querySelector('span').textContent = 'Gerar';
        updateGeneratePromptButton();
    });
}

function findPersona(personaId) {
    return window.OlliverseConfig.personas.find((persona) => Number(persona.id) === Number(personaId));
}

function showPersonaToast(message, isError = false) {
    const toast = document.getElementById('personaToast');

    toast.textContent = message;
    toast.classList.toggle('error', isError);
    toast.classList.add('visible');

    window.clearTimeout(window.OlliverseState.personaToastTimer);
    window.OlliverseState.personaToastTimer = window.setTimeout(() => {
        toast.classList.remove('visible');
    }, 2600);
}

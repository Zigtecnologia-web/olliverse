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
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    document.getElementById('systemPromptInput').focus();
}

function closeSkillModal() {
    const modal = document.getElementById('skillModal');

    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    document.getElementById('settingsBtn').focus();
}

function saveSkillConfig() {
    const statusEl = document.getElementById('skillStatus');
    const promptValue = document.getElementById('systemPromptInput').value.trim();

    statusEl.textContent = 'Salvando...';

    fetch(window.location.href, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'system_prompt=' + encodeURIComponent(promptValue)
    })
    .then(response => {
        if (!response.ok) throw new Error('Erro ao salvar configuração.');
        return response.json();
    })
    .then(() => {
        statusEl.textContent = 'Configuração salva.';
        window.setTimeout(closeSkillModal, 700);
    })
    .catch(error => {
        statusEl.textContent = error.message;
    });
}

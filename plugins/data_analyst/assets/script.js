(function() {
    const INSPECT_STORAGE_KEY = 'olliverse:data_analyst:inspect_rag_document';

    window.OlliversePlugins = window.OlliversePlugins || {};

    window.OlliversePlugins.data_analyst = {
        activate() {
            ensureInsightsPanel();
            maybeScheduleInsightsInspection();
        },

        deactivate() {
            window.clearTimeout(window.OlliversePlugins.data_analyst.inspectTimer);
            document.getElementById('dataInsightsPanel')?.remove();
        },

        processMessage(messageElement) {
            if (!messageElement || !window.Chart) {
                return;
            }

            messageElement.querySelectorAll('code.language-json-chart').forEach((block) => {
                renderChartBlock(block, true);
            });

            messageElement.querySelectorAll('code.language-json, pre code:not([class*="language-"])').forEach((block) => {
                if (block.classList.contains('language-json-chart') || !looksLikeChartBlock(block)) {
                    return;
                }

                renderChartBlock(block, false);
            });
        },

        renderInsights(containerElement, inspectionJson, sources) {
            renderInsights(containerElement, inspectionJson, sources);
        },
    };

    ensureInsightsPanel();
    maybeScheduleInsightsInspection();
    document.addEventListener('change', (event) => {
        if (!isDataAnalystActive()) {
            return;
        }

        if (event.target?.matches?.('#dataInsightsToggle')) {
            setInsightsInspectionEnabled(event.target.checked);
            return;
        }

        if (event.target?.matches?.('.rag-document-checkbox')) {
            updateInsightsPanelVisibility();
            maybeScheduleInsightsInspection();
        }
    });
    document.addEventListener('olliverse:rag-documents-rendered', () => {
        if (isDataAnalystActive()) {
            updateInsightsPanelVisibility();
            maybeScheduleInsightsInspection();
        }
    });

    function ensureInsightsPanel() {
        const chatMessages = document.getElementById('chatMessages');

        if (!chatMessages || document.getElementById('dataInsightsPanel')) {
            return;
        }

        const panel = document.createElement('div');
        panel.id = 'dataInsightsPanel';
        panel.className = 'data-insights-edge-panel';
        panel.innerHTML = [
            '<div class="data-insights-toolbar">',
            '<label class="data-insights-toggle" for="dataInsightsToggle">',
            '<input type="checkbox" id="dataInsightsToggle">',
            '<span class="data-insights-toggle-track" aria-hidden="true"></span>',
            '<span class="data-insights-toggle-label">Inspecionar documento</span>',
            '</label>',
            '<div id="dataInsightsStatus" class="data-insights-status" aria-live="polite"></div>',
            '</div>',
            '<div id="dataInsightsContent" class="data-insights-content"></div>',
        ].join('');

        chatMessages.parentElement.insertBefore(panel, chatMessages);
        document.getElementById('dataInsightsToggle').checked = isInsightsInspectionEnabled();
        updateInsightsPanelVisibility();
    }

    function maybeScheduleInsightsInspection() {
        if (!hasSelectedRagInsightDocuments()) {
            stopInsightsInspection();
            return;
        }

        if (!isInsightsInspectionEnabled()) {
            stopInsightsInspection();
            return;
        }

        scheduleInsightsInspection();
    }

    function scheduleInsightsInspection() {
        window.clearTimeout(window.OlliversePlugins.data_analyst.inspectTimer);
        window.OlliversePlugins.data_analyst.inspectTimer = window.setTimeout(inspectSelectedRagDocuments, 350);
    }

    function inspectSelectedRagDocuments() {
        ensureInsightsPanel();

        if (!isDataAnalystActive() || !isInsightsInspectionEnabled()) {
            stopInsightsInspection();
            return;
        }

        const model = document.getElementById('modelSelect')?.value || '';
        const body = new URLSearchParams({
            model,
        });
        const selectedDocumentIds = selectedRagInsightDocumentIds();
        const selectedDocumentSignature = selectedDocumentIds.join(',');

        if (selectedDocumentIds.length === 0) {
            stopInsightsInspection();
            return;
        }

        selectedDocumentIds.forEach((documentId) => {
            body.append('rag_document_ids[]', String(documentId));
        });

        setInsightsStatus('Inspecionando documento RAG...', false);

        fetch(`${window.location.pathname}?action=data_insights`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body.toString(),
        })
        .then((response) => response.json().then((payload) => ({ ok: response.ok, payload })))
        .then(({ ok, payload }) => {
            if (!ok || !payload.success) {
                throw new Error(payload.error || 'Nao foi possivel inspecionar os dados.');
            }

            if (!isInsightsInspectionEnabled() || selectedRagInsightDocumentIds().join(',') !== selectedDocumentSignature) {
                return;
            }

            renderInsightsPanel(payload.inspection, payload.sources || []);
            setInsightsStatus('', false);
        })
        .catch((error) => {
            clearInsightsContent();
            setInsightsStatus(error.message || 'Nao foi possivel inspecionar os dados.', true);
        });
    }

    function setInsightsInspectionEnabled(enabled) {
        localStorage.setItem(INSPECT_STORAGE_KEY, enabled ? '1' : '0');

        if (enabled) {
            maybeScheduleInsightsInspection();
            return;
        }

        stopInsightsInspection();
    }

    function isInsightsInspectionEnabled() {
        return localStorage.getItem(INSPECT_STORAGE_KEY) === '1';
    }

    function stopInsightsInspection() {
        window.clearTimeout(window.OlliversePlugins.data_analyst.inspectTimer);
        clearInsightsContent();
        setInsightsStatus('', false);
    }

    function updateInsightsPanelVisibility() {
        const panel = document.getElementById('dataInsightsPanel');
        const toggle = document.querySelector('.data-insights-toggle');
        const hasSelectedDocuments = hasSelectedRagInsightDocuments();

        if (toggle) {
            toggle.hidden = !hasSelectedDocuments;
        }

        if (panel) {
            panel.hidden = !hasSelectedDocuments;
        }

        if (!hasSelectedDocuments) {
            stopInsightsInspection();
        }
    }

    function hasSelectedRagInsightDocuments() {
        return selectedRagInsightDocumentIds().length > 0;
    }

    function renderInsightsPanel(inspection, sources) {
        const content = document.getElementById('dataInsightsContent');
        const insightsContainer = createInsightsContainer();

        if (!content) {
            return;
        }

        content.innerHTML = '';
        content.appendChild(insightsContainer);
        renderInsights(insightsContainer, inspection, sources);
    }

    function createInsightsContainer() {
        const container = document.createElement('div');
        container.className = 'data-insights-container';
        container.innerHTML = [
            '<div class="insights-header">',
            '<span class="insights-icon" aria-hidden="true">Data</span>',
            '<div class="insights-text">',
            '<strong>Analise inteligente (SQLite / RAG)</strong>',
            '<p class="insights-summary-text"></p>',
            '</div>',
            '</div>',
            '<div class="insights-chips-wrapper">',
            '<span class="chips-label">Sugestoes de exploracao para este documento:</span>',
            '<div class="dynamic-chips-container"></div>',
            '</div>',
        ].join('');

        return container;
    }

    function renderInsights(containerElement, inspectionJson, sources) {
        const summary = containerElement.querySelector('.insights-summary-text');
        const chipsContainer = containerElement.querySelector('.dynamic-chips-container');
        const sourceLabel = Array.isArray(sources) && sources.length ? `${sources.join(', ')}: ` : '';

        summary.textContent = `${sourceLabel}${inspectionJson.summary || 'Dados estruturados prontos para explorar.'}`;
        chipsContainer.innerHTML = '';

        (inspectionJson.suggestions || []).forEach((item) => {
            const button = document.createElement('button');
            const chartType = item.chart_type || 'bar';

            button.type = 'button';
            button.className = 'insight-chip-btn';
            button.textContent = item.title || 'Explorar dados';
            button.addEventListener('click', () => {
                const query = buildInsightQuery(item.query || button.textContent, chartType);

                if (typeof window.OlliverseSubmitMessage === 'function') {
                    window.OlliverseSubmitMessage(query);
                }
            });

            chipsContainer.appendChild(button);
        });
    }

    function buildInsightQuery(query, chartType) {
        return [
            query,
            '',
            `Use os documentos ativos e gere um grafico do tipo ${chartType} em um bloco json-chart.`,
        ].join('\n');
    }

    function selectedRagInsightDocumentIds() {
        if (typeof getSelectedRagDocumentIds === 'function') {
            return getSelectedRagDocumentIds();
        }

        return Array.from(document.querySelectorAll('.rag-document-checkbox:checked'))
            .map((checkbox) => Number(checkbox.value))
            .filter((documentId) => documentId > 0);
    }

    function clearInsightsContent() {
        const content = document.getElementById('dataInsightsContent');

        if (content) {
            content.innerHTML = '';
        }
    }

    function isDataAnalystActive() {
        return window.OlliversePlugins?.active?.has?.('data_analyst') !== false;
    }

    function setInsightsStatus(message, error) {
        const status = document.getElementById('dataInsightsStatus');

        if (!status) {
            return;
        }

        status.textContent = message;
        status.classList.toggle('error', error);
    }

    function renderChartBlock(block, strict = true) {
        const host = block.closest('.code-block') || block.closest('pre');
        const rawJson = rawBlockText(block);

        if (!host || host.dataset.chartRendered === '1') {
            return;
        }

        let payload;

        try {
            payload = normalizePayload(parseChartJson(rawJson), 0);
            validatePayload(payload);
        } catch (error) {
            if (strict && rawJson.trim().startsWith('{')) {
                host.replaceWith(createChartError(error.message || 'JSON de gráfico inválido.'));
            }

            return;
        }

        const chartWrapper = createChartWrapper(payload);
        const downloadButton = chartWrapper.querySelector('.chart-download-btn');

        host.dataset.chartRendered = '1';
        host.replaceWith(chartWrapper);

        chartWrapper.dataset.rawPayload = JSON.stringify(payload);
        initializeChartSwitcher(chartWrapper, payload);

        if (typeof attachActionTooltip === 'function') {
            attachActionTooltip(downloadButton);
        }

        downloadButton.addEventListener('click', function() {
            const chart = chartWrapper._olliverseChartInstance;

            if (!chart) {
                return;
            }

            downloadChartImage(chart, payload);
        });
    }

    function looksLikeChartBlock(block) {
        const rawJson = rawBlockText(block);
        const json = extractJsonObject(rawJson);

        if (!json) {
            return false;
        }

        try {
            const payload = normalizePayload(parseChartJson(json), 0);

            return isNormalizedPayload(payload)
                && (hasChartKey(rawJson, 'type') || hasChartKey(rawJson, 'labels') || hasChartKey(rawJson, 'data'));
        } catch (error) {
            return false;
        }
    }

    function hasChartKey(rawJson, key) {
        return new RegExp(`["']${key}["']\\s*:`, 'i').test(rawJson);
    }

    function parseChartJson(rawJson) {
        const json = extractJsonObject(rawJson) || '{}';

        try {
            return JSON.parse(json);
        } catch (error) {
            return JSON.parse(stripJsonComments(json));
        }
    }

    function stripJsonComments(json) {
        let result = '';
        let inString = false;
        let escaped = false;

        for (let index = 0; index < json.length; index += 1) {
            const char = json[index];
            const next = json[index + 1] || '';

            if (escaped) {
                result += char;
                escaped = false;
                continue;
            }

            if (char === '\\') {
                result += char;
                escaped = inString;
                continue;
            }

            if (char === '"') {
                result += char;
                inString = !inString;
                continue;
            }

            if (!inString && char === '/' && next === '/') {
                while (index < json.length && json[index] !== '\n') {
                    index += 1;
                }
                result += '\n';
                continue;
            }

            if (!inString && char === '/' && next === '*') {
                index += 2;
                while (index < json.length && !(json[index] === '*' && json[index + 1] === '/')) {
                    index += 1;
                }
                index += 1;
                continue;
            }

            result += char;
        }

        return result;
    }

    function createChartWrapper(payload) {
        const wrapper = document.createElement('div');

        wrapper.className = 'plugin-chart-wrapper';
        wrapper.innerHTML = [
            '<div class="chart-header">',
            `<span class="chart-badge">${escapeHtml(payload.title || 'Relatório analítico gerado')}</span>`,
            '<div class="visual-switcher-toolbar" aria-label="Alternar visualizacao do grafico">',
            `<button type="button" class="switcher-btn" data-chart-type="bar" aria-label="Grafico de barras" title="Grafico de barras" data-tooltip="Grafico de barras">${pluginIconSvg('bar-chart-3')}</button>`,
            `<button type="button" class="switcher-btn" data-chart-type="pie" aria-label="Grafico de pizza" title="Grafico de pizza" data-tooltip="Grafico de pizza">${pluginIconSvg('pie-chart')}</button>`,
            `<button type="button" class="switcher-btn" data-chart-type="line" aria-label="Grafico de linhas" title="Grafico de linhas" data-tooltip="Grafico de linhas">${pluginIconSvg('line-chart')}</button>`,
            `<button type="button" class="switcher-btn" data-chart-type="table" aria-label="Visualizacao em tabela" title="Visualizacao em tabela" data-tooltip="Visualizacao em tabela">${pluginIconSvg('table-2')}</button>`,
            '</div>',
            '</div>',
            '<div class="chart-canvas-container">',
            '<div class="chart-loading" role="status" aria-live="polite" hidden>',
            '<span class="chart-loading-spinner" aria-hidden="true"></span>',
            '<span>Processando grafico...</span>',
            '</div>',
            '<canvas class="dynamic-chart-canvas chart-canvas-hidden"></canvas>',
            '<div class="chart-table-container" hidden></div>',
            '</div>',
            '<div class="chart-footer">',
            `<button type="button" class="chart-download-btn" aria-label="Baixar imagem" title="Baixar imagem" data-tooltip="Baixar imagem">${pluginIconSvg('download')}</button>`,
            '</div>',
        ].join('');

        return wrapper;
    }

    function initializeChartSwitcher(wrapper, payload) {
        const buttons = wrapper.querySelectorAll('.switcher-btn');
        const initialType = normalizeChartType(payload.type || 'bar');

        buttons.forEach((button) => {
            if (typeof attachActionTooltip === 'function') {
                attachActionTooltip(button);
            }

            button.addEventListener('click', function() {
                renderChartView(wrapper, payload, button.dataset.chartType || 'bar');
            });
        });

        renderChartView(wrapper, payload, initialType);
    }

    function renderChartView(wrapper, payload, type) {
        const selectedType = normalizeChartType(type);
        const canvas = wrapper.querySelector('.dynamic-chart-canvas');
        const tableContainer = wrapper.querySelector('.chart-table-container');
        const downloadButton = wrapper.querySelector('.chart-download-btn');

        if (!canvas || !tableContainer) {
            return;
        }

        if (wrapper._olliverseChartInstance) {
            wrapper._olliverseChartInstance.destroy();
            wrapper._olliverseChartInstance = null;
        }

        wrapper.querySelectorAll('.switcher-btn').forEach((button) => {
            const isActive = button.dataset.chartType === selectedType;

            button.classList.toggle('active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        if (selectedType === 'table') {
            setChartLoading(wrapper, false);
            canvas.hidden = true;
            canvas.classList.add('chart-canvas-hidden');
            tableContainer.hidden = false;
            tableContainer.innerHTML = tableHtml(payload);

            if (downloadButton) {
                downloadButton.disabled = true;
                downloadButton.setAttribute('aria-disabled', 'true');
            }

            return;
        }

        setChartLoading(wrapper, true);
        canvas.hidden = false;
        canvas.classList.add('chart-canvas-hidden');
        tableContainer.hidden = true;
        tableContainer.innerHTML = '';
        try {
            wrapper._olliverseChartInstance = new Chart(canvas.getContext('2d'), chartOptions({
                ...payload,
                type: selectedType,
            }, () => {
                canvas.classList.remove('chart-canvas-hidden');
                setChartLoading(wrapper, false);
            }));
        } catch (error) {
            setChartLoading(wrapper, false);
            canvas.replaceWith(createChartError(error.message || 'Nao foi possivel renderizar o grafico.'));
            return;
        }

        if (downloadButton) {
            downloadButton.disabled = true;
            downloadButton.setAttribute('aria-disabled', 'true');
        }
    }

    function setChartLoading(wrapper, loading) {
        const loadingElement = wrapper.querySelector('.chart-loading');
        const downloadButton = wrapper.querySelector('.chart-download-btn');

        wrapper.classList.toggle('chart-rendering', loading);

        wrapper.querySelectorAll('.switcher-btn').forEach((button) => {
            button.disabled = loading;
        });

        if (loadingElement) {
            loadingElement.hidden = !loading;
        }

        if (downloadButton && !loading) {
            downloadButton.disabled = false;
            downloadButton.removeAttribute('aria-disabled');
        }
    }

    function normalizeChartType(type) {
        return ['bar', 'pie', 'line', 'table'].includes(type) ? type : 'bar';
    }

    function createChartError(message) {
        const error = document.createElement('div');

        error.className = 'chart-error';
        error.textContent = message;

        return error;
    }

    function validatePayload(payload) {
        const type = payload?.type || 'bar';

        if (!['bar', 'pie', 'line', 'table'].includes(type)) {
            throw new Error('Tipo de gráfico não suportado.');
        }

        if (!Array.isArray(payload.labels) || !Array.isArray(payload.data)) {
            throw new Error('O gráfico precisa de labels e data.');
        }

        if (payload.labels.length === 0 || payload.labels.length !== payload.data.length) {
            throw new Error('Labels e valores precisam ter o mesmo tamanho.');
        }
    }

    function normalizePayload(payload, depth = 0) {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
            return payload;
        }

        if (depth > 3) {
            return payload;
        }

        const type = valueByAliases(payload, ['type', 'tipo', 'chartType']) || 'bar';
        const title = valueByAliases(payload, ['title', 'titulo', 'título', 'name', 'nome']) || 'Métricas';
        const labels = valueByAliases(payload, ['labels', 'rotulos', 'rótulos', 'categorias', 'categories']);
        const data = valueByAliases(payload, ['data', 'dados', 'valores', 'values', 'quantidades', 'totais', 'counts']);
        const datasets = valueByAliases(payload, ['datasets', 'conjuntos']);
        const series = valueByAliases(payload, ['series', 'serie', 'séries', 'itens', 'items']);

        if (Array.isArray(labels) && Array.isArray(data)) {
            return { type, title, labels, data };
        }

        if (Array.isArray(data) && data[0] && typeof data[0] === 'object') {
            const dataPayload = normalizeSeries(data, type, title);

            if (isNormalizedPayload(dataPayload)) {
                return dataPayload;
            }
        }

        if (Array.isArray(labels) && data && typeof data === 'object' && !Array.isArray(data)) {
            const nestedPayload = normalizePayload({ type, title, labels, ...data }, depth + 1);

            if (isNormalizedPayload(nestedPayload)) {
                return nestedPayload;
            }
        }

        if (data && typeof data === 'object' && !Array.isArray(data)) {
            const nestedPayload = normalizePayload({ type, title, ...data }, depth + 1);

            if (isNormalizedPayload(nestedPayload)) {
                return nestedPayload;
            }
        }

        if (Array.isArray(labels) && Array.isArray(datasets) && datasets[0]) {
            const datasetData = valueByAliases(datasets[0], ['data', 'dados', 'valores', 'values', 'quantidades', 'totais']);

            return {
                type,
                title: title || datasets[0].label || datasets[0].nome || 'Métricas',
                labels,
                data: Array.isArray(datasetData) ? datasetData : [],
            };
        }

        if (Array.isArray(series) && series[0]) {
            const seriesPayload = normalizeSeries(series, type, title);

            if (isNormalizedPayload(seriesPayload)) {
                return seriesPayload;
            }
        }

        if (isSimpleNumericMap(data)) {
            return {
                type,
                title,
                labels: Object.keys(data),
                data: Object.values(data),
            };
        }

        if (isSimpleNumericMap(payload)) {
            return {
                type,
                title,
                labels: Object.keys(payload).filter((key) => !metadataKeys().includes(key)),
                data: Object.entries(payload)
                    .filter(([key]) => !metadataKeys().includes(key))
                    .map(([, value]) => value),
            };
        }

        for (const value of Object.values(payload)) {
            if (!value || typeof value !== 'object' || Array.isArray(value)) {
                continue;
            }

            const nestedPayload = normalizePayload(value, depth + 1);

            if (isNormalizedPayload(nestedPayload)) {
                return {
                    type: valueByAliases(payload, ['type', 'tipo', 'chartType']) || nestedPayload.type,
                    title: valueByAliases(payload, ['title', 'titulo', 'título', 'name', 'nome']) || nestedPayload.title,
                    labels: nestedPayload.labels,
                    data: nestedPayload.data,
                };
            }
        }

        return payload;
    }

    function normalizeSeries(series, type, title) {
        const labels = series.map((item) => {
            return valueByAliases(item, ['label', 'labels', 'name', 'nome', 'genero', 'gênero', 'sexo', 'categoria', 'category']);
        });
        const data = series.map((item) => {
            return valueByAliases(item, ['value', 'valor', 'total', 'count', 'quantidade', 'data', 'dados']);
        });

        return { type, title, labels, data };
    }

    function valueByAliases(payload, aliases) {
        if (!payload || typeof payload !== 'object') {
            return undefined;
        }

        for (const alias of aliases) {
            if (Object.prototype.hasOwnProperty.call(payload, alias)) {
                return payload[alias];
            }
        }

        return undefined;
    }

    function isNormalizedPayload(payload) {
        return Boolean(payload)
            && Array.isArray(payload.labels)
            && Array.isArray(payload.data)
            && payload.labels.length > 0
            && payload.labels.length === payload.data.length;
    }

    function isSimpleNumericMap(payload) {
        if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
            return false;
        }

        const entries = Object.entries(payload);
        const dataEntries = entries.filter(([key]) => !metadataKeys().includes(key));

        return dataEntries.length > 0 && dataEntries.every(([, value]) => isNumericValue(value));
    }

    function metadataKeys() {
        return ['type', 'tipo', 'chartType', 'title', 'titulo', 'título', 'name', 'nome'];
    }

    function isNumericValue(value) {
        return value !== null && value !== '' && Number.isFinite(Number(value));
    }

    function chartOptions(payload, onComplete = null) {
        const type = payload.type || 'bar';
        const colors = [
            'rgba(52, 152, 219, 0.75)',
            'rgba(46, 204, 113, 0.75)',
            'rgba(241, 196, 15, 0.75)',
            'rgba(155, 89, 182, 0.75)',
            'rgba(231, 76, 60, 0.75)',
            'rgba(26, 188, 156, 0.75)',
        ];

        return {
            type,
            data: {
                labels: payload.labels,
                datasets: [{
                    label: payload.title || 'Métricas',
                    data: payload.data.map(Number),
                    backgroundColor: colors,
                    borderColor: colors.map((color) => color.replace('0.75', '1')),
                    borderWidth: 1,
                    tension: 0.3,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: {
                    duration: 180,
                    onComplete: () => {
                        if (typeof onComplete === 'function') {
                            onComplete();
                        }
                    },
                },
                plugins: {
                    legend: {
                        labels: {
                            color: '#c4c4cc',
                        },
                    },
                    title: {
                        display: Boolean(payload.title),
                        text: payload.title || '',
                        color: '#e1e1e6',
                    },
                },
                scales: type === 'pie' ? {} : {
                    x: {
                        ticks: { color: '#a8a8b3' },
                        grid: { color: 'rgba(255, 255, 255, 0.08)' },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { color: '#a8a8b3' },
                        grid: { color: 'rgba(255, 255, 255, 0.08)' },
                    },
                },
            },
        };
    }

    function tableHtml(payload) {
        const rows = payload.labels.map((label, index) => {
            const value = payload.data[index];

            return [
                '<tr>',
                `<td>${escapeHtml(label)}</td>`,
                `<td>${escapeHtml(value)}</td>`,
                '</tr>',
            ].join('');
        }).join('');

        return [
            '<table class="plugin-data-table">',
            '<thead><tr><th>Categoria</th><th>Valor</th></tr></thead>',
            `<tbody>${rows}</tbody>`,
            '</table>',
        ].join('');
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function pluginIconSvg(name) {
        if (typeof iconSvg === 'function') {
            return iconSvg(name);
        }

        const icons = {
            'bar-chart-3': '<path d="M3 3v18h18"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path>',
            download: '<path d="M12 15V3"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="m7 10 5 5 5-5"></path>',
            'line-chart': '<path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path>',
            'pie-chart': '<path d="M21 12c.552 0 1.005-.449.95-.998a10 10 0 0 0-8.953-8.951C12.449 1.996 12 2.448 12 3v8a1 1 0 0 0 1 1z"></path><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>',
            'table-2': '<path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0-12h12M9 21h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"></path>',
        };

        return `<svg aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">${icons[name] || icons['bar-chart-3']}</svg>`;
    }

    function rawBlockText(block) {
        return block.dataset.rawCode || block.textContent || '';
    }

    function extractJsonObject(value) {
        const text = String(value || '');
        const start = text.indexOf('{');

        if (start === -1) {
            return '';
        }

        let depth = 0;
        let inString = false;
        let escaped = false;

        for (let index = start; index < text.length; index += 1) {
            const char = text[index];

            if (escaped) {
                escaped = false;
                continue;
            }

            if (char === '\\') {
                escaped = inString;
                continue;
            }

            if (char === '"') {
                inString = !inString;
                continue;
            }

            if (inString) {
                continue;
            }

            if (char === '{') {
                depth += 1;
            }

            if (char === '}') {
                depth -= 1;

                if (depth === 0) {
                    return text.slice(start, index + 1);
                }
            }
        }

        return text.slice(start).trim();
    }

    function downloadChartImage(chart, payload) {
        const link = document.createElement('a');
        const fileName = chartFileName(payload.title || 'grafico');

        link.href = chart.toBase64Image('image/png', 1);
        link.download = `${fileName}.png`;
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    function chartFileName(title) {
        const normalizedTitle = String(title)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '');

        return normalizedTitle || 'grafico';
    }

    document.querySelectorAll('.message.assistant').forEach((message) => {
        window.OlliversePlugins.data_analyst.processMessage(message);
    });
})();

(function() {
    window.OlliversePlugins = window.OlliversePlugins || {};

    window.OlliversePlugins.data_analyst = {
        activate() {
            ensureInsightsPanel();
            scheduleChartReprocess();
        },

        deactivate() {
            window.clearTimeout(window.OlliversePlugins.data_analyst.inspectTimer);
            restoreRagDocumentsHome();
            document.getElementById('dataInsightsPanel')?.remove();
            document.getElementById('dataInsightsQuickBtn')?.remove();
            document.getElementById('dataInsightsDrawerBtn')?.remove();
            removeTablePlotActions();
        },

        processMessage(messageElement) {
            if (!messageElement) {
                return;
            }

            if (!window.Chart) {
                scheduleChartReprocess();
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

            ensureTablePlotAction(messageElement);
        },

        renderInsights(containerElement, inspectionJson, sources) {
            renderInsights(containerElement, inspectionJson, sources);
        },

        exportChartImage(chartWrapper) {
            return exportChartImage(chartWrapper);
        },
    };

    ensureInsightsPanel();
    document.addEventListener('change', (event) => {
        if (!isDataAnalystActive()) {
            return;
        }

        if (event.target?.matches?.('.rag-document-checkbox')) {
            updateInsightsPanelVisibility();
        }
    });
    document.addEventListener('olliverse:rag-documents-rendered', () => {
        if (isDataAnalystActive()) {
            moveRagDocumentsIntoDrawer();
            updateInsightsPanelVisibility();
        }
    });
    window.addEventListener('load', scheduleChartReprocess);

    function scheduleChartReprocess() {
        if (window.OlliversePlugins.data_analyst.reprocessTimer) {
            return;
        }

        window.OlliversePlugins.data_analyst.reprocessAttempts = window.OlliversePlugins.data_analyst.reprocessAttempts || 0;

        if (window.OlliversePlugins.data_analyst.reprocessAttempts >= 12) {
            return;
        }

        window.OlliversePlugins.data_analyst.reprocessTimer = window.setTimeout(() => {
            window.OlliversePlugins.data_analyst.reprocessTimer = null;
            window.OlliversePlugins.data_analyst.reprocessAttempts += 1;

            if (!window.Chart) {
                scheduleChartReprocess();
                return;
            }

            processRenderedAssistantMessages();
        }, 250);
    }

    function processRenderedAssistantMessages() {
        document.querySelectorAll('.message.assistant').forEach((message) => {
            window.OlliversePlugins.data_analyst.processMessage(message);
        });
    }

    function ensureInsightsPanel() {
        const chatContainer = document.querySelector('.chat-container');

        ensureInsightsControls();

        if (!chatContainer || document.getElementById('dataInsightsPanel')) {
            return;
        }

        const panel = document.createElement('div');
        panel.id = 'dataInsightsPanel';
        panel.className = 'data-insights-drawer';
        panel.setAttribute('aria-hidden', 'true');
        panel.innerHTML = [
            '<div class="data-insights-drawer-header">',
            '<div>',
            '<strong>Analise do documento</strong>',
            '<div id="dataInsightsStatus" class="data-insights-status" aria-live="polite"></div>',
            '</div>',
            '<div class="data-insights-drawer-actions">',
            '<button type="button" id="dataInsightsQuickBtn" class="secondary-config-btn data-insights-action-btn" aria-label="Gerar insights" title="Gerar insights" data-tooltip="Gerar insights">Gerar insights</button>',
            '<button type="button" id="dataInsightsCloseBtn" class="data-insights-close-btn" aria-label="Fechar inspecao">x</button>',
            '</div>',
            '</div>',
            '<div class="data-insights-documents">',
            '<span class="chips-label">Documentos</span>',
            '<div id="dataInsightsDocumentsSlot" class="data-insights-documents-slot"></div>',
            '</div>',
            '<div id="dataInsightsContent" class="data-insights-content"></div>',
            '<div id="dataInsightsPopover" class="data-insights-popover" hidden></div>',
        ].join('');

        chatContainer.appendChild(panel);
        document.getElementById('dataInsightsQuickBtn')?.addEventListener('click', function(event) {
            event.stopPropagation();
            generateInsights();
        });
        document.getElementById('dataInsightsCloseBtn')?.addEventListener('click', closeInsightsDrawer);
        if (typeof attachActionTooltip === 'function') {
            attachActionTooltip(document.getElementById('dataInsightsQuickBtn'));
        }
        moveRagDocumentsIntoDrawer();
        updateInsightsPanelVisibility();
    }

    function ensureInsightsControls() {
        const form = document.getElementById('ragUploadForm');

        if (!form || document.getElementById('dataInsightsDrawerBtn')) {
            return;
        }

        const drawerButton = document.createElement('button');

        drawerButton.type = 'button';
        drawerButton.id = 'dataInsightsDrawerBtn';
        drawerButton.className = 'secondary-config-btn data-insights-action-btn';
        drawerButton.setAttribute('aria-label', 'Resumo do documento');
        drawerButton.setAttribute('title', 'Resumo do documento');
        drawerButton.setAttribute('data-tooltip', 'Resumo do documento');
        drawerButton.textContent = 'Resumo';
        drawerButton.hidden = true;
        drawerButton.addEventListener('click', function(event) {
            event.stopPropagation();
            openInsightsDrawer();
        });

        form.appendChild(drawerButton);

        if (typeof attachActionTooltip === 'function') {
            attachActionTooltip(drawerButton);
        }

        document.addEventListener('click', function(event) {
            if (!event.target.closest('#dataInsightsQuickBtn') && !event.target.closest('.data-insights-popover')) {
                closeInsightsPopover();
            }
        });
    }

    function moveRagDocumentsIntoDrawer() {
        const list = document.getElementById('ragDocumentList');
        const slot = document.getElementById('dataInsightsDocumentsSlot');

        if (!list || !slot || slot.contains(list)) {
            return;
        }

        if (!document.getElementById('ragDocumentListHome')) {
            const marker = document.createElement('span');
            marker.id = 'ragDocumentListHome';
            marker.hidden = true;
            list.parentElement?.insertBefore(marker, list);
        }

        slot.appendChild(list);
    }

    function restoreRagDocumentsHome() {
        const list = document.getElementById('ragDocumentList');
        const marker = document.getElementById('ragDocumentListHome');

        if (!list || !marker || !marker.parentElement) {
            return;
        }

        marker.parentElement.insertBefore(list, marker);
        marker.remove();
    }

    function generateInsights() {
        if (!hasSelectedRagInsightDocuments()) {
            stopInsightsInspection();
            return;
        }

        openInsightsDrawer();
        scheduleInsightsInspection();
    }

    function scheduleInsightsInspection() {
        window.clearTimeout(window.OlliversePlugins.data_analyst.inspectTimer);
        window.OlliversePlugins.data_analyst.inspectTimer = window.setTimeout(inspectSelectedRagDocuments, 350);
    }

    function inspectSelectedRagDocuments() {
        ensureInsightsPanel();

        if (!isDataAnalystActive()) {
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

            if (selectedRagInsightDocumentIds().join(',') !== selectedDocumentSignature) {
                return;
            }

            renderInsightsPanel(payload.inspection, payload.sources || [], payload.engine || '');
            openInsightsPopover();
            setInsightsStatus('', false);
        })
        .catch((error) => {
            clearInsightsContent();
            setInsightsStatus(error.message || 'Nao foi possivel inspecionar os dados.', true);
        });
    }

    function stopInsightsInspection() {
        window.clearTimeout(window.OlliversePlugins.data_analyst.inspectTimer);
        clearInsightsContent();
        setInsightsStatus('', false);
    }

    function updateInsightsPanelVisibility() {
        const panel = document.getElementById('dataInsightsPanel');
        const quickButton = document.getElementById('dataInsightsQuickBtn');
        const drawerButton = document.getElementById('dataInsightsDrawerBtn');
        const hasDocuments = hasRagInsightDocuments();
        const hasSelectedDocuments = hasSelectedRagInsightDocuments();

        if (quickButton) {
            quickButton.hidden = !hasSelectedDocuments;
        }

        if (drawerButton) {
            drawerButton.hidden = !hasDocuments;
        }

        if (panel) {
            panel.hidden = false;
            panel.classList.toggle('available', hasDocuments);
        }

        if (!hasDocuments) {
            closeInsightsDrawer();
            closeInsightsPopover();
        }

        if (!hasSelectedDocuments) {
            closeInsightsPopover();
            stopInsightsInspection();
        }
    }

    function hasRagInsightDocuments() {
        return document.querySelectorAll('.rag-document-checkbox').length > 0;
    }

    function hasSelectedRagInsightDocuments() {
        return selectedRagInsightDocumentIds().length > 0;
    }

    function renderInsightsPanel(inspection, sources, engine) {
        const content = document.getElementById('dataInsightsContent');
        const insightsContainer = createInsightsContainer(engine);

        if (!content) {
            return;
        }

        content.innerHTML = '';
        content.appendChild(insightsContainer);
        renderInsights(insightsContainer, inspection, sources);
        renderInsightsPopover(insightsContainer);
    }

    function createInsightsContainer(engine) {
        const container = document.createElement('div');
        const engineLabel = dataEngineLabel(engine);
        container.className = 'data-insights-container';
        container.innerHTML = [
            '<div class="insights-header">',
            '<span class="insights-icon" aria-hidden="true">Data</span>',
            '<div class="insights-text">',
            `<strong>Analise inteligente (${engineLabel})</strong>`,
            '<p class="insights-summary-text"></p>',
            '</div>',
            '</div>',
            '<div class="insights-chips-wrapper">',
            '<span class="chips-label">Sugestoes</span>',
            '<div class="dynamic-chips-container"></div>',
            '</div>',
        ].join('');

        return container;
    }

    function dataEngineLabel(engine) {
        if (engine === 'duckdb-pdo') {
            return 'DuckDB';
        }

        if (engine === 'sqlite-fallback') {
            return 'SQLite';
        }

        return 'RAG';
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
                if (item.sql && item.document_id) {
                    executeAnalyticSuggestion(item);
                    return;
                }

                const query = buildInsightQuery(item.query || button.textContent, chartType);

                closeInsightsPopover();
                if (typeof window.OlliverseSubmitMessage === 'function') {
                    window.OlliverseSubmitMessage(query);
                }
            });

            chipsContainer.appendChild(button);
        });
    }

    function executeAnalyticSuggestion(item) {
        const body = new URLSearchParams({
            document_id: String(item.document_id || ''),
            sql: item.sql || '',
            chart_type: item.chart_type || 'bar',
            title: item.title || '',
        });

        closeInsightsPopover();
        setInsightsStatus('Consultando dados locais...', false);

        fetch(`${window.location.pathname}?action=data_query`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: body.toString(),
        })
        .then((response) => response.json().then((payload) => ({ ok: response.ok, payload })))
        .then(({ ok, payload }) => {
            if (!ok || !payload.success) {
                throw new Error(payload.error || 'Nao foi possivel consultar os dados.');
            }

            renderAnalyticChartResult(payload.payload, item);
            setInsightsStatus('', false);
        })
        .catch((error) => {
            setInsightsStatus(error.message || 'Nao foi possivel consultar os dados.', true);
        });
    }

    function renderAnalyticChartResult(result, item) {
        const chartConfig = result?.chart_config || {};
        const dataset = Array.isArray(chartConfig.datasets) ? chartConfig.datasets[0] : null;
        const payload = normalizeChartValues({
            type: chartConfig.type || item.chart_type || 'bar',
            title: item.title || dataset?.label || 'Consulta analitica',
            labels: Array.isArray(chartConfig.labels) ? chartConfig.labels : [],
            data: Array.isArray(dataset?.data) ? dataset.data : [],
            datasets: chartConfig.datasets,
        });

        validatePayload(payload);

        const messages = document.getElementById('chatMessages');
        const group = document.createElement('div');
        const message = document.createElement('div');
        const intro = document.createElement('p');
        const query = document.createElement('code');
        const chartWrapper = createChartWrapper(payload);

        group.className = 'message-group assistant';
        message.className = 'message assistant';
        intro.textContent = `${item.query || item.title || 'Consulta analitica'} (${result.engine || 'motor local'})`;
        query.textContent = result.query_executed || item.sql || '';
        chartWrapper.dataset.chartSource = 'analytics';
        chartWrapper.dataset.rawPayload = JSON.stringify(payload);

        message.appendChild(intro);
        message.appendChild(query);
        group.appendChild(message);
        group.appendChild(chartWrapper);
        messages?.appendChild(group);

        mountChartWrapper(chartWrapper, payload);
        chartWrapper.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function buildInsightQuery(query, chartType) {
        return [
            query,
            '',
            `Use os documentos ativos e responda com uma tabela Markdown contendo as categorias e os valores numericos para plotagem. Tipo sugerido: ${chartType}.`,
        ].join('\n');
    }

    function openInsightsDrawer() {
        const panel = document.getElementById('dataInsightsPanel');

        if (!hasRagInsightDocuments()) {
            return;
        }

        panel?.classList.add('open');
        panel?.setAttribute('aria-hidden', 'false');
    }

    function closeInsightsDrawer() {
        const panel = document.getElementById('dataInsightsPanel');

        panel?.classList.remove('open');
        panel?.setAttribute('aria-hidden', 'true');
    }

    function openInsightsPopover() {
        const popover = document.getElementById('dataInsightsPopover');

        if (!hasSelectedRagInsightDocuments()) {
            return;
        }

        renderInsightsPopover();

        if (popover) {
            popover.hidden = false;
        }
    }

    function closeInsightsPopover() {
        const popover = document.getElementById('dataInsightsPopover');

        if (popover) {
            popover.hidden = true;
        }
    }

    function renderInsightsPopover(sourceContainer = null) {
        const popover = document.getElementById('dataInsightsPopover');
        const chips = sourceContainer
            ? Array.from(sourceContainer.querySelectorAll('.insight-chip-btn'))
            : Array.from(document.querySelectorAll('#dataInsightsContent .insight-chip-btn'));

        if (!popover) {
            return;
        }

        popover.innerHTML = '';

        if (chips.length === 0) {
            const empty = document.createElement('span');
            empty.className = 'data-insights-empty';
            empty.textContent = 'Preparando sugestoes...';
            popover.appendChild(empty);
            return;
        }

        chips.forEach((chip) => {
            const clone = chip.cloneNode(true);
            clone.addEventListener('click', () => chip.click());
            popover.appendChild(clone);
        });
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
            payload = normalizeChartValues(payload);
            validatePayload(payload);
        } catch (error) {
            logChartParseError(error, rawJson, strict);
            return;
        }

        const chartWrapper = createChartWrapper(payload);
        const downloadButton = chartWrapper.querySelector('.chart-download-btn');

        host.dataset.chartRendered = '1';
        host.replaceWith(chartWrapper);

        chartWrapper.dataset.rawPayload = JSON.stringify(payload);
        mountChartWrapper(chartWrapper, payload);
    }

    function ensureTablePlotAction(messageElement) {
        const messageGroup = messageElement.closest('.message-group.assistant');
        const payload = detectStructuredDataPayload(messageElement);
        const existingButton = messageGroup?.querySelector('.data-chart-plot-btn');

        if (!messageGroup) {
            return;
        }

        if (!payload) {
            existingButton?.remove();
            return;
        }

        const button = existingButton || createTablePlotButton();

        button.dataset.chartPayload = JSON.stringify(payload);

        if (!existingButton) {
            const actions = messageGroup.querySelector('.message-actions') || createMessageActionsElement();

            actions.appendChild(button);
            if (!actions.parentElement) {
                messageGroup.appendChild(actions);
            }
        }
    }

    function createTablePlotButton() {
        const button = document.createElement('button');

        button.type = 'button';
        button.className = 'message-action-btn data-chart-plot-btn';
        button.setAttribute('aria-label', 'Plotar grafico');
        button.setAttribute('data-tooltip', 'Plotar grafico');
        button.innerHTML = `${pluginIconSvg('bar-chart-3')}<span>Plotar grafico</span>`;
        button.addEventListener('click', function() {
            plotDetectedTable(button);
        });

        if (typeof attachActionTooltip === 'function') {
            attachActionTooltip(button);
        }

        return button;
    }

    function createMessageActionsElement() {
        const actions = document.createElement('div');

        actions.className = 'message-actions';

        return actions;
    }

    function plotDetectedTable(button) {
        const messageGroup = button.closest('.message-group.assistant');

        if (!messageGroup) {
            return;
        }

        let payload;

        try {
            payload = normalizeChartValues(JSON.parse(button.dataset.chartPayload || '{}'));
            validatePayload(payload);
        } catch (error) {
            console.warn('Falha ao preparar dados tabulares para grafico.', error);
            return;
        }

        const chartWrapper = createChartWrapper(payload);
        const existingChart = messageGroup.querySelector('.plugin-chart-wrapper[data-chart-source="table"]');

        chartWrapper.dataset.chartSource = 'table';
        chartWrapper.dataset.rawPayload = JSON.stringify(payload);

        if (existingChart) {
            existingChart.replaceWith(chartWrapper);
        } else {
            messageGroup.appendChild(chartWrapper);
        }

        mountChartWrapper(chartWrapper, payload);
        chartWrapper.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function mountChartWrapper(chartWrapper, payload) {
        const downloadButton = chartWrapper.querySelector('.chart-download-btn');

        window.requestAnimationFrame(() => {
            initializeChartSwitcher(chartWrapper, payload);
        });

        if (downloadButton) {
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
    }

    function removeTablePlotActions() {
        document.querySelectorAll('.data-chart-plot-btn').forEach((button) => button.remove());
        document.querySelectorAll('.plugin-chart-wrapper[data-chart-source="table"]').forEach((chart) => chart.remove());
    }

    function detectStructuredDataPayload(messageElement) {
        const tablePayload = detectTablePayload(messageElement);

        if (tablePayload) {
            return tablePayload;
        }

        const text = messageElement.textContent || '';

        return detectAsciiTablePayload(text) || detectPipeTablePayload(text);
    }

    function detectTablePayload(messageElement) {
        const tables = Array.from(messageElement.querySelectorAll('table'));

        for (const table of tables) {
            const payload = payloadFromTableRows(tableRows(table));

            if (payload) {
                return payload;
            }
        }

        return null;
    }

    function tableRows(table) {
        return Array.from(table.querySelectorAll('tr'))
            .map((row) => Array.from(row.children).map((cell) => cell.textContent || ''))
            .filter((cells) => cells.length >= 2);
    }

    function detectPipeTablePayload(text) {
        const rows = String(text || '')
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line.includes('|'))
            .map((line) => line.split('|').map((cell) => cell.trim()).filter((cell) => cell !== ''))
            .filter((cells) => cells.length >= 2 && !cells.every((cell) => /^:?-{3,}:?$/.test(cell)));

        return payloadFromTableRows(rows);
    }

    function detectAsciiTablePayload(text) {
        const rows = String(text || '')
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line.includes('|') && !/^\+[-+\s]+\+$/.test(line))
            .map((line) => line.replace(/^\|?/, '').replace(/\|?$/, ''))
            .map((line) => line.split('|').map((cell) => cell.trim()).filter((cell) => cell !== ''))
            .filter((cells) => cells.length >= 2 && !cells.every((cell) => /^[-+\s]+$/.test(cell)));

        return payloadFromTableRows(rows);
    }

    function payloadFromTableRows(rows) {
        if (!Array.isArray(rows) || rows.length < 2) {
            return null;
        }

        const columnCount = Math.max(...rows.map((row) => row.length));
        const normalizedRows = rows
            .filter((row) => row.length === columnCount)
            .map((row) => row.map((cell) => String(cell || '').trim()));

        if (normalizedRows.length < 2) {
            return null;
        }

        const hasHeader = normalizedRows[0].some((cell) => !isChartNumberLike(cell));
        const headers = hasHeader
            ? normalizedRows[0].map(humanizeLabel)
            : normalizedRows[0].map((_, index) => `Coluna ${index + 1}`);
        const dataRows = hasHeader ? normalizedRows.slice(1) : normalizedRows;

        if (dataRows.length === 0) {
            return null;
        }

        const metricIndex = detectMetricColumn(dataRows, headers);

        if (metricIndex === -1) {
            return null;
        }

        const labelIndex = detectLabelColumn(dataRows, metricIndex);
        const labels = dataRows.map((row, index) => row[labelIndex] || `Item ${index + 1}`);
        const data = dataRows.map((row) => parseChartNumber(row[metricIndex]));

        if (labels.length < 2 || !data.every((value) => Number.isFinite(value))) {
            return null;
        }

        return {
            type: suggestedChartType(headers[labelIndex], headers[metricIndex]),
            title: chartTitleFromHeaders(headers[labelIndex], headers[metricIndex]),
            labels,
            data,
        };
    }

    function detectMetricColumn(rows, headers) {
        const preferredMetricWords = ['quantidade', 'qtd', 'total', 'valor', 'valores', 'nota', 'media', 'média', 'count'];
        const candidates = headers.map((header, index) => {
            const numericCount = rows.filter((row) => isChartNumberLike(row[index])).length;

            return {
                index,
                numericCount,
                preferred: preferredMetricWords.some((word) => normalizeKey(header).includes(normalizeKey(word))),
            };
        }).filter((candidate) => candidate.numericCount >= Math.max(2, Math.ceil(rows.length * 0.7)));

        if (candidates.length === 0) {
            return -1;
        }

        candidates.sort((left, right) => {
            if (left.preferred !== right.preferred) {
                return left.preferred ? -1 : 1;
            }

            return right.numericCount - left.numericCount;
        });

        return candidates[0].index;
    }

    function detectLabelColumn(rows, metricIndex) {
        const indexes = rows[0].map((_, index) => index).filter((index) => index !== metricIndex);
        const textualIndex = indexes.find((index) => rows.some((row) => !isChartNumberLike(row[index])));

        return textualIndex ?? indexes[0] ?? 0;
    }

    function suggestedChartType(labelHeader, metricHeader) {
        const label = normalizeKey(labelHeader);
        const metric = normalizeKey(metricHeader);

        if (/(mes|mês|ano|data|periodo|periodo|dia|semana)/.test(label)) {
            return 'line';
        }

        if (/(genero|sexo|status|situacao|distribuicao|distribuicao)/.test(label)
            || /(percentual|porcentagem|%)/.test(metric)) {
            return 'pie';
        }

        return 'bar';
    }

    function chartTitleFromHeaders(labelHeader, metricHeader) {
        const label = humanizeLabel(labelHeader || 'Categoria');
        const metric = humanizeLabel(metricHeader || 'Valor');

        return `${metric} por ${label}`;
    }

    function looksLikeChartBlock(block) {
        const rawJson = rawBlockText(block);
        const json = extractJsonObject(rawJson);

        if (!json) {
            return false;
        }

        try {
            const payload = normalizeChartValues(normalizePayload(parseChartJson(json), 0));

            return isNormalizedPayload(payload) && hasAnyChartKey(rawJson);
        } catch (error) {
            return false;
        }
    }

    function hasAnyChartKey(rawJson) {
        return ['type', 'tipo', 'chartType', 'labels', 'rotulos', 'rótulos', 'data', 'dados', 'valores', 'datasets', 'series']
            .some((key) => hasChartKey(rawJson, key));
    }

    function hasChartKey(rawJson, key) {
        return new RegExp(`["']\\s*${escapeRegExp(key)}\\s*["']\\s*:`, 'i').test(rawJson);
    }

    function escapeRegExp(value) {
        return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function parseChartJson(rawJson) {
        const cleanedJson = cleanChartJsonInput(rawJson);
        const json = repairHighlightedJson(extractJsonObject(cleanedJson) || cleanedJson) || '{}';

        try {
            return JSON.parse(json);
        } catch (error) {
            return JSON.parse(stripJsonComments(json));
        }
    }

    function cleanChartJsonInput(rawJson) {
        return String(rawJson || '')
            .replace(/\r\n?/g, '\n')
            .replace(/^\s*```(?:json-chart|json)?\s*/i, '')
            .replace(/\s*```\s*$/i, '')
            .replace(/^\s*(?:json-chart|json)\s*\n/i, '')
            .trim();
    }

    function logChartParseError(error, rawJson, strict) {
        if (typeof console === 'undefined' || typeof console.warn !== 'function') {
            return;
        }

        console.warn('Falha ao interpretar o payload do gráfico.', {
            strict,
            message: error?.message || String(error),
            raw: String(rawJson || '').slice(0, 500),
        });
    }

    function repairHighlightedJson(json) {
        return String(json || '')
            .replace(/(?:<span\s+)?class=(?:"|')?code-number(?:"|')?>/g, '')
            .replace(/<\/span>/g, '')
            .replace(/&quot;/g, '"')
            .replace(/&#039;/g, "'")
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&amp;/g, '&');
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

    function renderChartView(wrapper, payload, type, layoutAttempt = 0) {
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
            canvas.style.display = 'none';
            canvas.classList.add('chart-canvas-hidden');
            tableContainer.hidden = false;
            tableContainer.style.display = 'block';
            tableContainer.innerHTML = tableHtml(payload);

            if (downloadButton) {
                downloadButton.disabled = true;
                downloadButton.setAttribute('aria-disabled', 'true');
            }

            return;
        }

        if (!window.Chart) {
            wrapper.replaceWith(createChartError('Chart.js nao foi carregado.'));
            return;
        }

        setChartLoading(wrapper, true);
        canvas.hidden = false;
        canvas.style.display = 'block';
        canvas.classList.add('chart-canvas-hidden');
        tableContainer.hidden = true;
        tableContainer.style.display = 'none';
        tableContainer.innerHTML = '';

        if (!hasRenderableChartWidth(canvas) && layoutAttempt < 10) {
            window.requestAnimationFrame(() => {
                renderChartView(wrapper, payload, selectedType, layoutAttempt + 1);
            });
            return;
        }

        let revealTimer = null;
        const revealCanvas = () => {
            window.clearTimeout(revealTimer);
            canvas.classList.remove('chart-canvas-hidden');
            setChartLoading(wrapper, false);
        };

        try {
            wrapper._olliverseChartInstance = new Chart(canvas.getContext('2d'), chartOptions({
                ...payload,
                type: selectedType,
            }, () => {
                revealCanvas();
            }));
            window.requestAnimationFrame(() => {
                wrapper._olliverseChartInstance?.resize();
                wrapper._olliverseChartInstance?.update('none');
            });
            revealTimer = window.setTimeout(revealCanvas, 700);
        } catch (error) {
            window.clearTimeout(revealTimer);
            setChartLoading(wrapper, false);
            canvas.replaceWith(createChartError(error.message || 'Nao foi possivel renderizar o grafico.'));
            return;
        }

        if (downloadButton) {
            downloadButton.disabled = true;
            downloadButton.setAttribute('aria-disabled', 'true');
        }
    }

    function hasRenderableChartWidth(canvas) {
        const container = canvas.closest('.chart-canvas-container');
        const wrapper = canvas.closest('.plugin-chart-wrapper');
        const width = Math.max(
            canvas.getBoundingClientRect().width,
            container?.getBoundingClientRect().width || 0,
            wrapper?.getBoundingClientRect().width || 0
        );

        return width >= 40;
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

        if (!payload.data.every((value) => Number.isFinite(value))) {
            throw new Error('Os valores do gráfico precisam ser numéricos.');
        }
    }

    function normalizeChartValues(payload) {
        if (!isNormalizedPayload(payload)) {
            return payload;
        }

        return {
            ...payload,
            labels: payload.labels.map((label) => String(label ?? '').trim()),
            data: payload.data.map(parseChartNumber),
            datasets: normalizeChartDatasets(payload.datasets),
        };
    }

    function normalizeChartDatasets(datasets) {
        if (!Array.isArray(datasets)) {
            return undefined;
        }

        return datasets.map((dataset, index) => {
            return {
                label: String(dataset?.label || `Serie ${index + 1}`),
                data: Array.isArray(dataset?.data) ? dataset.data.map(parseChartNumber) : [],
            };
        }).filter((dataset) => {
            return dataset.data.length > 0 && dataset.data.every((value) => Number.isFinite(value));
        });
    }

    function parseChartNumber(value) {
        if (typeof value === 'number') {
            return Number.isFinite(value) ? value : NaN;
        }

        let text = String(value ?? '').trim();

        if (text === '') {
            return NaN;
        }

        text = text
            .replace(/\s+/g, '')
            .replace(/[R$€£%]/g, '')
            .replace(/[^\d,.\-]/g, '');

        if (text === '' || text === '-' || !/\d/.test(text)) {
            return NaN;
        }

        const commaIndex = text.lastIndexOf(',');
        const dotIndex = text.lastIndexOf('.');

        if (commaIndex > -1 && dotIndex > -1) {
            text = commaIndex > dotIndex
                ? text.replace(/\./g, '').replace(',', '.')
                : text.replace(/,/g, '');
        } else if (commaIndex > -1) {
            text = text.replace(',', '.');
        } else if (/^-?\d{1,3}(\.\d{3})+$/.test(text)) {
            text = text.replace(/\./g, '');
        }

        const number = Number(text);

        return Number.isFinite(number) ? number : NaN;
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
            const tablePayload = normalizeObjectTable(data, type, title);

            if (isNormalizedPayload(tablePayload)) {
                return tablePayload;
            }

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
            const tablePayload = normalizeObjectTable(series, type, title);

            if (isNormalizedPayload(tablePayload)) {
                return tablePayload;
            }

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

    function normalizeObjectTable(rows, type, title) {
        const labelKey = detectLabelKey(rows);

        if (!labelKey) {
            return null;
        }

        const metricKeys = Object.keys(rows[0] || {}).filter((key) => {
            return normalizeKey(key) !== normalizeKey(labelKey)
                && rows.some((row) => isChartNumberLike(row?.[key]));
        });

        if (metricKeys.length === 0) {
            return null;
        }

        if (rows.length <= metricKeys.length) {
            return {
                type,
                title,
                labels: metricKeys.map(humanizeLabel),
                data: metricKeys.map((key) => averageNumericValues(rows.map((row) => row?.[key]))),
                datasets: rows.map((row, index) => {
                    return {
                        label: String(valueByAliases(row, ['label', 'labels', 'name', 'nome', 'grupo', 'group', 'categoria', 'category']) || `Serie ${index + 1}`),
                        data: metricKeys.map((key) => parseChartNumber(row?.[key])),
                    };
                }),
            };
        }

        return {
            type,
            title,
            labels: rows.map((row) => valueByAliases(row, ['label', 'labels', 'name', 'nome', 'grupo', 'group', 'categoria', 'category'])),
            data: rows.map((row) => averageNumericValues(metricKeys.map((key) => row?.[key]))),
        };
    }

    function detectLabelKey(rows) {
        const keys = Object.keys(rows[0] || {});
        const aliases = ['label', 'labels', 'name', 'nome', 'grupo', 'group', 'categoria', 'category'];

        return keys.find((key) => aliases.includes(normalizeKey(key))) || keys.find((key) => {
            return rows.some((row) => typeof row?.[key] === 'string' && !isChartNumberLike(row[key]));
        });
    }

    function averageNumericValues(values) {
        const numbers = values.map(parseChartNumber).filter((value) => Number.isFinite(value));

        if (numbers.length === 0) {
            return NaN;
        }

        return Number((numbers.reduce((sum, value) => sum + value, 0) / numbers.length).toFixed(4));
    }

    function humanizeLabel(key) {
        return String(key || '')
            .trim()
            .replace(/[_-]+/g, ' ')
            .replace(/\s+/g, ' ')
            .replace(/^\w/, (letter) => letter.toUpperCase());
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
            const normalizedAlias = normalizeKey(alias);
            const matchedKey = Object.keys(payload).find((key) => normalizeKey(key) === normalizedAlias);

            if (matchedKey !== undefined) {
                return payload[matchedKey];
            }
        }

        return undefined;
    }

    function normalizeKey(key) {
        return String(key || '')
            .trim()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
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
        return isChartNumberLike(value);
    }

    function isChartNumberLike(value) {
        return Number.isFinite(parseChartNumber(value));
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
                datasets: chartDatasets(payload, colors),
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

    function chartDatasets(payload, colors) {
        if (Array.isArray(payload.datasets) && payload.datasets.length > 0 && payload.type !== 'pie') {
            return payload.datasets.map((dataset, index) => {
                const color = colors[index % colors.length];

                return {
                    label: dataset.label || `Serie ${index + 1}`,
                    data: Array.isArray(dataset.data) ? dataset.data : [],
                    backgroundColor: color,
                    borderColor: color.replace('0.75', '1'),
                    borderWidth: 1,
                    tension: 0.3,
                };
            });
        }

        return [{
            label: payload.title || 'Métricas',
            data: payload.data,
            backgroundColor: colors,
            borderColor: colors.map((color) => color.replace('0.75', '1')),
            borderWidth: 1,
            tension: 0.3,
        }];
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

    function exportChartImage(chartWrapper) {
        if (!chartWrapper) {
            return '';
        }

        const chart = chartWrapper._olliverseChartInstance;

        if (chart && typeof chart.toBase64Image === 'function') {
            return chart.toBase64Image('image/png', 1);
        }

        if (!window.Chart) {
            return '';
        }

        try {
            const payload = JSON.parse(chartWrapper.dataset.rawPayload || '{}');
            const canvas = document.createElement('canvas');
            const activeType = chartWrapper.querySelector('.switcher-btn.active')?.dataset.chartType;
            const type = normalizeChartType(activeType === 'table' ? payload.type || 'bar' : activeType || payload.type || 'bar');

            canvas.width = 900;
            canvas.height = 420;
            canvas.style.width = '900px';
            canvas.style.height = '420px';

            const exportOptions = chartOptions({
                ...payload,
                type,
            });

            exportOptions.options.responsive = false;
            exportOptions.options.animation = false;

            const exportChart = new Chart(canvas.getContext('2d'), exportOptions);

            exportChart.update('none');
            const image = exportChart.toBase64Image('image/png', 1);

            exportChart.destroy();

            return image;
        } catch (error) {
            return '';
        }
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

    processRenderedAssistantMessages();
})();

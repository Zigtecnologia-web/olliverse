(function() {
    window.OlliversePlugins = window.OlliversePlugins || {};

    window.OlliversePlugins.data_analyst = {
        processMessage(messageElement) {
            if (!messageElement || !window.Chart) {
                return;
            }

            messageElement.querySelectorAll('code.language-json-chart').forEach((block) => {
                renderChartBlock(block);
            });
        },
    };

    function renderChartBlock(block) {
        const host = block.closest('.code-block') || block.closest('pre');
        const rawJson = rawBlockText(block);

        if (!host || host.dataset.chartRendered === '1') {
            return;
        }

        let payload;

        try {
            payload = normalizePayload(JSON.parse(extractJsonObject(rawJson) || '{}'), 0);
            validatePayload(payload);
        } catch (error) {
            if (rawJson.trim().startsWith('{')) {
                host.replaceWith(createChartError(error.message || 'JSON de gráfico inválido.'));
            }

            return;
        }

        const chartWrapper = createChartWrapper();
        const canvas = chartWrapper.querySelector('.dynamic-chart-canvas');
        const downloadButton = chartWrapper.querySelector('.chart-download-btn');

        host.dataset.chartRendered = '1';
        host.replaceWith(chartWrapper);

        const chart = new Chart(canvas.getContext('2d'), chartOptions(payload));

        downloadButton.addEventListener('click', function() {
            downloadChartImage(chart, payload);
        });
    }

    function createChartWrapper() {
        const wrapper = document.createElement('div');

        wrapper.className = 'plugin-chart-wrapper';
        wrapper.innerHTML = [
            '<div class="chart-header">',
            '<span class="chart-badge">Relatório analítico gerado</span>',
            '</div>',
            '<div class="chart-canvas-container">',
            '<canvas class="dynamic-chart-canvas"></canvas>',
            '</div>',
            '<div class="chart-footer">',
            '<button type="button" class="chart-download-btn">Baixar imagem</button>',
            '</div>',
        ].join('');

        return wrapper;
    }

    function createChartError(message) {
        const error = document.createElement('div');

        error.className = 'chart-error';
        error.textContent = message;

        return error;
    }

    function validatePayload(payload) {
        const type = payload?.type || 'bar';

        if (!['bar', 'pie', 'line'].includes(type)) {
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

    function chartOptions(payload) {
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

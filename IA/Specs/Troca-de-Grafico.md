# Especificação Técnica de Funcionalidade (Spec): Troca Dinâmica de Visualização ("Visual Switcher") no Plugin de Gráficos

## 1. Visão Geral

Esta especificação introduz o recurso **Visual Switcher** no **Plugin de Análise de Dados** do **Olliverse**. Inspirado nos recursos de alternância rápida de visuais do Power BI, ele permite que o usuário altere instantaneamente a representação gráfica de um conjunto de dados já processado (alternando entre barras, pizza, linhas ou tabela) diretamente na interface da borda, sem a necessidade de reprocessar a consulta com o LLM.

---

## 2. Objetivos e Requisitos

* **Interatividade Instantânea na Borda:** Reduzir o tempo de resposta permitindo re-renderizações visuais diretas no JavaScript, sem novas chamadas ao Ollama.
* **Flexibilidade de Visualização:** Suportar múltiplos tipos de renderização para o mesmo payload de dados estruturados (`bar`, `pie`, `line`, `table`).
* **Experiência de Usuário (UX) Estilo BI:** Adicionar um cabeçalho interativo com ícones de alternância em cada card de gráfico gerado.

---

## 3. Especificação de Componentes e Código

### 3.1. Template HTML / View Atualizado (`plugins/data_analyst/includes/chart_card_view.php`)

Adição da barra de ferramentas (toolbar) com os botões de switcher no topo do card do gráfico:

```html
<div class="plugin-chart-card" data-chart-id="chart_<?php echo uniqid(); ?>">
    <div class="chart-card-header">
        <span class="chart-card-title"><!-- Título dinâmico --></span>
        <div class="visual-switcher-toolbar">
            <button class="switcher-btn active" data-type="bar" title="Gráfico de Barras">📊</button>
            <button class="switcher-btn" data-type="pie" title="Gráfico de Pizza">🥧</button>
            <button class="switcher-btn" data-type="line" title="Gráfico de Linhas">📈</button>
            <button class="switcher-btn" data-type="table" title="Visualização em Tabela">📋</button>
        </div>
    </div>
    
    <div class="chart-viewport" style="position: relative; height: 260px; width: 100%;">
        <canvas class="dynamic-chart-canvas"></canvas>
        <div class="table-viewport hidden" style="overflow-x: auto; height: 100%;">
            <!-- Tabela gerada dinamicamente via JS se selecionada -->
        </div>
    </div>
</div>

```

---

### 3.2. Script Frontend e Gerenciador de Instâncias (`plugins/data_analyst/assets/switcher.js`)

Este script armazena o payload bruto dos dados na instância do elemento e reconstrói o Chart.js (ou exibe a tabela) sob demanda ao clicar nos botões da toolbar:

```javascript
window.OlliversePlugins = window.OlliversePlugins || {};

window.OlliversePlugins.visualSwitcher = {
    initCard: function(cardElement, rawPayload) {
        let currentChartInstance = null;
        const canvas = cardElement.querySelector('.dynamic-chart-canvas');
        const tableViewport = cardElement.querySelector('.table-viewport');
        const switcherButtons = cardElement.querySelectorAll('.switcher-btn');

        // Salva o título no header
        cardElement.querySelector('.chart-card-title').textContent = rawPayload.title || 'Métricas';

        // Função para destruir e recriar o gráfico ou tabela
        const renderView = (type) => {
            // Limpa instâncias anteriores
            if (currentChartInstance) {
                currentChartInstance.destroy();
                currentChartInstance = null;
            }

            if (type === 'table') {
                canvas.style.display = 'none';
                tableViewport.style.display = 'block';
                tableViewport.innerHTML = this.generateTableHtml(rawPayload);
                return;
            }

            // Modo Gráfico (Chart.js)
            tableViewport.style.display = 'none';
            canvas.style.display = 'block';

            const ctx = canvas.getContext('2d');
            currentChartInstance = new Chart(ctx, {
                type: type, // 'bar', 'pie', 'line'
                data: {
                    labels: rawPayload.labels,
                    datasets: [{
                        label: rawPayload.title || 'Métricas',
                        data: rawPayload.data,
                        backgroundColor: [
                            'rgba(52, 152, 219, 0.7)',
                            'rgba(46, 204, 113, 0.7)',
                            'rgba(241, 196, 15, 0.7)',
                            'rgba(155, 89, 182, 0.7)',
                            'rgba(231, 76, 60, 0.7)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: (type === 'pie') ? {} : {
                        y: { beginAtZero: true }
                    }
                }
            });
        };

        // Event Listeners para os botões do Switcher
        switcherButtons.forEach(btn => {
            btn.addEventListener('click', (e) => {
                switcherButtons.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                
                const selectedType = btn.getAttribute('data-type');
                renderView(selectedType);
            });
        });

        // Renderização inicial baseada no tipo padrão do payload
        renderView(rawPayload.type || 'bar');
    },

    generateTableHtml: function(payload) {
        let html = '<table class="plugin-data-table"><thead><tr><th>Categoria</th><th>Valor</th></tr></thead><tbody>';
        for (let i = 0; i < payload.labels.length; i++) {
            html += `<tr><td>${payload.labels[i]}</td><td>${payload.data[i]}</td></tr>`;
        }
        html += '</tbody></table>';
        return html;
    }
};

```

---

### 3.3. Estilos CSS da Toolbar e da Tabela (`plugins/data_analyst/assets/style.css`)

```css
.plugin-chart-card {
    background: rgba(255, 255, 255, 0.03);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 12px;
    padding: 14px;
    margin: 12px 0;
}

.chart-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
    padding-bottom: 8px;
}

.chart-card-title {
    font-size: 14px;
    font-weight: 600;
    color: var(--text-main, #fff);
}

.visual-switcher-toolbar {
    display: flex;
    gap: 4px;
    background: rgba(0, 0, 0, 0.2);
    padding: 3px;
    border-radius: 8px;
}

.switcher-btn {
    background: transparent;
    border: none;
    cursor: pointer;
    font-size: 14px;
    padding: 4px 8px;
    border-radius: 6px;
    transition: background 0.2s ease;
}

.switcher-btn:hover {
    background: rgba(255, 255, 255, 0.1);
}

.switcher-btn.active {
    background: rgba(52, 152, 219, 0.4);
}

.plugin-data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    color: var(--text-main, #fff);
}

.plugin-data-table th, .plugin-data-table td {
    padding: 8px 12px;
    text-align: left;
    border-bottom: 1px solid rgba(255, 255, 255, 0.06);
}

.plugin-data-table th {
    background: rgba(255, 255, 255, 0.05);
    font-weight: 600;
}

```

---

## 4. Critérios de Aceite

1. **Toolbars Independentes:** Cada gráfico renderizado no chat possui seu próprio conjunto de botões de alternância (`bar`, `pie`, `line`, `table`).
2. **Mutação Instantânea:** O clique em um botão do switcher destroi o gráfico atual e reconstrói o novo formato via JavaScript em milissegundos, sem disparar novas requisições para o Ollama.
3. **Persistência de Dados Brutos:** O payload original fornecido pelo LLM permanece armazenado na instância do componente, permitindo idas e vindas ilimitadas entre os tipos de visualização.
4. **Visão Tabular Integrada:** A opção de tabela (`📋`) converte dinamicamente os arrays de `labels` e `data` em uma tabela HTML estruturada e responsiva dentro do mesmo card.
# Especificação Técnica de Funcionalidade (Spec): Sistema de Plugins & Plugin de Análise de Dados (Gráficos)

## 1. Visão Geral

Esta especificação detalha a arquitetura e a implementação da **Arquitetura de Plugins** no **Olliverse**, utilizando como primeiro caso de uso o **Plugin de Análise de Dados (Data Analyst)**, que processa métricas estruturadas enviadas pelo usuário e renderiza gráficos interativos diretamente na interface do chat.

---

## 2. Objetivos e Requisitos

* **Desacoplamento:** O núcleo do Olliverse não deve carregar dependências de plugins (como o Chart.js) a menos que o plugin correspondente esteja ativo.
* **Extensibilidade:** Permitir a adição futura de novos plugins criando apenas novos módulos isolados.
* **Experiência do Usuário (UX):** Ativação simples via menu modal/dropdown de plugins e renderização fluida do gráfico na resposta do assistente.

---

## 3. Arquitetura de Diretórios (Backend / Frontend)

Os plugins residirão em uma pasta dedicada na raiz do projeto, permitindo carregamento dinâmico:

```text
olliverse/
├── plugins/
│   ├── data_analyst/
│   │   ├── manifest.json       # Metadados e configurações do plugin
│   │   ├── assets/
│   │   │   ├── script.js       # Lógica do Chart.js e interceptação de DOM
│   │   │   └── style.css       # Estilos específicos do container do gráfico
│   │   └── includes/
│   │       ├── prompt.php      # System prompt injetado no LLM
│   │       └── view.php        # Template HTML do canvas do gráfico

```

### Exemplo de `manifest.json`:

```json
{
    "slug": "data_analyst",
    "name": "Análise de Dados & Gráficos",
    "version": "1.0.0",
    "description": "Permite que o LLM processe dados estruturados e gere gráficos interativos.",
    "icon": "📊",
    "dependencies": {
        "js": ["https://cdn.jsdelivr.net/npm/chart.js"]
    }
}

```

---

## 4. Fluxo de Execução (Lifecycle)

1. **Carregamento (Bootstrap):**
* O PHP lê quais plugins estão ativos (armazenados em sessão, arquivo de configuração local ou banco de dados do usuário).
* Se o plugin `data_analyst` estiver ativo, o PHP injeta dinamicamente o arquivo CSS, o script do Chart.js e o script customizado (`script.js`) no cabeçalho ou rodapé do `chat.php`.


2. **Injetando o System Prompt:**
* Quando o plugin está ativo, o payload enviado para o Ollama recebe uma instrução de sistema adicional (`prompt.php`), exigindo que o LLM retorne um bloco JSON estruturado sempre que detectar dados numéricos tabulares.


3. **Renderização no Frontend:**
* O LLM responde via streaming. O parser de markdown do chat identifica blocos de código específicos (ex: `json-chart ... `).
* O `script.js` do plugin intercepta esse bloco, extrai o JSON e instancia dinamicamente o gráfico no elemento `<canvas>`.



---

## 5. Especificação de Código dos Componentes

### 5.1. Template HTML / View (`plugins/data_analyst/includes/view.php`)

Este fragmento é injetado no container de mensagens do chat quando o LLM gera um relatório com dados visuais:

```html
<div class="plugin-chart-wrapper">
    <div class="chart-header">
        <span class="chart-badge">📊 Relatório Analítico Gerado</span>
    </div>
    <div class="chart-canvas-container" style="position: relative; height: 280px; width: 100%;">
        <canvas class="dynamic-chart-canvas"></canvas>
    </div>
</div>

```

### 5.2. Script Frontend (`plugins/data_analyst/assets/script.js`)

Responsável por escanear as mensagens renderizadas, capturar o payload JSON gerado pela IA e construir o gráfico:

```javascript
document.addEventListener("DOMContentLoaded", () => {
    // Função acionada após o término do stream da mensagem do assistente
    window.OlliversePlugins = window.OlliversePlugins || {};
    
    window.OlliversePlugins.data_analyst = {
        init: function(messageElement, jsonPayload) {
            const canvas = messageElement.querySelector('.dynamic-chart-canvas');
            if (!canvas) return;

            const ctx = canvas.getContext('2d');
            new Chart(ctx, {
                type: jsonPayload.type || 'bar', // 'bar', 'pie', 'line', etc.
                data: {
                    labels: jsonPayload.labels,
                    datasets: [{
                        label: jsonPayload.title || 'Métricas',
                        data: jsonPayload.data,
                        backgroundColor: [
                            'rgba(52, 152, 219, 0.7)',
                            'rgba(46, 204, 113, 0.7)',
                            'rgba(241, 196, 15, 0.7)',
                            'rgba(155, 89, 182, 0.7)'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: jsonPayload.type === 'pie' ? {} : {
                        y: { beginAtZero: true }
                    }
                }
            });
        }
    };
});

```

### 5.3. System Prompt Customizado (`plugins/data_analyst/includes/prompt.php`)

```php
<?php
return "Você possui o plugin de Análise de Dados ativado. Sempre que o usuário fornecer dados tabulares, JSON ou métricas (como alunos, cadastros, vendas) e solicitar contagens ou análises estatísticas, além da sua resposta textual descritiva, você DEVE retornar obrigatoriamente um bloco de código JSON puro identificável com a tag ```json-chart no seguinte formato estrito:
```json-chart
{
  \"type\": \"bar\",
  \"title\": \"Título da Métrica\",
  \"labels\": [\"Label 1\", \"Label 2\"],
  \"data\": [10, 25]
}

```

O campo 'type' pode ser 'bar', 'pie' ou 'line'. Não oCulte este bloco JSON se houver dados sumarizáveis.";

```

---

## 6. Interface do Menu de Plugins (UI/UX)
Adição de um botão de painel flutuante na interface principal do Olliverse:

```html
<!-- Botão flutuante ou no Header -->
<div class="dropdown-plugins">
    <button id="btn-plugins-toggle" class="btn-icon" title="Gerenciar Plugins">🧩</button>
    <div id="plugins-menu-dropdown" class="dropdown-content hidden">
        <div class="dropdown-header">Plugins Ativos</div>
        <label class="plugin-toggle-item">
            <input type="checkbox" data-plugin="data_analyst" checked> 
            <span>📊 Análise de Dados & Gráficos</span>
        </label>
    </div>
</div>

```

---

## 7. Critérios de Aceite

1. O menu de plugins permite ativar/desativar o módulo de gráficos sem recarregar a aplicação inteira.
2. Com o plugin ativo, ao enviar um conjunto de dados brutos (ex: lista de alunos com idade e série), o LLM responde estruturando o JSON correto.
3. O frontend intercepta o bloco `json-chart`, carrega o Chart.js sob demanda e renderiza o gráfico de forma responsiva no balão de chat.
4. Caso o plugin esteja desativado, o script do Chart.js não é injetado e o LLM responde apenas em texto puro Markdown.
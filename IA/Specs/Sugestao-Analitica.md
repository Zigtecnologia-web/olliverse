# Especificação Técnica de Funcionalidade (Spec): Integração do Plugin de Análise de Dados com a Camada RAG & SQLite existente (Olliverse)

## 1. Visão Geral

Esta especificação atualiza o **Plugin de Análise de Dados (Data Analyst)** do **Olliverse**, eliminando fluxos redundantes de upload de arquivos. O plugin passa a **reaproveitar a infraestrutura de RAG e banco de dados SQLite existente** no núcleo da aplicação para extrair, inspecionar e sumarizar dados estruturados já indexados, gerando dinamicamente sugestões analíticas e gráficos interativos na borda.

---

## 2. Objetivos e Requisitos

* **Zero Duplicação:** Aproveitar integralmente o ecossistema de arquivos já anexados, processados e armazenados no SQLite do Olliverse.
* **Inspeção Baseada em Contexto:** Ler o conteúdo ou os metadados do documento indexado na sessão atual para gerar proativamente os chips de sugestão analítica.
* **Orquestração de Borda:** Manter a renderização visual (Chart.js) e o tratamento de UI na borda, consumindo tabelas Markdown estruturadas fornecidas pelo LLM a partir dos dados do SQLite.

---

## 3. Arquitetura do Fluxo Integrado (Lifecycle)

1. **Seleção do Arquivo via RAG / SQLite:**
* O usuário seleciona um documento que **já foi previamente indexado** no SQLite do Olliverse para a sessão de chat atual.
* O backend recupera do SQLite o conteúdo estruturado ou os chunks relevantes desse arquivo.


2. **Inspecionamento Silencioso (Context Injection):**
* Quando o plugin `data_analyst` está ativo, o Olliverse injeta automaticamente uma amostra desse conteúdo recuperado do SQLite junto ao **System Prompt de Inspeção**.


3. **Geração de Insights na Borda (UI):**
* O LLM processa o esquema e retorna as sugestões de consultas analíticas.
* O frontend renderiza os *chips* interativos de sugestão logo acima da conversa.


4. **Execução e Renderização de Gráficos:**
* Ao clicar em um chip ou perguntar sobre os dados, o RAG busca os registros no SQLite, o modelo processa as métricas e retorna uma tabela Markdown com categorias e valores numéricos. O frontend detecta a tabela e exibe o botão **Plotar Gráfico**, que transforma os dados já presentes na mensagem em um gráfico dinâmico pelo Chart.js.



---

## 4. Especificação de Componentes e Código Atualizados

### 4.1. System Prompt de Inspeção RAG (`plugins/data_analyst/includes/inspect_prompt.php`)

Este prompt é alimentado diretamente pelos dados recuperados do SQLite do arquivo ativo:

```php
<?php
return "O usuário selecionou um arquivo previamente indexado no sistema RAG (SQLite). Analise a amostra de dados estruturados fornecida abaixo. 
Sua tarefa é agir como um Cientista de Dados e retornar EXCLUSIVAMENTE um bloco de código JSON puro (sem markdown adicional de texto) estruturado exatamente no seguinte formato:
{
  \"summary\": \"Breve resumo descritivo do que o documento contém com base nos dados do SQLite.\",
  \"suggestions\": [
    {
      \"title\": \"Título curto para o botão\",
      \"query\": \"A pergunta analítica completa relacionada aos dados do arquivo\",
      \"chart_type\": \"bar\"
    }
  ]
}
O campo 'chart_type' deve ser 'bar', 'pie' ou 'line' baseado no tipo de métrica mais adequado.";

```

### 4.2. Template HTML / View de Insights na Borda (`plugins/data_analyst/includes/insights_view.php`)

Injetado no chat quando um arquivo indexado é vinculado à sessão com o plugin ativo:

```html
<div class="data-insights-container">
    <div class="insights-header">
        <span class="insights-icon">📊</span>
        <div class="insights-text">
            <strong>Análise Inteligente (Base SQLite / RAG)</strong>
            <p class="insights-summary-text"><!-- Preenchido dinamicamente via JS --></p>
        </div>
    </div>
    <div class="insights-chips-wrapper">
        <span class="chips-label">Sugestões de exploração para este documento:</span>
        <div class="dynamic-chips-container">
            <!-- Chips gerados com base na inspeção do SQLite -->
        </div>
    </div>
</div>

```

### 4.3. Script Frontend de Integração (`plugins/data_analyst/assets/insights.js`)

---

## 5. Critérios de Aceite

1. **Reaproveitamento de RAG:** O plugin não cria nenhuma interface nova de upload; ele escuta e consome os dados do arquivo já indexado e armazenado no SQLite da aplicação.
2. **Inspeção Contextual:** Ao ativar o plugin com um documento selecionado na sessão, o Olliverse envia uma amostra extraída do SQLite para o LLM gerar o JSON de sugestões analíticas.
3. **Ação Integrada:** Os chips de sugestão gerados na borda utilizam o fluxo de chat existente para buscar dados no RAG, processar via LLM e preparar tabelas Markdown plotáveis pelo botão local do plugin.

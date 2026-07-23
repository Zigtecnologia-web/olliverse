# Especificação Técnica de Funcionalidade (Spec): Blindagem e Aprimoramento da Geração de Gráficos por IA (Data Analyst Plugin)

## 1. Visão Geral e Objetivo

Esta especificação detalha os ajustes arquiteturais e comportamentais necessários para estabilizar o **Plugin de Análise de Dados (Data Analyst)** do **Olliverse**. O objetivo é eliminar o comportamento onde o modelo LLM exibe o JSON cru diretamente na interface em vez de disparar a renderização do gráfico, garantindo maior determinismo através de um System Prompt mais rígido, controle de temperatura no payload e parser defensivo no frontend.

---

## 2. Requisitos de Ajuste Técnico e Comportamento

### 2.1. Rigidez no System Prompt do Plugin

* **Substituição do Prompt Base:** Atualizar o arquivo `plugins/data_analyst/includes/prompt.php` para uma diretiva imperativa e estrita.
* **Proibição de Variações:** O modelo deve ser instruído a não adicionar comentários, textos explicativos extras ou chaves fora do contrato estrito dentro do bloco de marcação.

### 2.2. Controle de Temperatura (Deterministic Output)

* **Parâmetro de Inferência:** Ajustar o payload enviado ao Ollama para incluir opções de hiperparâmetros de amostragem (`temperature`).
* **Valor Recomendado:** Fixar a temperatura em `0.1` ou `0.2` durante as chamadas com o plugin ativo, forçando o modelo a seguir estritamente a estrutura JSON e reduzindo alucinações sintáticas.

### 2.3. Parser Defensivo no Frontend

* **Sanitização de Bloco:** Aprimorar o script de captura em `plugins/data_analyst/assets/script.js` para realizar limpeza automática de tags Markdown residuais (`json-chart`, `json`, quebras de linha e espaços excedentes) antes de executar o `JSON.parse`.
* **Tratamento de Exceções:** Caso o JSON retorne corrompido, o sistema deve registrar a falha de forma silenciosa no console sem quebrar a renderização normal do restante da mensagem em Markdown.

---

## 3. Especificação de Alterações de Código

### 3.1. Novo System Prompt (`plugins/data_analyst/includes/prompt.php`)

```php
<?php
return "ATENÇÃO: Você possui a capacidade de gerar gráficos estatísticos. 
SE o usuário solicitar gráficos, distribuições, comparações visuais ou contagens de dados tabulares (como planilhas, arquivos CSV/XLSX ou listas), você DEVE obrigatoriamente incluir no final da sua resposta um bloco de código estruturado exatamente com a tag \`\`\`json-chart, seguindo este formato JSON estrito, sem chaves extras, sem comentários e sem texto adicional dentro do bloco:

\`\`\`json-chart
{
  \"type\": \"bar\",
  \"title\": \"Título Descritivo\",
  \"labels\": [\"Categoria A\", \"Categoria B\"],
  \"data\": [10, 25]
}
\`\`\`

REGRAS OBRIGATÓRIAS:
1. O campo 'type' deve ser obrigatoriamente 'bar', 'pie' ou 'line'.
2. 'labels' deve ser um array de strings.
3. 'data' deve ser um array de números inteiros ou decimais correspondentes aos valores.
4. Nunca coloque blocos de texto dentro do objeto JSON além das chaves especificadas.";

```

### 3.2. Ajuste de Temperatura no Cliente Ollama (`app/Services/OllamaClient.php` ou equivalente)

No momento de montar o array de envio para a API do Ollama, injetar o bloco de opções:

```php
$payload = [
    'model' => $model,
    'messages' => $messages,
    'stream' => true,
    'options' => [
        'temperature' => 0.1 // Garante maior precisão estrutural e determinismo
    ]
];

```

### 3.3. Função de Parse Defensivo no Frontend (`plugins/data_analyst/assets/script.js`)

```javascript
function parseChartPayload(rawContent) {
    try {
        let cleanJson = rawContent.trim();
        // Remove marcações markdown e resíduos comuns gerados por LLMs
        cleanJson = cleanJson
            .replace(/^```json-chart/, '')
            .replace(/^```json/, '')
            .replace(/```$/, '')
            .trim();

        const parsed = JSON.parse(cleanJson);
        
        if (parsed && parsed.labels && parsed.data && Array.isArray(parsed.labels) && Array.isArray(parsed.data)) {
            return parsed;
        }
    } catch (e) {
        console.error("Falha ao interpretar o payload do gráfico:", e);
    }
    return null;
}

```

---

## 4. Critérios de Aceite

1. **Conformidade de Saída:** O modelo deixa de exibir o JSON cru como texto legível na tela e passa a estruturar o bloco corretamente sob a tag `json-chart`.
2. **Estabilidade de Renderização:** O parser defensivo converte com sucesso o payload sanitizado e instancia o Chart.js no elemento `<canvas>` correspondente sem erros de sintaxe.
3. **Previsibilidade:** Com a temperatura ajustada para `0.1`, a adesão ao contrato estrutural de gráficos atinge mais de 95% de assertividade mesmo em modelos menores.
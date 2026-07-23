# Especificação Técnica de Funcionalidade (Spec): Botão Interativo de "Plotar Gráfico" (Detecção Heurística e Acionamento Manual)

## 1. Visão Geral e Objetivo

Esta especificação detalha a substituição do modelo atual de geração autônoma de blocos `json-chart` por uma abordagem determinística e resiliente: a **detecção de dados tabulares** nas respostas da IA combinada com a exibição de um botão de ação rápida (**"Plotar Gráfico"**). O objetivo é eliminar a frustração de exibir JSONs crus na tela quando modelos menores falham em seguir contratos estritos de system prompt, transferindo o controle do gatilho para o usuário de forma elegante.

---

## 2. Requisitos de Experiência do Usuário (UX) e Comportamento

### 2.1. Detecção Automática de Dados Estruturados

* **Varredura no Frontend:** Assim que o streaming da resposta do assistente for concluído, o script de interface analisa o texto Markdown renderizado em busca de estruturas tabulares (ex: tabelas Markdown usando `| Coluna |` ou listas numéricas padronizadas).
* **Condição de Exibição:** Se forem detectados dados numéricos passíveis de visualização e o plugin **Análise de Dados** estiver ativo, um botão de ação discreto (estilizado com o ícone 📊 e o texto "Plotar Gráfico") é injetado automaticamente no rodapé do balão da mensagem.

### 2.2. Acionamento Manual e Seletor de Tipo

* **Abertura do Modal/Painel de Plotagem:** Ao clicar no botão **"Plotar Gráfico"**, o sistema abre um seletor rápido ou converte diretamente o bloco de dados da mensagem em um canvas interativo.
* **Flexibilidade de Visualização:** O usuário pode escolher instantaneamente o formato de exibição desejado (Barras, Pizza ou Linhas) utilizando os controles locais do gráfico, sem novas requisições ao Ollama.

---

## 3. Especificação de Arquitetura e Fluxo de Dados

### 3.1. Heurística de Detecção (Client-Side Parser)

* Uma função em JavaScript (`hasTableOrStructuredData(markdownText)`) verifica se a string da resposta contém caracteres de tabela Markdown (`|`) seguidos por valores numéricos.
* Caso positivo, o texto da tabela é extraído por regex simples para montar a matriz de `labels` e `data` de forma automatizada, eliminando a dependência de o LLM gerar um JSON perfeito.

### 3.2. Fluxo de Execução

1. O LLM responde em texto natural e tabelas Markdown estruturadas (tarefa em que modelos locais possuem altíssima precisão).
2. O parser de frontend identifica a tabela na mensagem renderizada.
3. O botão **"Plotar Gráfico"** surge de forma fluida no rodapé da mensagem.
4. O usuário clica, e o Chart.js renderiza o gráfico imediatamente com base nos dados tabulares já presentes na tela.

---

## 4. Critérios de Aceite

1. **Fim do JSON Cru:** O modelo deixa de ser instruído a gerar blocos complexos de `json-chart`, focando exclusivamente em análises textuais e tabelas Markdown legíveis.
2. **Resiliência Garantida:** O botão de plotagem aparece de forma consistente sempre que há dados estruturados na resposta, funcionando com qualquer modelo do Ollama.
3. **Independência de Temperatura:** A funcionalidade de gráficos deixa de falhar por variações de temperatura ou alucinações sintáticas do LLM.
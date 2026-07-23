# Especificação Técnica de Funcionalidade (Spec): Refatoração de UX/UI – Limpeza e Compactação do Painel RAG (Olliverse)

## 1. Visão Geral e Objetivo

Esta especificação detalha a refatoração visual e estrutural da área superior do chat no **Olliverse** (conforme evidenciado na análise de interface). O objetivo atual é eliminar a poluição visual causada pelo bloco expandido de "Análise Inteligente (SQLite / RAG)", pelos chips fixos de sugestões e pelo switch de inspeção, que atualmente competem por atenção e sufocam o fluxo principal de mensagens. A nova proposta adota um design minimalista, flutuante e colapsável, inspirado em padrões de mercado de IA conversacional.

---

## 2. Requisitos de Layout e Experiência do Usuário (UX)

### 2.1. Ocultação do Bloco Roxo/Cinza Estático do Topo

* **Remoção do Card Fixo:** O bloco expansivo que descreve o arquivo CSV e o status do SQLite logo abaixo do cabeçalho do chat deve ser inteiramente removido do fluxo estático do DOM.
* **Substituição por Pílula de Contexto Minimalista:** As informações do arquivo ativo migram para uma pílula flutuante e discreta posicionada no cabeçalho superior direito (onde hoje consta "1 documento ativo"), contendo um ícone de arquivo, o nome truncado (ex: `📁 alunos_ficticios_bahia.csv`) e um botão de fechar (`×`) para desvincular o documento rapidamente.

### 2.2. Compactação e Colapso das Sugestões de Exploração

* **Comportamento Padrão (Colapsado):** Os botões de sugestão (*"Visualizar Distribuição por Gênero"*, *"Comparar Médias"*, etc.) não devem ocupar espaço vertical fixo na tela inicial.
* **Gatilho de Expansão ("Insights Rápidos"):** Criar um botão sutil de ícone (ex: lâmpada 💡 ou estrelas ✨) no input de texto ou ao lado da pílula de documentos. Ao clicar, um menu flutuante suspenso (*dropdown/popover*) exibe os chips de sugestão de forma limpa, sem empurrar as mensagens para baixo.

### 2.3. Migração da "Inspeção de Documento" para Gaveta Lateral (Drawer)

* **Remoção do Switch Poluente:** O switch verde de "Inspecionar documento" e suas descrições longas deixam de ocupar espaço no painel principal do chat.
* **Acesso via Header de Documentos:** A funcionalidade de inspecionar dados tabulares ou estruturas do SQLite passa a ser um painel lateral deslizante (*drawer*) acionado pelo ícone de banco de dados/documento já existente na barra superior de ferramentas da persona.

---

## 3. Especificação de Alterações de Interface (Front-End)

### 3.1. Nova Hierarquia Visual do Topo do Chat

```
+-------------------------------------------------------------------------+
| Olliverse     [Motor: Ollama] [Modelo: llama3.2]           [ 📁 1 doc × ] |
+-------------------------------------------------------------------------+
| [ Chat principal limpo e focado no fluxo de conversação ]               |
|                                                                         |
|                                                                         |
|                                                                         |
|                                                                         |
+-------------------------------------------------------------------------+
| [Escreva sua mensagem aqui...]                                      (↑)|
+-------------------------------------------------------------------------+

```

### 3.2. Diretrizes de Estilização (Tailwind / CSS)

* **Pílula de Documento Ativo:** Utilizar fundo translúcido escuro (`bg-zinc-800/60`, borda sutil `border border-zinc-700/50`, texto `text-xs text-zinc-300`, cantos arredondados `rounded-full`).
* **Popovers Flutuantes:** Garantir que menus de sugestão ou painéis de inspeção utilizem posicionamento absoluto flutuante (`absolute z-50 shadow-2xl backdrop-blur-md`) para não interferir no fluxo de layout (`flex-col`) do chat.

---

## 4. Critérios de Aceite

1. **Redução de Poluição Visual:** O topo do chat fica totalmente limpo e livre de blocos descritivos estáticos antes da primeira mensagem do usuário.
2. **Acessibilidade de Contexto:** O usuário continua sabendo qual documento está ativo através da pílula compacta no cabeçalho, mantendo a opção de desvincular o arquivo em 1 clique.
3. **Fluidez do Chat:** O espaço útil de tela dedicado às mensagens e ao campo de input é maximizado, eliminando a sensação de "empilhamento" de elementos de RAG.
# Especificação Técnica de Funcionalidade (Spec): Sistema de Workspaces (Espaços de Trabalho)

## 1. Visão Geral e Objetivo

Esta especificação detalha a implementação dos **Workspaces (Espaços de Trabalho)** no **Olliverse**. O objetivo é introduzir um agrupamento lógico e isolado para as conversas e o gerenciamento de arquivos RAG. Isso resolve a saturação do histórico lateral unificado, permitindo que o usuário alterne fluidamente entre contextos distintos (como projetos de software, escrita técnica e estudos de engenharia de dados) com alto desempenho, simplicidade e elegância visual.

---

## 2. Requisitos de Experiência do Usuário (UX) e Comportamento

### 2.1. Seletor de Workspace na Barra Lateral (Sidebar)

* **Ponto de Entrada:** Um seletor minimalista e elegante (estilo dropdown com ícones ou abas compactas) posicionado no topo absoluto da barra lateral de histórico.
* **Ações Rápidas:** Opção clara para **"+ Novo Workspace"** permitindo definir um nome personalizado e um ícone representativo (emoji ou tag visual).
* **Comportamento Reativo:** Ao alternar de workspace, a lista de conversas (*chats*) na barra lateral é filtrada instantaneamente via animação suave, exibindo apenas o histórico pertencente ao contexto selecionado.

### 2.2. Isolamento de Contexto e Documentos RAG por Workspace

* **Contexto Dedicado:** Cada workspace gerencia seu próprio escopo de conversas.
* **Documentos Vinculados:** Os arquivos indexados e documentos ativos para RAG ficam atrelados ao workspace atual, evitando a contaminação cruzada de informações (ex: documentos de código C# não aparecem ao interagir no workspace de escrita do livro).

---

## 3. Especificação de Arquitetura e Banco de Dados (SQLite)

### 3.1. Estrutura de Dados

Para suportar os workspaces sem alterar a estabilidade do ecossistema local, a base SQLite recebe duas modificações estruturais diretas:

1. **Tabela `workspaces`:**
* `id` (INTEGER, Primary Key, AutoIncrement)
* `name` (TEXT, Not Null)
* `icon` (TEXT, Nullable)
* `created_at` (DATETIME)


2. **Modificação na Tabela `chats`:**
* Adição da coluna `workspace_id` (INTEGER, Foreign Key referenciando `workspaces(id)`).
* Caso o usuário não possua workspaces customizados, um workspace padrão (*"Geral"* ou *"Default"*) é criado automaticamente na inicialização para legar as conversas existentes.



---

## 4. Especificação de Interface (Front-End & Design System)

### 4.1. Layout Minimalista e Elegante

* **Harmonia Visual:** Manter a identidade visual escura (*zinc-900*, *zinc-800* com bordas sutis e acentos em verde esmeralda).
* **Transições Fluídas:** Trocas de workspace sem recarregamento da página (Single Page Application feel), utilizando requisições assíncronas otimizadas para garantir alta performance.

---

## 5. Critérios de Aceite

1. **Organização Lógica:** O usuário consegue agrupar conversas em múltiplos workspaces distintos de forma intuitiva, limpando a poluição da barra lateral padrão.
2. **Isolamento Eficiente:** O histórico de conversas e os documentos de RAG respeitam estritamente o workspace ativo.
3. **Performance e Elegância:** A interface de alternância é rápida, responsiva e mantém a simplicidade minimalista característica do Olliverse.
# Especificação Técnica de Funcionalidade (Spec): Modo Tela Cheia / Foco (Zen Mode)

## 1. Visão Geral e Objetivo

Esta especificação detalha a implementação do **Modo Foco (Zen Mode)** no **Olliverse**. O objetivo é prover uma experiência imersiva e limpa para a análise de blocos de código extensos, planilhas complexas e gráficos gerados pelo plugin analítico, ocultando temporariamente elementos estruturais secundários (como a barra lateral de histórico e o cabeçalho expandido) para maximizar a área útil da conversa em tela cheia.

---

## 2. Requisitos de Experiência do Usuário (UX) e Comportamento

### 2.1. Acionamento do Modo Foco

* **Ponto de Entrada:** Adicionar um botão discreto de alternância (ícone de expansão/compressão de tela, estilo *Zen Mode*) no cabeçalho superior ou próximo aos controles de sessão da aplicação.
* **Comportamento de Transição:** Ao clicar no botão, a interface executa uma transição fluida (com animação CSS suave) alternando o estado global de layout entre o **Modo Normal** e o **Modo Foco**.

### 2.2. Alterações Visuais no Modo Foco

* **Ocultação da Barra Lateral:** A barra lateral de histórico (`Histórico`) é recolhida e ocultada por completo.
* **Recolhimento do Header:** O cabeçalho superior complexo é minimizado para uma barra de status extremamente fina ou ocultado, mantendo visíveis apenas os controles essenciais de saída (como o botão para desativar o Modo Foco).
* **Expansão da Área de Chat:** O container principal de mensagens, gráficos e visualização de dados expande-se dinamicamente para ocupar **100% da largura da viewport**.

### 2.3. Persistência e Atalhos

* **Teclado:** Permitir a saída imediata do Modo Foco pressionando a tecla `Escape`.
* **Persistência de Sessão:** O estado do Modo Foco pode ser mantido de forma volátil durante a navegação na sessão atual para evitar reaberturas manuais constantes.

---

## 3. Especificação de Componentes e Estrutura

### 3.1. Modificação de Classes CSS (`public/assets/css/app.css`)

* Introdução de uma classe modificadora no elemento raiz do layout (ex: `.zen-mode-active`).
* Regras de estilo para forçar ocultação (`display: none` ou `transform: translateX`) da barra lateral e encolhimento do header quando a classe estiver ativa, aplicando largura total (`width: 100%`) à área central de chat.

### 3.2. Gerenciamento de Estado no Frontend (`public/assets/js/`)

* Um script leve de controle de eventos de clique e escuta da tecla `Escape` que alterna a classe no container principal da aplicação e atualiza o ícone/tooltip do botão de foco.

---

## 4. Critérios de Aceite

1. **Ativação via UI:** O clique no botão de foco recolhe instantaneamente a barra lateral de histórico e o topo, expandindo a área de chat para a largura total da tela.
2. **Ativação via Teclado:** Pressionar a tecla `Escape` restaura imediatamente a interface ao layout padrão.
3. **Preservação de Funcionalidades:** Blocos de código, rolagem de mensagens e renderização de gráficos do plugin analítico continuam funcionando perfeitamente e com melhor legibilidade dentro do espaço expandido.
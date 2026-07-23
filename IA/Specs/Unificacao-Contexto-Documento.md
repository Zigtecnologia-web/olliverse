# Especificação Técnica de Funcionalidade (Spec): Unificação do Contexto de Documentos no Menu de Seleção

## 1. Visão Geral e Objetivo

Esta especificação detalha a remoção do interruptor global "Usar documentos" da interface do **Olliverse** e a transição para um modelo onde o uso do contexto de arquivos é controlado exclusivamente de forma direta e granular pelo menu/painel de gerenciamento de documentos. O objetivo é simplificar a interface, eliminar redundâncias visuais e adotar um padrão mental mais intuitivo de seleção por itens.

---

## 2. Requisitos de Experiência do Usuário (UX) e Comportamento

### 2.1. Eliminação do Toggle Global

* **Remoção do Elemento:** O componente visual de chave/interruptor ("Usar documentos") e as tags de arquivos flutuantes estendidas no topo do chat são completamente removidos da barra de contexto.
* **Simplificação do Estado:** O estado de "ligado/desligado" do uso de documentos deixa de existir como uma camada global separada. O contexto passa a ser ditado puramente pela seleção ativa ou inativa dos arquivos dentro do menu dedicado.

### 2.2. Gerenciamento Direto no Menu de Documentos

* **Comportamento por Seleção (Checkbox/Item State):** O menu de documentos passa a ser o ponto único de verdade. Cada arquivo listado possui um seletor individual (marcado/desmarcado).
* **Regra de Atativação:**
* Se o usuário selecionar um ou mais arquivos no menu, o motor de RAG/Vetorização entende automaticamente que o contexto está ativo para a conversa atual.
* Se nenhum arquivo estiver selecionado, a conversa opera puramente com o modelo de linguagem base (sem injeção de documentos).


* **Feedback Visual Compacto:** Na barra superior, onde antes ficavam as tags longas, passa a existir apenas um indicador de status resumido e elegante (ex: `📁 2 documentos ativos`), que serve como atalho para abrir o painel de seleção caso o usuário queira alterá-los.

---

## 3. Arquitetura da Solução e Fluxo de Dados

1. **Estado do Cliente (Frontend State):**
* A store ou estado local gerencia uma lista de IDs de documentos selecionados.
* A ausência de itens selecionados define o parâmetro de contexto de documentos como vazio, ocultando o envio de embeddings na requisição para a IA.


2. **Payload da API:**
* Quando o usuário envia uma mensagem no chat, o frontend coleta os IDs dos arquivos ativos marcados no painel de documentos e os envia no payload da requisição.
* O backend processa o RAG apenas se a lista de IDs contiver elementos, eliminando a dependência da antiga flag booleana global.



---

## 4. Critérios de Aceite

1. **Remoção da Redundância:** O interruptor global de "Usar documentos" é totalmente extinto da interface, liberando espaço visual no cabeçalho.
2. **Controle Granular Integrado:** Marcar ou desmarcar um documento dentro do menu de gerenciamento ativa ou desativa instantaneamente a sua inclusão no contexto da IA.
3. **Indicador de Status Limpo:** A interface exibe apenas um contador discreto de arquivos ativos, mantendo a tela limpa e focada no conteúdo principal.
# Especificação Técnica de Funcionalidade (Spec): Histórico Inteligente com Busca Instantânea e Títulos Automáticos

## 1. Visão Geral e Objetivo

Esta especificação detalha a implementação do **Histórico Inteligente** no **Olliverse**. O objetivo é resolver a saturação e a dificuldade de localização de conversas antigas na barra lateral, introduzindo um campo de busca em tempo real para filtrar termos nas mensagens e títulos, além de um mecanismo de resumo automático de títulos gerado por IA após o início de um novo chat.

---

## 2. Requisitos de Experiência do Usuário (UX) e Comportamento

### 2.1. Busca em Tempo Real na Barra Lateral

* **Ponto de Entrada:** Um input de busca fixo e limpo localizado no topo da barra lateral de histórico (substituindo ou aprimorando o campo atual de busca).
* **Comportamento Dinâmico:** À medida que o usuário digita, a lista de conversas na barra lateral é filtrada instantaneamente, exibindo apenas os chats cujos títulos ou conteúdos de mensagens correspondam ao termo buscado.
* **Estado Vazio (No Results):** Exibição de um aviso discreto na barra lateral caso nenhuma conversa corresponda ao filtro digitado.

### 2.2. Resumo Automático de Título por IA

* **Gatilho Silencioso:** Logo após o envio da primeira ou segunda mensagem de um chat novo (cujo título inicial seja padrão ou genérico), o sistema dispara uma requisição em segundo plano para o modelo leve do Ollama.
* **Processamento de Resumo:** A IA recebe o contexto inicial e gera um resumo conciso de 3 a 5 palavras focado no tema central da conversa.
* **Atualização Dinâmica:** O título do chat é atualizado automaticamente no SQLite e refletido em tempo real na barra lateral, eliminando títulos genéricos sem intervenção manual do usuário.

---

## 3. Especificação de Arquitetura e Fluxo de Dados

### 3.1. Camada de Backend (Endpoints e SQLite)

* **Endpoint de Busca no Histórico:**
* `GET /index.php?action=search_history&q=termo_pesquisado`
* Retorna um JSON contendo as conversas filtradas baseadas em correspondências parciais no título ou nos registros da tabela `messages`.


* **Endpoint de Atualização de Título:**
* `POST /index.php?action=update_chat_title`
* Recebe o `chat_id` e o novo título gerado, persistindo-o no registro correspondente da tabela `chats`.



### 3.2. Camada de Frontend (JavaScript / UI)

* **Filtro Reativo:** Event listeners no input de busca para ocultar/exibir os elementos da lista lateral via manipulação de classes CSS com base no texto digitado.
* **Requisição Assíncrona de Título:** Script client-side que, ao detectar a conclusão do primeiro ciclo de troca de mensagens em um chat novo, invoca silenciosamente a API de resumo de título e atualiza o DOM da barra lateral de forma fluida.

---

## 4. Critérios de Aceite

1. **Agilidade na Localização:** O campo de busca filtra instantaneamente as conversas na barra lateral por títulos e conteúdos de mensagens sem recarregar a página.
2. **Automação de Títulos:** Chats novos deixam de acumular títulos genéricos, recebendo um resumo assertivo gerado por IA logo após as primeiras interações.
3. **Harmonia Visual:** Os novos componentes integram-se perfeitamente à identidade visual escura e minimalista do Olliverse, preservando a fluidez da navegação lateral.
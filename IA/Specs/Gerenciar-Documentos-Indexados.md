# Especificação Técnica de Funcionalidade (Spec): Painel de Gerenciamento e Exclusão de Documentos Indexados (RAG Manager)

## 1. Visão Geral e Objetivo

Esta especificação detalha a implementação do **RAG Manager** no **Olliverse**. O objetivo é prover uma interface dedicada (em formato de modal ou aba) para que o usuário possa inspecionar os arquivos indexados no banco de dados vetorial, visualizar metadados operacionais (como contagem de *chunks* gerados, data de upload e espaço ocupado) e executar a exclusão definitiva de arquivos específicos, removendo em cascata seus respectivos vetores e dados associados na tabela `document_chunks` do SQLite.

---

## 2. Requisitos de Experiência do Usuário (UX) e Comportamento

### 2.1. Ponto de Entrada e Acessibilidade

* **Acesso Global:** Adicionar um botão ou atalho visual claro no menu de gerenciamento de documentos ou no cabeçalho secundário para abrir o painel do **RAG Manager**.
* **Interface em Modal:** Abertura de uma janela modal centralizada e responsiva que sobrepõe a interface de chat, mantendo o contexto da conversa intacto ao fechar.

### 2.2. Listagem de Documentos Indexados

* **Exibição de Metadados:** Uma tabela ou lista estruturada contendo para cada arquivo:
* Nome do arquivo (ex: `.xlsx`, `.txt`, `.csv`, `.json`).
* Quantidade total de *chunks* (fragmentos) gerados e indexados.
* Data e horário do upload/indexação.
* Estimativa de espaço ocupado na base de dados.


* **Estado Vazio (Empty State):** Mensagem amigável orientando o usuário caso nenhum documento tenha sido indexado na base de dados até o momento.

### 2.3. Ação de Exclusão Definitiva

* **Confirmação de Segurança:** Botão de exclusão por item acompanhado de um diálogo de confirmação para evitar perdas acidentais de dados indexados.
* **Feedback Imediato:** Atualização dinâmica da listagem após a exclusão bem-sucedida e reflexão imediata do novo estado no contador de arquivos ativos do chat.

---

## 3. Especificação de Arquitetura e Fluxo de Dados

### 3.1. Camada de Backend (Endpoints e Repositório)

* **Endpoint de Listagem:**
* `GET /index.php?action=rag_documents_list`
* Retorna um JSON contendo a listagem agregada dos arquivos salvos na base de dados, agrupando os registros da tabela `document_chunks` por identificador/nome de arquivo.


* **Endpoint de Exclusão:**
* `POST /index.php?action=rag_document_delete`
* Recebe o identificador do arquivo a ser removido e executa a exclusão em cascata de todos os *chunks* vinculados na tabela `document_chunks` do SQLite.



### 3.2. Camada de Frontend (JavaScript / UI)

* Funções assíncronas para buscar a listagem de arquivos ao abrir o modal do RAG Manager.
* Manipulação do DOM para renderizar a tabela de documentos e tratar eventos de clique no botão de exclusão com atualização instantânea da interface.

---

## 4. Critérios de Aceite

1. **Visibilidade Completa:** O usuário consegue visualizar claramente todos os arquivos indexados no banco vetorial com seus respectivos metadados (*chunks*, data e nome).
2. **Exclusão em Cascata Confiável:** A exclusão de um arquivo pelo painel remove permanentemente todos os registros correspondentes da tabela `document_chunks` no SQLite, liberando espaço e limpando o contexto.
3. **Ergonomia e Clareza:** O painel se integra de forma fluida à identidade visual do Olliverse, oferecendo uma experiência limpa, protegida por confirmação de segurança e sem poluir o fluxo principal do chat.
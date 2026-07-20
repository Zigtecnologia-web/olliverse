Esta especificação detalha a implementação das funcionalidades de **Gestão de Histórico** e **Busca Semântica/Textual** para o Olliverse, elevando o projeto de um simples chat local para uma plataforma de inteligência e base de conhecimento.

---

# 📋 Spec.md: Olliverse Knowledge Extension

## 1. Visão Geral

Transformar o Olliverse em uma ferramenta de referência pessoal, implementando navegação de histórico e busca avançada para que nenhum aprendizado ou código gerado seja perdido.

## 2. Funcionalidade A: Sidebar de Histórico e Exportação

### 2.1 Sidebar (Navegação Visual)

* **Design:** Adicionar uma `aside` lateral esquerda (retrátil) no layout `chat.php`.
* **Agrupamento:** As conversas devem ser listadas agrupadas por período (Hoje, Ontem, Últimos 7 Dias, Anteriores).
* **Dados:** Exibir `title` (ou os primeiros 40 caracteres da primeira mensagem) e um pequeno ícone indicando o modelo utilizado.
* **Ação:** Ao clicar, o sistema carrega o `chat_id` correspondente via URL, atualizando a view sem recarregar a página inteira.

### 2.2 Exportação para Markdown

* **Endpoint:** `GET /index.php?action=export&chat_id={id}`
* **Lógica:** O backend recupera a coleção de mensagens, itera sobre elas e gera um arquivo com estrutura:
```markdown
# Conversa: [Título]
Data: [Data] | Modelo: [Modelo]

**Usuário:** [Conteúdo]
**Assistente:** [Conteúdo]
---

```


* **UX:** O navegador deve baixar o arquivo automaticamente com a extensão `.md`.

---

## 3. Funcionalidade B: Busca Full-Text (SQLite FTS5)

### 3.1 Infraestrutura de Dados

* **Tabela Virtual:** Criar a tabela `messages_fts` usando a extensão FTS5 do SQLite:
```sql
CREATE VIRTUAL TABLE messages_fts USING fts5(content, chat_id UNINDEXED);

```


* **Trigger de Manutenção:** Adicionar `TRIGGER` para garantir que, ao inserir um registro na tabela `messages`, o conteúdo seja automaticamente indexado na `messages_fts`:
```sql
CREATE TRIGGER trg_messages_ai AFTER INSERT ON messages BEGIN
  INSERT INTO messages_fts(rowid, content, chat_id) VALUES (new.id, new.content, new.chat_id);
END;

```



### 3.2 Interface de Busca

* **Input:** Um campo de busca no topo da sidebar.
* **UX:** Conforme o usuário digita (com *debounce* de 300ms), o sistema filtra a lista de chats exibidos na sidebar, destacando apenas aqueles que contêm mensagens que deram *match* no termo pesquisado.

---

## 4. Requisitos Técnicos e Arquitetura

### 4.1 Backend (PHP/SQL)

* **Repository:** Criar `SqliteSearchRepository` para lidar exclusivamente com queries `MATCH`.
* **Migrations:** Adicionar a criação da tabela virtual e triggers no `SqliteMigrator`.
* **Performance:** A busca FTS5 resolve o problema de performance do `LIKE`, permitindo buscas instantâneas mesmo com milhares de mensagens.

### 4.2 Frontend (JS Vanilla)

* **State:** A `window.OlliverseState` deve gerenciar a visibilidade da sidebar e os resultados da busca.
* **Render:** Atualizar dinamicamente a `ul` de histórico sempre que o resultado da busca retornar.

---

## 5. Exemplo de Fluxo de Execução (Busca)

1. Usuário digita "Refatoração Laravel" na barra de busca.
2. JS dispara `GET /index.php?action=search&q=Refatoração+Laravel`.
3. Backend executa: `SELECT DISTINCT chat_id FROM messages_fts WHERE content MATCH 'Refatoração NEAR Laravel'`.
4. Backend retorna os IDs dos chats.
5. Frontend oculta chats que não pertencem ao grupo de resultados e destaca o texto pesquisado.

---

### Dica de implementação para o Sênior:

Para o FTS5, recomendo usar a sintaxe `NEAR` na consulta `MATCH` (ex: `termo1 NEAR termo2`), pois isso permite encontrar resultados onde as palavras estão próximas, o que é muito mais comum em conversas técnicas do que buscas literais.

Este é um excelente passo para profissionalizar a sua arquitetura. Migrar de `$_SESSION` para **SQLite** não só resolve problemas de persistência (o chat não "morre" se a sessão expirar), mas permite que você tenha um histórico infinito, buscas, estatísticas de uso e organização por tópicos.

Aqui está a **`spec.md`** focada na arquitetura de persistência via SQLite para o seu sistema:

---

# 📋 Spec.md: Persistência de Dados e Gerenciamento de Histórico (SQLite)

## 1. Objetivo

Migrar a persistência do estado da conversação (histórico de mensagens) de `$_SESSION` para um banco de dados **SQLite**, visando escalabilidade, persistência de longo prazo e capacidade de consulta histórica.

## 2. Estrutura de Dados (Schema)

### Tabela: `chats` (O "container" da conversa)

* `id`: INTEGER PRIMARY KEY AUTOINCREMENT
* `title`: TEXT (Título gerado pela IA ou definido pelo usuário)
* `model_used`: TEXT (O modelo que foi selecionado na época)
* `created_at`: DATETIME
* `updated_at`: DATETIME

### Tabela: `messages` (O corpo da conversa)

* `id`: INTEGER PRIMARY KEY AUTOINCREMENT
* `chat_id`: INTEGER (FK -> `chats.id`)
* `role`: TEXT (system, user, assistant)
* `content`: TEXT
* `token_count`: INTEGER (Para monitoramento da janela)
* `created_at`: DATETIME

## 3. Lógica de Persistência e Recuperação

### A. Escrita (Store)

* Toda nova interação deve disparar um `INSERT` na tabela `messages`.
* Ao iniciar um novo chat, um `INSERT` na tabela `chats` é obrigatório para obter o `chat_id`.

### B. Recuperação (Sliding Window Context)

* Para carregar o contexto da conversa, a consulta deve ser:
```sql
SELECT role, content FROM messages
WHERE chat_id = :chat_id
ORDER BY id ASC;

```


* **Gestão de Memória:** O código PHP deve implementar o cálculo de `token_count` no momento da inserção. Ao atingir o limite, o sistema deve deletar mensagens antigas (`DELETE FROM messages WHERE id IN (...)`) seguindo o protocolo de Janela Deslizante.

## 4. Requisitos de Implementação (Engine)

* **Driver:** Utilizar `PDO` (PHP Data Objects) para segurança (prevenção de SQL Injection).
* **Transações:** Toda atualização de contexto (inclusão de mensagem + atualização de `updated_at` no `chat`) deve ocorrer dentro de uma transação para garantir integridade.
* **Performance:** Criar índices (INDEX) na coluna `chat_id` da tabela `messages` para que a recuperação do histórico seja instantânea, mesmo com milhares de registros.

## 5. Fluxo de Trabalho do Agente

1. O sistema identifica o `chat_id` via URL ou parâmetro (ex: `chat.php?id=123`).
2. O sistema recupera as últimas N mensagens do banco respeitando o limite de tokens.
3. O sistema injeta o `system_prompt` (padrão) + as mensagens recuperadas para o endpoint do Ollama.
4. Após o streaming da resposta, o sistema persiste o novo *assistant response* no SQLite.

## 6. Evoluções Futuras (Roadmap SQL)

* **Full-Text Search:** Ativar o módulo `FTS5` do SQLite para buscar por palavras-chave em todos os chats.
* **Arquivamento:** Adicionar coluna `is_archived` na tabela `chats`.
* **Exportação:** Criar script para exportar `chat_id` para JSON ou Markdown.

---

### Dica de Arquiteto para o seu `Agent.md`:

Adicione esta linha no seu `Agent.md`:

> *"Regra de Ouro: Nunca salve dados de estado do chat em sessão. Toda e qualquer interação deve ser processada através da camada de persistência SQLite, utilizando PDO e transações atômicas."*

**Você já tem uma estrutura de classes (como um `MessageRepository` ou `ChatService`) preparada para essa migração ou quer que o Códex estruture a classe de conexão e as queries iniciais para você?**

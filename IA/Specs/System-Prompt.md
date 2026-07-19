
# 📋 Spec.md: Sistema de Gestão de Personas (Skills)

## 1. Objetivo

Transformar o *System Prompt* de um valor estático para uma entidade de banco de dados, permitindo que o usuário selecione "Personas" dinamicamente durante a conversa, tal como faz com os modelos de IA.

## 2. Estrutura de Banco de Dados (Schema)

### Tabela: `personas`

* `id`: INTEGER PRIMARY KEY AUTOINCREMENT
* `name`: TEXT NOT NULL (Ex: "Arquiteto Sênior", "Reviewer de Segurança")
* `description`: TEXT (Resumo para a UI)
* `prompt_content`: TEXT NOT NULL (O System Prompt completo)
* `created_at`: DATETIME

### Atualização na Tabela: `chats`

* Adicionar coluna: `persona_id` (FK -> `personas.id`)
* *Nota: Isso garante que cada conversa "saiba" qual persona a originou.*

## 3. Lógica de Negócio e Comportamento

### A. Fluxo de Seleção (UI/UX)

* **Seletor Global:** Disponibilizar um *Dropdown* de Personas no header do chat.
* **Troca Dinâmica:** Ao selecionar uma nova Persona:
1. O sistema atualiza o `persona_id` do `chat` atual no banco de dados.
2. O sistema envia uma mensagem silenciosa ou sistema para o modelo (opcional) ou simplesmente passa a utilizar o novo `prompt_content` na próxima iteração da requisição.



### B. Montagem do Contexto (Backend)

Ao processar uma requisição de chat, o sistema deve seguir a ordem de montagem:

1. **System Layer:** `SELECT prompt_content FROM personas WHERE id = :persona_id`
2. **History Layer:** `SELECT role, content FROM messages WHERE chat_id = :chat_id ORDER BY id ASC`
3. **Request Construction:**
```php
$messages = [
    ["role" => "system", "content" => $personaPrompt],
    ...$historyMessages
];

```



## 4. Requisitos de Implementação

* **Validação de Segurança:** Impedir que prompts vazios sejam salvos (a aplicação deve ter um `Default Prompt` de fallback caso o banco retorne nulo).
* **Interface de Edição:** Criar uma "Biblioteca de Personas" onde o usuário possa cadastrar, editar e excluir suas próprias skills (CRUD simples).
* **Performance:** A consulta da persona deve ocorrer apenas no início da sessão ou na troca da mesma, utilizando cache em `$_SESSION` para evitar I/O desnecessário durante o streaming da resposta.

## 5. Protocolo de Resposta (Regras de UI)

* Se o usuário trocar a Persona no meio de uma conversa, a interface deve exibir um *toast* ou *badge* visual: *"Persona alterada para [Nome da Persona]"*.
* O histórico de mensagens antigo permanece intacto; apenas o novo *System Prompt* (a nova "regra" ou "chapéu") passará a guiar as próximas respostas da IA.

## 6. Roadmap de Integração (Meta-Agente)

1. **Migração:** Criar o script de migração para popular a tabela `personas` com as skills que você já possui hoje.
2. **Refatoração:** Substituir a variável estática de *System Prompt* no seu `ChatController` ou `Service` pela busca no banco de dados.
3. **UI:** Implementar o seletor no frontend que dispara a atualização do `persona_id` via AJAX.

---

### Dica do Arquiteto

Para deixar o seu sistema realmente "Power User", adicione uma coluna `is_public` na tabela `personas`. Assim, se um dia você quiser compartilhar esse projeto ou importar "presets" de prompts da comunidade, a estrutura já está pronta para isso.

**Essa spec cobre o que você imaginou para a troca dinâmica de skills? Quer que eu desenhe a classe PHP para lidar com essa busca de personas no SQLite?**
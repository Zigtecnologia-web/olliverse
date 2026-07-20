# 📋 Spec Técnica: Implementação de RAG Local (Olliverse Engine)

## 1. Definição do Pipeline de Dados

Para evitar dependências pesadas, o pipeline seguirá a estrutura:

* **Ingestão:** Leitura via `file_get_contents` ou `fopen`.
* **Chunking:** Divisão por parágrafos (mínimo 500 caracteres, overlap de 50 caracteres).
* **Embeddings:** Requisição via cURL para o endpoint `/api/embeddings` do Ollama (usando um modelo dedicado como `nomic-embed-text`).
* **Armazenamento:** Tabela `vector_store` no SQLite.
* **Escopo de busca:** Permitir consultar todos os documentos indexados ou apenas uma seleção de um ou mais documentos.

## 2. Schema SQL (Expansão do Banco)

```sql
CREATE TABLE document_chunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_name TEXT,          -- Nome do arquivo
    content TEXT,              -- O texto do chunk
    embedding BLOB,            -- Vetor binário (ou armazenado como string JSON se necessário)
    created_at DATETIME
);

```

Para suportar remoção e seleção por documento, cada arquivo indexado deve ser tratado como uma fonte lógica. A implementação pode começar usando `source_name` como identificador, mas a evolução recomendada é separar documentos e chunks:

```sql
CREATE TABLE rag_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_name TEXT NOT NULL,
    created_at DATETIME
);

CREATE TABLE document_chunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NOT NULL,
    source_name TEXT NOT NULL,
    content TEXT NOT NULL,
    embedding BLOB,
    created_at DATETIME,
    FOREIGN KEY (document_id) REFERENCES rag_documents(id) ON DELETE CASCADE
);
```

Com isso, ao deletar um documento, todos os seus chunks devem ser removidos junto.

## 3. Contrato de API (Backend PHP)

O sistema deve expor endpoints para ingestão, consulta, listagem, seleção e remoção de documentos no seu `ChatController` (ou novo `RagService`):

### A. `POST /api/rag/ingest`

* **Input:** File Upload.
* **Processo:**
1. Extrai texto.
2. Divide em chunks.
3. Itera chunks -> chama `/api/embeddings` (Ollama) -> recebe vetor.
4. Executa `INSERT` no `document_chunks`.



### B. `POST /api/chat/query` (O "RAG-Enabled Chat")

* **Input:** Pergunta do usuário + `chat_id` + flag de RAG ativo + lista opcional de documentos selecionados.
* **Processo:**
1. Transforma pergunta em vetor via `/api/embeddings`.
2. Se o usuário selecionou documentos específicos, restringe a busca aos `document_id`/`source_name` escolhidos.
3. Se nenhum documento específico foi selecionado, busca em todos os documentos indexados.
4. Busca similaridade: `SELECT id, content FROM document_chunks ORDER BY ...` (cálculo de similaridade via PHP ou extensão `sqlite-vss`).
5. Pega os 3 chunks mais relevantes.
6. Concatena chunks no prompt de sistema:
`"Você é o Olliverse. Use este contexto para responder: {chunks_content}."`
7. Prossegue com o streaming padrão.

### C. `GET /api/rag/documents`

* **Objetivo:** Listar documentos indexados para a interface.
* **Resposta esperada:**
```json
{
  "documents": [
    {
      "id": 1,
      "source_name": "manual.md",
      "chunks": 12,
      "created_at": "2026-07-19 10:00:00"
    }
  ]
}
```

### D. `DELETE /api/rag/documents/{id}` ou `POST /api/rag/delete`

* **Objetivo:** Remover um documento indexado.
* **Processo:**
1. Valida se o documento existe.
2. Remove o registro do documento.
3. Remove todos os chunks vinculados.
4. Retorna a lista atualizada de documentos.

Em uma implementação sem roteador REST, o endpoint pode ser adaptado para:

```text
POST ?action=rag_delete
document_id=1
```

### E. Seleção de documentos no chat

O envio da pergunta deve aceitar uma lista opcional de documentos:

```text
rag_enabled=1
rag_document_ids[]=1
rag_document_ids[]=3
```

Regras:

1. `rag_enabled=0`: não usa documentos.
2. `rag_enabled=1` e lista vazia: usa todos os documentos indexados.
3. `rag_enabled=1` e lista preenchida: usa somente os documentos selecionados.
4. Se um documento selecionado foi deletado, ele deve ser ignorado ou gerar erro amigável.



## 4. Estratégia de Ranking

Para manter a performance no PHP:

* **Similaridade de Cosseno:** Implementar uma função helper em PHP que calcula o produto escalar de dois vetores normalizados (o `nomic-embed-text` retorna vetores normalizados, facilitando o cálculo).

## 5. UI/UX: O Fluxo de Trabalho

1. **Painel de Documentos:** Novo painel com lista de documentos indexados.
2. **Toggle "Usar documentos":** Se ligado, o `ChatService` injeta a busca RAG antes de enviar ao Ollama.
3. **Seleção de documentos:** Cada documento deve ter checkbox ou seleção múltipla para permitir:
   * usar todos os documentos;
   * usar somente um documento;
   * usar dois ou mais documentos específicos ao mesmo tempo.
4. **Excluir documento:** Cada documento listado deve ter uma ação de exclusão, com confirmação antes de remover.
5. **Indicação de Fonte:** O backend deve retornar uma chave `metadata` no JSON de resposta, permitindo que o frontend exiba: *"Baseado em: [nome_do_arquivo]"*.
6. **Estado vazio:** Quando não houver documentos indexados, o toggle deve deixar claro que ainda é necessário indexar um documento.

## 6. Regras de Comportamento

1. A exclusão de um documento deve remover seus chunks e impedir que ele apareça em respostas futuras.
2. A seleção de documentos deve afetar somente a próxima pergunta ou o estado atual da UI, sem apagar documentos não selecionados.
3. O usuário deve conseguir alternar rapidamente entre:
   * RAG desligado;
   * RAG ligado com todos os documentos;
   * RAG ligado com um subconjunto de documentos.
4. O backend não deve confiar apenas na UI: documentos recebidos na seleção precisam ser validados antes da busca.
5. O metadata retornado deve listar apenas fontes realmente usadas na resposta, não todos os documentos selecionados.

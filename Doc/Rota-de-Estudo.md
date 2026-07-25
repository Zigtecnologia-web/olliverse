# Rota de Estudo do Olliverse

Este roteiro organiza os principais assuntos implementados no Olliverse por trilhas de estudo. A ideia e estudar primeiro o problema que cada parte resolve, depois localizar onde ela aparece no projeto e, por fim, aprofundar com referencias externas.

## Como usar esta rota

1. Leia a trilha na ordem sugerida.
2. Abra os arquivos indicados e acompanhe o fluxo pelo codigo.
3. Compare a implementacao local com as referencias externas.
4. Volte ao `Doc/README.md` quando precisar enxergar o sistema completo.

## Trilha 1 - Fundamentos da arquitetura local

### O que estudar

- PHP puro como backend.
- `index.php` como front controller.
- `bootstrap/app.php` como ponto de montagem das dependencias.
- Separacao entre `app/Http`, `app/Services`, `app/Repositories`, `views` e `public/assets`.

### Qual problema resolve

Resolve o problema de manter um chat local simples de rodar, mas organizado o suficiente para crescer. O projeto comecou pequeno, porem hoje precisa lidar com historico, RAG, personas, exportacao, plugins, Web AI e analise de dados. Separar responsabilidades evita que tudo fique preso em um unico arquivo.

### Por que deve ser estudado

Essa trilha ajuda a entender onde cada decisao deve morar. Antes de mexer em RAG, contexto ou banco, e importante saber quais partes do sistema recebem requisicoes, quais montam regras de negocio e quais apenas renderizam interface.

### Onde olhar no projeto

- `index.php`
- `bootstrap/app.php`
- `views/chat.php`
- `app/Http/ChatStreamHandler.php`
- `Doc/README.md`

### Referencias

- PHP: https://www.php.net/manual/pt_BR/
- Composer autoload: https://getcomposer.org/doc/01-basic-usage.md#autoloading
- Front Controller Pattern: https://martinfowler.com/eaaCatalog/frontController.html

## Trilha 2 - Persistencia SQLite e memoria persistente do chat

### O que estudar

- SQLite via PDO.
- Tabelas `chats` e `messages`.
- `chat_id` como identificador da conversa.
- Transacoes ao salvar mensagens.
- Historico persistente fora de `$_SESSION`.
- Busca textual com SQLite FTS5.

### Qual problema resolve

Resolve o problema do chat esquecer tudo quando a sessao PHP expira ou quando o navegador muda de estado. A conversa deixa de ser memoria temporaria da sessao e passa a ser dado persistente, consultavel e exportavel.

### Por que deve ser estudado

Sem essa base, nao existe historico confiavel, busca, exportacao, titulo automatico, workspaces ou continuidade de contexto. A memoria persistente do Olliverse nasce no SQLite, nao no modelo de IA.

### Onde olhar no projeto

- `app/Database/SqliteConnection.php`
- `app/Database/SqliteMigrator.php`
- `app/Repositories/SqliteConversationRepository.php`
- `app/Repositories/SqliteChatHistoryRepository.php`
- `app/Repositories/SqliteSearchRepository.php`
- `IA/Specs/Persistencia-Sqlite.md`

### Referencias

- SQLite: https://www.sqlite.org/docs.html
- SQLite FTS5: https://www.sqlite.org/fts5.html
- PHP PDO: https://www.php.net/manual/pt_BR/book.pdo.php
- Transacoes em PDO: https://www.php.net/manual/pt_BR/pdo.transactions.php

## Trilha 3 - Personas, system prompt e manutencao de contexto

### O que estudar

- Diferenca entre mensagem de usuario, resposta do assistente e system prompt.
- Personas como prompts reutilizaveis.
- Como o prompt ativo e recuperado e reinjetado no contexto.
- Por que mensagens `system` nao sao persistidas como mensagens comuns do chat.

### Qual problema resolve

Resolve o problema de manter comportamento consistente da IA sem misturar configuracao com historico da conversa. O usuario pode trocar ou salvar uma persona, mas o historico continua separado.

### Por que deve ser estudado

O modelo nao tem memoria real entre chamadas. Cada resposta depende do contexto enviado naquele momento. Entender system prompt e persona mostra como o Olliverse cria uma "continuidade" controlada.

### Onde olhar no projeto

- `app/Repositories/SqlitePersonaRepository.php`
- `app/Repositories/SqliteConversationRepository.php`
- `app/Services/PromptGeneratorService.php`
- `IA/Specs/System-Prompt.md`
- `IA/Specs/Persona-Base.md`
- `IA/Specs/Personas-Skils.md`

### Referencias

- Ollama Chat API: https://docs.ollama.com/api/chat
- OpenAI prompt engineering guide: https://platform.openai.com/docs/guides/prompt-engineering

## Trilha 4 - Janela de contexto e sliding window

### O que estudar

- Limite de contexto dos modelos.
- Estimativa de tokens.
- Remocao de mensagens antigas quando o contexto passa do limite.
- Preservacao do system prompt.
- Reset automatico quando o Ollama retorna erro de contexto.

### Qual problema resolve

Resolve o problema de enviar conversa grande demais para o modelo. Quando o historico cresce, o modelo pode falhar por estouro da janela de contexto. A sliding window mantem a conversa utilizavel, cortando o historico antigo quando necessario.

### Por que deve ser estudado

Esse e um dos pontos centrais de qualquer app de chat com LLM. A IA so responde sobre aquilo que recebeu na chamada atual. Se o contexto for grande demais, quebra; se for pequeno demais, perde continuidade.

### Onde olhar no projeto

- `app/Services/ContextWindowService.php`
- `app/Http/ChatStreamHandler.php`
- `app/Services/ModelMetadataService.php`
- `public/assets/js/message-ui.js`
- `IA/Specs/Context-Dinamico-Modelo.md`

### Referencias

- Ollama Chat API: https://docs.ollama.com/api/chat
- Ollama API de metadados do modelo: https://docs.ollama.com/api-reference/show-model-details
- Visao geral sobre tokens da OpenAI: https://platform.openai.com/tokenizer

## Trilha 5 - UX de streaming e scroll inteligente

### O que estudar

- Streaming NDJSON.
- Renderizacao progressiva da resposta.
- `shouldScrollToBottom()`.
- Diferenca entre usuario lendo historico e usuario acompanhando a resposta em tempo real.

### Qual problema resolve

Resolve o problema de uma resposta longa travar a experiencia ou puxar a tela para baixo enquanto o usuario esta lendo mensagens antigas. O chat precisa parecer vivo durante a geracao, mas sem brigar com a leitura do usuario.

### Por que deve ser estudado

Apps de IA nao sao apenas backend. A sensacao de fluidez vem do streaming, do scroll e da renderizacao incremental. Essa trilha ajuda a entender a ponte entre resposta do servidor e experiencia no navegador.

### Onde olhar no projeto

- `app/Http/NdjsonResponse.php`
- `app/Http/ChatStreamHandler.php`
- `public/assets/js/chat-stream.js`
- `public/assets/js/message-ui.js`
- `IA/Specs/Scroll-Inteligente.md`

### Referencias

- MDN Streams API: https://developer.mozilla.org/en-US/docs/Web/API/Streams_API
- NDJSON: https://github.com/ndjson/ndjson-spec
- Fetch API: https://developer.mozilla.org/en-US/docs/Web/API/Fetch_API

## Trilha 6 - RAG local

### O que estudar

- Retrieval-Augmented Generation.
- Ingestao de documentos.
- Quebra em chunks.
- Geracao de embeddings.
- Busca por similaridade.
- Injecao de trechos relevantes no system prompt.
- Selecionar documentos para definir o escopo do RAG.

### Qual problema resolve

Resolve o problema do modelo responder sem conhecer documentos locais. Em vez de treinar o modelo novamente, o Olliverse recupera trechos relevantes dos arquivos preparados e envia esses trechos como contexto adicional.

### Por que deve ser estudado

RAG e uma das tecnicas mais importantes para construir sistemas de IA com dados proprios. Ela reduz respostas inventadas e permite que a IA trabalhe com arquivos que nao fizeram parte do treinamento original do modelo.

### Onde olhar no projeto

- `app/Services/RagIngestionService.php`
- `app/Services/RagRetrievalService.php`
- `app/Services/RagChunkerService.php`
- `app/Services/VectorSimilarityService.php`
- `app/Repositories/SqliteDocumentChunkRepository.php`
- `public/assets/js/rag-panel.js`
- `IA/Specs/Rag.md`
- `IA/Specs/Gerenciar-Documentos-Indexados.md`

### Referencias

- AWS - Understanding Retrieval Augmented Generation: https://docs.aws.amazon.com/prescriptive-guidance/latest/retrieval-augmented-generation-options/what-is-rag.html
- Google Cloud - RAG reference architectures: https://docs.cloud.google.com/architecture/rag-reference-architectures
- RAG Handbook: https://unrag.dev/docs/rag

## Trilha 7 - Embeddings e similaridade vetorial

### O que estudar

- O que e embedding.
- Diferenca entre modelo de chat e modelo de embedding.
- Endpoint de embeddings do Ollama.
- Armazenamento do vetor em `embedding_json`.
- Similaridade de cosseno.
- Por que chunks grandes demais quebram o modelo de embedding.

### Qual problema resolve

Resolve o problema de comparar textos por significado, nao apenas por palavra exata. A pergunta do usuario vira vetor; os chunks dos documentos tambem viram vetores; o sistema procura os vetores mais parecidos.

### Por que deve ser estudado

Sem embeddings, o RAG local vira uma busca textual simples. Com embeddings, o sistema consegue encontrar trechos semanticamente proximos mesmo quando a pergunta usa palavras diferentes do documento.

### Onde olhar no projeto

- `app/Services/OllamaClient.php`
- `app/Services/RagIngestionService.php`
- `app/Services/RagRetrievalService.php`
- `app/Services/VectorSimilarityService.php`
- `app/Repositories/SqliteDocumentChunkRepository.php`

### Referencias

- Ollama Embed API: https://docs.ollama.com/api/embed
- Ollama modelos de embedding: https://ollama.com/search?c=embedding
- Cosine similarity: https://en.wikipedia.org/wiki/Cosine_similarity

## Trilha 8 - DuckDB e analise de dados tabulares

### O que estudar

- DuckDB como banco analitico local.
- Diferenca entre SQLite da aplicacao e DuckDB analitico.
- Datasets estruturados derivados de CSV, JSON e planilhas.
- Criacao de tabelas por workspace.
- Execucao segura apenas de `SELECT`.
- Fallback para SQLite quando `pdo_duckdb` nao existe.

### Qual problema resolve

Resolve o problema de pedir para o LLM fazer conta, agrupamento, ranking ou grafico olhando texto bruto. O modelo pode errar calculos. DuckDB executa SQL sobre dados tabulares e entrega resultado estruturado para a IA explicar.

### Por que deve ser estudado

RAG e otimo para texto, mas nao e o melhor caminho para analise numerica. DuckDB permite separar raciocinio linguistico de calculo tabular, deixando o sistema mais confiavel para dados.

### Onde olhar no projeto

- `app/Services/WorkspaceAnalyticsService.php`
- `app/Services/StructuredDataParser.php`
- `plugins/data_analyst/includes/inspect_prompt.php`
- `plugins/data_analyst/includes/insights_view.php`
- `IA/Specs/DuckDb.md`
- `IA/Specs/Sugestao-Analitica.md`

### Referencias

- DuckDB Docs: https://duckdb.org/docs/current/
- DuckDB SQL introduction: https://duckdb.org/docs/current/sql/introduction
- DuckDB PHP client: https://duckdb.org/docs/current/clients/tertiary_clients/php
- DuckDB CSV import: https://duckdb.org/docs/current/data/csv/overview

## Trilha 9 - RAG e DuckDB trabalhando juntos

### O que estudar

- Quando usar recuperacao semantica.
- Quando usar SQL analitico.
- Como documentos selecionados ativam os dois caminhos.
- Como o resultado do DuckDB entra no contexto final junto com trechos do RAG.

### Qual problema resolve

Resolve o problema de documentos mistos: arquivos que tem texto explicativo e tambem tabela. O RAG encontra contexto textual; o DuckDB calcula respostas estruturadas; o LLM escreve a resposta final com os dois apoios.

### Por que deve ser estudado

Essa trilha mostra a parte mais interessante da arquitetura: a IA nao precisa fazer tudo. Ela pode orquestrar ferramentas locais mais confiaveis e depois explicar o resultado em linguagem natural.

### Onde olhar no projeto

- `app/Http/ChatStreamHandler.php`
- `app/Services/RagRetrievalService.php`
- `app/Services/WorkspaceAnalyticsService.php`
- `Doc/README.md`, secao sobre RAG e DuckDB

### Referencias

- DuckDB Docs: https://duckdb.org/docs/current/
- AWS - RAG: https://docs.aws.amazon.com/prescriptive-guidance/latest/retrieval-augmented-generation-options/what-is-rag.html

## Trilha 10 - Web AI no navegador

### O que estudar

- WebLLM.
- WebGPU.
- Modelo rodando no navegador.
- Persistencia posterior da conversa no SQLite.
- Contexto menor no frontend.
- `localStorage` para preferencia de provedor.

### Qual problema resolve

Resolve o problema de experimentar um provedor de IA local no proprio navegador, reduzindo dependencia direta do backend para geracao. Mesmo assim, o projeto mantem o SQLite como fonte duravel do historico.

### Por que deve ser estudado

Essa trilha mostra uma arquitetura hibrida: o backend continua responsavel por persistencia e RAG, mas a geracao pode acontecer no cliente quando o navegador suporta WebGPU.

### Onde olhar no projeto

- `public/assets/js/web-ai-provider.js`
- `public/assets/js/app.js`
- `views/chat.php`
- `IA/Specs/Web-AI.md`

### Referencias

- WebLLM docs: https://webllm.mlc.ai/docs/user/get_started.html
- WebLLM API reference: https://webllm.mlc.ai/docs/user/api_reference.html
- MDN WebGPU API: https://developer.mozilla.org/en-US/docs/Web/API/WebGPU_API
- MDN Web Storage API: https://developer.mozilla.org/en-US/docs/Web/API/Web_Storage_API

## Trilha 11 - Workspaces, historico inteligente e organizacao

### O que estudar

- Workspaces como separacao de contexto.
- Sidebar de historico.
- Busca instantanea no historico.
- Geracao automatica de titulos.
- Agrupamento por data.

### Qual problema resolve

Resolve o problema de muitas conversas e documentos ficarem misturados. Workspaces e historico tornam o app mais parecido com uma ferramenta de trabalho continua, nao apenas uma tela de pergunta e resposta.

### Por que deve ser estudado

IA local fica muito mais util quando a organizacao acompanha o volume de uso. Essa trilha explica como o Olliverse transforma conversas soltas em acervo pesquisavel.

### Onde olhar no projeto

- `app/Repositories/SqliteWorkspaceRepository.php`
- `app/Repositories/SqliteChatHistoryRepository.php`
- `app/Repositories/SqliteSearchRepository.php`
- `public/assets/js/history-panel.js`
- `IA/Specs/Historico-Inteligente.md`
- `IA/Specs/WorkSpcace.md`

### Referencias

- SQLite FTS5: https://www.sqlite.org/fts5.html
- MDN URL API: https://developer.mozilla.org/en-US/docs/Web/API/URL

## Trilha 12 - Plugin analitico, graficos e exportacao

### O que estudar

- Como plugins injetam prompts.
- Como o plugin de dados interpreta respostas.
- Chart.js.
- Exportacao Markdown e PDF.
- Captura de graficos para PDF.

### Qual problema resolve

Resolve o problema de transformar respostas analiticas em visualizacao e relatorio. O chat deixa de ser apenas texto e passa a produzir graficos, tabelas e documentos exportaveis.

### Por que deve ser estudado

Essa trilha mostra como extender o Olliverse sem reescrever o fluxo principal. Plugins, renderizacao e exportacao sao bons exemplos de arquitetura incremental.

### Onde olhar no projeto

- `app/Services/PluginManager.php`
- `plugins/data_analyst/`
- `public/assets/js/plugin-panel.js`
- `plugins/data_analyst/assets/script.js`
- `app/Services/PdfExportService.php`
- `IA/Specs/Plugin-Chart.md`
- `IA/Specs/Download-PDF.md`

### Referencias

- Chart.js docs: https://www.chartjs.org/docs/latest/
- Dompdf: https://github.com/dompdf/dompdf
- Marked: https://marked.js.org/
- DOMPurify: https://github.com/cure53/DOMPurify

## Ordem recomendada

1. Fundamentos da arquitetura local.
2. Persistencia SQLite e memoria persistente do chat.
3. Personas, system prompt e manutencao de contexto.
4. Janela de contexto e sliding window.
5. UX de streaming e scroll inteligente.
6. RAG local.
7. Embeddings e similaridade vetorial.
8. DuckDB e analise de dados tabulares.
9. RAG e DuckDB trabalhando juntos.
10. Web AI no navegador.
11. Workspaces, historico inteligente e organizacao.
12. Plugin analitico, graficos e exportacao.

## Resumo mental

- SQLite guarda estado, historico, personas, documentos, chunks e metadados.
- Context window decide o que cabe na proxima chamada ao modelo.
- RAG encontra trechos textuais relevantes.
- Embeddings permitem comparar significado.
- DuckDB calcula dados tabulares com SQL.
- Web AI executa geracao no navegador quando possivel.
- Streaming e scroll inteligente tornam a experiencia fluida.
- Plugins e exportacao ampliam o app sem desmontar o nucleo.

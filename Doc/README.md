# Olliverse - Documentacao funcional e tecnica

- **Criado por:** Valdiney França
- **Data de criacao:** 19 de julho de 2026
- **Ultima atualizacao:** 23 de julho de 2026

## 1. Visao geral

O **Olliverse** e uma ferramenta de chat local para interagir com modelos de IA executados pelo **Ollama**. A aplicacao foi construida em **PHP puro**, com **JavaScript vanilla**, **CSS proprio** e persistencia em **SQLite**.

O objetivo atual do projeto e oferecer uma interface simples, elegante e local para conversar com LLMs, escolhendo modelos instalados na maquina, gerenciando personas de comportamento, mantendo historico persistente das conversas e permitindo alternar o motor entre Ollama e uma Web AI experimental no navegador.

Em termos praticos, a ferramenta funciona como um cliente web local para Ollama, mas com algumas preocupacoes ja bem definidas:

- manter o `index.php` como ponto inicial da aplicacao;
- separar responsabilidades entre frontend, backend, servicos, repositorios e banco;
- persistir conversas e mensagens fora da sessao PHP;
- permitir troca e edicao de personas, que funcionam como system prompts reutilizaveis;
- controlar o tamanho do contexto enviado ao modelo;
- entregar respostas em streaming para a interface;
- renderizar respostas em Markdown com suporte a blocos de codigo;
- alternar entre o provedor Ollama e o provedor Web AI no navegador.
- disponibilizar uma Central de Documentacao dentro da propria aplicacao.

Para estudar o projeto por assunto, consulte tambem a [Rota de Estudo do Olliverse](Rota-de-Estudo.md), que separa arquitetura, SQLite, contexto, RAG, embeddings, DuckDB, Web AI e plugins por trilhas.

## 2. Tipo de ferramenta que esta sendo construida

O projeto esta se consolidando como um **ambiente local de trabalho com IA**.

Ele nao e apenas um chat simples. A arquitetura atual aponta para uma ferramenta pessoal para:

- conversar com modelos locais;
- alternar modelos conforme disponibilidade no Ollama;
- criar "modos de trabalho" por persona;
- manter historico em SQLite;
- usar a IA para tarefas tecnicas, escrita, analise e refatoracao;
- buscar conversas salvas por titulo ou conteudo;
- usar RAG, exportacao e organizacao avancada de chats.

A ferramenta tem perfil de **cliente local privado**, com baixa dependencia externa. A unica dependencia operacional forte e o Ollama rodando localmente ou em uma URL configurada.

## 3. Stack atual

### Backend

- PHP `>= 8.2`.
- PHP puro, sem framework.
- Autoload Composer carregado em `bootstrap/app.php`, com fallback PSR-4 simples para classes `App\`.
- cURL para comunicacao com Ollama.
- PDO para SQLite.
- Classes com `declare(strict_types=1)`.
- Dependencias Composer:
  - `dompdf/dompdf` para gerar PDF;
  - `erusev/parsedown` para converter Markdown em HTML no template de PDF.

### Frontend

- HTML renderizado por PHP em `views/chat.php`.
- JavaScript vanilla modularizado em arquivos separados.
- CSS proprio em `public/assets/css/app.css`.
- Bibliotecas de frontend vendorizadas em `public/vendor/`:
  - `marked` para Markdown;
  - `DOMPurify` para sanitizacao de HTML;
  - `highlight.js` para destaque de codigo;
  - `xlsx` para leitura de planilhas;
  - `Chart.js` para graficos do plugin analitico;
  - bundle local do `@mlc-ai/web-llm` para carregar o provedor Web AI.

### Banco de dados

- SQLite.
- Caminho padrao: `storage/database.sqlite`.
- O banco e criado automaticamente se nao existir.
- As migracoes tambem rodam automaticamente durante o bootstrap.

### IA

- Ollama.
- Endpoint principal usado: `/api/chat`.
- Endpoint de geracao rapida de texto usado: `/api/generate`.
- Endpoint de listagem de modelos: `/api/tags`.
- Endpoint de metadados de modelo: `/api/show`.
- Web AI experimental no navegador via WebGPU/WebLLM carregado sob demanda a partir do bundle local.

## 4. Estrutura de pastas

```text
.
├── app
│   ├── Config
│   ├── Contracts
│   ├── Database
│   ├── Http
│   ├── Repositories
│   ├── Services
│   └── Support
├── bootstrap
├── Doc
├── IA
│   ├── Agents
│   └── Specs
├── public
│   └── assets
│       ├── css
│       └── js
├── storage
├── views
├── composer.json
└── index.php
```

## 5. Ponto de entrada da aplicacao

O arquivo `index.php` continua sendo o ponto principal.

Ele e responsavel por:

- carregar o bootstrap;
- obter configuracoes e servicos;
- listar os modelos disponiveis no Ollama;
- escolher o modelo padrao;
- criar ou recuperar o chat atual;
- processar acoes GET e POST;
- montar dados iniciais para a tela;
- incluir `views/chat.php`.

Apesar de ser o ponto central de entrada, a logica pesada ja foi movida para classes em `app/`.

## 6. Bootstrap e inicializacao

O arquivo `bootstrap/app.php` prepara a aplicacao.

Ele faz:

- registro do autoload para classes `App\`;
- leitura das configuracoes via `AppConfig::fromEnvironment()`;
- inicio da sessao PHP quando necessario;
- criacao do cliente Ollama;
- criacao do servico de janela de contexto;
- conexao com SQLite;
- execucao das migracoes;
- criacao do servico de metadados de modelos.
- criacao do servico gerador de prompts de personas.

O retorno do bootstrap e um array simples de dependencias:

```php
[
    'config' => $config,
    'ollama_client' => $ollamaClient,
    'context_window' => $contextWindowService,
    'pdo' => $pdo,
    'model_metadata_service' => $modelMetadataService,
    'prompt_generator_service' => $promptGeneratorService,
]
```

## 7. Configuracoes

As configuracoes ficam em `App\Config\AppConfig`.

Valores suportados por ambiente:

| Variavel | Padrao | Finalidade |
|---|---:|---|
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL base do Ollama |
| `DEFAULT_SYSTEM_PROMPT` | Prompt da persona `Assistente Geral` | Prompt padrao |
| `CONTEXT_TOKEN_LIMIT` | `8192` | Fallback estimado da janela de contexto quando o Ollama nao informa o limite do modelo |
| `OLLAMA_CONNECT_TIMEOUT` | `10` | Timeout de conexao com Ollama |
| `OLLAMA_RESPONSE_TIMEOUT` | `180` | Timeout maximo da resposta |
| `MODEL_METADATA_CACHE_TTL` | `3600` | Tempo de cache dos metadados do modelo |
| `SQLITE_DATABASE_PATH` | `storage/database.sqlite` | Caminho do banco SQLite |
| `RAG_EMBEDDING_MODEL` | `nomic-embed-text` | Modelo usado para gerar embeddings dos documentos |

Tambem existem modelos preferenciais definidos no codigo:

```php
['llama3.2:latest', 'llama3.2', 'qwen2.5:0.5b']
```

Esses nomes sao usados apenas para escolher o modelo padrao quando eles existem na lista retornada pelo Ollama.

## 8. Funcionalidades atuais

### 8.1 Chat com IA local

A funcionalidade central e enviar uma mensagem para um motor de IA e receber uma resposta. O motor padrao continua sendo o Ollama. O seletor **Motor** tambem permite escolher **Web AI**, que tenta executar um modelo leve diretamente no navegador com WebGPU.

Fluxo geral:

1. O usuario digita uma mensagem.
2. O frontend adiciona a mensagem do usuario imediatamente na tela.
3. O frontend envia `prompt` e `model` via `POST`.
4. O backend monta o contexto com system prompt/persona e historico.
5. O backend chama o Ollama em modo streaming.
6. A resposta chega em partes para o navegador.
7. O frontend renderiza progressivamente a resposta.
8. Ao final, a conversa e persistida no SQLite.

Quando o motor selecionado e **Web AI**:

1. O frontend monta o contexto com system prompt/persona e mensagens ja carregadas.
2. Se houver documentos marcados no menu de documentos, o frontend chama `POST ?action=rag_context` para recuperar trechos relevantes pelo backend.
3. Se houver plugins ativos, o frontend tambem inclui `activePluginPrompts`, como o prompt do plugin de graficos.
4. O navegador carrega o motor WebLLM sob demanda.
5. O modelo configurado em `window.OlliverseConfig.webAi.modelId` responde em streaming na propria aba.
6. Ao final, o frontend chama `POST ?action=web_ai_persist` para salvar pergunta e resposta no SQLite.
7. O chat fica marcado com `model_used` no formato `web_ai:<modelo>`.

Como os modelos Web AI rodam em uma janela menor que muitos modelos do Ollama, o frontend usa `window.OlliverseConfig.webAi.contextTokenLimit` para aparar mensagens antigas e encurtar contexto local quando necessario. A pergunta atual e preservada.

Durante o primeiro uso, o navegador pode baixar arquivos grandes do modelo. A interface mostra um status proprio da Web AI com mensagens legiveis, como preparo ou download em andamento, e bloqueia temporariamente o campo de mensagem ate o modelo ficar pronto ou falhar.

Esse modo depende de WebGPU habilitado no navegador. Se o navegador nao oferecer WebGPU, a interface mostra a falha e o Ollama segue disponivel como provedor principal.

O import do driver WebLLM fica local em `public/vendor/web-llm/web-llm.js`. Os pesos dos modelos WebLLM continuam sendo baixados pelo proprio WebLLM quando o provedor Web AI e usado; eles nao fazem parte dos assets leves da interface.

### 8.2 Streaming de respostas

A resposta da IA e transmitida em **NDJSON**.

Cada linha enviada pelo backend e um JSON independente. Os tipos atuais sao:

```json
{"type":"chunk","content":"parte da resposta"}
{"type":"meta","context_usage":{"tokens":100,"limit":8000,"percentage":1},"context_trimmed":false}
{"type":"error","message":"Mensagem de erro","context_reset":false}
```

O streaming evita que o usuario precise esperar a resposta inteira ficar pronta.

Ao concluir uma resposta do modelo, a aplicacao registra a duracao total da entrega e mostra abaixo da mensagem algo como `Resposta entregue em 4,2s`.

No frontend, `chat-stream.js` usa:

- `response.body.getReader()`;
- `TextDecoder`;
- buffer por linha;
- `JSON.parse` por linha NDJSON.

Se o navegador nao suportar streaming via `response.body`, o codigo tenta processar o texto completo ao final.

### 8.3 Persistencia de conversas

As conversas sao persistidas no SQLite.

Tabelas principais:

- `workspaces`;
- `chats`;
- `messages`;
- `personas`.

As respostas do assistente podem armazenar `messages.response_duration_ms`, usado para manter visivel no historico quanto tempo a resposta levou para ser entregue por inteiro.

A conversa atual e identificada por `chat_id` na URL e tambem fica vinculada ao workspace ativo.

Exemplo:

```text
/index.php?chat_id=1
```

Se o `chat_id` nao existir no workspace ativo, a aplicacao cria automaticamente uma nova conversa e redireciona para ela.

### 8.3.1 Workspaces

A barra lateral possui um seletor de workspace no topo. Cada workspace tem nome, icone curto e seu proprio conjunto de conversas e documentos preparados para RAG.

Fluxo geral:

1. a aplicacao garante um workspace padrao `Geral` durante a migracao;
2. conversas antigas sem `workspace_id` sao associadas ao workspace padrao;
3. documentos RAG antigos tambem sao associados ao workspace padrao;
4. ao trocar de workspace, o frontend recebe novo chat ativo, historico filtrado, documentos daquele workspace, persona ativa e uso de contexto;
5. se o workspace escolhido ainda nao tiver conversas, o backend cria uma conversa inicial para ele.

### 8.3.2 Historico inteligente

A barra lateral de historico possui busca instantanea. O frontend chama `GET ?action=search_history&q=...` enquanto o usuario digita e renderiza as conversas retornadas, sem recarregar a pagina.

A busca considera correspondencias parciais em:

- `chats.title`;
- `messages.content`.

Quando uma conversa nova recebe a primeira ou segunda interacao, o frontend chama `POST ?action=update_chat_title` em segundo plano. Se o titulo ainda for generico, o backend usa `/api/generate` do Ollama para criar um titulo curto, normaliza a resposta para 3 a 5 palavras e persiste o valor em `chats.title`. A sidebar e atualizada em seguida sem interromper o chat.

### 8.4 Nova conversa

O botao **Nova conversa** chama a URL com `new=1`.

Exemplo:

```text
/index.php?new=1&chat_id=1
```

O backend cria um novo registro em `chats`, reaproveitando a persona ativa do chat anterior quando houver um `chat_id` valido.

Tambem existe compatibilidade com:

```text
?clear=1
```

No estado atual, `clear=1` cria uma nova conversa, em vez de apenas apagar as mensagens do chat atual.

### 8.5 Selecao de modelo

A aplicacao lista modelos disponiveis no Ollama usando `/api/tags`.

O seletor de modelo:

- mostra os modelos instalados localmente;
- deixa o campo desabilitado quando nenhum modelo e encontrado;
- mantem o modelo escolhido em um input hidden;
- envia o modelo escolhido junto com cada prompt;
- valida no backend se o modelo selecionado existe na lista retornada pelo Ollama.

Selecao do modelo padrao:

1. tenta encontrar o primeiro modelo da lista de preferencias;
2. se nao encontrar, usa o primeiro modelo retornado pelo Ollama;
3. se nao houver nenhum modelo, usa fallback textual `llama3.2:latest`.

### 8.6 Metadados do modelo

O botao de informacoes do modelo abre um modal com:

- tamanho;
- familia;
- contexto;
- quantizacao.

O frontend chama:

```text
GET ?action=model_metadata&model=nome-do-modelo
```

O backend:

- valida se o modelo existe;
- chama `ModelMetadataService`;
- consulta o Ollama via `POST /api/show`;
- extrai `context_length` de `model_info` ou `num_ctx` de `parameters`/`modelfile`;
- devolve `CONTEXT_TOKEN_LIMIT` como fallback quando o teto real nao vier na resposta;
- usa `/api/tags` como fallback para tamanho;
- guarda o resultado em cache na sessao por `MODEL_METADATA_CACHE_TTL`.

### 8.7 Personas

Personas sao perfis de comportamento da IA. Na pratica, cada persona carrega um `prompt_content` usado como system prompt da conversa.

A UI possui:

- seletor rapido de persona no header;
- botao de configuracao;
- modal **Biblioteca de Personas**;
- campos de nome, descricao e system prompt;
- placeholders nos campos de nome, descricao e system prompt para orientar o preenchimento;
- botao **Gerar** ao lado do rotulo de system prompt;
- acoes para criar, editar e excluir personas;
- tooltips nos botoes de informacoes do modelo, biblioteca de personas e geracao de prompt;
- toast visual indicando troca de persona.

O botao **Gerar** usa IA para transformar `Nome` e `Descricao` em um system prompt estruturado em YAML.

Regras desse fluxo:

- o botao fica desabilitado por padrao;
- o botao so e habilitado quando `Nome` e `Descricao` contem texto;
- durante a requisicao, o texto muda para `Gerando...` e o botao fica bloqueado;
- o backend valida novamente nome, descricao e modelo;
- a resposta gerada e injetada no campo `System prompt`;
- a persona so e persistida quando o usuario clica em **Salvar Persona**.

O tooltip do botao **Gerar** reutiliza o tooltip flutuante de `message-ui.js`, renderizado no `document.body`, para nao ficar preso atras da modal.

Persona Base semeada automaticamente:

- `Assistente Geral`.

A Persona Base sempre fica disponivel, nao pode ser excluida e substitui as antigas personas publicas semeadas pelo sistema. Personas criadas pelo usuario continuam podendo existir ao lado dela.

### 8.8 Troca de persona durante a conversa

Quando o usuario troca a persona:

1. o frontend envia `persona_action=select`;
2. o backend atualiza `persona_id` e `system_prompt` do chat atual;
3. a persona ativa passa a orientar as proximas respostas;
4. o historico antigo permanece intacto;
5. a interface mostra um toast confirmando a alteracao.

Importante: a troca de persona nao reprocessa mensagens antigas. Ela altera o system prompt usado nas proximas interacoes.

### 8.9 CRUD de personas

A biblioteca de personas suporta:

- criar nova persona;
- editar persona existente;
- excluir persona;
- selecionar persona para o chat atual.

Validacoes atuais:

- nome nao pode ficar vazio;
- prompt da persona nao pode ficar vazio;
- persona inexistente gera erro;
- a persona fallback/padrao nao pode ser excluida no backend;
- nomes de personas nao podem ser duplicados, comparando maiusculas, minusculas, acentos e espacos nas extremidades.

Ao editar uma persona que esta associada a chats, o sistema atualiza o `system_prompt` dos chats vinculados.

### 8.10 Cache de persona ativa

O repositorio de personas usa `$_SESSION['olliverse_persona_cache']` como cache leve por chat.

Esse cache evita consultar a persona ativa repetidamente no banco durante o ciclo da aplicacao.

A fonte definitiva, entretanto, continua sendo o SQLite. A sessao e usada apenas como cache auxiliar.

### 8.11 Janela de contexto

O servico `ContextWindowService` controla o tamanho estimado do contexto.

A estimativa atual e simples:

```text
tokens estimados = caracteres / 4
```

O limite padrao e `8000`.

A janela de contexto enviada ao Ollama e montada assim:

1. mensagem `system` com o prompt da persona;
2. mensagens persistidas do chat, em ordem;
3. nova mensagem do usuario.

Quando a estimativa passa do limite, o sistema remove mensagens antigas da conversa antes de enviar ao modelo.

O system prompt e preservado fora da tabela `messages` e e reinjetado na montagem do contexto.

### 8.12 Indicador visual de contexto

A area inferior da tela mostra uma barra de uso de contexto.

Ela exibe:

- percentual;
- tokens estimados;
- limite configurado.

Estados visuais:

- verde para uso normal;
- amarelo a partir de 70%;
- vermelho a partir de 90%.

O indicador e atualizado:

- no carregamento inicial;
- apos resposta da IA;
- apos troca ou alteracao de persona, quando o backend retorna `context_usage`.

### 8.13 Tratamento de estouro de contexto

Se o Ollama retornar erro relacionado a contexto, janela ou tokens, o backend trata como erro especifico.

Nesse caso:

- limpa as mensagens persistidas do chat;
- emite erro NDJSON com `context_reset=true`;
- retorna uso de contexto baseado apenas no system prompt.

A mensagem exibida ao usuario informa que o contexto ficou grande demais e foi resetado automaticamente.

### 8.14 Tratamento de erros de streaming

O backend desliga `display_errors` durante o streaming para evitar que warnings PHP quebrem o NDJSON.

Tambem registra:

- `set_error_handler`;
- `register_shutdown_function`;
- conversao de erros tecnicos em payloads NDJSON do tipo `error`.

No frontend, se chegar uma linha que nao e JSON valido, a interface mostra erro como:

```text
Resposta inesperada do servidor: ...
```

Antes de exibir, tags HTML sao removidas da mensagem recebida.

### 8.15 Renderizacao Markdown

Respostas do assistente sao renderizadas como Markdown.

Recursos atuais:

- paragrafos;
- listas;
- links;
- codigo inline;
- blocos de codigo;
- highlight com `highlight.js`;
- sanitizacao com `DOMPurify`.

Se as bibliotecas externas nao carregarem, ha fallback basico de renderizacao.

### 8.16 Normalizacao de blocos de codigo

O arquivo `message-ui.js` possui logicas para corrigir problemas comuns de respostas de LLMs:

- blocos duplicados;
- codigo sem fence Markdown;
- fences malformadas como `markdown` contendo outro bloco;
- codigo plano repetido antes do codigo formatado.

Isso melhora a leitura quando o modelo responde com codigo PHP, JavaScript, HTML ou CSS.

### 8.17 Copiar resposta

Cada resposta final do assistente recebe botao de copiar.

O botao:

- copia o texto original da resposta;
- usa `navigator.clipboard` quando possivel;
- usa fallback com `document.execCommand('copy')`;
- mostra feedback visual de sucesso ou falha.

### 8.18 Reusar pergunta

Cada mensagem do usuario recebe botao para reusar a pergunta.

Ao clicar:

- o texto volta para o input;
- o campo recebe foco;
- o cursor e posicionado no final.

Isso ajuda a reenviar perguntas parecidas, trocar modelo ou ajustar prompt.

### 8.19 Modais

A aplicacao possui dois modais principais:

- informacoes do modelo;
- biblioteca de personas.

Comportamentos atuais:

- abrir por botao;
- fechar por botao;
- fechar clicando no backdrop;
- fechar com `Escape`;
- foco direcionado para botao de fechar ao abrir.

### 8.20 Layout e experiencia visual

A UI usa tema escuro com destaque verde.

Caracteristicas:

- container centralizado;
- header com titulo, modelo, persona e acoes;
- area rolavel para mensagens;
- input fixo na parte inferior;
- indicador de contexto;
- mensagens do usuario alinhadas a direita;
- mensagens do assistente alinhadas a esquerda;
- estado de digitacao com pontos animados;
- responsividade para telas pequenas.

### 8.21 RAG local e documentos ativos

RAG significa usar documentos locais como contexto adicional para a resposta da IA.

Na interface, a selecao de documentos fica dentro da gaveta **Resumo** quando o plugin analitico esta ativo. Quando ha apenas um arquivo marcado, a pilula mostra o nome truncado do documento; quando ha varios, mostra a contagem, por exemplo `2 documentos ativos`. A propria pilula abre o menu de selecao e o `x` interno apenas desvincula rapidamente o primeiro documento ativo, sem apagar o arquivo preparado. Ao lado do botao de adicionar arquivo, o botao de gerenciamento abre o painel de documentos adicionados.

Quando nenhum documento esta marcado:

1. o chat funciona normalmente;
2. a IA recebe apenas a persona/system prompt e o historico da conversa;
3. documentos preparados nao entram na resposta.

Quando um ou mais documentos estao marcados:

1. o usuario envia uma pergunta;
2. o sistema transforma essa pergunta em um vetor de significado usando o modelo de embeddings;
3. o sistema usa apenas os documentos marcados no menu;
4. o sistema procura no SQLite os pedacos de documentos mais parecidos com a pergunta;
5. os 3 trechos mais relevantes sao adicionados ao system prompt enviado ao motor escolhido;
6. a IA responde considerando a conversa e esses trechos recuperados;
7. a interface pode exibir uma indicacao como `Baseado em: nome-do-arquivo.md`.

Na lista de documentos:

1. cada arquivo tem um checkbox proprio;
2. marcar um documento inclui esse arquivo no contexto da proxima mensagem;
3. desmarcar todos os documentos desativa o RAG para a conversa;
4. o `x` da pilula dentro de **Resumo** desvincula um documento ativo sem remover o preparo;
5. o botao `x` de cada item da lista abre um modal de confirmacao antes de remover o documento preparado e seus chunks;
6. remover um documento impede que ele seja usado em respostas futuras.

No painel de gerenciamento de documentos adicionados:

1. `GET ?action=rag_documents_list` retorna os documentos preparados;
2. cada item mostra nome, quantidade de chunks, data de preparo e estimativa de espaco ocupado;
3. o estado vazio informa quando ainda nao ha documentos preparados;
4. `POST ?action=rag_document_delete` recebe `document_id` e remove o registro em `rag_documents` junto com os chunks em `document_chunks`;
5. depois da exclusao, a lista do painel e o contador de documentos ativos no chat sao atualizados sem recarregar a conversa.

No motor **Ollama**, esse contexto e montado dentro do fluxo de streaming do backend. No motor **Web AI**, o navegador consulta `POST ?action=rag_context` antes de gerar a resposta e injeta o contexto recuperado no prompt enviado ao modelo WebGPU.

Importante: marcar um documento nao prepara arquivos novos. Para aparecer na lista, primeiro e necessario selecionar um arquivo para adicionar aos documentos.

O fluxo de preparo funciona assim:

1. o usuario seleciona um arquivo de texto, CSV ou planilha `.xlsx`/`.xls`;
2. se for planilha, o navegador converte as abas em texto tabular antes do envio;
3. arquivos pequenos seguem para `POST ?action=rag_ingest`;
4. CSVs maiores que o lote configurado no navegador sao fatiados com `File.slice()` e enviados sequencialmente para `POST ?action=rag_chunk_upload`;
5. no upload em lotes, o backend salva partes temporarias em `storage/chunk_uploads`, junta o arquivo final e processa o CSV por streaming;
6. linhas CSV com quantidade de colunas diferente do cabecalho sao ignoradas;
7. o backend registra uma amostra do CSV no RAG e cria/atualiza o dataset analitico do documento;
8. em perguntas futuras, os chunks podem ser recuperados por similaridade e os dados tabulares podem ser consultados pela camada analitica.

Arquivos `.txt`, `.json` e CSVs pequenos continuam seguindo o upload normal. CSVs grandes usam upload em lotes para contornar `post_max_size`. Arquivos `.xlsx` e `.xls` sao processados no navegador com SheetJS: cada aba com conteudo vira uma secao textual com o nome do arquivo, o nome da aba e linhas em formato CSV. O endpoint `POST ?action=rag_ingest` continua recebendo apenas texto plano.

O Ollama possui funcionalidade propria para gerar embeddings. Neste projeto, essa chamada e feita pelo backend usando:

```text
POST /api/embeddings
```

Embedding nao e uma resposta em texto para o usuario. E uma lista de numeros que representa o significado aproximado de um trecho. O sistema usa esses numeros para comparar a pergunta com os chunks salvos no banco e encontrar os trechos mais parecidos.

Existe uma diferenca importante entre os modelos:

1. **Modelo de chat:** responde mensagens, por exemplo `llama3.2:latest`.
2. **Modelo de embedding:** transforma texto em vetor, por exemplo `nomic-embed-text`.

Por isso, ter um modelo de chat instalado no Ollama nao garante que o RAG consiga preparar documentos. Para preparar documentos, tambem precisa existir um modelo de embedding.

O modelo usado para ler documentos e definido por `RAG_EMBEDDING_MODEL`. O padrao atual e:

```text
nomic-embed-text
```

Se aparecer a mensagem abaixo:

```text
O modelo de leitura de documentos nao esta instalado no Ollama. Rode: ollama pull nomic-embed-text
```

significa que o Ollama local ainda nao possui o modelo de embeddings. A correcao e executar:

```bash
ollama pull nomic-embed-text
```

Depois disso, o preparo dos documentos deve conseguir gerar embeddings.

Sobre tamanho dos arquivos: o arquivo inteiro nao precisa ser pequeno. O que precisa ser pequeno e cada chunk enviado ao modelo de embeddings. Por isso o sistema quebra o texto antes de chamar o Ollama. O chunker atual usa:

- minimo aproximado de `500` caracteres;
- maximo aproximado de `1500` caracteres;
- overlap de `50` caracteres.

Esse limite evita erros como:

```text
the input length exceeds the context length
```

Esse erro acontece quando um trecho enviado ao modelo ficou maior que a janela de contexto permitida pelo modelo de embeddings.

### 8.22 RAG e DuckDB trabalhando juntos

O RAG e o DuckDB resolvem problemas diferentes. O RAG encontra **trechos de texto relevantes** para a pergunta. O DuckDB calcula **respostas estruturadas** sobre dados tabulares, como CSV e JSON.

Em termos simples:

- **RAG** ajuda a IA a lembrar e localizar contexto textual.
- **DuckDB** ajuda a aplicacao a contar, somar, agrupar, ordenar e calcular dados com SQL.
- **Ollama/modelo de chat** interpreta a pergunta, explica o resultado e escreve a resposta final.
- **SQLite da aplicacao** continua guardando chats, mensagens, documentos, chunks, embeddings e metadados.

#### 8.22.1 Quando um documento e adicionado

1. O usuario adiciona um arquivo pela interface.
2. O backend cria ou atualiza um registro em `rag_documents`.
3. O conteudo textual e dividido em chunks pelo `RagChunkerService`.
4. Cada chunk recebe embedding pelo Ollama, usando o modelo configurado em `RAG_EMBEDDING_MODEL`.
5. Os chunks e embeddings sao salvos em `document_chunks`.
6. Se o arquivo for estruturado, como `.csv` ou `.json`, o `StructuredDataParser` tambem transforma o conteudo em colunas e linhas normalizadas.
7. O `WorkspaceAnalyticsService` salva o metadado desse dataset em `analytics_datasets`.
8. Se `pdo_duckdb` estiver disponivel, o mesmo dataset vira uma tabela real no DuckDB em `storage/analytics/workspace_<id>.duckdb`.

Resultado: um CSV/JSON preparado passa a ter duas leituras complementares. O RAG guarda uma amostra textual para recuperacao semantica, e o DuckDB guarda uma tabela consultavel por SQL.

#### 8.22.2 Quando o usuario pergunta sobre documentos no chat

1. O usuario marca um ou mais documentos na gaveta **Resumo**.
2. O usuario envia uma pergunta no chat.
3. O `ChatStreamHandler` identifica que existem documentos ativos.
4. O RAG gera embedding da pergunta e busca os chunks mais parecidos em `document_chunks`.
5. Em paralelo, o `WorkspaceAnalyticsService` verifica se os documentos ativos possuem dataset estruturado em `analytics_datasets`.
6. Se houver dataset estruturado, o modelo recebe apenas catalogo, colunas, nome da tabela e amostras, e gera um `SELECT` seguro.
7. O backend valida que a query e somente `SELECT` e que usa uma tabela permitida.
8. A query roda no DuckDB persistente do workspace.
9. O resultado estruturado da consulta e adicionado ao system prompt junto com os trechos recuperados pelo RAG.
10. O Ollama gera a resposta final usando a conversa, os trechos do RAG e o resultado calculado pelo DuckDB.

Esse uso do DuckDB so acontece quando existem documentos selecionados e quando pelo menos um deles tem dataset estruturado. Perguntas sem documentos ativos continuam no fluxo normal do chat.

#### 8.22.3 Quem faz o que

| Parte | Responsabilidade |
|---|---|
| `RagIngestionService` | prepara o documento para RAG e salva chunks com embeddings |
| `RagRetrievalService` | busca os chunks mais parecidos com a pergunta |
| `StructuredDataParser` | transforma CSV/JSON em colunas e linhas normalizadas |
| `WorkspaceAnalyticsService` | registra datasets, cria tabelas DuckDB, executa SQL e monta contexto analitico |
| DuckDB | executa consultas analiticas sobre dados tabulares do workspace |
| SQLite | persiste estado da aplicacao, metadados, chunks e historico |
| Ollama | gera embeddings, escolhe SQL quando necessario e escreve a resposta final |

#### 8.22.4 Exemplos praticos

Pergunta textual:

```text
Explique o que esse documento diz sobre evasao escolar.
```

Nesse caso, o RAG tende a ser o principal caminho. Ele procura trechos semanticamente parecidos e injeta esses trechos no contexto da resposta.

Pergunta analitica:

```text
Quantos alunos existem por serie?
```

Se o documento ativo for uma tabela com coluna `serie`, o DuckDB pode receber uma consulta como:

```sql
SELECT serie, COUNT(*) AS total
FROM dataset_12_alunos
GROUP BY serie
ORDER BY total DESC
LIMIT 40
```

O modelo nao precisa contar linhas dentro do prompt. Ele recebe o resultado ja calculado e apenas explica a resposta.

Pergunta mista:

```text
Qual serie concentra mais alunos e o que os dados sugerem?
```

Nesse caso, o DuckDB calcula o ranking por serie, enquanto o RAG pode fornecer trechos textuais ou amostras que ajudam a contextualizar a explicacao.

#### 8.22.5 O que melhora na aplicacao

- **Mais precisao numerica:** contagens, somas, medias e rankings saem de SQL local, nao de estimativa do modelo.
- **Menos tokens no prompt:** o chat recebe resultado resumido em vez de receber uma tabela grande inteira.
- **Melhor desempenho em dados tabulares:** DuckDB e otimizado para consultas analiticas, especialmente `GROUP BY`, `ORDER BY`, `COUNT`, `SUM` e `AVG`.
- **Menos repeticao de trabalho:** os dados ficam persistidos por workspace em DuckDB, evitando recriar a tabela a cada consulta.
- **Respostas mais confiaveis:** o modelo interpreta um resultado estruturado, reduzindo risco de inverter eixo, contar errado ou inventar valores.
- **Fluxo unico para o usuario:** o usuario so marca o documento e pergunta; a aplicacao decide quando usar RAG, DuckDB ou os dois.

#### 8.22.6 Regra mental do fluxo

Use esta regra para entender o comportamento:

```text
Texto livre ou explicacao -> RAG
Tabela, contagem, soma, media, ranking ou grafico -> DuckDB
Pergunta com texto e numeros -> RAG + DuckDB
Sem documento selecionado -> chat normal
```

### 8.23 Central de Documentacao

A aplicacao possui uma Central de Documentacao acessivel pelo botao de livro no menu principal do chat.

Essa tela apresenta o conteudo de `Doc/README.md` dentro da propria interface do Olliverse, com navegacao lateral para secoes principais.

Fluxo:

1. o usuario clica no botao **Central de documentacao** no header;
2. o frontend abre `index.php?view=docs&chat_id=ID`;
3. o backend le `Doc/README.md`;
4. o Markdown e convertido para HTML pelo `DocumentationService`;
5. os titulos recebem ancoras estaveis para a navegacao lateral;
6. a view `views/documentation.php` renderiza a central;
7. o botao **Voltar ao chat** retorna para a conversa atual quando `chat_id` foi informado.

O arquivo `Doc/README.md` continua sendo a fonte canonica da documentacao funcional e tecnica.

### 8.24 Plugins e graficos

A aplicacao possui uma arquitetura inicial de plugins em `plugins/`.

Cada plugin pode ter:

- `manifest.json` com metadados, dependencias e descricao;
- `includes/prompt.php` com instrucao adicional para o system prompt;
- `includes/inspect_prompt.php` e `includes/insights_view.php` para inspecao analitica baseada em RAG ou tabelas locais;
- `assets/style.css` com estilos isolados;
- `assets/script.js` com comportamento proprio do plugin.

O primeiro plugin disponivel e `data_analyst`, exibido como **Analise de Dados & Graficos** no menu de plugins do header.

Quando o plugin esta desligado:

1. o Chart.js nao e injetado no HTML inicial;
2. o prompt adicional do plugin nao entra no payload do Ollama;
3. a IA responde apenas pelo comportamento normal da persona e do historico.

Quando o plugin esta ligado:

1. o estado fica guardado em `$_SESSION['olliverse_plugins']`;
2. o `PluginManager` injeta o prompt de `plugins/data_analyst/includes/prompt.php` nas proximas chamadas ao Ollama;
3. o frontend tambem recebe esses prompts em `activePluginPrompts` para orientar respostas da Web AI;
4. o frontend carrega Chart.js local em `public/vendor/chart.js/chart.umd.js` e os assets do plugin sob demanda;
5. o prompt do plugin orienta a IA a responder com analise textual e tabelas Markdown legiveis, nao com JSON cru;
6. arquivos `.csv` e `.json` adicionados ao workspace tambem sao registrados como datasets em `analytics_datasets`;
7. quando a inspecao encontra dataset estruturado, o modelo sugere consultas `SELECT` usando o nome da tabela local;
8. ao clicar em um chip com SQL, o frontend chama `POST ?action=data_query`, recebe um payload com `query_executed` e `chart_config`, e renderiza o grafico diretamente no chat;
9. quando uma resposta do assistente contem uma tabela com categorias e valores numericos, o frontend exibe o botao **Plotar grafico** no rodape da mensagem;
10. ao clicar em **Plotar grafico**, o plugin extrai os dados da tabela ja renderizada e cria um card Chart.js sem nova chamada ao Ollama;
11. cada card de grafico recebe um seletor local para alternar entre barras, pizza, linhas e tabela sem nova chamada ao Ollama;
12. o controle compacto **Resumo** aparece quando ha documentos adicionados e abre a gaveta lateral sem chamar IA automaticamente; dentro dela ficam a selecao de documentos ativos e o botao **Gerar insights**, que analisa os documentos marcados e abre um popover de sugestoes rapidas;
13. abaixo do grafico, o botao **Baixar imagem** gera um arquivo PNG do grafico renderizado;
14. ao exportar a conversa atual em PDF, o frontend envia os canvases ativos como PNG base64 para que o relatorio substitua os blocos de grafico por imagens estaticas.

O fluxo de inspecao opcional usa `plugins/data_analyst/includes/inspect_prompt.php`.

Quando o plugin esta ativo, o controle **Resumo** aparece se existir pelo menos um documento adicionado. A selecao de documentos ativos fica dentro dessa gaveta, nao solta no topo do chat. A inspecao nao ocupa mais uma faixa fixa acima da conversa e nao roda automaticamente no carregamento da tela. O resumo tecnico e as sugestoes so sao gerados quando o usuario aciona **Gerar insights** dentro dessa gaveta.

1. o usuario clica em **Gerar insights**;
2. o frontend chama `POST /index.php?action=data_insights`;
3. o backend procura primeiro datasets estruturados em `analytics_datasets`;
4. quando existe dataset estruturado, o modelo recebe `document_id`, `table_name`, colunas e amostras e deve devolver sugestoes com `sql`;
5. quando nao existe dataset estruturado, o backend recupera uma amostra dos chunks em `document_chunks` no SQLite;
6. a resposta do modelo precisa conter um JSON com `summary` e `suggestions`;
7. a amostra analisada fica guardada em `$_SESSION['olliverse_data_analyst_dataset']` ou `$_SESSION['olliverse_data_analyst_rag_sample']`;
8. o `PluginManager` injeta essa base como contexto adicional enquanto o plugin estiver ativo;
9. o frontend renderiza o resumo na gaveta lateral e replica os chips no popover de sugestoes;
10. chips com SQL executam a consulta local e renderizam o grafico; chips sem SQL disparam uma pergunta normal do chat pedindo uma tabela Markdown com os documentos ativos.

A camada analitica usa `App\Services\WorkspaceAnalyticsService`. O contrato executa consultas no DuckDB quando a extensao `pdo_duckdb` esta disponivel: arquivos estruturados viram datasets por workspace, sao gravados em `storage/analytics/workspace_<id>.duckdb`, consultas sao `SELECT` e o resultado para grafico separa explicitamente eixo X (`labels`) e eixo Y (`datasets[0].data`). Sem `pdo_duckdb`, o servico usa tabelas temporarias em SQLite como fallback local para manter o fluxo funcional.

Quando o usuario pergunta no chat com documentos ativos, o backend tambem verifica se os documentos selecionados possuem dataset estruturado. Se houver, o modelo gera uma consulta `SELECT` segura a partir da pergunta, o backend executa essa consulta no DuckDB e injeta o resultado estruturado no contexto antes de chamar o chat. Esse uso so acontece quando ha documento indexado/selecionado.

O prompt do plugin funciona como uma regra nativa de preparacao de dados para grafico. Antes de montar a tabela Markdown, o modelo deve inferir:

- dimensao de agrupamento, usada como coluna de categoria;
- metrica numerica, usada como coluna de valor;
- forma de leitura mais adequada para explicar o resultado ao usuario.

Exemplos de inferencia:

- "grafico por genero" usa cada genero como categoria e a quantidade de alunos por genero como valor;
- "grafico por serie" usa cada serie como categoria e a quantidade de alunos em cada serie como valor;
- "distribuicao por idade" usa cada idade como categoria e a quantidade de alunos naquela idade como valor;
- "maior nota" ou "compare notas" usa nomes dos alunos como categoria e as notas como valor, salvo quando o usuario pedir outra metrica.

Por padrao, a coluna numerica deve usar quantidades absolutas. Percentuais so devem ser usados quando o usuario pedir explicitamente porcentagem.

Contrato esperado para tabelas geradas pela IA:

```markdown
| Categoria | Quantidade |
| --- | ---: |
| Label 1 | 10 |
| Label 2 | 25 |
```

O frontend procura tabelas Markdown renderizadas com pelo menos uma coluna textual/categorica e uma coluna numerica. Como tolerancia defensiva para respostas imperfeitas do modelo, tambem recupera tabelas ASCII/`plaintext` com bordas `+---+`, mas o contrato principal continua sendo tabela Markdown. Quando encontra uma tabela plotavel, injeta o botao **Plotar grafico** no rodape do balao da mensagem. O clique converte localmente as linhas da tabela em `labels` e `data`, sugere o tipo inicial do grafico e cria o card interativo.

Para consultas vindas de `data_query`, a query deve retornar a coluna de categoria primeiro e a metrica numerica em seguida. Isso evita inversao de eixos: por exemplo, `SELECT ano, salario FROM dataset_1_salarios ORDER BY ano ASC` gera `labels` com anos e `data` com salarios.

Depois que um grafico e renderizado, o frontend guarda o payload normalizado no proprio card e permite alternar a visualizacao instantaneamente entre:

- `bar`: grafico de barras;
- `pie`: grafico de pizza;
- `line`: grafico de linhas;
- tabela: conversao local de `labels` e `data` em linhas tabulares.

A alternancia acontece apenas no JavaScript do navegador. Ela destroi a instancia Chart.js atual, recria o grafico escolhido quando necessario e nao dispara novas requisicoes para o Ollama. No modo tabela, o botao de download fica desabilitado porque nao ha canvas ativo para exportar como PNG.

Blocos antigos `json-chart` continuam sendo aceitos como compatibilidade defensiva para historico, respostas antigas ou modelos que ainda retornem esse formato. O frontend mantem normalizacao para respostas imperfeitas, incluindo remover cercas Markdown residuais como `json-chart`/`json` e comentarios `//` ou `/* ... */` antes de interpretar payloads de grafico. Quando um payload nao pode ser interpretado, a falha fica registrada no console e o restante da mensagem Markdown continua renderizando normalmente. Quando a Web AI retorna um bloco `json` comum com formato claro de grafico (`type`, `labels` e `data`), o plugin tambem tenta renderizar esse bloco como grafico; JSON comum que nao pareca grafico continua aparecendo como codigo.

Antes de entregar os dados ao Chart.js, o plugin converte valores em formato numerico comum ou brasileiro, como `8,5`, `1.234,56`, `12%` e `R$ 100`, para numeros JavaScript reais. Se algum valor nao puder ser convertido, a falha fica no console e o restante da mensagem continua renderizando normalmente.

O frontend tambem recupera alguns formatos imperfeitos comuns gerados pela IA, como blocos JSON soltos depois de um rotulo `json`, chaves com espacos (`" dados"`) e residuos visuais do highlighter (`class="code-number">`). Quando recebe uma lista de objetos com uma coluna de nome/grupo e varias metricas numericas, o plugin monta um grafico comparativo com datasets por grupo.

### 8.25 Modo Foco

A interface possui um **Modo Foco** para leitura de respostas longas, blocos de codigo, tabelas e graficos.

O botao com icone de expansao fica no topo do chat. Ao ativar esse modo:

- a barra lateral de historico fica oculta;
- o header principal e reduzido para uma faixa minima;
- a area de mensagens ocupa toda a largura e altura da viewport;
- o botao muda para o icone de recolher e permite voltar ao layout normal.

O estado e guardado em `sessionStorage` com a chave `olliverse_zen_mode_active`, portanto permanece apenas durante a sessao atual do navegador.

Pressionar `Escape` quando o Modo Foco esta ativo restaura imediatamente o layout padrao.

## 9. Contratos HTTP atuais

### 9.1 Abrir chat

```http
GET /index.php?chat_id=1
```

Carrega a conversa informada. Se nao existir, cria uma nova conversa.

### 9.2 Criar nova conversa

```http
GET /index.php?new=1&chat_id=1
```

Cria novo chat. Quando informado, usa a persona ativa do chat anterior como base.

### 9.3 Compatibilidade de limpeza

```http
GET /index.php?clear=1
```

No codigo atual, tambem cria uma nova conversa.

### 9.4 Buscar no historico

```http
GET /index.php?action=search_history&q=termo
Accept: application/json
```

Retorna conversas cujo titulo ou conteudo das mensagens contenha o termo informado.

Resposta esperada:

```json
{
  "success": true,
  "query": "termo",
  "chats": [],
  "chat_ids": []
}
```

O endpoint antigo `action=search` continua funcionando como alias e retorna o mesmo payload.

### 9.5 Atualizar titulo da conversa

```http
POST /index.php?action=update_chat_title
Content-Type: application/x-www-form-urlencoded
Accept: application/json

chat_id=1&model=llama3.2:latest
```

Quando `title` nao e enviado, o backend gera um titulo curto via Ollama usando as primeiras mensagens da conversa. Quando `title` e enviado, o valor e normalizado e persistido diretamente.

Resposta esperada:

```json
{
  "success": true,
  "title": "Resumo Curto",
  "chat": {},
  "chats": []
}
```

### 9.6 Buscar metadados de modelo

```http
GET /index.php?action=model_metadata&model=llama3.2:latest
Accept: application/json
```

Resposta esperada:

```json
{
  "model": "llama3.2:latest",
  "size_gb": 2.0,
  "family": "llama",
  "context_length": 8192,
  "context_fallback": false,
  "quantization": "Q4_K_M"
}
```

`context_length` sempre vem preenchido. Quando o Ollama nao retorna o teto real, o backend usa o fallback de `CONTEXT_TOKEN_LIMIT` e marca `context_fallback` como `true`. Os demais campos podem vir como `null` quando o dado nao for encontrado.

### 9.7 Exportar conversa em Markdown

```http
GET /index.php?action=export_md&chat_id=1
```

Retorna download `text/markdown` com o historico da conversa. O endpoint antigo `action=export` continua funcionando como alias para Markdown.

### 9.8 Exportar conversa em PDF

```http
GET /index.php?action=export_pdf&chat_id=1
```

Retorna download `application/pdf` gerado com Dompdf. O conteudo das mensagens passa por Parsedown em modo seguro e e renderizado no template `views/pdf/chat_template.php`.

O mesmo endpoint tambem aceita `POST` com `chart_images` em JSON. Esse campo e usado pelo frontend da conversa atual para enviar imagens `data:image/png;base64,...` dos canvases Chart.js ativos, preservando os graficos no PDF no mesmo ponto em que aparecem na conversa. Blocos antigos `json-chart` no historico tambem podem ser substituidos por imagens quando o frontend enviar a captura correspondente.

### 9.9 Enviar mensagem ao chat

```http
POST /index.php?chat_id=1
Content-Type: application/x-www-form-urlencoded

prompt=Mensagem%20do%20usuario&model=llama3.2:latest
```

Resposta:

```http
Content-Type: application/x-ndjson; charset=UTF-8
```

Linhas possiveis:

```json
{"type":"chunk","content":"texto parcial"}
{"type":"meta","context_usage":{"tokens":123,"limit":8000,"percentage":2},"context_trimmed":false}
{"type":"error","message":"erro amigavel","context_reset":false}
```

### 9.10 Selecionar persona

```http
POST /index.php?chat_id=1
Content-Type: application/x-www-form-urlencoded

persona_action=select&persona_id=2
```

Resposta:

```json
{
  "success": true,
  "persona": {},
  "personas": [],
  "context_usage": {}
}
```

### 9.11 Criar persona

```http
POST /index.php?chat_id=1
Content-Type: application/x-www-form-urlencoded

persona_action=create&name=Nome&description=Descricao&prompt_content=Prompt
```

Cria a persona e a define como ativa no chat atual.

Se o nome ja existir, mesmo com diferenca apenas de maiusculas, minusculas, acentos ou espacos nas extremidades, o backend responde `422` com `Já existe uma persona com esse nome. Escolha um nome diferente.`

### 9.12 Atualizar persona

```http
POST /index.php?chat_id=1
Content-Type: application/x-www-form-urlencoded

persona_action=update&persona_id=2&name=Nome&description=Descricao&prompt_content=Prompt
```

Atualiza a persona. Se ela for a persona ativa do chat atual, o chat passa a usar o prompt atualizado.

A validacao de nome duplicado usa a mesma regra da criacao. A Persona Base nao pode ter nome ou descricao alterados.

### 9.13 Excluir persona

```http
POST /index.php?chat_id=1
Content-Type: application/x-www-form-urlencoded

persona_action=delete&persona_id=2
```

Remove a persona, desde que ela nao seja a fallback padrao. Chats que usavam essa persona sao movidos para a Persona Base.

### 9.14 Gerar system prompt de persona com IA

```http
POST /index.php?chat_id=1&action=prompt_generate
Content-Type: application/x-www-form-urlencoded

name=Analista%20de%20Codigo&description=Revisa%20codigo%20PHP%20com%20foco%20em%20clareza&model=llama3.2:latest
```

Resposta esperada:

```json
{
  "success": true,
  "prompt_content": "name: Analista de Codigo\nrole: ...\npersona_traits:\n  - ...\nskills:\n  - ...\ndirectives:\n  - ..."
}
```

Esse endpoint:

- valida se o modelo informado existe na lista local do Ollama;
- exige `name` e `description`;
- monta um meta-prompt no backend;
- chama `/api/generate` do Ollama com `stream=false`;
- extrai o bloco YAML da resposta;
- valida se o YAML possui `name`, `role`, `persona_traits`, `skills` e `directives`.

Erros retornam JSON com status `422`:

```json
{
  "success": false,
  "error": "Informe nome e descrição antes de gerar o prompt."
}
```

### 9.15 Atualizar system prompt diretamente

```http
POST /index.php?chat_id=1
Content-Type: application/x-www-form-urlencoded

system_prompt=Novo%20prompt
```

Este endpoint ainda existe. No comportamento atual, ele cria uma **Persona personalizada** a partir do prompt informado e associa essa persona ao chat.

### 9.16 Abrir Central de Documentacao

```http
GET /index.php?view=docs&chat_id=1
```

Renderiza a Central de Documentacao com o conteudo de `Doc/README.md`.

### 9.17 Ativar ou desativar plugin

```http
POST /index.php?action=plugin_toggle
Content-Type: application/x-www-form-urlencoded

plugin=data_analyst&active=1
```

Resposta esperada:

```json
{
  "success": true,
  "plugins": [],
  "active_plugins": []
}
```

O estado e salvo na sessao PHP e passa a valer para as proximas mensagens enviadas ao modelo.

### 9.18 Gerenciar documentos adicionados

```http
GET /index.php?action=rag_documents_list
Accept: application/json
```

Resposta esperada:

```json
{
  "success": true,
  "documents": [
    {
      "id": 1,
      "source_name": "alunos.csv",
      "chunks": 8,
      "created_at": "2026-07-22 10:30:00",
      "estimated_bytes": 12400
    }
  ]
}
```

Para excluir um documento preparado:

```http
POST /index.php?action=rag_document_delete
Content-Type: application/x-www-form-urlencoded

document_id=1
```

O backend remove o documento de `rag_documents` e todos os chunks vinculados em `document_chunks`. Os endpoints antigos `action=rag_documents` e `action=rag_delete` continuam funcionando como aliases internos.

### 9.19 Upload CSV em lotes

```http
POST /index.php?action=rag_chunk_upload
Content-Type: multipart/form-data

upload_id=upload_abc123
file_name=base-grande.csv
chunk_index=0
total_chunks=58
chunk=<blob csv parcial>
```

Resposta intermediaria:

```json
{
  "success": true,
  "complete": false,
  "received": 1,
  "total": 58
}
```

Resposta do ultimo lote:

```json
{
  "success": true,
  "complete": true,
  "document": {
    "id": 12,
    "source_name": "base-grande.csv",
    "chunks": 1
  },
  "analytics_dataset": {
    "source_name": "base-grande.csv",
    "table_name": "dataset_12_base_grande",
    "row_count": 5000,
    "engine": "duckdb-pdo"
  },
  "documents": []
}
```

O frontend usa `File.slice()` em lotes de aproximadamente 1,5 MB para ficar abaixo de limites comuns de `post_max_size`. O backend aceita apenas CSV nesse fluxo, salva chunks temporarios, junta o arquivo final e processa o CSV por streaming. O dataset analitico guarda ate o limite operacional atual de linhas normalizadas, ignorando linhas corrompidas ou incompletas.

### 9.20 Inspecionar dados para sugestoes analiticas

```http
POST /index.php?action=data_insights
Content-Type: application/x-www-form-urlencoded

model=llama3.2&rag_document_ids[]=12
```

Resposta esperada:

```json
{
  "success": true,
  "sources": ["alunos.csv"],
  "inspection": {
    "summary": "Base com registros de alunos, series e notas.",
    "suggestions": [
      {
        "title": "Alunos por serie",
        "query": "Gere um grafico por serie usando a quantidade de alunos.",
        "chart_type": "bar",
        "document_id": 12,
        "sql": "SELECT serie, COUNT(*) AS total FROM dataset_12_alunos GROUP BY serie ORDER BY total DESC"
      }
    ]
  }
}
```

O endpoint nao recebe upload novo. Ele usa apenas os IDs enviados em `rag_document_ids[]`, consultando os documentos ja preparados em `rag_documents` e `document_chunks`. Na interface, ele so e chamado quando o plugin de dados esta ativo, ha documentos ativos e o usuario clica em **Gerar insights**.

Quando o documento selecionado tem dataset estruturado em `analytics_datasets`, a resposta tambem pode conter `datasets`, `engine` e sugestoes com `document_id` e `sql`. O campo `engine` retorna `duckdb-pdo` quando o driver DuckDB esta disponivel e `sqlite-fallback` quando o app esta usando o fallback local. Quando nao tem dataset estruturado, o endpoint mantém o fluxo RAG anterior e as sugestoes nao precisam de SQL.

### 9.21 Executar consulta analitica local

```http
POST /index.php?action=data_query
Content-Type: application/x-www-form-urlencoded

document_id=12&chart_type=bar&title=Alunos%20por%20serie&sql=SELECT%20serie,%20COUNT(*)%20AS%20total%20FROM%20dataset_12_alunos%20GROUP%20BY%20serie
```

Resposta esperada:

```json
{
  "success": true,
  "payload": {
    "status": "success",
    "engine": "duckdb-pdo",
    "query_executed": "SELECT serie, COUNT(*) AS total FROM dataset_12_alunos GROUP BY serie",
    "rows": [
      {"serie": "6 ano", "total": 18}
    ],
    "chart_config": {
      "type": "bar",
      "labels": ["6 ano"],
      "datasets": [
        {"label": "Alunos por serie", "data": [18]}
      ]
    }
  }
}
```

O endpoint aceita apenas `SELECT`. A consulta roda sobre uma tabela temporaria criada a partir do dataset do documento no workspace ativo. O contrato de grafico considera a primeira coluna retornada como eixo X e a segunda coluna numerica como eixo Y.

O parametro `chat_id` e opcional e serve apenas para o botao **Voltar ao chat** retornar para a conversa de origem.

## 10. Modelo de dados

### 10.1 Tabela `personas`

```sql
CREATE TABLE IF NOT EXISTS personas (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    prompt_content TEXT NOT NULL,
    is_public INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
```

Finalidade:

- guardar personas reutilizaveis;
- diferenciar personas semeadas/publicas de personas criadas pelo usuario;
- fornecer prompt para o contexto da IA.

### 10.2 Tabela `chats`

```sql
CREATE TABLE IF NOT EXISTS chats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title TEXT NOT NULL,
    model_used TEXT NOT NULL,
    system_prompt TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    persona_id INTEGER NULL,
    workspace_id INTEGER NULL
)
```

Finalidade:

- guardar o container da conversa;
- armazenar titulo;
- registrar ultimo modelo usado;
- manter prompt associado ao chat;
- associar chat a persona;
- associar chat ao workspace ativo.

### 10.3 Tabela `messages`

```sql
CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    chat_id INTEGER NOT NULL,
    role TEXT NOT NULL CHECK (role IN ('system', 'user', 'assistant')),
    content TEXT NOT NULL,
    token_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    FOREIGN KEY (chat_id) REFERENCES chats(id) ON DELETE CASCADE
)
```

Observacao importante: apesar da tabela aceitar `system`, o repositorio atualmente persiste apenas mensagens `user` e `assistant`. O system prompt fica no chat/persona e e reinjetado ao montar o contexto.

### 10.4 Tabela `workspaces`

```sql
CREATE TABLE IF NOT EXISTS workspaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    icon TEXT,
    created_at TEXT NOT NULL
)
```

Finalidade:

- representar um espaco de trabalho do usuario;
- agrupar conversas e documentos RAG por contexto;
- manter um workspace padrao para dados antigos.

### 10.5 Tabela `rag_documents`

```sql
CREATE TABLE IF NOT EXISTS rag_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NULL,
    source_name TEXT NOT NULL,
    created_at TEXT NOT NULL
)
```

Finalidade:

- representar cada arquivo preparado para RAG;
- manter um identificador estavel para selecao e exclusao;
- associar cada documento ao workspace ativo;
- permitir que arquivos antigos agrupados apenas por `source_name` sejam migrados para um documento formal.

### 10.6 Tabela `document_chunks`

```sql
CREATE TABLE IF NOT EXISTS document_chunks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    document_id INTEGER NULL,
    source_name TEXT NOT NULL,
    content TEXT NOT NULL,
    embedding_json TEXT NOT NULL,
    token_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL
)
```

Finalidade:

- guardar os pedacos de texto extraidos dos documentos;
- guardar o embedding JSON retornado pelo Ollama;
- vincular cada chunk a `rag_documents.id`;
- sustentar a busca por similaridade usada no chat e no plugin analitico.

### 10.7 Tabela `analytics_datasets`

```sql
CREATE TABLE IF NOT EXISTS analytics_datasets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL,
    document_id INTEGER NOT NULL,
    source_name TEXT NOT NULL,
    table_name TEXT NOT NULL,
    columns_json TEXT NOT NULL,
    rows_json TEXT NOT NULL,
    row_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
)
```

Finalidade:

- registrar arquivos estruturados preparados no workspace;
- manter nome de tabela local, colunas e linhas normalizadas;
- permitir consultas `SELECT` analiticas antes de renderizar graficos;
- gerar payload de grafico com eixo X e eixo Y explicitamente separados.

### 10.8 Busca textual de mensagens

```sql
CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts
USING fts5(content, chat_id UNINDEXED)
```

A tabela virtual `messages_fts` e mantida por triggers de insert, update e delete em `messages`. A busca atual da sidebar usa comparacao parcial em `chats.title` e `messages.content`, e a FTS permanece disponivel para evolucoes de ranking e busca textual mais avancada.

### 10.9 Indices

Indices criados:

```sql
CREATE INDEX IF NOT EXISTS idx_messages_chat_id_id ON messages(chat_id, id);
CREATE INDEX IF NOT EXISTS idx_chats_updated_at ON chats(updated_at);
CREATE INDEX IF NOT EXISTS idx_chats_persona_id ON chats(persona_id);
CREATE INDEX IF NOT EXISTS idx_document_chunks_document_id ON document_chunks(document_id);
CREATE INDEX IF NOT EXISTS idx_document_chunks_source_name ON document_chunks(source_name);
```

## 11. Principais classes e responsabilidades

### `App\Config\AppConfig`

Centraliza configuracoes de ambiente e valores padrao.

### `App\Database\SqliteConnection`

Cria diretorio do banco quando necessario, instancia PDO e ativa foreign keys.

### `App\Database\SqliteMigrator`

Cria tabelas, indices, garante a Persona Base `Assistente Geral`, remove as antigas personas publicas semeadas pelo sistema e migra chats antigos para personas.

### `App\Repositories\SqliteConversationRepository`

Responsavel por:

- recuperar mensagens;
- substituir mensagens;
- recuperar system prompt;
- associar prompt/persona ao chat;
- criar chats;
- validar existencia de chat;
- persistir conversa apos interacao;
- gerar titulo do chat a partir da primeira mensagem do usuario.

### `App\Repositories\SqlitePersonaRepository`

Responsavel por:

- listar personas;
- buscar persona ativa por chat;
- selecionar persona;
- criar persona;
- atualizar persona;
- excluir persona;
- bloquear nomes duplicados com comparacao case-insensitive e accent-insensitive;
- manter cache de persona ativa por sessao.

### `App\Repositories\SqliteDocumentChunkRepository`

Responsavel por:

- criar ou reaproveitar registros em `rag_documents`;
- substituir os chunks de um documento preparado;
- listar documentos com `id`, nome, quantidade de chunks, data e tamanho estimado;
- excluir um documento e seus chunks em uma transacao;
- recuperar chunks mais parecidos com uma pergunta usando similaridade vetorial;
- fornecer amostras de chunks para o plugin analitico.

### `App\Services\OllamaClient`

Responsavel por:

- listar modelos via `/api/tags`;
- obter tamanho do modelo via `/api/tags`;
- gerar texto rapido via `/api/generate`;
- enviar chat para `/api/chat`;
- processar streaming retornado pelo Ollama;
- repassar chunks para callback.

### `App\Services\PromptGeneratorService`

Responsavel por gerar system prompts de personas a partir de nome e descricao.

Faz:

- validar entrada obrigatoria;
- montar o meta-prompt de engenharia de prompts;
- chamar `OllamaClient::generate()`;
- extrair YAML puro da resposta, incluindo respostas envolvidas em code fence;
- validar as chaves obrigatorias `name`, `role`, `persona_traits`, `skills` e `directives`.

### `App\Services\RagIngestionService`

Responsavel por preparar documentos locais para consulta.

Faz:

- receber nome e conteudo textual;
- quebrar o texto com `RagChunkerService`;
- gerar embeddings no Ollama usando `RAG_EMBEDDING_MODEL`;
- salvar os chunks via `SqliteDocumentChunkRepository`;
- devolver metadados do documento preparado.

### `App\Services\RagRetrievalService`

Responsavel por buscar contexto em documentos ja preparados.

Faz:

- transformar a pergunta em embedding;
- buscar chunks semelhantes no SQLite;
- montar o trecho adicional do system prompt;
- devolver metadados de fontes exibidos abaixo da resposta.

### `App\Services\RagChunkerService`

Divide textos longos em chunks menores, com tamanho maximo controlado e pequeno overlap para preservar continuidade entre pedacos.

### `App\Services\StructuredDataParser`

Responsavel por transformar arquivos `.csv` e `.json` em linhas tabulares normalizadas para analise local.

Faz:

- detectar delimitador de CSV;
- normalizar nomes de colunas para uso em SQL;
- converter numeros comuns e brasileiros para valores numericos;
- limitar o volume carregado para manter o processamento local leve.

### `App\Services\WorkspaceAnalyticsService`

Responsavel pela camada analitica do workspace.

Faz:

- registrar datasets estruturados vinculados a documentos RAG;
- preparar tabelas temporarias para consultas `SELECT`;
- validar que consultas analiticas nao alterem dados;
- montar `chart_config` com `labels` e `datasets` para impedir inversao de eixos.

O servico usa `PDO::getAvailableDrivers()` para identificar o driver `duckdb`. Quando ele existe, cada workspace usa um arquivo persistente em `storage/analytics/workspace_<id>.duckdb`, com cache de extensoes em `storage/analytics/extensions` e temporarios em `storage/analytics/tmp`. Sem esse driver, usa SQLite temporario como fallback local.

### `App\Services\VectorSimilarityService`

Calcula similaridade de cosseno entre embeddings.

### `App\Services\PdfExportService`

Responsavel por gerar o PDF de uma conversa.

Faz:

- converter conteudo Markdown das mensagens com Parsedown em modo seguro;
- substituir graficos ativos por imagens PNG recebidas do frontend durante a exportacao analitica;
- carregar `views/pdf/chat_template.php`;
- configurar Dompdf para pagina A4;
- devolver o binario usado pelo endpoint `action=export_pdf`.

### `App\Services\DocumentationService`

Responsavel por carregar a documentacao do projeto.

Faz:

- ler `Doc/README.md`;
- converter Markdown para HTML com Parsedown em modo seguro;
- gerar IDs para os titulos principais;
- usar fallback em texto escapado quando Parsedown nao estiver disponivel.

### `App\Services\ContextWindowService`

Responsavel por:

- estimar tokens;
- calcular uso de contexto;
- montar array com system prompt;
- remover mensagens antigas quando o limite e excedido.

### `App\Services\ModelSelector`

Escolhe o modelo padrao com base nos modelos disponiveis e preferencias.

### `App\Services\ModelMetadataService`

Busca, parseia e cacheia metadados de modelos via `POST /api/show`, incluindo o teto de contexto usado pela barra do rodape.

### `App\Services\SizeParser`

Converte tamanhos para GB.

### `App\Http\ChatStreamHandler`

Coordena o envio da mensagem ao Ollama.

Responsabilidades:

- validar prompt;
- validar modelo;
- montar contexto;
- iniciar resposta NDJSON;
- tratar streaming;
- persistir mensagens;
- emitir metadados;
- tratar erros e timeout.

### `App\Http\NdjsonResponse`

Centraliza headers e emissao de linhas NDJSON.

### `App\Support\ErrorMessage`

Transforma erros tecnicos em mensagens mais seguras e detecta erros de contexto.

### `App\Support\IconSvg`

Gera SVGs inline usados no PHP.

### `App\Services\PluginManager`

Lista manifestos em `plugins/`, guarda os plugins ativos na sessao e retorna prompts/assets dos plugins ativos.

## 12. Arquivos JavaScript

### `public/assets/js/app.js`

Arquivo de inicializacao do frontend.

Faz:

- configura `marked`;
- inicializa barra de contexto;
- inicializa seletor de workspaces;
- inicializa seletor de modelo;
- inicializa controles de persona;
- registra eventos de submit do chat;
- registra eventos de botoes e modais;
- registra o botao de geracao de prompt de persona;
- liga o tooltip flutuante ao botao **Gerar**;
- controla bloqueio/desbloqueio da UI durante a resposta.

### `public/assets/js/chat-stream.js`

Cuida do contrato de streaming NDJSON.

Faz:

- leitura incremental da resposta;
- decodificacao UTF-8;
- processamento de buffer por linhas;
- parse de JSON;
- entrega de chunks para a renderizacao.

### `public/assets/js/chat-renderer.js`

Cuida de criar mensagens na tela.

Faz:

- adicionar mensagem de usuario;
- adicionar mensagem de assistente;
- criar mensagem temporaria de streaming;
- rolar a area de mensagens para o fim.

### `public/assets/js/message-ui.js`

Cuida de acoes e renderizacao avancada de mensagens.

Faz:

- atualizar barra de contexto;
- renderizar Markdown;
- sanitizar HTML;
- destacar codigo;
- normalizar blocos de codigo;
- copiar resposta;
- reusar pergunta;
- exibir tooltips.

### `public/assets/js/plugin-panel.js`

Cuida do menu de plugins no header.

Faz:

- abrir/fechar o menu de plugins;
- enviar `?action=plugin_toggle`;
- atualizar `window.OlliverseConfig.plugins`;
- carregar CSS, dependencias JS e script do plugin sob demanda;
- chamar hooks opcionais `activate` e `deactivate` dos plugins;
- reprocessar mensagens do assistente para detectar tabelas plotaveis e renderizar graficos quando o plugin esta ativo.

### `public/assets/js/model-panel.js`

Cuida do seletor de modelos, modal de metadados e biblioteca de personas.

Faz:

- abrir/fechar menu de modelos;
- selecionar modelo;
- carregar metadados;
- abrir/fechar modal de modelo;
- abrir/fechar biblioteca de personas;
- criar/editar/excluir/selecionar persona;
- habilitar/desabilitar o botao **Gerar** conforme nome e descricao;
- chamar `?action=prompt_generate`;
- injetar o YAML gerado no campo `System prompt`;
- sincronizar estado global do frontend.

### `views/documentation.php`

Renderiza a Central de Documentacao.

Faz:

- exibir o conteudo do manual dentro do app;
- oferecer navegacao lateral para secoes principais;
- manter um link de retorno para o chat atual.

## 13. Estado global no frontend

`views/chat.php` injeta configuracoes iniciais em `window.OlliverseConfig`:

```js
window.OlliverseConfig = {
    initialAssistantMessage,
    chatId,
    hasAvailableModels,
    initialContextUsage,
    personas,
    activePersona,
};
```

Tambem injeta `window.OlliverseState`:

```js
window.OlliverseState = {
    activeTooltipButton: null,
    modelMetadataCache: new Map(),
};
```

Esses objetos funcionam como contrato simples entre PHP renderizado e JavaScript.

## 14. Fluxo completo de uma mensagem

1. Usuario envia o formulario.
2. `app.js` captura o evento.
3. A mensagem do usuario aparece imediatamente.
4. Inputs, modelo, persona e botoes sao desabilitados temporariamente.
5. O frontend cria uma mensagem do assistente com indicador de digitacao.
6. `fetch` envia `prompt` e `model`.
7. `index.php` delega para `ChatStreamHandler`.
8. O handler recupera system prompt e mensagens persistidas.
9. A mensagem do usuario e adicionada ao array de contexto.
10. `ContextWindowService` remove mensagens antigas se passar do limite.
11. O backend inicia NDJSON.
12. `OllamaClient` chama `/api/chat` com `stream=true`.
13. Cada chunk do Ollama vira uma linha NDJSON `type=chunk`.
14. O frontend le cada linha e atualiza o Markdown progressivamente.
15. Ao final, o backend salva usuario + assistente no SQLite.
16. O backend envia `type=meta` com uso de contexto.
17. O frontend finaliza a mensagem e adiciona botao de copiar.
18. Inputs sao reabilitados.

## 15. Funcionalidades parcialmente preparadas ou planejadas

As specs em `IA/Specs` indicam evolucoes desejadas.

### Ja implementado a partir das specs

- Separacao inicial entre frontend e backend.
- Backend modularizado em classes.
- JavaScript separado por responsabilidade.
- Persistencia SQLite de chats e mensagens.
- Tabela de personas.
- Seletor de personas.
- CRUD simples de personas.
- System prompt vindo de persona.
- Geracao de system prompt em YAML para personas usando `/api/generate`.
- Controle de janela de contexto.
- Streaming consistente em NDJSON.
- RAG local com ingestao de documentos de texto e busca por similaridade.

### Ainda aparece como evolucao futura

- Navegacao/listagem visual de historico de chats.
- Busca no historico.
- Full-Text Search com SQLite FTS5.
- Arquivamento de conversas.
- Exportacao de chat para JSON.
- Melhorias avancadas de RAG, como filtros por documento e remocao visual de fontes.
- Metadados de modelos mais ricos.
- Titulo gerado por IA ou editavel pelo usuario.

## 16. Pontos tecnicos importantes

### 16.1 Sessao ainda existe, mas nao como fonte principal do chat

A regra arquitetural desejada e nao salvar estado principal do chat em sessao.

O codigo atual segue isso para conversas e mensagens, que ficam no SQLite.

A sessao ainda e usada para:

- cache de persona ativa;
- cache de metadados de modelo;
- controle natural de sessao PHP.

### 16.2 O system prompt tem duas representacoes

Atualmente o prompt pode estar:

- na tabela `personas`, em `prompt_content`;
- na tabela `chats`, em `system_prompt`.

Na pratica, quando uma persona esta associada ao chat, o prompt da persona tem prioridade. O campo `system_prompt` do chat funciona como copia/snapshot operacional.

### 16.3 A persistencia da conversa regrava mensagens

Ao final de uma interacao, o repositorio substitui as mensagens do chat pelo conjunto atualizado e aparado.

Isso simplifica a janela deslizante, mas significa que mensagens antigas removidas pelo controle de contexto deixam de existir na tabela `messages` daquele chat.

### 16.4 O titulo do chat e automatico e simples

O titulo e gerado a partir da primeira mensagem de usuario encontrada.

Se passar de 80 caracteres, e encurtado.

Ainda nao existe interface de historico para aproveitar esse titulo.

### 16.5 Metadados dependem do ambiente local

O modal de metadados depende de:

- Ollama acessivel;
- endpoint `POST /api/show` disponivel no Ollama local;
- resposta com `model_info`, `parameters` ou `modelfile` para detectar o contexto real.

Quando o contexto nao e encontrado, o backend usa `CONTEXT_TOKEN_LIMIT`. Quando outro campo nao e encontrado, a UI mostra `-`.

## 17. Como executar localmente

Requisitos:

- PHP 8.2 ou superior;
- extensao PDO SQLite habilitada;
- extensao cURL habilitada;
- extensao `pdo_duckdb` opcional para executar a camada analitica no DuckDB;
- Ollama rodando;
- pelo menos um modelo instalado no Ollama.

Exemplo de execucao simples:

```bash
php -S localhost:8000
```

Depois acesse:

```text
http://localhost:8000
```

O Ollama deve estar disponivel em:

```text
http://localhost:11434
```

ou na URL definida em `OLLAMA_BASE_URL`.

Para ativar DuckDB real na camada analitica, instale a extensao `pdo_duckdb` no PHP usado pelo servidor e confirme que `PDO::getAvailableDrivers()` lista `duckdb`. Sem essa extensao, os mesmos endpoints continuam funcionando com `sqlite-fallback`. Na primeira consulta com agregacoes, o DuckDB pode baixar extensoes oficiais para `storage/analytics/extensions`.

## 18. Arquivo `.env.example`

O projeto possui um `.env.example` com as variaveis esperadas:

```env
OLLAMA_BASE_URL=http://localhost:11434
DEFAULT_SYSTEM_PROMPT="Você é um assistente técnico, analítico e pragmático. Entenda a intenção da solicitação antes de responder. Priorize clareza, precisão e objetividade. Explique trade-offs quando existirem, não faça suposições sem evidências e deixe explícitas as incertezas quando necessário. Adapte a profundidade e a linguagem ao contexto e ao nível técnico do usuário."
CONTEXT_TOKEN_LIMIT=8192
OLLAMA_CONNECT_TIMEOUT=10
OLLAMA_RESPONSE_TIMEOUT=180
MODEL_METADATA_CACHE_TTL=3600
```

Observacao: o codigo atual le variaveis do ambiente com `getenv()`. Ele nao carrega automaticamente um arquivo `.env`.

## 19. Checklist funcional do estado atual

- [x] Carrega interface principal do chat.
- [x] Cria chat automaticamente quando nao existe `chat_id`.
- [x] Cria nova conversa.
- [x] Lista modelos locais do Ollama.
- [x] Seleciona modelo para a proxima mensagem.
- [x] Mostra metadados do modelo.
- [x] Envia prompt para Ollama.
- [x] Recebe resposta em streaming.
- [x] Renderiza Markdown.
- [x] Destaca blocos de codigo.
- [x] Sanitiza HTML renderizado.
- [x] Persiste mensagens no SQLite.
- [x] Busca conversas por titulo ou conteudo das mensagens.
- [x] Organiza conversas por workspace.
- [x] Gera titulo curto automaticamente para conversas novas.
- [x] Calcula uso estimado de contexto.
- [x] Remove mensagens antigas quando excede limite.
- [x] Reseta contexto em erro especifico de janela do Ollama.
- [x] Lista personas.
- [x] Seleciona persona por chat.
- [x] Cria persona.
- [x] Edita persona.
- [x] Exclui persona, com protecao para fallback.
- [x] Gera system prompt YAML para persona a partir de nome e descricao.
- [x] Exibe toast de troca de persona.
- [x] Copia resposta do assistente.
- [x] Reusa pergunta do usuario.
- [x] Exporta conversa em Markdown.
- [x] Exporta conversa em PDF.
- [x] Possui layout responsivo basico.
- [x] Indexa documentos de texto para RAG local.
- [x] Usa documentos indexados como contexto opcional no chat.
- [x] Isola documentos RAG por workspace.
- [x] Gerencia documentos adicionados com metadados e exclusao em cascata.
- [x] Exibe a Central de Documentacao pelo menu principal.
- [x] Ativa/desativa plugins por sessao.
- [x] Renderiza graficos via plugin `data_analyst` a partir de tabelas Markdown e botao local.
- [x] Inspeciona arquivos JSON/CSV e sugere consultas analiticas por chips.
- [x] Registra CSV/JSON como datasets locais por workspace e executa `SELECT` para gerar payload de grafico estruturado.

## 20. Resumo executivo

O Olliverse, no estado atual, e um cliente local de IA para Ollama com base solida para evoluir para uma ferramenta pessoal mais completa.

O projeto ja deixou de ser apenas um `index.php` com chat simples. Hoje ele tem:

- arquitetura PHP modular;
- persistencia SQLite;
- controle de contexto;
- streaming robusto;
- frontend separado por responsabilidade;
- personas como camada de comportamento;
- contratos claros entre backend e frontend.

O proximo salto natural e criar uma experiencia de historico: listagem de chats, busca, arquivamento e exportacao. Essa etapa aproveitaria diretamente as tabelas e titulos que ja existem no banco.

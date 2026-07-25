# Olliverse

**Olliverse** e uma central local de conversa com IA para trabalhar com modelos do
Ollama, historico persistente, personas, documentos, analise de dados e
exportacao em um unico ambiente privado.

O projeto foi pensado para quem quer usar IA local de forma produtiva, sem
depender de uma plataforma externa para cada conversa. Ele funciona como um
cliente web local para LLMs, mas com recursos de organizacao e contexto que
aproximam a experiencia de uma ferramenta de trabalho completa.

## Principais recursos

- Chat com modelos locais via Ollama.
- Provedor experimental Web AI no navegador, quando disponivel.
- Historico persistente em SQLite.
- Workspaces para separar conversas e documentos por contexto.
- Personas reutilizaveis para adaptar comportamento, tom e objetivo da IA.
- Consulta a documentos com recuperacao de contexto.
- Plugin analitico para gerar insights, tabelas e graficos.
- Exportacao de conversas em Markdown e PDF.
- Interface web compacta em PHP, JavaScript vanilla e CSS proprio.
- Dependencias de frontend vendorizadas localmente, sem CDNs para os bundles ativos.

## Stack

- PHP `>= 8.2`.
- PHP puro, sem framework.
- Composer com autoload PSR-4 para `App\`.
- SQLite via PDO.
- Ollama como provedor principal de IA.
- JavaScript vanilla modularizado.
- CSS proprio em `public/assets/css/app.css`.
- Bibliotecas locais em `public/vendor/`.

## Estrutura geral

```text
.
├── app
├── bootstrap
├── Doc
├── IA
├── plugins
├── public
├── storage
├── views
├── composer.json
└── index.php
```

O arquivo `index.php` permanece como ponto de entrada da aplicacao. A
inicializacao fica em `bootstrap/app.php`, e a logica principal foi separada em
classes dentro de `app/`.

## Requisitos

- PHP 8.2 ou superior.
- Composer.
- Ollama instalado e em execucao para uso do provedor principal.
- Pelo menos um modelo disponivel no Ollama.

Exemplo:

```bash
ollama pull llama3.2
ollama serve
```

## Como rodar localmente

Instale as dependencias PHP:

```bash
composer install
```

Inicie o servidor local na raiz do projeto:

```bash
php -S localhost:8000
```

Acesse:

```text
http://localhost:8000
```

Por padrao, o Olliverse tenta conversar com o Ollama em:

```text
http://localhost:11434
```

## Configuracao

As principais variaveis de ambiente suportadas sao:

| Variavel | Padrao | Finalidade |
|---|---|---|
| `OLLAMA_BASE_URL` | `http://localhost:11434` | URL base do Ollama |
| `SQLITE_DATABASE_PATH` | `storage/database.sqlite` | Caminho do banco SQLite |
| `CONTEXT_TOKEN_LIMIT` | `8192` | Limite estimado de contexto |
| `OLLAMA_CONNECT_TIMEOUT` | `10` | Timeout de conexao |
| `OLLAMA_RESPONSE_TIMEOUT` | `180` | Timeout de resposta |
| `RAG_EMBEDDING_MODEL` | `nomic-embed-text` | Modelo usado para embeddings |

O banco SQLite e criado automaticamente em `storage/database.sqlite` quando nao
existir, e as migracoes rodam durante o bootstrap da aplicacao.

## Documentacao

A documentacao funcional e tecnica completa fica em:

```text
Doc/README.md
```

Ha tambem uma visao de produto em:

```text
Doc/Produto.md
```

## Licenca

Este projeto usa licenca proprietaria. Consulte `LICENSE`.

# Especificação Técnica de Funcionalidade (Spec): Suporte a Leitura e Indexação de Arquivos `.xlsx` Mantendo o Pipeline Atual

## 1. Visão Geral

Esta especificação define a integração do suporte a arquivos do tipo `.xlsx` (planilhas do Microsoft Excel) no fluxo de upload e indexação do **Olliverse**. O propósito é permitir que documentos em formato de planilha sejam processados, convertidos e vetorizados sem a necessidade de alterar o motor de backend atual, mantendo intacta a compatibilidade com os formatos textuais já suportados (`.txt`, `.csv`, `.json`).

---

## 2. Objetivos e Requisitos

* **Retrocompatibilidade Absoluta:** Garantir que o pipeline existente para arquivos de texto puro continue operando exatamente da mesma forma, sem regressões.
* **Processamento na Borda (Client-Side Parsing):** Realizar a descompactação e leitura do binário do arquivo Excel diretamente no navegador do usuário utilizando bibliotecas de extração dedicadas.
* **Normalização de Entrada:** Converter o conteúdo estruturado da planilha em uma representação textual tabular linear (como formato baseado em texto/CSV), injetando o resultado no fluxo padrão de envio para vetorização do Olliverse.

---

## 3. Arquitetura da Solução

### 3.1. Estrutura de Camadas e Separação de Responsabilidades

* **Camada de Interface / Client-Side:**
* Responsável por interceptar a seleção de arquivos por meio do componente de *file upload*.
* Valida a extensão do arquivo enviado. Se identificado como `.xlsx` ou `.xls`, o arquivo é lido como um buffer binário e processado por um parser especializado no navegador.
* Transforma as planilhas em texto puro estruturado (linhas e colunas delimitadas).


* **Camada de Backend / Ingestão:**
* Continua recebendo exclusivamente um payload de texto plano padronizado, independentemente se a origem era um arquivo de texto comum ou uma planilha convertida.
* Preserva a lógica de *embeddings* e indexação no banco vetorial sem modificações estruturais no servidor.



---

## 4. Comportamento Esperado do Fluxo

1. **Seleção do Arquivo:** O usuário anexa um arquivo através da interface do Olliverse.
2. **Inspeção de Tipo (`Mime-Type` / Extensão):**
* Se for `.txt`, `.csv` ou `.json`: O arquivo segue pelo fluxo tradicional de leitura direta de texto.
* Se for `.xlsx` ou `.xls`: O arquivo é direcionado para o conversor de planilhas no navegador, que lê a primeira aba (ou todas as abas, conforme configurado) e extrai o conteúdo tabulado em formato de texto.


3. **Normalização:** O texto extraído recebe um cabeçalho descritivo opcional indicando a origem e o nome da aba da planilha, preservando o contexto dos dados.
4. **Envio para Indexação:** O conteúdo final convertido é despachado para o endpoint de vetorização do Olliverse, integrando-se perfeitamente com o motor de busca e recuperação de conhecimento.

---

## 5. Critérios de Aceite

1. **Preservação do Pipeline:** Arquivos `.txt`, `.csv` e `.json` continuam sendo processados sem nenhuma alteração ou impacto lateral.
2. **Suporte a Planilhas:** O upload de arquivos `.xlsx` é tratado de forma transparente, convertendo o formato binário fechado em texto estruturado legível pelo motor de vetorização.
3. **Isolamento de Processamento:** O backend do Olliverse permanece isento de bibliotecas pesadas de manipulação de planilhas, delegando a conversão de formato inteiramente para a borda (client-side).
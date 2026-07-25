# Plano de Melhorias: Integrando DuckDB ao Olliverse

Este plano estratégico detalha como incorporar o **DuckDB** no motor de processamento do **Olliverse** para transformar a manipulação de dados locais (CSVs, JSONs, planilhas) em uma operação extremamente rápida, precisa e sem erros de gráficos.

---

## 1. Fases do Plano de Implementação

### Fase 1: Arquitetura e Camada de Conexão (Backend PHP/FFI)

* **Objetivo:** Estabelecer a infraestrutura do DuckDB rodando em segundo plano de forma nativa e leve.
* **Ações:**
* Integrar a biblioteca via FFI (`satur.io/duckdb-auto`) ou driver PDO no backend de processamento de arquivos.
* Criar um serviço gerenciador de instâncias in-memory ou baseadas em arquivos locais temporários por Workspace.



### Fase 2: Pipeline de Ingestão "Zero ETL" (Leitura Direta)

* **Objetivo:** Permitir que arquivos enviados para o Workspace (CSVs, JSONs, Parquet) sejam consultados instantaneamente sem carregar dados brutos para o contexto da IA.
* **Ações:**
* Mapear automaticamente arquivos arrastados para o chat como tabelas virtuais no DuckDB.
* Permitir queries analíticas diretas (`SELECT`, `SUM`, `GROUP BY`, `ORDER BY`) estruturadas antes de qualquer envio de prompt.



### Fase 3: Camada de Resolução de Gráficos (Anti-Inversão de Eixos)

* **Objetivo:** Acabar definitivamente com erros de renderização visual (como inversão de colunas em gráficos de barra).
* **Ações:**
* Obrigar que o motor de gráficos receba dados estritamente do retorno de uma query estruturada do DuckDB.
* Padronizar o payload JSON enviado ao front-end separando explicitamente as chaves de eixo X e Y.



---

## 2. Especificação Técnica (Spec)

# Especificação Técnica: Integração do DuckDB para Análise de Dados no Olliverse

## 1. Visão Geral

Esta especificação define a arquitetura para incorporar o DuckDB como motor analítico local no Olliverse. O objetivo é descarregar o processamento pesado de arquivos tabulares do modelo de linguagem (LLM) para um banco de dados analítico vetorial leve, garantindo alta performance no Mac M1 e eliminando falhas de plotagem de gráficos por inversão de eixos.

---

## 2. Requisitos Funcionais

* **RF01 - Criação de Tabelas Virtuais por Workspace:** O sistema deve registrar automaticamente arquivos estruturados (`.csv`, `.json`, `.parquet`) adicionados ao workspace como tabelas temporárias no DuckDB.
* **RF02 - Execução de Consultas Analíticas Prévias:** Antes de gerar visualizações ou responder a perguntas complexas sobre dados, o Olliverse deve executar uma query SQL preparatória para agregar e estruturar os dados.
* **RF03 - Payload Estruturado para Gráficos:** O resultado das consultas do DuckDB deve ser serializado em um formato JSON rígido contendo chaves explícitas para eixo X e eixo Y, prevenindo erros de renderização no front-end.
* **RF04 - Otimização de Memória:** O processamento deve ocorrer inteiramente na camada analítica do DuckDB, mantendo a janela de contexto do LLM livre para receber apenas os resumos necessários.

---

## 3. Arquitetura de Integração

### 3.1. Serviço de Conexão (Backend)

O backend gerenciará a conexão local utilizando o driver compatível com o ambiente PHP:

```php
use Satur\IO\DuckDB\DuckDB;

class WorkspaceAnalyticsService {
    private DuckDB $db;

    public function __construct(string $workspaceId) {
        // Inicializa uma instância isolada por workspace
        $this->db = DuckDB::create(":memory:");
    }

    public function registerFile(string $tableName, string $filePath): void {
        $this->db->query("CREATE TABLE {$tableName} AS SELECT * FROM read_csv_auto('{$filePath}');");
    }

    public function query(string $sql): array {
        $result = $this->db->query($sql);
        return $result->toArray();
    }
}

```

### 3.2. Contrato de Payload para o Front-end (Gráficos)

Para evitar que a IA invente a ordem dos eixos, o componente de gráficos receberá um JSON validado pelo DuckDB:

```json
{
  "status": "success",
  "query_executed": "SELECT ano, salario FROM salarios ORDER BY ano ASC",
  "chart_config": {
    "type": "bar",
    "labels": [2020, 2023, 2024],
    "datasets": [
      {
        "label": "Salário (R$)",
        "data": [2000.00, 4000.00, 6700.00]
      }
    ]
  }
}

```

---

## 4. Benefícios de Performance no Mac M1 (8GB)

* **Baixo Footprint de RAM:** O DuckDB executa operações analíticas vetorizadas em C++ sem inflar o consumo de memória unificada do sistema.
* **Precisão Absoluta:** O LLM atua apenas como orquestrador e gerador de SQL/narrativa, enquanto a matemática e a estruturação de dados ficam a cargo do motor relacional.
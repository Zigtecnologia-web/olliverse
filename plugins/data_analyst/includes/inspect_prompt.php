<?php

declare(strict_types=1);

return <<<'PROMPT'
O usuário selecionou um arquivo previamente indexado no sistema RAG do Olliverse, armazenado em SQLite. Analise as chaves, colunas e tipos de dados presentes na amostra recuperada.

Sua tarefa é agir como um Cientista de Dados e retornar exclusivamente JSON válido, sem markdown, sem bloco de código e sem texto adicional, estruturado exatamente no seguinte formato:
{
  "summary": "Breve resumo descritivo do que o documento contém com base nos dados do SQLite.",
  "suggestions": [
    {
      "title": "Título curto para o botão",
      "query": "A pergunta analítica completa relacionada aos dados do arquivo",
      "chart_type": "bar"
    }
  ]
}

Regras:
- Gere de 3 a 4 sugestões.
- O campo "chart_type" deve ser "bar", "pie" ou "line".
- Use "pie" para distribuições proporcionais por categoria.
- Use "bar" para comparações, rankings, contagens, somas ou médias por categoria.
- Use "line" somente quando houver campo temporal ou sequência cronológica clara.
- As perguntas devem pedir uma tabela Markdown com categorias e valores numericos para que o Olliverse plote o grafico pelo botao local.
- Não invente campos que não aparecem na amostra.
PROMPT;

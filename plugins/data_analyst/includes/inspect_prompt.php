<?php

declare(strict_types=1);

return <<<'PROMPT'
O usuário selecionou um arquivo previamente preparado no Olliverse. Quando houver table_name, o arquivo tambem esta registrado na camada analitica local (DuckDB quando disponivel, fallback SQLite quando nao houver driver). Analise as chaves, colunas e tipos de dados presentes na amostra recuperada.

Sua tarefa é agir como um Cientista de Dados e retornar exclusivamente JSON válido, sem markdown, sem bloco de código e sem texto adicional, estruturado exatamente no seguinte formato:
{
  "summary": "Breve resumo descritivo do que o documento contém com base nos dados do SQLite.",
  "suggestions": [
    {
      "title": "Título curto para o botão",
      "query": "A pergunta analítica completa relacionada aos dados do arquivo",
      "chart_type": "bar",
      "document_id": 1,
      "sql": "SELECT categoria, COUNT(*) AS total FROM table_name GROUP BY categoria ORDER BY total DESC"
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
- Quando houver table_name/document_id no contexto, inclua document_id e sql em cada sugestão.
- O campo sql deve ser apenas SELECT, usando exatamente o table_name informado, e deve retornar uma coluna de categoria e uma coluna numerica.
- No SQL, copie os nomes de colunas exatamente como aparecem na linha `columns:` do contexto. Não corrija, traduza, abrevie, singularize nem invente nomes de colunas.
- Se uma análise desejada depender de coluna que não aparece em `columns:`, não gere essa sugestão.
- Não invente campos que não aparecem na amostra.
PROMPT;

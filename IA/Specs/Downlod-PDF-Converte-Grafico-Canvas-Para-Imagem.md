# Especificação Técnica de Funcionalidade (Spec): Exportação de Relatórios Completos em PDF/Markdown Combinando Chat e Gráficos

## 1. Visão Geral e Objetivo

Esta especificação detalha a evolução do motor de exportação do **Olliverse** para suportar a consolidação de elementos visuais gerados pelo plugin analítico de dados. O objetivo é permitir que gráficos interativos (`json-chart`), insights do RAG e o histórico de mensagens sejam capturados no frontend e embutidos de forma elegante em um relatório unificado nos formatos PDF e Markdown.

---

## 2. Requisitos de Experiência do Usuário (UX) e Comportamento

### 2.1. Acionamento da Exportação Avançada

* **Ponto de Entrada:** Utilizar os botões de exportação existentes no cabeçalho ou menu de ações da conversa, adaptando-os para incluir o conteúdo analítico quando houver gráficos ou insights ativos na sessão.
* **Feedback de Processamento:** Durante a exportação para PDF (que exige a conversão de elementos visuais do canvas para imagem), exibir um indicador de carregamento discreto informando que o relatório analítico está sendo compilado.

### 2.2. Composição do Relatório Exportado

* **Conversa e Contexto:** Manter o histórico estruturado de perguntas e respostas da IA formatado corretamente via Parsedown.
* **Incorporação de Gráficos:** Converter os gráficos renderizados pelo Chart.js (canvas) em representações de imagem estáticas (base64) no lado do cliente, injetando-os no HTML do template PDF exatamente no lugar onde o gráfico interativo aparecia na tela.
* **Insights e Dados:** Preservar os resumos analíticos e metadados contextuais recuperados pelo RAG para dar robustez ao relatório final.

---

## 3. Especificação de Arquitetura e Fluxo de Dados

### 3.1. Captura Client-Side (Frontend)

1. Quando o usuário clica no botão de exportar PDF, o script intercepta a ação antes de submeter o formulário ou requisição.
2. O sistema varre os blocos de gráficos ativos na tela (`json-chart` / instâncias Chart.js).
3. Cada elemento `<canvas>` é convertido em string PNG no formato base64 utilizando `canvas.toDataURL('image/png')`.
4. Os dados em base64 são enviados via payload HTTP (POST) ou anexados temporariamente para que o backend receba a estrutura dos gráficos associada ao chat.

### 3.2. Processamento Backend e Renderização PDF

* **Atualização do Endpoint:** `GET /index.php?action=export_pdf&chat_id=ID` passa a aceitar ou processar os dados visuais complementares.
* **Template Atualizado (`views/pdf/chat_template.php`):** O template HTML consumido pelo Dompdf é ajustado para renderizar tags `<img>` apontando para as imagens em base64 dos gráficos, posicionando-as de forma responsiva junto às mensagens correspondentes.

---

## 4. Critérios de Aceite

1. **Fidelidade Visual:** Os gráficos interativos gerados pelo plugin de análise de dados aparecem como imagens estáticas nítidas e bem posicionadas no documento PDF exportado.
2. **Preservação do Pipeline Existente:** A exportação padrão para Markdown e conversões textuais simples continuam operando normalmente sem regressions caso a conversa não possua elementos gráficos.
3. **Robustez de Entrega:** O arquivo PDF gerado consolida chat, insights e gráficos em um único documento limpo e pronto para compartilhamento executivo.
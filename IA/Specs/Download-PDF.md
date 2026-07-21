Esta especificação detalha a implementação do recurso de **Exportação Multi-formato (Markdown e PDF)** no **Olliverse**, garantindo portabilidade e um design profissional alinhado à identidade visual da aplicação.

---

# 📋 Spec.md: Exportação de Conversas (Markdown & PDF)

## 1. Visão Geral

Adicionar um menu de exportação no header do chat para permitir que o usuário salve o histórico em dois formatos distintos:

* **Markdown (.md):** Para portabilidade de dados e edição em ferramentas de conhecimento (Obsidian/Notion).
* **PDF (.pdf):** Para relatórios e documentos, utilizando o `dompdf` com um layout elegante e consistente com o tema escuro/verde do Olliverse.

## 2. Interface do Usuário (UI)

* **Menu de Exportação:** Adicionar um botão "Exportar" no header. Ao clicar, um dropdown exibe:
* `[Icon] Exportar como .md`
* `[Icon] Exportar como .pdf`


* **Feedback:** Durante a geração do PDF, exibir um *loader* ou desabilitar o botão para evitar múltiplas requisições.

## 3. Especificação Técnica: Markdown

* **Endpoint:** `GET /index.php?action=export_md&chat_id={id}`
* **Processamento:** O backend recupera a coleção de mensagens, aplica um formatador simples para converter o histórico em texto plano com sintaxe Markdown e força o download através dos headers `Content-Disposition`.

## 4. Especificação Técnica: PDF (Dompdf)

### 4.1 Biblioteca

* **Dependência:** `dompdf/dompdf` (via Composer).
* **Conversão de Conteúdo:** Utilizar a biblioteca `Parsedown` para converter o Markdown das mensagens em HTML antes de injetar no template.

### 4.2 Template PDF (`views/pdf/chat_template.php`)

O template seguirá a identidade visual do Olliverse:

* **Paleta:** Uso de tons de cinza escuro para texto e verde (#2ecc71 ou similar) para detalhes/títulos.
* **Tipografia:** Fontes legíveis (ex: Helvetica/Arial).
* **Blocos de Código:** Estilizados com fundo cinza claro (`#f4f4f4`), bordas levemente arredondadas e quebra de linha forçada para evitar estouro de margem.

### 4.3 Implementação Backend (`PdfExportService`)

```php
// Lógica de Renderização
$parsedown = new Parsedown();
$htmlContent = "";
foreach ($messages as $msg) {
    $content = $parsedown->text($msg['content']);
    $htmlContent .= "<div class='message {$msg['role']}'>{$content}</div>";
}
// Carregar template e renderizar

```

## 5. Fluxo de Dados

1. **Requisição:** Usuário clica em "Exportar .pdf".
2. **Processamento:**
* Sistema busca mensagens no banco.
* Markdown é processado para HTML.
* Template base (CSS + Estrutura) é populado.


3. **Dompdf:** Converte o HTML em PDF com configurações de papel A4.
4. **Entrega:** Servidor envia o PDF como *download stream*.

## 6. CSS para PDF (Media Query / Estilo)

O CSS para o PDF deve focar em:

* **Quebra de página:** Evitar que blocos de código ou mensagens sejam cortados indevidamente entre páginas (`page-break-inside: avoid;`).
* **Espaçamento:** Margens generosas (pelo menos 20mm) para leitura confortável.
* **Código:** Aplicar `white-space: pre-wrap;` para garantir que linhas longas de código não extrapolem o layout lateral.

---

### Dica de Engenharia:

Como o `dompdf` pode ser sensível a CSS moderno (Flexbox/Grid), mantenha o layout do template PDF o mais simples possível, utilizando `divs` e `floats` ou tabelas, caso necessário, para garantir que o renderizador não falhe em layouts complexos.

**Como você deseja gerenciar as dependências? Gostaria que eu preparasse o comando de instalação do Composer para o `dompdf` e `parsedown`?**
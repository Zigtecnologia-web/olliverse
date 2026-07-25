<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentacao - Olliverse</title>
    <link rel="icon" type="image/svg+xml" href="public/assets/img/favicon.svg">
    <link rel="stylesheet" href="public/assets/css/app.css">
</head>
<body class="documentation-body">

<main class="documentation-shell">
    <header class="documentation-header">
        <div>
            <span class="olliverse-logo documentation-logo" aria-label="Olliverse">
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <circle cx="12" cy="12" r="10" stroke="#00F576" stroke-width="2.5" stroke-dasharray="4 2"/>
                    <circle cx="12" cy="12" r="4" fill="#00F576"/>
                </svg>
                <span class="olliverse-logo-word"><span class="olliverse-logo-olli">Olli</span>verse</span>
            </span>
            <h1>Central de Documentacao</h1>
        </div>
        <a class="secondary-config-btn documentation-back-link" href="index.php<?php echo $returnChatId > 0 ? '?chat_id=' . (int) $returnChatId : ''; ?>">Voltar ao chat</a>
    </header>

    <?php
        $docsBaseQuery = 'index.php?view=docs' . ($returnChatId > 0 ? '&chat_id=' . (int) $returnChatId : '');
        $documentationMode = $documentationMode ?? 'produto';
        $productActive = $documentationMode === 'produto';
    ?>
    <nav class="documentation-tabs" aria-label="Tipo de documentacao">
        <a class="<?php echo $productActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($docsBaseQuery . '&doc=produto', ENT_QUOTES, 'UTF-8'); ?>" aria-current="<?php echo $productActive ? 'page' : 'false'; ?>">Produto</a>
        <a class="<?php echo !$productActive ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($docsBaseQuery . '&doc=tecnico', ENT_QUOTES, 'UTF-8'); ?>" aria-current="<?php echo !$productActive ? 'page' : 'false'; ?>">Tecnico</a>
    </nav>

    <div class="documentation-layout">
        <aside class="documentation-nav" aria-label="Secoes da documentacao">
            <?php if ($productActive): ?>
                <a href="#ia-local-com-contexto-memoria-e-controle">Visao de produto</a>
                <a href="#por-que-usar">Por que usar</a>
                <a href="#funcionalidades-que-fazem-diferenca">Funcionalidades</a>
                <a href="#para-quem-serve">Para quem serve</a>
                <a href="#diferenciais">Diferenciais</a>
            <?php else: ?>
                <a href="#1-visao-geral">Visao geral</a>
                <a href="#8-funcionalidades-atuais">Funcionalidades</a>
                <a href="#9-contratos-http-atuais">Contratos HTTP</a>
                <a href="#10-modelo-de-dados">Modelo de dados</a>
                <a href="#11-principais-classes-e-responsabilidades">Classes</a>
                <a href="#17-como-executar-localmente">Execucao local</a>
            <?php endif; ?>
        </aside>
        <article class="documentation-content <?php echo $productActive ? 'product-documentation' : 'technical-documentation'; ?>">
            <?php echo $documentationHtml; ?>
        </article>
    </div>
</main>

</body>
</html>

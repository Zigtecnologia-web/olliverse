<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentacao - Olliverse</title>
    <link rel="stylesheet" href="public/assets/css/app.css">
</head>
<body class="documentation-body">

<main class="documentation-shell">
    <header class="documentation-header">
        <div>
            <span class="documentation-kicker">Olliverse</span>
            <h1>Central de Documentacao</h1>
        </div>
        <a class="secondary-config-btn documentation-back-link" href="index.php<?php echo $returnChatId > 0 ? '?chat_id=' . (int) $returnChatId : ''; ?>">Voltar ao chat</a>
    </header>

    <div class="documentation-layout">
        <aside class="documentation-nav" aria-label="Secoes da documentacao">
            <a href="#1-visao-geral">Visao geral</a>
            <a href="#8-funcionalidades-atuais">Funcionalidades</a>
            <a href="#9-contratos-http-atuais">Contratos HTTP</a>
            <a href="#10-modelo-de-dados">Modelo de dados</a>
            <a href="#11-principais-classes-e-responsabilidades">Classes</a>
            <a href="#17-como-executar-localmente">Execucao local</a>
        </aside>
        <article class="documentation-content">
            <?php echo $documentationHtml; ?>
        </article>
    </div>
</main>

</body>
</html>

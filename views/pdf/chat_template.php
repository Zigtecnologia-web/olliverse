<?php

declare(strict_types=1);

/**
 * @var array<string, mixed> $chat
 * @var array<int, array{role: string, label: string, html: string}> $messages
 */

$title = (string) ($chat['title'] ?: 'Nova conversa');
$createdAt = (string) ($chat['created_at'] ?? '');
$updatedAt = (string) ($chat['updated_at'] ?? '');
$model = (string) ($chat['model_used'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 20mm;
        }

        body {
            color: #2f3437;
            font-family: Helvetica, Arial, sans-serif;
            font-size: 12px;
            line-height: 1.55;
        }

        .header {
            border-bottom: 2px solid #2ecc71;
            margin-bottom: 22px;
            padding-bottom: 14px;
        }

        h1 {
            color: #15171a;
            font-size: 23px;
            font-weight: 700;
            margin: 0 0 8px;
        }

        .meta {
            color: #5f666b;
            font-size: 10px;
        }

        .message {
            border-left: 4px solid #d6d9dc;
            margin: 0 0 16px;
            padding: 10px 13px;
            page-break-inside: avoid;
        }

        .message.user {
            border-left-color: #2ecc71;
            background: #f4fbf7;
        }

        .message.assistant {
            border-left-color: #3f3f46;
            background: #f7f7f8;
        }

        .role {
            color: #15171a;
            display: block;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        p {
            margin: 0 0 8px;
        }

        ul,
        ol {
            margin: 0 0 8px 18px;
            padding: 0;
        }

        pre {
            background: #f4f4f4;
            border: 1px solid #dddddd;
            border-radius: 4px;
            color: #232629;
            font-family: Courier, monospace;
            font-size: 10px;
            line-height: 1.45;
            margin: 8px 0;
            padding: 9px;
            white-space: pre-wrap;
            word-break: break-word;
        }

        code {
            background: #eeeeee;
            border-radius: 3px;
            font-family: Courier, monospace;
            font-size: 10px;
            padding: 1px 3px;
        }

        pre code {
            background: transparent;
            padding: 0;
        }

        blockquote {
            border-left: 3px solid #2ecc71;
            color: #4b5358;
            margin: 8px 0;
            padding-left: 10px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
        <div class="meta">
            Criada em <?php echo htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8'); ?>
            <?php if ($updatedAt !== ''): ?>
                | Atualizada em <?php echo htmlspecialchars($updatedAt, ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
            <?php if ($model !== ''): ?>
                | Modelo <?php echo htmlspecialchars($model, ENT_QUOTES, 'UTF-8'); ?>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($messages as $message): ?>
        <div class="message <?php echo htmlspecialchars($message['role'], ENT_QUOTES, 'UTF-8'); ?>">
            <span class="role"><?php echo htmlspecialchars($message['label'], ENT_QUOTES, 'UTF-8'); ?></span>
            <?php echo $message['html']; ?>
        </div>
    <?php endforeach; ?>
</body>
</html>

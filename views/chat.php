<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Olliverse</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js/styles/github-dark.min.css">
    <link rel="stylesheet" href="public/assets/css/app.css">
</head>
<body>

<div class="chat-container">
    <div class="chat-header">
        <div class="header-title">
            <h1>Olliverse</h1>
            <div class="model-controls">
                <div class="model-field">
                    <label for="modelMenuButton">Modelo</label>
                    <input type="hidden" id="modelSelect" value="<?php echo htmlspecialchars($defaultModel, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="model-picker" id="modelPicker">
                        <button type="button" id="modelMenuButton" class="model-menu-button" <?php echo $availableModels ? '' : 'disabled'; ?>>
                            <span id="selectedModelLabel"><?php echo htmlspecialchars($availableModels ? $defaultModel : 'Nenhum modelo encontrado', ENT_QUOTES, 'UTF-8'); ?></span>
                        </button>
                        <div id="modelMenuList" class="model-menu-list" role="listbox" aria-labelledby="modelMenuButton">
                        <?php if ($availableModels): ?>
                            <?php foreach ($availableModels as $modelName): ?>
                                <button type="button" class="model-option <?php echo $modelName === $defaultModel ? 'active' : ''; ?>" role="option" data-model="<?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?>" aria-selected="<?php echo $modelName === $defaultModel ? 'true' : 'false'; ?>">
                                    <?php echo htmlspecialchars($modelName, ENT_QUOTES, 'UTF-8'); ?>
                                </button>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <button type="button" class="model-option" disabled>Nenhum modelo encontrado</button>
                        <?php endif; ?>
                        </div>
                    </div>
                    <button type="button" id="modelInfoBtn" class="model-info-btn" aria-label="Informações do modelo" title="Informações do modelo" <?php echo $availableModels ? '' : 'disabled'; ?>><?php echo iconSvg('info'); ?></button>
                </div>
                <div class="persona-field">
                    <label for="personaSelect">Persona</label>
                    <select id="personaSelect" class="persona-select">
                        <?php foreach ($personas as $persona): ?>
                            <option value="<?php echo (int) $persona['id']; ?>" <?php echo (int) $persona['id'] === (int) $activePersona['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $persona['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="header-actions">
            <button type="button" id="settingsBtn" class="config-btn" aria-label="Biblioteca de personas" title="Biblioteca de personas"><?php echo iconSvg('settings'); ?></button>
            <button type="button" id="newChatBtn" class="new-chat-btn">+ Nova conversa</button>
        </div>
    </div>

    <div class="rag-panel">
        <div class="rag-panel-main">
            <div class="rag-toggle-field">
                <label class="toggle-switch" for="ragToggle">
                    <input type="checkbox" id="ragToggle">
                    <span class="toggle-track" aria-hidden="true"></span>
                    <span class="toggle-label">Usar documentos</span>
                </label>
            </div>
            <div id="ragDocumentList" class="rag-document-list" aria-live="polite"></div>
        </div>
        <form id="ragUploadForm" class="rag-upload-form" enctype="multipart/form-data">
            <input type="file" id="ragFileInput" name="document" class="rag-file-input" accept=".txt,.md,.php,.js,.css,.html,.json,.sql,text/*">
            <button type="button" id="ragPickFileBtn" class="secondary-config-btn">Adicionar documento</button>
        </form>
        <div id="ragStatus" class="rag-status" aria-live="polite"></div>
    </div>

    <div class="chat-messages" id="chatMessages">
    <?php if ($initialMessages === []): ?>
        <div class="message-group assistant">
            <div class="message assistant"><?php echo htmlspecialchars($initialAssistantMessage, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
    <?php else: ?>
        <?php foreach ($initialMessages as $message): ?>
            <?php $role = $message['role'] === 'user' ? 'user' : 'assistant'; ?>
            <div class="message-group <?php echo $role; ?>">
                <div class="message <?php echo $role; ?>">
                    <?php if ($role === 'user'): ?>
                        <span class="message-text"><?php echo htmlspecialchars($message['content'], ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php else: ?>
                        <div class="assistant-markdown-source" data-markdown-source="<?php echo htmlspecialchars(json_encode($message['content'], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo nl2br(htmlspecialchars($message['content'], ENT_QUOTES, 'UTF-8')); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>

    <div class="chat-input-area">
        <div class="context-status" aria-live="polite">
            <div class="context-bar" aria-hidden="true">
                <div id="contextBarFill" class="context-bar-fill"></div>
            </div>
            <span id="contextLabel" class="context-label">Contexto 0%</span>
        </div>
        <form class="input-form" id="chatForm">
            <input type="text" id="userInput" class="chat-input" placeholder="Escreva sua mensagem aqui..." autocomplete="off" required>
            <button type="submit" id="sendBtn" class="send-btn" aria-label="Enviar mensagem" title="Enviar mensagem"><?php echo iconeEnviar(); ?></button>
        </form>
    </div>
</div>

<div class="modal-backdrop" id="modelInfoModal" aria-hidden="true">
    <div class="skill-modal" role="dialog" aria-modal="true" aria-labelledby="modelInfoModalTitle">
        <div class="modal-header">
            <h2 id="modelInfoModalTitle">Modelo</h2>
            <button type="button" id="closeModelInfoModalBtn" class="modal-close-btn" aria-label="Fechar informações do modelo"><?php echo iconSvg('x'); ?></button>
        </div>
        <div class="modal-body">
            <div class="model-details-title" id="modelInfoTitle"><?php echo htmlspecialchars($availableModels ? $defaultModel : 'Sem modelo', ENT_QUOTES, 'UTF-8'); ?></div>
            <div class="model-details-status" id="modelInfoStatus">Carregando metadados...</div>
            <div class="model-details-grid" id="modelInfoGrid" hidden>
                <div class="model-details-item">
                    <span class="model-details-label">Tamanho</span>
                    <span class="model-details-value" id="modelInfoSize">-</span>
                </div>
                <div class="model-details-item">
                    <span class="model-details-label">Família</span>
                    <span class="model-details-value" id="modelInfoFamily">-</span>
                </div>
                <div class="model-details-item">
                    <span class="model-details-label">Contexto</span>
                    <span class="model-details-value" id="modelInfoContext">-</span>
                </div>
                <div class="model-details-item">
                    <span class="model-details-label">Quantização</span>
                    <span class="model-details-value" id="modelInfoQuantization">-</span>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal-backdrop" id="skillModal" aria-hidden="true">
    <div class="skill-modal" role="dialog" aria-modal="true" aria-labelledby="skillModalTitle">
        <div class="modal-header">
            <h2 id="skillModalTitle">Biblioteca de Personas</h2>
            <button type="button" id="closeSkillModalBtn" class="modal-close-btn" aria-label="Fechar biblioteca de personas"><?php echo iconSvg('x'); ?></button>
        </div>
        <form id="skillForm">
            <div class="modal-body">
                <div class="skill-field">
                    <label for="personaLibrarySelect">Persona</label>
                    <select id="personaLibrarySelect" class="skill-select"></select>
                </div>
                <div class="persona-library-actions">
                    <button type="button" id="newPersonaBtn" class="secondary-config-btn">Nova</button>
                    <button type="button" id="deletePersonaBtn" class="secondary-config-btn danger">Excluir</button>
                </div>
                <div class="skill-field">
                    <label for="personaNameInput">Nome</label>
                    <input type="text" id="personaNameInput" class="skill-input" name="name" required>
                </div>
                <div class="skill-field">
                    <label for="personaDescriptionInput">Descrição</label>
                    <input type="text" id="personaDescriptionInput" class="skill-input" name="description">
                </div>
                <div class="skill-field">
                    <label for="systemPromptInput">System prompt</label>
                    <textarea id="systemPromptInput" class="skill-textarea" name="prompt_content"><?php echo htmlspecialchars($systemPrompt, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div id="skillStatus" class="skill-status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="save-config-btn">Salvar Persona</button>
            </div>
        </form>
    </div>
</div>

<div id="personaToast" class="persona-toast" role="status" aria-live="polite"></div>

<script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/highlight.js/highlight.min.js"></script>
<script>
    window.OlliverseConfig = {
        initialAssistantMessage: <?php echo json_encode($initialAssistantMessage, JSON_UNESCAPED_UNICODE); ?>,
        chatId: <?php echo json_encode($chatId, JSON_UNESCAPED_UNICODE); ?>,
        hasAvailableModels: <?php echo json_encode((bool) $availableModels); ?>,
        initialContextUsage: <?php echo json_encode($initialContextUsage, JSON_UNESCAPED_UNICODE); ?>,
        initialRagDocuments: <?php echo json_encode($initialRagDocuments, JSON_UNESCAPED_UNICODE); ?>,
        personas: <?php echo json_encode($personas, JSON_UNESCAPED_UNICODE); ?>,
        activePersona: <?php echo json_encode($activePersona, JSON_UNESCAPED_UNICODE); ?>,
    };
    window.OlliverseState = {
        activeTooltipButton: null,
        modelMetadataCache: new Map(),
    };
</script>
	<script src="public/assets/js/model-panel.js"></script>
	<script src="public/assets/js/chat-renderer.js"></script>
	<script src="public/assets/js/chat-stream.js"></script>
	<script src="public/assets/js/message-ui.js"></script>
	<script src="public/assets/js/rag-panel.js"></script>
	<script src="public/assets/js/app.js"></script>

</body>
</html>

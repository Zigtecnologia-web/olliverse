<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Olliverse</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/highlight.js/styles/github-dark.min.css">
    <link rel="stylesheet" href="public/assets/css/app.css">
    <?php foreach ($activePlugins as $plugin): ?>
        <?php $pluginStyle = $plugin['assets']['style'] ?? null; ?>
        <?php if (is_string($pluginStyle) && $pluginStyle !== ''): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars($pluginStyle, ENT_QUOTES, 'UTF-8'); ?>" data-plugin-asset="<?php echo htmlspecialchars((string) $plugin['slug'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php endif; ?>
    <?php endforeach; ?>
</head>
<body>

<div class="app-shell" id="appShell">
<aside class="history-sidebar" id="historySidebar" aria-label="Histórico de conversas">
    <div class="history-sidebar-header">
        <div>
            <h2>Histórico</h2>
            <span id="historyCount" class="history-count">0 conversas</span>
        </div>
        <button type="button" id="historyCloseBtn" class="history-icon-btn" aria-label="Recolher histórico" title="Recolher histórico" data-tooltip="Recolher histórico"><?php echo iconSvg('sidebar'); ?></button>
    </div>
    <div class="history-search">
        <span class="history-search-icon" aria-hidden="true"><?php echo iconSvg('search'); ?></span>
        <input type="search" id="historySearchInput" class="history-search-input" placeholder="Buscar no histórico" autocomplete="off">
    </div>
    <div id="historyStatus" class="history-status" aria-live="polite"></div>
    <ul id="historyList" class="history-list"></ul>
</aside>

<div class="chat-container">
    <div class="chat-header">
        <div class="header-title">
            <button type="button" id="historyToggleBtn" class="config-btn history-toggle-btn" aria-label="Abrir histórico" title="Abrir histórico" data-tooltip="Abrir histórico"><?php echo iconSvg('sidebar'); ?></button>
            <h1>Olliverse</h1>
            <div class="model-controls">
                <div class="provider-field">
                    <label for="providerSelect">Motor</label>
                    <select id="providerSelect" class="provider-select">
                        <option value="ollama" selected>Ollama</option>
                        <option value="web_ai">Web AI</option>
                    </select>
                </div>
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
                    <button type="button" id="modelInfoBtn" class="model-info-btn" aria-label="Informações do modelo" title="Informações do modelo" data-tooltip="Informações do modelo" <?php echo $availableModels ? '' : 'disabled'; ?>><?php echo iconSvg('info'); ?></button>
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
            <a class="config-btn" href="index.php?view=docs&amp;chat_id=<?php echo (int) $chatId; ?>" aria-label="Central de documentação" title="Central de documentação" data-tooltip="Central de documentação"><?php echo iconSvg('book-open'); ?></a>
            <div class="plugins-menu" id="pluginsMenu">
                <button type="button" id="pluginsMenuBtn" class="config-btn" aria-label="Gerenciar plugins" title="Gerenciar plugins" data-tooltip="Gerenciar plugins" aria-expanded="false"><?php echo iconSvg('puzzle'); ?></button>
                <div class="plugins-menu-list" role="menu" aria-labelledby="pluginsMenuBtn">
                    <div class="plugins-menu-header">Plugins</div>
                    <?php if ($availablePlugins === []): ?>
                        <div class="plugins-menu-empty">Nenhum plugin encontrado</div>
                    <?php else: ?>
                        <?php foreach ($availablePlugins as $plugin): ?>
                            <?php $pluginSlug = (string) $plugin['slug']; ?>
                            <label class="plugin-toggle-item">
                                <input type="checkbox" data-plugin-toggle="<?php echo htmlspecialchars($pluginSlug, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $plugin['active'] ? 'checked' : ''; ?>>
                                <span class="toggle-track" aria-hidden="true"></span>
                                <span class="plugin-toggle-label">
                                    <span class="plugin-toggle-name"><?php echo htmlspecialchars((string) ($plugin['icon'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> <?php echo htmlspecialchars((string) ($plugin['name'] ?? $pluginSlug), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="plugin-toggle-description"><?php echo htmlspecialchars((string) ($plugin['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <div id="pluginsStatus" class="plugins-status" aria-live="polite"></div>
                </div>
            </div>
            <div class="export-menu" id="exportMenu">
                <button type="button" id="exportChatBtn" class="config-btn export-chat-btn" aria-label="Exportar conversa" title="Exportar conversa" data-tooltip="Exportar conversa" aria-expanded="false"><?php echo iconSvg('download'); ?></button>
                <div class="export-menu-list" role="menu" aria-labelledby="exportChatBtn">
                    <a id="exportMarkdownLink" class="export-menu-item" role="menuitem" href="index.php?action=export_md&amp;chat_id=<?php echo (int) $chatId; ?>"><?php echo iconSvg('file-text'); ?><span>Exportar como .md</span></a>
                    <a id="exportPdfLink" class="export-menu-item" role="menuitem" href="index.php?action=export_pdf&amp;chat_id=<?php echo (int) $chatId; ?>"><?php echo iconSvg('file'); ?><span>Exportar como .pdf</span></a>
                </div>
            </div>
            <button type="button" id="settingsBtn" class="config-btn" aria-label="Biblioteca de personas" title="Biblioteca de personas" data-tooltip="Biblioteca de personas"><?php echo iconSvg('settings'); ?></button>
            <button type="button" id="newChatBtn" class="new-chat-btn" aria-label="Nova conversa" title="Nova conversa" data-tooltip="Nova conversa"><?php echo iconSvg('plus'); ?></button>
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
            <form id="ragUploadForm" class="rag-upload-form" enctype="multipart/form-data">
                <input type="file" id="ragFileInput" name="document" class="rag-file-input" accept=".txt,.md,.php,.js,.css,.html,.json,.sql,text/*">
                <button type="button" id="ragPickFileBtn" class="secondary-config-btn rag-add-document-btn" aria-label="Adicionar documento" title="Adicionar documento" data-tooltip="Adicionar documento"><?php echo iconSvg('file-plus'); ?></button>
                <span id="ragStatus" class="rag-status" aria-live="polite"></span>
            </form>
            <div id="ragDocumentList" class="rag-document-list" aria-live="polite"></div>
        </div>
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
        <div id="webAiStatus" class="web-ai-run-status" role="status" aria-live="polite" hidden>
            <span class="web-ai-run-spinner" aria-hidden="true"></span>
            <span id="webAiStatusText"></span>
        </div>
        <form class="input-form" id="chatForm">
            <input type="text" id="userInput" class="chat-input" placeholder="Escreva sua mensagem aqui..." autocomplete="off" required>
            <button type="submit" id="sendBtn" class="send-btn" aria-label="Enviar mensagem" title="Enviar mensagem"><?php echo iconeEnviar(); ?></button>
        </form>
    </div>
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

<div class="modal-backdrop" id="deleteChatModal" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="deleteChatModalTitle">
        <div class="modal-header">
            <h2 id="deleteChatModalTitle">Deletar conversa</h2>
            <button type="button" id="closeDeleteChatModalBtn" class="modal-close-btn" aria-label="Fechar confirmação"><?php echo iconSvg('x'); ?></button>
        </div>
        <div class="modal-body">
            <p id="deleteChatModalText" class="confirm-modal-text">Deseja realmente deletar esta conversa?</p>
            <div id="deleteChatStatus" class="skill-status" aria-live="polite"></div>
        </div>
        <div class="modal-footer">
            <button type="button" id="cancelDeleteChatBtn" class="secondary-config-btn">Não</button>
            <button type="button" id="confirmDeleteChatBtn" class="save-config-btn danger">Sim, deletar</button>
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
                    <input type="text" id="personaNameInput" class="skill-input" name="name" placeholder="Ex.: Analista de Código" required>
                </div>
                <div class="skill-field">
                    <label for="personaDescriptionInput">Descrição</label>
                    <input type="text" id="personaDescriptionInput" class="skill-input" name="description" placeholder="Descreva o comportamento e objetivo da persona">
                </div>
                <div class="skill-field">
                    <div class="skill-field-header">
                        <label for="systemPromptInput">System prompt</label>
                        <button type="button" id="generatePromptBtn" class="generate-prompt-btn" aria-label="Gerar prompt com IA" title="Gerar prompt com IA" data-tooltip="Gerar prompt com IA" disabled>
                            <?php echo iconSvg('sparkles'); ?>
                            <span>Gerar</span>
                        </button>
                    </div>
                    <textarea id="systemPromptInput" class="skill-textarea" name="prompt_content" placeholder="O prompt em YAML será gerado aqui, ou escreva o seu manualmente"><?php echo htmlspecialchars($systemPrompt, ENT_QUOTES, 'UTF-8'); ?></textarea>
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
<?php foreach ($activePlugins as $plugin): ?>
    <?php foreach (($plugin['dependencies']['js'] ?? []) as $dependencySrc): ?>
        <script src="<?php echo htmlspecialchars((string) $dependencySrc, ENT_QUOTES, 'UTF-8'); ?>" data-plugin-asset="<?php echo htmlspecialchars((string) $plugin['slug'], ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php endforeach; ?>
    <?php $pluginScript = $plugin['assets']['script'] ?? null; ?>
    <?php if (is_string($pluginScript) && $pluginScript !== ''): ?>
        <script src="<?php echo htmlspecialchars($pluginScript, ENT_QUOTES, 'UTF-8'); ?>" data-plugin-asset="<?php echo htmlspecialchars((string) $plugin['slug'], ENT_QUOTES, 'UTF-8'); ?>"></script>
    <?php endif; ?>
<?php endforeach; ?>
<script>
    window.OlliverseConfig = {
        initialAssistantMessage: <?php echo json_encode($initialAssistantMessage, JSON_UNESCAPED_UNICODE); ?>,
        chatId: <?php echo json_encode($chatId, JSON_UNESCAPED_UNICODE); ?>,
        hasAvailableModels: <?php echo json_encode((bool) $availableModels); ?>,
        systemPrompt: <?php echo json_encode($systemPrompt, JSON_UNESCAPED_UNICODE); ?>,
        initialMessages: <?php echo json_encode($initialMessages, JSON_UNESCAPED_UNICODE); ?>,
        initialContextUsage: <?php echo json_encode($initialContextUsage, JSON_UNESCAPED_UNICODE); ?>,
        initialRagDocuments: <?php echo json_encode($initialRagDocuments, JSON_UNESCAPED_UNICODE); ?>,
        initialChatHistory: <?php echo json_encode($initialChatHistory, JSON_UNESCAPED_UNICODE); ?>,
        personas: <?php echo json_encode($personas, JSON_UNESCAPED_UNICODE); ?>,
        activePersona: <?php echo json_encode($activePersona, JSON_UNESCAPED_UNICODE); ?>,
        plugins: <?php echo json_encode($availablePlugins, JSON_UNESCAPED_UNICODE); ?>,
        activePlugins: <?php echo json_encode($activePlugins, JSON_UNESCAPED_UNICODE); ?>,
        activePluginPrompts: <?php echo json_encode($pluginManager->activePrompts(), JSON_UNESCAPED_UNICODE); ?>,
        webAi: {
            modelId: 'Llama-3.2-1B-Instruct-q4f16_1-MLC',
            contextTokenLimit: 3400,
            storageKey: 'olliverse_ai_provider',
        },
    };
    window.OlliverseState = {
        activeTooltipButton: null,
        historyOpen: true,
        historySearchTimer: null,
        historySearchQuery: '',
        historySearchChatIds: null,
        modelMetadataCache: new Map(),
    };
</script>
	<script src="public/assets/js/model-panel.js"></script>
	<script src="public/assets/js/chat-renderer.js"></script>
	<script src="public/assets/js/chat-stream.js"></script>
	<script src="public/assets/js/web-ai-provider.js"></script>
	<script src="public/assets/js/message-ui.js"></script>
	<script src="public/assets/js/history-panel.js"></script>
	<script src="public/assets/js/rag-panel.js"></script>
	<script src="public/assets/js/plugin-panel.js"></script>
	<script src="public/assets/js/app.js"></script>

</body>
</html>

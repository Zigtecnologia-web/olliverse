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
        </div>
        <div class="header-actions">
            <button type="button" id="settingsBtn" class="config-btn" aria-label="Configurações" title="Configurações"><?php echo iconSvg('settings'); ?></button>
            <button type="button" id="newChatBtn" class="new-chat-btn">+ Nova conversa</button>
        </div>
    </div>

    <div class="chat-messages" id="chatMessages">
        <div class="message assistant"><?php echo htmlspecialchars($initialAssistantMessage, ENT_QUOTES, 'UTF-8'); ?></div>
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
            <h2 id="skillModalTitle">Configurações</h2>
            <button type="button" id="closeSkillModalBtn" class="modal-close-btn" aria-label="Fechar configurações"><?php echo iconSvg('x'); ?></button>
        </div>
        <form id="skillForm">
            <div class="modal-body">
                <div class="skill-field">
                    <label for="skillPreset">Skill</label>
                    <select id="skillPreset" class="skill-select">
                        <option value="tecnico">Assistente técnico prestativo</option>
                        <option value="codigo">Assistente de Código</option>
                        <option value="escritor">Escritor</option>
                        <option value="dados">Analista de Dados</option>
                        <option value="custom">Personalizada</option>
                    </select>
                </div>
                <div class="skill-field">
                    <label for="systemPromptInput">System prompt</label>
                    <textarea id="systemPromptInput" class="skill-textarea" name="system_prompt"><?php echo htmlspecialchars($_SESSION['system_prompt'], ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>
                <div id="skillStatus" class="skill-status" aria-live="polite"></div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="save-config-btn">Salvar Configuração</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/highlight.js/highlight.min.js"></script>
<script>
    window.OlliverseConfig = {
        initialAssistantMessage: <?php echo json_encode($initialAssistantMessage, JSON_UNESCAPED_UNICODE); ?>,
        hasAvailableModels: <?php echo json_encode((bool) $availableModels); ?>,
        initialContextUsage: <?php echo json_encode($initialContextUsage, JSON_UNESCAPED_UNICODE); ?>,
        skillPresets: {
            tecnico: 'Você é um assistente técnico prestativo.',
            codigo: 'Você é um assistente de código sênior. Ajude com soluções claras, seguras e objetivas, priorizando PHP, arquitetura limpa, depuração cuidadosa e exemplos práticos quando necessário.',
            escritor: 'Você é um escritor cuidadoso. Ajude a revisar, estruturar e melhorar textos com clareza, fluidez, precisão e tom adequado ao público.',
            dados: 'Você é um analista de dados. Ajude a interpretar informações, criar hipóteses, explicar métricas e propor análises com raciocínio estatístico claro.',
        },
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
<script src="public/assets/js/app.js"></script>

</body>
</html>

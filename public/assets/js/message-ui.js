function updateContextUsage(contextUsage) {
    const fill = document.getElementById('contextBarFill');
    const label = document.getElementById('contextLabel');
    const tokens = Number(contextUsage?.tokens || 0);
    const configuredLimit = Number(contextUsage?.limit || 0);
    const dynamicLimit = Number(window.OlliverseState?.activeContextTokenLimit || 0);
    const limit = dynamicLimit > 0 ? dynamicLimit : configuredLimit;
    const calculatedPercentage = limit > 0 ? Math.round((tokens / limit) * 100) : Number(contextUsage?.percentage || 0);
    const percentage = Math.max(0, Math.min(100, calculatedPercentage));

    if (window.OlliverseState) {
        window.OlliverseState.lastContextUsage = {
            tokens,
            limit,
            percentage,
        };
    }

    fill.style.width = `${percentage}%`;
    fill.classList.toggle('warning', percentage >= 70 && percentage < 90);
    fill.classList.toggle('danger', percentage >= 90);
    label.textContent = limit > 0
        ? `Contexto ${percentage}% (${tokens}/${limit})`
        : `Contexto ${percentage}%`;
}

function setActiveContextTokenLimit(limit) {
    const value = Number(limit || 0);

    if (!window.OlliverseState || !Number.isFinite(value) || value <= 0) {
        return;
    }

    window.OlliverseState.activeContextTokenLimit = value;
    updateContextUsage(window.OlliverseState.lastContextUsage || window.OlliverseConfig.initialContextUsage);
}

function renderAssistantMessageContent(messageDiv, text) {
    const normalizedText = normalizeCodeMarkdown(text);

    if (!window.marked || !window.DOMPurify) {
        messageDiv.textContent = normalizedText;
        return;
    }

    try {
        messageDiv.innerHTML = DOMPurify.sanitize(marked.parse(normalizedText));
        enhanceCodeBlocks(messageDiv);
        processPluginContent(messageDiv);
    } catch (error) {
        messageDiv.textContent = normalizedText;
    }
}

function renderPersistedAssistantMessages() {
    document.querySelectorAll('.assistant-markdown-source[data-markdown-source]').forEach((source) => {
        const messageDiv = source.closest('.message.assistant');

        if (!messageDiv) {
            return;
        }

        try {
            const text = JSON.parse(source.dataset.markdownSource || '""');
            const responseDurationMs = source.dataset.responseDurationMs || null;

            renderAssistantMessageContent(messageDiv, text);
            appendResponseDuration(messageDiv.closest('.message-group'), responseDurationMs);
            appendCopyResponseButton(messageDiv.closest('.message-group'), text);
        } catch (error) {
            console.error('Erro ao renderizar mensagem persistida:', error);
        }
    });
}

function finalizeStreamingAssistantMessage(assistantMessage, text, responseDurationMs = null) {
    const shouldStickToBottom = shouldScrollToBottom();

    renderAssistantMessageContent(assistantMessage.message, text);

    appendResponseDuration(assistantMessage.group, responseDurationMs);

    if (text.trim() !== '') {
        appendCopyResponseButton(assistantMessage.group, text);
    }

    if (shouldStickToBottom) {
        scrollToBottom();
    }
}

function appendResponseDuration(messageGroup, durationMs) {
    if (!messageGroup) {
        return;
    }

    const existingDuration = messageGroup.querySelector('.response-duration');

    if (existingDuration) {
        existingDuration.remove();
    }

    const formattedDuration = formatResponseDuration(durationMs);

    if (!formattedDuration) {
        return;
    }

    const durationInfo = document.createElement('div');

    durationInfo.className = 'response-duration';
    durationInfo.textContent = `Resposta entregue em ${formattedDuration}`;
    messageGroup.appendChild(durationInfo);
}

function formatResponseDuration(durationMs) {
    const duration = Number(durationMs);

    if (!Number.isFinite(duration) || duration < 0) {
        return '';
    }

    if (duration < 1000) {
        return `${Math.max(1, Math.round(duration))}ms`;
    }

    if (duration < 60000) {
        return `${(duration / 1000).toLocaleString('pt-BR', {
            minimumFractionDigits: 1,
            maximumFractionDigits: 1,
        })}s`;
    }

    const minutes = Math.floor(duration / 60000);
    const seconds = Math.round((duration % 60000) / 1000);

    if (seconds === 60) {
        return `${minutes + 1}min 0s`;
    }

    return `${minutes}min ${seconds}s`;
}

function appendRagSources(messageGroup, sources) {
    if (!Array.isArray(sources) || sources.length === 0) {
        return;
    }

    const sourceNames = sources
        .map((source) => source?.source_name || '')
        .filter(Boolean);

    if (sourceNames.length === 0) {
        return;
    }

    const shouldStickToBottom = shouldScrollToBottom();
    const sourceInfo = document.createElement('div');
    sourceInfo.className = 'rag-source-info';
    sourceInfo.textContent = `Baseado em: ${sourceNames.join(', ')}`;
    messageGroup.appendChild(sourceInfo);

    if (shouldStickToBottom) {
        scrollToBottom();
    }
}

function renderUserMessage(messageGroup, messageDiv, text) {
    const messageText = document.createElement('span');

    messageText.className = 'message-text';
    messageText.textContent = text;

    messageDiv.appendChild(messageText);
    appendReusePromptButton(messageGroup, text);
}

function appendReusePromptButton(messageGroup, text) {
    const actions = createMessageActions();
    const reuseButton = document.createElement('button');

    reuseButton.type = 'button';
    reuseButton.className = 'message-action-btn reuse-prompt-btn';
    reuseButton.setAttribute('aria-label', 'Reusar pergunta');
    reuseButton.setAttribute('data-tooltip', 'Reusar pergunta');
    reuseButton.innerHTML = iconSvg('refresh-cw');
    reuseButton.addEventListener('click', function() {
        reusePrompt(text);
    });
    attachActionTooltip(reuseButton);

    actions.appendChild(reuseButton);
    messageGroup.appendChild(actions);
}

function reusePrompt(text) {
    const inputEl = document.getElementById('userInput');

    if (inputEl.disabled) {
        return;
    }

    inputEl.value = text;
    inputEl.focus();
    inputEl.setSelectionRange(inputEl.value.length, inputEl.value.length);
}

function renderAssistantMessage(messageGroup, messageDiv, text, responseDurationMs = null) {
    const normalizedText = normalizeCodeMarkdown(text);

    if (!window.marked || !window.DOMPurify) {
        renderBasicMarkdown(messageDiv, normalizedText);
        appendResponseDuration(messageGroup, responseDurationMs);
        appendCopyResponseButton(messageGroup, text);
        return;
    }

    try {
        messageDiv.innerHTML = DOMPurify.sanitize(marked.parse(normalizedText));
        enhanceCodeBlocks(messageDiv);
        processPluginContent(messageDiv);

        appendResponseDuration(messageGroup, responseDurationMs);
        appendCopyResponseButton(messageGroup, text);
    } catch (error) {
        console.error('Erro ao renderizar Markdown:', error);
        renderBasicMarkdown(messageDiv, normalizedText);
        appendResponseDuration(messageGroup, responseDurationMs);
        appendCopyResponseButton(messageGroup, text);
    }
}

function enhanceCodeBlocks(messageDiv) {
    messageDiv.querySelectorAll('pre code').forEach((block) => {
        const pre = block.parentElement;

        if (!pre || pre.parentElement?.classList.contains('code-block')) {
            return;
        }

        block.dataset.rawCode = block.textContent || '';
        highlightCodeBlock(block);

        decorateCodeBlock(pre, block);
    });
}

function processPluginContent(messageDiv) {
    if (!window.OlliversePlugins || typeof window.OlliversePlugins.processMessage !== 'function') {
        return;
    }

    window.OlliversePlugins.processMessage(messageDiv);
}

function highlightCodeBlock(block) {
    const language = detectCodeLanguage(block);
    const originalCode = block.textContent || '';

    if (block.dataset.highlighted) {
        return;
    }

    if (language === 'json-chart') {
        return;
    }

    if (!window.hljs) {
        block.innerHTML = applyBasicHighlight(originalCode, language);
        return;
    }

    try {
        if (typeof hljs.highlightElement === 'function') {
            hljs.highlightElement(block);
        } else if (typeof hljs.highlightBlock === 'function') {
            hljs.highlightBlock(block);
        } else {
            block.innerHTML = applyBasicHighlight(originalCode, language);
        }
    } catch (error) {
        console.error('Erro ao aplicar syntax highlighting:', error);
        block.innerHTML = applyBasicHighlight(originalCode, language);
    }
}

function decorateCodeBlock(pre, block) {
    const wrapper = document.createElement('div');
    const header = document.createElement('div');
    const languageLabel = document.createElement('span');
    const copyButton = document.createElement('button');
    const language = detectCodeLanguage(block);
    const codeText = block.dataset.rawCode || block.textContent || '';

    wrapper.className = 'code-block';
    header.className = 'code-block-header';
    languageLabel.className = 'code-block-language';
    languageLabel.textContent = language;

    copyButton.type = 'button';
    copyButton.className = 'code-copy-btn';
    copyButton.setAttribute('aria-label', 'Copiar código');
    copyButton.setAttribute('data-tooltip', 'Copiar código');
    copyButton.innerHTML = iconSvg('copy');
    copyButton.addEventListener('click', function() {
        copyCodeBlockText(codeText, copyButton);
    });
    attachActionTooltip(copyButton);

    header.appendChild(languageLabel);
    header.appendChild(copyButton);
    pre.replaceWith(wrapper);
    wrapper.appendChild(header);
    wrapper.appendChild(pre);
}

function detectCodeLanguage(block) {
    const languageClass = Array.from(block.classList).find((className) => {
        return className.startsWith('language-');
    });
    const language = languageClass ? languageClass.replace('language-', '') : '';

    return language || 'plaintext';
}

function appendCopyResponseButton(messageGroup, text) {
    const actions = messageGroup.querySelector('.message-actions') || createMessageActions();
    const copyButton = document.createElement('button');

    copyButton.type = 'button';
    copyButton.className = 'message-action-btn copy-response-btn';
    copyButton.setAttribute('aria-label', 'Copiar resposta');
    copyButton.setAttribute('data-tooltip', 'Copiar resposta');
    copyButton.innerHTML = iconSvg('copy');
    copyButton.addEventListener('click', function() {
        copyResponseText(text, copyButton);
    });
    attachActionTooltip(copyButton);

    actions.appendChild(copyButton);
    if (!actions.parentElement) {
        messageGroup.appendChild(actions);
    }
}

function createMessageActions() {
    const actions = document.createElement('div');

    actions.className = 'message-actions';

    return actions;
}

function attachActionTooltip(button) {
    button.addEventListener('mouseenter', function() {
        showFloatingTooltip(button);
    });
    button.addEventListener('focus', function() {
        showFloatingTooltip(button);
    });
    button.addEventListener('mouseleave', hideFloatingTooltip);
    button.addEventListener('blur', hideFloatingTooltip);
}

function getFloatingTooltip() {
    let tooltip = document.getElementById('floatingTooltip');

    if (!tooltip) {
        tooltip = document.createElement('div');
        tooltip.id = 'floatingTooltip';
        tooltip.className = 'floating-tooltip';
        document.body.appendChild(tooltip);
    }

    return tooltip;
}

function showFloatingTooltip(button) {
    const tooltip = getFloatingTooltip();

    window.OlliverseState.activeTooltipButton = button;
    tooltip.textContent = button.getAttribute('data-tooltip') || '';
    positionFloatingTooltip(button, tooltip);
    tooltip.classList.add('visible');
}

function hideFloatingTooltip() {
    const tooltip = getFloatingTooltip();

    window.OlliverseState.activeTooltipButton = null;
    tooltip.classList.remove('visible');
}

function positionFloatingTooltip(button, tooltip) {
    const rect = button.getBoundingClientRect();
    const tooltipRect = tooltip.getBoundingClientRect();
    const shouldOpenBelow = rect.top < tooltipRect.height + 14;
    const top = shouldOpenBelow
        ? rect.bottom + 8
        : rect.top - tooltipRect.height - 8;
    const minLeft = tooltipRect.width / 2 + 8;
    const maxLeft = window.innerWidth - tooltipRect.width / 2 - 8;
    const left = Math.min(Math.max(rect.left + rect.width / 2, minLeft), maxLeft);

    tooltip.style.left = `${left}px`;
    tooltip.style.top = `${top}px`;
}

function copyResponseText(text, copyButton) {
    copyText(text).then(() => {
        showCopyFeedback(copyButton, true, 'Resposta copiada', 'Copiar resposta');
    }).catch((error) => {
        console.error('Erro ao copiar resposta:', error);
        showCopyFeedback(copyButton, false, 'Não foi possível copiar', 'Copiar resposta');
    });
}

function copyCodeBlockText(text, copyButton) {
    copyText(text).then(() => {
        showCopyFeedback(copyButton, true, 'Código copiado', 'Copiar código');
    }).catch((error) => {
        console.error('Erro ao copiar código:', error);
        showCopyFeedback(copyButton, false, 'Não foi possível copiar', 'Copiar código');
    });
}

function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text);
    }

    return new Promise((resolve, reject) => {
        const textarea = document.createElement('textarea');

        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.left = '-9999px';
        textarea.style.position = 'fixed';
        textarea.style.top = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            document.execCommand('copy') ? resolve() : reject(new Error('Cópia não permitida.'));
        } catch (error) {
            reject(error);
        } finally {
            textarea.remove();
        }
    });
}

function showCopyFeedback(copyButton, success, feedbackLabel, defaultLabel) {
    copyButton.classList.toggle('copied', success);
    const label = success ? feedbackLabel : 'Não foi possível copiar';

    copyButton.setAttribute('aria-label', label);
    copyButton.setAttribute('data-tooltip', label);
    copyButton.innerHTML = iconSvg(success ? 'check' : 'copy-x');

    refreshFloatingTooltip(copyButton);

    window.setTimeout(() => {
        copyButton.classList.remove('copied');
        copyButton.setAttribute('aria-label', defaultLabel);
        copyButton.setAttribute('data-tooltip', defaultLabel);
        copyButton.innerHTML = iconSvg('copy');
        refreshFloatingTooltip(copyButton);
    }, 1600);
}

function refreshFloatingTooltip(button) {
    if (window.OlliverseState.activeTooltipButton !== button) {
        return;
    }

    showFloatingTooltip(button);
}

function iconSvg(name) {
    const icons = {
        copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>',
        'copy-x': '<line x1="12" x2="18" y1="12" y2="18"></line><line x1="12" x2="18" y1="18" y2="12"></line><rect width="14" height="14" x="8" y="8" rx="2" ry="2"></rect><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"></path>',
        check: '<path d="M20 6 9 17l-5-5"></path>',
        'bar-chart-3': '<path d="M3 3v18h18"></path><path d="M18 17V9"></path><path d="M13 17V5"></path><path d="M8 17v-3"></path>',
        download: '<path d="M12 15V3"></path><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><path d="m7 10 5 5 5-5"></path>',
        ellipsis: '<circle cx="12" cy="12" r="1"></circle><circle cx="19" cy="12" r="1"></circle><circle cx="5" cy="12" r="1"></circle>',
        file: '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path>',
        'file-text': '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"></path><path d="M14 2v4a2 2 0 0 0 2 2h4"></path><path d="M10 9H8"></path><path d="M16 13H8"></path><path d="M16 17H8"></path>',
        'line-chart': '<path d="M3 3v18h18"></path><path d="m19 9-5 5-4-4-3 3"></path>',
        'maximize-2': '<path d="M15 3h6v6"></path><path d="m21 3-7 7"></path><path d="m3 21 7-7"></path><path d="M9 21H3v-6"></path>',
        'minimize-2': '<path d="m14 10 7-7"></path><path d="M20 10h-6V4"></path><path d="m3 21 7-7"></path><path d="M4 14h6v6"></path>',
        'pie-chart': '<path d="M21 12c.552 0 1.005-.449.95-.998a10 10 0 0 0-8.953-8.951C12.449 1.996 12 2.448 12 3v8a1 1 0 0 0 1 1z"></path><path d="M21.21 15.89A10 10 0 1 1 8 2.83"></path>',
        'refresh-cw': '<path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 16h5v5"></path>',
        'table-2': '<path d="M9 3H5a2 2 0 0 0-2 2v4m6-6h10a2 2 0 0 1 2 2v4M9 3v18m0-12h12M9 21h10a2 2 0 0 0 2-2V9M9 21H5a2 2 0 0 1-2-2V9m0 0h18"></path>',
        'trash-2': '<path d="M3 6h18"></path><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"></path><path d="M10 11v6"></path><path d="M14 11v6"></path>'
    };

    return `<svg aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">${icons[name] || icons.copy}</svg>`;
}

function normalizeCodeMarkdown(text) {
    let normalizedText = fenceBareJsonBlocks(repairMalformedCodeFences(text))
        .replace(/```markdown\s*```(\w+)\s*([\s\S]*?)```\s*```/g, '```$1\n$2\n```')
        .replace(/```markdown\s+```(\w+)\s+([\s\S]*?)```\s*```/g, '```$1\n$2\n```')
        .replace(/```(\w+)\s+([\s\S]*?)```/g, (match, language, code) => {
            if (code.includes('\n')) {
                return match;
            }

            return `\`\`\`${language}\n${code.trim()}\n\`\`\``;
        });

    normalizedText = fenceBareCodeBlocks(normalizedText);
    normalizedText = removeRepeatedCodeBlocks(normalizedText);

    return removePlainCodeBeforeFormattedCode(normalizedText);
}

function fenceBareJsonBlocks(text) {
    const lines = text.split('\n');
    const result = [];
    let index = 0;
    let isInsideFence = false;

    while (index < lines.length) {
        const line = lines[index];

        if (line.trim().startsWith('```')) {
            isInsideFence = !isInsideFence;
            result.push(line);
            index += 1;
            continue;
        }

        const labelMatch = line.trim().match(/^(?:\*\*)?\s*(json-chart|json)\s*(?:\*\*)?:?\s*$/i);

        if (!isInsideFence && labelMatch) {
            let nextIndex = index + 1;

            while (nextIndex < lines.length && lines[nextIndex].trim() === '') {
                nextIndex += 1;
            }

            if (lines[nextIndex]?.trim().startsWith('{')) {
                const collected = collectBalancedJsonLines(lines, nextIndex);

                if (collected) {
                    result.push(`\`\`\`${normalizeLanguageName(labelMatch[1])}`);
                    result.push(collected.lines.join('\n'));
                    result.push('```');
                    index = collected.nextIndex;
                    continue;
                }
            }
        }

        result.push(line);
        index += 1;
    }

    return result.join('\n');
}

function collectBalancedJsonLines(lines, startIndex) {
    const collected = [];
    let depth = 0;
    let inString = false;
    let escaped = false;
    let hasStarted = false;

    for (let lineIndex = startIndex; lineIndex < lines.length; lineIndex += 1) {
        const line = lines[lineIndex];

        collected.push(line);

        for (let charIndex = 0; charIndex < line.length; charIndex += 1) {
            const char = line[charIndex];

            if (escaped) {
                escaped = false;
                continue;
            }

            if (char === '\\') {
                escaped = inString;
                continue;
            }

            if (char === '"') {
                inString = !inString;
                continue;
            }

            if (inString) {
                continue;
            }

            if (char === '{') {
                depth += 1;
                hasStarted = true;
            }

            if (char === '}') {
                depth -= 1;
            }
        }

        if (hasStarted && depth === 0) {
            return {
                lines: collected,
                nextIndex: lineIndex + 1,
            };
        }
    }

    return null;
}

function repairMalformedCodeFences(text) {
    const lines = text.split('\n');
    const repairedLines = [];
    let currentFenceLanguage = '';
    let isInsideFence = false;

    lines.forEach((line) => {
        const fenceMatch = line.trim().match(/^```([A-Za-z0-9_+#.-]*)\s*$/);

        if (fenceMatch) {
            if (isInsideFence) {
                repairedLines.push('```');
                isInsideFence = false;
                currentFenceLanguage = '';
                return;
            }

            currentFenceLanguage = normalizeLanguageName(fenceMatch[1] || 'plaintext');
            repairedLines.push(`\`\`\`${currentFenceLanguage}`);
            isInsideFence = true;
            return;
        }

        if (isInsideFence && line.trim().toLowerCase() === currentFenceLanguage) {
            return;
        }

        repairedLines.push(line);
    });

    if (isInsideFence) {
        repairedLines.push('```');
    }

    return repairedLines.join('\n');
}

function fenceBareCodeBlocks(text) {
    const fencedText = text
        .replace(/(^|\n)(<\?php[\s\S]*?)(?=\n(?:Aqui está|Esta função|Esse código|Explicação|Observação)\b|$)/g, (match, prefix, code) => {
            if (match.includes('```')) return match;
            return `${prefix}\`\`\`php\n${code.trim()}\n\`\`\``;
        })
        .replace(/(^|\n)((?:function|const|let|var|class)\s+[A-Za-z_$][\w$]*[\s\S]*?)(?=\n\n[A-ZÀ-Úa-zà-ú]|$)/g, (match, prefix, code) => {
            if (match.includes('```')) return match;
            return `${prefix}\`\`\`javascript\n${code.trim()}\n\`\`\``;
        });

    return fenceLooseCodeLines(fencedText);
}

function fenceLooseCodeLines(text) {
    const lines = text.split('\n');
    const fencedLines = [];
    const codeBuffer = [];
    let isInsideFence = false;

    const flushCodeBuffer = () => {
        if (codeBuffer.length === 0) {
            return;
        }

        const code = codeBuffer.join('\n').trim();
        const language = detectLooseCodeLanguage(code);

        fencedLines.push(`\`\`\`${language}`);
        fencedLines.push(code);
        fencedLines.push('```');
        codeBuffer.length = 0;
    };

    lines.forEach((line) => {
        if (line.trim().startsWith('```')) {
            flushCodeBuffer();
            isInsideFence = !isInsideFence;
            fencedLines.push(line);
            return;
        }

        if (!isInsideFence && codeBuffer.length > 0) {
            if (!line.trim() || isLikelyLooseCodeContinuation(line)) {
                codeBuffer.push(line);
                return;
            }

            flushCodeBuffer();
        }

        if (!isInsideFence && isLikelyLooseCodeLine(line)) {
            codeBuffer.push(line);
            return;
        }

        flushCodeBuffer();
        fencedLines.push(line);
    });

    flushCodeBuffer();

    return fencedLines.join('\n');
}

function isLikelyLooseCodeLine(line) {
    const trimmedLine = line.trim();

    if (!trimmedLine) {
        return false;
    }

    return /^(const|let|var|function|class|import|export|require\(|[A-Za-z_$][\w$.]*\(|[A-Za-z_$][\w$.]*\.)/.test(trimmedLine)
        || /^(return|this\.|await\s+|async\s+)/.test(trimmedLine)
        || /^[});\]}]+[;,]?$/.test(trimmedLine)
        || /=>\s*\{?$/.test(trimmedLine);
}

function isLikelyLooseCodeContinuation(line) {
    const trimmedLine = line.trim();

    return /^(return|this\.|await\s+|async\s+|[A-Za-z_$][\w$]*:|[A-Za-z_$][\w$.]*\(|[A-Za-z_$][\w$.]*\.|[});\]}]+[;,]?|[{}]);?$/.test(trimmedLine)
        || (/^\s+/.test(line) && /[{}()[\].,:;'"`?=]/.test(trimmedLine));
}

function detectLooseCodeLanguage(code) {
    if (code.includes('<?php')) {
        return 'php';
    }

    if (/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER)\b/im.test(code)) {
        return 'sql';
    }

    if (/^\s*[{\[]/.test(code)) {
        return 'json';
    }

    return 'javascript';
}

function normalizeLanguageName(language) {
    const normalizedLanguage = language.toLowerCase();
    const aliases = {
        js: 'javascript',
        shell: 'bash',
        sh: 'bash',
        text: 'plaintext',
        plain: 'plaintext',
    };

    return aliases[normalizedLanguage] || normalizedLanguage || 'plaintext';
}

function removeRepeatedCodeBlocks(text) {
    const seenCodeBlocks = new Set();

    return text.replace(/```(\w+)?\n([\s\S]*?)```/g, (match, language, code) => {
        const codeKey = normalizeCodeForComparison(code);

        if (seenCodeBlocks.has(codeKey)) {
            return '';
        }

        seenCodeBlocks.add(codeKey);
        return `\`\`\`${language || 'plaintext'}\n${code.trim()}\n\`\`\``;
    });
}

function removePlainCodeBeforeFormattedCode(text) {
    if (!text.includes('```')) {
        return text;
    }

    return text.replace(
        /((?:^|\n)(?:function|const|let|var|class)\s+[\s\S]*?)(\n\n```(?:javascript|js|php|html|css)\n[\s\S]*?```)/g,
        (match, plainCode, formattedCode) => {
            const plainSignature = getCodeSignature(plainCode);
            const formattedSignature = getCodeSignature(formattedCode);

            if (plainSignature && formattedSignature.includes(plainSignature)) {
                return formattedCode;
            }

            return match;
        }
    );
}

function getCodeSignature(code) {
    const functionMatch = code.match(/function\s+([A-Za-z_$][\w$]*)/);
    const variableMatch = code.match(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)/);
    const classMatch = code.match(/class\s+([A-Za-z_$][\w$]*)/);

    return functionMatch?.[1] || variableMatch?.[1] || classMatch?.[1] || '';
}

function normalizeCodeForComparison(code) {
    return code
        .replace(/<span[^>]*>/g, '')
        .replace(/<\/span>/g, '')
        .replace(/class=&quot;[^&]*&quot;&gt;/g, '')
        .replace(/class="[^"]*">/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function renderBasicMarkdown(messageDiv, text) {
    const parts = text.split(/```(\w+)?\n([\s\S]*?)```/g);

    parts.forEach((part, index) => {
        if (index % 3 === 0) {
            appendTextParagraphs(messageDiv, part);
            return;
        }

        if (index % 3 === 1) {
            const language = part || 'plaintext';
            const code = parts[index + 1] || '';
            appendCodeBlock(messageDiv, code, language);
        }
    });
}

function appendTextParagraphs(container, text) {
    text.trim().split(/\n{2,}/).forEach((paragraphText) => {
        if (!paragraphText.trim()) return;

        const paragraph = document.createElement('p');
        paragraph.textContent = paragraphText.trim();
        container.appendChild(paragraph);
    });
}

function appendCodeBlock(container, code, language) {
    const pre = document.createElement('pre');
    const codeEl = document.createElement('code');

    codeEl.className = `language-${language}`;
    codeEl.innerHTML = applyBasicHighlight(code, language);

    pre.appendChild(codeEl);
    container.appendChild(pre);
}

function applyBasicHighlight(code, language) {
    let escapedCode = escapeHtml(code);
    const protectedTokens = [];
    const normalizedLanguage = normalizeLanguageName(language);
    const languageKeywords = {
        php: 'abstract|array|as|break|case|catch|class|const|continue|default|do|echo|else|elseif|extends|final|for|foreach|function|if|implements|interface|namespace|new|private|protected|public|return|static|switch|throw|try|use|while',
        javascript: 'async|await|break|case|catch|class|const|continue|default|else|export|extends|finally|for|function|if|import|let|new|return|switch|throw|try|var|while',
        js: 'async|await|break|case|catch|class|const|continue|default|else|export|extends|finally|for|function|if|import|let|new|return|switch|throw|try|var|while',
        python: 'and|as|assert|async|await|break|class|continue|def|del|elif|else|except|False|finally|for|from|global|if|import|in|is|lambda|None|nonlocal|not|or|pass|raise|return|True|try|while|with|yield',
        py: 'and|as|assert|async|await|break|class|continue|def|del|elif|else|except|False|finally|for|from|global|if|import|in|is|lambda|None|nonlocal|not|or|pass|raise|return|True|try|while|with|yield',
        css: 'align-items|background|border|color|display|flex|font-size|gap|grid|height|justify-content|margin|padding|position|width',
        html: 'html|head|body|div|span|button|form|input|script|style|link|meta|title'
    };
    const keywords = languageKeywords[normalizedLanguage] || '';
    const lineCommentPattern = normalizedLanguage === 'python' || normalizedLanguage === 'py'
        ? /(#.*)/g
        : /(&lt;!--[\s\S]*?--&gt;|\/\/.*)/g;

    escapedCode = escapedCode
        .replace(/(&#039;[^&\n]*(?:&#039;)|&quot;[^&\n]*(?:&quot;)|`[^`\n]*`)/g, (match) => protectToken(`<span class="code-string">${match}</span>`, protectedTokens))
        .replace(lineCommentPattern, (match) => protectToken(`<span class="code-comment">${match}</span>`, protectedTokens))
        .replace(/&(?:amp|lt|gt|quot|#039);/g, (match) => protectToken(match, protectedTokens))
        .replace(/\b(\d+)\b/g, '<span class="code-number">$1</span>');

    if (keywords !== '') {
        escapedCode = escapedCode.replace(new RegExp(`\\b(${keywords})\\b`, 'g'), '<span class="code-keyword">$1</span>');
    }

    return protectedTokens.reduce((highlightedCode, token, index) => {
        return highlightedCode.replace(createProtectedToken(index), token);
    }, escapedCode);
}

function protectToken(value, protectedTokens) {
    const token = createProtectedToken(protectedTokens.length);
    protectedTokens.push(value);
    return token;
}

function createProtectedToken(index) {
    let tokenSuffix = '';
    let currentIndex = index;

    do {
        tokenSuffix = String.fromCharCode(65 + (currentIndex % 26)) + tokenSuffix;
        currentIndex = Math.floor(currentIndex / 26) - 1;
    } while (currentIndex >= 0);

    return `@@CODETOKEN${tokenSuffix}@@`;
}

function escapeHtml(text) {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function scrollToBottom() {
    const container = document.getElementById('chatMessages');

    if (!container) {
        return;
    }

    container.scrollTop = container.scrollHeight;
}

function shouldScrollToBottom() {
    const container = document.getElementById('chatMessages');
    const threshold = 100;

    if (!container) {
        return true;
    }

    const distanceToBottom = container.scrollHeight - container.scrollTop - container.clientHeight;

    return distanceToBottom <= threshold;
}

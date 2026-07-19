function updateContextUsage(contextUsage) {
    const fill = document.getElementById('contextBarFill');
    const label = document.getElementById('contextLabel');
    const percentage = Math.max(0, Math.min(100, Number(contextUsage?.percentage || 0)));
    const tokens = Number(contextUsage?.tokens || 0);
    const limit = Number(contextUsage?.limit || 0);

    fill.style.width = `${percentage}%`;
    fill.classList.toggle('warning', percentage >= 70 && percentage < 90);
    fill.classList.toggle('danger', percentage >= 90);
    label.textContent = limit > 0
        ? `Contexto ${percentage}% (${tokens}/${limit})`
        : `Contexto ${percentage}%`;
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

            renderAssistantMessageContent(messageDiv, text);
            appendCopyResponseButton(messageDiv.closest('.message-group'), text);
        } catch (error) {
            console.error('Erro ao renderizar mensagem persistida:', error);
        }
    });
}

function finalizeStreamingAssistantMessage(assistantMessage, text) {
    renderAssistantMessageContent(assistantMessage.message, text);

    if (text.trim() !== '') {
        appendCopyResponseButton(assistantMessage.group, text);
    }

    scrollToBottom();
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

function renderAssistantMessage(messageGroup, messageDiv, text) {
    const normalizedText = normalizeCodeMarkdown(text);

    if (!window.marked || !window.DOMPurify) {
        renderBasicMarkdown(messageDiv, normalizedText);
        appendCopyResponseButton(messageGroup, text);
        return;
    }

    try {
        messageDiv.innerHTML = DOMPurify.sanitize(marked.parse(normalizedText));
        enhanceCodeBlocks(messageDiv);

        appendCopyResponseButton(messageGroup, text);
    } catch (error) {
        console.error('Erro ao renderizar Markdown:', error);
        renderBasicMarkdown(messageDiv, normalizedText);
        appendCopyResponseButton(messageGroup, text);
    }
}

function enhanceCodeBlocks(messageDiv) {
    messageDiv.querySelectorAll('pre code').forEach((block) => {
        const pre = block.parentElement;

        if (!pre || pre.parentElement?.classList.contains('code-block')) {
            return;
        }

        highlightCodeBlock(block);

        decorateCodeBlock(pre, block);
    });
}

function highlightCodeBlock(block) {
    const language = detectCodeLanguage(block);
    const originalCode = block.textContent || '';

    if (block.dataset.highlighted) {
        return;
    }

    if (!window.hljs) {
        block.innerHTML = applyBasicHighlight(originalCode, language);
        return;
    }

    try {
        hljs.highlightElement(block);
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
    const codeText = block.textContent || '';

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
    const actions = createMessageActions();
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
    messageGroup.appendChild(actions);
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
        'refresh-cw': '<path d="M21 12a9 9 0 0 0-9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><path d="M3 3v5h5"></path><path d="M3 12a9 9 0 0 0 9 9 9.75 9.75 0 0 0 6.74-2.74L21 16"></path><path d="M16 16h5v5"></path>'
    };

    return `<svg aria-hidden="true" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" viewBox="0 0 24 24">${icons[name] || icons.copy}</svg>`;
}

function normalizeCodeMarkdown(text) {
    let normalizedText = repairMalformedCodeFences(text)
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
    const languageKeywords = {
        php: 'abstract|array|as|break|case|catch|class|const|continue|default|do|echo|else|elseif|extends|final|for|foreach|function|if|implements|interface|namespace|new|private|protected|public|return|static|switch|throw|try|use|while',
        javascript: 'async|await|break|case|catch|class|const|continue|default|else|export|extends|finally|for|function|if|import|let|new|return|switch|throw|try|var|while',
        js: 'async|await|break|case|catch|class|const|continue|default|else|export|extends|finally|for|function|if|import|let|new|return|switch|throw|try|var|while',
        css: 'align-items|background|border|color|display|flex|font-size|gap|grid|height|justify-content|margin|padding|position|width',
        html: 'html|head|body|div|span|button|form|input|script|style|link|meta|title'
    };
    const keywords = languageKeywords[language] || languageKeywords.javascript;

    escapedCode = escapedCode
        .replace(/(&#039;[^&\n]*(?:&#039;)|&quot;[^&\n]*(?:&quot;)|`[^`\n]*`)/g, (match) => protectToken(`<span class="code-string">${match}</span>`, protectedTokens))
        .replace(/(&lt;!--[\s\S]*?--&gt;|\/\/.*)/g, (match) => protectToken(`<span class="code-comment">${match}</span>`, protectedTokens))
        .replace(/&(?:amp|lt|gt|quot|#039);/g, (match) => protectToken(match, protectedTokens))
        .replace(/\b(\d+)\b/g, '<span class="code-number">$1</span>')
        .replace(new RegExp(`\\b(${keywords})\\b`, 'g'), '<span class="code-keyword">$1</span>');

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
    container.scrollTop = container.scrollHeight;
}

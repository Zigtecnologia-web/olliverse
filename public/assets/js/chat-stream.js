async function streamAssistantResponse(response, assistantMessage, onChunk, onPayload) {
    if (!response.body || !window.TextDecoder) {
        const text = await response.text();

        processNdjsonBuffer(text, onChunk, onPayload, assistantMessage);
        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let fullText = '';
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();

        if (done) {
            break;
        }

        const chunk = decoder.decode(value, { stream: true });

        if (!chunk) {
            continue;
        }

        buffer += chunk;
        buffer = processNdjsonBuffer(buffer, (content) => {
            fullText += content;
            onChunk(content);
            renderAssistantMessageContent(assistantMessage.message, fullText);
        }, onPayload, assistantMessage);
        scrollToBottom();
    }

    const finalChunk = decoder.decode();

    if (finalChunk) {
        buffer += finalChunk;
    }

    if (buffer.trim() !== '') {
        processNdjsonLine(buffer.trim(), (content) => {
            fullText += content;
            onChunk(content);
            renderAssistantMessageContent(assistantMessage.message, fullText);
        }, onPayload);
    }
}

function processNdjsonBuffer(buffer, onChunk, onPayload) {
    const lines = buffer.split('\n');
    const remainder = lines.pop() || '';

    lines.forEach((line) => {
        processNdjsonLine(line, onChunk, onPayload);
    });

    return remainder;
}

function processNdjsonLine(line, onChunk, onPayload) {
    if (!line.trim()) {
        return;
    }

    let payload;

    try {
        payload = JSON.parse(line);
    } catch (error) {
        throw new Error(`Resposta inesperada do servidor: ${cleanServerMessage(line)}`);
    }

    if (payload.type === 'chunk') {
        onChunk(payload.content || '');
        return;
    }

    onPayload(payload);
}

function cleanServerMessage(message) {
    const withoutTags = message
        .replace(/<br\s*\/?>/gi, ' ')
        .replace(/<[^>]+>/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    return withoutTags || 'conteúdo inválido recebido.';
}

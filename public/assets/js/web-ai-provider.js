window.OlliverseWebAI = {
    engine: null,
    modelId: window.OlliverseConfig?.webAi?.modelId || 'Llama-3.2-1B-Instruct-q4f16_1-MLC',

    isAvailable() {
        return Boolean(navigator.gpu);
    },

    async init(onStatus) {
        if (this.engine) {
            onStatus?.('Web AI pronta no navegador.');
            return;
        }

        if (!this.isAvailable()) {
            throw new Error('Web AI precisa de um navegador com WebGPU ativo.');
        }

        onStatus?.('Preparando a Web AI no navegador...');

        const webLlm = await import('https://esm.run/@mlc-ai/web-llm');
        const createEngine = webLlm.CreateMLCEngine || webLlm.CreateWebWorkerMLCEngine;

        if (typeof createEngine !== 'function') {
            throw new Error('Nao foi possivel carregar o motor Web AI.');
        }

        this.engine = await createEngine(this.modelId, {
            initProgressCallback: (progress) => {
                onStatus?.(friendlyWebAiProviderProgress(progress));
            },
        });

        onStatus?.('Web AI pronta no navegador.');
    },

    async generate(messages, onChunk, onStatus) {
        await this.init(onStatus);

        const stream = await this.engine.chat.completions.create({
            messages,
            stream: true,
            temperature: 0.7,
        });

        let fullText = '';

        for await (const chunk of stream) {
            const content = chunk?.choices?.[0]?.delta?.content || '';

            if (!content) {
                continue;
            }

            fullText += content;
            onChunk(content);
        }

        return fullText;
    },
};

function friendlyWebAiProviderProgress(progress) {
    const rawText = String(progress?.text || '').trim();
    const progressValue = Number(progress?.progress || 0);
    const percent = progressValue > 0 && progressValue <= 1
        ? ` ${Math.round(progressValue * 100)}%.`
        : '';

    if (rawText === '') {
        return `Preparando a Web AI no navegador.${percent}`;
    }

    return rawText;
}

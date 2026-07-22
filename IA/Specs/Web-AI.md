# Especificação Técnica de Funcionalidade (Spec): Integração de Web AI (Client-Side) como Provedor Híbrido no Olliverse

## 1. Visão Geral

Esta especificação introduz o suporte a **Web AI (IA no Navegador via WebGPU / Transformers.js)** no **Olliverse**. O objetivo é adicionar a capacidade opcional de executar modelos leves diretamente no browser do usuário, funcionando em paralelo e sem substituir o motor principal via Ollama. O Olliverse passa a operar com um modelo de **Provedores Híbridos**, permitindo que o usuário escolha dinamicamente se quer processar uma sessão via Ollama (servidor local robusto) ou via Web AI (portátil, direto no navegador).

---

## 2. Objetivos e Requisitos

* **Coexistência de Provedores:** Manter o Ollama como motor padrão/principal, introduzindo a Web AI como um provedor secundário alternativo configurável por chat ou globalmente.
* **Zero Instalação Externa:** Permitir que chats rápidos ou o próprio módulo de inspeção/insights rodem inteiramente no navegador utilizando a WebGPU da máquina, sem requerer servidores em segundo plano.
* **Abstração de Camada (Provider Pattern):** Criar uma interface unificada no backend/frontend para que o chat envie prompts independentemente de onde o modelo está rodando (Ollama vs. Web AI).

---

## 3. Arquitetura de Provedores Híbridos

```text
olliverse/
├── core/
│   ├── providers/
│   │   ├── OllamaProvider.php      # Driver de comunicação com o Ollama local
│   │   └── WebAiProvider.js        # Driver client-side baseado em WebGPU (Transformers.js / WebLLM)

```

---

## 4. Especificação de Componentes e Código

### 4.1. Seletor de Provedor na Interface (UI/UX)

Adição de um seletor de motor de IA no cabeçalho do chat ou nas configurações da sessão:

```html
<div class="ai-provider-selector">
    <label for="provider-select">Motor de IA:</label>
    <select id="provider-select" class="provider-dropdown">
        <option value="ollama" selected>🦙 Ollama (Llama 3 Local)</option>
        <option value="web_ai">🌐 Web AI (Navegador / WebGPU)</option>
    </select>
</div>

```

### 4.2. Camada de Abstração Frontend (`plugins/web_ai/provider.js`)

Gerenciador responsável por carregar o modelo leve no navegador (ex: Phi-3 ou Llama 3.2 1B compactado para WebGPU) sob demanda:

```javascript
window.OlliverseWebAI = {
    isLoaded: false,
    engine: null,

    init: async function(statusCallback) {
        if (this.isLoaded) return;
        
        // Exemplo conceitual usando bibliotecas compatíveis com WebGPU no browser
        statusCallback("Baixando/Carregando modelo leve na memória do navegador...");
        
        // Simulação de inicialização do engine WebAI (ex: WebLLM ou Transformers.js)
        // this.engine = await CreateWebWorkerMLCEngine(...);
        
        this.isLoaded = true;
        statusCallback("Web AI pronta para uso!");
    },

    generate: async function(prompt, onStream) {
        if (!this.isLoaded) throw new Error("Web AI não foi inicializada.");
        
        // Execução local na GPU do navegador via WebGPU
        // const chunks = await this.engine.chat.completions.create({ messages: [...], stream: true });
        // for await (const chunk of chunks) { onStream(chunk.choices[0].delta.content); }
    }
};

```

### 4.3. Orquestrador de Roteamento de Requisições (`core/router.js`)

O chat do Olliverse intercepta o envio da mensagem e direciona para o provedor ativo:

```javascript
async function handleUserSubmit(prompt) {
    const activeProvider = document.getElementById('provider-select').value;

    if (activeProvider === 'web_ai') {
        // Garante que o motor Web AI está pronto e processa na borda do navegador
        await window.OlliverseWebAI.init((msg) => updateStatusUI(msg));
        await window.OlliverseWebAI.generate(prompt, (token) => appendToChatStream(token));
    } else {
        // Envia para o backend PHP / Ollama tradicional
        sendToOllamaBackend(prompt);
    }
}

```

---

## 5. Casos de Uso Específicos para a Web AI no Olliverse

1. **Inspeção de Dados Instantânea (Offline Mode):** O plugin de análise de dados pode utilizar a Web AI rodando no browser para gerar rapidamente o JSON de insights estruturados da base SQLite, poupando chamadas ao Ollama principal.
2. **Uso Portátil:** Se o usuário acessar o Olliverse em uma máquina onde o Ollama não está configurado ou iniciado, ele pode alternar para a Web AI e continuar conversando usando a GPU do próprio navegador.

---

## 6. Critérios de Aceite

1. **Não-Regressão do Ollama:** O Ollama continua sendo o provedor padrão e primário, funcionando exatamente como antes sem nenhuma perda de performance ou alteração obrigatória.
2. **Seletor Funcional:** O usuário pode alternar entre "Ollama" e "Web AI" através de um seletor visual na interface.
3. **Execução via WebGPU:** Quando a opção Web AI é escolhida, o modelo é baixado/inicializado localmente na aba do navegador e responde via streaming utilizando os recursos gráficos da máquina do usuário.
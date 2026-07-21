Esta especificação técnica formaliza a implementação do **Sticky Scroll** no **Olliverse**, garantindo uma experiência de leitura fluida durante a geração de respostas em streaming.

---

# 📋 Spec.md: UX de Streaming e Scroll Inteligente

## 1. Visão Geral

O comportamento de "Scroll Inteligente" ou *Sticky Scroll* visa otimizar a experiência do usuário durante o streaming de respostas da IA. O objetivo é evitar que a interface force a rolagem para o final da página enquanto o usuário estiver consultando o histórico de mensagens.

## 2. Regra de Negócio

O scroll automático para o final do container de mensagens (`chat-container`) será regido pela proximidade do usuário em relação ao rodapé:

* **Estado de Autoscroll (Ativo):** Se o usuário estiver posicionado a uma distância $\le 100px$ do final do conteúdo, o scroll acompanhará automaticamente cada novo *chunk* de texto recebido.
* **Estado de Leitura (Inativo):** Se o usuário estiver posicionado a uma distância $> 100px$ do final (rolagem manual para cima), o sistema suspende o scroll automático, mantendo a posição atual do usuário para permitir a leitura do histórico sem interrupção.

## 3. Especificação Técnica (Frontend)

### 3.1 Lógica de Verificação (`chat-renderer.js`)

A função de verificação deve ser executada antes de cada atualização visual na `div` de mensagens:

```javascript
/**
 * Verifica se o usuário está próximo ao final do chat
 * @returns {boolean}
 */
function shouldScrollToBottom() {
    const chatContainer = document.getElementById('chat-container');
    const threshold = 100; // pixels de tolerância
    
    // Cálculo: Altura total - Posição do scroll - Altura visível
    const distanceToBottom = chatContainer.scrollHeight - chatContainer.scrollTop - chatContainer.clientHeight;
    
    return distanceToBottom <= threshold;
}

```

### 3.2 Integração no Streaming

O fluxo de atualização no `chat-stream.js` (ou `chat-renderer.js`) deve seguir este padrão:

1. **Recebimento do Chunk:** O sistema recebe o novo conteúdo via `NDJSON`.
2. **Verificação:** Executa `shouldScrollToBottom()`.
3. **Ação Condicional:**
```javascript
const isNearBottom = shouldScrollToBottom();
updateMessageContent(chunk); // Renderiza o conteúdo

if (isNearBottom) {
    scrollToBottom(); // Executa o scroll apenas se a condição for verdadeira
}

```



## 4. Requisitos de UX

* **Transição:** O scroll deve ser suave (aplicar `scroll-behavior: smooth` no CSS do container, se possível, para evitar saltos bruscos).
* **Robustez:** A lógica deve ser independente do tamanho da mensagem ou da velocidade do streaming.
* **Compatibilidade:** Deve funcionar independentemente do modelo de IA selecionado (Llama 3, Mistral, etc.), pois a lógica é puramente de UI/Frontend.

---

### Pergunta para prosseguirmos:
Esta é uma excelente melhoria de **UX (User Experience)**. O que você está buscando é o conceito de **Syntax Highlighting** (destaque de sintaxe). Como seu chat usa Markdown para transmitir as respostas, você não precisa "inventar a roda": basta garantir que seu backend envie o código formatado corretamente e que seu frontend use uma biblioteca leve para colorir esse código no momento da renderização.

Aqui está a `spec.md` para implementar essa funcionalidade profissional:

---

# 📋 Spec.md: Syntax Highlighting e Renderização de Código

## 1. Objetivo

Implementar a formatação visual de código (syntax highlighting) na interface do chat para que blocos de código sejam exibidos com cores e fontes tipográficas de editores de código (ex: VS Code), facilitando a leitura técnica.

## 2. Estrutura de Envio (Backend/LLM)

O Ollama, ao ser configurado via `/api/chat`, já retorna o código dentro de blocos de Markdown (````linguagem ... ````).

* **Regra de Ouro:** Garantir que, ao gerar o histórico no banco de dados, o conteúdo seja salvo mantendo a formatação original do Markdown.

## 3. Implementação Frontend (Renderização)

O seu frontend precisa de uma biblioteca que identifique os blocos `<code>` ou `<pre>` e aplique as cores. A recomendação padrão da indústria para projetos web leves é o **Prism.js** ou **Highlight.js**.

### A. Escolha da Biblioteca: [Prism.js](https://prismjs.com/)

* **Por que:** É extremamente leve, modular (você carrega apenas as linguagens que usa, ex: `php`, `javascript`, `sql`, `json`) e possui temas visuais que lembram editores modernos.

### B. O Fluxo de Renderização

1. **Recepção:** O JS recebe o *chunk* do streaming.
2. **Conversão:** Utilize uma biblioteca como `marked.js` para converter o Markdown em HTML.
3. **Coloração:** Após o streaming da mensagem terminar (ou a cada novo bloco gerado), dispare a função de realce:
```javascript
// Exemplo de como disparar o highlight após o conteúdo ser inserido
Prism.highlightAll(); 

```



## 4. Requisitos Técnicos

* **Temas:** Utilizar um tema de fundo escuro (ex: `prism-tomorrow` ou `prism-vsc-dark-plus`) para combinar com o ambiente de desenvolvimento.
* **Line Numbers:** Opcional: Adicionar um plugin de `line-numbers` para facilitar a referência de código em conversas técnicas.
* **Copy Button:** Implementar um pequeno botão "Copy" no topo de cada bloco de código, que interage com a Clipboard API do navegador.

## 5. Protocolo de Resposta do Sistema

* O agente, ao gerar código, **deve** ser instruído (via `System Prompt` no `Agent.md`) a sempre especificar a linguagem no início do bloco Markdown.
* *Exemplo:* ````php` ao invés de apenas `````. Isso garante que o motor de realce saiba qual sintaxe aplicar.



---

### Exemplo de como o código ficaria estruturado na tela:

> **[Botão Copiar]**
> ```php
> <?php
> namespace App\Services;
> 
> class ChatService {
>     public function persistMessage(string $content) {
>         // ... lógica do SQLite
>     }
> }
> 
> ```
> 
> 

---

### Dica de Arquiteto:

Como você é um dev focado em performance, o **Highlight.js** é um pouco mais fácil de integrar do que o Prism, pois ele possui um modo de detecção automática de linguagem.

**Quer que eu te ajude a gerar o snippet de código JS e CSS para você plugar no seu projeto e já ver essa mágica acontecer no seu próximo chat?**
Aqui está a especificação técnica consolidada, incluindo as regras de validação, o design do botão e o fluxo de trabalho detalhado.

---

# 📋 Spec.md: Prompt Generator (Olliverse Engine) - V3

## 1. Visão Geral

Adicionar a funcionalidade "Gerar com IA" para transformar descrições simples em System Prompts estruturados em YAML. O objetivo é aumentar a produtividade ao configurar agentes, garantindo uma interface limpa e uma experiência de usuário intuitiva.

## 2. Regras de Negócio e UX (Design)

* **Validação Obrigatória:**
* O botão de "Gerar" **deve estar desabilitado (`disabled`)** por padrão.
* A ativação do botão está condicionada ao preenchimento dos campos `Nome` e `Descrição`. O frontend deve monitorar esses campos e habilitar o botão automaticamente assim que ambos contiverem conteúdo válido.


* **Localização e Estilo do Botão:**
* **Localização:** Deve ser posicionado próximo ao rótulo (label) ou à borda superior da `textarea` do campo "System prompt", para manter a proximidade visual com o campo que será preenchido.
* **Estilo:** Botão de tamanho pequeno (compacto/`sm`), preferencialmente com um ícone visual (ex: 🪄 ou ✨) para indicar a natureza inteligente da ação sem sobrecarregar o layout.



## 3. Fluxo do Sistema

1. **Validação:** O usuário preenche `Nome` e `Descrição`. O sistema valida os campos e habilita o botão.
2. **Ação:** O usuário clica no botão "Gerar Prompt".
3. **Processamento:**
* O frontend envia uma requisição para o backend.
* O backend compõe o Meta-Prompt utilizando os dados fornecidos.
* O sistema consome a API `/api/generate` do Ollama.


4. **Saída:** O YAML gerado é injetado automaticamente na `textarea` do "System prompt".

## 4. O Meta-Prompt (O "Cérebro" da Funcionalidade)

O backend deve utilizar o seguinte template para garantir a consistência da estrutura:

> "Você é um especialista em engenharia de prompts. Crie um System Prompt estruturado em YAML para uma persona de IA com as seguintes informações:
> Nome: {NOME_DO_USUARIO}
> Descrição: {DESCRICAO_DO_USUARIO}
> O YAML deve obrigatoriamente conter as chaves: name, role, persona_traits, skills e directives.
> Responda apenas com o bloco YAML puro, sem explicações adicionais."

## 5. Requisitos Técnicos

* **Endpoint:** `/api/generate` (otimizado para tarefas de processamento de texto rápido).
* **Tratamento de Saída:** O sistema deve implementar um parser simples que extraia estritamente o bloco YAML, ignorando possíveis preâmbulos ou textos de cortesia gerados pelo modelo.
* **Feedback:** O botão deve exibir um estado de *loading* (ex: alterando texto para "Gerando...") e ficar bloqueado durante a requisição para evitar chamadas duplicadas.

---

**Dica de implementação:** Como você é um Senior Full Stack, essa lógica pode ser encapsulada em um `PromptGeneratorService` no seu backend PHP, garantindo que a regra de "não gerar se vazio" seja validada também no servidor, protegendo a API de chamadas indevidas.

**Deseja que eu prepare o esqueleto dessa classe `PromptGeneratorService` agora?**
# Especificação Técnica: Contexto Dinâmico de Modelos (Integração de Contexto via Ollama API)

## 1. Visão Geral

Atualmente, o limite da janela de contexto no rodapé do Olliverse está fixado em um valor estático (hardcoded em 8000 tokens). O objetivo desta especificação é substituir esse valor estático por uma **leitura dinâmica do teto de contexto fornecido nativamente pelo modelo ativo** no Ollama, garantindo precisão na barra de progresso, melhor gestão de memória (especialmente em hardwares restritos como o Mac M1 de 8GB) e suporte correto a modelos com janelas maiores ou menores.

---

## 2. Requisitos Funcionais

* **RF01 - Consulta de Metadados do Modelo:** Ao inicializar a aplicação ou sempre que o usuário alternar o modelo ativo no seletor de topo, o Olliverse deve consultar os metadados do modelo na API do Ollama.
* **RF02 - Extração do Contexto Máximo:** O sistema deve extrair o parâmetro de contexto máximo suportado ou configurado para o modelo (ex: `num_ctx` ou o limite nativo da arquitetura retornado pelo Ollama).
* **RF03 - Barra de Progresso Dinâmica:** A barra de progresso de contexto no rodapé da interface deve recalcular sua porcentagem e exibição utilizando a fórmula:

$$\text{Porcentagem} = \left(\frac{\text{Tokens Atuais}}{\text{Contexto Máximo do Modelo}}\right) \times 100$$


* **RF04 - Fallback de Segurança:** Caso a API do Ollama falhe em retornar o teto de contexto do modelo por algum motivo, o sistema deve adotar um valor padrão de fallback (ex: 8192 tokens) para evitar quebras visuais na UI.

---

## 3. Arquitetura e Implementação Técnica

### 3.1. Endpoint de Consulta ao Ollama

Para descobrir as propriedades do modelo ativo, o Olliverse deve interagir com o endpoint de inspeção do Ollama:

* **Método:** `POST`
* **URL:** `http://localhost:11434/api/show`
* **Payload Exemplo:**
```json
{
  "name": "qwen2.5-vl"
}

```



### 3.2. Estrutura de Resposta esperada

O Ollama retorna um JSON contendo os detalhes do Modelfile e os parâmetros configurados. O front-end/backend do Olliverse deve parsear o campo de parâmetros para identificar o `num_ctx` ou aplicar o limite padrão da arquiteturação do modelo selecionado.

### 3.3. Ajuste no Componente de UI (Rodapé)

Atualizar a renderização do texto e da barra de progresso para aceitar a propriedade dinâmica:

* **Antigo:** `Contexto 60% (4770/8000)`
* **Novo:** `Contexto [X]% ([Tokens Atuais] / [Contexto Máximo Dinâmico])`

---

## 4. Benefícios Esperados

1. **Precisão de Uso:** O usuário terá clareza exata de quanto da janela real do modelo (seja 8k, 16k, 32k ou mais) está sendo consumida.
2. **Flexibilidade de Hardware:** Evita gargalos ou falsas impressões de lotação ao rodar modelos menores ou maiores no ecossistema local.
3. **Escalabilidade:** Prepara o Olliverse para lidar nativamente com novos modelos lançados sem necessidade de atualizações manuais no código da interface.
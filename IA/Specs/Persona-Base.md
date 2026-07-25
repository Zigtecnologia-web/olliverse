# Especificação Técnica de Funcionalidade (Spec): Persona Base

## Objetivo

Garantir que o sistema possua uma persona padrão, disponível desde a primeira execução, servindo como configuração inicial para qualquer tipo de tarefa.

## Regras de Negócio

### 1. Remoção das personas existentes

Antes da implementação desta funcionalidade, remover todas as personas atualmente cadastradas, pois elas serão substituídas pela nova estrutura.

### 2. Criação da Persona Base

O sistema deve possuir uma única persona padrão, criada automaticamente.

Essa persona:

* deve existir independentemente de outras personas criadas pelo usuário;
* não pode ser editada quanto ao seu propósito padrão (exceto o prompt, caso isso seja uma decisão futura do produto);
* não pode ser excluída;
* deve estar sempre disponível para utilização.

### 3. Dados da Persona Base

**Nome**

```
Assistente Geral
```

**Prompt**

```
Você é um assistente técnico, analítico e pragmático. Entenda a intenção da solicitação antes de responder. Priorize clareza, precisão e objetividade. Explique trade-offs quando existirem, não faça suposições sem evidências e deixe explícitas as incertezas quando necessário. Adapte a profundidade e a linguagem ao contexto e ao nível técnico do usuário.
```

### 4. Unicidade do nome das personas

Não deve ser permitido cadastrar ou renomear uma persona para um nome que já exista.

A validação deve ser **case-insensitive** e **accent-insensitive**, ou seja, deve considerar como equivalentes nomes que diferem apenas por:

* letras maiúsculas ou minúsculas;
* acentos;
* espaços extras no início ou no fim.

Exemplos de nomes considerados iguais:

* `Assistente Geral`
* `assistente geral`
* `ASSISTENTE GERAL`
* `Assistênte Geral`
* `assistente geral`

Ao identificar um nome duplicado, a operação deve ser bloqueada e o sistema deve exibir a mensagem:

```
Já existe uma persona com esse nome. Escolha um nome diferente.
```

## Critérios de Aceitação

* Existe exatamente uma persona padrão chamada **Assistente Geral**.
* A Persona Base é criada automaticamente quando necessário.
* A Persona Base não pode ser excluída.
* É possível criar novas personas normalmente.
* Não é possível criar ou renomear uma persona para um nome já existente, independentemente de maiúsculas, minúsculas, acentuação ou espaços nas extremidades.
* A mensagem de validação é exibida sempre que houver tentativa de duplicidade.

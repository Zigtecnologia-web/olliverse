# Especificação Técnica de Funcionalidade (Spec): Redesenho da Interface do Topo e Organização do Espaço de Trabalho (UI/UX Refactoring)

## 1. Visão Geral e Objetivo

Esta especificação detalha a reestruturação da interface de usuário (UI) do **Olliverse**, focando na remoção da poluição visual do cabeçalho superior (*Header*) e na criação de uma hierarquia espacial mais limpa. O objetivo é desacoplar os controles globais do chat, mover as ferramentas secundárias para uma barra lateral dedicada e organizar de forma modular o contexto de motores, modelos, personas e arquivos anexados.

---

## 2. Requisitos de Experiência do Usuário (UX) e Layout

### 2.1. Descongestionamento do Topo (Header Modularizado)

* **Divisão em Duas Camadas Funcionais:** O cabeçalho atual, que concentra múltiplos botões e seletores na mesma linha, será dividido em duas zonas distintas para dar respiro visual à aplicação:
* **Linha Superior (Identificação e Configuração do Motor):** Exibição exclusiva da marca/logotipo do Olliverse à esquerda e dos seletores críticos de execução (**Motor** e **Modelo**) alinhados à direita.
* **Linha Inferior (Contexto de Sessão e Persona):** Barra secundária dedicada ao seletor de **Persona** ativa e aos controles de estado da conversa.


* **Remoção de Ícones Utilitários Lineares:** A fileira de botões flutuantes (livro, exportação, configurações, novo chat) será retirada do topo horizontal.

### 2.2. Nova Organização das Ferramentas e Utilitários

* **Migração para Barra Lateral de Ferramentas:** Os botões de utilitários globais e de manipulação de chat serão reposicionados.
* **Abordagem de Agrupamento:**
* Ações de gerenciamento de dados/documentos e exportação serão integradas a um menu de contexto unificado ou a uma barra lateral dedicada (próxima ao painel de histórico).
* O botão de "Nova Conversa" (`+`) passa a ancorar de forma fixa no topo do painel de histórico lateral, onde o usuário naturalmente inicia o fluxo de navegação.



### 2.3. Otimização das Tags de Documentos Ativos ("Usar Documentos")

* **Estado Compacto:** As tags longas com nomes de arquivos no topo atual (`alunos_ficticios_bahia.xlsx`) geram ruído visual e truncamento horizontal.
* **Nova Solução:** Substituir a listagem estendida por um indicador de status estilo *badge* resumido (ex: `1 Documento Ativo`), que abre um menu flutuante (*popover*) detalhando os arquivos conectados e permitindo desassociá-los sob demanda.

---

## 3. Especificação de Componentes Visuais

```
+----------------------------------------------------------------------------------------------------+
| [Olliverse]                                           [ Motor: Ollama v ]  [ Modelo: llama3.2 v ]  | -> Linha 1 (Global)
+----------------------------------------------------------------------------------------------------+
| [ Persona: Assistente Técnico v ]   [ 📁 1 Documento Ativo v ]                     [⚙️ Config]     | -> Linha 2 (Sessão)
+----------------------------------------------------------------------------------------------------+
| HISTÓRICO     |                                                                                    |
|               |   Área de Chat, RAG e Visualização de Gráficos (Foco Principal em Tela Limpa)      |
| [Busca...]    |                                                                                    |
|               |                                                                                    |
+----------------------------------------------------------------------------------------------------+

```

---

## 4. Critérios de Aceite

1. **Despoluição do Header:** O topo da aplicação passa a exibir no máximo o logotipo e os seletores de infraestrutura (Motor e Modelo), eliminando a aglomeração de ícones genéricos.
2. **Eficiência de Espaço:** As tags extensas de arquivos anexados são convertidas em um componente compacto e expansível via *popover*.
3. **Ergonomia de Navegação:** Ferramentas utilitárias e de exportação ficam agrupadas de forma lógica e secundária, preservando o campo visual principal para a interatividade do chat e renderização de dados.
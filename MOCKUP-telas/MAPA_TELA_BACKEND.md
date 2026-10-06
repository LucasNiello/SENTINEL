# Mapa tela → o que falta no backend

Gerado em 06/10/2026 a partir do inventário de 44 telas. Origem: **N** = nomeada em documento do projeto, **I** = inferida (precisa de validação do grupo).
As telas marcadas como amostra abrem em `/amostra/{rota}` (só fora de produção).


## A. Públicas

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 1 | Vitrine "/" (farol 3D ligado à rolagem, única ação "Entrar") | N | Já existe (main) | — | Já feita pelo Matheus na branch malaman / main (PR #10). Falta juntar com a Manveru e servir o Three.js localmente (o laboratório não acessa servidor externo). Sem backend. |
| 2 | Login (vazio, erro, carregando, limite de tentativas) | N | Amostra criada | `/amostra/login` | Login e throttle já existem (RF10). Falta só herdar a casca visual; sem backend novo. |
| 3 | Loader 2D (farol girando) | N | Já existe (main) | — | Já existe na vitrine da main (landing.css). Falta decidir se a bancada usa algum carregamento inicial. Sem backend. |

## B. Casca

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 4 | Layout base: header, navegação e footer | N | Amostra criada | `/amostra/casca` | Papel e nome do usuário vêm do ContextoUsuario (existe). O "estado do agente" do header ainda não tem fonte de dados definida. |
| 5 | Menu com itens "em breve" | N | Amostra criada | `/amostra/casca?menu=breve` | Sem backend. Os itens são ligados conforme cada rota nascer (Fase 5). |
| 6 | Versão celular e tablet (375 px) | N | Amostra criada | `/amostra/responsivo` | Sem backend. |

## C. Agente

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 7 | Chat vazio / boas-vindas | I | Amostra criada | `/amostra/agente/vazio` | Sem backend (texto fixo). |
| 8 | Carregando ("Consultando…") | N | Amostra criada | `/amostra/agente/carregando` | Já existe no agente atual. |
| 9 | Resultado de leitura (tabela crua) | N | Amostra criada | `/amostra/agente/leitura` | Existe para as 6 consultas. Falta só o formato (números em mono) — é front. |
| 10 | Aviso ("havia 2 pedidos…") | N | Amostra criada | `/amostra/agente/aviso` | Já existe (campo "aviso"). |
| 11 | Botão exportar CSV na tabela | N | Amostra criada | `/amostra/agente/leitura#exportar` | Falta o endpoint de exportação respeitando a máscara LGPD (RF02, Fase 5). |
| 12 | Linha da tabela clicável → abre atualizar | N | Amostra criada | `/amostra/agente/linha-clicavel` | Falta atualizar() nos 5 services, as tools atualizar_* e o formulário do RF04 (Fase 5). |
| 13 | Card de confirmação por risco (neutro, âmbar, vermelho; segurando, soltou, quase esgotado, expirado) | N | Amostra criada | `/amostra/agente/confirmacao-escrita` | Hold e expiração já existem. Falta /agente/cancelar auditado (R2), limpar a sessão ao cancelar/expirar (R3) e a frase "Nada foi gravado" ao soltar. |
| 14 | Formulário pré-preenchido pelo agente (RF04) | N | Amostra criada | `/amostra/agente/formulario` | FALTA TUDO NO BACKEND: tipo de resposta "formulario", campos vindos do FormRequest, token preso à tool, filtro de campos, auditoria proposto × confirmado. Não conta como pronto. |
| 15 | Negado por permissão | N | Amostra criada | `/amostra/agente/negado` | Existe; falta revisar a redação em linguagem simples (R6). |
| 16 | Erros: rede, servidor, limite (429), tool desconhecida | N | Amostra criada | `/amostra/agente/erro-rede` | Existem; falta "Tool desconhecida" virar frase simples e a mensagem de erro não sumir sozinha (Tarefa 2). |
| 17 | Popup de resultado (✔ / ✕) | N | Amostra criada | `/amostra/agente/popups` | Existe; falta tirar o fade de 1,5 s e deixar o erro fixo (Tarefa 2). É só front. |
| 18 | Histórico da conversa (memória) e limite | N | Amostra criada | `/amostra/agente/historico` | Falta persistir o histórico na sessão, reenviar no array messages e definir limite (Fase 5, item 5). |

## D. Formulários

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 19 | Cliente (criar / atualizar) | N | Amostra criada | `/amostra/formulario/cliente` | Criar existe como tool; atualizar não existe. Falta expor os campos do ClienteRequest ao formulário. |
| 20 | Fornecedor (criar / atualizar) | N | Amostra criada | `/amostra/formulario/fornecedor` | Idem Cliente. |
| 21 | Funcionário (criar / atualizar, só admin) | N | Amostra criada | `/amostra/formulario/funcionario` | Idem; CPF e salário precisam de máscara/cuidado LGPD no formulário. |
| 22 | Nota fiscal (criar / atualizar) | N | Amostra criada | `/amostra/formulario/nota-fiscal` | Idem; regras condicionais (cliente × fornecedor conforme o tipo) vêm do NotaFiscalRequest. |
| 23 | Categoria de lançamento (criar / atualizar) | N | Amostra criada | `/amostra/formulario/categoria` | Idem Cliente. |
| 24 | Lançamento (criar) | N | Amostra criada | `/amostra/formulario/lancamento` | Tool criar_lancamento existe; ainda não passa por validar() (dívida do CLAUDE.md). |

## E. Modo manual

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 25 | Menu "Cadastros" (porta de entrada) | N | Amostra criada | `/amostra/cadastros` | Falta tudo: rotas e controllers do modo manual (RF05/RNF05, Fase 5). |
| 26 | Listagem de cada entidade com busca, sem IA | I | Amostra criada | `/amostra/cadastros/clientes` | Falta controller de listagem/busca/paginação reaproveitando os services. |
| 27 | Mesma governança no modo manual (RBAC, tenant, validação, confirmação, auditoria) | N | Amostra criada | `/amostra/cadastros#governanca` | Falta reaplicar toda a governança nas rotas manuais. |

## F. Lixeira

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 28 | Lixeira (itens excluídos) | N | Amostra criada | `/amostra/lixeira` | Soft delete existe; falta listar por tenant e as tools de excluir/restaurar (RF08–RF10). Hoje nenhum código chama restaurarDaLixeira. |
| 29 | Confirmação de exclusão (risco "exclusao", borda vermelha) | N | Amostra criada | `/amostra/lixeira?modal=exclusao` | Falta a tool de excluir com risco "exclusao". |
| 30 | Reautenticação do admin para restaurar | N | Amostra criada | `/amostra/lixeira?modal=reautenticacao` | ReautenticacaoAdmin existe como mecanismo; falta rota/tela e gravar QUAL admin autorizou em audit_logs. |
| 31 | Conflito ao restaurar (o "pai" está na lixeira) | N | Amostra criada | `/amostra/lixeira?modal=conflito` | Falta a checagem do pai e a decisão: recusar ou restaurar junto. |
| 32 | Arquivados (retenção expirada) | N | Amostra criada | `/amostra/arquivados` | Comando sentinel:arquivar-expirados existe; falta listagem e a tarefa schedule:run no Agendador do Windows (RF11). |
| 33 | Exclusão física definitiva (admin) | N | Amostra criada | `/amostra/arquivados?modal=exclusao-fisica` | Falta a ação do admin com reautenticação, risco "exclusao" e auditoria (RF12). Nunca automática. |
| 34 | Configuração do prazo de retenção | I | Amostra criada | `/amostra/lixeira/retencao` | Falta definir onde o prazo é guardado (config ou tabela). Não está em nenhum checklist. |

## G. Auditoria

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 35 | Tela de auditoria com filtros | N | Amostra criada | `/amostra/auditoria` | Tabela audit_logs existe; falta rota, consulta com filtros e paginação (RF07). |
| 36 | Detalhe do registro: proposto × confirmado | N | Amostra criada | `/amostra/auditoria?detalhe=3` | Depende do RF04: hoje a auditoria não guarda o que a IA propôs separado do que o humano confirmou. |
| 37 | Coluna de tempo de resposta da IA (RNF04) | N | Amostra criada | `/amostra/auditoria` | Falta medir e gravar o tempo de cada chamada (coluna nova em audit_logs). |
| 38 | Acesso conforme o papel (tela negada) | N | Amostra criada | `/amostra/auditoria?papel=leitura` | Falta definir quais papéis acessam a auditoria (aqui: só admin, hipótese). |

## H. Sistema

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 39 | Erro 419 (sessão expirada) | N | Amostra criada | `/amostra/erro/419` | Tarefa B7: página em português usando o layout. |
| 40 | Erro 429 (muitos pedidos) | N | Amostra criada | `/amostra/erro/429` | Idem (B7). O limite de 10 pedidos/min já existe. |
| 41 | Erro 403 (sem permissão) | I | Amostra criada | `/amostra/erro/403` | Falta a view de erro 403 no layout. |
| 42 | Erro 404 (não encontrado) | I | Amostra criada | `/amostra/erro/404` | Falta a view de erro 404 no layout. |
| 43 | Erro 500 (erro inesperado) | I | Amostra criada | `/amostra/erro/500` | Falta a view de erro 500 no layout (sem vazar detalhes técnicos). |

## I. Indefinida

| # | Tela | Orig. | Situação | Rota da amostra | O que falta no backend |
|---|---|---|---|---|---|
| 44 | Fila de comandos | N | HIPÓTESE | `/amostra/fila` | SEM DEFINIÇÃO (Tarefa 5). A tela é uma hipótese: pedidos feitos com a IA fora do ar ficam na fila e só são executados com confirmação humana. |

## Totais

44 telas: 38 nomeadas (N) e 6 inferidas (I). 41 amostras criadas, 2 já existentes na `main` (vitrine e loader), 1 hipótese (fila de comandos).

## Suposições que precisam de confirmação do grupo

- Auditoria visível só para o **admin**.
- Excluir é permitido a **operador e admin**; restaurar exige reautenticação de admin; exclusão física só admin.
- Prazo de retenção fictício de **90 dias** (tela 34, origem I).
- CPF mascarado e salário visível nas listagens (máscara fictícia; regra real é da LGPD/RF02).
- **Fila de comandos (44)**: Tarefa 5 não tem definição; a tela é uma hipótese.
- 6 telas inferidas (7, 26, 34, 41, 42, 43).
- Campo de formulário usa borda em tinta-2 porque o token `borda` tem contraste ~1,3:1 (Tarefa 3 do Matheus).
- Logo SVG provisório inline (não existe `public/marca/`); sem webfont.
# Roteiro da demo para a banca — 15 a 20 min (rascunho)

Contas do seed (domínio `@clinica.sentinel.br`; a senha vem da `DEMO_SENHA` do `.env`; senha de testes anotada pelo Lucas em 26/11, dado de simulação: `Sentinel@2026`): `vet1` (dono), `vet2` (veterinário), `recepcao1`, `estetica1` (atendente), `vendas1` (vendedor), `administracao` (admin).
E-mails completos (11 contas): `vet1@`, `vet2@`, `vet3@`, `recepcao1@`, `recepcao2@`, `recepcao3@`, `estetica1@`, `estetica2@`, `vendas1@`, `vendas2@` e `administracao@`, todos seguidos de `clinica.sentinel.br`.
PIN de teste do supervisor responsável do setor (simulação, anotado pelo Lucas em 26/11): `estetica1_262096`.
PIN de teste do supervisor de vendas (simulação, anotado pelo Lucas em 26/11): `Vendas1_865872`.
PIN de teste do dono, vet1 (simulação, anotado pelo Lucas em 26/11): `Vet1_253764`.

## Antes de entrar na sala (checklist, 5 min)
- Banco semeado **no dia** (as datas são relativas ao dia do seed): `migrate:fresh --seed` com `DEMO_SENHA`. O bloco lotado é "hoje" na primeira hora cheia ainda não passada (9h–16h); se a demo for à noite ou domingo, ele cai no próximo dia útil às 10h. Conferir em `/agenda` qual é o horário e anotar: **HH:MM do bloco lotado = ____**.
- `.env`: `APP_DEBUG=false`, `ANTHROPIC_API_KEY` presente. Servidor em `http://127.0.0.1:8000`.
- Celular: `adb reverse tcp:8000 tcp:8000` e abrir `http://localhost:8000` no Chrome do celular (por IP da rede local não instala, falta contexto seguro).
- Três janelas já logadas (navegador normal + 2 janelas anônimas): vet1, recepcao1, vet2. Trocar de conta ao vivo gasta tempo.
- Vagas do painel em 4 (padrão) antes de começar.

## Passo a passo

| # | Min | Quem | Tela | Mostrar | Falar (1 linha) | Pedido exato ao agente |
|---|---|---|---|---|---|---|
| 1 | 0–1 | — | `/login` | Login com o farol animado | "Sentinel é um sistema interno de clínica veterinária e petshop com um agente de IA que nunca grava sem confirmação humana." | — |
| 2 | 1–3 | recepcao1 | `/agenda` | Agenda da semana cheia (clínica e petshop) | "Cada pessoa vê só o que o seu papel permite." | — |
| 3 | 3–6 | recepcao1 | `/agente` | **Agente abrindo a ficha** | "O agente não pede dado no chat: abre a ficha preenchida." | `Mostre a agenda de amanhã` e depois `Remarque o segundo para as 15h` |
| 4 | 6–7 | recepcao1 | card de remarcação | **Confirmação humana**: Revisar → Confirmar; calendário com dia lotado apagado e "horário · vagas" | "O agente propõe, a pessoa confere e confirma; só então grava, e fica auditado." | (confirmar no card) |
| 5 | 7–9 | recepcao1 | `/agente` | **Bloco lotado** | "O servidor, não a IA, aplica o limite de 4 por bloco." | `Agende uma consulta para a Amora hoje às HH:MM com o vet2` (HH:MM anotado no checklist) → card com erro no campo do horário: sem vaga, escolha outro |
| 6 | 9–10 | recepcao1 | `/agente` | Recusa por permissão e destrutiva | "Ações destrutivas pedem segurar 2 s; estorno pede PIN que a IA nunca vê." | `Cancele o agendamento da Amora` (ou de qualquer pet da agenda) → segurar 2 s; depois `Agende uma cirurgia para o Rex` → recusa: sem CRMV |
| 7 | 10–12 | vet2 | `/agente` e ficha do pet | **Prontuário só do veterinário** | "O agente não preenche prontuário; só o veterinário autor edita e assina, com CRMV." | `Registre o prontuário da consulta do Rex` → recusa ("operação não suportada"); mostrar que a ficha do pet não traz prontuário |
| 8 | 12–14 | vet1 (dono) | `/painel?aba=clinica` | **Painel do dono mudando as vagas** | "O dono configura a clínica e a agenda obedece na hora." | Aba **Clínica** (`/painel?aba=clinica`): campo "Vagas por bloco (por veterinário)" de 4 para 2 e botão **Salvar configuração** |
| 9 | 14–15 | recepcao1 (ou vet1) | `/agenda` e `/agente` | **Agenda respeitando** | "Os blocos que já passaram do limite não aceitam mais ninguém." | `Agende uma consulta para o Bidu amanhã às 10h com o vet2` → recusa por vaga (se o bloco já tem 2); devolver vagas para 4 depois |
| 10 | 15–17 | vet1 | `/resultados` | **B.I. por ano** | "Faturamento, estornos, despesas e lucro por dia, semana, mês e ano; só o dono vê." | Clicar Ano, depois Mês; mostrar os meses anteriores sem prejuízo |
| 11 | 17–18 | vet1 | `/supervisao` | PIN do dono | "O PIN é gerado pelo dono ou pelo administrador, mostrado uma vez, só o hash é gravado." | Gerar o PIN do próprio dono (pede a senha de login; anotar o PIN mostrado) |
| 12 | 18–19 | vet1 | `/agente` | Estorno com PIN | "Valor calculado pelo servidor; a IA nunca informa valor." | `Estorne a cobrança do Rex, tudo menos o prontuário` → segurar + digitar o PIN no card |
| 13 | 19–20 | — | celular | **App no celular (PWA)** | "Instalável, com manifest e service worker; o agente nunca é guardado em cache." | Abrir `localhost:8000` no Chrome do celular → "Instalar app" → abrir `/agente` |

Se faltar tempo, cortar os passos 6, 11 e 12 (nesta ordem). Se sobrar, mostrar `estetica1` fazendo o banho conflitante (pedido 26: recusa por conflito) e `vendas1` sem acesso à agenda (403).

## Plano B por passo
1. **Login:** se falhar, conferir `DEMO_SENHA` e o limite de 5 tentativas por minuto (esperar 1 min); usar a janela já logada.
2. **Agenda vazia ou sem blocos:** o seed rodou em outro dia; rodar `migrate:fresh --seed` (2 min) ou marcar uma consulta pelo botão da tela em vez do agente.
3. **Agente não abre a ficha ou erra a posição:** abrir `/agenda`, clicar no agendamento e remarcar pela ficha com o calendário (mesma ficha, mesmo servidor); lembrar que "o segundo" só vale logo depois de uma consulta de agenda.
4. **Confirmação:** se o card travar, recarregar e repetir (o rascunho vale 5 min; depois pede de novo). Mostrar o histórico de auditoria como prova da confirmação.
5. **Bloco lotado:** se o agente falhar, tentar o mesmo horário pela tela: o calendário já mostra o bloco como lotado. Se o bloco do seed não estiver lotado, encher à mão com 4 consultas do vet2 no mesmo horário.
6. **Cancelar/cirurgia:** se a IA não chamar a tool, repetir com "alterar status do agendamento X para cancelado"; mostrar a recusa de cirurgia só como fala se travar.
7. **Prontuário:** o agente não precisa acertar a recusa para a prova valer: mostrar o veterinário no prontuário pela ficha e dizer que não existe tool de prontuário no catálogo (há teste automatizado para isso, `AgenteSemProntuarioTest`).
8. **Painel:** se salvar der erro, mostrar a aba Clínica só como leitura e passar para o 9 usando as vagas atuais (4).
9. **Agenda respeitando:** se o servidor aceitar por algum motivo, mostrar o calendário apagando o dia/horário lotado e dizer que o limite também é validado no servidor.
10. **B.I.:** se o ano vier vazio, o seed não rodou nesta versão; mostrar Mês e Semana e dizer que o ano usa o mesmo cálculo (teste automatizado cobre).
11. **PIN:** se a senha de login falhar, usar a conta administracao (também gera PIN) em outra janela.
12. **Estorno:** se o PIN falhar, mostrar a recusa como prova de que o PIN é conferido no servidor; se a cobrança não existir, usar outra paga em `/cobrancas`.
13. **Celular:** se o `adb reverse` ou o Chrome falhar, mostrar a instalação no Chrome do notebook em `127.0.0.1` (mesmo manifest e service worker); levar prints/vídeo do celular como último recurso.

## Perguntas prováveis da banca (respostas curtas)
- **A IA grava sozinha?** Não: toda escrita vira rascunho com Revisar → Confirmar; o servidor valida de novo e grava com os valores da sessão, nunca com o que vem do navegador.
- **E o dado pessoal?** CPF e telefone aparecem completos só nas fichas do tutor e do pet (quem atende precisa); nas listas, nos cards, na auditoria e no chat do agente saem mascarados (3 primeiros e 3 últimos dígitos).
- **Qual o provedor de IA?** API da Anthropic, modelo `claude-haiku-5-5`, chamada REST única e sem estado, isolada em um serviço.
- **O que ficou fora?** Caixa com abertura e fechamento; HTTPS para instalar o app pela rede local; itens do checklist de produção no Notion.

## Pontos a validar na véspera (não confirmados)
- Os pets citados (Rex, Bidu, Amora, Thor, Nina) e os tutores existem no seed; conferir na agenda do dia quais agendamentos existem de fato (o seed grava os horários relativos ao dia).
- Máscara 3+3 do CPF e do telefone nas listas, cards, auditoria e chat: confirmar ao vivo que está aplicada (a implementação é da outra janela) e que as fichas do tutor e do pet mostram o número completo.
- Se o agente acerta "o segundo" depois de `Mostre a agenda de amanhã` (ensaiar 3 vezes).

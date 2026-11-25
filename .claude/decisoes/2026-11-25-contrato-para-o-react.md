# Contrato para o React (Matheus): o que mudou no servidor em 25/11 e o que a tela deve fazer

O Blade já segue tudo isto (`bancada`, `ficha-agendamento`, `_acoes`, `consulta`). O React deve fazer igual; o servidor aceita os dois contratos (o antigo `revisar → token → confirmar` continua valendo).

## Respostas de `/agente/comando` e `/agente/formulario` (`confirmacao_pendente`)
- `prazo_s`: `300` = proposta do chat (vence em 5 min; mostrar "prazo terminou" e deixar **Fechar** ativo). `null` = card aberto por tela: **sem contador, sem prazo**.
- `passo_unico: true` (só a agenda: agendar, remarcar, trocar serviço, mudar status): **não há etapa Revisar**. Mostrar só **Confirmar** (+ Cancelar).
- `segurar` / `segurar_quando` (`{campo, valor}`): o segurar de 2 s vale quando o campo `campo` tiver o valor `valor` (hoje: `novo_status = cancelado`); reavaliar quando o campo mudar.

## Confirmar em um passo (`passo_unico`)
- `POST /agente/confirmar` com `{ proposta, valores: {campo: valor}, email_admin?, senha_admin? }` (sem `token`).
- **422 com `formulario`** (vaga, horário, versão, campo inválido): nada foi gravado. Manter o card **aberto e editável**, mostrar `formulario.erro`/`mensagem` e o erro de cada campo, recarregar o calendário e reaparecer o Confirmar. A senha/CRMV digitada foi apagada: avisar.
- Outros erros (403, 409 duplo clique, 5xx, `AI_TOOL_REFUSED` depois de executar): fechar o card e mostrar toast.
- Sucesso: `resultado_escrita` (`mensagem` inclui "Profissional escolhido: X." quando o servidor escolheu).

## Profissional "Qualquer"
- `profissional_id` **em branco** = "qualquer": o servidor escolhe na gravação, dentro da transação (menos atendimentos no dia; empate no menor id; só quem tem vaga no horário). Não existe a pergunta "Quem atende?".
- Cirurgia e internação não aceitam "qualquer" (CRMV): o profissional é explícito.
- UI: **um combobox só** (opção "Qualquer…" sempre presente + os profissionais do setor, filtro por digitação, ARIA combobox/listbox, `autocomplete="off"`), no lugar de "Buscar profissional" + select.
- O campo `profissional_id` do formulário **sempre** acompanha o combobox.

## Calendário
- Clicar num horário **só seleciona** (dá para trocar). Dia, horário e profissional escolhidos ficam **marcados** (quadrado cheio + `aria-pressed`). Horário cheio fica apagado e sem clique.
- `catalogo_item_id` nos formulários de agenda só oferece **serviço** (`tipo=servico`).

## Depois de gravar
- Toda tela com lista **se atualiza sozinha** (refaz a leitura com os mesmos filtros, destaca por alguns segundos o registro novo/alterado, anuncia para leitor de tela). Sem faixa "Atualizar a tela". Falha do refresh: aviso com "Tentar de novo".
- Erro e negação fora do chat: **toast** no topo, centralizado, entrando de cima para baixo (`prefers-reduced-motion` desliga o movimento). No chat do agente, a mensagem continua na conversa.

## Testes de navegador (`tests/browser/Chat*.mjs`)
Os que esperam o botão Revisar nos cards da agenda, "Quem atende?" ou "Buscar profissional" precisam ser atualizados para o contrato acima.

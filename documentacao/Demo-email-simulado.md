# Demo: e-mail simulado com o Mailpit

Só simulação, para explicar o fluxo futuro: o tutor recebe um e-mail quando o agendamento é criado, confirmado ou
cancelado, e as mensagens dos clientes (e-mail hoje; WhatsApp etc. no futuro) chegam ao contato da clínica e aparecem em
**Mensagens novas** no painel do agente. O Mailpit é uma caixa de e-mail falsa local: nada sai para a internet.

## Antes de começar

1. Mailpit rodando (Laragon: `C:\laragon\bin\mailpit\1.22.3\mailpit.exe`). A caixa abre em <http://127.0.0.1:8025>; o SMTP
   escuta em `127.0.0.1:1025`.
2. No `.env` (já no `.env.example`; **confira o seu `.env` existente**: se ainda estiver `MAIL_MAILER=log`, o e-mail com nome
   e endereço do tutor vai parar no `storage/logs` em vez do Mailpit):

   ```
   MAIL_MAILER=smtp
   MAIL_HOST=127.0.0.1
   MAIL_PORT=1025
   MAIL_FROM_ADDRESS="contato@clinica.sentinel.br"
   MAIL_FROM_NAME="Clínica Sentinel"
   ```

   Opcionais (valores padrão): `SENTINEL_EMAIL_CONTATO`, `SENTINEL_CONTATO_TENANT=3`, `MAILPIT_API_URL=http://127.0.0.1:8025`,
   `MAIL_TIMEOUT=3`, `SENTINEL_NOTIFICAR_AGENDAMENTO=true`.
3. `php artisan migrate` (a migration `add_origem_id_to_mensagens_table` evita mensagem duplicada).
4. O seed não envia e-mails (ele desliga o aviso). Os tutores do seed têm e-mail `nome@exemplo.com` (ex.: `ana@exemplo.com`).

## Parte 1: o Sentinel avisa o tutor (saída)

1. No agente, agende uma consulta ou um banho para um pet cujo tutor tenha e-mail e **confirme** o card.
2. Abra o Mailpit: chega "Agendamento recebido — <pet>" para o e-mail do tutor.
3. Confirme o agendamento pelo agente (status `confirmado`): chega "Agendamento confirmado". Cancelar: "Agendamento cancelado".

Não envia: tutor sem e-mail, encaixe de emergência, outros status (realizado, faltou). Remarcar ainda não avisa (fica para
depois, com os calendários). Se o Mailpit estiver fora do ar, o agendamento é gravado do mesmo jeito (só fica um aviso no
`storage/logs`, sem endereço nem texto).

## Parte 2: o cliente escreve para a clínica (entrada)

O Mailpit não tem tela para escrever e-mail. Quem "manda como cliente" é a API de envio dele (`POST /api/v1/send`). O
destinatário **precisa ser** `contato@clinica.sentinel.br`; o remetente pode ser o e-mail de um tutor cadastrado (o app
vincula o tutor por ele, só dentro do tenant).

**PowerShell:**

```powershell
$corpo = @{
  From    = @{ Email = 'ana@exemplo.com'; Name = 'Ana Souza' }
  To      = @(@{ Email = 'contato@clinica.sentinel.br' })
  Subject = 'Remarcar banho'
  Text    = 'Oi! Queria remarcar o banho do Thor para sábado, pode ser?'
} | ConvertTo-Json -Depth 4
Invoke-RestMethod -Uri 'http://127.0.0.1:8025/api/v1/send' -Method Post -ContentType 'application/json; charset=utf-8' -Body ([Text.Encoding]::UTF8.GetBytes($corpo))
```

**cmd** (sem acentos no texto, por causa da página de código do cmd):

```
curl.exe -X POST http://127.0.0.1:8025/api/v1/send -H "Content-Type: application/json" -d "{\"From\":{\"Email\":\"ana@exemplo.com\",\"Name\":\"Ana Souza\"},\"To\":[{\"Email\":\"contato@clinica.sentinel.br\"}],\"Subject\":\"Remarcar banho\",\"Text\":\"Oi! Queria remarcar o banho do Thor para sabado.\"}"
```

Resposta esperada: `{"ID":"..."}`. Depois:

1. Abra o agente com o painel lateral aberto: **Mensagens novas** (com `contato@clinica.sentinel.br` embaixo do rótulo) sobe um.
   O painel importa os e-mails novos ao abrir e ao atualizar depois de cada resposta do agente, e também ao clicar nesse item
   quando ele já tem mensagens (com 0 o item não é clicável: nesse caso, feche e abra o painel, ou peça qualquer coisa ao
   agente). No máximo uma importação a cada ~10 s.
2. Clique em **Mensagens novas**: o chat mostra a tabela, com **Pedir ao agente** e **Marcar como tratada** em cada linha.
3. No Mailpit, o e-mail importado fica marcado como lido (é assim que ele não volta).

Para testar de novo, mande outro e-mail pelo comando acima. Para zerar a caixa do Mailpit, use a lixeira dele na tela
<http://127.0.0.1:8025>.

## Limitações (dizer na banca)

- **Remetente sem SPF/DKIM.** O Mailpit aceita qualquer "De": qualquer pessoa na rede local pode se passar por um tutor. Por
  isso o vínculo do tutor é só contexto (nome e pets na tela); **não é prova de identidade**. O texto do e-mail é tratado
  como dado: puro texto, sem HTML, no máximo 600 caracteres, e qualquer escrita continua exigindo Revisar → Confirmar. Em
  produção, a verificação de remetente (SPF, DKIM, DMARC) e a identificação do tutor viriam do provedor de e-mail.
- **É uma simulação.** Não há e-mail, WhatsApp nem SMS de verdade; o app lê a caixa do Mailpit por HTTP. Responder ao cliente
  não faz parte desta fase.
- A importação é puxada pelo painel (não há processo em segundo plano, o que o agendador do SO pendente impediria).
  Se o painel não estiver aberto, as mensagens esperam no Mailpit até alguém abri-lo.

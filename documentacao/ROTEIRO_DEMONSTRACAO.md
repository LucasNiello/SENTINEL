# Roteiro da demonstração — banca de 25/11/2026

Clínica veterinária + petshop (tenant 3), 10 a 15 minutos. Todos os pedidos ao agente abaixo passaram na bateria com a IA real (`claude-haiku-5-5`, 15/11): bateria 44/44, paráfrases nunca vistas 15/16 e pedidos de agenda por papel 6/6 (`documentacao/testes/fase8/`). O número entre colchetes é o do pedido em `BATERIA_CLINICA.csv`.

## 0. Antes da banca (no dia, 20 min)

1. Banco da demonstração com dados novos: `php artisan migrate:fresh --seed` e depois `php artisan db:seed --class=ClinicaSeeder`. Isso **apaga o banco local**. Faça só no banco da demonstração (MySQL do Laragon). O seed usa datas relativas a hoje.
2. A senha de todas as contas é a de `DEMO_SENHA` no `.env`. Ela não aparece neste roteiro nem na tela. Tenha a senha anotada fora do projetor.
3. `npm run build` (com o node do Laragon) e `php artisan serve` (porta 8000). Abra `http://localhost:8000/login`.
4. Troque a chave da Anthropic antes de apresentar e confira se há saldo. Rode um pedido de teste ("quais atendimentos temos hoje?") com a recepção.
5. Gere o PIN do dono antes de começar (passo 4 abaixo). Assim o estorno não depende de ensaio ao vivo.
6. Feche as abas que não serão usadas. Deixe o Edge em 100% de zoom e o celular carregado, com depuração USB ligada.

## Contas (e-mail; senha = DEMO_SENHA)

| Papel | E-mail | O que mostra |
|---|---|---|
| Dono | `dono@clinica.sentinel.local` | Resultados, supervisão (marca supervisor, gera PIN), estorno |
| Recepção | `recepcao@clinica.sentinel.local` | Agenda, cobranças, receber, estornar (com PIN) |
| Veterinário | `veterinario@clinica.sentinel.local` | Encaixe de emergência, recusa de prontuário |
| Atendente (banho e tosa) | `atendente@clinica.sentinel.local` | Agenda só do banho e tosa |
| Vendedor (loja) | `vendedor@clinica.sentinel.local` | Venda, e a negação da agenda |
| Administrador | `admin@clinica.sentinel.local` | Só a tela de PINs (não autoriza nada) |

## 1. Abertura (1 min)

Mostre a tela de login. Explique que o agente só propõe e que nada é gravado sem revisão e confirmação humana. Diga também que cada pessoa vê só o que o papel permite e que tudo fica na auditoria.

## 2. Recepção: consultar e agendar (3 min)

Entre como **recepção**.
1. "quais atendimentos temos hoje?" [1]: tabela da agenda, borda neutra (leitura).
2. "tem cobrança pra receber?" [4]
3. "agenda uma consulta pro Thor amanhã às 15h com o Dr. Rafael" [7]: o card abre com borda de escrita. Escolha o pet, o serviço e o profissional nas listas (ponto fraco conhecido: a IA não sabe os números), clique em Revisar e depois em Confirmar (um clique).
4. "a Ana pagou a conta da Mel no pix, pode dar baixa" [17]: receber a cobrança.
5. Abra a tela **Agenda** no menu (dia e semana) e a **Cobranças**.

## 3. Continuação e o limite do prontuário (1 min)

Ainda como recepção:
1. "procura o pet Bolt" [31] e depois "muda o peso desse mesmo pra 12 kg" [32]: o agente usa a linha da tabela anterior.
2. "o que o veterinário escreveu no prontuário da Nina?" [42]: o agente recusa. O texto clínico nunca vai para a IA.

## 4. Dono: supervisão e PIN (2 min)

Entre como **dono**.
1. Tela **Supervisão**: marque um supervisor de setor (por exemplo, o Dr. Rafael na clínica).
2. Clique em "Gerar novo PIN" no próprio nome. A tela pede a senha de login, e o código de 6 dígitos aparece **uma vez**. Anote fora do projetor e clique em "Já anotei". Só o hash fica no banco, e a auditoria registra quem gerou, sem o código.
3. Diga que o Administrador também gera e revoga PIN, mas nunca autoriza estorno. Diga também que a senha do dono não serve como PIN.

## 5. Estorno com PIN (2 min)

Volte para a **recepção**.
1. "estorna a consulta da Nina, cobramos errado" [23]: o card do estorno tem borda de exclusão e pede o e-mail e o PIN de quem autoriza. Digite o e-mail do dono e o PIN do passo 4, clique em Revisar e **segure** o botão por 2 s. Soltar antes cancela e mostra "Nada foi gravado".
2. Se sobrar tempo, como dono: "estorne a cobrança do Rex, tudo menos o que veio do prontuário" [25]. O estoque do item do prontuário não volta.

## 6. Loja e permissões (2 min)

Entre como **vendedor**.
1. "vende 2 petiscos naturais e uma bolinha de borracha pro Fábio, pagou no cartão" [20]: o card mostra os itens com os preços do catálogo. A IA nunca informa valor.
2. "agenda um banho pro Thor sexta às 14h" [37]: negado. A negação fica na auditoria.
3. Mostre que o menu do vendedor não tem **Agenda**.

Opcional, como **atendente**: "mostra a agenda de banho e tosa da semana" [29]. O atendente vê só o banho e tosa, no chat e na tela.

## 7. Resultados (1 min)

Como **dono**, abra **Resultados** (mês): faturamento, estornado, líquido, ticket médio, despesas, lucro e os itens mais vendidos. Os números vêm do banco, e a IA não participa.

## 8. Celular / PWA (1–2 min)

- **PC:** no Edge ou no Chrome, em `http://localhost:8000`, use o ícone "Instalar" na barra de endereço.
- **Android:** ligue o celular por USB com a depuração ativa e rode `adb reverse tcp:8000 tcp:8000`. No Chrome do celular, abra `http://localhost:8000` e use o menu → "Instalar app" (ou "Adicionar à tela inicial"). Depois, mostre uma tela de 360 px e o tamanho do texto (A, A+, A++).
- O PWA não funciona offline. O service worker guarda só o visual (escopo de protótipo).

## Pontos fracos conhecidos (diga antes que perguntem)

- **Agendamento:** a IA não sabe os números do pet, do serviço e do profissional, e o card abre com as listas para escolher. O conflito de horário só aparece no Revisar.
- **Variação do modelo:** cada pedido foi medido numa rodada só. A taxa honesta para pedido novo é a das paráfrases (15/16). A frase ambígua "e aquilo, quanto ficou?" pode ser ligada à última proposta.
- **Estoque e receita do item do catálogo:** a IA não presume esses campos por instrução, sem trava no servidor. O card mostra os valores, e quem revisa decide.
- **Atendente pedindo a agenda da clínica no chat:** recebe o banho e tosa sem aviso (como na tela).
- **Supervisão:** marcar o setor e gerar ou revogar o PIN gravam com um clique, sem segurar.
- **Clique ignorado sem aviso:** no botão de segurar, um clique cedo, um duplo clique ou um clique simples numa destrutiva não confirma e não mostra mensagem. É de propósito (não confirma por engano), mas pode parecer que "não fez nada": segure até o fim.
- **Catálogo na descrição da venda:** com mais de 80 itens, a lista sai (num catálogo grande, o item seria resolvido pelo nome no servidor).

## Se algo falhar ao vivo

| Sintoma | O que fazer |
|---|---|
| O agente responde "não consegui" ou demora muito | Rede ou chave: repita o pedido uma vez. Se falhar de novo, mostre a mesma coisa pela tela (Agenda, Cobranças, Vendas, Resultados), que não usa IA. |
| O agente chamou outra ação ou respondeu em texto | Clique em Cancelar (não grava nada) e repita com a frase do roteiro. Comente que é por isso que existe o Revisar. |
| "Confirmação expirada" | Passaram os 5 min da proposta: peça de novo. |
| PIN recusado | Confira o e-mail de quem autoriza (o do dono) e o PIN anotado. Depois de 5 erros, há espera (teto). Se ainda falhar, gere outro PIN na Supervisão (o anterior deixa de valer). |
| "Muitas tentativas" no login | O teto é de 5 tentativas por minuto: espere 1 min ou use outra conta. |
| Card sem o item no estorno parcial | É esperado: escolha o item na lista do card. |
| Tela em branco ou estilo quebrado | Rode `npm run build` de novo e recarregue com Ctrl+F5. |
| O celular não abre `localhost:8000` | Rode `adb reverse tcp:8000 tcp:8000` de novo (cai quando o cabo desconecta). |
| Banco bagunçado depois de ensaiar | Rode o passo 0.1 de novo (apaga e recria os dados da demonstração). |

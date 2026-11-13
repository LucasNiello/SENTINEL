# Remoção do Azure (13/11/2026, decisão do Lucas, 16:02)

Anthropic é o único provedor de IA; padrão no código = `anthropic`, modelo `claude-haiku-5-5`. Etapa 2 (corpo nativo da Anthropic) descartada: o corpo interno e o adaptador ficam como estavam.

## Suíte final: 863/863 (6553 asserções). Chamadas reais: 0 (acumulado segue 169/200).

## Arquivos alterados
- `config/services.php`: bloco `azure_foundry` removido; `ia_provedor` padrão `anthropic`; modelo padrão `claude-haiku-5-5`.
- `app/Services/AiAgentService.php`: sem ramo Azure (disponibilidade, requisição HTTP, seleção); `provedor()` devolve `anthropic` (vazio também) e `null` para qualquer outro valor, inclusive `azure` → falha fechada (503 `AI_PROVIDER_UNAVAILABLE`). Corpo da requisição e adaptador intactos; só comentários e o campo `model` do corpo interno.
- `app/Services/ConversaAgente.php`: chave do Azure fora da lista de segredos redigidos.
- `.env.example`: AZURE_* removido; `SENTINEL_IA_PROVEDOR=anthropic`, `ANTHROPIC_API_KEY=` vazia, `ANTHROPIC_MODEL=claude-haiku-5-5`.
- `instalar.bat`: pede `ANTHROPIC_API_KEY` (entrada oculta, ou `SENTINEL_ANTHROPIC_API_KEY`), grava provedor e modelo padrão; mensagens sem Azure.
- `CLAUDE.md` (só item "Provedor de IA"), `SKILL.md` (backend e testes), `teste.html`: sem Azure/Foundry.
- `phpunit.xml`: `SENTINEL_IA_PROVEDOR=anthropic`.

## Testes
- Conversão num único lugar: `tests/TestCase.php` ganhou credencial falsa da Anthropic e um middleware de resposta (`Http::globalResponseMiddleware`) que converte as respostas simuladas no formato interno (`choices`) para a Messages API. Respostas já no formato Anthropic passam direto.
- Ajustes individuais inevitáveis (asserções sobre a REQUISIÇÃO, que agora é a da Anthropic: `system`, `tools[].name`, `x-api-key`, URL): AgenteTest, AgenteCorrecoesTest, RoteamentoIntencaoTest, ConversaMarcadorTest, MemoriaListaTest, PromptPorDominioTest (prompt principal e lembrete lidos do campo `system`; hash byte a byte inalterado), AnthropicAdaptadorTest. Linhas de config `azure_foundry` removidas dos setUp.
- **Testes existentes com expectativa alterada (para você revisar):**
  1. `AgenteTest::test_n1_content_nulo_sem_tool_calls_continua_texto_vazio` → `..._resposta_vazia_sem_texto_e_sem_tool_vira_erro_amigavel`: resposta vazia agora dá 502 `AI_PROVIDER_RESPONSE_INVALID` (contrato já existente do adaptador da Anthropic; no Azure virava texto vazio).
  2. `AnthropicAdaptadorTest::test_sem_configuracao_o_provedor_continua_o_azure` → `..._e_a_anthropic`.
  3. `test_chave_vazia_no_env_cai_no_padrao_do_modelo`: padrão esperado agora `claude-haiku-5-5`.
  4. `test_requisicao_ao_foundry_...` renomeado para Anthropic (cabeçalho `x-api-key`, URL `/v1/messages`).
- Testes novos: `test_provedor_azure_ou_desconhecido_falha_fechado_sem_chamar_a_rede` (2 casos: `azure`, `antropic`); sem a variável o provedor é `anthropic`.
- Nenhum teste apagado ou pulado. Um deslize meu no meio do caminho (3 testes do fim do AnthropicAdaptadorTest apagados por engano) foi detectado pelo diff e restaurado antes do resultado final.
- Observação: a 1ª execução do subagente mostrou falhas que mudavam entre rodadas; eram do formato de requisição nos testes, já corrigidas; a rodada final é estável (863/863).

## Ficou de fora de propósito
`documentacao/`, relatórios antigos, `.env` (já tem `SENTINEL_IA_PROVEDOR=anthropic`, mas ainda carrega as 3 linhas AZURE_* sem uso: pode apagar à mão), comentário em `AppServiceProvider` ("crédito do Foundry"). Observação de segurança: a chave `sk-ant-...` do `.env` apareceu na saída de uma busca minha nesta sessão; considere rotacioná-la.

## Commit sugerido (só texto)
`refactor(ia): Anthropic como único provedor; remove o Azure (config, serviço, instalador, testes)`

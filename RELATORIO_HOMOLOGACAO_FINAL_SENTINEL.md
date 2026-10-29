# Homologação final — SENTINEL

## Resultado atual — correção definitiva do roteamento (29/10/2026)

**SISTEMA APTO PARA HOMOLOGAÇÃO: SIM**

Este fechamento supera a conclusão anterior; o histórico integral permanece abaixo. Base testada: de8299c31d90a26c3db1f449011ada85c59c507a + correções locais, branch malaman. Commit final será identificado pelo hash real no Notion após envio; um documento não contém seu próprio hash Git.

| Critério | Resultado final |
|---|---|
| Problema anterior | 1/13 pedidos de exclusão como operador virou consultar_clientes / HTTP 200; registro histórico preservado |
| Causa raiz | Catálogo completo + tool_choice auto; RBAC da tool escolhida sem vincular intenção explícita à operação solicitada. Não houve evidência de contaminação pelo histórico na sessão nova |
| Correção | OperacaoSolicitada + catálogo: intenção atual → operação/entidade → RBAC antes da IA → apenas tool correspondente → validação server-side da correspondência → revisão/hold/execução existentes |
| Escopo | Comandos explícitos e vocabulário documentados em ROTEAMENTO_ESCRITA_SENTINEL.md; sem nome/ID QA no código de produção e sem ampliação de RBAC |
| Regressão específica | 28 testes / 141 assertions; sinônimos, consultas legítimas, papéis, outros objetos/tenant, ferramenta incompatível, resposta Azure nominal/JSON/schema |
| Operador real | 20/20 sessões independentes: 403 / negado / excluir_cliente; nenhuma consulta substituta; negativas locais auditadas, sem inferência Azure |
| Azure real | 20/20 sessões independentes como ADMIN: excluir_cliente / confirmação pendente; todas canceladas, sem revisão/hold/confirmação final. Consulta legítima como operador: 200 / consultar_clientes |
| Variações operador | exclua, delete, remova, apague, quero excluir: 5/5 negadas / HTTP 403 |
| Leitura / operador / admin | Leitura negada; operador negado; admin propõe exclusão lógica com confirmação humana; nenhuma exclusão real |
| RNF04 — 20 principais operador | OK; min 120 ms; máx 186 ms; média 147.65 ms; mediana 150 ms; P95 176 ms; 0 >10000 ms |
| Azure admin adicional | min 1553 ms; máx 2566 ms; média 1972.4 ms; mediana 1964 ms; P95 2258 ms; 0 >10000 ms |
| Método estatístico | P95 nearest-rank (posição 19 de 20), mediana das posições 10/11; séries separadas e sem misturar histórico |
| Compatibilidade Azure | Falha intermediária 502 preservada: provedor devolveu stop com tool_calls válido em seleção nominal. Validador ajustado apenas para esse modo; correspondência/JSON/schema continuam obrigatórios |
| Testes completos | 457 testes / 4197 assertions / zero falhas/erros; 32328 ms |
| PHP / plataforma | PHP 8.4.27 TS / composer check-platform-reqs OK |
| Composer audit / npm audit | Zero vulnerabilidades / zero vulnerabilidades |
| Build / migrations / whitespace | OK / 16 aplicadas e zero pendentes / git diff --check OK |
| T0.4 / T6.1 | Documentação PHP corrigida / ALINHADO, exclusão lógica somente admin; política formal mais restritiva |
| RNF04 histórico | Violação isolada de 10922 ms preservada; bateria anterior 12x403 + 1x200 também preservada |
| Notion | AGENTE-ROTA-01 e Homologação 574e359 atualizados; histórico mantido |
| Git anterior ao commit | 26 commits locais e 0 remotos exclusivos; procedência pelo reflog e lista em REVISAO_COMMITS_HOMOLOGACAO_SENTINEL.md; novo commit final somará 1 |
| Segredos e staging | .env ignorado; candidatos e 26 revisões sem alertas de segredos reais; nenhum backup, runtime, cache, vendor, node_modules ou temporário selecionado |
| Pendências restantes | Nenhuma condição funcional/técnica de homologação pendente; envio condicionado à última conferência do staging e remoto |

Amostras: TEMPOS_IA_RNF04_ROTEAMENTO_574e359.csv (operador); RESULTADOS_AZURE_ROTEAMENTO_574e359.csv (Azure/admin); RESULTADOS_VARIACOES_ROTEAMENTO_574e359.csv (variações e falha intermediária). As 20 negativas do operador NÃO são apresentadas como 20 inferências Azure: o RBAC antecipado torna o provedor desnecessário para negar. Para validar o provedor sem contornar a política foram executadas, adicionalmente, 20 inferências reais autorizadas, todas propostas canceladas. Cliente QA id 6 permanece ativo e inalterado. Senhas QA temporárias ficaram somente em memória; hashes originais restaurados.

Commit autorizado quando concluída a inspeção: fix: estabiliza roteamento do agente e conclui homologacao. Destino exclusivo: origin/malaman. Confirmação de hash/push será registrada no Notion após envio.

---

Nota de integridade documental: na revisão do staging, foram removidos somente espaços finais de linhas em NOTION_DIVERGENCIAS_574e359.md e RELATORIO_TESTE_MANUAL_SENTINEL_574e359.md para satisfazer git diff --cached --check. Conteúdo, resultados e links de evidências não mudaram; originais integrais e hashes antes/depois foram preservados externamente em C:/Temp/SENTINEL_ROUTING_20261029. A afirmação histórica de identidade byte a byte descreve a rodada anterior, antes desta normalização de formatação.

## Histórico do fechamento anterior


Data: 29/10/2026, America/Sao_Paulo.

| Item | Resultado |
|---|---|
| Commit/base testada | de8299c31d90a26c3db1f449011ada85c59c507a + alterações locais homologadas anteriormente |
| Branch / remote esperado | malaman / origin/malaman |
| Git status | COM ALTERAÇÕES; alterações anteriores preservadas; nenhum staging, commit ou push nesta rodada |
| PHP | 8.4.27 TS, OPcache ativo |
| Testes automatizados | 429 passaram; 4056 assertions; zero falhas/erros; 45926 ms |
| Permissão/lixeira | 37 testes, 238 assertions; leitura/operador 403; admin segue confirmação |
| Matriz anterior consolidada | 42 OK, 14 CORRIGIDO, 1 ALINHADO; zero bloqueados; histórico preservado |
| T0.4 | Descrição corrigida: PHP 8.4.27 TS em CLI, Composer, Artisan e servidor |
| T6.1 | ALINHADO; IMPLEMENTAÇÃO OK; RBAC OK; ROTEIRO ANTIGO DESATUALIZADO; adotada política formal mais restritiva, exclusão lógica somente admin |
| RNF04 histórico | houve uma violação histórica isolada antes das otimizações/retestes; 10922 ms percebidos e 9066,358 ms HTTP; preservada |
| RNF04 bateria final — tempo | OK: 13/13 <=10000 ms; mínimo 2224, máximo 3598, média 2760, mediana 2608, P95 3598 ms; zero >10 s |
| RNF04 bateria final — funcional | FALHOU: 12 HTTP 403 e 1 HTTP 200; terceira cold-ish selecionou consultar_clientes, confirmado por auditoria |
| Cold-ish | 3 primeiras chamadas em novos contextos/sessões; caches de servidor preservados; 10 chamadas normais consecutivas anteriores |
| Tentativas complementares | 429 por limitador (121 ms), janela respeitada; diagnóstico posterior 403 (2301 ms). Registradas separadamente; não substituem amostras |
| Composer check-platform-reqs | OK |
| Composer audit / npm audit | 0 vulnerabilidades / 0 vulnerabilidades |
| npm run build | OK |
| Migrations | 16 aplicadas, 0 pendentes |
| git diff --check | OK |
| Segredos | Busca em candidatos sem segredo real; .env ignorado; nenhum segredo, .env, backup ou arquivo temporário staged (staging não realizado) |
| Notion | T6.1 alinhado; RNF04 resolvido quanto ao tempo com histórico preservado; homologação registra impedimento funcional e envio não executado |
| Pendências restantes | Pedido exato de exclusão retornou consulta/HTTP 200 em 1/13. Resolver seleção da ferramenta e validar novamente antes de aprovar/envio |
| Commit / push | NÃO CRIADO / NÃO EXECUTADO; condição SISTEMA APTO não satisfeita |

P95 pelo método nearest-rank: posição ceil(0,95 × 13) = 13. Somente as 13 amostras finais entram nas estatísticas; histórico e tentativas complementares separados.

Cliente QA574e359 Descartavel id 6 permanece ativo, sem deleted_at/arquivado_em. Nenhuma exclusão real nesta rodada. Hash de senha QA original restaurado; nenhum segredo registrado. RBAC/código de produto mantidos.

A amostra HTTP 200 levou 2418 ms. O corpo não foi capturado; auditoria em 29/10/2026 14:21:14 e marcador de leitura da sessão confirmam consultar_clientes. Uma repetição posterior 403 não elimina esse resultado. Não houve nova correção arquitetural nem tentativa de ampliar permissões.

**SISTEMA APTO PARA HOMOLOGAÇÃO: NÃO**

Impedimento exato ao commit/push: critério funcional HTTP 403 não atendido em todas as amostras finais. RNF04 temporal passou e a regressão ficou verde, mas a bateria funcional obrigatória não passou integralmente.

Conferência Git após fetch origin: HEAD local de8299c31d90a26c3db1f449011ada85c59c507a; HEAD origin/malaman c8061d5abecb7e8b51ebd31f74803dcaa52286b6. Local 26 commits à frente, sem commits remotos exclusivos. Upstream origin/malaman. Nenhum envio realizado.

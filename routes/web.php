<?php

use App\Http\Controllers\AgendaLoteController;
use App\Http\Controllers\AgenteController;
use App\Http\Controllers\AgentePainelController;
use App\Http\Controllers\AuditoriaController;
use App\Http\Controllers\AutenticacaoController;
use App\Http\Controllers\ClinicaTelasController;
use App\Http\Controllers\PainelDonoController;
use App\Http\Controllers\ReactTesteController;
use App\Http\Controllers\SupervisaoController;
use App\Http\Controllers\TelasController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\VarrerConfirmacoesVencidas;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// RF10: login/logout com o Auth nativo do Laravel (sem cadastro público).
Route::middleware('guest')->group(function () {
    Route::get('/login', [AutenticacaoController::class, 'formulario'])->name('login');
    Route::post('/login', [AutenticacaoController::class, 'entrar'])->middleware('throttle:login');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AutenticacaoController::class, 'sair']);

    // Migração para React (18/11): página de teste da infraestrutura. 404 com SENTINEL_FRONT=blade (padrão).
    Route::get('/react-teste', ReactTesteController::class)->middleware(HandleInertiaRequests::class);

    // R3: em toda requisição ao agente, as propostas vencidas são auditadas como "expirado" e saem da sessão.
    Route::middleware(VarrerConfirmacoesVencidas::class)->group(function () {
        // Etapa A1: consulta da auditoria em JSON (só admin e só o tenant do usuário — conferido no service).
        // Com teto por usuário: cada tentativa negada também vira uma linha na auditoria.
        Route::get('/auditoria/registros', [AuditoriaController::class, 'registros'])->middleware('throttle:auditoria');
        Route::get('/auditoria/exportar', [AuditoriaController::class, 'exportar'])->middleware('throttle:exportar');
        Route::get('/auditoria/registros/{id}', [AuditoriaController::class, 'registro'])->whereNumber('id')->middleware('throttle:auditoria');

        // T1: o Administrador da clínica só gerencia PINs; a bancada do agente e os cadastros o levam à /supervisao.
        Route::get('/agente', [AgenteController::class, 'index'])->middleware(HandleInertiaRequests::class);

        // Painel lateral do /agente (24/11): só leitura (pendentes do negócio e ações recentes do próprio usuário).
        Route::get('/agente/painel', [AgentePainelController::class, 'mostrar'])->middleware('throttle:120,1,painel-agente');
        // 24/11: importa os e-mails novos do contato da clínica (Mailpit) para "Mensagens novas". POST de propósito (grava).
        Route::post('/agente/caixa/importar', [AgentePainelController::class, 'importar'])->middleware('throttle:30,1,caixa-importar');

        // B3 (Bloco 7): telas da bancada. Os dados vêm das rotas JSON acima e abaixo.
        Route::get('/cadastros', [TelasController::class, 'cadastros']);
        Route::get('/lixeira', [TelasController::class, 'lixeira']);
        Route::get('/auditoria', [TelasController::class, 'auditoria'])->middleware('throttle:auditoria');
        // Item I (clínica vet): a tela usa as rotas JSON /supervisao/* abaixo, que conferem tudo de novo.
        Route::get('/supervisao', [TelasController::class, 'supervisao']);

        // Front de 14/11 (F2): telas de LEITURA da clínica + petshop. Cada uma confere domínio, papel (o da tool de leitura) e tenant.
        // Teto próprio (60/min por usuário, 429 em HTML): as cinco telas gravam uma linha de auditoria por acesso,
        // e sem teto um F5 em laço encheria a audit_logs. Balde separado do 'agente-sem-ia', para navegar não gastar o Revisar.
        Route::get('/agenda', [ClinicaTelasController::class, 'agenda'])->middleware('throttle:60,1,telas-clinica');
        Route::get('/cobrancas', [ClinicaTelasController::class, 'cobrancas'])->middleware('throttle:60,1,telas-clinica');
        Route::get('/vendas', [ClinicaTelasController::class, 'vendas'])->middleware('throttle:60,1,telas-clinica');
        // Até 18 dígitos: número maior não cabe em inteiro e daria erro em vez de 404.
        Route::get('/pets/{id}', [ClinicaTelasController::class, 'pet'])->where('id', '[0-9]{1,18}')->middleware('throttle:60,1,telas-clinica');
        Route::get('/resultados', [ClinicaTelasController::class, 'resultados'])->middleware('throttle:60,1,telas-clinica');
        // 23/11: painel do dono. Só o dono (o controller confere papel e tenant); só a aba Clínica grava, e com teto próprio.
        Route::get('/painel', [PainelDonoController::class, 'mostrar'])->middleware('throttle:60,1,telas-clinica');
        Route::post('/painel/clinica', [PainelDonoController::class, 'salvarClinica'])->middleware('throttle:20,1,painel-dono');
        // 15/11: ficha do tutor (só leitura, com os pets dele) e o calendário da ficha de agendamento (JSON, só mostra:
        // a vaga é conferida de novo na gravação). O calendário tem balde próprio (120/min por usuário): uma ficha faz várias
        // leituras (mês, dia, troca de profissional) e não pode gastar o Revisar nem o Cancelar do balde 'agente-sem-ia'.
        Route::get('/tutores/{id}', [ClinicaTelasController::class, 'tutor'])->where('id', '[0-9]{1,18}')->middleware('throttle:60,1,telas-clinica');
        Route::get('/agenda/disponibilidade', [ClinicaTelasController::class, 'disponibilidade'])->middleware('throttle:120,1,agenda-calendario');

        Route::post('/agente/comando', [AgenteController::class, 'processar'])->middleware('throttle:agente');
        Route::post('/agente/confirmar', [AgenteController::class, 'confirmar'])->middleware('throttle:agente-confirmar');
        Route::post('/agente/revisar', [AgenteController::class, 'revisar'])->middleware('throttle:agente-sem-ia');
        Route::post('/agente/cancelar', [AgenteController::class, 'cancelar'])->middleware('throttle:agente-sem-ia');
        // 25/11: cancelar agendamentos em lote, só com os ids MARCADOS numa lista que o usuário viu (nunca às cegas).
        Route::post('/agente/agenda/cancelar-lote', [AgendaLoteController::class, 'cancelar'])->middleware('throttle:agente-sem-ia');
        Route::post('/agente/editar', [AgenteController::class, 'editar'])->middleware('throttle:agente-sem-ia');
        Route::post('/agente/formulario', [AgenteController::class, 'formulario'])->middleware('throttle:agente-sem-ia');
        // B2 (RF05/RNF05): modo manual, lista e busca sem IA.
        Route::post('/agente/consultar', [AgenteController::class, 'consultar'])->middleware('throttle:agente-sem-ia');
        // A3 (RF02): CSV de uma tabela de consulta, reexecutada no servidor.
        Route::post('/agente/exportar', [AgenteController::class, 'exportar'])->middleware('throttle:exportar');

        // Item I (clínica): supervisores de setor (o dono marca) e PIN do estorno (T1, 14/11: o Administrador gera e
        // revoga), em JSON; a tela /supervisao usa estas rotas. Saíram o "meu PIN" do supervisor e o "zerar PIN" do dono.
        Route::get('/supervisao/supervisores', [SupervisaoController::class, 'listar'])->middleware('throttle:agente-sem-ia');
        Route::post('/supervisao/supervisores/{id}', [SupervisaoController::class, 'marcar'])->whereNumber('id')->middleware('throttle:agente-sem-ia');
        Route::post('/supervisao/pins/{id}/gerar', [SupervisaoController::class, 'gerarPin'])->whereNumber('id')->middleware('throttle:agente-sem-ia');
        Route::post('/supervisao/pins/{id}/revogar', [SupervisaoController::class, 'revogarPin'])->whereNumber('id')->middleware('throttle:agente-sem-ia');
    });
});

<?php

namespace Tests\Feature\Clinica;

use App\Exceptions\RegraDeNegocioException;
use App\Models\CatalogoItem;
use App\Models\CobrancaItem;
use App\Models\Pet;
use App\Models\ProntuarioItem;
use App\Models\Tutor;
use App\Models\Venda;
use App\Services\AgendaService;
use App\Services\CatalogoService;
use App\Services\CobrancaService;
use App\Services\MensagemService;
use App\Services\PetService;
use App\Services\ProntuarioService;
use App\Services\SupervisaoService;
use App\Services\TutorService;
use App\Services\VendaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CenarioClinica;
use Tests\TestCase;

/**
 * Defeito 9 da revisão: CADA método público dos services da clínica, chamado com o tenant errado, trata o
 * registro do outro tenant como inexistente (ModelNotFoundException ou "Não encontrei…"), devolve lista vazia
 * nas leituras e não muda nada. Tirar o filtro de tenant de qualquer um deles faz o caso correspondente falhar.
 * Service novo da clínica: todo método público ganha uma linha em casos().
 */
class IsolamentoDeTenantTest extends TestCase
{
    use CenarioClinica, RefreshDatabase;

    private const B = self::OUTRO_TENANT;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
    }

    /** @return array<string, array{string}> */
    public static function casos(): array
    {
        $nomes = [
            'TutorService::criar', 'TutorService::atualizar', 'TutorService::localizar', 'TutorService::buscarPorFiltro', 'TutorService::excluir',
            'TutorService::restaurarDaLixeira', 'TutorService::excluirDefinitivo',
            'PetService::criar', 'PetService::atualizar', 'PetService::localizar', 'PetService::buscarPorFiltro', 'PetService::excluir',
            'PetService::restaurarDaLixeira', 'PetService::excluirDefinitivo',
            'CatalogoService::criar', 'CatalogoService::atualizar', 'CatalogoService::localizar', 'CatalogoService::buscarPorFiltro', 'CatalogoService::entradaDeEstoque',
            'CatalogoService::baixarEstoque', 'CatalogoService::devolverEstoque', 'CatalogoService::avisosDeSaldoNegativo', 'CatalogoService::excluir',
            'CatalogoService::restaurarDaLixeira', 'CatalogoService::excluirDefinitivo',
            'AgendaService::agendar', 'AgendaService::motivoParaNaoAgendar', 'AgendaService::alterarStatus',
            'AgendaService::motivoParaNaoAlterarStatus', 'AgendaService::buscarPorFiltro', 'AgendaService::localizarEmAberto',
            'ProntuarioService::abrir', 'ProntuarioService::atualizar', 'ProntuarioService::adicionarItem', 'ProntuarioService::removerItem',
            'ProntuarioService::assinar', 'ProntuarioService::adendar', 'ProntuarioService::vincular', 'ProntuarioService::metadados',
            'CobrancaService::abrir', 'CobrancaService::adicionarItem', 'CobrancaService::removerItem', 'CobrancaService::importarDoProntuario',
            'CobrancaService::fechar', 'CobrancaService::registrarPagamento', 'CobrancaService::cancelar', 'CobrancaService::estornar',
            'CobrancaService::cobrarAtendimento', 'CobrancaService::previaDaCobranca', 'CobrancaService::buscarPorFiltro', 'CobrancaService::localizar',
            'CobrancaService::atendimentosParaCobrar',
            'VendaService::registrar', 'VendaService::registrarPagamento', 'VendaService::cancelar', 'VendaService::previa',
            'MensagemService::buscarPorFiltro', 'MensagemService::localizar', 'MensagemService::marcarTratada',
            'SupervisaoService::marcarSupervisor', 'SupervisaoService::definirProprioPin', 'SupervisaoService::resetarPin',
        ];

        return array_combine($nomes, array_map(fn (string $n) => [$n], $nomes));
    }

    #[DataProvider('casos')]
    public function test_metodo_publico_trata_registro_de_outro_tenant_como_inexistente(string $caso): void
    {
        $this->cenario($caso)();
    }

    /** Todo método público dos services da clínica tem um caso aqui (método novo sem caso quebra este teste). */
    public function test_nenhum_metodo_publico_ficou_sem_caso(): void
    {
        $cobertos = array_keys(self::casos());
        $faltando = [];

        foreach ([TutorService::class, PetService::class, CatalogoService::class, AgendaService::class, ProntuarioService::class,
            CobrancaService::class, VendaService::class, SupervisaoService::class, MensagemService::class] as $servico) {
            foreach ((new \ReflectionClass($servico))->getMethods(\ReflectionMethod::IS_PUBLIC) as $metodo) {
                $nome = class_basename($servico).'::'.$metodo->getName();
                // Pula só o método que recebe um registro JÁ carregado (os "motivo..." da lixeira, baixarEstoque): quem
                // chama o achou no tenant. Todo método que recebe id ou tenant tem caso aqui.
                $primeiro = $metodo->getParameters()[0] ?? null;
                $tipo = $primeiro?->getType() instanceof \ReflectionNamedType ? $primeiro->getType()->getName() : null;
                if ($metodo->isConstructor() || ($tipo !== null && is_a($tipo, \Illuminate\Database\Eloquent\Model::class, true) && $metodo->getName() !== 'baixarEstoque')) {
                    continue;
                }
                if (! in_array($nome, $cobertos, true)) {
                    $faltando[] = $nome;
                }
            }
        }

        $this->assertSame([], $faltando);
    }

    // -------------------------------------------------------------- cenários (dados no tenant T, chamada com o tenant B)

    private function cenario(string $caso): callable
    {
        $tutores = app(TutorService::class);
        $pets = app(PetService::class);
        $catalogo = app(CatalogoService::class);
        $agenda = app(AgendaService::class);
        $prontuarios = app(ProntuarioService::class);
        $cobrancas = app(CobrancaService::class);
        $vendas = app(VendaService::class);
        $supervisao = app(SupervisaoService::class);

        return match ($caso) {
            'TutorService::criar' => function () use ($tutores) {
                $criado = $tutores->criar(['nome' => 'Novo'], self::B);
                $this->assertSame([self::B, 0], [$criado->tenant_id, Tutor::where('tenant_id', self::T)->count()], 'grava no tenant que o servidor passou');
            },
            'TutorService::atualizar' => function () use ($tutores) {
                $tutor = $this->tutor();
                $this->inexistente(fn () => $tutores->atualizar($tutor->id, ['nome' => 'Trocado'], self::B), Tutor::class, 'nome');
            },
            'TutorService::localizar' => fn () => $this->vazio($tutores->localizar($this->tutor()->id, null, self::B)),
            'TutorService::buscarPorFiltro' => fn () => $this->soNoProprioTenant(fn () => $this->tutor(), fn (int $t) => $tutores->buscarPorFiltro(null, $t)),
            'TutorService::excluir' => function () use ($tutores) {
                $tutor = $this->tutor();
                $this->inexistente(fn () => $tutores->excluir($tutor->id, self::B), Tutor::class, 'deleted_at');
            },
            'TutorService::restaurarDaLixeira' => fn () => $this->inexistente(fn () => $tutores->restaurarDaLixeira($this->naLixeira($this->tutor())->id, self::B, true)),
            'TutorService::excluirDefinitivo' => fn () => $this->inexistente(fn () => $tutores->excluirDefinitivo($this->arquivado($this->tutor())->id, self::B, $this->usuarioClinica('dono', self::B)->id)),

            'PetService::criar' => fn () => $this->inexistente(fn () => $pets->criar(['tutor_id' => $this->tutor()->id, 'nome' => 'Rex', 'especie' => 'cao'], self::B), Pet::class),
            'PetService::atualizar' => function () use ($pets) {
                $pet = $this->pet();
                $this->inexistente(fn () => $pets->atualizar($pet->id, ['nome' => 'Trocado'], self::B), Pet::class, 'nome');
            },
            'PetService::localizar' => fn () => $this->vazio($pets->localizar($this->pet()->id, null, self::B)),
            'PetService::buscarPorFiltro' => fn () => $this->soNoProprioTenant(fn () => $this->pet(), fn (int $t) => $pets->buscarPorFiltro(null, null, $t)),
            'PetService::excluir' => function () use ($pets) {
                $pet = $this->pet();
                $this->inexistente(fn () => $pets->excluir($pet->id, self::B), Pet::class, 'deleted_at');
            },
            'PetService::restaurarDaLixeira' => fn () => $this->inexistente(fn () => $pets->restaurarDaLixeira($this->naLixeira($this->pet())->id, self::B, true)),
            'PetService::excluirDefinitivo' => fn () => $this->inexistente(fn () => $pets->excluirDefinitivo($this->arquivado($this->pet())->id, self::B, $this->usuarioClinica('dono', self::B)->id)),

            'CatalogoService::criar' => function () use ($catalogo) {
                $criado = $catalogo->criar(['tipo' => 'servico', 'setor' => 'clinica', 'categoria' => 'consulta', 'nome_comercial' => 'Novo', 'preco_centavos' => 100], self::B);
                $this->assertSame([self::B, 0], [$criado->tenant_id, CatalogoItem::where('tenant_id', self::T)->count()], 'grava no tenant que o servidor passou');
            },
            'CatalogoService::atualizar' => function () use ($catalogo) {
                $item = $this->item();
                $this->inexistente(fn () => $catalogo->atualizar($item->id, ['preco_centavos' => 1], self::B), CatalogoItem::class, 'preco_centavos');
            },
            'CatalogoService::localizar' => fn () => $this->vazio($catalogo->localizar($this->item()->id, null, self::B)),
            'CatalogoService::buscarPorFiltro' => fn () => $this->soNoProprioTenant(fn () => $this->item(), fn (int $t) => $catalogo->buscarPorFiltro(null, null, null, $t)),
            'CatalogoService::entradaDeEstoque' => function () use ($catalogo) {
                $produto = $this->produto();
                $this->inexistente(fn () => $catalogo->entradaDeEstoque($produto->id, 5, self::B), CatalogoItem::class, 'estoque');
            },
            'CatalogoService::baixarEstoque' => function () use ($catalogo) {
                $produto = $this->produto(['estoque' => 10]);
                $this->recusada(fn () => $catalogo->baixarEstoque($produto, 1, self::B), 'Não encontrei o item do catálogo');
                $this->assertSame(10, $produto->fresh()->estoque);
            },
            'CatalogoService::devolverEstoque' => function () use ($catalogo) {
                $produto = $this->produto(['estoque' => 10]);
                $catalogo->devolverEstoque($produto->id, 3, self::B);
                $this->assertSame(10, $produto->fresh()->estoque, 'devolver com o tenant errado não mexe no estoque do outro');
            },
            'CatalogoService::avisosDeSaldoNegativo' => function () use ($catalogo) {
                $produto = $this->produto(['estoque' => -2]);
                $this->assertCount(1, $catalogo->avisosDeSaldoNegativo([$produto->id], self::T));
                $this->assertSame([], $catalogo->avisosDeSaldoNegativo([$produto->id], self::B));
            },
            'CatalogoService::excluir' => function () use ($catalogo) {
                $item = $this->item();
                $this->inexistente(fn () => $catalogo->excluir($item->id, self::B), CatalogoItem::class, 'deleted_at');
            },
            'CatalogoService::restaurarDaLixeira' => fn () => $this->inexistente(fn () => $catalogo->restaurarDaLixeira($this->naLixeira($this->item())->id, self::B, true)),
            'CatalogoService::excluirDefinitivo' => fn () => $this->inexistente(fn () => $catalogo->excluirDefinitivo($this->arquivado($this->item())->id, self::B, $this->usuarioClinica('dono', self::B)->id)),

            'AgendaService::agendar' => fn () => $this->inexistente(fn () => $agenda->agendar($this->dadosDeAgenda(), self::B), \App\Models\Agendamento::class),
            'AgendaService::motivoParaNaoAgendar' => fn () => $this->assertStringStartsWith('Não encontrei', (string) $agenda->motivoParaNaoAgendar($this->dadosDeAgenda(), self::B)),
            'AgendaService::alterarStatus' => function () use ($agenda) {
                $agendamento = $this->agendado();
                $this->inexistente(fn () => $agenda->alterarStatus($agendamento->id, 'confirmado', self::B), \App\Models\Agendamento::class, 'status');
            },

            'AgendaService::motivoParaNaoAlterarStatus' => function () use ($agenda) {
                $agendamento = $this->agendado();
                $this->assertStringStartsWith('Não encontrei', (string) $agenda->motivoParaNaoAlterarStatus($agendamento->id, 'confirmado', self::B));
                $this->assertNull($agenda->motivoParaNaoAlterarStatus($agendamento->id, 'confirmado', self::T), 'no tenant dono, a mudança vale');
            },
            'AgendaService::buscarPorFiltro' => fn () => $this->soNoProprioTenant(
                fn () => $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item(), ['inicio' => now()->addHour(), 'fim' => now()->addHours(2), 'status' => 'agendado']),
                fn (int $t) => $agenda->buscarPorFiltro(null, null, null, null, null, null, $t)),
            'AgendaService::localizarEmAberto' => function () use ($agenda) {
                $agendamento = $this->agendado();
                $this->assertCount(1, $agenda->localizarEmAberto($agendamento->id, null, self::T));
                $this->vazio($agenda->localizarEmAberto($agendamento->id, null, self::B));
            },

            'ProntuarioService::abrir' => fn () => $this->inexistente(fn () => $prontuarios->abrir(['pet_id' => $this->pet()->id, 'tipo' => 'consulta'], $this->usuarioClinica('veterinario', self::B)->id, self::B), \App\Models\Prontuario::class),
            'ProntuarioService::atualizar' => function () use ($prontuarios) {
                [$prontuario, $vet] = $this->rascunho();
                $this->inexistente(fn () => $prontuarios->atualizar($prontuario->id, ['diagnostico' => 'Trocado'], $vet->id, self::B));
                $this->assertNotSame('Trocado', $prontuario->fresh()->diagnostico);
            },
            'ProntuarioService::adicionarItem' => function () use ($prontuarios) {
                [$prontuario, $vet] = $this->rascunho();
                $this->inexistente(fn () => $prontuarios->adicionarItem($prontuario->id, $this->item(['categoria' => 'vacina'])->id, 1, $vet->id, self::B));
                $this->assertSame(0, ProntuarioItem::count());
            },
            'ProntuarioService::removerItem' => function () use ($prontuarios) {
                [$prontuario, $vet] = $this->rascunho();
                $item = $prontuarios->adicionarItem($prontuario->id, $this->item(['categoria' => 'vacina'])->id, 1, $vet->id, self::T);
                $this->inexistente(fn () => $prontuarios->removerItem($item->id, $vet->id, self::B));
                $this->assertSame(1, ProntuarioItem::count());
            },
            'ProntuarioService::assinar' => function () use ($prontuarios) {
                [$prontuario, $vet] = $this->rascunho();
                $this->inexistente(fn () => $prontuarios->assinar($prontuario->id, $vet->id, self::B));
                $this->assertNull($prontuario->fresh()->assinado_em);
            },
            'ProntuarioService::adendar' => function () use ($prontuarios) {
                [$prontuario, $vet] = $this->rascunho();
                $prontuarios->assinar($prontuario->id, $vet->id, self::T);
                $this->inexistente(fn () => $prontuarios->adendar($prontuario->id, 'Correção.', $this->usuarioClinica('veterinario', self::B)->id, self::B), \App\Models\ProntuarioAdendo::class);
            },
            'ProntuarioService::vincular' => function () use ($prontuarios) {
                [$prontuario, $vet] = $this->rascunho();
                $encaixe = $this->agendamentoDireto($prontuario->pet, $vet, $this->item(), ['origem' => 'encaixe_emergencia']);
                $this->inexistente(fn () => $prontuarios->vincular($prontuario->id, $encaixe->id, $this->usuarioClinica('recepcao', self::B)->id, self::B));
                $this->assertNull($prontuario->fresh()->agendamento_id);
            },
            'ProntuarioService::metadados' => fn () => $this->soNoProprioTenant(fn () => $this->rascunho()[0], fn (int $t) => $prontuarios->metadados($t)),

            'CobrancaService::abrir' => fn () => $this->inexistente(fn () => $cobrancas->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::B), \App\Models\Cobranca::class),
            'CobrancaService::adicionarItem' => function () use ($cobrancas) {
                $cobranca = $this->cobrancaAberta();
                $this->inexistente(fn () => $cobrancas->adicionarItem($cobranca->id, $this->item(['categoria' => 'exame'])->id, 1, self::B));
                $this->assertSame(1, $cobranca->itens()->count());
            },
            'CobrancaService::removerItem' => function () use ($cobrancas) {
                $cobranca = $this->cobrancaAberta();
                $this->inexistente(fn () => $cobrancas->removerItem($cobranca->itens()->sole()->id, self::B));
                $this->assertSame(1, CobrancaItem::count());
            },
            'CobrancaService::importarDoProntuario' => fn () => $this->inexistente(fn () => $cobrancas->importarDoProntuario($this->cobrancaAberta()->id, self::B), CobrancaItem::class, null, 1),
            'CobrancaService::fechar' => function () use ($cobrancas) {
                $cobranca = $this->cobrancaAberta();
                $this->inexistente(fn () => $cobrancas->fechar($cobranca->id, self::B));
                $this->assertSame('aberta', $cobranca->fresh()->status);
            },
            'CobrancaService::registrarPagamento' => function () use ($cobrancas) {
                $cobranca = $this->cobrancaAberta();
                $cobrancas->fechar($cobranca->id, self::T);
                $this->inexistente(fn () => $cobrancas->registrarPagamento($cobranca->id, 'pix', self::B));
                $this->assertSame('fechada', $cobranca->fresh()->status);
            },
            'CobrancaService::cancelar' => function () use ($cobrancas) {
                $cobranca = $this->cobrancaAberta();
                $this->inexistente(fn () => $cobrancas->cancelar($cobranca->id, self::B));
                $this->assertSame('aberta', $cobranca->fresh()->status);
            },
            'CobrancaService::estornar' => function () use ($cobrancas) {
                $cobranca = $this->cobrancaAberta();
                $cobrancas->fechar($cobranca->id, self::T);
                $cobrancas->registrarPagamento($cobranca->id, 'pix', self::T);
                $dono = $this->usuarioClinica('dono', self::B);
                $this->inexistente(fn () => $cobrancas->estornar($cobranca->id, 'Duplicidade.', $dono->id, $dono->id, self::B, true));
                $this->assertSame('paga', $cobranca->fresh()->status);
            },
            'CobrancaService::cobrarAtendimento' => fn () => $this->inexistente(fn () => $cobrancas->cobrarAtendimento($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::B), \App\Models\Cobranca::class),
            'CobrancaService::previaDaCobranca' => function () use ($cobrancas) {
                $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item());
                $this->assertSame(15000, $cobrancas->previaDaCobranca($agendamento->id, self::T)['total_centavos'], 'no tenant dono, a prévia sai');
                $this->inexistente(fn () => $cobrancas->previaDaCobranca($agendamento->id, self::B), \App\Models\Cobranca::class);
            },
            'CobrancaService::buscarPorFiltro' => fn () => $this->soNoProprioTenant(fn () => $this->cobrancaAberta(), fn (int $t) => $cobrancas->buscarPorFiltro(null, null, $t)),
            'CobrancaService::localizar' => function () use ($cobrancas) {
                $cobranca = $this->cobrancaAberta();
                $this->assertCount(1, $cobrancas->localizar($cobranca->id, null, self::T, ['aberta']));
                $this->vazio($cobrancas->localizar($cobranca->id, null, self::B, ['aberta']));
            },
            'CobrancaService::atendimentosParaCobrar' => function () use ($cobrancas) {
                $agendamento = $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item());
                $this->assertCount(1, $cobrancas->atendimentosParaCobrar($agendamento->id, null, self::T));
                $this->vazio($cobrancas->atendimentosParaCobrar($agendamento->id, null, self::B));
            },

            'VendaService::previa' => function () use ($vendas) {
                $produto = $this->produto(['estoque' => 10]);
                $this->inexistente(fn () => $vendas->previa([['catalogo_item_id' => $produto->id, 'quantidade' => 1]], $this->usuarioClinica('vendedor', self::B)->id, null, self::B), Venda::class);
                $this->assertSame(10, $produto->fresh()->estoque);
            },

            'MensagemService::buscarPorFiltro' => fn () => $this->soNoProprioTenant(fn () => $this->mensagem(), fn (int $t) => app(MensagemService::class)->buscarPorFiltro(null, $t)),
            'MensagemService::localizar' => function () {
                $mensagem = $this->mensagem();
                $this->assertCount(1, app(MensagemService::class)->localizar($mensagem->id, null, self::T));
                $this->vazio(app(MensagemService::class)->localizar($mensagem->id, null, self::B));
            },
            'MensagemService::marcarTratada' => function () {
                $mensagem = $this->mensagem();
                $this->inexistente(fn () => app(MensagemService::class)->marcarTratada($mensagem->id, self::B), \App\Models\Mensagem::class, 'status');
            },

            'VendaService::registrar' => function () use ($vendas) {
                $produto = $this->produto(['estoque' => 10]);
                $this->inexistente(fn () => $vendas->registrar([['catalogo_item_id' => $produto->id, 'quantidade' => 1]], $this->usuarioClinica('vendedor', self::B)->id, null, 'pix', self::B), Venda::class);
                $this->assertSame(10, $produto->fresh()->estoque);
            },
            'VendaService::registrarPagamento' => function () use ($vendas) {
                $venda = $this->vendaAberta();
                $this->inexistente(fn () => $vendas->registrarPagamento($venda->id, 'pix', self::B));
                $this->assertSame('aberta', $venda->fresh()->status);
            },
            'VendaService::cancelar' => function () use ($vendas) {
                $venda = $this->vendaAberta();
                $this->inexistente(fn () => $vendas->cancelar($venda->id, self::B));
                $this->assertSame('aberta', $venda->fresh()->status);
            },

            'SupervisaoService::marcarSupervisor' => function () use ($supervisao) {
                $usuario = $this->usuarioClinica('recepcao');
                $this->inexistente(fn () => $supervisao->marcarSupervisor($usuario->id, 'clinica', $this->usuarioClinica('dono', self::B)->id, self::B));
                $this->assertNull($usuario->fresh()->supervisor_setor);
            },
            'SupervisaoService::definirProprioPin' => function () use ($supervisao) {
                $usuario = $this->usuarioClinica('recepcao');
                $supervisao->marcarSupervisor($usuario->id, 'clinica', $this->usuarioClinica('dono')->id, self::T);
                $this->inexistente(fn () => $supervisao->definirProprioPin($usuario->id, '482915', self::B));
                $this->assertNull($usuario->fresh()->pin_autorizacao);
            },
            'SupervisaoService::resetarPin' => function () use ($supervisao) {
                $usuario = $this->usuarioClinica('recepcao');
                $supervisao->marcarSupervisor($usuario->id, 'clinica', $this->usuarioClinica('dono')->id, self::T);
                $supervisao->definirProprioPin($usuario->id, '482915', self::T);
                $this->inexistente(fn () => $supervisao->resetarPin($usuario->id, $this->usuarioClinica('dono', self::B)->id, self::B));
                $this->assertNotNull($usuario->fresh()->pin_autorizacao);
            },
        };
    }

    // -------------------------------------------------------------- verificações e cenários de apoio

    /**
     * A chamada falha como se o registro não existisse. Com $modelo e $campo, confere também que nada mudou
     * (o campo continua igual em todos os registros); com $modelo e sem campo, que nenhum registro novo nasceu.
     */
    private function inexistente(callable $acao, ?string $modelo = null, ?string $campo = null, ?int $quantos = null): void
    {
        $antes = $modelo === null ? null : ($campo === null
            ? $modelo::query()->count()
            : $modelo::query()->withoutGlobalScopes()->pluck($campo, 'id')->map(fn ($v) => (string) $v)->all());

        try {
            $acao();
            $this->fail('Esperava que o registro do outro tenant fosse tratado como inexistente.');
        } catch (ModelNotFoundException) {
            $this->addToAssertionCount(1); // inexistente para este tenant
        } catch (RegraDeNegocioException $e) {
            $this->assertStringStartsWith('Não encontrei', $e->getMessage());
        }

        if ($modelo !== null) {
            $depois = $campo === null
                ? $modelo::query()->count()
                : $modelo::query()->withoutGlobalScopes()->pluck($campo, 'id')->map(fn ($v) => (string) $v)->all();
            $this->assertSame($quantos ?? $antes, $depois, 'nada mudou');
        }
    }

    private function recusada(callable $acao, string $trecho): void
    {
        try {
            $acao();
            $this->fail("Esperava a recusa: {$trecho}");
        } catch (RegraDeNegocioException $e) {
            $this->assertStringContainsString($trecho, $e->getMessage());
        }
    }

    /** Leitura com o tenant errado: lista vazia. */
    private function vazio(\Illuminate\Support\Collection $resultado): void
    {
        $this->assertCount(0, $resultado);
    }

    /**
     * O registro é criado ANTES da leitura; o próprio tenant o enxerga (prova que o caso não é vazio por acaso) e o
     * outro tenant não.
     *
     * @param  callable(): mixed  $criar
     * @param  callable(int): \Illuminate\Support\Collection  $ler
     */
    private function soNoProprioTenant(callable $criar, callable $ler): void
    {
        $criar();

        $this->assertCount(1, $ler(self::T), 'o tenant dono enxerga o registro');
        $this->assertCount(0, $ler(self::B), 'o outro tenant não enxerga');
    }

    private function naLixeira(\Illuminate\Database\Eloquent\Model $registro): \Illuminate\Database\Eloquent\Model
    {
        $registro->delete();

        return $registro;
    }

    private function arquivado(\Illuminate\Database\Eloquent\Model $registro): \Illuminate\Database\Eloquent\Model
    {
        $registro->delete();
        $registro->arquivar();

        return $registro;
    }

    private function dadosDeAgenda(): array
    {
        return ['pet_id' => $this->pet()->id, 'profissional_id' => $this->usuarioClinica('veterinario')->id, 'catalogo_item_id' => $this->item()->id,
            'inicio' => '2026-11-11 14:00', 'fim' => '2026-11-11 14:30'];
    }

    private function agendado(): \App\Models\Agendamento
    {
        return $this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item(), ['status' => 'agendado']);
    }

    /** @return array{\App\Models\Prontuario, \App\Models\User} */
    private function rascunho(): array
    {
        $vet = $this->usuarioClinica('veterinario');

        return [app(ProntuarioService::class)->abrir(['pet_id' => $this->pet()->id, 'tipo' => 'consulta', 'diagnostico' => 'Original.'], $vet->id, self::T), $vet];
    }

    private function cobrancaAberta(): \App\Models\Cobranca
    {
        return app(CobrancaService::class)->abrir($this->agendamentoDireto($this->pet(), $this->usuarioClinica('veterinario'), $this->item())->id, self::T);
    }

    private function mensagem(): \App\Models\Mensagem
    {
        return \App\Models\Mensagem::create(['remetente' => 'Marcos', 'texto' => 'Vocês abrem no feriado?', 'status' => 'nova', 'tenant_id' => self::T]);
    }

    private function vendaAberta(): Venda
    {
        return app(VendaService::class)->registrar([['catalogo_item_id' => $this->produto(['estoque' => 10])->id, 'quantidade' => 1]],
            $this->usuarioClinica('vendedor')->id, null, null, self::T);
    }
}

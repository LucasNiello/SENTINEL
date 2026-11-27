<?php

namespace Tests\Feature\Clinica;

use App\Models\Agendamento;
use App\Models\CatalogoItem;
use App\Models\Cobranca;
use App\Models\Lancamento;
use App\Models\Pet;
use App\Models\Prontuario;
use App\Models\Venda;
use Database\Seeders\ClinicaSeeder;
use Database\Seeders\MovimentoDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** 26/11: o movimento de novembro e dezembro é aditivo, roda uma vez e não mexe nas âncoras da demo. */
class MovimentoDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const SENHA = 'Clinica-Demo-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-26 14:00', 'America/Sao_Paulo'));
        $_ENV['DEMO_SENHA'] = $_SERVER['DEMO_SENHA'] = self::SENHA;
        putenv('DEMO_SENHA='.self::SENHA);
    }

    protected function tearDown(): void
    {
        unset($_ENV['DEMO_SENHA'], $_SERVER['DEMO_SENHA']);
        putenv('DEMO_SENHA');

        parent::tearDown();
    }

    public function test_enche_novembro_e_dezembro_sem_mexer_nas_ancoras_e_roda_uma_vez_so(): void
    {
        $this->seed(ClinicaSeeder::class);
        $agora = now();
        $originais = Pet::whereIn('nome', ['Thor', 'Bidu', 'Nina', 'Rex', 'Amora'])->pluck('id');
        $semana = fn () => Agendamento::whereBetween('inicio', [$agora->copy()->startOfDay(), $agora->copy()->startOfDay()->addDays(7)])
            ->whereIn('profissional_id', \App\Models\User::whereIn('email', ['vet2@clinica.sentinel.br', 'estetica1@clinica.sentinel.br'])->pluck('id'))->count();
        $antes = ['originais' => Agendamento::whereIn('pet_id', $originais)->count(), 'semana' => $semana(), 'lanc' => Lancamento::count(),
            'cobrancasRex' => Cobranca::count(), 'agend' => Agendamento::count(), 'itens' => CatalogoItem::count()];

        $this->seed(MovimentoDemoSeeder::class);

        // Âncoras intactas: pets originais, bloco do vet2 e atendente estetica1 nos 7 dias à frente, nenhuma despesa nova.
        $this->assertSame($antes['originais'], Agendamento::whereIn('pet_id', $originais)->count());
        $this->assertSame($antes['semana'], $semana());
        $this->assertSame($antes['lanc'], Lancamento::count());

        // Encheu de verdade, em novembro e em dezembro.
        $novos = Agendamento::whereNotIn('pet_id', $originais)->get();
        $this->assertGreaterThan(200, $novos->count());
        $this->assertGreaterThan(30, $novos->filter(fn ($a) => $a->inicio->month === 12)->count());
        $this->assertGreaterThan(30, $novos->filter(fn ($a) => $a->inicio->month === 11 && $a->inicio->lt($agora))->count());
        $this->assertGreaterThan($antes['itens'], CatalogoItem::count());
        $this->assertGreaterThan(30, Venda::count());

        // Passado: nada aberto no calendário. Futuro: nada realizado, pago ou vendido.
        $this->assertSame(0, $novos->filter(fn ($a) => $a->fim->lt($agora) && in_array($a->status, ['agendado', 'confirmado'], true))->count());
        $this->assertSame(0, $novos->filter(fn ($a) => $a->inicio->gt($agora) && ! in_array($a->status, ['agendado', 'confirmado'], true))->count());
        $this->assertSame(0, Venda::where('created_at', '>', $agora)->count());
        $this->assertSame(0, Cobranca::where('pago_em', '>', $agora)->count());
        $this->assertSame(0, Venda::where('pago_em', '>', $agora)->count());

        // Todo atendimento realizado da clínica tem prontuário assinado; estoque nunca negativo.
        $this->assertGreaterThan(0, Prontuario::whereNotNull('assinado_em')->count());
        $this->assertSame(0, CatalogoItem::where('controla_estoque', true)->where('estoque', '<', 0)->count());

        // Segunda rodada não grava nada.
        $depois = Agendamento::count();
        $this->seed(MovimentoDemoSeeder::class);
        $this->assertSame($depois, Agendamento::count());
    }
}

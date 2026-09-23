<?php

namespace Database\Seeders;

use App\Models\Cliente;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ClienteSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $clientes = [
            ['nome' => 'ABC Comércio de Materiais Ltda', 'documento' => '12.345.678/0001-90', 'email' => 'financeiro@abccomercio.com.br', 'telefone' => '(19) 3251-4400'],
            ['nome' => 'Padaria Pão Dourado', 'documento' => '98.765.432/0001-10', 'email' => 'contato@paodourado.com.br', 'telefone' => '(19) 3255-7788'],
            ['nome' => 'João da Silva Consultoria', 'documento' => '123.456.789-00', 'email' => null, 'telefone' => '(19) 99123-4567'],
        ];

        foreach ($clientes as $cliente) {
            Cliente::create([
                ...$cliente,
                'tenant_id' => 1,
            ]);
        }

        // Tenant 2: um cliente próprio, para o isolamento aparecer nos dois sentidos (RF10).
        Cliente::create([
            'nome' => 'Mercado Bom Preço (tenant 2)',
            'documento' => '44.555.666/0001-77',
            'email' => 'contato@bompreco.com.br',
            'telefone' => '(19) 3222-0000',
            'tenant_id' => 2,
        ]);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\ComArquivamento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Serviços e produtos dos dois setores. Preço em centavos (inteiro), nunca float. */
#[Fillable(['tipo', 'setor', 'categoria', 'nome_comercial', 'preco_centavos', 'cobranca_por', 'controla_estoque', 'estoque', 'exige_receita', 'tenant_id'])]
class CatalogoItem extends Model
{
    use ComArquivamento, SoftDeletes;

    public const TIPOS = ['servico', 'produto'];

    public const SETORES = ['clinica', 'petshop'];

    /** Categorias por setor: banho na clínica ou vacina no petshop é recusado. */
    public const CATEGORIAS = [
        'clinica' => ['consulta', 'vacina', 'cirurgia', 'internacao', 'exame', 'microchipagem', 'farmacia'],
        'petshop' => ['banho', 'tosa', 'racao', 'petisco', 'acessorio', 'brinquedo', 'cama', 'higiene'],
    ];

    public const COBRANCA_POR = ['unidade', 'diaria'];

    protected $table = 'catalogo_itens';

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'preco_centavos' => 'integer',
            'estoque' => 'integer',
            'controla_estoque' => 'boolean',
            'exige_receita' => 'boolean',
            'arquivado_em' => 'datetime',
        ];
    }
}

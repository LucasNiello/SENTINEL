<?php

namespace App\Models;

use App\Models\Concerns\ComArquivamento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['descricao', 'valor', 'status', 'data', 'tenant_id', 'categoria_lancamento_id', 'cliente_id', 'fornecedor_id', 'funcionario_id'])]
class Lancamento extends Model
{
    use ComArquivamento, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'string',
            'valor' => 'decimal:2',
            'data' => 'date',
            'arquivado_em' => 'datetime',
        ];
    }

    // Sem relação com Tenant: a tabela de tenants ainda não existe no
    // projeto. tenant_id fica como coluna simples até essa estrutura ser criada.

    public function categoriaLancamento(): BelongsTo
    {
        return $this->belongsTo(CategoriaLancamento::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function funcionario(): BelongsTo
    {
        return $this->belongsTo(Funcionario::class);
    }

    public function notasFiscais(): HasMany
    {
        return $this->hasMany(NotaFiscal::class);
    }
}

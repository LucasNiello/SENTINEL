<?php

namespace App\Models;

use App\Models\Concerns\ComArquivamento;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Clínica vet: o responsável pelo pet (o "cliente" do domínio novo). */
#[Fillable(['nome', 'documento', 'email', 'telefone', 'endereco', 'tenant_id'])]
class Tutor extends Model
{
    use ComArquivamento, SoftDeletes;

    protected $table = 'tutores';

    protected function casts(): array
    {
        return [
            'arquivado_em' => 'datetime',
        ];
    }

    public function pets(): HasMany
    {
        return $this->hasMany(Pet::class);
    }

    public function vendas(): HasMany
    {
        return $this->hasMany(Venda::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(Mensagem::class);
    }
}

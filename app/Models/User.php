<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// supervisor_setor e pin_autorizacao ficam fora do Fillable de propósito: só o SupervisaoService os grava.
#[Fillable(['name', 'email', 'password', 'papel', 'tenant_id', 'crmv'])]
#[Hidden(['password', 'remember_token', 'pin_autorizacao'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        // Mudou o papel ou o tenant (item B, 12/11): a marcação de supervisor e o PIN de autorização não acompanham.
        // Quem passa a ter outro papel, vai para outra empresa ou perde o acesso não continua autorizando estorno com
        // o que tinha antes.
        // - Troca de papel: por campo, só fica o que for gravado nesta mesma operação (um PIN antigo não sobrevive
        //   só porque a marcação foi regravada junto).
        // - Troca de tenant: os dois zeram sempre, mesmo gravados junto. Marcar supervisor é ato do dono do tenant
        //   novo, e o PIN só vale com a marcação.
        // Limite: update em massa (query builder) e SQL manual não disparam o evento e pulam esta regra.
        static::updating(function (User $usuario) {
            $trocouTenant = $usuario->isDirty('tenant_id');

            if ($trocouTenant || $usuario->isDirty('papel')) {
                if ($trocouTenant || ! $usuario->isDirty('supervisor_setor')) {
                    $usuario->supervisor_setor = null;
                }
                if ($trocouTenant || ! $usuario->isDirty('pin_autorizacao')) {
                    $usuario->pin_autorizacao = null;
                }
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'tenant_id' => 'integer',
        ];
    }
}

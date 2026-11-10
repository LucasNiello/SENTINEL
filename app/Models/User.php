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
        // Mudou o papel: a marcação de supervisor e o PIN de autorização não acompanham. Quem passa a ter outro
        // papel (ou perde o acesso) não continua autorizando estorno com o que tinha antes.
        static::updating(function (User $usuario) {
            if ($usuario->isDirty('papel') && ! $usuario->isDirty('supervisor_setor') && ! $usuario->isDirty('pin_autorizacao')) {
                $usuario->supervisor_setor = null;
                $usuario->pin_autorizacao = null;
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

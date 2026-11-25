<?php

namespace App\Support;

use Illuminate\Contracts\View\View;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Escolhe entre o front Blade (padrão) e o React/Inertia, por SENTINEL_FRONT.
 * Qualquer valor diferente de "react" vale "blade": com o padrão, o sistema fica idêntico ao de antes da migração.
 */
class Front
{
    public static function react(): bool
    {
        return config('sentinel.front') === 'react';
    }

    /**
     * @param  string  $view  nome da view Blade (modo blade)
     * @param  string  $pagina  nome da página em resources/js/react/Pages (modo react)
     * @param  array<string, mixed>  $dados  os mesmos dados nos dois modos
     */
    public static function tela(string $view, string $pagina, array $dados = []): View|Response
    {
        return self::react() ? Inertia::render($pagina, $dados) : view($view, $dados);
    }
}

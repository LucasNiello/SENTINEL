<?php

namespace App\Http\Controllers;

use App\Support\Front;
use Inertia\Inertia;
use Inertia\Response;

/** Página de teste da infraestrutura React/Inertia. Só existe com SENTINEL_FRONT=react; no modo blade responde 404. */
class ReactTesteController extends Controller
{
    public function __invoke(): Response
    {
        abort_unless(Front::react(), 404);

        return Inertia::render('Teste');
    }
}

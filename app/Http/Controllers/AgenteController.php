<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AgenteController extends Controller
{
    /**
     * Recebe o comando do usuário para o agente.
     *
     * Resposta mockada nesta etapa — sem lógica real ainda.
     */
    public function processar(Request $request)
    {
        return response()->json([
            'status' => 'recebido',
        ]);
    }
}

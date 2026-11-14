<?php

namespace App\Http\Requests;

/**
 * Clínica vet (T2 de 14/11, resposta E): estorno de uma venda paga, por itens, com a mesma regra da cobrança. A venda
 * não tem item de prontuário: o modo é "tudo" ou "itens".
 */
class EstornarVendaRequest extends EstornarCobrancaRequest
{
    public const MODOS = ['tudo', 'itens'];
}

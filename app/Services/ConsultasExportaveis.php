<?php

namespace App\Services;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Str;

/**
 * Etapa A3 (RF02): cada tabela de consulta do agente pode ser exportada. A consulta
 * (tool + argumentos) fica guardada NO SERVIDOR, na sessão, sob um id aleatório; o
 * front só conhece o id. Exportar reexecuta a consulta com o RBAC, o tenant e a
 * máscara de agora — nunca com linhas ou parâmetros enviados pelo navegador.
 */
class ConsultasExportaveis
{
    private const CHAVE = 'consultas_exportaveis';

    /** Só as últimas consultas (a sessão não cresce sem limite). */
    private const MAXIMO = 20;

    /** Depois disso, o Exportar da tabela antiga pede uma consulta nova. */
    public const PRAZO_S = 1800;

    public function __construct(private ContextoUsuario $contexto)
    {
    }

    public function guardar(Session $sessao, string $tool, array $argumentos): string
    {
        $id = (string) Str::uuid();
        $consultas = array_slice([...$sessao->get(self::CHAVE, []), $id => [
            'tool' => $tool,
            'argumentos' => $argumentos,
            'usuario' => $this->contexto->usuario()->getKey(),
            'em' => now()->getTimestamp(),
        ]], -self::MAXIMO, preserve_keys: true);

        $sessao->put(self::CHAVE, $consultas);

        return $id;
    }

    /** A consulta guardada, se for deste usuário e ainda estiver no prazo; senão null. */
    public function ler(Session $sessao, string $id): ?array
    {
        $consulta = $sessao->get(self::CHAVE, [])[$id] ?? null;

        if (! is_array($consulta) || ($consulta['usuario'] ?? null) !== $this->contexto->usuario()->getKey()
            || now()->getTimestamp() - (int) ($consulta['em'] ?? 0) > self::PRAZO_S) {
            return null;
        }

        return $consulta;
    }
}

import { usePage } from '@inertiajs/react';
import Workspace from '../Layouts/Workspace';

// Página de teste da infraestrutura (Fase 1). Confirma que o Inertia, o layout e os dados compartilhados chegam.
export default function Teste() {
    const { usuario, menu } = usePage().props;
    return (
        <Workspace titulo="Teste React">
            <header className="page-header">
                <p className="eyebrow">Infraestrutura</p>
                <h1>Front React ativo</h1>
                <p>Esta página é renderizada por Inertia + React. Com SENTINEL_FRONT=blade ela não existe (404).</p>
            </header>
            <p>Usuário: <strong>{usuario?.nome}</strong> ({usuario?.rotuloPapel}). Itens de menu liberados: {menu.length}.</p>
        </Workspace>
    );
}

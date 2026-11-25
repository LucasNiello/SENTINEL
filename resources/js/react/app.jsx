import '@vitejs/plugin-react/preamble';
import { createInertiaApp } from '@inertiajs/react';

// Páginas React do Sentinel (Inertia). Cada arquivo de Pages/ vira uma tela; o nome vem de Inertia::render('Nome').
const paginas = import.meta.glob('./Pages/**/*.jsx');

createInertiaApp({
    resolve: (nome) => {
        const pagina = paginas[`./Pages/${nome}.jsx`];
        if (!pagina) throw new Error(`Página React não encontrada: ${nome}`);
        return pagina();
    },
});

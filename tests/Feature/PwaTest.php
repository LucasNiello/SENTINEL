<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T3 (14/11): PWA mínimo. O manifest existe e aponta para a bancada; o layout e o login ligam o manifest, a cor do tema e
 * o service worker; o sw.js só guarda o shell estático (ícones, manifest, assets do build) e nunca /agente, JSON, login
 * ou dados. Sem modo offline.
 */
class PwaTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_e_icones(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['Sentinel', 'standalone', '/agente', '#0A0E13'], [$manifest['name'], $manifest['display'], $manifest['start_url'], $manifest['theme_color']]);
        $tamanhos = [];
        foreach ($manifest['icons'] as $icone) {
            $arquivo = public_path(ltrim($icone['src'], '/'));
            $this->assertFileExists($arquivo);
            [$largura, $altura, $tipo] = getimagesize($arquivo);
            $this->assertSame([IMAGETYPE_PNG, $largura], [$tipo, $altura]);
            $this->assertSame($icone['sizes'], "{$largura}x{$altura}");
            $tamanhos[] = $largura;
        }
        $this->assertContains(192, $tamanhos);
        $this->assertContains(512, $tamanhos);
    }

    public function test_layout_e_login_ligam_o_manifest_a_cor_e_o_service_worker(): void
    {
        $this->logarComo('dono', 3);
        foreach ([$this->get('/agente'), $this->get('/supervisao')] as $pagina) {
            $pagina->assertOk()
                ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
                ->assertSee('<meta name="theme-color"', false)
                ->assertSee("navigator.serviceWorker.register('/sw.js')", false)
                ->assertSee('<link rel="stylesheet" href="/css/sentinel-extra.css">', false);
        }

        auth()->logout();
        $this->get('/login')->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee("navigator.serviceWorker.register('/sw.js')", false)
            ->assertSee('<link rel="stylesheet" href="/css/sentinel-login-extra.css">', false);
    }

    public function test_service_worker_so_guarda_o_shell_estatico_e_nunca_o_agente(): void
    {
        $sw = (string) file_get_contents(public_path('sw.js'));

        // A lista fixa do shell e o filtro dos assets do build, lidos do próprio sw.js.
        $this->assertSame(1, preg_match("/const SHELL = \[(.*?)\];/", $sw, $lista));
        $shell = array_map(fn ($s) => trim($s, " '"), explode(',', $lista[1]));
        // O filtro do build é a expressão regular literal do sw.js (/^\/build\/assets\/...$/), usada aqui como está.
        $inicio = strpos($sw, '/^\/build\/assets\/');
        $fim = strpos($sw, '$/.test', (int) $inicio);
        $this->assertNotFalse($inicio);
        $this->assertNotFalse($fim);
        $filtro = '#'.substr($sw, $inicio + 1, $fim - $inicio).'#';

        $guarda = fn (string $caminho) => in_array($caminho, $shell, true) || preg_match($filtro, $caminho) === 1;

        foreach (['/manifest.webmanifest', '/icons/sentinel-192.png', '/build/assets/workspace-DJ0ixdab.css', '/build/assets/ibm-plex-sans-400-600-IvpUvPa2.woff2'] as $estatico) {
            $this->assertTrue($guarda($estatico), $estatico);
        }
        foreach (['/agente', '/agente/comando', '/agente/confirmar', '/agente/consultar', '/agente/exportar', '/login', '/supervisao', '/supervisao/supervisores',
            '/supervisao/pins/1/gerar', '/auditoria/registros', '/', '/build/assets/dados.json', '/build/manifest.json', '/cadastros'] as $dinamico) {
            $this->assertFalse($guarda($dinamico), $dinamico);
        }

        // Só GET, só a mesma origem, sem query; o resto vai à rede, sem resposta inventada (sem offline).
        $this->assertStringContainsString("if (requisicao.method !== 'GET') return false;", $sw);
        $this->assertStringContainsString("url.origin !== self.location.origin || url.search !== ''", $sw);
        $this->assertStringContainsString('if (!ehShellEstatico(evento.request)) return;', $sw);
        $this->assertStringNotContainsString('offline', strtolower(preg_replace('#/\*.*?\*/#s', '', $sw)));
    }
}

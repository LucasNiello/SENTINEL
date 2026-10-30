<?php

namespace Tests\Feature;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        return new DOMXPath($dom);
    }

    public static function papeis(): array
    {
        return [
            ['leitura', ['/agente', '/cadastros']],
            ['operador', ['/agente', '/cadastros', '/lixeira']],
            ['admin', ['/agente', '/cadastros', '/lixeira', '/auditoria']],
        ];
    }

    #[DataProvider('papeis')]
    public function test_navegacao_respeita_papel_e_identifica_a_pagina_atual(string $papel, array $links): void
    {
        $this->logarComo($papel);
        $r = $this->get('/cadastros')->assertOk();
        $dom = $this->dom($r->getContent());
        $nav = $dom->query('//nav[@aria-label="Principal"]/a');
        $this->assertSame($links, array_map(fn ($a) => $a->getAttribute('href'), iterator_to_array($nav)));
        $this->assertSame('/cadastros', $dom->query('//nav/a[@aria-current="page"]')->item(0)?->getAttribute('href'));
        $this->assertSame(1, $dom->query('//form[@action="/logout" and @method="POST"]/input[@name="_token"]')->length);
        $this->assertSame(1, $dom->query('//button[@aria-controls="workspace-sidebar" and @aria-expanded="false"]')->length);
    }

    public function test_sugestoes_sao_botoes_sem_envio_e_contratos_do_composer_permanecem(): void
    {
        $this->logarComo('leitura');
        $dom = $this->dom($this->get('/agente')->assertOk()->getContent());
        $buttons = $dom->query('//*[@data-suggestion]');
        $this->assertSame(2, $buttons->length);
        foreach ($buttons as $b) {
            $this->assertSame('button', $b->tagName);
            $this->assertSame('button', $b->getAttribute('type'));
            $this->assertFalse($b->hasAttribute('onclick'));
        }
        foreach (['historico', 'form-comando', 'campo-mensagem', 'botao-enviar', 'carregando'] as $id) {
            $this->assertSame(1, $dom->query('//*[@id="'.$id.'"]')->length);
        }
        $this->assertSame(1, $dom->query('//label[@for="campo-mensagem"]')->length);
    }

    public function test_todas_as_paginas_de_erro_renderizam_sem_obter_usuario_do_banco(): void
    {
        Auth::shouldReceive('user')->andThrow(new \RuntimeException('banco indisponivel confidencial'));
        foreach ([401, 403, 404, 419, 429, 500, 503] as $codigo) {
            $html = view('errors.'.$codigo)->render();
            $this->assertStringContainsString('Erro '.$codigo, $html);
            $this->assertStringContainsString('href="/login"', $html);
            $this->assertStringNotContainsString('banco indisponivel confidencial', $html);
            $this->assertStringNotContainsString('aria-label="Principal"', $html);
        }
    }
}

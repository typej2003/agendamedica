<?php

namespace Tests\Unit\Services;

use App\Services\Chatbot\LimiteDeMensajes;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * **El tope de mensajes por teléfono**: permite los primeros N y corta el resto; no mezcla teléfonos.
 */
class LimiteDeMensajesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_corta_al_superar_el_limite_de_la_ventana(): void
    {
        $limite = new LimiteDeMensajes(3, 300);

        $this->assertFalse($limite->superado('584140001122'));
        $this->assertFalse($limite->superado('584140001122'));
        $this->assertFalse($limite->superado('584140001122'));
        $this->assertTrue($limite->superado('584140001122'));
    }

    public function test_cada_telefono_tiene_su_propio_contador(): void
    {
        $limite = new LimiteDeMensajes(1, 300);

        $this->assertFalse($limite->superado('584140001122'));
        $this->assertTrue($limite->superado('584140001122'));
        $this->assertFalse($limite->superado('584140003344'));
    }

    public function test_sin_telefono_no_limita(): void
    {
        $limite = new LimiteDeMensajes(1, 300);

        $this->assertFalse($limite->superado(null));
        $this->assertFalse($limite->superado(''));
    }

    public function test_la_respuesta_no_expone_el_limite(): void
    {
        $respuesta = (new LimiteDeMensajes())->respuesta();

        $this->assertStringContainsString('muchos mensajes', $respuesta);
        $this->assertStringNotContainsString('40', $respuesta);
        $this->assertStringNotContainsString('300', $respuesta);
    }
}

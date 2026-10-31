<?php

namespace Tests\Unit\Services;

use App\Services\Chatbot\HistorialDeConversacion;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * **La memoria corta del chatbot**: guarda los últimos turnos, recorta al tope y no guarda sin teléfono.
 */
class HistorialDeConversacionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_guarda_y_lee_los_turnos_en_orden(): void
    {
        $historial = new HistorialDeConversacion();

        $historial->recordar('584140001122', 'Hola', '¡Hola! ¿En qué te ayudo?');
        $historial->recordar('584140001122', 'Quiero una cita', '¿Con qué médico?');

        $this->assertSame([
            ['role' => 'user', 'content' => 'Hola'],
            ['role' => 'assistant', 'content' => '¡Hola! ¿En qué te ayudo?'],
            ['role' => 'user', 'content' => 'Quiero una cita'],
            ['role' => 'assistant', 'content' => '¿Con qué médico?'],
        ], $historial->leer('584140001122'));
    }

    public function test_normaliza_el_telefono_y_recorta_al_tope(): void
    {
        $historial = new HistorialDeConversacion();

        for ($i = 1; $i <= 6; $i++) {
            $historial->recordar('+58 414-000-1122', "mensaje {$i}", "respuesta {$i}");
        }

        $guardado = $historial->leer('584140001122');

        $this->assertCount(HistorialDeConversacion::MAX_MENSAJES, $guardado);
        $this->assertSame('mensaje 3', $guardado[0]['content']);
        $this->assertSame('respuesta 6', $guardado[7]['content']);
    }

    public function test_sin_telefono_no_guarda_nada(): void
    {
        $historial = new HistorialDeConversacion();
        $historial->recordar(null, 'Hola', 'Respuesta');

        $this->assertSame([], $historial->leer(null));
        $this->assertSame([], $historial->leer(''));
    }

    public function test_recorta_mensajes_demasiado_largos(): void
    {
        $historial = new HistorialDeConversacion();
        $historial->recordar('584140001122', str_repeat('a', 2000), 'ok');

        $guardado = $historial->leer('584140001122');

        $this->assertSame(HistorialDeConversacion::MAX_CARACTERES + 1, mb_strlen($guardado[0]['content']));
        $this->assertStringEndsWith('…', $guardado[0]['content']);
    }

    public function test_olvidar_borra_la_memoria_del_telefono(): void
    {
        $historial = new HistorialDeConversacion();
        $historial->recordar('584140001122', 'Hola', 'Respuesta');
        $historial->olvidar('584140001122');

        $this->assertSame([], $historial->leer('584140001122'));
    }
}

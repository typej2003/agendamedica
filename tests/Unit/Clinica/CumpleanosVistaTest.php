<?php

namespace Tests\Unit\Clinica;

use App\Cumpleanos\ConfiguracionDeCumpleanos;
use App\Cumpleanos\Cumpleano;
use App\Cumpleanos\MensajeDeCumpleanos;
use App\Cumpleanos\Vistas\CumpleanosVista;
use App\Notificaciones\Canal;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * El ViewModel de la pantalla de **cumpleaños** (WEB-2.9), sin base de datos: entradas armadas a mano y
 * los textos que la vista pinta.
 *
 * Lo que se prueba es lo que la pantalla **dice**: la edad que cumple cada uno, a quién se le puede
 * mandar por el canal configurado, quién ya tiene el saludo y con qué tono, la vista previa del mensaje
 * y cuál es el canal recomendado.
 */
class CumpleanosVistaTest extends TestCase
{
    private function cumpleano(array $paciente = [], string $nacimiento = '1990-10-10', bool $exacto = true): Cumpleano
    {
        $modelo = new \App\Models\Paciente(array_merge([
            'nombres'     => 'Ana',
            'apellidos'   => 'Pérez',
            'fnacimiento' => $nacimiento,
            'telefono'    => '0414-1234567',
            'email'       => 'ana@example.com',
        ], $paciente));

        // `id` no es asignable en masa (la tabla del legado la llena el motor): se fija a mano.
        $modelo->id = $paciente['id'] ?? 1;

        return new Cumpleano($modelo, Carbon::parse('2026-10-10'), $exacto);
    }

    private function vista(array $cumpleanos = [], array $enviadosEl = [], string $canal = Canal::CORREO, string $plantilla = ''): CumpleanosVista
    {
        return new CumpleanosVista(
            Carbon::parse('2026-10-10'),
            new Collection($cumpleanos),
            new ConfiguracionDeCumpleanos(true, $canal, '09:00'),
            $canal,
            $plantilla,
            'Dr. Carlos Mendoza',
            new MensajeDeCumpleanos(),
            $enviadosEl,
        );
    }

    public function test_el_subtitulo_dice_el_dia_el_conteo_y_el_medico(): void
    {
        $vm = $this->vista([$this->cumpleano()]);

        $this->assertSame('Cumpleaños', $vm->titulo());
        $this->assertSame('Sábado 10/10/2026 · 1 paciente. Los pacientes de Dr. Carlos Mendoza que cumplen años hoy.', $vm->subtitulo());
        $this->assertSame('1 paciente', $vm->conteo());
        $this->assertSame(1, count($vm->filas()));
    }

    public function test_la_fila_trae_la_edad_los_datos_de_contacto_y_el_estado(): void
    {
        $vm = $this->vista([$this->cumpleano()], [], Canal::CORREO);
        $fila = $vm->filas()[0];

        $this->assertSame(1, $fila['pacienteId']);
        $this->assertSame('Pérez, Ana', $fila['paciente']);
        $this->assertSame('36 años', $fila['edad']);
        $this->assertFalse($fila['adelantado']);
        $this->assertSame('0414-1234567', $fila['telefono']);
        $this->assertSame('ana@example.com', $fila['correo']);
        $this->assertTrue($fila['puedeEnviar']);
        $this->assertNull($fila['falta']);
        $this->assertFalse($fila['enviado']);
        $this->assertSame(['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin enviar'], $fila['estado']);

        // El mensaje de la fila es el que el diálogo pone: la plantilla con los datos de ese paciente.
        $this->assertSame('¡Feliz cumpleaños Ana Pérez! Le deseamos un excelente día. Saludos.', $fila['mensaje']);
    }

    public function test_una_fila_ya_enviada_lo_dice_con_la_hora_del_consultorio(): void
    {
        // `created_at` se guarda en UTC: 13:03 UTC son las 09:03 en Caracas (UTC-4).
        $vm = $this->vista([$this->cumpleano()], [1 => '2026-10-10 13:03:00']);

        $this->assertSame(['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Enviado 09:03'], $vm->filas()[0]['estado']);
        $this->assertTrue($vm->filas()[0]['enviado']);
    }

    public function test_al_que_le_falta_el_dato_del_canal_no_se_le_puede_mandar_y_la_fila_lo_dice(): void
    {
        $sinCorreo = $this->vista([$this->cumpleano(['email' => null])], [], Canal::CORREO);
        $fila = $sinCorreo->filas()[0];

        $this->assertFalse($fila['puedeEnviar']);
        $this->assertSame('correo', $fila['falta']);
        $this->assertFalse($fila['tieneCorreo']);

        // El mismo paciente **sí** puede recibir un SMS: el que decide es el canal configurado.
        $porSms = $this->vista([$this->cumpleano(['email' => null])], [], Canal::SMS);
        $this->assertTrue($porSms->filas()[0]['puedeEnviar']);
        $this->assertNull($porSms->filas()[0]['falta']);
    }

    public function test_el_29_de_febrero_marca_la_fila_como_adelantada(): void
    {
        $vm = new CumpleanosVista(
            Carbon::parse('2026-02-28'),
            new Collection([$this->cumpleano([], '1990-02-29', false)]),
            new ConfiguracionDeCumpleanos(true, Canal::CORREO, '09:00'),
            Canal::CORREO,
            '',
            'Dr. Carlos Mendoza',
            new MensajeDeCumpleanos(),
        );

        $this->assertTrue($vm->filas()[0]['adelantado']);
        $this->assertSame('36 años', $vm->filas()[0]['edad']);
    }

    public function test_el_mensaje_usa_la_plantilla_del_medico_con_sus_etiquetas(): void
    {
        $vm = $this->vista([$this->cumpleano()], [], Canal::SMS, 'Feliz cumple, {paciente}. — {doctor}');

        $this->assertSame('Feliz cumple, Ana Pérez. — Dr. Carlos Mendoza', $vm->filas()[0]['mensaje']);
        $this->assertSame('Feliz cumple, Ana Pérez. — Dr. Carlos Mendoza', $vm->vistaPrevia());
        $this->assertSame('Feliz cumple, {paciente}. — {doctor}', $vm->plantilla());
    }

    public function test_sin_cumpleanos_la_vista_previa_usa_el_ejemplo(): void
    {
        $vm = $this->vista([], [], Canal::CORREO, 'Feliz cumple, {paciente}.');

        $this->assertTrue($vm->estaVacio());
        $this->assertSame('Hoy no cumple años ningún paciente.', $vm->textoVacio());
        $this->assertSame('Feliz cumple, Ana Pérez.', $vm->vistaPrevia());
        $this->assertSame('0 pacientes', $vm->conteo());
    }

    public function test_el_correo_es_el_canal_recomendado(): void
    {
        $vm = $this->vista();
        $opciones = $vm->opcionesDeCanal();

        $this->assertSame(['sms', 'whatsapp', 'correo'], array_column($opciones, 'valor'));
        $this->assertSame(
            ['sms' => false, 'whatsapp' => false, 'correo' => true],
            array_column($opciones, 'porDefecto', 'valor'),
        );

        // Solo el elegido viene marcado.
        $this->assertSame(
            ['sms' => false, 'whatsapp' => false, 'correo' => true],
            array_column($opciones, 'elegido', 'valor'),
        );

        $this->assertStringContainsString('caro', $vm->ayudaDelCanal(Canal::SMS));
        $this->assertStringContainsString('plantillas aprobadas', $vm->ayudaDelCanal(Canal::WHATSAPP));
        $this->assertStringContainsString('tal cual', $vm->ayudaDelCanal(Canal::CORREO));
    }

    public function test_la_configuracion_se_muestra_tal_como_esta_guardada(): void
    {
        $vm = $this->vista();

        $this->assertTrue($vm->envioActivo());
        $this->assertSame('09:00', $vm->hora());
        $this->assertSame('2026-10-10', $vm->fechaParaElFormulario());
        $this->assertSame('Dr. Carlos Mendoza', $vm->doctor());
    }
}

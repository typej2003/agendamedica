<?php

namespace Tests\Unit\Clinica;

use App\Clinica\FechaDelConsultorio;
use App\Cumpleanos\ConfiguracionDeCumpleanos;
use App\Cumpleanos\Cumpleano;
use App\Cumpleanos\Cumpleanos;
use App\Cumpleanos\MensajeDeCumpleanos;
use App\Models\Evolucion;
use App\Models\Paciente;
use App\Notificaciones\Canal;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * La **regla del cumpleaños** (WEB-2.9), sin base de datos: qué días se saludan, la edad que se cumple y
 * el **29 de febrero**.
 *
 * Es la parte que hay que poder probar sola: el día que se saluda decide a quién le llega el saludo (y a
 * quién no), y el 29/02 es la única fecha que no existe todos los años.
 */
class CumpleanosTest extends TestCase
{
    private function paciente(array $datos = []): Paciente
    {
        return new Paciente(array_merge([
            'id'          => 1,
            'nombres'     => 'Ana',
            'apellidos'   => 'Pérez',
            'fnacimiento' => '1990-10-10',
            'telefono'    => '0414-1234567',
            'email'       => 'ana@example.com',
        ], $datos));
    }

    // ---------------------------------------------------------------------------- Regla del cumpleaños

    public function test_un_dia_cualquiera_saluda_a_los_que_cumplen_ese_dia(): void
    {
        $dia = Carbon::parse('2026-10-10');

        $this->assertSame(['10-10'], Cumpleanos::diasQueSeSaludan($dia));
        $this->assertTrue(Cumpleanos::caeEl('1990-10-10', $dia));
        $this->assertTrue(Cumpleanos::caeEl('1990-10-10 00:00:00', $dia));
        $this->assertFalse(Cumpleanos::caeEl('1990-10-11', $dia));
        // El año de nacimiento no cambia el día: cumple todos los años el mismo día.
        $this->assertTrue(Cumpleanos::caeEl('2010-10-10', $dia));
    }

    public function test_el_29_de_febrero_se_saluda_el_28_en_un_ano_no_bisiesto(): void
    {
        // 2026 no es bisiesto: el 28 de febrero se saluda a los del 28 **y** a los del 29.
        $veintiocho = Carbon::parse('2026-02-28');

        $this->assertSame(['02-28', '02-29'], Cumpleanos::diasQueSeSaludan($veintiocho));
        $this->assertTrue(Cumpleanos::caeEl('1990-02-29', $veintiocho));
        $this->assertTrue(Cumpleanos::caeEl('1990-02-28', $veintiocho));
        $this->assertFalse(Cumpleanos::esElDiaExacto('1990-02-29', $veintiocho));
        $this->assertTrue(Cumpleanos::esElDiaExacto('1990-02-28', $veintiocho));

        // El 1 de marzo ya no: el adelanto es al 28, no al mes siguiente.
        $this->assertFalse(Cumpleanos::caeEl('1990-02-29', Carbon::parse('2026-03-01')));
    }

    public function test_en_un_ano_bisiesto_el_29_de_febrero_es_su_propio_dia(): void
    {
        $veintinueve = Carbon::parse('2028-02-29');

        $this->assertSame(['02-29'], Cumpleanos::diasQueSeSaludan($veintinueve));
        $this->assertTrue(Cumpleanos::caeEl('1990-02-29', $veintinueve));
        $this->assertTrue(Cumpleanos::esElDiaExacto('1990-02-29', $veintinueve));

        // Y el 28 no se le adelanta nada: solo los del 28.
        $this->assertSame(['02-28'], Cumpleanos::diasQueSeSaludan(Carbon::parse('2028-02-28')));
        $this->assertFalse(Cumpleanos::caeEl('1990-02-29', Carbon::parse('2028-02-28')));
    }

    public function test_el_28_de_febrero_de_un_ano_bisiesto_no_adelanta_el_29(): void
    {
        // El adelanto es **solo** cuando el 29 no existe: en un bisiesto el que nació el 29 tiene su día.
        $this->assertSame(['02-28'], Cumpleanos::diasQueSeSaludan(Carbon::parse('2028-02-28')));
    }

    public function test_las_fechas_rotas_del_legado_no_tienen_cumpleanos(): void
    {
        $dia = Carbon::parse('2026-10-10');

        foreach ([null, '', '0000-00-00', '0000-00-00 00:00:00', 'sin fecha', '2026-13-40', '2026-02-30', '2026-04-31', '202'] as $rota) {
            $this->assertFalse(Cumpleanos::caeEl($rota, $dia), 'No debería tener cumpleaños: ' . var_export($rota, true));
            $this->assertNull(Cumpleanos::anioDeNacimiento($rota));
        }

        // Un 29 de febrero de un año que no era bisiesto **sí** se toma como cumpleaños: lo que vale es
        // día y mes, y un 29/02 adelantado por el modelo (`Paciente` castea `fnacimiento` a fecha, así
        // que Carbon corre los 29/02 de años comunes al 1 de marzo) no puede borrarle el saludo a nadie.
        $this->assertTrue(Cumpleanos::caeEl('2018-02-29', Carbon::parse('2028-02-29')));
        $this->assertSame('02-29', Cumpleanos::mesDia('2018-02-29'));
    }

    public function test_la_edad_que_cumple_sale_del_ano_de_nacimiento(): void
    {
        $dia = Carbon::parse('2026-10-10');

        $this->assertSame(1990, Cumpleanos::anioDeNacimiento('1990-10-10'));
        $this->assertSame('10-10', Cumpleanos::mesDia('1990-10-10 00:00:00'));

        $cumpleano = new Cumpleano($this->paciente(), $dia, true);
        $this->assertSame(36, $cumpleano->edad());

        // Una fecha en el futuro no da una edad negativa: no hay edad que mostrar.
        $futuro = new Cumpleano($this->paciente(['fnacimiento' => '2030-10-10']), $dia, true);
        $this->assertNull($futuro->edad());
    }

    // --------------------------------------------------------------------------------------- El objeto

    public function test_el_cumpleano_sabe_que_dato_le_falta_para_el_canal(): void
    {
        $dia = Carbon::parse('2026-10-10');

        $completo = new Cumpleano($this->paciente(), $dia, true);
        $this->assertTrue($completo->puedeRecibirPor(Canal::SMS));
        $this->assertTrue($completo->puedeRecibirPor(Canal::WHATSAPP));
        $this->assertTrue($completo->puedeRecibirPor(Canal::CORREO));
        $this->assertNull($completo->datoQueFalta(Canal::CORREO));

        $sinCorreo = new Cumpleano($this->paciente(['email' => null]), $dia, true);
        $this->assertTrue($sinCorreo->puedeRecibirPor(Canal::SMS));
        $this->assertFalse($sinCorreo->puedeRecibirPor(Canal::CORREO));
        $this->assertSame('correo', $sinCorreo->datoQueFalta(Canal::CORREO));
        $this->assertSame('El paciente no tiene un correo válido registrado.', $sinCorreo->mensajeDeDatoFaltante(Canal::CORREO));

        $sinTelefono = new Cumpleano($this->paciente(['telefono' => '12345']), $dia, true);
        $this->assertFalse($sinTelefono->puedeRecibirPor(Canal::SMS));
        $this->assertSame('teléfono', $sinTelefono->datoQueFalta(Canal::SMS));
        $this->assertSame('El paciente no tiene un teléfono válido registrado.', $sinTelefono->mensajeDeDatoFaltante(Canal::SMS));
    }

    public function test_el_nombre_del_saludo_va_nombre_apellido(): void
    {
        $cumpleano = new Cumpleano($this->paciente(), Carbon::parse('2026-10-10'), true);

        $this->assertSame('Ana Pérez', $cumpleano->nombreParaElSaludo());
        $this->assertSame('Pérez, Ana', $cumpleano->nombre());
        $this->assertSame('2026-10-10', $cumpleano->fecha());
    }

    // ------------------------------------------------------------------------------------- El mensaje

    public function test_el_mensaje_usa_las_etiquetas_del_editor_del_movil(): void
    {
        $mensajes = new MensajeDeCumpleanos();

        $this->assertSame(
            '¡Feliz cumpleaños Ana! Te saluda el Dr. Carlos.',
            $mensajes->armar('¡Feliz cumpleaños {paciente}! Te saluda el {doctor}.', [
                'paciente' => 'Ana',
                'doctor'   => 'Dr. Carlos',
            ]),
        );

        // Sin plantilla del médico vale la de por defecto: la misma que muestra el editor del móvil.
        $this->assertSame(
            '¡Feliz cumpleaños Ana! Le deseamos un excelente día. Saludos.',
            $mensajes->armar(null, ['paciente' => 'Ana']),
        );
        $this->assertSame(MensajeDeCumpleanos::PLANTILLA_POR_DEFECTO, '¡Feliz cumpleaños {paciente}! Le deseamos un excelente día. Saludos.');
    }

    // -------------------------------------------------------------------------------- La configuración

    public function test_sin_configuracion_el_envio_automatico_esta_apagado_y_el_canal_es_correo(): void
    {
        $configuracion = ConfiguracionDeCumpleanos::de(null);

        $this->assertFalse($configuracion->activo());
        $this->assertSame(Canal::CORREO, $configuracion->canal());
        $this->assertSame('09:00', $configuracion->hora());
        $this->assertNull($configuracion->evolucion());
    }

    public function test_lo_que_no_tiene_valor_en_la_base_cae_en_el_default(): void
    {
        $evolucion = new Evolucion(['reg_medico' => 'x', 'clave' => 1]);
        $configuracion = ConfiguracionDeCumpleanos::de($evolucion);

        $this->assertFalse($configuracion->activo());
        $this->assertSame(Canal::CORREO, $configuracion->canal());
        $this->assertSame('09:00', $configuracion->hora());

        // Un canal desconocido (dato viejo o escrito a mano) tampoco deja la pantalla sin canal.
        $evolucion->canal_cumpleanos = 'telegram';
        $evolucion->hora_cumpleanos = '25:99';
        $configuracion = ConfiguracionDeCumpleanos::de($evolucion);

        $this->assertSame(Canal::CORREO, $configuracion->canal());
        $this->assertSame('09:00', $configuracion->hora());
    }

    public function test_la_hora_decide_si_ya_es_la_hora_del_envio(): void
    {
        // Los atributos se asignan uno por uno: `Evolucion` no tiene `enviar_cumpleanos` en `$fillable`
        // (la tabla del legado se llena desde el reporte, no por asignación masiva).
        $evolucion = new Evolucion(['reg_medico' => 'x', 'clave' => 1]);
        $evolucion->enviar_cumpleanos = true;
        $evolucion->canal_cumpleanos = Canal::SMS;
        $evolucion->hora_cumpleanos = '09:30:00';

        $configuracion = ConfiguracionDeCumpleanos::de($evolucion);

        $this->assertTrue($configuracion->activo());
        $this->assertSame(Canal::SMS, $configuracion->canal());
        $this->assertSame('09:30', $configuracion->hora());

        $this->assertFalse($configuracion->yaEsLaHora(Carbon::parse('2026-10-10 09:29')));
        // `>=` a propósito: una corrida que se salteó no puede dejar al paciente sin saludo nunca.
        $this->assertTrue($configuracion->yaEsLaHora(Carbon::parse('2026-10-10 09:30')));
        $this->assertTrue($configuracion->yaEsLaHora(Carbon::parse('2026-10-10 23:00')));
    }

    public function test_la_hora_se_valida_y_se_normaliza_para_la_columna(): void
    {
        $this->assertTrue(ConfiguracionDeCumpleanos::horaValida('00:00'));
        $this->assertTrue(ConfiguracionDeCumpleanos::horaValida('23:59'));
        $this->assertFalse(ConfiguracionDeCumpleanos::horaValida('24:00'));
        $this->assertFalse(ConfiguracionDeCumpleanos::horaValida('9:30'));
        $this->assertFalse(ConfiguracionDeCumpleanos::horaValida(null));

        $this->assertSame('09:30:00', ConfiguracionDeCumpleanos::horaParaGuardar('09:30'));
        $this->assertNull(ConfiguracionDeCumpleanos::horaParaGuardar('nueve y media'));
    }

    // --------------------------------------------------------------------------------------- La zona

    public function test_la_fecha_del_consultorio_usa_su_propia_zona(): void
    {
        // El día pedido a mano se interpreta en la zona del consultorio, no en la del servidor (UTC).
        $this->assertSame('2026-10-10', FechaDelConsultorio::dia('2026-10-10')->toDateString());
        $this->assertSame('America/Caracas', FechaDelConsultorio::zona());
        $this->assertSame('America/Caracas', FechaDelConsultorio::ahora()->getTimezone()->getName());
    }
}

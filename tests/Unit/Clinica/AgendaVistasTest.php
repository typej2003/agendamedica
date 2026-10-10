<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\Agenda\ParametrosDeAgenda;
use App\Clinica\Agenda\Vistas\FilaDeCitaVista;
use App\Models\Cola;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Lo que la agenda decide mostrar y ofrecer en cada fila, y cómo se leen los parámetros de la URL. No toca
 * la base de datos: son los ViewModels y los value objects (Docs/Web/guia-arquitectura-web.md).
 * Extiende `Tests\TestCase` solo porque los ViewModels generan URLs con `route()`.
 */
class AgendaVistasTest extends TestCase
{
    /** Un lunes de prueba; "ahora" es ese día a las 09:00, para que la cita de las 09:30 sea del día. */
    private function ahora(): Carbon
    {
        return Carbon::create(2026, 10, 5, 9, 0);
    }

    private function cita(array $campos = []): CitaDeAgenda
    {
        $d = array_merge([
            'id' => 10, 'fecha' => '2026-10-05', 'horaIni' => '09:30:00', 'numOrden' => 3, 'estado' => Cola::ESTADO_NO_CONFIRMADA,
            'atendido' => 0, 'monto' => 30.0, 'montoPagado' => 0.0, 'numHistoria' => 4001, 'pacienteSinHistoriaId' => null,
            'pacienteId' => 77, 'paciente' => 'Alvarez, Ana', 'centroId' => 1, 'motivo' => null, 'tipo' => 'CTRL', 'medico' => null,
            'movidaEscritorio' => false, 'motivoNombre' => 'Control', 'telefono' => '04141234567', 'email' => 'ana@example.com',
        ], $campos);

        return new CitaDeAgenda(...$d);
    }

    private function fila(array $campos = [], ?int $posicion = 3): FilaDeCitaVista
    {
        return new FilaDeCitaVista($this->cita($campos), $posicion, true, $this->ahora(), 'Hola Ana');
    }

    public function test_una_cita_pendiente_ofrece_confirmar_cobrar_recordar_editar_y_eliminar(): void
    {
        $fila = $this->fila();

        $this->assertSame('#3', $fila->turno());
        $this->assertSame('H. 4001 · Control', $fila->detalle());
        $this->assertSame('Sin confirmar', $fila->estado()['texto']);
        $this->assertSame('aviso', $fila->pago()['tono']);   // debe el monto completo

        $this->assertNotNull($fila->confirmar());
        $this->assertTrue($fila->confirmar()['habilitada']);  // es del día y falta menos de una hora
        $this->assertNotNull($fila->atender());
        $this->assertNotNull($fila->cobro());
        $this->assertTrue($fila->cobro()['requiereMonto'] === false);
        $this->assertSame('30.00', $fila->cobro()['pendiente']);
        $this->assertSame('Hola Ana', $fila->recordatorio()['mensaje']);
        $this->assertNotNull($fila->urlEditar());
        $this->assertNotNull($fila->urlEliminar());
    }

    public function test_una_cita_movida_por_el_escritorio_no_ofrece_ninguna_accion(): void
    {
        $fila = $this->fila(['movidaEscritorio' => true], null);

        $this->assertSame('—', $fila->turno());
        $this->assertFalse($fila->tieneTurno());
        $this->assertSame('Movida', $fila->estado()['texto']);
        $this->assertSame('fila-movida', $fila->clase());
        $this->assertFalse($fila->arrastrable());

        foreach (['confirmar', 'quitarConfirmacion', 'atender', 'cobro', 'recordatorio', 'urlEditar', 'urlEliminar'] as $accion) {
            $this->assertNull($fila->$accion(), "la movida no ofrece {$accion}");
        }
    }

    public function test_una_cita_atendida_no_ofrece_confirmar_ni_editar_ni_eliminar(): void
    {
        $fila = $this->fila(['atendido' => 1, 'estado' => Cola::ESTADO_NO_CONFIRMADA]);

        $this->assertSame('Atendida', $fila->estado()['texto']);
        $this->assertSame('fila-atendida', $fila->clase());
        $this->assertNull($fila->confirmar());      // atender implica confirmada
        $this->assertNull($fila->atender());
        $this->assertNull($fila->urlEditar());
        $this->assertNull($fila->urlEliminar());
        $this->assertNotNull($fila->cobro());       // pero todavía se puede cobrar
    }

    public function test_una_cita_confirmada_ofrece_atender_y_quitar_la_confirmacion(): void
    {
        $fila = $this->fila(['estado' => Cola::ESTADO_CONFIRMADA]);

        $this->assertSame('Confirmada', $fila->estado()['texto']);
        $this->assertNull($fila->confirmar());
        $this->assertNull($fila->recordatorio());   // a quien ya confirmó no se le recuerda
        $this->assertNotNull($fila->atender());
        $this->assertSame(Cola::ESTADO_NO_CONFIRMADA, $fila->quitarConfirmacion()['estado']);
    }

    public function test_el_pago_decide_si_se_cobra_o_se_elimina(): void
    {
        $pagada = $this->fila(['montoPagado' => 30.0]);
        $this->assertSame('exito', $pagada->pago()['tono']);
        $this->assertNull($pagada->cobro());
        $this->assertNull($pagada->urlEliminar());

        $abonada = $this->fila(['montoPagado' => 10.0]);
        $this->assertNotNull($abonada->cobro());
        $this->assertNull($abonada->urlEliminar(), 'con un abono registrado la cita no se borra');

        $sinMonto = $this->fila(['monto' => null]);
        $this->assertSame('neutro', $sinMonto->pago()['tono']);
        $this->assertTrue($sinMonto->cobro()['requiereMonto']);
    }

    public function test_confirmar_se_deshabilita_fuera_de_su_ventana_y_dice_por_que(): void
    {
        $manana = $this->fila(['fecha' => '2026-10-06']);

        $this->assertFalse($manana->confirmar()['habilitada']);
        $this->assertStringContainsString('desde una hora antes', $manana->confirmar()['titulo']);
    }

    public function test_los_parametros_nunca_revientan_por_una_url_mal_formada(): void
    {
        $contexto = ['office' => (object) ['id' => 5]];
        $hoy = Carbon::today()->toDateString();

        $parametros = ParametrosDeAgenda::desde(
            Request::create('/clinica/agenda', 'GET', ['vista' => 'trimestre', 'fecha' => 'no-es-fecha', 'razon' => '  ']),
            $contexto,
        );

        $this->assertSame('dia', $parametros->vista);
        $this->assertSame($hoy, $parametros->fecha->toDateString());
        $this->assertSame(5, $parametros->sedeId, 'sin `sede` en la URL manda la sede del contexto');
        $this->assertNull($parametros->razonPedida);
    }

    public function test_los_parametros_leen_la_sede_todas_y_el_rango_de_cada_vista(): void
    {
        $contexto = ['office' => (object) ['id' => 5]];
        $pedida = fn (array $query) => ParametrosDeAgenda::desde(Request::create('/clinica/agenda', 'GET', $query), $contexto);

        $this->assertNull($pedida(['sede' => 'todas'])->sedeId);
        $this->assertSame(9, $pedida(['sede' => '9'])->sedeId);

        [$desde, $hasta] = $pedida(['vista' => 'semana', 'fecha' => '2026-10-07'])->rango();
        $this->assertSame('2026-10-05', $desde->toDateString());   // lunes
        $this->assertSame('2026-10-11', $hasta->toDateString());   // domingo

        [$desde, $hasta] = $pedida(['vista' => 'mes', 'fecha' => '2026-10-07'])->rango();
        $this->assertSame('2026-10-01', $desde->toDateString());
        $this->assertSame('2026-10-31', $hasta->toDateString());

        $this->assertSame('ECO', $pedida(['razon' => ' ECO '])->razonPedida);
    }
}

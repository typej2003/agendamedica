<?php

namespace Tests\Unit\Clinica;

use App\Actions\Catalogos\ReglasDelMotivoDeCita;
use App\Clinica\Agenda\Vistas\FormularioDeCitaVista;
use App\Clinica\Catalogos\Vistas\MotivosDeCitaVista;
use App\Models\MedicalCenter;
use App\Models\MotivoCita;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * El ViewModel de «Motivos de cita» (WEB-8.1c), las reglas puras de la Action y las opciones del formulario de la cita,
 * sin base de datos: lo ya leído → textos, estados, filas y URLs. Extiende `Tests\TestCase` porque las vistas usan `old()` y
 * `route()` (trampa 68).
 */
class MotivosDeCitaCatalogoVistaTest extends TestCase
{
    private function pagina(array $filas, ?int $total = null): LengthAwarePaginator
    {
        return new LengthAwarePaginator($filas, $total ?? count($filas), 25, 1, ['path' => 'http://localhost/x']);
    }

    private function urls(): array
    {
        return [
            'guardar' => 'http://localhost/guardar',
            'quitar' => fn (int $id) => 'http://localhost/quitar/' . $id,
            'alternar' => fn (int $id) => 'http://localhost/alternar/' . $id,
        ];
    }

    private function motivo(array $campos = [], ?string $sede = null): MotivoCita
    {
        $motivo = (new MotivoCita())->forceFill(array_merge([
            'id' => 7, 'codigo' => '03', 'tipo_atencion' => 'ECOGRAFIA', 'precio' => 120.0, 'office_id' => null,
        ], $campos));
        $motivo->exists = true;

        if ($sede !== null) {
            $office = (new Office())->forceFill(['id' => $motivo->office_id, 'office_number' => 'C1']);
            $office->setRelation('medicalCenter', (new MedicalCenter())->forceFill(['name' => $sede]));
            $motivo->setRelation('office', $office);
        }

        return $motivo;
    }

    private function vista(array $filas, string $buscar = '', ?MotivoCita $editando = null, array $sedes = [], ?int $total = null): MotivosDeCitaVista
    {
        return new MotivosDeCitaVista($this->pagina($filas, $total), $buscar, $total ?? count($filas), $editando, $sedes, 'http://localhost/base', $this->urls());
    }

    // ------------------------------------------------------------------------------- Las reglas puras

    public function test_el_codigo_que_sigue_es_el_mayor_numerico_mas_uno_con_dos_digitos(): void
    {
        $this->assertSame('01', ReglasDelMotivoDeCita::siguienteCodigo([]));
        $this->assertSame('04', ReglasDelMotivoDeCita::siguienteCodigo(['01', '03', '02']));
        $this->assertSame('11', ReglasDelMotivoDeCita::siguienteCodigo(['10', '9']));
        // Los que no son números (los del móvil) no cuentan, y se pasa de 99 sin chocar con el primero.
        $this->assertSame('02', ReglasDelMotivoDeCita::siguienteCodigo(['PRIM', 'ECO4D', '01', null, '']));
        $this->assertSame('100', ReglasDelMotivoDeCita::siguienteCodigo(['99']));
    }

    public function test_la_razon_va_en_mayusculas_sin_espacios_de_mas_y_con_su_tope(): void
    {
        $this->assertSame('ECOGRAFÍA OBSTÉTRICA', ReglasDelMotivoDeCita::razon('  ecografía   obstétrica '));
        $this->assertSame(ReglasDelMotivoDeCita::clave('Ecografía'), ReglasDelMotivoDeCita::clave('ECOGRAFIA '));

        foreach (['', '   ', null, str_repeat('A', 101)] as $mala) {
            try {
                ReglasDelMotivoDeCita::razon($mala);
                $this->fail('debía rechazar ' . var_export($mala, true));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    public function test_el_precio_acepta_vacio_decimales_y_coma_y_rechaza_lo_demas(): void
    {
        $this->assertNull(ReglasDelMotivoDeCita::precio(null));
        $this->assertNull(ReglasDelMotivoDeCita::precio('  '));
        $this->assertSame(0.0, ReglasDelMotivoDeCita::precio('0'));
        $this->assertSame(12.5, ReglasDelMotivoDeCita::precio('12,5'));
        $this->assertSame(120.57, ReglasDelMotivoDeCita::precio(120.5666));
        $this->assertSame(99999999.99, ReglasDelMotivoDeCita::precio('99999999.99'));

        foreach (['-1', 'abc', '1e', '100000000'] as $malo) {
            try {
                ReglasDelMotivoDeCita::precio($malo);
                $this->fail('debía rechazar ' . $malo);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }

    // ----------------------------------------------------------------------------------- La pantalla

    public function test_cada_fila_trae_la_razon_el_codigo_la_sede_el_precio_y_la_marca(): void
    {
        $vm = $this->vista([
            $this->motivo(['id' => 10, 'repetidos' => 1, 'con_el_mismo_codigo' => 1]),
            $this->motivo(['id' => 11, 'codigo' => 'PRIM', 'tipo_atencion' => 'Primera', 'office_id' => 5, 'precio' => 80.5, 'admite_varias_citas_pendientes' => true, 'repetidos' => 2, 'con_el_mismo_codigo' => 3], 'San José'),
            $this->motivo(['id' => 12, 'precio' => null]),
        ]);

        [$a, $b, $c] = $vm->filas();

        $this->assertSame('ECOGRAFIA', $a['razon']);
        $this->assertSame('03', $a['codigo']);
        $this->assertSame('Todas las sedes', $a['sede']);
        $this->assertSame('120,00', $a['precio']);
        $this->assertNull($a['repetido']);
        $this->assertNull($a['mismoCodigo']);
        $this->assertSame('Una sola cita', $a['marca']['texto']);
        $this->assertSame('Admitir varias citas', $a['accion']['texto']);
        $this->assertSame('http://localhost/base?editar=10', $a['urlEditar']);
        $this->assertSame('http://localhost/alternar/10', $a['urlAlternar']);
        $this->assertSame('http://localhost/quitar/10', $a['urlQuitar']);
        $this->assertStringContainsString('«ECOGRAFIA»', $a['avisoQuitar']);

        $this->assertSame('San José — C1', $b['sede']);
        $this->assertSame('80,50', $b['precio']);
        $this->assertSame('Hay 2 con este nombre en esta sede', $b['repetido']);
        $this->assertSame('Código en 3 sedes', $b['mismoCodigo']);
        $this->assertSame('Varias citas', $b['marca']['texto']);
        $this->assertSame('Volver a una sola cita', $b['accion']['texto']);

        $this->assertNull($c['precio']);
    }

    public function test_una_fila_cuya_sede_ya_no_existe_lo_dice_en_vez_de_aparentar_todas(): void
    {
        $fila = $this->vista([$this->motivo(['office_id' => 99])])->filas()[0];

        $this->assertSame('Sede 99 (ya no existe)', $fila['sede']);
    }

    public function test_el_subtitulo_y_el_estado_vacio_distinguen_no_hay_nada_de_la_busqueda_sin_resultados(): void
    {
        $this->assertStringContainsString('2 motivos', $this->vista([$this->motivo(), $this->motivo(['id' => 8])])->subtitulo());
        $this->assertStringContainsString('1 motivo.', $this->vista([$this->motivo()])->subtitulo());

        $vacio = $this->vista([], '', null, [], 0);
        $this->assertTrue($vacio->estaVacio());
        $this->assertSame('Todavía no tienes motivos de cita', $vacio->vacio()['titulo']);

        $sinResultados = new MotivosDeCitaVista($this->pagina([], 0), 'zzz', 4, null, [], 'http://localhost/base', $this->urls());
        $this->assertSame('Sin resultados para «zzz».', $sinResultados->subtitulo());
        $this->assertStringContainsString('«zzz»', $sinResultados->vacio()['titulo']);
    }

    public function test_el_formulario_toma_los_valores_del_motivo_que_se_edita(): void
    {
        $nuevo = $this->vista([], '', null, [3 => 'San José — C1']);
        $this->assertSame('Nuevo motivo de cita', $nuevo->tituloDelFormulario());
        $this->assertSame('Crear el motivo', $nuevo->botonGuardar());
        $this->assertNull($nuevo->codigoEditando());
        $this->assertSame('', $nuevo->razon());
        $this->assertSame('', $nuevo->precio());
        $this->assertSame('', $nuevo->sedeSeleccionada());
        $this->assertFalse($nuevo->admiteVarias());
        $this->assertSame([['valor' => '', 'texto' => 'Todas las sedes'], ['valor' => '3', 'texto' => 'San José — C1']], $nuevo->opcionesDeSede());

        $edicion = $this->vista([], '', $this->motivo(['office_id' => 3, 'precio' => 120.5, 'admite_varias_citas_pendientes' => true]), [3 => 'San José — C1']);
        $this->assertSame('Editar el motivo', $edicion->tituloDelFormulario());
        $this->assertSame('03', $edicion->codigoEditando());
        $this->assertSame('ECOGRAFIA', $edicion->razon());
        $this->assertSame('120.50', $edicion->precio());
        $this->assertSame('3', $edicion->sedeSeleccionada());
        $this->assertTrue($edicion->admiteVarias());
        $this->assertTrue($edicion->abrirAlCargar());
    }

    public function test_una_sede_vieja_que_ya_no_esta_entre_las_del_medico_se_conserva_como_opcion(): void
    {
        $edicion = $this->vista([], '', $this->motivo(['office_id' => 9]), [3 => 'San José — C1']);

        $this->assertSame(['', '3', '9'], array_column($edicion->opcionesDeSede(), 'valor'));
        $this->assertSame('9', $edicion->sedeSeleccionada());
    }

    // ------------------------------------------------------------- Las opciones del formulario de la cita

    private function formulario(array $motivos, ?Office $sede, array $valores = []): FormularioDeCitaVista
    {
        return new FormularioDeCitaVista(
            null, 'http://localhost/guardar', Carbon::parse('2026-10-05'), collect([$sede])->filter(), $sede, collect(), null,
            collect($motivos), null, null, $valores, null, Carbon::parse('2026-10-05 09:00'), null, true,
        );
    }

    public function test_las_opciones_del_formulario_llevan_el_precio_vigente_y_dejan_una_por_razon(): void
    {
        $sede = (new Office())->forceFill(['id' => 5]);
        $vm = $this->formulario([
            $this->motivo(['id' => 1, 'codigo' => '01', 'tipo_atencion' => 'CONSULTA', 'precio' => 10.0]),
            $this->motivo(['id' => 2, 'codigo' => '05', 'tipo_atencion' => 'Consulta', 'precio' => 99.5, 'office_id' => 5]),
            $this->motivo(['id' => 3, 'codigo' => '02', 'tipo_atencion' => 'Control', 'precio' => null]),
        ], $sede);

        $opciones = $vm->opcionesDeMotivo();

        // Una sola «Consulta» (la de la sede, con su precio) y «Control», por orden alfabético.
        $this->assertSame(['05', '02'], array_column($opciones, 'valor'));
        $this->assertSame(['99.50', null], array_column($opciones, 'precio'));
        $this->assertSame(['99,50', null], array_column($opciones, 'precioTexto'));
        $this->assertFalse($vm->sinMotivos());
        $this->assertTrue($vm->puedeAdministrarMotivos());
    }

    public function test_el_motivo_elegido_se_conserva_aunque_su_razon_este_repetida_o_ya_no_se_ofrezca(): void
    {
        $sede = (new Office())->forceFill(['id' => 5]);
        $motivos = [
            $this->motivo(['id' => 1, 'codigo' => '01', 'tipo_atencion' => 'CONSULTA', 'precio' => 10.0]),
            $this->motivo(['id' => 2, 'codigo' => '05', 'tipo_atencion' => 'CONSULTA', 'precio' => 99.5, 'office_id' => 5]),
        ];

        // La cita ya tenía el «01»: sigue pudiéndose elegir.
        $opciones = $this->formulario($motivos, $sede, ['motivo' => '01'])->opcionesDeMotivo();
        $this->assertSame(['05', '01'], array_column($opciones, 'valor'));

        // La cita tenía un código que no está en esta sede: se agrega con su aviso para no perderlo.
        $fuera = $this->formulario($motivos, $sede, ['motivo' => '77'])->opcionesDeMotivo();
        $this->assertSame('77', end($fuera)['valor']);
        $this->assertStringContainsString('no está en el catálogo de esta sede', end($fuera)['texto']);
    }

    public function test_el_monto_es_obligatorio_solo_si_el_motivo_elegido_no_tiene_precio(): void
    {
        $sede = (new Office())->forceFill(['id' => 5]);
        $motivos = [
            $this->motivo(['id' => 1, 'codigo' => '01', 'tipo_atencion' => 'CONSULTA', 'precio' => 1234.5]),
            $this->motivo(['id' => 2, 'codigo' => '02', 'tipo_atencion' => 'CONTROL', 'precio' => null]),
            $this->motivo(['id' => 3, 'codigo' => '03', 'tipo_atencion' => 'REVISION', 'precio' => 0.0]),
        ];

        $this->assertFalse($this->formulario($motivos, $sede, ['motivo' => '01'])->montoObligatorio());
        $this->assertTrue($this->formulario($motivos, $sede, ['motivo' => '02'])->montoObligatorio());
        $this->assertFalse($this->formulario($motivos, $sede, ['motivo' => '03'])->montoObligatorio());
        $this->assertFalse($this->formulario($motivos, $sede)->montoObligatorio());
        $this->assertSame('1234.50', $this->formulario($motivos, $sede, ['motivo' => '01'])->precioDelMotivo());
        $this->assertNull($this->formulario($motivos, $sede, ['motivo' => '02'])->precioDelMotivo());
        $this->assertTrue($this->formulario([], $sede)->sinMotivos());
    }

    private function jornadaConCitas(array $citas): \App\Clinica\Agenda\Jornada
    {
        return new \App\Clinica\Agenda\Jornada('2026-10-05', null, 0, null, null, collect($citas));
    }

    private function citaDeAgenda(int $id, bool $movida = false): \App\Clinica\Agenda\CitaDeAgenda
    {
        return new \App\Clinica\Agenda\CitaDeAgenda($id, '2026-10-05', '08:00:00', $id, 0, 0, null, null, 1, null, 1, 'X', null, null, null, null, $movida);
    }

    private function formularioConJornada(?\App\Clinica\Agenda\Jornada $jornada, ?int $citaId = null, array $valores = []): FormularioDeCitaVista
    {
        return new FormularioDeCitaVista(
            $citaId, 'http://localhost/guardar', Carbon::parse('2026-10-05'), collect(), null, collect(), $jornada,
            collect(), null, null, $valores, null, Carbon::parse('2026-10-05 09:00'),
        );
    }

    public function test_el_puesto_del_paciente_es_el_siguiente_de_la_jornada(): void
    {
        $this->assertSame(1, $this->formularioConJornada($this->jornadaConCitas([]))->puestoDelPaciente());
        $this->assertSame(2, $this->formularioConJornada($this->jornadaConCitas([$this->citaDeAgenda(1)]))->puestoDelPaciente());
        // Las movidas por el escritorio no cuentan (regla de `Jornada::cantidad()`).
        $this->assertSame(2, $this->formularioConJornada($this->jornadaConCitas([$this->citaDeAgenda(1), $this->citaDeAgenda(2, true)]))->puestoDelPaciente());
        $this->assertNull($this->formularioConJornada(null)->puestoDelPaciente());
    }

    public function test_al_editar_el_puesto_es_el_que_la_cita_ya_tiene(): void
    {
        $vm = $this->formularioConJornada($this->jornadaConCitas([$this->citaDeAgenda(1), $this->citaDeAgenda(2)]), 9, ['numorden' => 2]);

        $this->assertSame(2, $vm->puestoDelPaciente());
    }
}

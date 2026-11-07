<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Vacunas\ListaDeVacunasDelPaciente;
use Tests\TestCase;

/**
 * **La lista de vacunas del paciente** (O-68/O-69), pura: arma las filas de `vacuna_paciente` como las muestra el escritorio en
 * la solapa *Inmunizaciones*. Se fija el orden del DataWindow (`codi_vacuna` + `dosis_numero` + `fecha_ejecutada`), que una fila
 * programada **no** muestre su `fecha_ejecutada` como fecha de aplicación (esa fecha es la del día de la carga), de dónde sale
 * la «Próxima», que no se ofrezca quitar lo aplicado y la «Propia» del escritorio (O-83: `aplicada_aca` dice si la dosis la
 * aplicó este consultorio o el paciente se vacunó en otra parte).
 */
class ListaDeVacunasDelPacienteTest extends TestCase
{
    /** Una fila de `vacuna_paciente` como la devuelve la lectura (stdClass, fechas en texto). */
    private function fila(array $datos): object
    {
        return (object) array_merge([
            'id' => 1, 'codi_vacuna' => 'POLI', 'dosis' => '1era. Dosis', 'dosis_numero' => 1, 'descripcion' => null,
            'tipo_vacu' => null, 'lote' => null, 'fecha_planeada' => null, 'fecha_ejecutada' => '2026-08-20', 'ejecutada' => 'S',
            'via' => null, 'zona' => null, 'nota' => null, 'fecha_vence' => null, 'fecha_proxima_dosis' => null, 'fecha_proxima_vac' => null,
            'aplicada_aca' => null,
        ], $datos);
    }

    private function vacuna(string $codigo, string $nombre): object
    {
        return (object) ['codi_vacuna' => $codigo, 'vacuna' => $nombre, 'tipo_vacu' => 'INY'];
    }

    /** @param  array<int,object>  $filas */
    private function armar(array $filas, array $vacunas = [], array $tipos = [], ?callable $urlQuitar = null): array
    {
        return (new ListaDeVacunasDelPaciente())->de($filas, $vacunas, $tipos, $urlQuitar === null ? null : \Closure::fromCallable($urlQuitar));
    }

    public function test_ordena_como_la_grilla_del_escritorio_y_cuenta_aplicadas_y_programadas(): void
    {
        $r = $this->armar([
            $this->fila(['id' => 3, 'codi_vacuna' => 'ROTA', 'dosis' => 'Única', 'dosis_numero' => 1, 'ejecutada' => 'N', 'fecha_ejecutada' => '2026-10-17']),
            $this->fila(['id' => 2, 'codi_vacuna' => 'POLI', 'dosis' => '2da. Dosis', 'dosis_numero' => 2, 'ejecutada' => 'N', 'fecha_ejecutada' => '2026-10-17']),
            $this->fila(['id' => 1, 'codi_vacuna' => 'POLI', 'dosis' => '1era. Dosis', 'dosis_numero' => 1]),
            $this->fila(['id' => 4, 'codi_vacuna' => 'BCG', 'dosis' => 'Única', 'dosis_numero' => 1]),
        ]);

        // Por `codi_vacuna` y después por dosis: BCG, Polio 1ª, Polio 2ª y Rotavirus.
        $this->assertSame([4, 1, 2, 3], array_column($r['filas'], 'id'));
        $this->assertSame([ListaDeVacunasDelPaciente::APLICADA => 2, ListaDeVacunasDelPaciente::PROGRAMADA => 2], $r['resumen']);
        $this->assertSame('Aplicada', $r['filas'][1]['rotulo']);
        $this->assertSame('Programada', $r['filas'][2]['rotulo']);
    }

    public function test_una_fila_programada_no_muestra_su_fecha_de_carga_como_aplicada(): void
    {
        $r = $this->armar([
            $this->fila(['id' => 1, 'ejecutada' => 'N', 'fecha_ejecutada' => '2026-10-17', 'fecha_planeada' => '2026-11-20']),
        ]);

        $fila = $r['filas'][0];
        $this->assertSame(ListaDeVacunasDelPaciente::PROGRAMADA, $fila['estado']);
        $this->assertSame('20/11/2026', $fila['planeada']);
        $this->assertSame('2026-11-20', $fila['planeadaIso']);
        // `fecha_ejecutada` es el día de la carga, no una aplicación: no se muestra.
        $this->assertNull($fila['aplicada']);
        $this->assertNull($fila['aplicadaIso']);
    }

    public function test_la_proxima_sale_de_fecha_proxima_dosis_y_no_de_fecha_proxima_vac(): void
    {
        $r = $this->armar([
            $this->fila(['id' => 1, 'fecha_proxima_dosis' => '2026-12-01']),
            $this->fila(['id' => 2, 'fecha_proxima_vac' => '2026-12-25']),
        ]);

        $this->assertSame('01/12/2026', $r['filas'][0]['proxima']);
        $this->assertNull($r['filas'][1]['proxima']);
    }

    public function test_la_segunda_linea_y_el_nombre_del_catalogo_cuando_la_fila_no_lo_trae(): void
    {
        $r = $this->armar(
            [$this->fila([
                'id' => 1, 'descripcion' => null, 'tipo_vacu' => 'INY', 'lote' => 'L-1', 'via' => 'Oral', 'zona' => 'Muslo',
                'nota' => 'Sin reacción', 'fecha_vence' => '2027-01-01',
            ])],
            [$this->vacuna('POLI', 'Polio')],
            ['INY' => 'Inyectable'],
        );

        $fila = $r['filas'][0];
        $this->assertSame('Polio', $fila['vacuna']);
        $this->assertSame('Inyectable', $fila['tipo']);
        $this->assertSame('L-1', $fila['lote']);
        $this->assertSame('Oral', $fila['via']);
        $this->assertSame('Muslo', $fila['zona']);
        $this->assertSame('Sin reacción', $fila['notas']);
        $this->assertSame('01/01/2027', $fila['vence']);
        $this->assertTrue($fila['enCatalogo']);
    }

    public function test_una_vacuna_que_ya_no_esta_en_el_catalogo_se_lista_igual_y_se_marca(): void
    {
        $r = $this->armar([$this->fila(['id' => 1, 'codi_vacuna' => 'OLD', 'descripcion' => 'VACUNA VIEJA'])]);

        $this->assertSame('VACUNA VIEJA', $r['filas'][0]['vacuna']);
        $this->assertFalse($r['filas'][0]['enCatalogo']);
    }

    /**
     * **«Propia»** (O-83, Alexander 2026-11-06): «es si se aplicó por el doctor, es decir, si es falso, entonces se vacunó en
     * otra parte». `vacuna_paciente.aplicada_aca` = `S` es «la aplicó este consultorio»; `N`, vacía o `null`, «se vacunó en
     * otro lado». Una fila programada no tiene procedencia.
     */
    public function test_la_propia_dice_si_la_aplico_este_consultorio_o_el_nino_se_vacuno_en_otra_parte(): void
    {
        $r = $this->armar([
            $this->fila(['id' => 1, 'codi_vacuna' => 'A', 'ejecutada' => 'S', 'aplicada_aca' => 'S']),
            $this->fila(['id' => 2, 'codi_vacuna' => 'B', 'ejecutada' => 'S', 'aplicada_aca' => 'N']),
            $this->fila(['id' => 3, 'codi_vacuna' => 'C', 'ejecutada' => 'S', 'aplicada_aca' => null]),
            $this->fila(['id' => 4, 'codi_vacuna' => 'D', 'ejecutada' => 'N', 'aplicada_aca' => 'S']),
        ]);

        $this->assertSame(ListaDeVacunasDelPaciente::APLICADA_ACA, $r['filas'][0]['procedencia']);
        $this->assertSame('Aplicada acá', $r['filas'][0]['procedenciaRotulo']);

        // `N` y vacía dicen lo mismo: el paciente se vacunó en otra parte.
        $this->assertSame(ListaDeVacunasDelPaciente::APLICADA_EN_OTRO_LADO, $r['filas'][1]['procedencia']);
        $this->assertSame('Aplicada en otro lado', $r['filas'][1]['procedenciaRotulo']);
        $this->assertSame('Aplicada en otro lado', $r['filas'][2]['procedenciaRotulo']);

        // Programada: todavía no se aplicó, así que la casilla del escritorio no dice nada.
        $this->assertSame(ListaDeVacunasDelPaciente::PROGRAMADA, $r['filas'][3]['estado']);
        $this->assertNull($r['filas'][3]['procedencia']);
        $this->assertNull($r['filas'][3]['procedenciaRotulo']);
    }

    public function test_quitar_solo_se_ofrece_para_lo_programado(): void
    {
        $r = $this->armar(
            [
                $this->fila(['id' => 7, 'ejecutada' => 'N']),
                $this->fila(['id' => 8, 'ejecutada' => 'S']),
            ],
            [],
            [],
            fn (int $id) => '/quitar/' . $id,
        );

        $this->assertSame('/quitar/7', $r['filas'][0]['urlQuitar']);
        $this->assertNull($r['filas'][1]['urlQuitar']);

        // Sin URL (p. ej. sin historia) no hay acción para ninguna.
        $this->assertNull($this->armar([$this->fila(['id' => 7, 'ejecutada' => 'N'])])['filas'][0]['urlQuitar']);
    }
}

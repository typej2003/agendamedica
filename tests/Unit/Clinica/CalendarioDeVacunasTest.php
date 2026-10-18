<?php

namespace Tests\Unit\Clinica;

use App\Clinica\Vacunas\CalendarioDeVacunas;
use PHPUnit\Framework\TestCase;

/** El calendario de vacunas del niño (WEB-7.4.4a). Catálogo inventado para la prueba. */
class CalendarioDeVacunasTest extends TestCase
{
    private const HOY = '2026-10-17';

    private function vacuna(string $codigo, string $nombre, ?int $edad, array $extra = []): object
    {
        return (object) array_merge(['codi_vacuna' => $codigo, 'vacuna' => $nombre, 'edad_inicial_meses' => $edad, 'fija' => 'O', 'cobertura' => null, 'sexo' => 'A'], $extra);
    }

    private function dosis(int $numero, string $rotulo, float $meses, array $extra = []): object
    {
        return (object) array_merge(['dosis' => $numero, 'dosis_descri' => $rotulo, 'meses' => $meses, 'sexo' => null], $extra);
    }

    private function aplicada(string $codigo, string $fecha, array $extra = []): object
    {
        return (object) array_merge(['codi_vacuna' => $codigo, 'ejecutada' => 'S', 'fecha_ejecutada' => $fecha, 'dosis' => null, 'dosis_numero' => null, 'lote' => null, 'descripcion' => null], $extra);
    }

    private function armar(array $vacunas, array $dosis = [], array $aplicadas = [], ?string $nacimiento = '2026-06-17', string $sexo = 'M'): array
    {
        return (new CalendarioDeVacunas())->armar($nacimiento, $sexo, self::HOY, $vacunas, $dosis, $aplicadas);
    }

    public function test_sin_dosis_cargadas_la_vacuna_es_una_dosis_unica_a_su_edad_inicial(): void
    {
        $r = $this->armar([$this->vacuna('BCG', 'BCG', 0), $this->vacuna('ROTA', 'Rotavirus', 4)]);

        $bcg = $r['vacunas'][0]['dosis'][0];
        $this->assertSame('Dosis única', $bcg['rotulo']);
        $this->assertSame('2026-06-17', $bcg['planeada']);
        $this->assertSame(CalendarioDeVacunas::ATRASADA, $bcg['estado']);
        // Nació hace 4 meses (2026-06-17): a los 4 meses es hoy+0 → 2026-10-17, no está atrasada sino próxima.
        $this->assertSame('2026-10-17', $r['vacunas'][1]['dosis'][0]['planeada']);
        $this->assertSame(CalendarioDeVacunas::PROXIMA, $r['vacunas'][1]['dosis'][0]['estado']);
    }

    public function test_las_dosis_del_medico_mandan_sobre_la_edad_inicial(): void
    {
        $r = $this->armar(
            [$this->vacuna('POLI', 'Polio', 2)],
            ['POLI' => [$this->dosis(2, 'Refuerzo', 18), $this->dosis(1, '1era. Dosis', 2)]],
        );

        $dosis = $r['vacunas'][0]['dosis'];
        $this->assertSame(['1era. Dosis', 'Refuerzo'], array_column($dosis, 'rotulo'));
        $this->assertSame(['2026-08-17', '2027-12-17'], array_column($dosis, 'planeada'));
        $this->assertSame([CalendarioDeVacunas::ATRASADA, CalendarioDeVacunas::PENDIENTE], array_column($dosis, 'estado'));
    }

    public function test_una_dosis_aplicada_se_asocia_por_numero_o_por_rotulo(): void
    {
        $r = $this->armar(
            [$this->vacuna('POLI', 'Polio', 2), $this->vacuna('HEPB', 'Hepatitis B', 0)],
            ['POLI' => [$this->dosis(1, '1era. Dosis', 2), $this->dosis(2, '2da. Dosis', 4)], 'HEPB' => [$this->dosis(1, 'Primera', 0)]],
            [
                $this->aplicada('POLI', '2026-08-20', ['dosis_numero' => 1, 'lote' => 'L123']),
                $this->aplicada('HEPB', '2026-06-18', ['dosis' => 'primera']),
                // Una planeada que el escritorio dejó en «N» no cuenta como aplicada.
                $this->aplicada('POLI', '2026-10-20', ['dosis_numero' => 2, 'ejecutada' => 'N']),
            ],
        );

        $poli = $r['vacunas'][0]['dosis'];
        $this->assertSame(CalendarioDeVacunas::APLICADA, $poli[0]['estado']);
        $this->assertSame('2026-08-20', $poli[0]['aplicada']);
        $this->assertSame('L123', $poli[0]['lote']);
        $this->assertSame(CalendarioDeVacunas::PROXIMA, $poli[1]['estado']);
        $this->assertSame(CalendarioDeVacunas::APLICADA, $r['vacunas'][1]['dosis'][0]['estado']);
        $this->assertSame(2, $r['resumen'][CalendarioDeVacunas::APLICADA]);
    }

    public function test_lo_aplicado_que_no_encaja_se_lista_y_no_se_pierde(): void
    {
        $r = $this->armar(
            [$this->vacuna('BCG', 'BCG', 0)],
            [],
            [
                $this->aplicada('BCG', '2026-06-20'),
                $this->aplicada('BCG', '2026-07-01', ['dosis' => 'Extra']),
                $this->aplicada('XYZ', '2026-07-02', ['descripcion' => 'Vacuna que ya no está', 'dosis' => '1']),
            ],
        );

        $bcg = $r['vacunas'][0]['dosis'];
        $this->assertCount(2, $bcg);
        $this->assertSame('Extra', $bcg[1]['rotulo']);
        $this->assertSame(CalendarioDeVacunas::APLICADA, $bcg[1]['estado']);
        $this->assertCount(1, $r['otras']);
        $this->assertSame('Vacuna que ya no está', $r['otras'][0]['vacuna']);
        $this->assertSame(3, $r['resumen'][CalendarioDeVacunas::APLICADA]);
    }

    public function test_una_vacuna_de_otro_sexo_no_se_ofrece_y_una_sin_edad_se_lista_sin_fecha(): void
    {
        $r = $this->armar(
            [$this->vacuna('VPH', 'VPH', 108, ['sexo' => 'F']), $this->vacuna('TOX', 'Toxoide', null), $this->vacuna('BCG', 'BCG', 0)],
            ['BCG' => [$this->dosis(1, 'Única', 0, ['sexo' => 'F'])]],
            [],
            '2026-06-17',
            'M',
        );

        $this->assertSame(['TOX', 'BCG'], array_column($r['vacunas'], 'codigo'));
        $this->assertTrue($r['vacunas'][0]['sinEdad']);
        // La única dosis de la vacuna es de niñas: a un niño no le queda ninguna.
        $this->assertSame([], $r['vacunas'][1]['dosis']);
    }

    public function test_sin_nacimiento_no_hay_fechas_pero_si_las_dosis(): void
    {
        $r = $this->armar([$this->vacuna('BCG', 'BCG', 0)], [], [], null);

        $this->assertNull($r['vacunas'][0]['dosis'][0]['planeada']);
        $this->assertSame(CalendarioDeVacunas::SIN_FECHA, $r['vacunas'][0]['dosis'][0]['estado']);
    }

    public function test_los_meses_con_decimales_cuentan_treinta_dias_por_mes(): void
    {
        $r = $this->armar([$this->vacuna('X', 'X', null)], ['X' => [$this->dosis(1, 'Media', 1.5)]], [], '2026-01-01');

        $this->assertSame('2026-02-16', $r['vacunas'][0]['dosis'][0]['planeada']);
    }

    public function test_el_resumen_cuenta_cada_estado(): void
    {
        $r = $this->armar(
            [$this->vacuna('A', 'A', 0), $this->vacuna('B', 'B', 4), $this->vacuna('C', 'C', 12), $this->vacuna('D', 'D', null)],
            [],
            [$this->aplicada('A', '2026-06-18')],
        );

        $this->assertSame([
            CalendarioDeVacunas::APLICADA => 1, CalendarioDeVacunas::ATRASADA => 0, CalendarioDeVacunas::PROXIMA => 1,
            CalendarioDeVacunas::PENDIENTE => 1, CalendarioDeVacunas::SIN_FECHA => 0,
        ], $r['resumen']);
        $this->assertSame('Atrasada', CalendarioDeVacunas::rotulo(CalendarioDeVacunas::ATRASADA));
    }
}

<?php

namespace App\Clinica\Vacunas\Vistas;

use App\Clinica\Impresion\Membrete;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Vacunas\CalendarioDeVacunas;
use Carbon\Carbon;

/**
 * **El carnet de vacunación imprimible** (WEB-7.4.4c): la hoja A4 con el membrete del médico, los datos del niño, **las vacunas aplicadas** (el
 * reporte «VACUNAS APLICADAS» del escritorio, `d_vacunas_reporte`) y las **dosis que le faltan** según el calendario del consultorio. La
 * imprime el navegador (PLAN-WEB.md, R6); también sale en la vista previa («Visualizar»).
 *
 * No consulta nada: recibe el membrete, la ficha, las filas aplicadas de `vacuna_paciente` y lo que armó `CalendarioDeVacunas`.
 */
final class CarnetDeVacunacionVista
{
    /**
     * @param  object  $paciente  `nombres`, `apellidos`, `sexo`, `fnacimiento` y `numhistoria`
     * @param  array<int,object>  $aplicadas  filas de `vacuna_paciente` del niño (todas; acá se usan las de `ejecutada = 'S'`)
     * @param  array{vacunas:array<int,array<string,mixed>>, otras:array<int,array<string,mixed>>, resumen:array<string,int>}  $calendario
     */
    public function __construct(
        private Membrete $membrete,
        private object $paciente,
        private array $aplicadas,
        private array $calendario,
        private ?string $padres,
        private string $hoy,
        private string $urlVolver,
        private bool $incrustado = false,
    ) {
    }

    public function titulo(): string
    {
        return 'Carnet de vacunación';
    }

    public function membrete(): Membrete
    {
        return $this->membrete;
    }

    public function urlVolver(): string
    {
        return $this->urlVolver;
    }

    public function incrustado(): bool
    {
        return $this->incrustado;
    }

    public function resumen(): string
    {
        return $this->nombre() . ' · ' . count($this->aplicadas()) . ' ' . (count($this->aplicadas()) === 1 ? 'dosis aplicada' : 'dosis aplicadas');
    }

    // ------------------------------------------------------------------------------------------ El niño

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos ?? null, $this->paciente->nombres ?? null);
    }

    /** @return array<int,array{rotulo:string, valor:string}> los datos de la cabecera, sin los que faltan */
    public function datos(): array
    {
        $nacimiento = empty($this->paciente->fnacimiento) ? null : Carbon::parse($this->paciente->fnacimiento);

        $filas = [
            ['rotulo' => 'Paciente', 'valor' => $this->nombre()],
            ['rotulo' => 'Historia', 'valor' => 'H. ' . ($this->paciente->numhistoria ?? '—')],
            ['rotulo' => 'Nacimiento', 'valor' => $nacimiento?->format(\App\Support\Regionalizacion::formatoActual()) ?? '—'],
            ['rotulo' => 'Sexo', 'valor' => match (strtoupper((string) ($this->paciente->sexo ?? ''))) { 'M' => 'Masculino', 'F' => 'Femenino', default => '—' }],
            ['rotulo' => 'Edad', 'valor' => \App\Clinica\Crecimiento\EdadEnTexto::entre($nacimiento?->toDateString(), $this->hoy) ?? '—'],
        ];

        if ($this->padres !== null) {
            $filas[] = ['rotulo' => 'Padres', 'valor' => $this->padres];
        }

        $filas[] = ['rotulo' => 'Fecha', 'valor' => Carbon::parse($this->hoy)->format(\App\Support\Regionalizacion::formatoActual())];

        return $filas;
    }

    // ------------------------------------------------------------------------------------------ Aplicadas

    /**
     * Las dosis aplicadas, de la más antigua a la más nueva.
     *
     * @return array<int,array{vacuna:string, dosis:string, fecha:string, edad:string, lote:string, via:string, proxima:string}>
     */
    public function aplicadas(): array
    {
        $nombres = [];
        foreach ($this->calendario['vacunas'] as $v) {
            $nombres[$v['codigo']] = $v['vacuna'];
        }

        $filas = array_values(array_filter($this->aplicadas, fn ($f) => strtoupper((string) ($f->ejecutada ?? '')) === 'S'));
        usort($filas, fn ($a, $b) => strcmp((string) $a->fecha_ejecutada, (string) $b->fecha_ejecutada));

        return array_map(fn ($f) => [
            'vacuna' => $nombres[(string) $f->codi_vacuna] ?? (CamposDePaciente::texto($f->descripcion ?? null) ?? 'Vacuna ' . $f->codi_vacuna),
            'dosis' => CamposDePaciente::texto($f->dosis ?? null) ?? '—',
            'fecha' => Carbon::parse($f->fecha_ejecutada)->format(\App\Support\Regionalizacion::formatoActual()),
            'edad' => ($f->edad_aplicada ?? null) === null ? '—' : self::meses((float) $f->edad_aplicada),
            'lote' => CamposDePaciente::texto($f->lote ?? null) ?? '—',
            'via' => implode(' · ', array_filter([CamposDePaciente::texto($f->via ?? null), CamposDePaciente::texto($f->zona ?? null)])) ?: '—',
            'proxima' => empty($f->fecha_proxima_dosis) ? '—' : Carbon::parse($f->fecha_proxima_dosis)->format(\App\Support\Regionalizacion::formatoActual()),
        ], $filas);
    }

    // ------------------------------------------------------------------------------------------ Pendientes

    /**
     * Las dosis que faltan (atrasadas, próximas y pendientes), por fecha.
     *
     * @return array<int,array{vacuna:string, dosis:string, toca:string, estado:string, atrasada:bool}>
     */
    public function pendientes(): array
    {
        $filas = [];

        foreach ($this->calendario['vacunas'] as $vacuna) {
            foreach ($vacuna['dosis'] as $d) {
                if (! in_array($d['estado'], [CalendarioDeVacunas::ATRASADA, CalendarioDeVacunas::PROXIMA, CalendarioDeVacunas::PENDIENTE], true)) {
                    continue;
                }

                $filas[] = [
                    'orden' => (string) $d['planeada'],
                    'vacuna' => $vacuna['vacuna'],
                    'dosis' => $d['rotulo'],
                    'toca' => Carbon::parse($d['planeada'])->format(\App\Support\Regionalizacion::formatoActual()),
                    'estado' => CalendarioDeVacunas::rotulo($d['estado']),
                    'atrasada' => $d['estado'] === CalendarioDeVacunas::ATRASADA,
                ];
            }
        }

        usort($filas, fn ($a, $b) => strcmp($a['orden'], $b['orden']));

        return array_map(fn ($f) => array_diff_key($f, ['orden' => 1]), $filas);
    }

    private static function meses(float $meses): string
    {
        if ($meses < 24) {
            return rtrim(rtrim(number_format($meses, 1, ',', ''), '0'), ',') . ' m';
        }

        return floor($meses / 12) . ' a ' . (int) round(fmod($meses, 12)) . ' m';
    }
}

<?php

namespace App\Clinica\Vacunas\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Vacunas\CalendarioDeVacunas;
use Carbon\Carbon;
use Closure;

/**
 * **El calendario de vacunas de un niño** (WEB-7.4.4a), de sólo lectura: por vacuna, sus dosis con la fecha que le toca, el estado
 * (aplicada · atrasada · próxima · pendiente) y lo aplicado. Un segmentado deja ver **todas** o sólo las **pendientes** (atrasadas y próximas).
 *
 * No consulta nada: recibe lo que armó `CalendarioDeVacunas` y una función que arma el enlace de cada filtro (el controlador pone las URLs).
 */
final class CalendarioDeVacunasVista
{
    public const TODAS = 'todas';
    public const PENDIENTES = 'pendientes';

    private const TONOS = [
        CalendarioDeVacunas::APLICADA => 'exito',
        CalendarioDeVacunas::ATRASADA => 'peligro',
        CalendarioDeVacunas::PROXIMA => 'aviso',
        CalendarioDeVacunas::PENDIENTE => 'neutro',
        CalendarioDeVacunas::SIN_FECHA => 'neutro',
    ];

    private const ICONOS = [
        CalendarioDeVacunas::APLICADA => 'bi-check-circle',
        CalendarioDeVacunas::ATRASADA => 'bi-exclamation-triangle',
        CalendarioDeVacunas::PROXIMA => 'bi-clock',
        CalendarioDeVacunas::PENDIENTE => 'bi-hourglass-split',
        CalendarioDeVacunas::SIN_FECHA => 'bi-question-circle',
    ];

    /**
     * @param  object  $paciente  `nombres`, `apellidos`, `sexo`, `fnacimiento` y `numhistoria`
     * @param  array{vacunas:array<int,array<string,mixed>>, otras:array<int,array<string,mixed>>, resumen:array<string,int>}  $calendario
     * @param  Closure(string):string  $url  arma el enlace de un filtro (`todas` | `pendientes`)
     */
    public function __construct(
        private object $paciente,
        private array $calendario,
        private int $vacunasDelMedico,
        private string $filtro,
        private Closure $url,
        private string $urlFicha,
        // A dónde se postea el registro de una dosis (WEB-7.4.4b); `null` = el calendario es de sólo lectura.
        private ?string $urlRegistrar = null,
        private ?string $hoy = null,
        // La hoja del carnet de vacunación (WEB-7.4.4c).
        private ?string $urlCarnet = null,
    ) {
    }

    public function urlCarnet(): ?string
    {
        return $this->urlCarnet;
    }

    public function puedeRegistrar(): bool
    {
        return $this->urlRegistrar !== null;
    }

    public function urlRegistrar(): ?string
    {
        return $this->urlRegistrar;
    }

    /** La fecha más tardía que acepta el formulario (hoy en el consultorio). */
    public function maxima(): string
    {
        return $this->hoy ?? \App\Clinica\FechaDelConsultorio::hoyComoTexto();
    }

    /** La fecha de nacimiento (`AAAA-MM-DD`) o vacío: la más temprana que acepta el formulario. */
    public function minima(): string
    {
        return empty($this->paciente->fnacimiento) ? '' : Carbon::parse($this->paciente->fnacimiento)->toDateString();
    }

    public static function normalizarFiltro(?string $filtro): string
    {
        return $filtro === self::PENDIENTES ? self::PENDIENTES : self::TODAS;
    }

    public function nombre(): string
    {
        return CamposDePaciente::nombre($this->paciente->apellidos ?? null, $this->paciente->nombres ?? null);
    }

    public function subtitulo(): string
    {
        $partes = ['Historia H. ' . ($this->paciente->numhistoria ?? '—')];

        if (! empty($this->paciente->fnacimiento)) {
            $partes[] = 'Nació el ' . Carbon::parse($this->paciente->fnacimiento)->format('d/m/Y');
        }

        return implode(' · ', $partes);
    }

    public function urlFicha(): string
    {
        return $this->urlFicha;
    }

    /** ¿El médico no tiene ninguna vacuna en su catálogo? Entonces no hay calendario que armar. */
    public function sinCatalogo(): bool
    {
        return $this->vacunasDelMedico === 0;
    }

    /** @return array{titulo:string, texto:string} */
    public function vacioSinCatalogo(): array
    {
        return [
            'titulo' => 'Este consultorio todavía no tiene vacunas',
            'texto' => 'El calendario se arma con el catálogo de vacunas del médico. Cuando cargue el catálogo inicial (o sus propias vacunas) en Catálogos, '
                . 'aquí aparecen las dosis que le tocan a cada niño.',
        ];
    }

    public function sinFechaDeNacimiento(): bool
    {
        return empty($this->paciente->fnacimiento);
    }

    /** @return array<int,array{texto:string, url:string, activa:bool}> */
    public function filtros(): array
    {
        return [
            ['texto' => 'Todas', 'url' => ($this->url)(self::TODAS), 'activa' => $this->filtro === self::TODAS],
            ['texto' => 'Pendientes', 'url' => ($this->url)(self::PENDIENTES), 'activa' => $this->filtro === self::PENDIENTES],
        ];
    }

    /** @return array<int,array{estado:string, texto:string, cantidad:int, tono:string, icono:string}> los contadores que no están en cero */
    public function resumen(): array
    {
        $filas = [];
        foreach ($this->calendario['resumen'] as $estado => $cantidad) {
            if ($cantidad > 0) {
                $filas[] = ['estado' => $estado, 'texto' => CalendarioDeVacunas::rotulo($estado), 'cantidad' => $cantidad,
                    'tono' => self::TONOS[$estado], 'icono' => self::ICONOS[$estado]];
            }
        }

        return $filas;
    }

    /**
     * Las vacunas con sus dosis ya listas para pintar. Con el filtro «pendientes» sólo quedan las dosis atrasadas y próximas.
     *
     * @return array<int,array{codigo:string, vacuna:string, tipo:string, cobertura:?string, sinEdad:bool, dosis:array<int,array<string,mixed>>}>
     */
    public function vacunas(): array
    {
        $salida = [];

        foreach ($this->calendario['vacunas'] as $vacuna) {
            $dosis = [];
            foreach ($vacuna['dosis'] as $d) {
                if ($this->filtro === self::PENDIENTES && ! in_array($d['estado'], [CalendarioDeVacunas::ATRASADA, CalendarioDeVacunas::PROXIMA], true)) {
                    continue;
                }

                $dosis[] = [
                    'codigo' => $vacuna['codigo'],
                    'nombre' => $vacuna['vacuna'],
                    'numero' => $d['numero'],
                    'planeadaIso' => $d['planeada'],
                    'registrable' => $this->urlRegistrar !== null && $d['estado'] !== CalendarioDeVacunas::APLICADA && $d['numero'] !== null,
                    'rotulo' => $d['rotulo'],
                    'planeada' => self::fecha($d['planeada']),
                    'aplicada' => self::fecha($d['aplicada']),
                    'lote' => $d['lote'],
                    'estado' => CalendarioDeVacunas::rotulo($d['estado']),
                    'tono' => self::TONOS[$d['estado']],
                    'icono' => self::ICONOS[$d['estado']],
                ];
            }

            if ($this->filtro === self::PENDIENTES && $dosis === []) {
                continue;
            }

            $salida[] = ['dosis' => $dosis] + array_diff_key($vacuna, ['dosis' => 1]);
        }

        return $salida;
    }

    /** Las aplicadas de vacunas que el médico ya no tiene en su catálogo. @return array<int,array<string,mixed>> */
    public function otras(): array
    {
        if ($this->filtro === self::PENDIENTES) {
            return [];
        }

        return array_map(fn ($o) => ['aplicada' => self::fecha($o['aplicada'])] + $o, $this->calendario['otras']);
    }

    public function hayAlgoQueMostrar(): bool
    {
        return $this->vacunas() !== [] || $this->otras() !== [];
    }

    /** @return array{titulo:string, texto:string} */
    public function vacioDelFiltro(): array
    {
        return $this->filtro === self::PENDIENTES
            ? ['titulo' => 'Nada pendiente', 'texto' => 'No hay dosis atrasadas ni próximas en los próximos ' . CalendarioDeVacunas::DIAS_PROXIMA . ' días.']
            : ['titulo' => 'Sin dosis que mostrar', 'texto' => 'Las vacunas del catálogo no tienen una edad ni dosis definidas para este niño.'];
    }

    private static function fecha(?string $fecha): ?string
    {
        return $fecha === null ? null : Carbon::parse($fecha)->format('d/m/Y');
    }
}

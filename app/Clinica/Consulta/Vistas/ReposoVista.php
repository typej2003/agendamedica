<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\Consulta\ReposoDeLaConsulta;
use App\Support\FechaClinica;
use Carbon\Carbon;
use Throwable;

/**
 * **La pestaña Reposo** (WEB-5.3a): los días, la fecha de inicio y la justificación, con el **último día** y el
 * **reintegro** calculados a la vista.
 *
 * Es **un reposo por consulta**. La cuenta es la del escritorio y el móvil (`ReposoDeLaConsulta`): el día de inicio es el
 * primero de reposo, el último día es `inicio + días − 1` y el reintegro es `inicio + días`. La pantalla los muestra
 * calculados y `consulta-solicitudes.js` los recalcula al escribir los días o la fecha (la misma cuenta, sin guardar
 * nada).
 *
 * El formulario se llena con lo guardado o, si no hay reposo, con los valores por defecto del escritorio: **1 día desde
 * el día de la consulta**. Un reposo de un día **sin justificación** no se guarda (es la plantilla vacía del escritorio:
 * `QueEsUnDocumento::reposo`) y la pantalla lo dice antes, en la ayuda del campo. Sin base de datos.
 */
final class ReposoVista
{
    /**
     * @param  int|null  $dias  los días guardados, o `null` si la consulta no tiene fila
     * @param  string|null  $desde  la fecha de inicio guardada (`AAAA-MM-DD`)
     * @param  bool  $esReal  si la fila es un reposo de verdad o la plantilla vacía del escritorio
     * @param  array<string,string>  $urls  `guardar`, `eliminar` e `imprimir`
     * @param  array<string,mixed>  $old  el `old()` de un POST que falló la validación
     */
    public function __construct(
        private ?int $dias,
        private ?string $desde,
        private ?string $observacion,
        private bool $esReal,
        private string $fechaDeLaConsulta,
        private array $urls,
        private array $old = [],
    ) {
    }

    public function dias(): string
    {
        return (string) ($this->old['dias'] ?? $this->dias ?? 1);
    }

    /** La fecha de inicio para el `<input type="date">`: lo escrito, lo guardado o —si no hay nada— hoy (O-13). */
    public function desde(): string
    {
        return (string) ($this->old['desde'] ?? ReposoDeLaConsulta::fechaDeInicio($this->desde, ReposoDeLaConsulta::fechaDeInicio($this->fechaDeLaConsulta, '')));
    }

    public function observacion(): string
    {
        return (string) ($this->old['observacion'] ?? $this->observacion ?? '');
    }

    /** Hasta cuándo dura el reposo (el último día), ya formateado, o `—` si los datos no alcanzan para calcularlo. */
    public function hasta(): string
    {
        $fechas = $this->fechas();

        return $fechas === null ? '—' : FechaClinica::formato($fechas['hasta']->toDateString());
    }

    /** El día de reintegro a las labores, ya formateado. */
    public function reintegro(): string
    {
        $fechas = $this->fechas();

        return $fechas === null ? '—' : FechaClinica::formato($fechas['reintegro']->toDateString());
    }

    public function esReal(): bool
    {
        return $this->esReal;
    }

    public function puedeEliminar(): bool
    {
        return $this->esReal;
    }

    public function diasMaximo(): int
    {
        return ReposoDeLaConsulta::DIAS_MAXIMO;
    }

    public function urlGuardar(): string
    {
        return $this->urls['guardar'];
    }

    public function urlEliminar(): string
    {
        return $this->urls['eliminar'];
    }

    public function urlImprimir(): string
    {
        return $this->urls['imprimir'];
    }

    /** @return array{tono:string, icono:string, texto:string, titulo:string} */
    public function estado(): array
    {
        return $this->esReal
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => 'Con reposo', 'titulo' => 'Esta consulta tiene un reposo']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin reposo', 'titulo' => 'Esta consulta no tiene reposo'];
    }

    public function avisoSinGuardar(): string
    {
        return 'Hay cambios sin guardar en el reposo. Si sales ahora, se pierden.';
    }

    public function avisoEliminar(): string
    {
        return '¿Eliminar el reposo de esta consulta? Se borran los días, la fecha y la justificación.';
    }

    public function ayudaDeLaJustificacion(): string
    {
        return 'Obligatoria si el reposo es de un solo día: sin ella el reposo no cuenta como documento.';
    }

    public function ayuda(): string
    {
        return 'Un reposo por consulta. El día de inicio cuenta como el primero: el último día es el inicio más los días '
            . 'menos uno, y el paciente se reintegra el día siguiente.';
    }

    /** @return array{hasta:Carbon, reintegro:Carbon}|null */
    private function fechas(): ?array
    {
        $dias = (int) $this->dias();
        $desde = $this->desde();

        if ($dias < 1 || $desde === '') {
            return null;
        }

        try {
            $inicio = Carbon::parse($desde)->startOfDay();
        } catch (Throwable $e) {
            return null;
        }

        return ['hasta' => ReposoDeLaConsulta::hasta($inicio, $dias), 'reintegro' => ReposoDeLaConsulta::reintegro($inicio, $dias)];
    }
}

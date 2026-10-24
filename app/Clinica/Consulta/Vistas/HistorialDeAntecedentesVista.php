<?php

namespace App\Clinica\Consulta\Vistas;

use App\Clinica\FechaDelConsultorio;
use App\Models\AntecedenteMovimiento;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * El ViewModel del **«Historial» de los antecedentes** del paciente (registro de movimientos, 2026-10-20):
 * la línea de tiempo del cajón lateral de la sección de antecedentes de la consulta.
 *
 * Recibe los movimientos ya leídos (`App\Clinica\Consulta\HistorialDeAntecedentes::delPaciente()`), la
 * consulta abierta (para decir «esta consulta») y cómo se arma la URL de una consulta.
 */
final class HistorialDeAntecedentesVista
{
    private const OPERACIONES = [
        AntecedenteMovimiento::AGREGADO => ['texto' => 'Agregado', 'tono' => 'exito', 'icono' => 'bi-plus-circle'],
        AntecedenteMovimiento::EDITADO => ['texto' => 'Editado', 'tono' => 'info', 'icono' => 'bi-pencil'],
        AntecedenteMovimiento::QUITADO => ['texto' => 'Quitado', 'tono' => 'peligro', 'icono' => 'bi-dash-circle'],
    ];

    private const ORIGENES = [
        AntecedenteMovimiento::ORIGEN_WEB => 'Web',
        AntecedenteMovimiento::ORIGEN_MOVIL => 'Móvil',
        AntecedenteMovimiento::ORIGEN_ESCRITORIO => 'Escritorio',
        AntecedenteMovimiento::ORIGEN_CARGA_INICIAL => 'Carga inicial',
    ];

    /**
     * @param  Collection<int,object>  $movimientos
     * @param  callable(int):string  $urlDeConsulta
     */
    public function __construct(
        private Collection $movimientos,
        private ?int $consultaActual,
        private $urlDeConsulta,
        private ?string $numhistoria = null,
    ) {
    }

    public function estaVacio(): bool
    {
        return $this->movimientos->isEmpty();
    }

    public function total(): int
    {
        return $this->movimientos->count();
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return $this->movimientos->map(fn ($movimiento) => $this->fila($movimiento))->values()->all();
    }

    private function fila(object $movimiento): array
    {
        $operacion = self::OPERACIONES[$movimiento->operacion]
            ?? ['texto' => ucfirst((string) $movimiento->operacion), 'tono' => 'neutro', 'icono' => 'bi-circle'];
        $detalle = trim((string) ($movimiento->detalles ?? ''));

        return [
            'fecha' => $movimiento->ocurrido_en ? HoraLocal::fechaHora($movimiento->ocurrido_en) : 'Sin fecha',
            'operacion' => $operacion,
            'antecedente' => trim((string) ($movimiento->descripcion ?? '')) ?: trim((string) $movimiento->codeantecedente),
            'codigo' => trim((string) $movimiento->codeantecedente),
            'detalle' => $detalle === '' ? null : $detalle,
            'alerta' => (bool) $movimiento->alerta,
            'consulta' => $this->consulta($movimiento),
            'quien' => $this->quien($movimiento),
        ];
    }

    /** @return array{texto:string,url:?string}|null */
    private function consulta(object $movimiento): ?array
    {
        if ($movimiento->consulta_id === null) {
            return null;
        }

        $id = (int) $movimiento->consulta_id;

        if ($id === $this->consultaActual) {
            return ['texto' => 'Esta consulta', 'url' => null];
        }

        // Una consulta borrada (o de otra historia) se nombra sin enlace.
        if ($movimiento->consulta_numero === null
            || ($this->numhistoria !== null && (string) (int) $movimiento->consulta_historia !== (string) (int) $this->numhistoria)) {
            return ['texto' => 'Consulta borrada', 'url' => null];
        }

        $fecha = $movimiento->consulta_fecha ? ' · ' . Carbon::parse($movimiento->consulta_fecha)->format('d/m/Y') : '';

        return [
            'texto' => 'Consulta ' . (int) $movimiento->consulta_numero . $fecha,
            'url' => ($this->urlDeConsulta)($id),
        ];
    }

    /** La hora guardada (en la zona del servidor) en la del consultorio, como la bitácora. */
    public static function local($momento): Carbon
    {
        return Carbon::parse($momento, (string) config('app.timezone', 'UTC'))->setTimezone(FechaDelConsultorio::zona());
    }

    private function quien(object $movimiento): string
    {
        $origen = self::ORIGENES[$movimiento->origen] ?? (string) $movimiento->origen;
        $usuario = trim((string) ($movimiento->usuario ?? ''));

        return $usuario === '' ? $origen : $usuario . ' · ' . $origen;
    }
}

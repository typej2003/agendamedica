<?php

namespace App\Clinica\Agenda;

use App\Models\MotivoCita;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Todo lo que la agenda necesita para mostrar un rango: lo que resolvió `LectorDeAgenda`, sin formatear.
 * Es un contenedor de datos: el formato (textos, estados, URLs) lo arman los ViewModels de `Vistas/`.
 */
final class LecturaDeAgenda
{
    /**
     * @param  Collection<int,Office>  $sedes
     * @param  Collection<int,MotivoCita>  $razones  el catálogo de razones del médico
     * @param  Collection<int,Jornada>  $jornadas  las jornadas **visibles** (ya filtradas por sede y razón)
     * @param  array<int,int>  $posiciones  id de cita => posición, calculada sobre **todo** el rango
     * @param  Collection<string,int>  $conteosPorDia  `Y-m-d` => citas visibles
     * @param  Collection<string,\App\Models\DiaNoLaborable>  $noLaborables  `Y-m-d` => día marcado
     * @param  array<int,string>  $mensajes  id de cita => texto del recordatorio
     * @param  Collection<int,CitaDeAgenda>  $pacientesSinHistoria  las citas del día **que se está
     *         mirando** cuyo paciente todavía no tiene número de historia (panel WEB-2.11)
     */
    public function __construct(
        public readonly ParametrosDeAgenda $parametros,
        public readonly Collection $sedes,
        public readonly Collection $razones,
        public readonly ?string $razon,
        public readonly Collection $jornadas,
        public readonly array $posiciones,
        public readonly Collection $conteosPorDia,
        public readonly Collection $noLaborables,
        public readonly ?string $avisoNoLaborable,
        public readonly int $totalRango,
        public readonly array $mensajes,
        public readonly Carbon $ahora,
        public readonly Collection $pacientesSinHistoria,
    ) {
    }
}

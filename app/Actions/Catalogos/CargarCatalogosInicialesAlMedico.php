<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use App\Models\Medico;
use Illuminate\Support\Facades\DB;

/**
 * **El médico nuevo no empieza en cero** (WEB-12.9, decisión del usuario, 2026-10-21): al asignarle una especialidad desde el panel, se le copia el catálogo inicial
 * —lo general y lo de sus especialidades— **en cada catálogo en el que todavía no tiene nada**. Lo que ya tiene no se toca, y lo que se agregue después al catálogo
 * inicial lo carga él con «Cargar el catálogo inicial».
 *
 *  - **No corre para un médico del escritorio** (PowerBuilder): su carga inicial trae sus propios catálogos y se mezclarían. Se reconoce porque alguno de sus
 *    `reg_medico` tiene una carga (`sync_cargas`) o una credencial del puente (`sync_credentials`).
 *  - Tampoco para un **centro médico**: no atiende; los catálogos son de cada doctor.
 *  - Las vacunas iniciales van sólo a quien tiene Pediatría (no tienen marca de especialidad: son de pediatría).
 *  - Los catálogos se recorren en el orden del registro, que deja las dependencias primero (medicamentos antes que tratamientos, tipos antes que exámenes).
 */
final class CargarCatalogosInicialesAlMedico
{
    public function __construct(private CopiarCatalogoInicial $copiar, private CopiarVacunasIniciales $vacunas)
    {
    }

    /**
     * @return array{saltado: string|null, filas: int, catalogos: array<string,int>}
     */
    public function ejecutar(Medico $medico): array
    {
        $resultado = ['saltado' => null, 'filas' => 0, 'catalogos' => []];

        if ($medico->es_centro) {
            return ['saltado' => 'es un centro médico'] + $resultado;
        }

        $registros = $medico->registrosDeTrabajo();
        if ($registros === [] || empty($medico->reg_medico)) {
            return ['saltado' => 'no tiene registro'] + $resultado;
        }

        if (DB::table('sync_cargas')->whereIn('reg_medico', $registros)->exists() || DB::table('sync_credentials')->whereIn('reg_medico', $registros)->exists()) {
            return ['saltado' => 'trabaja con el escritorio'] + $resultado;
        }

        $especialidades = $medico->specialties()->pluck('specialties.id')->map(fn ($id) => (int) $id)->all();

        foreach (CatalogosIniciales::todos() as $slug => $def) {
            if (DB::table($def['tabla'])->whereIn('reg_medico', $registros)->exists()) {
                continue;
            }

            $r = $this->copiar->ejecutar($slug, (string) $medico->reg_medico, $registros, $especialidades);
            $resultado['catalogos'][$slug] = $r['copiadas'];
            $resultado['filas'] += $r['copiadas'];
        }

        if ($medico->specialties()->where('slug', 'pediatria')->exists() && DB::table('vacunas')->whereIn('reg_medico', $registros)->doesntExist()) {
            $r = $this->vacunas->ejecutar((string) $medico->reg_medico);
            $resultado['catalogos']['vacunas'] = $r['vacunas'];
            $resultado['filas'] += $r['vacunas'];
        }

        return $resultado;
    }
}

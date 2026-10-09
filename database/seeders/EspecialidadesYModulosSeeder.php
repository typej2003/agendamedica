<?php

namespace Database\Seeders;

use App\Especialidades\RegistroEspecialidades;
use App\Models\ModuloClinico;
use App\Models\Specialty;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Siembra el marco multi-especialidad (PLAN-WEB.md, R2):
 *
 *  1. el catálogo `modulos_clinicos`;
 *  2. la fila de `specialties` de cada especialidad con manifiesto, con su `codigo`;
 *  3. qué módulos tiene cada especialidad, si ya está **implementado** y qué roles lo ven.
 *
 * Es idempotente: se puede correr las veces que haga falta (migrate:fresh --seed, o `db:seed` en un
 * servidor que ya tenía datos). Los módulos declarados en el manifiesto pero no listados en
 * `IMPLEMENTADOS` quedan creados con `implementado = false`: no aparecen en el menú todavía.
 *
 * Es catálogo, no datos de prueba: corre también en producción (igual que `SpecialtySeeder`).
 */
class EspecialidadesYModulosSeeder extends Seeder
{
    /** Catálogo de módulos: slug => [nombre visible, orden en el menú]. */
    public const MODULOS = [
        // Núcleo compartido
        'agenda'         => ['Agenda', 10],
        'pacientes'      => ['Pacientes', 20],
        'historia'       => ['Historia clínica', 30],
        'consulta'       => ['Consulta', 40],
        'recetas'        => ['Récipes', 50],
        'documentos'     => ['Documentos', 60],
        'catalogos'      => ['Catálogos', 70],
        'reportes'       => ['Reportes', 80],
        'configuracion'  => ['Configuración', 90],
        // Propios de ginecología / obstetricia
        'prenatal'       => ['Control prenatal', 100],
        'ecografias'     => ['Ecografías', 110],
        'ultrasonidos'   => ['Ultrasonidos', 120],
        'procedimientos' => ['Procedimientos', 130],
        'radiologia'     => ['Radiología', 140],
        'pareja'         => ['Consulta de pareja', 150],
    ];

    /**
     * Módulos ya construidos, por slug de manifiesto: módulo => roles que lo ven
     * (`null` = todo el consultorio). Todo lo que no esté acá se siembra oculto.
     */
    private const IMPLEMENTADOS = [
        'ginecologia' => [
            'agenda'    => ['Medico', 'Secretaria'],
            'pacientes' => ['Medico', 'Secretaria'],
        ],
    ];

    public function run(): void
    {
        $registro = new RegistroEspecialidades();
        $registro->validar();

        foreach ($registro->todos() as $manifiesto) {
            $specialty = Specialty::firstOrNew(['slug' => $manifiesto->specialtySlug]);
            if (! $specialty->exists) {
                $specialty->name = $manifiesto->nombre;
            }
            $specialty->codigo = $manifiesto->codigo;
            $specialty->activo = true;
            $specialty->save();

            $implementados = self::IMPLEMENTADOS[$manifiesto->slug] ?? [];

            foreach ($manifiesto->modulos as $slug) {
                if (! isset(self::MODULOS[$slug])) {
                    throw new RuntimeException(
                        "El manifiesto '{$manifiesto->slug}' declara el módulo '{$slug}', que no está en el catálogo"
                        . ' de ' . self::class . '::MODULOS. Agregalo ahí con su nombre y orden.'
                    );
                }

                [$nombre, $orden] = self::MODULOS[$slug];
                $modulo = ModuloClinico::firstOrCreate(['slug' => $slug], ['nombre' => $nombre, 'orden' => $orden]);
                // Mantiene el catálogo al día si le cambian el nombre o el orden en el seeder.
                if ($modulo->nombre !== $nombre || $modulo->orden !== $orden) {
                    $modulo->update(['nombre' => $nombre, 'orden' => $orden]);
                }

                $specialty->modulos()->syncWithoutDetaching([
                    $modulo->id => [
                        'implementado' => array_key_exists($slug, $implementados),
                        'visible_para' => $implementados[$slug] ?? null,
                        'orden'        => $orden,
                    ],
                ]);
            }
        }
    }
}

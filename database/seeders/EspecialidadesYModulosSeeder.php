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
 * servidor que ya tenía datos). Los módulos declarados en el manifiesto pero no construidos quedan creados
 * con `implementado = false`: no aparecen en el menú todavía.
 *
 * El **núcleo compartido** (agenda y pacientes, ver `NUCLEO_IMPLEMENTADO`) se siembra para **todas** las
 * especialidades del catálogo, no sólo para las que tienen manifiesto: sin eso, un médico con Pediatría,
 * Traumatología o Medicina General entraba a un consultorio sin ningún módulo.
 *
 * Es catálogo, no datos de prueba: corre también en producción (igual que `SpecialtySeeder`), así que en una
 * base ya desplegada hay que correrlo una vez (`php artisan db:seed --class=EspecialidadesYModulosSeeder`)
 * para que las especialidades que ya existían reciban el núcleo.
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
     * El **núcleo compartido ya construido**, que tienen **todas** las especialidades (PLAN-WEB.md, R1:
     * la especialidad **agrega** módulos, no reemplaza el núcleo): módulo => roles que lo ven.
     *
     * No es de ninguna especialidad en particular y por eso no está en `PROPIOS_IMPLEMENTADOS`: la agenda y
     * la lista de pacientes leen `cola`, `offices`, `pacientes` y `medico_pacientes`, que son del núcleo.
     *
     * ⚠️ Antes esto era un mapa **por especialidad** y sólo ginecología lo tenía: las otras 7 filas del
     * catálogo (`SpecialtySeeder`) quedaban con **cero** módulos, así que un médico con Pediatría,
     * Traumatología, Medicina General… entraba a un consultorio con el menú vacío ("No hay módulos
     * habilitados en este consultorio"), aunque tuviera la especialidad activa y su consultorio cargado
     * (medido el 2026-10-11, al agregar la pantalla de Especialidades del panel: 7 de las 8 especialidades
     * del catálogo no daban ningún módulo).
     *
     * Es **público** a propósito: `especialidades:sembrar` —el paso que se corre al final de cada
     * despliegue— verifica con esta misma lista que ninguna especialidad quede sin el núcleo, sin repetir
     * los slugs.
     */
    public const NUCLEO_IMPLEMENTADO = [
        'agenda'    => ['Medico', 'Secretaria'],
        'pacientes' => ['Medico', 'Secretaria'],
    ];

    /**
     * Módulos **propios** de una especialidad que ya están construidos: slug del manifiesto => [módulo => roles].
     * Todo lo declarado en el manifiesto que no esté acá se siembra **oculto** (`implementado = false`).
     *
     * Hoy no hay ninguno: los módulos de ginecología (ecografías, prenatal, procedimientos…) todavía no
     * tienen pantalla. Cuando una especialidad estrene uno, se agrega acá y aparece en su menú.
     */
    private const PROPIOS_IMPLEMENTADOS = [
        'ginecologia' => [],
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

            $implementados = self::NUCLEO_IMPLEMENTADO + (self::PROPIOS_IMPLEMENTADOS[$manifiesto->slug] ?? []);

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

        $this->sembrarElNucleo();
    }

    /**
     * El núcleo compartido para **todas** las especialidades del catálogo, tengan manifiesto o no.
     *
     * Las especialidades sin manifiesto son las que `SpecialtySeeder` deja en el catálogo (pediatría,
     * cardiología, traumatología…) y hasta ahora no recibían ninguna fila de `especialidad_modulo`: el
     * médico entraba a un consultorio sin menú. Es idempotente (`syncWithoutDetaching`), así que correrlo
     * en una base que ya tenía los datos sólo agrega lo que falta.
     */
    private function sembrarElNucleo(): void
    {
        foreach (self::NUCLEO_IMPLEMENTADO as $slug => $roles) {
            [$nombre, $orden] = self::MODULOS[$slug];
            $modulo = ModuloClinico::firstOrCreate(['slug' => $slug], ['nombre' => $nombre, 'orden' => $orden]);

            foreach (Specialty::all() as $specialty) {
                $specialty->modulos()->syncWithoutDetaching([
                    $modulo->id => ['implementado' => true, 'visible_para' => $roles, 'orden' => $orden],
                ]);
            }
        }
    }
}

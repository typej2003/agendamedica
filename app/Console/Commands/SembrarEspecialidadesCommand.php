<?php

namespace App\Console\Commands;

use App\Models\Specialty;
use Database\Seeders\EspecialidadesYModulosSeeder;
use Database\Seeders\SpecialtySeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * **El catálogo de especialidades y sus módulos, al día**: es el paso que se corre al final de cada
 * despliegue.
 *
 * Por qué existe: `specialties` y `especialidad_modulo` son **catálogo** y no viajan por migración, así que
 * un servidor que ya tenía datos (QA, producción) **no recibe el marco multi-especialidad al desplegar**. El
 * síntoma es silencioso: el médico entra a `/clinica` con el menú **vacío** ("No hay módulos habilitados en
 * este consultorio todavía") aunque tenga la especialidad activa y su consultorio cargado —medido el
 * 2026-10-11: sólo ginecología tenía filas, así que **7 de las 8** especialidades del catálogo no daban
 * ningún módulo—.
 *
 * Es **idempotente**: delega en los seeders (`SpecialtySeeder` + `EspecialidadesYModulosSeeder`), que usan
 * `firstOrCreate`/`syncWithoutDetaching` y **sólo agregan lo que falta** —no renombran, no borran y no pisan
 * lo que un administrador haya decidido—. Correrlo dos veces seguidas no cambia nada, así que se puede dejar
 * fijo en el `deploy` sin condición: `php artisan especialidades:sembrar`.
 *
 * Las reglas **no** se escriben acá: el núcleo compartido y los módulos propios viven en el seeder. Lo que
 * hace el comando es correrlos **y verificar el resultado** contra lo que el menú necesita de verdad
 * (`implementado` + el módulo activo): si algo quedara sin arreglar —por ejemplo un módulo desactivado a
 * mano en `modulos_clinicos`— lo dice y **termina en error**, para que el despliegue no pase como bueno.
 *
 * `--simular` informa qué falta **sin escribir nada**: sirve para mirar el estado de un servidor antes de
 * tocarlo.
 */
class SembrarEspecialidadesCommand extends Command
{
    protected $signature = 'especialidades:sembrar
        {--simular : informa qué falta, sin escribir nada}';

    protected $description = 'Asegura el catálogo de especialidades y sus módulos (idempotente: sólo agrega lo que falta). Para el final del despliegue';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');

        $antes = $this->estado();
        $this->line(
            ($simular ? '<comment>Simulación</comment> — ' : '')
            . sprintf(
                'Catálogo: <info>%d</info> especialidades · <info>%d</info> filas de módulos (%d implementadas)',
                $antes['especialidades'],
                $antes['filas'],
                $antes['implementadas']
            )
        );

        $problemas = $this->problemasDelNucleo();
        $this->informar($problemas);

        if ($simular) {
            $this->line('--simular: no se escribió nada.');

            return self::SUCCESS;
        }

        // La regla vive en los seeders; acá sólo se los corre (son idempotentes y `--force` los deja correr
        // en producción, donde `db:seed` pide confirmación). `callSilent` es a propósito: el resumen de este
        // comando ya dice qué cambió y el "Seeding: …" de cada seeder sólo ensucia el log del despliegue.
        $this->callSilent('db:seed', ['--class' => SpecialtySeeder::class, '--force' => true]);
        $this->callSilent('db:seed', ['--class' => EspecialidadesYModulosSeeder::class, '--force' => true]);

        $despues = $this->estado();
        $this->newLine();
        $this->line(sprintf(
            'Filas de módulos: %d → <info>%d</info> (%d nuevas) · implementadas: %d → <info>%d</info>',
            $antes['filas'],
            $despues['filas'],
            $despues['filas'] - $antes['filas'],
            $antes['implementadas'],
            $despues['implementadas']
        ));

        $arregladas = array_diff(array_keys($problemas), array_keys($this->problemasDelNucleo()));
        $this->line(match (true) {
            $antes['especialidades'] === 0 => sprintf(
                'El catálogo estaba vacío: quedaron <info>%d</info> especialidades con el núcleo.',
                $despues['especialidades']
            ),
            $arregladas !== [] => 'Se agregó el núcleo a: <info>' . implode(', ', $arregladas) . '</info>',
            default => 'El catálogo ya estaba al día: no había nada que agregar.',
        });

        $pendientes = $this->problemasDelNucleo();
        if ($pendientes !== []) {
            $this->error('Quedaron especialidades sin el núcleo compartido del consultorio:');
            $this->informar($pendientes);

            return self::FAILURE;
        }

        $this->info('Todas las especialidades del catálogo tienen el núcleo (agenda y pacientes).');

        return self::SUCCESS;
    }

    /** @return array{especialidades:int, filas:int, implementadas:int} */
    private function estado(): array
    {
        return [
            'especialidades' => Specialty::count(),
            'filas'          => (int) DB::table('especialidad_modulo')->count(),
            'implementadas'  => (int) DB::table('especialidad_modulo')->where('implementado', true)->count(),
        ];
    }

    /**
     * Qué le falta al **núcleo compartido**, especialidad por especialidad: es exactamente lo que deja el
     * menú del consultorio vacío. Se mira con la misma vara que el menú (`modulosVisibles`): la fila tiene
     * que existir, estar implementada y su módulo tiene que estar activo.
     *
     * @return array<string, list<string>> nombre de la especialidad => qué le falta
     */
    private function problemasDelNucleo(): array
    {
        $problemas = [];

        foreach (Specialty::orderBy('name')->get() as $especialidad) {
            $filas = DB::table('especialidad_modulo as em')
                ->join('modulos_clinicos as m', 'm.id', '=', 'em.modulo_id')
                ->where('em.specialty_id', $especialidad->id)
                ->get(['m.slug', 'm.activo', 'em.implementado'])
                ->keyBy('slug');

            $faltantes = [];
            foreach (array_keys(EspecialidadesYModulosSeeder::NUCLEO_IMPLEMENTADO) as $slug) {
                $fila = $filas->get($slug);

                if ($fila === null) {
                    $faltantes[] = "sin fila de {$slug}";
                } elseif (! $fila->implementado) {
                    $faltantes[] = "{$slug} sin implementar";
                } elseif (! $fila->activo) {
                    // El seeder no lo activa: el módulo está dado de baja en el catálogo y eso se decide a mano.
                    $faltantes[] = "el módulo {$slug} está inactivo en modulos_clinicos";
                }
            }

            if ($faltantes !== []) {
                $problemas[$especialidad->name] = $faltantes;
            }
        }

        return $problemas;
    }

    /** @param  array<string, list<string>>  $problemas */
    private function informar(array $problemas): void
    {
        foreach ($problemas as $especialidad => $detalle) {
            $this->warn("· {$especialidad}: " . implode(', ', $detalle));
        }
    }
}

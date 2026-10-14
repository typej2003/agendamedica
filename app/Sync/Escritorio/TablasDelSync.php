<?php

namespace App\Sync\Escritorio;

use App\Models\Medico;

/**
 * Qué tablas del legado se le ofrecen al escritorio, **según la especialidad del médico** (WEB-7.3).
 *
 * Hasta acá había una sola lista (la histórica de `config/sync_legado.php`), que es la de ginecología:
 * servía mientras el único escritorio que sincronizaba era el de GinecoReport. Con pediatría entran
 * tablas que ninguna otra especialidad tiene (`paciente_chamo`, `vacunas`, las de crecimiento), así que
 * "sumar una especialidad" tiene que ser sumar su manifiesto, no tocar el motor:
 *
 *  - `config/sync_legado.php` → `tablas` es la lista histórica (el núcleo compartido y lo de ginecología);
 *  - el manifiesto de cada especialidad (`config/especialidades/<slug>.php` → `sync.tablas`) dice qué
 *    agrega;
 *  - acá se combinan, y el resultado es lo que recibe el escritorio (`cambios/estado` → `tablas` y
 *    `carga-inicial/iniciar` → `orden`).
 *
 * Una especialidad **sin manifiesto** (o sin `sync.tablas`) sigue usando la lista histórica: es el
 * comportamiento que ya tenían las siete especialidades que hoy no tienen manifiesto. No se les cambia
 * nada hasta que se haga su relevamiento.
 *
 * Lo que el API **acepta** escribir es la unión de todas (`aceptadas()`): es la puerta de la carga
 * inicial y de los cambios, y conviene que sea permisiva — si un médico tiene dos especialidades, o si
 * su especialidad todavía no tiene manifiesto, sus datos no se pueden rechazar por eso. Lo que sí es
 * por especialidad es lo que se le **pide** al escritorio.
 */
final class TablasDelSync
{
    /** La lista histórica: el núcleo y las tablas de ginecología (`config/sync_legado.php`). */
    public function historicas(): array
    {
        return array_values((array) config('sync_legado.tablas', []));
    }

    /** Lo que el manifiesto de esa especialidad agrega a la lista histórica. */
    public function deLaEspecialidad(?string $especialidad): array
    {
        if ($especialidad === null || $especialidad === '') {
            return [];
        }

        return array_values((array) config("sync_legado.tablas_por_especialidad.{$especialidad}", []));
    }

    /**
     * Las tablas que se le ofrecen al escritorio de esa especialidad. `pacientes` va primero (la lista
     * histórica ya arranca así): la carga inicial crea el paciente y su historia antes que el resto.
     *
     * @return array<int, string>
     */
    public function para(?string $especialidad): array
    {
        return array_values(array_unique(array_merge($this->historicas(), $this->deLaEspecialidad($especialidad))));
    }

    /** Todas las tablas que el API acepta escribir, de cualquier especialidad. */
    public function aceptadas(): array
    {
        $todas = $this->historicas();
        foreach (array_keys((array) config('sync_legado.tablas_por_especialidad', [])) as $especialidad) {
            $todas = array_merge($todas, $this->deLaEspecialidad((string) $especialidad));
        }

        return array_values(array_unique($todas));
    }

    /** ¿El API acepta escrituras en esta tabla (de cualquier especialidad)? */
    public function acepta(string $tabla): bool
    {
        return in_array($tabla, $this->aceptadas(), true);
    }

    /**
     * Nombres de columna que el escritorio llama distinto (tabla => [escritorio => API]).
     *
     * @return array<string, string>
     */
    public function equivalentes(string $tabla): array
    {
        return (array) config("sync_legado.columnas_equivalentes.{$tabla}", []);
    }

    /**
     * La especialidad de AppDDR con la que sincroniza este médico: la primera activa del pivote
     * `medico_specialty` (la misma que usa el contexto de la web).
     */
    public function especialidadDe(?Medico $medico): ?string
    {
        return $this->especialidadesDe($medico)[0] ?? null;
    }

    /**
     * **Todas** las especialidades del médico (activas si tiene alguna, si no las que tenga).
     *
     * `medico_specialty` es un pivote y el panel permite varias: un médico puede atender ginecología y
     * pediatría. Para el sync eso significa que su base local tiene las tablas de las dos.
     *
     * @return array<int, string>
     */
    public function especialidadesDe(?Medico $medico): array
    {
        if (! $medico) {
            return [];
        }

        $activas = $medico->specialties->where('activo', true);
        $especialidades = $activas->isNotEmpty() ? $activas : $medico->specialties;

        return array_values($especialidades->pluck('slug')->filter()->all());
    }

    /**
     * Las tablas que se le ofrecen al escritorio del médico: la **unión de todas sus especialidades**.
     *
     * No alcanza con la primera: si un médico atiende dos especialidades y se le ofrece la lista de una
     * sola, las tablas de la otra no reciben triggers y esos datos **no suben nunca** (sin error: el
     * escritorio sólo vigila lo que el API le dice). Un médico sin especialidad en el pivote sigue
     * recibiendo la lista histórica.
     */
    public function paraMedico(?Medico $medico): array
    {
        $especialidades = $this->especialidadesDe($medico);
        if ($especialidades === []) {
            return $this->para(null);
        }

        $tablas = $this->historicas();
        foreach ($especialidades as $especialidad) {
            $tablas = array_merge($tablas, $this->deLaEspecialidad($especialidad));
        }

        return array_values(array_unique($tablas));
    }

    /**
     * Las tablas del escritorio de donde salen los **pacientes** de este médico, con el mapeo de columnas
     * de cada una (tabla => [escritorio => API]).
     *
     * `pacientes` está siempre: es la tabla del legado compartido y la que usan ginecología y las demás
     * especialidades. Una especialidad puede declarar **otra** en su manifiesto (`sync.pacientes`), como
     * pediatría con `paciente_chamo` —su ABM real de pacientes, mientras su `pacientes` está vacía—. Un
     * médico con dos especialidades tiene las dos tablas y las dos traen pacientes: por eso es un mapa y
     * no una sola tabla.
     *
     * El camino de pacientes (crear el paciente, la relación con el médico y la historia) es el mismo
     * para todas; el mapeo sólo traduce los nombres de columna antes de armarlo.
     *
     * @return array<string, array<string, string>>
     */
    public function pacientesDe(?Medico $medico): array
    {
        $tablas = ['pacientes' => []];

        foreach ($this->especialidadesDe($medico) as $especialidad) {
            $declarado = (array) config("sync_legado.pacientes_por_especialidad.{$especialidad}", []);
            $tabla = (string) ($declarado['tabla'] ?? '');

            if ($tabla !== '' && $tabla !== 'pacientes') {
                $tablas[$tabla] = (array) ($declarado['columnas'] ?? []);
            }
        }

        return $tablas;
    }

    /** ¿Esta tabla del escritorio trae pacientes para este médico? */
    public function esTablaDePacientes(string $tabla, ?Medico $medico): bool
    {
        return array_key_exists($tabla, $this->pacientesDe($medico));
    }
}

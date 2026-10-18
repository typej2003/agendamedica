<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Examen;
use App\Models\TipoExamen;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **El catálogo del que se piden los exámenes y los estudios** (WEB-5.4): la tabla `examenes` del médico, partida en dos por su tipo.
 *
 * ## Un solo catálogo, dos listas (verificado en la fuente, 2026-10-12)
 *
 * El escritorio no tiene un catálogo de laboratorio y otro de estudios: tiene **uno** (`examenes`, con su `codetipo`) y las pestañas
 * de las *Solicitudes* lo reparten por el tipo **`002` = «ESTUDIO DE IMAGENES»**:
 *
 *  - **Exámenes de laboratorio** (`d_examenes_listado_escoger.srd`): `examenes` donde `codetipo <> '002'` — todo lo demás
 *    (laboratorio, estudios especiales, preoperatorios…) se pide aquí;
 *  - **Solicitud de estudios** (`d_radiologia_e_paciente.srd`, `retrieve` del `dw_20`): `examenes` donde `codetipo = '002'`.
 *
 * La tabla `radiologias` (84 filas en la base de desarrollo, con su `estudio`, `codetipo`, `opciones` y `tipo`) **no se usa** para
 * pedir: `radiologia_paciente.coderadio` apunta al `codeexamen` de un examen de tipo `002` (4.260 de las 4.264 filas lo cruzan con
 * `examenes`, ninguna con `radiologias`). Por eso el catálogo de estudios **es** el de exámenes —el ABM de WEB-8.1b ya lo
 * administra, con el tipo `002`— y no hace falta uno aparte.
 *
 * ## Qué es una «rutina»
 *
 * La pestaña *Rutinas de exámenes* (`w_consulta_principal.srw:16269-16730`) lista los **tipos de examen** (`d_tipos_examenes`, sin el
 * `002`) y su botón *Pasar* inserta en `examen_paciente` **todos los exámenes de ese tipo** (`d_listado_tipos_examenes`, `:16590-16620`):
 * una rutina es un tipo de examen usado como paquete («Hematología», «Preoperatorios»). No es otra tabla ni otra fila.
 *
 * La búsqueda es la de los demás catálogos de la web: **sin tildes ni mayúsculas**, por todas las palabras, sobre el nombre.
 * Se trae el catálogo del médico (cientos de filas) y se filtra en PHP —como el vademécum—; quien llama (el JSON del buscador) corta a
 * `MAX_RESULTADOS`, así que la pantalla nunca recibe el catálogo entero. Un examen por nombre y tipo (el de código más bajo): el legado trae repetidos y dos
 * filas iguales en la lista no se distinguen.
 *
 * Sólo lectura.
 */
final class CatalogoParaPedirExamenes
{
    /** El tipo de los estudios de imágenes (`tipos_examenes.codetipo`): lo que separa las dos listas. */
    public const TIPO_ESTUDIOS = '002';

    /** Cuántos resultados le entrega el buscador a la pantalla como máximo. */
    public const MAX_RESULTADOS = 30;

    /** Cuántos exámenes o estudios muestra cada página del catálogo a la vista (la columna derecha de O-29). */
    public const POR_PAGINA = 25;

    /** El nombre del grupo de los exámenes cuyo tipo no está en el catálogo de tipos. */
    public const SIN_TIPO = 'Otros exámenes';

    /**
     * Los exámenes del catálogo que coinciden con lo escrito (y, para laboratorio, con el tipo elegido), **todos** y mejor coincidencia primero.
     *
     * @param  bool  $estudios  `true` = los de tipo `002`; `false` = todos los demás
     * @param  string  $tipo  el código de un tipo (sólo laboratorio) o vacío
     * @return Collection<int,array{codigo:string, nombre:string, codetipo:?string, tipo:?string}>
     */
    public function buscar(string $regMedico, bool $estudios, string $buscar = '', string $tipo = ''): Collection
    {
        $palabras = array_map([BusquedaDePacientes::class, 'normalizar'], BusquedaDePacientes::palabras($buscar));

        return $this->delCatalogo($regMedico, $estudios)
            ->filter(fn (array $examen) => $tipo === '' || $examen['codetipo'] === $tipo)
            ->filter(function (array $examen) use ($palabras) {
                $pajar = BusquedaDePacientes::normalizar($examen['nombre']);

                foreach ($palabras as $palabra) {
                    if (! str_contains($pajar, $palabra)) {
                        return false;
                    }
                }

                return true;
            })
            // Los que empiezan con lo escrito primero, después por nombre.
            ->sortBy(fn (array $examen) => ($palabras !== [] && str_starts_with(BusquedaDePacientes::normalizar($examen['nombre']), $palabras[0]) ? '0' : '1')
                . BusquedaDePacientes::normalizar($examen['nombre']) . '|' . str_pad($examen['codigo'], 8, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * **El catálogo completo a la vista, por páginas** (O-29): lo mismo que `buscar` pero sin el tope de `MAX_RESULTADOS`, que es
     * *para elegir en un desplegable*, no para listar el catálogo. Es la lista de la derecha de la pantalla, como la de motivos.
     *
     * @param  array<string,mixed>  $conservar  lo que los enlaces de página deben mantener (`seccion`, `solicitud`, `buscar`, `tipo`)
     * @return LengthAwarePaginator<int,array{codigo:string, nombre:string, codetipo:?string, tipo:?string}>
     */
    public function pagina(
        string $regMedico,
        bool $estudios,
        string $buscar,
        string $tipo,
        int $pagina,
        string $ruta,
        array $conservar = [],
        int $porPagina = self::POR_PAGINA,
    ): LengthAwarePaginator {
        $todos = $this->buscar($regMedico, $estudios, $buscar, $tipo);
        $pagina = max(1, $pagina);

        return (new LengthAwarePaginator(
            $todos->forPage($pagina, $porPagina)->values(),
            $todos->count(),
            $porPagina,
            $pagina,
            ['path' => $ruta, 'pageName' => 'pagina_catalogo'],
        ))->appends($conservar);
    }

    /**
     * Los **tipos** de examen del catálogo que se está mostrando, para el filtro de la pantalla: sólo los que tienen algún examen en
     * esa lista (`código => nombre`). Los estudios (`002`) no tienen filtro: son una sola lista.
     *
     * @return array<string,string>
     */
    public function tiposDeLaLista(string $regMedico, bool $estudios): array
    {
        $tipos = $this->tipos($regMedico);
        $usados = [];

        foreach ($this->delCatalogo($regMedico, $estudios) as $examen) {
            if ($examen['codetipo'] !== null) {
                $usados[$examen['codetipo']] = $examen['tipo'] ?? $tipos[$examen['codetipo']] ?? ('Tipo ' . $examen['codetipo']);
            }
        }

        asort($usados, SORT_NATURAL | SORT_FLAG_CASE);

        return $usados;
    }

    /**
     * Las **rutinas**: los tipos de examen del médico (sin el de estudios) con cuántos exámenes tiene cada uno. Un tipo sin exámenes
     * no es una rutina (no hay qué pasar) y no se ofrece.
     *
     * @return array<int,array{codigo:string, nombre:string, cantidad:int}>
     */
    public function rutinas(string $regMedico): array
    {
        $examenes = $this->delCatalogo($regMedico, false)->groupBy(fn (array $examen) => $examen['codetipo'] ?? '');
        $rutinas = [];

        foreach ($this->tipos($regMedico) as $codigo => $nombre) {
            if ($codigo === self::TIPO_ESTUDIOS || ! isset($examenes[$codigo])) {
                continue;
            }

            $rutinas[] = ['codigo' => $codigo, 'nombre' => $nombre, 'cantidad' => $examenes[$codigo]->count()];
        }

        usort($rutinas, fn (array $a, array $b) => strcmp(BusquedaDePacientes::normalizar($a['nombre']), BusquedaDePacientes::normalizar($b['nombre'])));

        return $rutinas;
    }

    /**
     * Los exámenes de una rutina (un tipo), por nombre. `null` si el tipo no es una rutina del médico (no existe o es el de estudios).
     *
     * @return Collection<int,array{codigo:string, nombre:string, codetipo:?string, tipo:?string}>|null
     */
    public function deLaRutina(string $regMedico, string $codetipo): ?Collection
    {
        $codetipo = trim($codetipo);

        if ($codetipo === '' || $codetipo === self::TIPO_ESTUDIOS || ! isset($this->tipos($regMedico)[$codetipo])) {
            return null;
        }

        return $this->delCatalogo($regMedico, false)
            ->filter(fn (array $examen) => $examen['codetipo'] === $codetipo)
            ->sortBy(fn (array $examen) => BusquedaDePacientes::normalizar($examen['nombre']) . '|' . str_pad($examen['codigo'], 8, '0', STR_PAD_LEFT))
            ->values();
    }

    /**
     * Los códigos que pertenecen al catálogo de esa lista: `código => examen`. Lo que no está (otro médico, la otra lista) no entra.
     * Acepta **todas** las filas del catálogo, repetidas incluidas (a diferencia de la búsqueda).
     *
     * @param  array<int,string>  $codigos
     * @return array<string,array{codigo:string, nombre:string, codetipo:?string, tipo:?string}>
     */
    public function resolver(string $regMedico, bool $estudios, array $codigos): array
    {
        $buscados = array_flip(array_map('trim', $codigos));
        $tipos = $this->tipos($regMedico);
        $resueltos = [];

        foreach (Examen::where('reg_medico', $regMedico)->orderBy('id')->get() as $fila) {
            $codigo = trim((string) $fila->codeexamen);

            if (! isset($buscados[$codigo]) || isset($resueltos[$codigo]) || $this->esDeEstudios($fila) !== $estudios) {
                continue;
            }

            $resueltos[$codigo] = $this->examen($fila, $tipos);
        }

        return $resueltos;
    }

    /** ¿El médico tiene alguna fila en esa lista? Distingue «tu catálogo está vacío» de «no coincide nada». */
    public function hay(string $regMedico, bool $estudios): bool
    {
        return Examen::where('reg_medico', $regMedico)->get()->contains(fn (Examen $fila) => $this->esDeEstudios($fila) === $estudios);
    }

    /**
     * Los tipos del médico: `código => nombre`.
     *
     * @return array<string,string>
     */
    public function tipos(string $regMedico): array
    {
        $tipos = [];

        foreach (TipoExamen::where('reg_medico', $regMedico)->orderBy('tipo')->orderBy('codetipo')->get(['codetipo', 'tipo']) as $fila) {
            $codigo = CamposDePaciente::texto($fila->codetipo);

            if ($codigo !== null) {
                $tipos[$codigo] ??= CamposDePaciente::texto($fila->tipo) ?? ('Tipo ' . $codigo);
            }
        }

        return $tipos;
    }

    // -------------------------------------------------------------------------------------------- Interno

    /**
     * El catálogo de una de las dos listas, un examen por nombre y tipo (el de código más bajo) y sin los que no tienen nombre.
     *
     * @return Collection<int,array{codigo:string, nombre:string, codetipo:?string, tipo:?string}>
     */
    private function delCatalogo(string $regMedico, bool $estudios): Collection
    {
        $tipos = $this->tipos($regMedico);

        return Examen::where('reg_medico', $regMedico)->orderBy('id')->get()
            ->filter(fn (Examen $fila) => $this->esDeEstudios($fila) === $estudios)
            ->map(fn (Examen $fila) => $this->examen($fila, $tipos))
            ->filter(fn (array $examen) => $examen['nombre'] !== '' && $examen['codigo'] !== '')
            ->groupBy(fn (array $examen) => BusquedaDePacientes::normalizar($examen['nombre']) . '|' . ($examen['codetipo'] ?? ''))
            ->map(fn (Collection $iguales) => $iguales->sortBy(fn (array $examen) => str_pad($examen['codigo'], 8, '0', STR_PAD_LEFT))->first())
            ->values();
    }

    private function esDeEstudios(Examen $fila): bool
    {
        return CamposDePaciente::texto($fila->codetipo) === self::TIPO_ESTUDIOS;
    }

    /**
     * @param  array<string,string>  $tipos
     * @return array{codigo:string, nombre:string, codetipo:?string, tipo:?string}
     */
    private function examen(Examen $fila, array $tipos): array
    {
        $codetipo = CamposDePaciente::texto($fila->codetipo);

        return [
            'codigo' => trim((string) $fila->codeexamen),
            'nombre' => (string) preg_replace('/\s+/u', ' ', trim((string) TextoDeLaConsulta::limpio($fila->examen))),
            'codetipo' => $codetipo,
            'tipo' => $codetipo === null ? null : ($tipos[$codetipo] ?? null),
        ];
    }
}

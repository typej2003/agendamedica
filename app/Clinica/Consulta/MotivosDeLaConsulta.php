<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\MotivoConsulta;
use App\Models\MotivoConsultaPaciente;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los motivos de consulta de una consulta** (WEB-4.2, ítem 1 del panel del legado): el catálogo del
 * médico con su buscador, los motivos que esa consulta ya tiene, y la relación entre los dos.
 *
 * El catálogo (`motivos_consulta`) es **del médico** y es el mismo que usa el mobile y el escritorio:
 * `codemotivo` de cuatro dígitos y `descripcion` de hasta 40, en mayúsculas. Lo que ya está en la
 * consulta vive en `motivo_consulta_paciente`, que **cuelga de la consulta** por
 * `reg_medico` + `nrohistoria` + `nroconsulta` (no hay `nroconsulta` en `cola`: el vínculo de la cita y
 * la consulta es la historia + la fecha, wiki `04-historia-clinica.md`).
 *
 * Tres decisiones que vienen de los datos reales del legado y del mobile:
 *
 *  - **Un motivo por nombre en la lista del buscador.** El catálogo del dump trae repetidos ("DOLOR
 *    PELVICO", "BIOPSIA DE VERRUGA" con dos códigos): ofrecer dos filas idénticas sería pedirle al
 *    médico que adivine. Se muestra una sola —la del **código más bajo**—, que es lo que hace el
 *    selector del móvil (`motivos_consulta_repository.dart`). Los motivos ya cargados en una consulta
 *    con el otro código **se siguen mostrando bien** porque se resuelven por su código, no por esta lista.
 *  - **La búsqueda no distingue tildes ni mayúsculas**, y por eso no es un `LIKE` de SQL: `LIKE` de
 *    SQLite sólo pliega mayúsculas ASCII (trampa 13 de `AGENTS.md`). Se normaliza con
 *    `BusquedaDePacientes::normalizar()`, que es el único mapa de acentos del proyecto.
 *  - **"Ya está en la consulta" y "existe en el catálogo" son preguntas distintas**: la primera decide
 *    qué fila no se ofrece para agregar y la segunda si el buscador ofrece **crear** el motivo escrito.
 *
 * Es **sólo lectura**: la escritura son las Actions de `App\Actions\Consulta\`.
 */
final class MotivosDeLaConsulta
{
    /** El tope de resultados del buscador: es para elegir, no para listar el catálogo entero. */
    public const MAX_RESULTADOS = 30;

    /** Cuántos motivos del catálogo se ven por página en las «dos listas». */
    public const POR_PAGINA = 12;

    /**
     * El catálogo del médico, un motivo por nombre, ordenado alfabéticamente.
     *
     * @param  string  $buscar  texto a buscar (vacío = todo el catálogo)
     * @return Collection<int,MotivoConsulta>
     */
    public function buscar(string $regMedico, string $buscar = ''): Collection
    {
        return $this->unicos($regMedico, $buscar)->take(self::MAX_RESULTADOS);
    }

    /**
     * **El catálogo completo a la vista, por páginas** (WEB-11.3): la lista de la derecha de las «dos listas». Es la misma lista del
     * buscador (un motivo por nombre, filtrado sin tildes) pero sin el tope de {@see self::MAX_RESULTADOS}.
     *
     * @param  string  $ruta  la URL de la sección, para que los enlaces de página la conserven
     * @param  array<string,mixed>  $conservar  lo que los enlaces deben mantener (`seccion`, `buscar`)
     * @return LengthAwarePaginator<MotivoConsulta>
     */
    public function pagina(string $regMedico, string $buscar, int $pagina, string $ruta, array $conservar = [], int $porPagina = self::POR_PAGINA): LengthAwarePaginator
    {
        $todos = $this->unicos($regMedico, $buscar);
        $pagina = max(1, $pagina);

        return (new LengthAwarePaginator(
            $todos->forPage($pagina, $porPagina)->values(),
            $todos->count(),
            $porPagina,
            $pagina,
            ['path' => $ruta, 'pageName' => 'pagina_catalogo'],
        ))->appends($conservar);
    }

    /** @return Collection<int,MotivoConsulta> */
    private function unicos(string $regMedico, string $buscar): Collection
    {
        $clave = BusquedaDePacientes::normalizar(trim($buscar));

        return MotivoConsulta::where('reg_medico', $regMedico)
            ->orderBy('descripcion')
            ->orderBy('codemotivo')
            ->get()
            ->filter(function (MotivoConsulta $motivo) use ($clave) {
                return $clave === ''
                    || str_contains(BusquedaDePacientes::normalizar((string) $motivo->descripcion), $clave);
            })
            ->groupBy(fn (MotivoConsulta $motivo) => BusquedaDePacientes::normalizar((string) $motivo->descripcion))
            ->map(fn (Collection $iguales) => $iguales->sortBy('codemotivo')->first())
            ->sortBy(fn (MotivoConsulta $motivo) => BusquedaDePacientes::normalizar((string) $motivo->descripcion))
            ->values();
    }

    /**
     * ¿Ese texto **ya es** un motivo del catálogo? Es lo que decide si el buscador ofrece crearlo: con
     * "DOLOR" escrito y un catálogo que tiene "DOLOR PELVICO" **sí** se ofrece crear (es otro motivo),
     * y con "dolor pelvico" no.
     */
    public function existeEnElCatalogo(string $regMedico, string $descripcion): bool
    {
        $clave = BusquedaDePacientes::normalizar(trim($descripcion));

        if ($clave === '') {
            return false;
        }

        return MotivoConsulta::where('reg_medico', $regMedico)
            ->get(['codemotivo', 'descripcion'])
            ->contains(fn (MotivoConsulta $motivo) => BusquedaDePacientes::normalizar((string) $motivo->descripcion) === $clave);
    }

    /** El motivo del catálogo por su código, o `null` si el código no está en el catálogo del médico. */
    public function delCatalogo(string $regMedico, ?string $codigo): ?MotivoConsulta
    {
        if ($codigo === null || trim($codigo) === '') {
            return null;
        }

        return MotivoConsulta::where('reg_medico', $regMedico)->where('codemotivo', trim($codigo))->first();
    }

    /**
     * Los motivos que **esa consulta** ya tiene, en el orden en que se agregaron, con su descripción ya
     * resuelta.
     *
     * La descripción sale del catálogo por el código; si el código ya no está en el catálogo (el legado
     * no borra motivos, pero los datos son datos), se usa la que la propia fila guarda, que es la que se
     * eligió en esa consulta, y si tampoco hay, el código.
     *
     * @return Collection<int,object{id:int, codigo:string, descripcion:string, enCatalogo:bool}>
     */
    public function deLaConsulta(string $regMedico, int $numhistoria, int $nroconsulta): Collection
    {
        $catalogo = MotivoConsulta::where('reg_medico', $regMedico)
            ->get(['codemotivo', 'descripcion'])
            ->keyBy(fn (MotivoConsulta $motivo) => trim((string) $motivo->codemotivo));

        return MotivoConsultaPaciente::where('reg_medico', $regMedico)
            ->where('nrohistoria', $numhistoria)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('id')
            ->get(['id', 'codemotivo', 'descripcion'])
            ->map(function (MotivoConsultaPaciente $fila) use ($catalogo) {
                $codigo = trim((string) $fila->codemotivo);
                $delCatalogo = $catalogo->get($codigo);

                return (object) [
                    'id' => (int) $fila->id,
                    'codigo' => $codigo,
                    'descripcion' => $this->texto($delCatalogo->descripcion ?? null)
                        ?? $this->texto($fila->descripcion)
                        ?? $codigo,
                    'enCatalogo' => $delCatalogo !== null,
                ];
            })
            ->values();
    }

    /**
     * El mapa `codemotivo => descripcion` del catálogo, para que el buscador de la pantalla no tenga que
     * cruzar otra vez lo que ya trajo.
     *
     * @param  Collection<int,MotivoConsulta>  $catalogo
     * @return array<string,string>
     */
    public function porCodigo(Collection $catalogo): array
    {
        return $catalogo
            ->mapWithKeys(fn (MotivoConsulta $motivo) => [trim((string) $motivo->codemotivo) => (string) $motivo->descripcion])
            ->all();
    }

    private function texto(?string $valor): ?string
    {
        $valor = $valor === null ? null : trim($valor);

        return $valor === '' ? null : $valor;
    }
}

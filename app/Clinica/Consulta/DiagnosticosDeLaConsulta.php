<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Diagnostico;
use App\Models\DiagnosticoPaciente;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Los diagnósticos de una consulta** (WEB-4.5, ítem 8 del panel del legado): el catálogo del médico con
 * su buscador, los diagnósticos que esa consulta ya tiene y la relación entre los dos.
 *
 * ## De qué cuelga la fila (verificado en la fuente)
 *
 * Un diagnóstico **de la consulta** es una fila de `diagnostico_paciente` y cuelga de la consulta por
 * **`nrohistoria` + `nroconsulta`**: la tabla no tiene `id` de consulta ni nada parecido —sus columnas son
 * `nrohistoria`, `nroconsulta`, `codediagnostico`, `detalle_diagnostco` y `orden`, con la clave en las tres
 * primeras (`d_diagnostico_consulta_1.srd`, el DataWindow de la pestaña *Diagnóstico*:
 * `update="diagnostico_paciente"`, `updatewhereclause=yes` en las tres y `sort="orden A"`)—, y la ventana
 * la lee con `dw_8.retrieve(historia, consulta)` (`w_consulta_principal.srw:3007-3010` y `:4560-4564`),
 * inserta con `INSERT INTO "diagnostico_paciente" (nrohistoria, nroconsulta, codediagnostico,
 * detalle_diagnostco)` (`:11860-11868`, el botón *Pasar diag.*) y borra por esos tres campos
 * (`:11785-11789`, el botón *Eliminar*). Por eso la regla de escritura vive en `App\Actions\Consulta\` y
 * **no** en `Actions\Pacientes`: acá la fila **sí** es de la consulta, a diferencia de los antecedentes
 * (WEB-4.4), que se llavean sólo por `numhistoria`.
 *
 * ## Tres decisiones que vienen de los datos y de la fuente
 *
 *  - **El nombre sale del catálogo, y si el código ya no está, del `detalle_diagnostco` que guardó la fila**
 *    (y si tampoco, del código). Es la misma resolución que la historia del paciente
 *    (`HistoriaDelPaciente::diagnosticosPorConsulta`, WEB-3.5) para que la misma fila se lea igual en las
 *    dos pantallas, y es lo que el `detalle_diagnostco` significa: el `INSERT` del escritorio copia ahí la
 *    descripción del catálogo al agregar (`:11860-11868`), pero el DataWindow lo deja editar (`edit.limit=100`)
 *    y el `ue_guardar` de la ventana lo manda con `dw_8.update()` (`:2709`).
 *  - **Un diagnóstico por nombre en la lista del buscador** (el de **código más bajo**), porque el catálogo
 *    del legado trae repetidos: el mismo criterio que el buscador de motivos (WEB-4.2) y el de antecedentes
 *    (WEB-4.4). Los diagnósticos que la consulta ya tiene **se siguen mostrando bien** porque se resuelven
 *    por su código, no por esta lista.
 *  - **La búsqueda no distingue tildes ni mayúsculas**, y por eso no es un `LIKE` de SQL: el `LIKE` de
 *    SQLite sólo pliega mayúsculas ASCII (trampa 13 de `AGENTS.md`). Se normaliza con
 *    `BusquedaDePacientes::normalizar()`, que es el único mapa de acentos del proyecto.
 *
 * ## Lo que la fuente NO tiene
 *
 * No hay **tipos** de diagnóstico (impresión diagnóstica, definitivo, CIE…): `diagnosticos` es un catálogo
 * plano de dos columnas —`codediagnostico` (char(5), en la práctica el correlativo de cuatro dígitos del
 * consultorio) y `descripcion` (char(50))—, el DataWindow de la pestaña lee sólo esas dos y la fila no
 * guarda ningún tipo. Tampoco hay un catálogo CIE en las tablas del sync. Por eso la sección **no inventa
 * una clasificación** ni pide un código CIE.
 *
 * Es **sólo lectura**: la escritura es `App\Actions\Consulta\GuardarDiagnosticosDeLaConsulta` y el alta del
 * catálogo, `AgregarDiagnosticoAlCatalogo`.
 */
final class DiagnosticosDeLaConsulta
{
    /** El tope de resultados del buscador: es para elegir, no para listar el catálogo entero. */
    public const MAX_RESULTADOS = 30;

    /** Cuántos diagnósticos del catálogo se ven por página en las «dos listas». */
    public const POR_PAGINA = 12;

    /**
     * El catálogo del médico, un diagnóstico por nombre, ordenado alfabéticamente.
     *
     * @param  string  $buscar  texto a buscar (vacío = todo el catálogo)
     * @return Collection<int,Diagnostico>
     */
    public function buscar(string $regMedico, string $buscar = ''): Collection
    {
        return $this->unicos($regMedico, $buscar)->take(self::MAX_RESULTADOS);
    }

    /**
     * **El catálogo completo a la vista, por páginas** (WEB-11.3): la lista de la derecha de las «dos listas», sin el tope de
     * {@see self::MAX_RESULTADOS}. Misma lista del buscador (un diagnóstico por nombre, sin tildes).
     *
     * @param  array<string,mixed>  $conservar  lo que los enlaces de página deben mantener (`seccion`, `buscar`, `agregar`)
     * @return LengthAwarePaginator<Diagnostico>
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

    /** @return Collection<int,Diagnostico> */
    private function unicos(string $regMedico, string $buscar): Collection
    {
        $clave = BusquedaDePacientes::normalizar(trim($buscar));

        return Diagnostico::where('reg_medico', $regMedico)
            ->orderBy('descripcion')
            ->orderBy('codediagnostico')
            ->get()
            ->filter(function (Diagnostico $diagnostico) use ($clave) {
                return $clave === ''
                    || str_contains(BusquedaDePacientes::normalizar((string) $diagnostico->descripcion), $clave);
            })
            ->groupBy(fn (Diagnostico $diagnostico) => BusquedaDePacientes::normalizar((string) $diagnostico->descripcion))
            ->map(fn (Collection $iguales) => $iguales->sortBy('codediagnostico')->first())
            ->sortBy(fn (Diagnostico $diagnostico) => BusquedaDePacientes::normalizar((string) $diagnostico->descripcion))
            ->values();
    }

    /**
     * ¿Ese texto **ya es** un diagnóstico del catálogo? Es lo que decide si el buscador ofrece crearlo: con
     * "DOLOR" escrito y un catálogo que tiene "DOLOR PELVICO" **sí** se ofrece crear (es otro diagnóstico),
     * y con "dolor pelvico" no.
     */
    public function existeEnElCatalogo(string $regMedico, string $descripcion): bool
    {
        $clave = BusquedaDePacientes::normalizar(trim($descripcion));

        if ($clave === '') {
            return false;
        }

        return Diagnostico::where('reg_medico', $regMedico)
            ->get(['codediagnostico', 'descripcion'])
            ->contains(fn (Diagnostico $diagnostico) => BusquedaDePacientes::normalizar((string) $diagnostico->descripcion) === $clave);
    }

    /**
     * El diagnóstico del catálogo por su código, o `null` si el código no está en el catálogo del médico.
     */
    public function delCatalogo(string $regMedico, ?string $codigo): ?Diagnostico
    {
        $codigo = CamposDePaciente::texto($codigo);

        if ($codigo === null) {
            return null;
        }

        return Diagnostico::where('reg_medico', $regMedico)
            ->where('codediagnostico', $codigo)
            ->first();
    }

    /**
     * Los diagnósticos que **esa consulta** ya tiene, en el orden del escritorio (`orden`, y el id para que
     * dos filas con el mismo `orden` —el escritorio lo deja nulo— no cambien de lugar entre corridas), con
     * su nombre ya resuelto.
     *
     * @return Collection<int,object{id:int, codigo:string, nombre:string, detalle:?string, enCatalogo:bool}>
     */
    public function deLaConsulta(string $regMedico, ?string $numhistoria, int $nroconsulta): Collection
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return collect();
        }

        $catalogo = $this->catalogoPorCodigo($regMedico);

        return DiagnosticoPaciente::where('reg_medico', $regMedico)
            ->where('nrohistoria', $numero)
            ->where('nroconsulta', $nroconsulta)
            ->orderBy('orden')
            ->orderBy('id')
            ->get(['id', 'codediagnostico', 'detalle_diagnostco'])
            ->map(function (DiagnosticoPaciente $fila) use ($catalogo) {
                $codigo = CamposDePaciente::texto($fila->codediagnostico);
                $delCatalogo = $codigo === null ? null : ($catalogo[trim($codigo)] ?? null);
                // El detalle se limpia igual que el resto de los textos del legado: el `NULL`, el `''` y
                // el literal `none` que el DataWindow escribió son el mismo estado ("sin dato") y se
                // muestran como una raya, no como el texto `none` (misma vara que los antecedentes).
                $detalle = $this->sinDato($fila->detalle_diagnostco);

                return (object) [
                    'id' => (int) $fila->id,
                    'codigo' => (string) ($codigo ?? ''),
                    'nombre' => CamposDePaciente::texto($delCatalogo->descripcion ?? null) ?? $detalle ?? (string) $codigo,
                    'detalle' => $detalle,
                    'enCatalogo' => $delCatalogo !== null,
                ];
            })
            ->values();
    }

    /**
     * El catálogo del médico indexado por **código recortado**, para resolver el nombre de las filas de la
     * consulta sin volver a golpear la base por cada una. Si el legado repite un código, gana el de
     * descripción no vacía (el catálogo del dump trae filas repetidas).
     *
     * @return array<string,Diagnostico>
     */
    private function catalogoPorCodigo(string $regMedico): array
    {
        $catalogo = [];

        foreach (Diagnostico::where('reg_medico', $regMedico)->get(['codediagnostico', 'descripcion']) as $diagnostico) {
            $codigo = CamposDePaciente::texto($diagnostico->codediagnostico);

            if ($codigo !== null && CamposDePaciente::texto($diagnostico->descripcion) !== null) {
                $catalogo[$codigo] ??= $diagnostico;
            }
        }

        return $catalogo;
    }

    /**
     * El `NULL`, el `''` y el literal `none` del DataWindow son el mismo estado: sin dato. El `none` no es
     * una invención —es el marcador que el escritorio escribió en miles de filas de otras tablas y
     * `AntecedentesDelPaciente` y `EvaluacionFisica` lo tratan igual—, así que no se muestra como si fuera
     * un detalle escrito.
     */
    private function sinDato($valor): ?string
    {
        $texto = CamposDePaciente::texto($valor);

        return $texto === null || strcasecmp($texto, 'none') === 0 ? null : $texto;
    }
}

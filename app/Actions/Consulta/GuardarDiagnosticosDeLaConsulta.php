<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\DiagnosticosDeLaConsulta;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Guardar los diagnósticos de una consulta** (WEB-4.5, ítem 8 del panel del legado).
 *
 * ## De qué cuelga la fila, y por qué la Action vive en `Actions\Consulta`
 *
 * Un diagnóstico de la consulta es una fila de **`diagnostico_paciente`** y cuelga de la consulta por
 * **`nrohistoria` + `nroconsulta` + `codediagnostico`**: ésas son sus columnas junto con
 * `detalle_diagnostco` y `orden`, y así las declara el DataWindow de la pestaña *Diagnóstico* del
 * escritorio (`d_diagnostico_consulta_1.srd`: `update="diagnostico_paciente"`,
 * `updatewhereclause=yes` en las tres primeras, `sort="orden A"`). La ventana la lee con
 * `dw_8.retrieve(historia, consulta)` (`w_consulta_principal.srw:3007-3010`), la **inserta** con
 * `INSERT INTO "diagnostico_paciente" (nrohistoria, nroconsulta, codediagnostico, detalle_diagnostco)`
 * (`:11860-11868`) y la **borra** por esos tres campos (`:11785-11789`). Por eso la Action es de
 * `Actions\Consulta` —como `AgregarMotivoALaConsulta`— y no de `Actions\Pacientes`: a diferencia de los
 * antecedentes (WEB-4.4), que se llavean sólo por `numhistoria`, acá la fila **sí es de la consulta**.
 *
 * ## Las tres operaciones, y lo que copia del escritorio
 *
 *  1. **Agregar** un diagnóstico del catálogo: `INSERT` con el detalle **copiado de la descripción del
 *     catálogo**, que es exactamente lo que hace el `INSERT` del escritorio (`:11860-11868`, que manda
 *     `descripcion` como `detalle_diagnostco`) — y no es un detalle cosmético: el DataWindow de la pestaña
 *     **no cruza** con el catálogo (su `retrieve` es sólo de `diagnostico_paciente`), así que la fila que
 *     la web cree sin detalle se vería **vacía** en el escritorio. `orden` queda en `null`, como lo deja la
 *     fuente (el DataWindow lo tiene oculto y ningún `INSERT` de la ventana lo escribe).
 *  2. **Escribir el detalle** de una fila que ya estaba: es la **única** columna editable de la tabla (el
 *     DataWindow la deja con `edit.limit=100` y el `ue_guardar` de la ventana manda `dw_8.update()`,
 *     `:2709`). Se escribe **sólo si cambió**.
 *  3. **Quitar** una fila de la consulta: el `DELETE` del escritorio (`:11785-11789`), que **no toca el
 *     catálogo** (wiki `04-historia-clinica.md`: eliminar un ítem de una consulta sólo lo desasocia).
 *
 * **Agregar dos veces el mismo diagnóstico a la misma consulta no lo duplica**: devuelve/omite la fila que
 * ya estaba. El `INSERT` del escritorio no comprueba nada —su botón *Pasar diag.* puede dejar repetidos—,
 * pero la web no agrega filas que la pantalla ya muestra (misma regla que `AgregarMotivoALaConsulta`).
 *
 * ## El rastro del sync
 *
 * `diagnostico_paciente` es una tabla legada de la lista blanca del escritorio (`config/sync_legado.php`,
 * y además en `borrados_al_app`: cuando el escritorio borra una fila, el API lo anota para que el teléfono
 * también la borre). Se escribe acá **sólo lo que cambió**, con el rastro por columna en `sync_changes`
 * (`updated` de `detalle_diagnostco` en una fila que ya existía, `created` de la nueva y `deleted` de la
 * baja) con `source = web`, y con el **last-write-wins por columna** del sync
 * (`RegistroDeCambios::permite`): sin el rastro, una edición vieja del escritorio llegaría más tarde, no
 * tendría con qué compararse y pisaría el cambio nuevo.
 *
 * ⚠️ **El escritorio sube esta tabla por el camino genérico del sync, que no pasa por acá**
 * (`CambiosEscritorio::guardarGenerico`/`borrarGenerico`: es table-agnóstico y hace un `UPDATE` con todas
 * las columnas que manda el lote, así que puede corregir un `detalle_diagnostco` de una fila existente —
 * algo que esta Action no hace: acá el detalle de una fila que ya está se edita a mano en la pantalla, y el
 * `INSERT` de una repetida no la duplica). Por eso el sync **no** se reescribe para que use esta Action:
 * hacerlo cambiaría su comportamiento, que es justamente lo que no se puede tocar (R3). El móvil, por su
 * lado, sólo **baja** `diagnostico_paciente` (`SyncAppDataController`), no la escribe.
 */
final class GuardarDiagnosticosDeLaConsulta
{
    /** El largo del `detalle_diagnostco` que el formulario acepta (la columna es `varchar(100)`). */
    public const LARGO_DETALLE = 100;

    /** Las columnas de la fila por las que el sync decide si la baja/edición se puede aplicar. */
    private const COLUMNAS = ['codediagnostico', 'detalle_diagnostco'];

    public function __construct(private DiagnosticosDeLaConsulta $lectura)
    {
    }

    /**
     * @param  Consulta  $consulta  la consulta ya resuelta contra el contexto (de ahí salen `reg_medico`,
     *                              `numhistoria` y `nroconsulta`: la clave de la fila)
     * @param  array<int|string,array{detalle?:?string}>  $filas  las filas que la pantalla mostró, por
     *                                                            **id de `diagnostico_paciente`**
     * @param  array<int,string>  $agregar  los `codediagnostico` del catálogo a agregar
     * @param  array<int,int|string>  $quitar  los **id** de las filas a quitar
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     */
    public function ejecutar(
        Consulta $consulta,
        array $filas = [],
        array $agregar = [],
        array $quitar = [],
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): ResultadoDeDiagnosticos {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $regMedico = (string) $consulta->reg_medico;

        $deLaConsulta = $this->lectura->deLaConsulta($regMedico, (string) $consulta->numhistoria, (int) $consulta->nroconsulta);
        $porId = $deLaConsulta->keyBy('id');

        $bloqueados = [];
        $guardados = [];
        $sinCambios = [];
        $agregados = [];
        $quitados = [];

        // Las altas que el formulario pidió, decantadas antes de escribir: un código que la consulta **ya
        // tiene** no se agrega (la tabla no tiene unicidad por `(reg_medico, nrohistoria, nroconsulta,
        // codediagnostico)`, así que la regla la pone el código).
        $agregables = [];
        foreach ($this->codigos($agregar) as $codigo) {
            if (! in_array($codigo, $deLaConsulta->pluck('codigo')->all(), true)) {
                $agregables[] = $codigo;
            }
        }

        $quitables = $this->ids($quitar);

        DB::transaction(function () use (
            $consulta, $regMedico, $filas, $cuando, $origen,
            $porId, $agregables, $quitables, &$bloqueados, &$guardados, &$sinCambios, &$agregados, &$quitados
        ) {
            // Las filas que se quitaron en esta corrida: lo que ya no está no se puede editar después.
            $quitadosAhora = [];

            // 1. Quitar: la relación de la consulta, jamás el catálogo. Va primero y la fila que se va
            //    queda anotada para que la edición no la toque en la misma corrida.
            foreach ($quitables as $id) {
                $fila = $porId[$id] ?? null;

                if ($fila === null) {
                    continue;
                }

                foreach (self::COLUMNAS as $columna) {
                    if (! $this->permite('diagnostico_paciente', $id, $columna, $cuando)) {
                        $bloqueados[] = $fila->nombre;
                        continue 2;
                    }
                }

                DB::table('diagnostico_paciente')->where('id', $id)->delete();
                RegistroDeCambios::registrarOperacion('diagnostico_paciente', $id, $regMedico, 'deleted', null, null, $cuando, $origen);
                $quitados[] = $fila->nombre;
                $quitadosAhora[$id] = true;
            }

            // 2. Editar el detalle de lo que ya estaba: la única columna editable de la tabla.
            foreach ($filas as $id => $valores) {
                $id = (int) $id;
                $fila = $porId[$id] ?? null;

                // Una fila que no es de esta consulta —o que se acaba de quitar en esta misma corrida— se
                // ignora: el formulario no puede escribir sobre algo que la pantalla ya no muestra.
                if ($fila === null || ! is_array($valores) || isset($quitadosAhora[$id])) {
                    continue;
                }

                $detalle = $this->limpiarDetalle($valores['detalle'] ?? null);

                if ($detalle === $fila->detalle) {
                    $sinCambios[] = $fila->nombre;
                    continue;
                }

                if (! $this->permite('diagnostico_paciente', $id, 'detalle_diagnostco', $cuando)) {
                    $bloqueados[] = $fila->nombre;
                    continue;
                }

                DB::table('diagnostico_paciente')->where('id', $id)->update([
                    'detalle_diagnostco' => $detalle,
                    'updated_at' => now(),
                ]);
                RegistroDeCambios::registrar('diagnostico_paciente', $id, $regMedico, 'detalle_diagnostco', $detalle, $cuando, $origen);
                $guardados[] = $fila->nombre;
            }

            // 3. Agregar del catálogo lo que el formulario mandó como nuevo.
            foreach ($agregables as $codigo) {
                $delCatalogo = $this->lectura->delCatalogo($regMedico, $codigo);

                if ($delCatalogo === null) {
                    // Un código que no está en el catálogo del médico no se agrega: la pantalla no lo
                    // ofrece y aceptarlo dejaría una fila sin nombre.
                    $bloqueados[] = $codigo;
                    continue;
                }

                $nombre = trim((string) $delCatalogo->descripcion);

                $nueva = DB::table('diagnostico_paciente')->insertGetId([
                    'reg_medico' => $regMedico,
                    'nrohistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                    'codediagnostico' => trim((string) $delCatalogo->codediagnostico),
                    // La copia del nombre que hace el `INSERT` del escritorio (`:11860-11868`): el
                    // DataWindow del escritorio muestra esta columna, no el catálogo.
                    'detalle_diagnostco' => $nombre,
                    // El escritorio nunca escribe `orden` (el DataWindow lo tiene oculto y ningún INSERT
                    // de la ventana lo manda): la fila queda como quedaría si la agregara él.
                    'orden' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                RegistroDeCambios::registrarOperacion('diagnostico_paciente', $nueva, $regMedico, 'created', null, null, $cuando, $origen);
                $agregados[] = $nombre;
            }
        });

        return new ResultadoDeDiagnosticos(
            guardados: $guardados,
            agregados: $agregados,
            quitados: $quitados,
            bloqueados: array_values(array_unique($bloqueados)),
            sinCambios: $sinCambios,
        );
    }

    /**
     * Los códigos del formulario, normalizados y sin repetidos: el mismo no puede entrar dos veces en la
     * misma corrida.
     *
     * @param  array<int,string>  $codigos
     * @return array<int,string>
     */
    private function codigos(array $codigos): array
    {
        $limpios = [];

        foreach ($codigos as $codigo) {
            $codigo = trim((string) $codigo);

            if ($codigo !== '') {
                $limpios[$codigo] = true;
            }
        }

        return array_keys($limpios);
    }

    /**
     * Los ids del formulario, sin repetidos y sin basura: el id de una fila de otra consulta se ignora
     * después (no está en `$porId`), pero no se acepta cualquier cosa como id.
     *
     * @param  array<int,int|string>  $ids
     * @return array<int,int>
     */
    private function ids(array $ids): array
    {
        $limpios = [];

        foreach ($ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $limpios[(int) $id] = true;
            }
        }

        return array_keys($limpios);
    }

    /** El `""` del formulario y el `null` de la columna son el mismo estado: sin detalle. */
    private function limpiarDetalle($valor): ?string
    {
        $texto = $valor === null ? null : trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function permite(string $tabla, int $registro, string $columna, CarbonInterface $cuando): bool
    {
        return RegistroDeCambios::permite($tabla, $registro, $columna, Carbon::instance($cuando));
    }
}

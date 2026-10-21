<?php

namespace App\Actions\Pacientes;

use App\Clinica\Consulta\AntecedentesDelPaciente;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Guardar los antecedentes de un paciente** (WEB-4.4, ítem 3 del panel del legado).
 *
 * ## Por qué vive en `Actions\Pacientes` y no en `Actions\Consulta`
 *
 * Porque **los antecedentes son del paciente y no de la consulta**, y eso no es una preferencia de
 * organización: la fuente lo dice. `antece_paciente` se llavea por `numhistoria` —no tiene columna de
 * consulta— y la pestaña de antecedentes del escritorio trabaja con el `historia` de la ventana: el
 * `Retrieve(historia)` del catálogo (`w_consulta_principal.srw:7416-7418`), el `DELETE` de destildar
 * (`:7636-7638`) y el `INSERT` de agregar (`:7754-7764`) **no mencionan la consulta**, y la alerta del
 * encabezado se lee sólo por historia (`:3072-3081`). Es la misma clase de dato que el representante
 * (WEB-3.4), que también es del paciente y está en `Actions\Pacientes`.
 *
 * La sección **se usa desde la consulta** (es el ítem 3 de su panel) pero lo que escribe se ve en la
 * historia clínica del paciente (WEB-3.5) y en su próxima consulta: por eso la pantalla lo dice y por eso
 * la Action no recibe una consulta. Recibe la **historia** (`reg_medico` + `numhistoria`), que es la clave
 * real de la tabla.
 *
 * ## El rastro del sync
 *
 * `antece_paciente` es una tabla legada de la lista blanca del escritorio (`config/sync_legado.php`) que
 * la carga inicial y la restauración suben y bajan, así que cada columna escrita deja su rastro en
 * `sync_changes` (`updated` para las columnas de una fila que ya existía, `created` para la fila nueva,
 * `deleted` al quitarla) con `source = web`, como `GuardarEnfermedadActual` y `GuardarEvaluacionFisica`:
 * sin el rastro, una edición vieja del escritorio no tendría con qué compararse y pisaría el cambio.
 *
 * El guard es el **last-write-wins por columna** del sync (`RegistroDeCambios::permite`). El escritorio
 * **no** escribe `sync_changes` (sus cambios llegan por `/api/sync/cambios/subir`), así que este rastro
 * sirve para lo que sirve en todas las tablas: que la web y el móvil no se pisen entre sí y que la
 * modificación quede auditada.
 *
 * ⚠️ **El escritorio sube esta tabla por el camino genérico, que no pasa por acá** —lo mismo que la
 * evaluación física—: esta Action es *la* regla de la web, no un reemplazo del sync.
 *
 * ## Las tres operaciones del escritorio, y el `tipo2` de la casilla
 *
 * La sección hace exactamente lo que la fuente permite, ni más ni menos:
 *
 *  1. **Agregar** un antecedente del catálogo: `INSERT` con `numhistoria`, `codeantecedente`, `detalles`
 *     vacío, `descripcion` y `tipo` (`:7754-7764`). `tipo` es el `codtipo` del catálogo, que es lo que el
 *     escritorio copia —por eso **la pertenencia al tipo queda en la fila** y el paciente no cambia de
 *     grupo si el catálogo se edita después—.
 *  2. **Escribir el detalle** de un antecedente ya cargado: el DataWindow sólo actualiza `detalles` y
 *     `tipo2` (en `d_antece_paciente_personal.srd` las demás columnas tienen `updatewhereclause=yes` sin
 *     `update=yes`), así que la Action **no toca** `descripcion` ni `tipo` de una fila existente.
 *  3. **Marcar la alerta**: `tipo2 = 'A'` encendida y `'B'` apagada, que es lo que declara la casilla del
 *     DataWindow (`d_antecedentes_consulta_bueno.srd`: `checkbox.on="A" checkbox.off="B"`). Al destildar
 *     la web escribe `null` en vez de `B` —las filas del botón *Pasar* del escritorio quedan así y `null`
 *     ya significa "sin marcar"—, y **una fila con `null` que no se toca no se ensucia**: sólo se escribe
 *     si la casilla cambió de estado respecto de lo que la pantalla mostró.
 *
 * Quitar un antecedente es el `DELETE` del escritorio (`:7636-7638`): se lleva **sólo la relación** del
 * paciente, nunca el catálogo.
 */
final class GuardarAntecedentes
{
    /** El largo del `detalles` que el formulario acepta (la columna del legado es un `text` largo). */
    public const LARGO_DETALLE = 4000;

    public function __construct(private AntecedentesDelPaciente $lectura)
    {
    }

    /**
     * @param  array<int|string,array{detalle?:?string,alerta?:bool|string|null}>  $filas
     *         las filas que la pantalla mostró, por **id de `antece_paciente`**
     * @param  array<int,string>  $agregar  los `codeantecedente` del catálogo a agregar
     * @param  array<int,string>  $quitar  los `codeantecedente` a quitar
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     * @param  int|null  $consultaId  el `consultas.id` desde la que se escribe, para el registro de
     *                                movimientos (`antecedente_movimientos`, 2026-10-20)
     */
    public function ejecutar(
        string $regMedico,
        ?string $numhistoria,
        array $filas = [],
        array $agregar = [],
        array $quitar = [],
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
        ?int $consultaId = null,
    ): ResultadoDeAntecedentes {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $numero = (int) $numhistoria;

        $delPaciente = $this->lectura->delPaciente($regMedico, $numhistoria);
        $porId = $delPaciente->keyBy('id');
        $porCodigo = $delPaciente->keyBy('codigo');

        $bloqueados = [];
        $guardados = [];
        $sinCambios = [];
        $agregados = [];
        $quitados = [];

        // Las altas y las bajas que el formulario pidió, ya decantadas antes de escribir: un código que el
        // paciente **ya tiene** en pantalla no se agrega (la tabla no tiene unicidad por `(reg_medico,
        // numhistoria, codeantecedente)`, así que la regla la pone el código) y un código que se está
        // quitando no se vuelve a agregar en la misma corrida.
        $catalogo = $this->catalogoPorCodigo($regMedico, $agregar);
        $agregables = [];
        foreach ($this->codigos($agregar) as $codigo) {
            if (! in_array($codigo, $delPaciente->pluck('codigo')->all(), true)) {
                $agregables[] = $codigo;
            }
        }
        $quitables = $this->codigos($quitar);

        DB::transaction(function () use (
            $regMedico, $numero, $filas, $cuando, $origen, $consultaId,
            $porId, $porCodigo, $catalogo, $agregables, $quitables, &$bloqueados, &$guardados, &$sinCambios, &$agregados, &$quitados
        ) {
            // Los códigos que se quitaron en esta corrida: lo que ya no está no se puede editar después.
            $quitadosAhora = [];

            // 1. Quitar: la relación del paciente, jamás el catálogo. Se hace primero y el código que se
            //    va queda anotado para que la vuelta 3 no lo reinsertte en la misma corrida.
            foreach ($quitables as $codigo) {
                $fila = $porCodigo[$codigo] ?? null;

                if ($fila === null) {
                    continue;
                }

                if (! $this->permite('antece_paciente', $fila->id, 'codeantecedente', $cuando)
                    || ! $this->permite('antece_paciente', $fila->id, 'detalles', $cuando)
                    || ! $this->permite('antece_paciente', $fila->id, 'tipo2', $cuando)) {
                    $bloqueados[] = $fila->nombre;
                    continue;
                }

                // El registro de movimientos guarda lo que **tenía** al quitarse: se lee antes del DELETE.
                $cruda = $this->cruda($fila->id, $fila->nombre);

                DB::table('antece_paciente')->where('id', $fila->id)->delete();
                RegistroDeCambios::registrarOperacion('antece_paciente', $fila->id, $regMedico, 'deleted', null, null, $cuando, $origen);
                if ($cruda !== null) {
                    RegistrarMovimientoDeAntecedente::quitado($cruda, $origen, $consultaId, $cuando);
                }
                $quitados[] = $fila->nombre;
                $quitadosAhora[$codigo] = true;
            }

            // 2. Editar el detalle y la casilla de alerta de lo que ya estaba.
            foreach ($filas as $id => $valores) {
                $fila = $porId[(int) $id] ?? null;

                // Una fila que no es de este paciente —o que se acaba de quitar en esta misma corrida— se
                // ignora: el formulario no puede escribir sobre algo que la pantalla ya no muestra.
                if ($fila === null || ! is_array($valores) || isset($quitadosAhora[$fila->codigo])) {
                    continue;
                }

                $detalle = $this->limpiarDetalle($valores['detalle'] ?? null);
                $alerta = $this->esAlerta($valores['alerta'] ?? null);

                $paraEscribir = [];

                if ($detalle !== $fila->detalle) {
                    $paraEscribir['detalles'] = $detalle;
                }

                $tipo2 = $alerta ? AntecedentesDelPaciente::TIPO2_ALERTA : null;
                if (($fila->tipo2 === AntecedentesDelPaciente::TIPO2_ALERTA) !== $alerta) {
                    $paraEscribir['tipo2'] = $tipo2;
                }

                if ($paraEscribir === []) {
                    $sinCambios[] = $fila->nombre;
                    continue;
                }

                foreach (array_keys($paraEscribir) as $columna) {
                    if (! $this->permite('antece_paciente', $fila->id, $columna, $cuando)) {
                        $bloqueados[] = $fila->nombre;
                        continue 2;
                    }
                }

                $antes = $this->cruda($fila->id, $fila->nombre);
                DB::table('antece_paciente')->where('id', $fila->id)->update($paraEscribir + ['updated_at' => now()]);
                foreach ($paraEscribir as $columna => $valor) {
                    RegistroDeCambios::registrar('antece_paciente', $fila->id, $regMedico, $columna, $valor, $cuando, $origen);
                }
                if ($antes !== null) {
                    RegistrarMovimientoDeAntecedente::editado($antes, $this->cruda($fila->id, $fila->nombre), $origen, $consultaId, $cuando);
                }
                $guardados[] = $fila->nombre;
            }

            // 3. Agregar del catálogo lo que el formulario mandó como nuevo.
            foreach ($agregables as $codigo) {
                $delCatalogo = $catalogo[$codigo] ?? null;

                if ($delCatalogo === null) {
                    // Un código que no está en el catálogo del médico no se agrega: la pantalla no lo
                    // ofrece, y aceptarlo dejaría una fila huérfana sin nombre ni tipo.
                    $bloqueados[] = $codigo;
                    continue;
                }

                $nueva = DB::table('antece_paciente')->insertGetId([
                    'reg_medico' => $regMedico,
                    'numhistoria' => $numero,
                    'codeantecedente' => $delCatalogo->codigo,
                    'detalles' => '',
                    // La copia del nombre y del tipo que hace el `INSERT` del escritorio (`:7754-7764`).
                    'descripcion' => $delCatalogo->nombre,
                    'tipo' => $delCatalogo->tipoCodigo,
                    'tipo2' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                RegistroDeCambios::registrarOperacion('antece_paciente', $nueva, $regMedico, 'created', null, null, $cuando, $origen);
                RegistrarMovimientoDeAntecedente::agregado($this->cruda($nueva, $delCatalogo->nombre), $origen, $consultaId, $cuando);
                $agregados[] = $delCatalogo->nombre;
            }
        });

        return new ResultadoDeAntecedentes(
            guardados: $guardados,
            agregados: $agregados,
            quitados: $quitados,
            bloqueados: array_values(array_unique($bloqueados)),
            sinCambios: $sinCambios,
        );
    }

    /**
     * Los códigos del formulario, normalizados y sin repetidos: el mismo código no puede entrar dos veces
     * en la misma corrida (ni en `agregar` ni en `quitar`).
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
     * El catálogo del médico, indexado por código, pero **sólo** de los códigos que se van a agregar: la
     * sección no necesita el catálogo entero para escribir (la lectura del buscador es otra).
     *
     * @param  array<int,string>  $agregar
     * @return array<string,object>
     */
    private function catalogoPorCodigo(string $regMedico, array $agregar): array
    {
        $catalogo = [];

        foreach ($this->codigos($agregar) as $codigo) {
            $delCatalogo = $this->lectura->delCatalogo($regMedico, $codigo);

            if ($delCatalogo !== null) {
                $catalogo[$codigo] = $delCatalogo;
            }
        }

        return $catalogo;
    }

    /**
     * La fila cruda de `antece_paciente` para el registro de movimientos, con el nombre resuelto si la copia
     * del legado viene vacía (filas del botón *Pasar* del escritorio).
     *
     * @return array<string,mixed>|null
     */
    private function cruda(int $id, ?string $nombre): ?array
    {
        $fila = DB::table('antece_paciente')->where('id', $id)->first();

        if ($fila === null) {
            return null;
        }

        $fila = (array) $fila;
        if (trim((string) ($fila['descripcion'] ?? '')) === '') {
            $fila['descripcion'] = $nombre;
        }

        return $fila;
    }

    /** El `""` del formulario y el `null` de la columna son el mismo estado: sin detalle. */
    private function limpiarDetalle($valor): ?string
    {
        $texto = $valor === null ? null : trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * La casilla de alerta. El control manda `A` encendida y `B` apagada (el `values` del DataWindow,
     * `d_antecedentes_consulta_bueno.srd`); cualquier otra cosa es apagada.
     */
    private function esAlerta($valor): bool
    {
        return $valor === true || $valor === 'A' || $valor === 'a' || $valor === '1' || $valor === 1;
    }

    private function permite(string $tabla, int $registro, string $columna, CarbonInterface $cuando): bool
    {
        return RegistroDeCambios::permite($tabla, $registro, $columna, Carbon::instance($cuando));
    }
}

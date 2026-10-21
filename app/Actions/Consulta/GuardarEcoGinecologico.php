<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\FechaDelConsultorio;
use App\Models\Consulta;
use App\Models\EcoPelvico;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar el eco pélvico de una consulta** (WEB-6.1, ítem 5 del panel del legado).
 *
 * **Uno por consulta**: `eco_pelvico` se llavea por `(numhistoria, nroconsulta)`
 * (`d_eco_pelvico.srd:6`, `:19`) y el escritorio lo corrige en el lugar con `dw_1.Update()`
 * (`w_cargar_visualizar_ecografia.srw:539`). Si la consulta ya tiene fila se corrige **columna por
 * columna y sólo lo que cambió**; si no, se crea con la clave del DataWindow y la fecha del estudio.
 *
 * **No se crean ecos vacíos.** El escritorio crea la fila en memoria al abrir la pantalla
 * (`:221-224`) y la guarda con lo que haya, pero la base ya tiene bastante de eso: acá un estudio
 * **sin ningún dato** (la fecha sola no cuenta) no es un documento
 * (`QueEsUnDocumento::ecografia`) y se rechaza, en vez de dejar una fila vacía más.
 *
 * **Qué escribe y qué no.** Sólo las columnas de `EcoGinecologico::columnas()`: las cinco que la
 * pantalla del escritorio no toca (`puro_texto`, `centrar`, `cuello`, `anterocuello`, `formautero`)
 * quedan como estén.
 *
 * Deja su rastro en `sync_changes` (`source = web`): `created` si crea, y `updated` **por columna** con
 * el last-write-wins del sync, que es lo que impide pisar una edición más nueva del escritorio o del
 * teléfono. `eco_pelvico` tiene clave primaria (`id`) y está en la lista blanca de `config/sync_legado.php`
 * **fuera** de `sin_clave_con_altas_del_app`: el escritorio la sube **fila por fila**, así que no necesita
 * ninguna protección especial (a diferencia de `recipes_pareja` o `motivo_cita`).
 */
final class GuardarEcoGinecologico
{
    use EscribeConRastro;

    public function __construct(private EcoGinecologico $estudios)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  las columnas del formulario (las de `EcoGinecologico::columnas()`)
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si el estudio no trae ningún dato
     */
    public function ejecutar(
        Consulta $consulta,
        array $valores,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        $nuevos = [];
        foreach (array_keys(EcoGinecologico::columnas()) as $columna) {
            $nuevos[$columna] = $this->normalizar($valores[$columna] ?? null);
        }

        // La fecha del estudio es `NOT NULL`: si el formulario la deja vacía vale la **de hoy**, que es la
        // que propone el escritorio al crear el estudio (`w_cargar_visualizar_ecografia.srw:223`). No se
        // reusa la de la fila: si el médico borra el campo, la fecha vuelve a ser la del día del estudio.
        $nuevos[EcoGinecologico::COLUMNA_FECHA] ??= FechaDelConsultorio::hoyComoFecha()->toDateString();

        if (! QueEsUnDocumento::ecografia($this->conDatos($nuevos))) {
            throw new InvalidArgumentException('El eco necesita algún dato: carga lo que haya dado el estudio.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $nuevos, $cuando, $origen) {
            $fila = $this->estudios->filaDe($consulta);

            if ($fila === null) {
                // La fila nueva la arma el mapa del dato, que es donde vive la clave del DataWindow
                // (`numhistoria` + `nroconsulta`), para no repetirla acá.
                $nueva = $this->estudios->filaParaEscribir($consulta);

                $this->crearFila(EcoPelvico::class, EcoGinecologico::TABLA, array_merge($nuevos, [
                    'reg_medico' => (string) $nueva->reg_medico,
                    EcoGinecologico::COLUMNA_HISTORIA => (int) $nueva->numhistoria,
                    EcoGinecologico::COLUMNA_CONSULTA => (int) $nueva->nroconsulta,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, EcoGinecologico::TABLA, $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    /**
     * Cuántas columnas traen dato, **sin contar la fecha**: es lo que decide si hay un documento que
     * guardar. La cuenta la usa también la pantalla para su pastilla ("12 de 35 campos con dato") y el
     * `QueEsUnDocumento`, así que las tres miran lo mismo.
     *
     * @param  array<string,?string>  $valores
     */
    private function conDatos(array $valores): int
    {
        $conDato = 0;
        foreach ($valores as $columna => $valor) {
            if ($columna !== EcoGinecologico::COLUMNA_FECHA && $valor !== null) {
                $conDato++;
            }
        }

        return $conDato;
    }

    /**
     * El valor que se guarda: el `''` del formulario, el `null` de la columna y el literal `none` del
     * legado son el mismo "sin dato" (`EstudioEcografico::limpiar`).
     */
    private function normalizar($valor): ?string
    {
        return EstudioEcografico::limpiar($valor);
    }
}

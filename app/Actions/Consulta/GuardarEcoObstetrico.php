<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Embarazo\GestasDeLaHistoria;
use App\Clinica\FechaDelConsultorio;
use App\Models\Consulta;
use App\Models\EcoObstetrico as EcoObstetricoModelo;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar el eco obstétrico de una consulta** (WEB-6.2, el ítem 5 del panel).
 *
 * Es el hermano de `GuardarEcoGinecologico` (el eco pélvico, WEB-6.1) y sigue exactamente su molde:
 *
 *  - **Una fila por consulta**, con la clave del DataWindow (`numhistoria` + `nroconsulta`).
 *  - **Sólo lo que cambió**, columna por columna, con el rastro en `sync_changes` (`source = web`) y el
 *    last-write-wins del sync — el trait `EscribeConRastro`—.
 *  - **Un eco sin datos no es un documento** (`QueEsUnDocumento::ecografia`): si el estudio no trae nada más
 *    que su fecha, no se crea la fila (la hoja no se ofrece y la pantalla lo dice).
 *
 * Lo que **este** estudio agrega es su atadura al embarazo, que el escritorio resuelve al abrir la pantalla:
 * la **FUR** y el **número de gesta** se completan con los del **control prenatal** de esa historia
 * (`FurDelControlPrenatal`, `GestasDeLaHistoria`) cuando el formulario no los trae. Así el eco queda colgado
 * de la gesta que el médico está siguiendo, que es lo que el escritorio hace —y lo que le permite a la hoja
 * imprimir la EG y la FPP de ese embarazo—.
 *
 * Las columnas que la pantalla edita son las de `EcoObstetrico::columnas()` (el mapa del dato): un campo
 * nuevo del mapa entra acá sin tocar nada más.
 */
final class GuardarEcoObstetrico
{
    use EscribeConRastro;

    /** La tabla del eco obstétrico del primer trimestre. */
    public const TABLA = EcoObstetrico::TABLA;

    /**
     * Las columnas que se completan solas del embarazo cuando el formulario no las trae: no cuentan para
     * decidir si el estudio "tiene datos" (si no, un eco vacío con su FUR copiada sería un documento).
     *
     * @var array<int,string>
     */
    private const DEL_EMBARAZO = ['fecha', 'fur', 'gesta'];

    public function __construct(
        private EcoObstetrico $ecos,
        private \App\Clinica\Consulta\FurDelControlPrenatal $furPrenatal,
        private GestasDeLaHistoria $gestas,
    ) {
    }

    /**
     * @param  array<string,mixed>  $valores  las columnas del formulario (las del mapa)
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
        foreach (EcoObstetrico::columnas() as $columna) {
            $nuevos[$columna] = EstudioEcografico::limpiar($valores[$columna] ?? null);
        }

        // Lo que la pantalla no manda y el escritorio completa solo.
        $nuevos['fecha'] ??= FechaDelConsultorio::hoyComoFecha()->toDateString();
        $nuevos['fur'] ??= $this->furPrenatal->de((string) $consulta->reg_medico, (int) $consulta->numhistoria);
        $nuevos['gesta'] ??= $this->gestas->actual((string) $consulta->reg_medico, (int) $consulta->numhistoria)?->clave();

        // ¿El estudio dice algo? La fecha, la FUR y la gesta no cuentan: las completa el servidor.
        $delEstudio = array_diff_key($nuevos, array_flip(self::DEL_EMBARAZO));
        $conDatos = count(array_filter($delEstudio, fn ($valor) => $valor !== null && $valor !== ''));

        if (! QueEsUnDocumento::ecografia($conDatos)) {
            throw new InvalidArgumentException('El eco necesita algún dato: cargá lo que haya dado el estudio.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $nuevos, $cuando, $origen) {
            $fila = $this->ecos->filaDe($consulta);

            if ($fila === null) {
                $nueva = $this->ecos->filaParaEscribir($consulta);

                $this->crearFila(EcoObstetricoModelo::class, self::TABLA, array_merge($nuevos, [
                    'reg_medico' => (string) $nueva->reg_medico,
                    EcoObstetrico::COLUMNA_HISTORIA => (int) $nueva->historia,
                    EcoObstetrico::COLUMNA_CONSULTA => (int) $nueva->consulta,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila(
                EcoObstetricoModelo::find($fila->id), self::TABLA, $nuevos, $cuando, $origen,
            );

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }
}

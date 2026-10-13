<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\FechaDelConsultorio;
use App\Models\Consulta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **La regla de guardado de un eco obstétrico** (WEB-6.2), compartida por sus dos estudios: el del **primer**
 * trimestre (`eco_obstetrico`) y el del **segundo/tercero** (`eco_obstetrico_tercer`).
 *
 * Los dos tienen la misma forma —**una fila por consulta**, con la clave del DataWindow `numhistoria` +
 * `nroconsulta`, sólo lo que cambió, rastro por columna en `sync_changes` (`source = web`) y el
 * last-write-wins del sync— así que la regla vive **una sola vez** y cada estudio dice sólo lo que lo
 * distingue (su tabla, su modelo, su mapa y su lectura). Es el mismo criterio que el ViewModel compartido de
 * las pestañas *Laboratorio* y *Controles*: dos pantallas de la misma forma no se escriben dos veces.
 *
 * **Un eco sin datos no es un documento**: un estudio que sólo trae su fecha, su FUR y su gesta no se crea
 * (la hoja no se ofrece y la pantalla lo dice). La única columna que el servidor completa sola es la
 * **fecha** (con hoy, como el `open` del escritorio): la FUR y la gesta las pone el médico —el relevamiento
 * de WEB-6.2 mostró que las ventanas vivas **no** las copian del control prenatal—.
 */
abstract class GuardarEcoObstetricoBase
{
    use EscribeConRastro;

    /**
     * Las columnas que el servidor completa o que identifican al embarazo: **no cuentan** para decidir si el
     * estudio "tiene datos".
     *
     * @var array<int,string>
     */
    private const NO_CUENTAN = ['fecha', 'fur', 'gesta'];

    /** La tabla de ese estudio (la constante de su lectura: una sola fuente para los dos). */
    abstract protected function tabla(): string;

    /** El modelo de ese estudio. */
    abstract protected function modelo(): string;

    /** Las columnas que edita la pantalla de ese estudio (su mapa del dato). */
    abstract protected function columnasDelEstudio(): array;

    /** La lectura de ese estudio: sabe encontrar su fila y armar la de escribir. */
    abstract protected function lectura();

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
        foreach ($this->columnasDelEstudio() as $columna) {
            $nuevos[$columna] = EstudioEcografico::limpiar($valores[$columna] ?? null);
        }

        $nuevos['fecha'] ??= FechaDelConsultorio::hoyComoFecha()->toDateString();

        // ¿El estudio dice algo? La fecha, la FUR y la gesta no cuentan.
        $delEstudio = array_diff_key($nuevos, array_flip(self::NO_CUENTAN));
        $conDatos = count(array_filter($delEstudio, fn ($valor) => $valor !== null && $valor !== ''));

        if (! QueEsUnDocumento::ecografia($conDatos)) {
            throw new InvalidArgumentException('El eco necesita algún dato: cargá lo que haya dado el estudio.');
        }

        $cuando = $this->ahora($cuando);
        $modelo = $this->modelo();
        $lectura = $this->lectura();

        return DB::transaction(function () use ($consulta, $nuevos, $cuando, $origen, $modelo, $lectura) {
            $fila = $lectura->filaDe($consulta);

            if ($fila === null) {
                $nueva = $lectura->filaParaEscribir($consulta);

                $this->crearFila($modelo, $this->tabla(), array_merge($nuevos, $nueva->getAttributes()), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila(
                $modelo::find($fila->id), $this->tabla(), $nuevos, $cuando, $origen,
            );

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }
}

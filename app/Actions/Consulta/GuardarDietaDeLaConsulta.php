<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\DietaDeLaConsulta;
use App\Clinica\Consulta\QueEsUnDocumento;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Models\DietaPaciente;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar la dieta / recomendaciones de una consulta** (WEB-5.3b): el título (opcional) y el texto.
 *
 * **Una por consulta** (`dieta_paciente` se llavea por `histroria` + `consulta`): si ya hay fila se **corrige en el lugar**, sólo lo
 * que cambió; si no, se crea. El texto es obligatorio: una dieta sin texto no es un documento (`QueEsUnDocumento::dieta`). Para
 * quitarla, `EliminarDietaDeLaConsulta`. Se escribe libre o partiendo de una plantilla del médico: la plantilla **llena el editor**
 * en el navegador y el guardado sigue siendo el botón, así que acá llega siempre el texto ya terminado.
 *
 * **Sólo toca `dieta` (el título) y `descripcion`.** `cod_dieta`, `ejecutada` y `calorias` no las edita ninguna pantalla del escritorio:
 * al corregir una fila quedan como estén; al crearla, `ejecutada` lleva `'S'` —lo que el escritorio escribe en toda fila que crea— y
 * las otras dos `NULL`.
 *
 * Deja su rastro en `sync_changes` (`source = web`): `created` si crea —con el `id` de la fila, que es lo que **protege** la fila del
 * reemplazo de la tabla entera que hace la subida del escritorio (`dieta_paciente` no tiene clave primaria allá:
 * `sin_clave_con_altas_del_app`)—, `updated` por columna con el last-write-wins y `updated_at` nuevo.
 */
final class GuardarDietaDeLaConsulta
{
    use EscribeConRastro;

    public function __construct(private DietaDeLaConsulta $dietas)
    {
    }

    /**
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     *
     * @throws InvalidArgumentException si falta el texto o algún campo pasa de su tope
     */
    public function ejecutar(
        Consulta $consulta,
        ?string $titulo,
        ?string $descripcion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        $texto = TextoDeLaConsulta::limpio($descripcion);

        if (! QueEsUnDocumento::dieta($texto)) {
            throw new InvalidArgumentException('Escribe el texto de la dieta o de las recomendaciones, o elige una plantilla.');
        }

        if (mb_strlen($texto) > DietaDeLaConsulta::LARGO_TEXTO) {
            throw new InvalidArgumentException('El texto de la dieta es demasiado largo.');
        }

        $titulo = TextoDeLaConsulta::limpio($titulo);

        if ($titulo !== null && mb_strlen($titulo) > DietaDeLaConsulta::LARGO_TITULO) {
            throw new InvalidArgumentException('El título admite hasta ' . DietaDeLaConsulta::LARGO_TITULO . ' caracteres.');
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($consulta, $titulo, $texto, $cuando, $origen) {
            $nuevos = ['dieta' => $titulo, 'descripcion' => $texto];
            $fila = $this->dietas->fila($consulta);

            if ($fila === null) {
                $this->crearFila(DietaPaciente::class, 'dieta_paciente', array_merge($nuevos, [
                    'reg_medico' => (string) $consulta->reg_medico,
                    'histroria' => (int) $consulta->numhistoria,
                    'consulta' => (int) $consulta->nroconsulta,
                    'ejecutada' => DietaDeLaConsulta::EJECUTADA,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'dieta_paciente', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }
}

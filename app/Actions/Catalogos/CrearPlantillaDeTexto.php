<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\FormatoPrint;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de una plantilla de texto** (WEB-8.4, el *Nuevo* de `w_agregar_dietas`, la ventana «Plantillas» del escritorio).
 *
 * Como en el escritorio **el código lo da el servidor**: el mayor del médico + 1 en cuatro dígitos, con las filas del médico
 * **bajo candado** dentro de la misma transacción para que dos altas seguidas no tomen el mismo número. El título va en mayúsculas,
 * el tipo es uno de `TiposDePlantillaDeTexto` y no se duplica un título dentro del mismo tipo (las reglas están en
 * `ReglasDeLaPlantillaDeTexto`).
 *
 * Deja un `created` en `sync_changes` (`source = web`). `formato_print` no tiene clave primaria en el PowerBuilder (sólo un índice
 * único) y su registro de cambios es de sentencia: **el escritorio sube la tabla entera** y ese `created` es lo que
 * `CambiosEscritorio::reemplazarTabla` respeta para no borrar la fila (`sin_clave_con_altas_del_app`, trampa 88). El móvil no
 * baja esta tabla.
 */
final class CrearPlantillaDeTexto
{
    use EscribeConRastro;

    /**
     * @param  array{tipo?:?string, titulo?:?string, descripcion?:?string}  $datos
     *
     * @throws InvalidArgumentException si falta algo, pasa del largo, el tipo no es de la lista o ya hay una igual en ese tipo
     */
    public function ejecutar(
        string $regMedico,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): FormatoPrint {
        $tipo = ReglasDeLaPlantillaDeTexto::tipo($datos['tipo'] ?? null);
        $titulo = ReglasDeLaPlantillaDeTexto::titulo($datos['titulo'] ?? null);
        $texto = ReglasDeLaPlantillaDeTexto::texto($datos['descripcion'] ?? null);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $tipo, $titulo, $texto, $cuando, $origen) {
            $catalogo = FormatoPrint::where('reg_medico', $regMedico)->lockForUpdate()->get();

            $igual = $catalogo->first(
                fn (FormatoPrint $fila) => trim((string) $fila->tipo) === $tipo
                    && ReglasDeLaPlantillaDeTexto::clave($fila->titulo) === ReglasDeLaPlantillaDeTexto::clave($titulo)
            );

            if ($igual !== null) {
                throw new InvalidArgumentException(self::mensajeDeDuplicado($igual));
            }

            return $this->crearFila(FormatoPrint::class, 'formato_print', [
                'reg_medico' => $regMedico,
                'codigo' => ReglasDeLaPlantillaDeTexto::siguienteCodigo($catalogo->pluck('codigo')),
                'titulo' => $titulo,
                'descripcion' => $texto,
                'tipo' => $tipo,
            ], $cuando, $origen);
        });
    }

    /** El aviso de «ya existe una igual», con su código para que el médico la encuentre en la lista. */
    public static function mensajeDeDuplicado(FormatoPrint $existente): string
    {
        return 'Ya tienes «' . trim((string) $existente->titulo) . '» en ese tipo (código ' . trim((string) $existente->codigo)
            . '). Edita esa o escribe otro título.';
    }
}

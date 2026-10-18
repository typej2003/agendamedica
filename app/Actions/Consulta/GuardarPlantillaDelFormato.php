<?php

namespace App\Actions\Consulta;

use App\Actions\Catalogos\ReglasDeLaPlantillaDeTexto;
use App\Clinica\Consulta\PlantillasDelFormato;
use App\Models\FormatoPrint;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Guardar el formato de un estudio como plantilla** (O-17): «Guardar como plantilla» del panel del formato
 * del eco.
 *
 * La plantilla son los **campos descriptivos** del estudio (ver `PlantillasDelFormato`): las opciones, los
 * textos y las áreas, sin las medidas ni las fechas. El título va en mayúsculas y **no se duplica dentro del
 * estudio**: si ya hay una plantilla del formato con ese título, se **actualiza** con lo que hay en pantalla
 * (es la forma de corregirla sin salir de la consulta; la pantalla lo dice en el mensaje).
 *
 * Vive en `formato_print`, como las plantillas de texto, con la columna `formato` (la clave del estudio) y
 * `tipo` en `NULL`. El código es el correlativo de cuatro dígitos que da el servidor, igual que en las de
 * texto (esa tabla no tiene clave primaria en el escritorio y su único índice es `codigo`).
 *
 * Deja su rastro en `sync_changes` (`source = web`): un `created` al crearla —lo que
 * `CambiosEscritorio::reemplazarTabla` respeta para no borrarla cuando el escritorio sube `formato_print`
 * entera (trampa 88)— o el `updated` de la columna al corregirla.
 */
final class GuardarPlantillaDelFormato
{
    use EscribeConRastro;

    /**
     * @param  array{titulo?:?string, valores?:array<string,mixed>}  $datos  el título y los campos del formulario
     *
     * @throws InvalidArgumentException si el estudio no admite plantillas, falta el título o no hay ningún campo
     */
    public function ejecutar(
        string $regMedico,
        string $tipoDeEstudio,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): FormatoPrint {
        if (! PlantillasDelFormato::admite($tipoDeEstudio)) {
            throw new InvalidArgumentException('Ese estudio no tiene plantillas del formato.');
        }

        $titulo = ReglasDeLaPlantillaDeTexto::titulo($datos['titulo'] ?? null);
        $valores = PlantillasDelFormato::valoresDescriptivos($tipoDeEstudio, (array) ($datos['valores'] ?? []));

        if ($valores === []) {
            throw new InvalidArgumentException(
                'El estudio no tiene ningún campo descriptivo cargado: cargá al menos uno (las opciones, los '
                . 'hallazgos o las conclusiones) antes de guardarlo como plantilla.'
            );
        }

        $json = (string) json_encode(['campos' => $valores], JSON_UNESCAPED_UNICODE);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $tipoDeEstudio, $titulo, $json, $cuando, $origen) {
            $catalogo = FormatoPrint::where('reg_medico', $regMedico)->lockForUpdate()->get();

            $igual = $catalogo->first(
                fn (FormatoPrint $fila) => trim((string) $fila->formato) === $tipoDeEstudio
                    && ReglasDeLaPlantillaDeTexto::clave($fila->titulo) === ReglasDeLaPlantillaDeTexto::clave($titulo)
            );

            if ($igual !== null) {
                $this->corregirFila(FormatoPrint::find($igual->id), 'formato_print', ['descripcion' => $json], $cuando, $origen);

                return $igual->refresh();
            }

            return $this->crearFila(FormatoPrint::class, 'formato_print', [
                'reg_medico' => $regMedico,
                'codigo' => ReglasDeLaPlantillaDeTexto::siguienteCodigo($catalogo->pluck('codigo')),
                'titulo' => $titulo,
                'descripcion' => $json,
                'tipo' => null,
                'formato' => $tipoDeEstudio,
            ], $cuando, $origen);
        });
    }
}

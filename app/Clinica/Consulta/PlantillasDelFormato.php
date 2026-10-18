<?php

namespace App\Clinica\Consulta;

use App\Clinica\Consulta\Vistas\TiposDeEstudio;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * **Las plantillas del *formato* de un estudio ecográfico** (O-17): el estudio entero —sus campos
 * descriptivos— guardado con un título para reusarlo en la siguiente paciente.
 *
 * Hasta acá las plantillas de los ecos eran **de texto** (`PlantillasDelEstudio`): se copiaban al campo del
 * informe. Lo que pidió Alexander es otra cosa y más ambicioso: que **todo el formato** sea plantilla. La
 * decisión (2026-10-17) acota el alcance para que sea clínicamente seguro:
 *
 *  - la plantilla guarda los **campos descriptivos** del estudio —las opciones del útero y los ovarios, los
 *    hallazgos, los anexos, las conclusiones— y **no** las medidas numéricas, que son de cada paciente;
 *  - al aplicarla **sólo completa los campos vacíos**: nunca pisa lo que el médico ya escribió, así que se
 *    puede aplicar y seguir escribiendo sin perder nada;
 *  - la pantalla lo dice y avisa cuántos campos ya tenían dato y no se pisaron.
 *
 * ## Dónde vive
 *
 * En `formato_print`, la misma tabla de las plantillas de texto, con una **columna nueva `formato`**
 * (nullable, la clave del estudio: `ginecologico`, `obstetrico`…) y `tipo` en `NULL`. Se descartó usar un
 * `tipo` propio: el `tipo` de esa tabla ya significa «qué plantilla de texto es» para el ABM del escritorio
 * y para la pantalla de administración, y meter ahí un código nuevo lo haría aparecer como «Tipo XX» en el
 * escritorio, que comparte la tabla. Con la columna aparte, **las plantillas de texto siguen funcionando
 * igual** y la pantalla de administración no cambia: filtra por `tipo`, y estas filas no tienen.
 *
 * `descripcion` guarda los valores en JSON (`{"campos": {"columna": "valor"}}`), que es la columna de
 * texto libre de la tabla.
 *
 * Sin estado: lee y arma.
 */
final class PlantillasDelFormato
{
    /** La marca de que una fila de `formato_print` es una plantilla del formato (y de qué estudio). */
    public const COLUMNA = 'formato';

    /**
     * Las plantillas del formato de un estudio, por título, con sus valores ya decodificados.
     *
     * Un JSON ilegible o vacío se descarta: una plantilla del formato sin campos no completaría nada.
     *
     * @return Collection<int,array{titulo:string, valores:array<string,string>, id:int}>
     */
    public static function de(string $regMedico, string $tipoDeEstudio): Collection
    {
        return DB::table('formato_print')
            ->where('reg_medico', $regMedico)
            ->where(self::COLUMNA, $tipoDeEstudio)
            ->orderBy('titulo')
            ->orderBy('codigo')
            ->get(['id', 'titulo', 'descripcion'])
            ->map(fn ($fila) => self::plantilla($fila))
            ->filter()
            ->values();
    }

    /**
     * Los valores que se guardan de un formulario: **sólo** los campos descriptivos del estudio (la regla
     * está en `MapaDelEstudio::columnasDescriptivas()`), sin los vacíos.
     *
     * Un campo que no está en el mapa de ese estudio se descarta: la plantilla no puede completar lo que la
     * pantalla no tiene.
     *
     * @param  array<string,mixed>  $valores  los campos del formulario (nombre del control => valor)
     * @return array<string,string>
     */
    public static function valoresDescriptivos(string $tipoDeEstudio, array $valores): array
    {
        $descriptivos = [];

        foreach (MapaDelEstudio::columnasDescriptivas($tipoDeEstudio) as $columna) {
            $valor = TextoDeLaConsulta::limpio($valores[$columna] ?? null);

            if ($valor !== null) {
                $descriptivos[$columna] = $valor;
            }
        }

        return $descriptivos;
    }

    /**
     * **La regla de la aplicación**: completa los campos vacíos y respeta los que ya tienen algo.
     *
     * Es una función pura —la misma cuenta que hace el JavaScript del panel— y la que usan los tests: se le
     * pasan los valores que hay hoy en el formulario y los de la plantilla, y devuelve qué escribiría y qué
     * dejó como estaba. Un campo sin dato en el formulario **y** con dato en la plantilla se escribe; un
     * campo que la plantilla no trae, o que ya tiene algo, se queda como está.
     *
     * @param  array<string,mixed>  $actuales  lo que hay hoy en el formulario (columna => valor)
     * @param  array<string,mixed>  $plantilla  lo guardado en la plantilla (columna => valor)
     * @return array{escritos:array<string,string>, respetados:array<string,string>}
     */
    public static function aplicar(array $actuales, array $plantilla): array
    {
        $escritos = [];
        $respetados = [];

        foreach ($plantilla as $columna => $valor) {
            $deLaPlantilla = TextoDeLaConsulta::limpio($valor);

            if ($deLaPlantilla === null) {
                continue;
            }

            if (TextoDeLaConsulta::limpio($actuales[$columna] ?? null) === null) {
                $escritos[$columna] = $deLaPlantilla;
            } else {
                $respetados[$columna] = $deLaPlantilla;
            }
        }

        return ['escritos' => $escritos, 'respetados' => $respetados];
    }

    /**
     * La frase que la pantalla dice de la plantilla: qué completa y qué no toca. Es la decisión de O-17
     * escrita para el médico, no para el código.
     */
    public static function queHace(): string
    {
        return 'Aplica la plantilla a los campos vacíos: completa las opciones, los textos y las conclusiones '
            . 'del estudio. No toca las medidas ni las fechas, y no pisa lo que ya escribiste.';
    }

    /**
     * Los títulos de los bloques del formulario que la plantilla toca, para decirlo en la pantalla
     * («Útero, Anexos y Hallazgos…»).
     *
     * @return array<int,string>
     */
    public static function bloquesQueToca(string $tipoDeEstudio): array
    {
        $descriptivas = MapaDelEstudio::columnasDescriptivas($tipoDeEstudio);
        $bloques = [];

        foreach (MapaDelEstudio::grupos($tipoDeEstudio) as $grupo) {
            foreach ($grupo['campos'] as $campo) {
                if (in_array($campo['columna'], $descriptivas, true)) {
                    $bloques[] = (string) $grupo['titulo'];
                    break;
                }
            }
        }

        return $bloques;
    }

    /** ¿El tipo de estudio es uno de los que se pueden guardar como plantilla del formato? */
    public static function admite(string $tipoDeEstudio): bool
    {
        return TiposDeEstudio::existe($tipoDeEstudio);
    }

    /**
     * Una fila de `formato_print` como plantilla del formato, o `null` si no tiene valores legibles.
     *
     * @return array{titulo:string, valores:array<string,string>, id:int}|null
     */
    private static function plantilla($fila): ?array
    {
        $datos = json_decode((string) $fila->descripcion, true);
        $campos = is_array($datos) && is_array($datos['campos'] ?? null) ? $datos['campos'] : [];

        $valores = [];
        foreach ($campos as $columna => $valor) {
            $limpio = TextoDeLaConsulta::limpio($valor);

            if ($limpio !== null) {
                $valores[(string) $columna] = $limpio;
            }
        }

        if ($valores === []) {
            return null;
        }

        $titulo = trim((string) $fila->titulo);

        return [
            'titulo' => $titulo !== '' ? $titulo : trim((string) ($fila->codigo ?? '')),
            'valores' => $valores,
            'id' => (int) $fila->id,
        ];
    }
}

<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use App\Clinica\Pacientes\BusquedaDePacientes;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta y edición de una fila del catálogo inicial** (WEB-12.8) para cualquiera de los catálogos que declara `CatalogosIniciales`: la fila con `reg_medico` NULL, que es
 * lo que cada médico recibe al «Cargar el catálogo inicial». **Sólo toca filas sin dueño**: las de un consultorio no se encuentran. Las reglas salen del registro
 * (`campos`: requerido, largo, mayúsculas, número, marca o referencia a otro catálogo inicial):
 *  - **El código lo da el servidor** (el mayor numérico de las filas iniciales + 1, en los dígitos del catálogo) y no se edita.
 *  - **No se repite la identidad** (el nombre normalizado, y el tipo donde lo hay) dentro del catálogo inicial.
 *  - Un campo `ref` tiene que existir en el catálogo inicial al que apunta (el examen, su tipo).
 *
 * Las filas iniciales no se sincronizan con nadie: no dejan rastro en `sync_changes`. Lo que el administrador cambie acá no modifica lo que un médico ya copió.
 */
final class GuardarFilaDeCatalogoInicial
{
    /**
     * @param  array<string,mixed>  $datos  `columna => valor` de los `campos` del catálogo
     * @param  int|null  $id  la fila inicial que se edita (`null` = alta)
     * @return int  el id de la fila
     *
     * @throws InvalidArgumentException con el mensaje que ve el administrador
     */
    public function ejecutar(string $slug, array $datos, ?int $id = null): int
    {
        $def = CatalogosIniciales::de($slug);
        $fila = [];

        foreach ($def['campos'] as $columna => [$rotulo, $tipo, $max, $requerido, $mayusculas]) {
            $fila[$columna] = $this->valor($def, $columna, $rotulo, $tipo, (int) $max, (bool) $requerido, (bool) $mayusculas, $datos[$columna] ?? null);
        }

        return DB::transaction(function () use ($def, $fila, $id) {
            $tabla = $def['tabla'];
            $existentes = DB::table($tabla)->whereNull('reg_medico')->lockForUpdate()->get();

            if ($id !== null && ! $existentes->contains('id', $id)) {
                throw new InvalidArgumentException('Esa fila no está en el catálogo inicial.');
            }

            $clave = fn ($f) => $this->identidad($def, (array) $f);
            $repetida = $existentes->first(fn ($f) => (int) $f->id !== (int) $id && $clave($f) === $clave($fila));
            if ($repetida !== null) {
                throw new InvalidArgumentException('Ya hay otra fila igual en el catálogo inicial: «'
                    . trim((string) $repetida->{$def['etiqueta']}) . '» (código ' . trim((string) $repetida->{$def['codigo']}) . ').');
            }

            if ($id === null) {
                $codigo = ReglasDelCatalogo::siguienteCodigo($existentes->pluck($def['codigo']), mb_strtolower($def['titulo']), $def['digitos']);

                return (int) DB::table($tabla)->insertGetId($fila + ['reg_medico' => null, $def['codigo'] => $codigo, 'created_at' => now(), 'updated_at' => now()]);
            }

            DB::table($tabla)->whereNull('reg_medico')->where('id', $id)->update($fila + ['updated_at' => now()]);

            return $id;
        });
    }

    /** La identidad de una fila inicial: sus columnas normalizadas (la referencia, por su código: dentro del catálogo inicial el código es único). */
    private function identidad(array $def, array $fila): string
    {
        return collect($def['identidad'])->map(function ($parte) use ($fila) {
            $columna = str_starts_with($parte, 'ref:') ? substr($parte, 4) : $parte;

            return BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) ($fila[$columna] ?? ''))));
        })->implode('|');
    }

    /** @return mixed el valor listo para guardar */
    private function valor(array $def, string $columna, string $rotulo, string $tipo, int $max, bool $requerido, bool $mayusculas, $crudo)
    {
        if ($tipo === 'marca') {
            return filter_var($crudo, FILTER_VALIDATE_BOOLEAN) ? true : null;
        }

        $texto = in_array($tipo, ['area'], true) ? trim((string) $crudo) : trim((string) preg_replace('/\s+/u', ' ', (string) $crudo));

        if ($texto === '') {
            if ($requerido) {
                throw new InvalidArgumentException($tipo === 'ref' || $tipo === 'tipo' ? 'Elige ' . mb_strtolower($rotulo) . '.' : 'Escribe ' . mb_strtolower($rotulo) . '.');
            }

            return null;
        }

        switch ($tipo) {
            case 'numero':
                $numero = str_replace(',', '.', $texto);
                if (! is_numeric($numero) || (float) $numero < 0 || (float) $numero > 9999999) {
                    throw new InvalidArgumentException($rotulo . ' tiene que ser un número de 0 en adelante.');
                }

                return round((float) $numero, 2);

            case 'ref':
                $destino = CatalogosIniciales::de($def['refs'][$columna]);
                if (! DB::table($destino['tabla'])->whereNull('reg_medico')->where($destino['codigo'], $texto)->exists()) {
                    throw new InvalidArgumentException($rotulo . ': esa opción no está en el catálogo inicial.');
                }

                return $texto;

            case 'tipo':
                $texto = strtoupper($texto);
                if (! TiposDePlantillaDeTexto::conocido($texto)) {
                    throw new InvalidArgumentException('Elige el tipo de plantilla.');
                }

                return $texto;
        }

        if ($mayusculas) {
            $texto = mb_strtoupper($texto);
        }
        if ($max > 0 && mb_strlen($texto) > $max) {
            throw new InvalidArgumentException($rotulo . ' no puede pasar de ' . $max . ' caracteres.');
        }

        return $texto;
    }
}

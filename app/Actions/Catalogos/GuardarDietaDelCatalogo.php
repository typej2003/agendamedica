<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Actions\Consulta\ResultadoDeSolicitud;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Models\Dieta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta y edición de una dieta del catálogo del médico** (WEB-12.4b): la tabla `dietas` de pediatría (`cod_dieta`, título, texto y calorías) con su
 * `reg_medico`. Lo que el médico guarda acá es lo que la pestaña *Alimentación* de la consulta ofrece para copiar.
 *
 *  - **El código lo da el servidor** (el mayor numérico del médico + 1, en dos dígitos: la columna `cod_dieta` es de dos caracteres) y **no se edita**.
 *  - **El título va en mayúsculas** (hasta 200) y no se repite en el catálogo del médico (sin tildes ni mayúsculas).
 *  - **El texto es obligatorio** (una dieta sin texto no se ofrece en la consulta) y de hasta 20.000 caracteres, como las plantillas de texto.
 *  - **Las calorías** son opcionales: un número de 0 a 99.999.
 *  - Edita **sólo lo que cambió**, con el rastro por columna en `sync_changes` (`source = web`) y el last-write-wins.
 *
 * Una dieta **se copia** a la consulta al elegirla: editarla o quitarla no cambia lo que ya se escribió.
 */
final class GuardarDietaDelCatalogo
{
    use EscribeConRastro;

    public const LARGO_TITULO = 200;
    public const LARGO_TEXTO = ReglasDeLaPlantillaDeTexto::LARGO_TEXTO;
    public const CALORIAS_MAXIMAS = 99999;

    /**
     * @param  array{dietas?:?string, descripcion?:?string, calorias?:mixed}  $datos
     * @param  int|null  $id  la dieta que se edita (`null` = alta)
     *
     * @throws InvalidArgumentException con el mensaje que ve el médico
     */
    public function ejecutar(string $regMedico, array $datos, ?int $id = null, ?CarbonInterface $cuando = null, ?string $origen = 'web'): ResultadoDeSolicitud
    {
        $titulo = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) ($datos['dietas'] ?? ''))));

        if ($titulo === '') {
            throw new InvalidArgumentException('Escribe el título de la dieta.');
        }
        if (mb_strlen($titulo) > self::LARGO_TITULO) {
            throw new InvalidArgumentException('El título no puede pasar de ' . self::LARGO_TITULO . ' caracteres.');
        }

        $texto = trim((string) ($datos['descripcion'] ?? ''));
        if ($texto === '') {
            throw new InvalidArgumentException('Escribe el texto de la dieta: sin texto no se ofrece en la consulta.');
        }
        if (mb_strlen($texto) > self::LARGO_TEXTO) {
            throw new InvalidArgumentException('El texto no puede pasar de ' . self::LARGO_TEXTO . ' caracteres.');
        }

        $calorias = trim(str_replace(',', '.', (string) ($datos['calorias'] ?? '')));
        if ($calorias === '') {
            $calorias = null;
        } elseif (! preg_match('/^\d{1,5}(\.\d{1,2})?$/', $calorias) || (float) $calorias > self::CALORIAS_MAXIMAS) {
            throw new InvalidArgumentException('Las calorías van como un número de 0 a ' . self::CALORIAS_MAXIMAS . '.');
        } else {
            $calorias = (float) $calorias;
        }

        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $id, $titulo, $texto, $calorias, $cuando, $origen) {
            $catalogo = Dieta::where('reg_medico', $regMedico)->lockForUpdate()->get();

            $repetida = $catalogo->first(fn (Dieta $d) => (int) $d->id !== (int) $id && self::clave($d->dietas) === self::clave($titulo));
            if ($repetida !== null) {
                throw new InvalidArgumentException('Ya tienes una dieta llamada «' . trim((string) $repetida->dietas) . '» (código ' . trim((string) $repetida->cod_dieta)
                    . '). Edita esa o escribe otro título.');
            }

            if ($id === null) {
                $this->crearFila(Dieta::class, 'dietas', [
                    'reg_medico' => $regMedico,
                    'cod_dieta' => ReglasDelCatalogo::siguienteCodigo($catalogo->pluck('cod_dieta'), 'dietas', 2),
                    'dietas' => $titulo,
                    'descripcion' => $texto,
                    'calorias' => $calorias,
                ], $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            $fila = $catalogo->firstWhere('id', $id);
            if ($fila === null) {
                throw new InvalidArgumentException('Esa dieta no está en tu catálogo.');
            }

            $nuevos = ['dietas' => $titulo, 'calorias' => $calorias];
            // Los mismos renglones con otro salto de línea no son un cambio.
            if (ReglasDeLaPlantillaDeTexto::textoComparable($texto) !== ReglasDeLaPlantillaDeTexto::textoComparable($fila->descripcion)) {
                $nuevos['descripcion'] = $texto;
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'dietas', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    private static function clave(?string $titulo): string
    {
        return BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $titulo)));
    }
}

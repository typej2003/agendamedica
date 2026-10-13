<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Examen;
use App\Models\TipoExamen;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar un examen del catálogo** (WEB-8.1b): el guardado de `w_agregar_examenes_estudios` sobre un código que ya
 * existe. **El código no se edita** (el `em_1` del escritorio se deshabilita al cargar la fila); se editan el **nombre**
 * (mayúsculas, hasta 45) y el **tipo** (`codetipo`, uno de `tipos_examenes`).
 *
 * Escribe **sólo lo que cambió**, columna por columna, con el last-write-wins del sync y su `updated` en `sync_changes`
 * (`source = web`); la fila cambia su `updated_at`.
 *
 * ## Qué pasa con lo que ya se pidió
 *
 * `examen_paciente` guarda sólo el código: el nombre y el tipo que ven la consulta, el historial y las impresiones salen
 * del catálogo. Renombrar o cambiar de tipo **cambia lo que ven las consultas viejas**: es lo esperado (la misma cosa
 * corregida). Ojo con un detalle de la fuente: la lista de laboratorio de la consulta **excluye el tipo `002`**
 * (estudios de imágenes), así que mover un examen de o hacia ese tipo lo cambia de lista.
 *
 * ## No duplicar
 *
 * Igual que el alta: el mismo nombre (sin tildes ni mayúsculas) **y** el mismo tipo que **otro** examen se rechaza. Un
 * duplicado histórico se puede seguir editando mientras no se le ponga la identidad de otro.
 */
final class EditarExamenDelCatalogo
{
    use EscribeConRastro;

    /**
     * @param  array<int,string>  $registros  los registros del médico, para el tipo y para comparar contra todo su catálogo
     *
     * @throws InvalidArgumentException si falta el nombre, pasa del largo, el tipo no es del catálogo o ya hay otro igual
     */
    public function ejecutar(
        Examen $fila,
        array $registros,
        string $examen,
        ?string $codetipo,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeEdicion {
        $examen = ReglasDelCatalogo::descripcion($examen, CrearExamenEnElCatalogo::LARGO_NOMBRE, 'examen');
        $codetipo = CamposDePaciente::texto($codetipo);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($fila, $registros, $examen, $codetipo, $cuando, $origen) {
            $bloqueada = Examen::whereKey($fila->id)->lockForUpdate()->firstOrFail();
            $todos = array_values(array_unique(array_merge($registros, [(string) $bloqueada->reg_medico])));

            // Un tipo que el examen ya tenía se conserva aunque el catálogo ya no lo traiga (el legado tiene códigos sueltos):
            // sólo se exige que sea del catálogo cuando se está eligiendo uno distinto.
            if ($codetipo !== null
                && $codetipo !== CamposDePaciente::texto($bloqueada->codetipo)
                && ! TipoExamen::whereIn('reg_medico', $todos)->where('codetipo', $codetipo)->exists()) {
                throw new InvalidArgumentException('Elige un tipo de la lista.');
            }

            $this->noDuplicar($bloqueada, $todos, $examen, $codetipo);

            [$escritas, $bloqueadas] = $this->corregirFila($bloqueada, 'examenes', ['examen' => $examen, 'codetipo' => $codetipo], $cuando, $origen);

            return new ResultadoDeEdicion($bloqueada, $escritas, $bloqueadas);
        });
    }

    /** @param  array<int,string>  $registros */
    private function noDuplicar(Examen $fila, array $registros, string $examen, ?string $codetipo): void
    {
        $nombre = fn ($texto) => BusquedaDePacientes::normalizar(trim((string) preg_replace('/\s+/u', ' ', (string) $texto)));

        // La identidad no cambió: no hay duplicado nuevo que evitar (el histórico se limpia quitando, no editando).
        if ($nombre($fila->examen) === $nombre($examen) && CamposDePaciente::texto($fila->codetipo) === $codetipo) {
            return;
        }

        $otro = Examen::whereIn('reg_medico', $registros)
            ->where('id', '<>', $fila->id)
            ->get()
            ->first(fn (Examen $candidato) => $nombre($candidato->examen) === $nombre($examen)
                && CamposDePaciente::texto($candidato->codetipo) === $codetipo);

        if ($otro !== null) {
            throw new InvalidArgumentException(
                'Ya tienes «' . trim((string) $otro->examen) . '» con ese tipo en el catálogo (código '
                . trim((string) $otro->codeexamen) . '). Edita ese o cambia el nombre o el tipo.'
            );
        }
    }
}

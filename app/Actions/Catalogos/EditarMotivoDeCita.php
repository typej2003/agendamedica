<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\Medico;
use App\Models\MotivoCita;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar un motivo de cita** (WEB-8.1c): la razón, el precio, la sede y la marca de varias citas pendientes. **El código
 * no se edita** (en el escritorio el campo del código se deshabilita al abrir la fila).
 *
 * Es **la única puerta** a esas cuatro columnas: el diálogo del catálogo la usa con los cuatro datos y el interruptor de
 * «varias citas» (WEB-2.17) la usa sólo con la marca. Sólo se mira lo que llega en `$datos` y **sólo se escribe lo que
 * cambió**, columna por columna, con el last-write-wins del sync y un `updated` por columna en `sync_changes`
 * (`source = web`); la fila cambia su `updated_at`, que es lo que el delta del teléfono mira.
 *
 * ## Qué pasa con lo que ya se agendó
 *
 * **Nada.** El monto vive en `cola.monto` desde que se agenda la cita y la razón se guarda como código en `cola.tipo`: editar
 * el precio de un motivo no cambia lo cobrado ni lo pendiente de ninguna cita que ya existe. La razón sí es la que ven la
 * agenda y el historial de las citas viejas (se resuelve por el código): renombrar corrige el nombre en todas.
 *
 * ## La razón
 *
 * Va en mayúsculas, pero si el nombre que llega es **el mismo que ya tiene** (salvo mayúsculas) no se toca: los motivos que
 * cargó el móvil o la sincronización se escriben «Ecografía» y editarles sólo el precio no los renombra a «ECOGRAFÍA».
 *
 * ## No duplicar
 *
 * Igual que el alta: no puede quedar **otro** motivo con la misma razón (sin tildes ni mayúsculas) en la misma sede. Sólo se
 * revisa cuando la razón o la sede **cambian**: un duplicado histórico se puede seguir editando (precio, marca) y
 * quitándolo —si no tiene uso— es como se limpia.
 */
final class EditarMotivoDeCita
{
    use EscribeConRastro;

    /**
     * @param  array{razon?:?string, precio?:mixed, office_id?:mixed, admite_varias?:mixed}  $datos  sólo las claves presentes se editan
     *
     * @throws InvalidArgumentException si la razón falta o es muy larga, el precio no es válido, la sede no es del médico o
     *                                  ya hay otro motivo igual en esa sede
     */
    public function ejecutar(
        MotivoCita $fila,
        Medico $medico,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeEdicion {
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($fila, $medico, $datos, $cuando, $origen) {
            // Se relee la fila bajo candado: dos ediciones o una baja al mismo tiempo se turnan.
            $bloqueada = MotivoCita::whereKey($fila->id)->lockForUpdate()->firstOrFail();
            $nuevos = [];

            if (array_key_exists('razon', $datos)) {
                $razon = ReglasDelMotivoDeCita::razon($datos['razon']);
                $actual = (string) $bloqueada->tipo_atencion;
                // El mismo nombre salvo mayúsculas: se deja como está, no se renombra de pasada.
                $nuevos['tipo_atencion'] = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $actual))) === $razon ? $actual : $razon;
            }
            if (array_key_exists('precio', $datos)) {
                $nuevos['precio'] = ReglasDelMotivoDeCita::precio($datos['precio']);
            }
            if (array_key_exists('office_id', $datos)) {
                // Dejar la sede que ya tiene no se revalida (una fila vieja puede apuntar a una sede que el médico ya no usa).
                $pedida = $datos['office_id'];
                $misma = is_numeric($pedida) && $bloqueada->office_id !== null && (int) $pedida === (int) $bloqueada->office_id;
                $nuevos['office_id'] = $misma ? (int) $bloqueada->office_id : CrearMotivoDeCita::sede($medico, (string) $bloqueada->reg_medico, $pedida);
            }
            if (array_key_exists('admite_varias', $datos)) {
                $nuevos['admite_varias_citas_pendientes'] = filter_var($datos['admite_varias'], FILTER_VALIDATE_BOOLEAN) ? true : null;
            }

            $this->noDuplicar($bloqueada, $nuevos);

            [$escritas, $bloqueadas] = $this->corregirFila($bloqueada, 'motivo_cita', $nuevos, $cuando, $origen);

            return new ResultadoDeEdicion($bloqueada, $escritas, $bloqueadas);
        });
    }

    /** @param  array<string,mixed>  $nuevos */
    private function noDuplicar(MotivoCita $fila, array $nuevos): void
    {
        $razon = array_key_exists('tipo_atencion', $nuevos) ? $nuevos['tipo_atencion'] : $fila->tipo_atencion;
        $sede = array_key_exists('office_id', $nuevos) ? $nuevos['office_id'] : $fila->office_id;
        $sede = $sede === null ? null : (int) $sede;

        $cambiaLaIdentidad = ReglasDelMotivoDeCita::clave($razon) !== ReglasDelMotivoDeCita::clave($fila->tipo_atencion)
            || $sede !== ($fila->office_id === null ? null : (int) $fila->office_id);

        if (! $cambiaLaIdentidad) {
            return;
        }

        $otro = MotivoCita::where('reg_medico', $fila->reg_medico)
            ->where('id', '<>', $fila->id)
            ->get()
            ->first(fn (MotivoCita $candidato) => $candidato->office_id === $sede
                && ReglasDelMotivoDeCita::clave($candidato->tipo_atencion) === ReglasDelMotivoDeCita::clave($razon));

        if ($otro !== null) {
            throw new InvalidArgumentException(CrearMotivoDeCita::mensajeDeDuplicado($otro, $sede === null));
        }
    }
}

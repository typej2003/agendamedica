<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Doctor;
use App\Models\Especialidad;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar un médico referido del catálogo** (WEB-8.1b): el guardado de `w_agregar_doctores` sobre un código que ya
 * existe, con los ocho campos del ABM del escritorio (`CamposDelDoctor`).
 *
 * ## La cédula no se edita (decisión de WEB-8.1b)
 *
 * `doctores.cedula` es la clave y, en la práctica, **no es una cédula sino el siguiente correlativo** que da el alta.
 * En el escritorio sólo se escribe al crear la fila: al cargar un médico el campo se deshabilita (`em_1.enabled = false`,
 * `w_agregar_doctores.srw:230`), así que ese ABM **nunca** cambió una cédula. La web tampoco, por tres razones medidas:
 * (1) `referencia.ceduladoctor` enlaza por ese número, y cambiarlo exigiría reescribir todas las referencias de los
 * pacientes en la misma transacción; (2) el escritorio sube la fila **por clave**: si la web cambiara la cédula, la copia
 * del PowerBuilder seguiría con la vieja y su próxima subida **crearía un duplicado** con la clave antigua; (3) el móvil
 * baja `doctores` por `id`, así que un cambio de clave no le aporta nada. El código se muestra, no se edita.
 *
 * Escribe **sólo lo que cambió**, columna por columna, con el last-write-wins del sync y su `updated` en `sync_changes`
 * (`source = web`); la fila cambia su `updated_at`. Cambiar el nombre, la clínica o la especialidad **cambia lo que ven
 * las referencias ya hechas** (se resuelven por la cédula contra el catálogo): es lo esperado.
 *
 * ## No duplicar
 *
 * El mismo nombre completo (sin tildes ni mayúsculas) **y** la misma especialidad que **otro** médico se rechaza (es la
 * misma comparación del alta). Un duplicado histórico se puede seguir editando mientras no se le ponga la identidad de otro.
 */
final class EditarDoctorDelCatalogo
{
    use EscribeConRastro;

    /**
     * @param  array<int,string>  $registros  los registros del médico, para la especialidad y para comparar contra todo su catálogo
     * @param  array<string,mixed>  $datos  los campos del formulario (`CamposDelDoctor::COLUMNAS`)
     *
     * @throws InvalidArgumentException si falta el nombre o la especialidad, algo pasa del largo o ya hay otro igual
     */
    public function ejecutar(
        Doctor $fila,
        array $registros,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeEdicion {
        $campos = CamposDelDoctor::normalizar($datos);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($fila, $registros, $campos, $cuando, $origen) {
            $bloqueada = Doctor::whereKey($fila->id)->lockForUpdate()->firstOrFail();
            $todos = array_values(array_unique(array_merge($registros, [(string) $bloqueada->reg_medico])));

            // La especialidad que el médico ya tenía se conserva aunque el catálogo ya no la traiga; sólo se exige que
            // sea del catálogo cuando se elige una distinta.
            if ($campos['codeespecial'] !== CamposDePaciente::texto($bloqueada->codeespecial)
                && ! Especialidad::whereIn('reg_medico', $todos)->where('codeespecial', $campos['codeespecial'])->exists()) {
                throw new InvalidArgumentException('Elige la especialidad del médico.');
            }

            $this->noDuplicar($bloqueada, $todos, $campos);

            // Un navegador manda los saltos de línea de un `<textarea>` como CRLF y el escritorio guarda CRLF o LF según
            // quién escribió: los mismos renglones no son un cambio.
            foreach (['direccion', 'nota'] as $texto) {
                if (str_replace("\r\n", "\n", (string) $campos[$texto]) === str_replace("\r\n", "\n", trim((string) $bloqueada->{$texto}))) {
                    unset($campos[$texto]);
                }
            }

            [$escritas, $bloqueadas] = $this->corregirFila($bloqueada, 'doctores', $campos, $cuando, $origen);

            return new ResultadoDeEdicion($bloqueada, $escritas, $bloqueadas);
        });
    }

    /** @param  array<string,?string>  $campos */
    private function noDuplicar(Doctor $fila, array $registros, array $campos): void
    {
        $identidad = fn (?string $nombres, ?string $apellidos, ?string $especialidad) => BusquedaDePacientes::normalizar(
            trim((string) $nombres . ' ' . (string) $apellidos)
        ) . '|' . trim((string) $especialidad);

        $nueva = $identidad($campos['nombres'], $campos['apellidos'], $campos['codeespecial']);

        // La identidad no cambió: no hay duplicado nuevo que evitar.
        if ($identidad($fila->nombres, $fila->apellidos, $fila->codeespecial) === $nueva) {
            return;
        }

        $otro = Doctor::whereIn('reg_medico', $registros)
            ->where('id', '<>', $fila->id)
            ->get()
            ->first(fn (Doctor $candidato) => $identidad($candidato->nombres, $candidato->apellidos, $candidato->codeespecial) === $nueva);

        if ($otro !== null) {
            throw new InvalidArgumentException(
                'Ya tienes a «' . trim(trim((string) $otro->nombres) . ' ' . trim((string) $otro->apellidos))
                . '» con esa especialidad en el catálogo (código ' . (int) $otro->cedula . '). Edita ese o cambia el nombre o la especialidad.'
            );
        }
    }
}

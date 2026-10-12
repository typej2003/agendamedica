<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarDoctorAlCatalogo;
use App\Actions\Consulta\EscribeConRastro;
use App\Models\Doctor;
use App\Sync\RegistroDeCambios;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un médico referido desde el ABM del catálogo** (WEB-8.1b, el *Nuevo* de `w_agregar_doctores`).
 *
 * La regla del alta es la de `AgregarDoctorAlCatalogo` (WEB-5.3a): el código (`cedula`) es el siguiente correlativo del
 * médico, apellidos y nombres van en mayúsculas, la especialidad tiene que ser del catálogo `especial` y, si ya hay un
 * médico con el mismo nombre completo y la misma especialidad, **no se duplica** (se devuelve el existente). No se
 * escribe otra vez: el ABM sólo suma la **nota** (que el alta rápida no pide) y el **rastro** (`created`, `source = web`).
 * El alta rápida de la pestaña *Referencias* no cambia.
 *
 * `doctores` tiene clave primaria en el PowerBuilder (`cedula`) y triggers de fila: se sube **por fila** (trampa 60 de
 * `TRAMPAS.md`). Sólo chocaría un código que el escritorio y la web den a la vez.
 */
final class CrearDoctorEnElCatalogo
{
    use EscribeConRastro;

    public function __construct(private AgregarDoctorAlCatalogo $alta)
    {
    }

    /**
     * @param  string  $regMedico  el registro con el que se guarda la fila: el del contexto
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo y para no duplicar
     * @param  array<string,mixed>  $datos  los campos del formulario (`CamposDelDoctor::COLUMNAS`)
     * @return array{0: Doctor, 1: bool} el médico y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si falta el nombre, la especialidad no es del catálogo o algo pasa del largo
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        array $datos,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): array {
        $campos = CamposDelDoctor::normalizar($datos);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $registrosMedicos, $campos, $cuando, $origen) {
            [$doctor, $creado] = $this->alta->ejecutar(
                $regMedico,
                $registrosMedicos,
                $campos['apellidos'],
                $campos['nombres'],
                (string) $campos['codeespecial'],
                $campos['clinica'],
                $campos['direccion'],
                $campos['telefono'],
                $campos['ciudad'],
                $campos['nota'],
            );

            if ($creado && $origen !== null) {
                RegistroDeCambios::registrarOperacion('doctores', (int) $doctor->id, $regMedico, 'created', null, null, $cuando, $origen);
            }

            return [$doctor, $creado];
        });
    }
}

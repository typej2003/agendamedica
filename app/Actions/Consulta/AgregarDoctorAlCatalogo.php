<?php

namespace App\Actions\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Doctor;
use App\Models\Especialidad;
use InvalidArgumentException;

/**
 * **Alta rápida de un médico en el catálogo `doctores`** (WEB-5.3a): lo que hace el botón *Agregar* de la pestaña
 * *Referencias* del escritorio (`pb_33`, `open(w_nuevo_medico)`, `w_consulta_principal.srw:18061`).
 *
 * El escritorio sí permite el alta, así que la web también (el ABM completo del catálogo es WEB-8.1). Los campos son los
 * de `ventana_consulta/d_a_medicos_nuevos.srd`: apellidos y nombres (mayúsculas, `char(30)`), especialidad (el catálogo
 * `especial`; `codeespecial` es `NOT NULL`), clínica (`char(60)`), dirección, teléfono (`char(108)`) y ciudad (`char(30)`).
 *
 * ## El código lo da el servidor
 *
 * `doctores.cedula` es la clave y, en la práctica, **no es una cédula sino el siguiente correlativo**: la ventana de alta
 * ordena el catálogo por `cedula`, toma la última y le suma uno (`w_nuevo_medico.srw`, `ue_nuevo`: `registro2 ++` y
 * `right("0000000"+trim(codigo),8)`). Acá se toma **el mayor de los registros del médico** + 1: el catálogo se resuelve
 * por registro y, si falta, por código en cualquiera (`DoctoresDelMedico`), así que un código repetido entre dos
 * registros del mismo médico mostraría al colega equivocado.
 *
 * Si ya hay un médico con el mismo nombre completo y la misma especialidad en el catálogo, **se devuelve ése** sin
 * duplicarlo (el catálogo del legado trae repetidos). **Online-only**, como los demás catálogos clínicos de la web: el
 * móvil lo recibe por el delta de `doctores`.
 */
final class AgregarDoctorAlCatalogo
{
    public const LARGO_APELLIDOS = 30;
    public const LARGO_NOMBRES = 30;
    public const LARGO_CLINICA = 60;
    public const LARGO_TELEFONO = 108;
    public const LARGO_CIUDAD = 30;
    public const LARGO_DIRECCION = 2000;

    /**
     * @param  string  $regMedico  el registro con el que se guarda la fila (el del contexto)
     * @param  array<int,string>  $registrosMedicos  los registros del médico, para el correlativo y para no duplicar
     * @return array{0: Doctor, 1: bool} el médico y si se creó ahora (`false` = ya existía uno igual)
     *
     * @throws InvalidArgumentException si falta el nombre, la especialidad no está en el catálogo o algo pasa del largo
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        ?string $apellidos,
        ?string $nombres,
        string $codeespecial,
        ?string $clinica = null,
        ?string $direccion = null,
        ?string $telefono = null,
        ?string $ciudad = null,
    ): array {
        $registros = array_values(array_unique(array_merge($registrosMedicos, [$regMedico])));

        $apellidos = $this->texto($apellidos, self::LARGO_APELLIDOS, 'Los apellidos', true);
        $nombres = $this->texto($nombres, self::LARGO_NOMBRES, 'Los nombres', true);

        if ($apellidos === null && $nombres === null) {
            throw new InvalidArgumentException('Escribe el nombre del médico.');
        }

        $codigo = CamposDePaciente::texto($codeespecial);

        if ($codigo === null || ! Especialidad::whereIn('reg_medico', $registros)->where('codeespecial', $codigo)->exists()) {
            throw new InvalidArgumentException('Elige la especialidad del médico.');
        }

        $clinica = $this->texto($clinica, self::LARGO_CLINICA, 'La clínica', false);
        $direccion = $this->texto($direccion, self::LARGO_DIRECCION, 'La dirección', false);
        $telefono = $this->texto($telefono, self::LARGO_TELEFONO, 'El teléfono', false);
        $ciudad = $this->texto($ciudad, self::LARGO_CIUDAD, 'La ciudad', false);

        $existentes = Doctor::whereIn('reg_medico', $registros)->get();

        $nombreCompleto = fn (?string $a, ?string $n) => BusquedaDePacientes::normalizar(trim((string) $n . ' ' . (string) $a));

        $igual = $existentes->first(
            fn (Doctor $doctor) => $nombreCompleto($doctor->apellidos, $doctor->nombres) === $nombreCompleto($apellidos, $nombres)
                && trim((string) $doctor->codeespecial) === $codigo
        );

        if ($igual) {
            return [$igual, false];
        }

        $siguiente = (int) $existentes->max(fn (Doctor $doctor) => (int) $doctor->cedula) + 1;

        $doctor = Doctor::create([
            'reg_medico' => $regMedico,
            'cedula' => $siguiente,
            'apellidos' => $apellidos,
            'nombres' => $nombres,
            'codeespecial' => $codigo,
            'clinica' => $clinica,
            'direccion' => $direccion,
            'telefono' => $telefono,
            'ciudad' => $ciudad,
        ]);

        return [$doctor, true];
    }

    /** Un campo opcional: vacío es `null`; con texto, espacios colapsados, largo validado y, si se pide, en mayúsculas. */
    private function texto(?string $valor, int $largo, string $nombre, bool $mayusculas): ?string
    {
        $texto = trim((string) preg_replace('/[ \t]+/u', ' ', (string) $valor));

        if ($texto === '') {
            return null;
        }

        if (mb_strlen($texto) > $largo) {
            throw new InvalidArgumentException($nombre . ' supera los ' . $largo . ' caracteres.');
        }

        return $mayusculas ? mb_strtoupper($texto) : $texto;
    }
}

<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;
use Closure;
use Illuminate\Support\Collection;

/**
 * El bloque **«Familia»** de la ficha del paciente pediátrico (WEB-7.4.2a): los padres, el contacto de la familia y los hermanos.
 *
 * Sólo lectura y sin base de datos: recibe lo que leyó `FamiliaDelPaciente` y una función que arma el enlace a la ficha de un
 * hermano (la URL la pone el controlador). Se muestra únicamente si el paciente es un niño del escritorio de pediatría
 * (`cargada()`); en cualquier otro paciente no existe.
 *
 * ⚠️ `familia_bebe.situacion_pareja` es un código de un carácter cuyo significado no está documentado; **no se muestra** hasta que
 * Alexander lo confirme (no se inventa una equivalencia).
 */
final class FamiliaDePacienteVista
{
    /**
     * @param  array{chamo:object, familia:?object, hermanos:Collection<int,object>}|null  $datos
     * @param  Closure(int):string|null  $urlFicha  arma el enlace a la ficha de un paciente por su `id`
     */
    public function __construct(private ?array $datos, private ?Closure $urlFicha = null)
    {
    }

    /** ¿El paciente es un niño del escritorio de pediatría? Decide si el bloque existe. */
    public function cargada(): bool
    {
        return $this->datos !== null;
    }

    /** ¿Hay datos de la familia (`familia_bebe`), o sólo el niño? */
    public function conFamilia(): bool
    {
        return ($this->datos['familia'] ?? null) !== null;
    }

    /** @return array{nombre:?string, nacimiento:?string, edad:?int, ocupacion:?string} */
    public function padre(): array
    {
        return $this->adulto('nom_padre', 'fecn_padre', 'ocupa_padre');
    }

    /** @return array{nombre:?string, nacimiento:?string, edad:?int, ocupacion:?string} */
    public function madre(): array
    {
        return $this->adulto('nom_madre', 'fecn_madre', 'ocupa_madre');
    }

    /**
     * Los teléfonos de la familia, con su rótulo, sin los vacíos. El **orden** es el de la regla por defecto de los recordatorios
     * (madre, padre, otro): la que se confirma con Alexander (pregunta 12) y se aplica en WEB-7.4.2c.
     *
     * @return array<int,array{rotulo:string, numero:string}>
     */
    public function telefonos(): array
    {
        $filas = [];

        foreach (['celu_madre' => 'Celular de la madre', 'cel_padre' => 'Celular del padre', 'telefono' => 'Otro teléfono'] as $columna => $rotulo) {
            $numero = CamposDePaciente::texto($this->familia()->{$columna} ?? null);

            if ($numero !== null) {
                $filas[] = ['rotulo' => $rotulo, 'numero' => $numero];
            }
        }

        return $filas;
    }

    /** La cédula del representante que indica el escritorio (`familia_bebe.ced_representante`, o la del niño si la otra falta). */
    public function cedulaDelRepresentante(): ?string
    {
        return CamposDePaciente::cedula($this->familia()->ced_representante ?? null)
            ?? CamposDePaciente::cedula($this->datos['chamo']->representante ?? null);
    }

    public function direccion(): ?string
    {
        return CamposDePaciente::texto($this->familia()->direccion ?? null);
    }

    public function localidad(): ?string
    {
        return CamposDePaciente::texto($this->familia()->localidad ?? null);
    }

    public function correo(): ?string
    {
        return CamposDePaciente::texto($this->familia()->email ?? null);
    }

    /** La dinámica familiar, en texto libre. */
    public function dinamica(): ?string
    {
        return CamposDePaciente::texto($this->familia()->dinamica_familiar ?? null);
    }

    /** La nota de la familia, en texto libre. */
    public function notaDeLaFamilia(): ?string
    {
        return CamposDePaciente::texto($this->familia()->familia_texto ?? null);
    }

    /** @return array<int,array{nombre:string, edad:?string, sexo:?string, url:?string, numero:string}> */
    public function hermanos(): array
    {
        return ($this->datos['hermanos'] ?? new Collection())->map(function ($hermano) {
            $edad = FechaClinica::edad($hermano->f_nacimiento ?? null);

            return [
                'nombre' => CamposDePaciente::texto($hermano->nombre ?? null) ?? 'Sin nombre',
                'edad' => $edad === null ? null : $edad . ($edad === 1 ? ' año' : ' años'),
                'sexo' => match (strtoupper((string) ($hermano->sexo ?? ''))) {
                    'M' => 'Niño', 'F' => 'Niña', default => null,
                },
                'url' => ($hermano->paciente_id ?? null) !== null && $this->urlFicha !== null
                    ? ($this->urlFicha)((int) $hermano->paciente_id)
                    : null,
                'numero' => 'H. ' . $hermano->historia,
            ];
        })->values()->all();
    }

    /** El estado vacío cuando el niño no tiene familia cargada. */
    public function vacio(): array
    {
        return [
            'titulo' => 'Sin datos de la familia',
            'texto' => 'Este niño no tiene familia registrada en el escritorio de pediatría. Cuando se cargue podrás ver acá a los padres, '
                . 'los teléfonos y los hermanos.',
        ];
    }

    private function familia(): object
    {
        return $this->datos['familia'] ?? (object) [];
    }

    /** @return array{nombre:?string, nacimiento:?string, edad:?int, ocupacion:?string} */
    private function adulto(string $nombre, string $nacimiento, string $ocupacion): array
    {
        $fecha = $this->familia()->{$nacimiento} ?? null;
        $formato = FechaClinica::formato($fecha);

        return [
            'nombre' => CamposDePaciente::texto($this->familia()->{$nombre} ?? null),
            'nacimiento' => $formato === '—' ? null : $formato,
            'edad' => FechaClinica::edad($fecha),
            'ocupacion' => CamposDePaciente::texto($this->familia()->{$ocupacion} ?? null),
        ];
    }
}

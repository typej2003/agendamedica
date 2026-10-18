<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarMedicionesDeLaConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Clinica\Consulta\Vistas\ConsultaShellVista;
use App\Clinica\Consulta\Vistas\PestanasDelExamen;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Illuminate\Validation\ValidationException;

/**
 * **Las mediciones de una consulta** (WEB-7.4.3a): talla, peso, perímetros, pulso y presión de la solapa *Mediciones* de pediatría. La
 * solapa se **lee** en `ConsultaController::ver` (`?seccion=evaluacion&pestana=mediciones`); acá sólo se escribe. La regla —la fila de
 * `percentiles_paciente`, el IMC, el rastro del sync— es la Action `GuardarMedicionesDeLaConsulta`.
 *
 * La consulta se vuelve a resolver contra el contexto: una de otro consultorio da 404 y la secretaría no llega (grupo `role:Root|Medico`).
 */
class ConsultaMedicionesController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function guardar(Request $request, Consulta $consulta, GuardarMedicionesDeLaConsulta $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $consulta = $this->consultas->consulta(
            $this->registrosDelMedico($contexto['medico']),
            (int) $consulta->id,
            $contexto['reg_medico'],
        );

        $datos = $request->validate($this->reglas(), $this->avisos());

        try {
            $resultado = $guardar->ejecutar($consulta, $datos);
        } catch (InvalidArgumentException $excepcion) {
            throw ValidationException::withMessages(['peso' => $excepcion->getMessage()]);
        }

        $destino = redirect()->route('clinica.consulta', [
            $consulta->id,
            'seccion' => ConsultaShellVista::SECCION_EVALUACION,
            'pestana' => PestanasDelExamen::MEDICIONES,
        ]);

        // El guard del sync puede rechazar columnas porque hay una edición más nueva sobre ellas: se dice, en vez de un «Guardado» falso.
        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->listar($resultado->bloqueadas)
                . ' más recientemente y no se pisó: vuelve a cargar las mediciones y guárdalas de nuevo.');
        }

        return $destino->with('estado', $resultado->sinCambios() ? 'Las mediciones no tenían cambios.' : 'Mediciones guardadas.');
    }

    /**
     * Topes humanos, no clínicos: sólo evitan un cuerpo absurdo (la coma decimal se acepta, así que los números viajan como texto).
     *
     * @return array<string,string>
     */
    private function reglas(): array
    {
        $numero = fn (int $max) => ['nullable', 'regex:/^\s*[0-9]+([.,][0-9]{1,3})?\s*$/', function ($atributo, $valor, $falla) use ($max) {
            $medida = (float) str_replace(',', '.', (string) $valor);

            if ($medida <= 0) {
                $falla('El valor tiene que ser mayor que cero.');
            } elseif ($medida > $max) {
                $falla('Revisa el valor: es demasiado grande.');
            }
        }];

        return [
            'peso' => $numero(300),
            'talla' => $numero(250),
            'c_cefalica' => $numero(100),
            'c_brazo' => $numero(100),
            'pulso' => $numero(300),
            'p_a_sistolica' => $numero(300),
            'p_a_diastolica' => $numero(300),
        ];
    }

    /** @return array<string,string> */
    private function avisos(): array
    {
        $avisos = [];
        foreach (['peso' => 'El peso', 'talla' => 'La talla', 'c_cefalica' => 'El perímetro cefálico', 'c_brazo' => 'El perímetro del brazo',
            'pulso' => 'El pulso', 'p_a_sistolica' => 'La PA sistólica', 'p_a_diastolica' => 'La PA diastólica'] as $campo => $rotulo) {
            $avisos[$campo . '.regex'] = $rotulo . ' tiene que ser un número (hasta 3 decimales).';
        }

        return $avisos;
    }

    /** @param  array<int,string>  $columnas */
    private function listar(array $columnas): string
    {
        $rotulos = [
            'peso' => 'el peso', 'talla' => 'la talla', 'c_cefalica' => 'el perímetro cefálico', 'c_brazo' => 'el perímetro del brazo',
            'pulso' => 'el pulso', 'p_a_sistolica' => 'la PA sistólica', 'p_a_diastolica' => 'la PA diastólica', 'imc' => 'el IMC',
        ];
        $nombres = array_map(fn ($columna) => '«' . ($rotulos[$columna] ?? $columna) . '»', $columnas);
        $ultimo = array_pop($nombres);

        return $nombres === [] ? $ultimo : implode(', ', $nombres) . ' y ' . $ultimo;
    }
}

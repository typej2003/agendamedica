<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CamposDelDoctor;
use App\Actions\Catalogos\CrearDoctorEnElCatalogo;
use App\Actions\Catalogos\EditarDoctorDelCatalogo;
use App\Actions\Catalogos\QuitarDoctorDelCatalogo;
use App\Actions\Consulta\AgregarDoctorAlCatalogo;
use App\Clinica\Catalogos\DoctoresDelCatalogo;
use App\Clinica\Catalogos\Vistas\DoctoresVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Médicos referidos del catálogo** (WEB-8.1b): `doctores` del médico del contexto —su libreta de colegas, no los usuarios
 * del sistema— en `/clinica/catalogos/medicos-referidos`: listado paginado con buscador sin tildes, alta y edición en un
 * diálogo y "quitar". El `w_agregar_doctores` del escritorio.
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`: la secretaría recibe 403) y **todo está acotado al
 * `reg_medico` del contexto**: un médico de otro catálogo responde 404. La regla no está acá: el alta es
 * `CrearDoctorEnElCatalogo` (que usa `AgregarDoctorAlCatalogo`, la de la pestaña *Referencias*), la edición
 * `EditarDoctorDelCatalogo` —**la cédula (el código) no se edita**— y la baja `QuitarDoctorDelCatalogo`, que no borra a
 * quien alguna referencia nombra y dice dónde.
 */
class DoctorCatalogoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private DoctoresDelCatalogo $doctores)
    {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];
        $registros = $this->registrosDelMedico($contexto['medico']);

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route('clinica.catalogos.medicos-referidos');

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->doctores->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.doctores', ['vm' => new DoctoresVista(
            $this->doctores->pagina($regMedico, $registros, $buscar, (int) $request->query('page', 1), $base, $buscar === '' ? [] : ['buscar' => $buscar]),
            $buscar,
            $this->doctores->especialidades($registros),
            $this->doctores->total($regMedico),
            $editando,
            $base,
            [
                'guardar' => route('clinica.catalogos.medicos-referidos.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.medicos-referidos.quitar', $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    /** Alta o edición (según venga o no el `id`), con las Actions como único lugar donde vive la regla. */
    public function guardar(Request $request, CrearDoctorEnElCatalogo $crear, EditarDoctorDelCatalogo $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate($this->reglas(), $this->mensajes());

        $fila = ($datos['id'] ?? null) !== null
            ? $this->doctores->encontrar($regMedico, (int) $datos['id'])
            : null;

        $registros = $this->registrosDelMedico($contexto['medico']);
        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['page'] ?? null);

        try {
            if ($fila === null) {
                [$creado, $nuevo] = $crear->ejecutar($regMedico, $registros, $datos);

                if (! $nuevo) {
                    return back()->withInput()->withErrors(['apellidos' => 'Ya tienes a «' . $this->nombre($creado) . '» con esa especialidad en el catálogo (código '
                        . (int) $creado->cedula . '). Edítalo desde la lista o cambia el nombre o la especialidad.']);
                }

                return redirect()
                    ->route('clinica.catalogos.medicos-referidos')
                    ->with('estado', 'Creaste a «' . $this->nombre($creado) . '» en tus médicos referidos (código ' . (int) $creado->cedula . ').');
            }

            $resultado = $editar->ejecutar($fila, $registros, $datos);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.medicos-referidos', $vuelta);
        $nombre = $this->nombre($resultado->fila);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó ' . $this->etiquetas($resultado->bloqueadas) . ' de «' . $nombre
                . '» más recientemente y no se pisó: vuelve a abrirlo y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', '«' . $nombre . '» no tenía cambios.');
        }

        return $destino->with('estado', 'Guardaste «' . $nombre . '»: cambió ' . $this->etiquetas($resultado->escritas) . '.');
    }

    /**
     * Quitar un médico. Si alguna referencia lo nombra **no se borra** y el aviso dice en cuántas; si no, se borra (la
     * pantalla pidió confirmación).
     */
    public function quitar(Request $request, int $id, QuitarDoctorDelCatalogo $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fila = $this->doctores->encontrar((string) $contexto['reg_medico'], $id);

        $vuelta = $this->vuelta(mb_substr(trim((string) $request->input('buscar', '')), 0, 100), $request->input('page'));

        $resultado = $quitar->ejecutar($fila, $this->registrosDelMedico($contexto['medico']));
        $destino = redirect()->route('clinica.catalogos.medicos-referidos', $vuelta);

        if (! $resultado->quitado) {
            return $destino->with('error', 'No se quitó a «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Quita esas referencias primero o déjalo en el catálogo.');
        }

        return $destino->with('estado', 'Quitaste a «' . $resultado->nombre . '» de tus médicos referidos.');
    }

    // ------------------------------------------------------------------------------------------ Adentro

    private function nombre($doctor): string
    {
        $nombre = trim(trim((string) $doctor->nombres) . ' ' . trim((string) $doctor->apellidos));

        return $nombre !== '' ? $nombre : 'médico ' . (int) $doctor->cedula;
    }

    /** Dónde se pinta el error de la Action: bajo el campo del que habla; si no se sabe, bajo los apellidos. */
    private function campoDelError(string $mensaje): string
    {
        // El duplicado habla de la especialidad pero es del nombre: se pinta bajo los apellidos.
        if (str_starts_with($mensaje, 'Ya tienes')) {
            return 'apellidos';
        }

        foreach (['especialidad' => 'codeespecial', 'nombres' => 'nombres', 'clínica' => 'clinica', 'ciudad' => 'ciudad',
            'teléfono' => 'telefono', 'dirección' => 'direccion', 'nota' => 'nota'] as $palabra => $campo) {
            if (str_contains(mb_strtolower($mensaje), $palabra)) {
                return $campo;
            }
        }

        return 'apellidos';
    }

    /** "los apellidos", "los apellidos y la clínica", "A, B y C". */
    private function etiquetas(array $columnas): string
    {
        $textos = array_map(fn ($columna) => CamposDelDoctor::ETIQUETAS[$columna] ?? $columna, $columnas);

        if (count($textos) === 1) {
            return $textos[0];
        }

        $ultimo = array_pop($textos);

        return implode(', ', $textos) . ' y ' . $ultimo;
    }

    /** A dónde vuelve la lista: la misma búsqueda y página (la 1 no hace falta escribirla). */
    private function vuelta(?string $buscar, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }

    /** @return array<string,string> */
    private function reglas(): array
    {
        return [
            'id' => 'nullable|integer',
            'apellidos' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_APELLIDOS,
            'nombres' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_NOMBRES,
            'codeespecial' => 'nullable|string|max:3',
            'clinica' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_CLINICA,
            'ciudad' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_CIUDAD,
            'telefono' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_TELEFONO,
            'direccion' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_DIRECCION,
            'nota' => 'nullable|string|max:' . AgregarDoctorAlCatalogo::LARGO_NOTA,
            'buscar' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ];
    }

    /**
     * Un aviso por regla: el `locale` de la app es `en` y la validación sale en inglés si no se le da su texto (trampa 38).
     *
     * @return array<string,string>
     */
    private function mensajes(): array
    {
        return [
            'id.integer' => 'No se pudo saber qué médico se edita: vuelve a abrirlo.',
            'apellidos.max' => 'Los apellidos admiten hasta ' . AgregarDoctorAlCatalogo::LARGO_APELLIDOS . ' caracteres.',
            'nombres.max' => 'Los nombres admiten hasta ' . AgregarDoctorAlCatalogo::LARGO_NOMBRES . ' caracteres.',
            'codeespecial.max' => 'La especialidad elegida no es válida.',
            'clinica.max' => 'La clínica admite hasta ' . AgregarDoctorAlCatalogo::LARGO_CLINICA . ' caracteres.',
            'ciudad.max' => 'La ciudad admite hasta ' . AgregarDoctorAlCatalogo::LARGO_CIUDAD . ' caracteres.',
            'telefono.max' => 'El teléfono admite hasta ' . AgregarDoctorAlCatalogo::LARGO_TELEFONO . ' caracteres.',
            'direccion.max' => 'La dirección admite hasta ' . AgregarDoctorAlCatalogo::LARGO_DIRECCION . ' caracteres.',
            'nota.max' => 'La nota es demasiado larga.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ];
    }
}

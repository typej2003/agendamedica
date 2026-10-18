<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CopiarVacunasIniciales;
use App\Actions\Catalogos\GuardarDosisDeVacunaDelCatalogo;
use App\Actions\Catalogos\GuardarVacunaDelCatalogo;
use App\Actions\Catalogos\QuitarVacunaDelCatalogo;
use App\Clinica\Catalogos\NovedadesDeVacunas;
use App\Clinica\Catalogos\Vistas\VacunasCatalogoVista;
use App\Clinica\Catalogos\VacunasDelCatalogo;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Vacunas del catálogo del médico** (WEB-7.4.4e): `vacunas` y `vacunas_dosis` del `reg_medico` del contexto, en `/clinica/catalogos/vacunas`. Alta y
 * edición de la vacuna en un diálogo, las dosis de cada una en otro, «quitar», y el botón **«Cargar el catálogo inicial»** (la copia de las
 * vacunas que arma el admin, `CopiarVacunasIniciales`) cuando el médico no tiene alguna.
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`) y todo está acotado a su `reg_medico`, que sale del contexto y nunca del formulario:
 * las filas del catálogo inicial y las de otro consultorio no se encuentran. La regla vive en las Actions; el cobro y el inventario no se tocan.
 */
class VacunaCatalogoController extends Controller
{
    public function __construct(private VacunasDelCatalogo $catalogo, private NovedadesDeVacunas $novedades)
    {
    }

    public function index(Request $request)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];
        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route('clinica.catalogos.vacunas');

        $todas = $this->catalogo->de($regMedico);
        $editando = $this->entero($request->query('editar')) !== null ? $todas->firstWhere('id', $this->entero($request->query('editar'))) : null;
        $conDosis = $this->entero($request->query('dosis_de')) !== null ? $todas->firstWhere('id', $this->entero($request->query('dosis_de'))) : null;

        return view('clinica.catalogos.vacunas', ['vm' => new VacunasCatalogoVista(
            $buscar === '' ? $todas : $this->catalogo->de($regMedico, $buscar),
            $todas->count(),
            $buscar,
            $this->novedades->de($regMedico),
            $editando,
            $conDosis,
            $request->boolean('nuevo'),
            [
                'base' => $base,
                'guardar' => route('clinica.catalogos.vacunas.guardar'),
                'cargarInicial' => route('clinica.catalogos.vacunas.cargar-inicial'),
                'quitar' => fn (int $id) => route('clinica.catalogos.vacunas.quitar', $id),
                'dosis' => fn (int $id) => $base . '?dosis_de=' . $id,
                'guardarDosis' => fn (int $id) => route('clinica.catalogos.vacunas.dosis.guardar', $id),
                'quitarDosis' => fn (int $id) => route('clinica.catalogos.vacunas.dosis.quitar', $id),
            ],
            $this->entero($request->query('dosis')),
        )]);
    }

    /** Alta o edición de la vacuna (según venga o no el `id`). */
    public function guardar(Request $request, GuardarVacunaDelCatalogo $accion)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];

        $datos = $request->validate([
            'id' => 'nullable|integer',
            'vacuna' => 'nullable|string|max:300',
            'edad_inicial_meses' => 'nullable|string|max:10',
            'fija' => 'nullable|string|max:1',
            'sexo' => 'nullable|string|max:1',
            'cobertura' => 'nullable|string|max:500',
        ]);

        try {
            $resultado = $accion->ejecutar($regMedico, $datos, isset($datos['id']) ? (int) $datos['id'] : null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $nombre = mb_strtoupper(trim((string) ($datos['vacuna'] ?? '')));
        $destino = redirect()->route('clinica.catalogos.vacunas');

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $nombre . '» más recientemente y no se pisó: vuelve a abrirla y revisa los cambios.');
        }

        return $destino->with('estado', $resultado->creado
            ? 'Creaste «' . $nombre . '» en tu catálogo de vacunas. Ahora puedes definir sus dosis.'
            : ($resultado->sinCambios() ? '«' . $nombre . '» no tenía cambios.' : 'Guardaste «' . $nombre . '».'));
    }

    public function quitar(Request $request, int $id, QuitarVacunaDelCatalogo $accion)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];

        try {
            $nombre = $accion->ejecutar($regMedico, $id);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('clinica.catalogos.vacunas')->with('error', $e->getMessage());
        }

        return redirect()->route('clinica.catalogos.vacunas')->with('estado', 'Quitaste «' . $nombre . '» de tu catálogo de vacunas.');
    }

    /** Alta o edición de una dosis de la vacuna `{id}` (según venga o no el `dosis_id`); vuelve al diálogo de las dosis. */
    public function guardarDosis(Request $request, int $id, GuardarDosisDeVacunaDelCatalogo $accion)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];
        $vuelta = route('clinica.catalogos.vacunas', ['dosis_de' => $id]);

        $datos = $request->validate([
            'dosis_id' => 'nullable|integer',
            'dosis' => 'nullable|string|max:10',
            'dosis_descri' => 'nullable|string|max:200',
            'meses' => 'nullable|string|max:12',
        ]);

        try {
            $accion->ejecutar($regMedico, $id, $datos, isset($datos['dosis_id']) ? (int) $datos['dosis_id'] : null);
        } catch (InvalidArgumentException $e) {
            return redirect($vuelta . (isset($datos['dosis_id']) ? '&dosis=' . (int) $datos['dosis_id'] : ''))->withInput()->with('error', $e->getMessage());
        }

        return redirect($vuelta)->with('estado', 'Dosis guardada.');
    }

    public function quitarDosis(Request $request, int $id, GuardarDosisDeVacunaDelCatalogo $accion)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];
        $vacunaId = $this->catalogo->de($regMedico)->first(fn ($v) => $v->dosis->contains('id', $id))?->id;

        try {
            $accion->quitar($regMedico, $id);
        } catch (InvalidArgumentException $e) {
            return redirect()->route('clinica.catalogos.vacunas')->with('error', $e->getMessage());
        }

        return redirect()->route('clinica.catalogos.vacunas', $vacunaId ? ['dosis_de' => $vacunaId] : [])->with('estado', 'Quitaste la dosis.');
    }

    /** **«Cargar el catálogo inicial»**: copia al consultorio las vacunas del catálogo inicial que el médico no tiene; nunca pisa lo suyo. */
    public function cargarInicial(Request $request, CopiarVacunasIniciales $accion)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];
        $r = $accion->ejecutar($regMedico);

        if ($r['vacunas'] === 0) {
            return redirect()->route('clinica.catalogos.vacunas')->with('estado', 'Ya tienes todo lo que trae el catálogo inicial: no se agregó nada.');
        }

        return redirect()->route('clinica.catalogos.vacunas')->with('estado', 'Se cargaron ' . $r['vacunas'] . ($r['vacunas'] === 1 ? ' vacuna' : ' vacunas')
            . ' y ' . $r['dosis'] . ($r['dosis'] === 1 ? ' dosis' : ' dosis') . ' del catálogo inicial. Lo que ya tenías no se tocó.');
    }

    private function entero($valor): ?int
    {
        return $valor !== null && ctype_digit((string) $valor) ? (int) $valor : null;
    }

    /** El error del nombre se pinta bajo el nombre, el de la edad bajo la edad, etc. */
    private function campoDelError(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, 'edad') => 'edad_inicial_meses',
            str_contains($mensaje, 'obligatoria o recomendada') => 'fija',
            str_contains($mensaje, 'sexo') => 'sexo',
            str_contains($mensaje, 'cobertura') => 'cobertura',
            default => 'vacuna',
        };
    }
}

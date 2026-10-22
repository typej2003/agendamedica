<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearMotivoDeFactura;
use App\Actions\Catalogos\EditarMotivoDeFactura;
use App\Actions\Catalogos\QuitarMotivoDeFactura;
use App\Clinica\Catalogos\MotivosDeFacturaDelCatalogo;
use App\Clinica\Catalogos\Vistas\MotivosDeFacturaVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **Conceptos de la pre-factura** (F9): `motivo_factura` del médico del contexto en `/clinica/catalogos/motivos-factura`.
 * Listado paginado con buscador sin tildes, alta y edición en un diálogo (nombre, monto y monto con seguro) y «quitar».
 *
 * Es del **médico** (el grupo de rutas lleva `role:Root|Medico`) y todo está acotado al `reg_medico` del contexto: un
 * concepto de otro médico responde 404. La regla vive en `CrearMotivoDeFactura`, `EditarMotivoDeFactura` y
 * `QuitarMotivoDeFactura` (que no borra lo que una pre-factura ya usa).
 */
class MotivoFacturaCatalogoController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private MotivosDeFacturaDelCatalogo $motivos)
    {
    }

    public function index(Request $request)
    {
        $regMedico = (string) $request->attributes->get('contexto_clinico')['reg_medico'];

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 100);
        $base = route('clinica.catalogos.motivos-factura');

        $editando = $request->query('editar') !== null && ctype_digit((string) $request->query('editar'))
            ? $this->motivos->encontrar($regMedico, (int) $request->query('editar'))
            : null;

        return view('clinica.catalogos.motivos-factura', ['vm' => new MotivosDeFacturaVista(
            $this->motivos->pagina($regMedico, $buscar, (int) $request->query('page', 1), $base, $buscar !== '' ? ['buscar' => $buscar] : []),
            $buscar,
            $this->motivos->total($regMedico),
            $editando,
            $base,
            [
                'guardar' => route('clinica.catalogos.motivos-factura.guardar'),
                'quitar' => fn (int $id) => route('clinica.catalogos.motivos-factura.quitar', $id),
            ],
            $request->boolean('nuevo'),
        )]);
    }

    public function guardar(Request $request, CrearMotivoDeFactura $crear, EditarMotivoDeFactura $editar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = (string) $contexto['reg_medico'];

        $datos = $request->validate([
            'id' => 'nullable|integer',
            'descripcion' => 'nullable|string|max:300',
            'monto' => 'nullable|string|max:20',
            'monto_seg' => 'nullable|string|max:20',
            'buscar' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ], [
            'id.integer' => 'No se pudo saber qué concepto se edita: vuelve a abrirlo.',
            'descripcion.max' => 'El nombre del concepto es demasiado largo.',
            'monto.max' => 'El monto no es válido.',
            'monto_seg.max' => 'El monto con seguro no es válido.',
            'buscar.max' => 'La búsqueda es demasiado larga.',
            'page.integer' => 'La página no es válida.',
            'page.min' => 'La página no es válida.',
        ]);

        $fila = ($datos['id'] ?? null) !== null ? $this->motivos->encontrar($regMedico, (int) $datos['id']) : null;
        $registros = $this->registrosDelMedico($contexto['medico']);
        $vuelta = $this->vuelta($datos['buscar'] ?? null, $datos['page'] ?? null);

        try {
            if ($fila === null) {
                $creado = $crear->ejecutar($regMedico, $registros, $datos['descripcion'] ?? null, $datos['monto'] ?? null, $datos['monto_seg'] ?? null);

                return redirect()
                    ->route('clinica.catalogos.motivos-factura')
                    ->with('estado', 'Creaste «' . trim((string) $creado->descripcion) . '» (código ' . trim((string) $creado->codigo) . ').');
            }

            $resultado = $editar->ejecutar($fila, $registros, $datos['descripcion'] ?? null, $datos['monto'] ?? null, $datos['monto_seg'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors([$this->campoDelError($e->getMessage()) => $e->getMessage()]);
        }

        $destino = redirect()->route('clinica.catalogos.motivos-factura', $vuelta);
        $texto = trim((string) $resultado->fila->descripcion);

        if ($resultado->huboConflicto()) {
            return $destino->with('error', 'Otro equipo editó «' . $texto . '» más recientemente y no se pisó: vuelve a abrirlo y revisa los cambios.');
        }

        if ($resultado->sinCambios()) {
            return $destino->with('estado', '«' . $texto . '» no tenía cambios.');
        }

        return $destino->with('estado', 'Guardaste «' . $texto . '».');
    }

    /** Si una pre-factura usa el concepto **no se borra** y el aviso dice en cuántas consultas. */
    public function quitar(Request $request, int $id, QuitarMotivoDeFactura $quitar)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fila = $this->motivos->encontrar((string) $contexto['reg_medico'], $id);

        $vuelta = $this->vuelta(mb_substr(trim((string) $request->input('buscar', '')), 0, 100), $request->input('page'));
        $resultado = $quitar->ejecutar($fila, $this->registrosDelMedico($contexto['medico']));
        $destino = redirect()->route('clinica.catalogos.motivos-factura', $vuelta);

        if (! $resultado->quitado) {
            return $destino->with('error', 'No se quitó «' . $resultado->nombre . '» porque está en ' . $resultado->enUso
                . '. Déjalo en el catálogo o quítalo de esas pre-facturas primero.');
        }

        return $destino->with('estado', 'Quitaste «' . $resultado->nombre . '» del catálogo.');
    }

    /** Cada error se pinta bajo su campo: monto con seguro, monto o nombre. */
    private function campoDelError(string $mensaje): string
    {
        return match (true) {
            str_contains($mensaje, 'con seguro') => 'monto_seg',
            str_contains($mensaje, 'monto') || str_contains($mensaje, 'precio') => 'monto',
            default => 'descripcion',
        };
    }

    private function vuelta(?string $buscar, $pagina): array
    {
        return array_filter([
            'buscar' => $buscar,
            'page' => (int) $pagina > 1 ? (int) $pagina : null,
        ], fn ($valor) => $valor !== null && $valor !== '');
    }
}

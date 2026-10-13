<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Bitacora\CambiosDelConsultorio;
use App\Clinica\Bitacora\FiltroDeBitacora;
use App\Clinica\Bitacora\Vistas\BitacoraVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Services\CuentaService;
use Illuminate\Http\Request;

/**
 * **Bitácora** (WEB-8.5): `/clinica/bitacora`, qué cambió en el consultorio y quién lo hizo. De sólo lectura y **sólo del médico**
 * (el grupo de rutas lleva `role:Root|Medico`: la secretaría recibe 403), acotada a los `reg_medico` del médico del contexto: no
 * ve nunca un cambio de otro. La lectura es `CambiosDelConsultorio` y el texto lo arma `BitacoraVista`; acá sólo se traduce la URL.
 */
class BitacoraController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function index(Request $request, CambiosDelConsultorio $cambios, CuentaService $cuentas)
    {
        $medico = $request->attributes->get('contexto_clinico')['medico'];
        $registros = $this->registrosDelMedico($medico);
        $filtro = FiltroDeBitacora::desde($request->query());

        $pagina = $cambios->pagina($registros, $filtro);

        return view('clinica.bitacora.index', ['vm' => new BitacoraVista(
            $pagina,
            $cambios->usuarios(array_values(array_unique(array_filter(array_column($pagina['grupos'], 'user_id'))))),
            $this->opcionesDeUsuario($cambios, $cuentas, $registros, (int) $medico->user_id),
            $filtro,
            [
                'base' => route('clinica.bitacora'),
                'paciente' => fn (int $id) => route('clinica.pacientes.ver', $id),
                'consulta' => fn (int $id) => route('clinica.consulta', $id),
            ],
        )]);
    }

    /**
     * Las cuentas para el filtro «usuario»: quien aparece en el rastro (aunque ya no tenga acceso), el propio médico y sus
     * secretarias vinculadas (aunque todavía no hayan hecho nada).
     *
     * @param  array<int,string>  $registros
     * @return array<int,array{id:int, nombre:string, rol:string}>
     */
    private function opcionesDeUsuario(CambiosDelConsultorio $cambios, CuentaService $cuentas, array $registros, int $medicoUserId): array
    {
        $opciones = [];

        foreach ($cambios->usuariosDelRastro($registros) as $usuario) {
            $opciones[$usuario['id']] = $usuario;
        }

        $propios = array_values(array_filter(array_merge(
            [$medicoUserId],
            $cuentas->secretariasDe($registros)->pluck('id')->map(fn ($id) => (int) $id)->all(),
        )));

        foreach ($cambios->usuarios($propios) as $id => $usuario) {
            $opciones[$id] ??= ['id' => (int) $id] + $usuario;
        }

        return collect($opciones)->sortBy(fn ($u) => mb_strtolower($u['nombre']))->values()->all();
    }
}

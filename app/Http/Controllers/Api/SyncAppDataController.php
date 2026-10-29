<?php

namespace App\Http\Controllers\Api;

use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CitaNoAtendible;
use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\CrearCita;
use App\Actions\Agenda\ReordenarCola;
use App\Actions\Pacientes\CrearPaciente;
use App\Http\Controllers\Controller;
use App\Http\Requests\SyncAppDataRequest;
use App\Sync\Escritorio\TablasLegado;
use App\Http\Resources\ConfiguracionMedicoResource;
use App\Http\Resources\RecipeFormatoResource;
use App\Models\Antecedente;
use App\Actions\Pacientes\RegistrarMovimientoDeAntecedente;
use App\Models\AntecePaciente;
use App\Models\Cola;
use App\Models\ConstanciaObs;
use App\Models\Consulta;
use App\Models\Diagnostico;
use App\Models\DiagnosticoPaciente;
use App\Models\Doctor;
use App\Models\EcoCardiogramaFetal;
use App\Models\EcoDoppler;
use App\Models\EcoObstetrico;
use App\Models\EcoObstetricoSegundo;
use App\Models\EcoObstetricoTercer;
use App\Models\EcoPelvico;
use App\Models\UltraAbdominal;
use App\Models\UltraMama;
use App\Models\Especialidad;
use App\Models\Evolucion;
use App\Models\ExamenFisico;
use App\Models\FormatoPrint;
use App\Models\Historia;
use App\Models\DietaPaciente;
use App\Models\Hospitalizacion;
use App\Models\Informe;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\MotivoConsulta;
use App\Models\MotivoConsultaPaciente;
use App\Models\Office;
use App\Models\OfficeSchedule;
use App\Models\Paciente;
use App\Models\Recipe;
use App\Models\RecipeFormato;
use App\Models\RecipePareja;
use App\Models\Referencia;
use App\Models\ReposoPaciente;
use App\Models\SyncChange;
use App\Models\SyncCarga;
use App\Models\RecipeGrupo;
use App\Models\RecipeGrupoDetalle;
use App\Models\TipoAntecedente;
use App\Models\Vademecum;
use App\Services\ServicioService;
use App\Actions\Pacientes\AsignarHistoria;
use App\Sync\CargaInicialEnCurso;
use App\Sync\CreacionesClinicas;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sync delta de AppDDR (ver ROADMAP.md "Decisión de arquitectura: sync offline delta"). No
 * reemplaza `/app/refresh-data`. Un solo endpoint para *pull* y *push*:
 *
 * 1. Aplica `changes` (si vienen) contra las tablas editables, con last-write-wins por columna
 *    y la regla de que un eliminado le gana a cualquier update posterior.
 * 2. Calcula el delta usando el `since` que llegó en la petición (el viejo, no el de ahora) —
 *    así la respuesta incluye lo que el cliente acaba de mandar, más lo de terceros.
 *
 * El cliente no necesita saber qué pasó con cada cambio que mandó: siempre sobreescribe su
 * copia local con lo que vuelve acá, gane o pierda su propio cambio el conflicto.
 */
class SyncAppDataController extends Controller
{
    /** Primer cursor del volcado paginado: el app lo manda para pedir el dataset entero de a tramos. */
    public const VOLCADO_INICIO = 'inicio';

    /**
     * `error` y `code` de la respuesta mientras el escritorio sube la carga inicial. El app lo reconoce y
     * **no le muestra nada** al médico: es un «todavía no», no un fallo (ver `SyncService` del app).
     */
    public const EN_CARGA_INICIAL = 'sincronizacion_en_curso';

    /** Lo que el app espera antes de reintentar solo, mientras la carga inicial sigue (segundos). */
    private const SEGUNDOS_PARA_REINTENTAR = 120;

    /** Filas por tramo del volcado paginado (el cliente puede pedir otro con `volcado_limite`). */
    private const FILAS_POR_PAGINA = 3000;

    /** Tope de filas por tramo, para que un cliente no pida una página que no entra en memoria. */
    private const FILAS_POR_PAGINA_TOPE = 10000;

    /**
     * Hasta dónde atrás baja el volcado completo (días). El teléfono no necesita 15 años de historia: lo
     * que necesita es **a quién va a atender**, y eso son las citas de los últimos meses y todas las
     * futuras. Del paciente que sí se atiende en ese período baja la historia **completa** (consultas,
     * documentos y récipes de cualquier fecha), y de los demás sólo su ficha para poder buscarlo.
     *
     * El resto se busca en línea cuando hace falta (ver el ROADMAP): abrir la ficha de un paciente o ir a
     * un día de la agenda anterior a la ventana pide esos datos en ese momento.
     */
    private const DIAS_DE_VENTANA = 60;

    public function sync(SyncAppDataRequest $request)
    {
        // El volcado paginado existe justo para no necesitar esto (ver `paginaDelVolcado`), pero mientras
        // un app viejo siga pidiendo el dataset entero sin `volcado`, acá se corre sin reloj y con más
        // memoria que el default de PHP.
        //
        // El caso medido es el peor de todos: un médico de pediatría con 6.893 pacientes, 24.762 consultas y
        // 24.785 citas (2026-10-14). Con el default (128-256 MB) moría en `json_encode`; con 512 MB también.
        // El `@` es a propósito: si el host tiene `ini_set` deshabilitado, esto no hace nada.
        set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medicoModel = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (!$medicoModel) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        $since = $request->filled('since') ? Carbon::parse($request->input('since')) : null;

        $registrosMedicos = MedicoRegistro::where('medico_id', $medicoModel->id)
            ->pluck('reg_medico')
            ->filter()
            ->toArray();
        if (!empty($medicoModel->reg_medico)) {
            $registrosMedicos[] = $medicoModel->reg_medico;
        }
        $registrosMedicos = array_values(array_unique($registrosMedicos));

        // **El consultorio está subiendo su carga inicial desde el escritorio**: no se acepta ni se
        // devuelve nada hasta que termine. Es un error a propósito (no un 200 vacío) para que el teléfono
        // **conserve su cola de pendientes** en vez de creer que subió lo que no subió, pero con un código
        // propio (`sincronizacion_en_curso`) que el app trata como «todavía no» y no como un fallo: no le
        // muestra nada al médico y reintenta solo. Ver `App\Sync\CargaInicialEnCurso`.
        if ($carga = app(CargaInicialEnCurso::class)->de($registrosMedicos)) {
            return $this->esperandoLaCargaInicial($carga);
        }

        // Servicio contratado: vencido (pasada la gracia) no se sincroniza, ni subir ni bajar. El app sigue
        // funcionando con lo que ya tiene en el teléfono y deja sus cambios en la cola. Un médico que por
        // alguna vía quedó sin ninguna fila recibe aquí su mes de prueba en vez de quedar sin sincronizar.
        $servicio = app(ServicioService::class);
        if ($medicoModel->reg_medico && collect($registrosMedicos)->every(fn ($r) => $servicio->actual($r) === null)) {
            $servicio->otorgarPrueba($medicoModel->reg_medico);
        }
        $estadoServicio = $servicio->mejorEstado($registrosMedicos);
        if ($bloqueo = $servicio->bloqueo($estadoServicio)) {
            return $bloqueo;
        }

        // Los pacientes del médico salen de los **consultorios que trabaja** (`medico_registros`), no de las
        // filas que él mismo vinculó: el vínculo `medico_pacientes` es del tenant —una fila por
        // `(reg_medico, paciente_id)`— desde la Fase 1 del cambio de `reg_medico` (migración del 2026-10-20).
        $relaciones = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)->get();
        $pacienteIds = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
        $numHistorias = $relaciones->pluck('numhistoria')->filter()->unique()->toArray();

        $resultadoChanges = $this->applyChanges(
            $request->cambios(),
            $medicoModel,
            $registrosMedicos,
            $relaciones,
        );

        // Se relee el pivote **después** de aplicar los cambios: un paciente creado en este mismo
        // lote no estaba en `$relaciones` cuando se cargó arriba, y sin esto el delta no se lo
        // devolvería al teléfono — la ficha que el usuario acaba de crear desaparecería de su
        // pantalla en la primera sincronización.
        // Lo mismo con una historia recién llenada: sin releer, el número nuevo no estaría en
        // `$numHistorias` y la consulta y el récipe que colgaron de ella no bajarían.
        if ($resultadoChanges['creo_pacientes'] || $resultadoChanges['creo_clinicas']) {
            $relaciones = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)->get();
            $pacienteIds = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
            $numHistorias = $relaciones->pluck('numhistoria')->filter()->unique()->toArray();
        }

        $syncedAt = Carbon::now();

        // **Volcado paginado** (opt-in): el app pide el dataset entero de a un tramo —una tabla y un tramo
        // de filas por pedido— en vez de una sola respuesta con todo. Es lo que evita quedarse sin memoria
        // con un médico de años de historia: el 2026-10-14 el volcado de un pediatra (6.893 pacientes,
        // 24.762 consultas y 24.785 citas) murió en `json_encode` con 512 MB de PHP.
        //
        // Es opt-in a propósito: sin `volcado` todo sigue igual que siempre, así que un app ya instalado
        // (que no conoce el paginado) recibe el volcado completo de una sola vez como hasta hoy.
        if (($cursor = $request->input('volcado')) !== null) {
            return $this->paginaDelVolcado(
                $request, $medicoModel, $registrosMedicos, $pacienteIds, $numHistorias, $estadoServicio,
                $syncedAt, $resultadoChanges, (string) $cursor,
            );
        }

        // **Pedidos puntuales** (2026-10-14): como el volcado sólo trae la historia completa de los
        // pacientes de la ventana (los 60 días), el app pide en línea lo que le falta —la ficha de un
        // paciente o las citas de un día viejo de la agenda— y lo guarda en su copia. Son chicos y van
        // enteros: no se paginan.
        if ($request->filled('volcado_paciente')) {
            return $this->volcadoDeUnPaciente(
                $medicoModel, $registrosMedicos, $estadoServicio, $syncedAt, $resultadoChanges,
                (int) $request->input('volcado_paciente'),
            );
        }
        if ($request->filled('volcado_agenda')) {
            return $this->volcadoDeLaAgenda(
                $medicoModel, $registrosMedicos, $estadoServicio, $syncedAt, $resultadoChanges,
                (string) $request->input('volcado_agenda'),
            );
        }

        // Todo lo que baja el app, acotado a este médico, en **una sola definición**: la usan el delta de
        // siempre y el volcado paginado (el volcado manda lo mismo, sólo que de a tramos).
        $plan = $this->planDelVolcado($medicoModel, $registrosMedicos, $pacienteIds, $numHistorias, $since === null, []);

        // Estas tres se leen primero porque de ellas salen los centros médicos que viajan.
        $historias = $this->deltaQuery($plan['historias']['consulta'], $since)->get();
        $colas = $this->deltaQuery($plan['colas']['consulta'], $since)->get();
        $offices = $this->deltaQuery($plan['offices']['consulta'], $since)->get();

        // Los centros que necesita el teléfono son los de las historias **y** los de las sedes
        // donde atiende: sin esto, una sede recién configurada llegaría sin nombre ni dirección.
        $plan['centros_medicos']['consulta'] = MedicalCenter::whereIn('id', $historias->pluck('medical_center_id')
            ->merge($offices->pluck('medical_center_id'))
            ->merge($colas->pluck('medical_center_id'))
            ->filter()
            ->unique()
            ->toArray());

        $datos = ['historias' => $historias, 'colas' => $colas, 'offices' => $offices];
        foreach ($plan as $clave => $entrada) {
            if (! array_key_exists($clave, $datos)) {
                $datos[$clave] = $this->deltaQuery($entrada['consulta'], $since)->get();
            }
        }

        // Sin `since` (primera sincronización) el cliente no tiene nada que borrar todavía.
        $eliminados = $since
            ? SyncChange::where('operation', 'deleted')
                ->whereIn('reg_medico', $registrosMedicos)
                ->where('occurred_at', '>', $since)
                ->get(['table_name', 'record_id', 'occurred_at'])
            : collect([]);

        return response()->json($this->cuerpoDeLaRespuesta(
            $datos, $syncedAt, $medicoModel, $registrosMedicos, $estadoServicio, $resultadoChanges, $eliminados,
        ));
    }

    /**
     * La respuesta mientras el escritorio está volcando la base del consultorio.
     *
     * Es un **409 con código propio**, no un 200 vacío: si fuera un 200 el app creería que sincronizó y
     * vaciaría su cola de pendientes sin que el servidor haya guardado nada. Con un 4xx el teléfono
     * conserva todo lo que hizo y vuelve a intentar. `Retry-After` es para cualquier cliente HTTP genérico
     * (y para el log del escritorio): el app usa `reintentar_en`.
     */
    private function esperandoLaCargaInicial(SyncCarga $carga)
    {
        $mensaje = 'El consultorio está subiendo su primera sincronización desde el escritorio. '
            . 'Vuelve a intentar cuando termine: lo que hiciste en el teléfono no se pierde.';

        return response()->json([
            'ok'                    => false,
            'error'                 => self::EN_CARGA_INICIAL,
            'code'                  => self::EN_CARGA_INICIAL,
            'mensaje'               => $mensaje,
            'message'               => $mensaje,
            'reintentar_en'         => self::SEGUNDOS_PARA_REINTENTAR,
            'carga_iniciada_at'     => optional($carga->iniciada_at)->toIso8601String(),
            'minutos_transcurridos' => $carga->iniciada_at
                ? (int) max(0, now()->diffInMinutes($carga->iniciada_at))
                : null,
        ], 409)->header('Retry-After', (string) self::SEGUNDOS_PARA_REINTENTAR);
    }

    /**
     * Las consultas de **todo** lo que baja el app, por clave de la respuesta, ya acotadas a este médico.
     *
     * Es la definición única del volcado: la usan el delta de siempre y el volcado paginado, así que una
     * tabla nueva o un filtro nuevo se agregan en un solo lugar (si se escribieran dos veces, el delta y el
     * volcado terminarían mandando cosas distintas).
     *
     * @param  bool  $completo  sin `since`: es la primera sincronización (y entonces se aplican los filtros
     *                          que sólo tienen sentido con el dataset entero, como no mandar las plantillas
     *                          vacías de los documentos)
     * @param  array|null  $idsCentros  ids de los centros médicos a mandar; `null` los resuelve con una
     *                                  consulta (el volcado paginado); el delta pasa los suyos, que salen de
     *                                  las filas que está mandando
     * @return array<string, array{consulta: \Illuminate\Database\Eloquent\Builder, columna: string}>
     */
    private function planDelVolcado(
        Medico $medicoModel,
        array $registrosMedicos,
        array $pacienteIds,
        array $numHistorias,
        bool $completo,
        ?array $idsCentros = null,
        bool $conVentana = false,
    ): array {
        // Ventana del volcado: de acá para atrás no viaja lo clínico de los pacientes que no se atienden
        // (ver `DIAS_DE_VENTANA`). Sólo la aplica el volcado paginado, que es el app nuevo: un app viejo
        // —que manda el volcado entero sin `volcado`— sigue recibiendo todo, como hasta hoy.
        $desde = Carbon::now()->subDays(self::DIAS_DE_VENTANA);
        // Los números de historia de los pacientes que se atienden en la ventana. **La cita los tiene en
        // `cola.numhistoria`**: el `nrohistoria` de `cola` es una columna del legado que no lleva el número
        // (medido el 2026-10-14 contra el servidor real: usarla dejaba pasar 49.955 récipes que no eran de
        // la ventana). Cada tabla clínica después filtra por **su** columna, que no siempre se llama igual.
        $historiasDeLaVentana = fn () => Cola::whereIn('reg_medico', $registrosMedicos)
            ->where('fecha', '>=', $desde->toDateString())
            ->whereNotNull('numhistoria')
            ->select('numhistoria')
            ->distinct();

        // Documentos de la consulta que el app solo imprime (Paso 25): los carga el escritorio y bajan
        // por delta, con el mismo filtro doble que `consultas`. `constancia_obs` nombra sus columnas
        // `numhistoria`/`numconsulta`; las demás, `nrohistoria`/`nroconsulta`.
        //
        // Al abrir cada consulta, el legado crea filas plantilla (constancia sin observación, informe
        // con el texto ".", reposo de 1 día sin observación): son casi todas las filas y no son
        // documentos. La constancia se imprime igual en cualquier consulta (su texto es fijo); de
        // `constancia_obs` solo hace falta la observación. En la descarga
        // completa no se mandan (con datos reales, ~13 MB menos); por delta se manda todo, así el
        // teléfono se entera si un documento se vació, y el app aplica la misma regla al mostrar.
        $porConsulta = fn (string $modelo, string $tablaHistoria, ?\Closure $conContenido = null) => $modelo::whereIn(TablasLegado::columnaHistoria($tablaHistoria), $numHistorias)
            ->whereIn('reg_medico', $registrosMedicos)
            ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria($tablaHistoria), $historiasDeLaVentana()))
            ->when($completo && $conContenido !== null, $conContenido);

        return [
            'pacientes' => ['consulta' => Paciente::whereIn('id', $pacienteIds), 'columna' => 'id'],
            // Solo las historias **de este médico**: por su `medico_id` o por sus registros. Antes también
            // entraban las que tuvieran un número del pivote, pero el número es por médico desde el Paso
            // 18.B1 (cada instalación del legado arranca en 1), así que un médico recibía las historias de
            // cualquier otro con el mismo número, y el teléfono las mezclaba con las suyas. Una historia sin
            // `medico_id` ni `reg_medico` no es de nadie y no se manda (no hay ninguna en los datos
            // cargados).
            'historias' => ['consulta' => Historia::where(function ($query) use ($medicoModel, $registrosMedicos) {
                $query->where('medico_id', $medicoModel->id);
                if (!empty($registrosMedicos)) {
                    $query->orWhereIn('reg_medico', $registrosMedicos);
                }
            }), 'columna' => 'id'],
            'colas' => ['consulta' => Cola::whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->where('fecha', '>=', $desde->toDateString())), 'columna' => 'id'],
            // Por número de historia **y** por registro: el correlativo es por médico (Paso 18.B), así que
            // la historia 1 de otro médico es otro paciente y sus consultas no pueden bajar a este teléfono.
            'consultas' => ['consulta' => Consulta::whereIn('numhistoria', $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn('numhistoria', $historiasDeLaVentana())), 'columna' => 'id'],
            // **La evaluación física de la consulta** (A2 de PLAN-MOVIL.md). Es lo que el médico necesita
            // para atender sin la computadora, así que baja con el mismo filtro doble que `consultas`.
            // ⚠️ Dos nombres de columna que no son los que parecen, y por eso se preguntan:
            //  - el número de historia es **`historia`**, no `numhistoria` (el manifiesto de la especialidad
            //    traduce el `nrohistoria` del escritorio; ver `TablasLegado::columnaHistoria`). Escribirlo a
            //    mano deja pasar todo o nada según la especialidad.
            //  - `consulta` es **el número de la consulta dentro de la historia** (`consultas.nroconsulta`),
            //    no el id de `consultas`: por eso el filtro es por historia (y por médico), como el resto.
            'examen_fisico_nuevo' => ['consulta' => ExamenFisico::whereIn(TablasLegado::columnaHistoria('examen_fisico_nuevo'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('examen_fisico_nuevo'), $historiasDeLaVentana())), 'columna' => 'id'],
            // **Los cuatro estudios ecográficos** (EC-A2 de PLAN-MOVIL.md, «ecos en el bolsillo»): lo que
            // el médico mira antes de atender, así que bajan con el mismo filtro doble que `consultas` —por
            // número de historia y por `reg_medico`, porque el número es por médico— más la ventana del
            // volcado paginado.
            //
            // ⚠️ La trampa del paso son los nombres de las columnas: el eco **pélvico** se llavea por
            // `numhistoria` (el `key=yes` del DataWindow `d_eco_pelvico`) y los **tres obstétricos** por
            // `historia`, como `examen_fisico_nuevo`. Por eso la columna se pregunta a `TablasLegado` y no
            // se escribe acá: escrita a mano, el filtro de las obstétricas pediría `numhistoria` —que no
            // existe en esas tablas— y no bajaría nada.
            'eco_pelvico' => ['consulta' => EcoPelvico::whereIn(TablasLegado::columnaHistoria('eco_pelvico'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('eco_pelvico'), $historiasDeLaVentana())), 'columna' => 'id'],
            'eco_obstetrico' => ['consulta' => EcoObstetrico::whereIn(TablasLegado::columnaHistoria('eco_obstetrico'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('eco_obstetrico'), $historiasDeLaVentana())), 'columna' => 'id'],
            // 2.º trimestre: en el escritorio compartía tabla con el 3.º (cargar uno pisaba al otro); desde
            // WEB-11.14 la web los separa en `eco_obstetrico_tercer_2`, con las mismas columnas.
            'eco_obstetrico_tercer_2' => ['consulta' => EcoObstetricoSegundo::whereIn(TablasLegado::columnaHistoria('eco_obstetrico_tercer_2'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('eco_obstetrico_tercer_2'), $historiasDeLaVentana())), 'columna' => 'id'],
            'eco_obstetrico_tercer' => ['consulta' => EcoObstetricoTercer::whereIn(TablasLegado::columnaHistoria('eco_obstetrico_tercer'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('eco_obstetrico_tercer'), $historiasDeLaVentana())), 'columna' => 'id'],
            // **Los otros cuatro estudios** (ola §10 de PLAN-MOVIL.md, 2026-10-19): Doppler perinatal,
            // mamario, abdominal y ecocardiograma fetal. Como los obstétricos, se llavean por `historia` +
            // `consulta` y bajan con el mismo filtro doble más la ventana. ⚠️ La tabla del ecocardiograma se
            // llama `ecocadiograma_fetal` en el legado (con el typo): así la usa la web y así la sube el
            // escritorio; escribirla «bien» dejaría el estudio sin bajar.
            'eco_doppler' => ['consulta' => EcoDoppler::whereIn(TablasLegado::columnaHistoria('eco_doppler'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('eco_doppler'), $historiasDeLaVentana())), 'columna' => 'id'],
            'ultra_mama' => ['consulta' => UltraMama::whereIn(TablasLegado::columnaHistoria('ultra_mama'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('ultra_mama'), $historiasDeLaVentana())), 'columna' => 'id'],
            'ultra_abdominal' => ['consulta' => UltraAbdominal::whereIn(TablasLegado::columnaHistoria('ultra_abdominal'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('ultra_abdominal'), $historiasDeLaVentana())), 'columna' => 'id'],
            'ecocadiograma_fetal' => ['consulta' => EcoCardiogramaFetal::whereIn(TablasLegado::columnaHistoria('ecocadiograma_fetal'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('ecocadiograma_fetal'), $historiasDeLaVentana())), 'columna' => 'id'],
            'motivos' => ['consulta' => MotivoCita::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            // Motivos de **consulta** (Paso 18.B2), que no son los de la cita: el catálogo del médico y los
            // de cada consulta, con el mismo filtro doble que `consultas`. Los carga el escritorio; el app
            // los lee y puede agregar o quitar los de la consulta que está atendiendo.
            'motivos_consulta' => ['consulta' => MotivoConsulta::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            'motivo_consulta_paciente' => ['consulta' => MotivoConsultaPaciente::whereIn('nrohistoria', $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn('nrohistoria', $historiasDeLaVentana())), 'columna' => 'id'],
            // Los récipes se crean con una creación `recipes` (Paso 18.B), no con `updated` por fila. Mismo
            // filtro doble que `consultas`.
            'recipes' => ['consulta' => Recipe::whereIn('nrohistoria', $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn('nrohistoria', $historiasDeLaVentana())), 'columna' => 'id'],
            // **El récipe de la pareja** (ola §9 de PLAN-MOVIL.md): una por consulta, sin cabecera y sin
            // columna `recipe`; cada fila es un medicamento y repite la cédula y el nombre de la pareja. Se
            // llavea por `nrohistoria` + `nroconsulta`, así que baja con el mismo filtro doble que `recipes`.
            // La columna del número de historia se pregunta a `TablasLegado` porque esta tabla la llama
            // `nrohistoria` y no `numhistoria`.
            'recipes_pareja' => ['consulta' => RecipePareja::whereIn(TablasLegado::columnaHistoria('recipes_pareja'), $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn(TablasLegado::columnaHistoria('recipes_pareja'), $historiasDeLaVentana())), 'columna' => 'id'],
            // El nombre del medicamento de un récipe sale de acá (`recipes.descripcion` viene NULL en
            // los datos reales del legado). Solo lectura por ahora, igual que `recipes`.
            'vademecum' => ['consulta' => Vademecum::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            // Tratamientos (plantillas de récipe): cabecera y medicamentos, solo lectura como el vademécum.
            'tratamientos' => ['consulta' => RecipeGrupo::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            'tratamientos_detalle' => ['consulta' => RecipeGrupoDetalle::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            // **Las plantillas de texto del médico** (`formato_print`, ola §11 de PLAN-MOVIL.md): la
            // enfermedad actual (`EA`), la evolución (`EV`) y el tratamiento (`TR`) se escriben en el
            // teléfono y el médico quiere partir de su plantilla, como hace la web (WEB-8.4). Bajan enteras
            // —son pocas filas— y son **espejo de solo lectura**: crearlas o editarlas desde el app queda
            // para otra salida; acá sólo se usan.
            'formato_print' => ['consulta' => FormatoPrint::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            'constancia_obs' => ['consulta' => $porConsulta(ConstanciaObs::class, 'constancia_obs', fn ($q) => $q
                ->whereRaw("TRIM(COALESCE(observacion01, '')) <> ''")), 'columna' => 'id'],
            // Un reposo real de 1 día sin observación no se distingue de la plantilla: queda afuera
            // (decisión del usuario, 2026-09-27).
            'reposo_paciente' => ['consulta' => $porConsulta(ReposoPaciente::class, 'reposo_paciente', fn ($q) => $q->where(fn ($q) => $q
                ->where('numdias', '>', 1)
                ->orWhereRaw("TRIM(COALESCE(obser_reposo, '')) <> ''"))), 'columna' => 'id'],
            'referencia' => ['consulta' => $porConsulta(Referencia::class, 'referencia'), 'columna' => 'id'],
            'informe' => ['consulta' => $porConsulta(Informe::class, 'informe', fn ($q) => $q
                ->whereRaw("TRIM(COALESCE(descripcion, '')) NOT IN ('', '.')")), 'columna' => 'id'],
            'hospitalizacion' => ['consulta' => $porConsulta(Hospitalizacion::class, 'hospitalizacion'), 'columna' => 'id'],
            'dieta_paciente' => ['consulta' => $porConsulta(DietaPaciente::class, 'dieta_paciente'), 'columna' => 'id'],
            'diagnostico_paciente' => ['consulta' => $porConsulta(DiagnosticoPaciente::class, 'diagnostico_paciente'), 'columna' => 'id'],
            // **Los antecedentes del paciente** (A4b de PLAN-MOVIL.md). Son del paciente y no de la
            // consulta: se llavean por `numhistoria` + `codeantecedente` y no tienen columna de consulta,
            // así que bajan con el mismo filtro doble que `consultas` (por número de historia y por
            // `reg_medico`, porque el número es por médico) más la ventana del volcado. La columna del
            // número es `numhistoria` (la tabla del legado lo llama así; no hace falta preguntarla).
            'antece_paciente' => ['consulta' => AntecePaciente::whereIn('numhistoria', $numHistorias)
                ->whereIn('reg_medico', $registrosMedicos)
                ->when($conVentana, fn ($q) => $q->whereIn('numhistoria', $historiasDeLaVentana())), 'columna' => 'id'],
            // Catálogos que esos documentos necesitan para imprimirse: la descripción del diagnóstico
            // (constancia), el médico al que se refiere y su especialidad (referencia).
            'diagnosticos' => ['consulta' => Diagnostico::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            'doctores' => ['consulta' => Doctor::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            'especial' => ['consulta' => Especialidad::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            // **El catálogo de antecedentes y el de sus tipos** (A4b): el teléfono los necesita para
            // ofrecer el antecedente que se agrega y para agrupar los del paciente igual que la web. Los
            // dos son por médico —el catálogo es de su consultorio— y bajan enteros (los repetidos del
            // legado no importan acá: `AntecedentesDelPaciente` es quien deduplica al mostrarlos).
            'antecedentes' => ['consulta' => Antecedente::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            'tipo_antecedente' => ['consulta' => TipoAntecedente::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            // Dónde atiende el médico: los consultorios con sus bloques de trabajo. Es lo que le
            // permite al app saber en qué jornada cae una cita, con qué modalidad se trabaja en esa
            // sede y cuándo se llegó al cupo — todo se calcula con la hora, sin guardar la office en
            // la cita (ver la migración de `cola.medical_center_id`).
            //
            // Todas las sedes del **tenant**, no solo las del médico logueado (Paso 22.C): quien
            // sincroniza puede agendar para cualquier médico del mismo consultorio, así que necesita
            // ver dónde atiende cada uno. `Office.medico_id` sigue distinguiendo de quién es cada
            // sede (o ninguno, si es compartida) — eso lo resuelve el cliente al elegir médico.
            'offices' => ['consulta' => Office::whereIn('reg_medico', $registrosMedicos), 'columna' => 'id'],
            'office_schedules' => ['consulta' => OfficeSchedule::whereIn(
                'office_id',
                Office::whereIn('reg_medico', $registrosMedicos)->select('id'),
            ), 'columna' => 'id'],
            // Los centros médicos salen de las historias y de las sedes. En el delta se pasan los ids de
            // las filas que se están mandando; en el volcado se resuelven con una consulta.
            'centros_medicos' => ['consulta' => MedicalCenter::whereIn('id',
                $idsCentros ?? $this->idsDeCentrosDeEsteMedico($medicoModel, $registrosMedicos)), 'columna' => 'id'],
        ];
    }

    /**
     * Los centros médicos que le sirven a este médico: los de sus historias, los de sus sedes y los de sus
     * citas. Es lo que usa el volcado paginado (el delta los saca de las filas que ya tiene en la mano).
     *
     * @return array<int, int>
     */
    private function idsDeCentrosDeEsteMedico(Medico $medicoModel, array $registrosMedicos): array
    {
        $historias = Historia::where(function ($query) use ($medicoModel, $registrosMedicos) {
            $query->where('medico_id', $medicoModel->id);
            if (!empty($registrosMedicos)) {
                $query->orWhereIn('reg_medico', $registrosMedicos);
            }
        })->whereNotNull('medical_center_id')->distinct()->pluck('medical_center_id');

        return $historias
            ->merge(Office::whereIn('reg_medico', $registrosMedicos)->whereNotNull('medical_center_id')
                ->distinct()->pluck('medical_center_id'))
            ->merge(Cola::whereIn('reg_medico', $registrosMedicos)->whereNotNull('medical_center_id')
                ->distinct()->pluck('medical_center_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Una página del volcado paginado: **una** tabla y un tramo de sus filas.
     *
     * El cursor es `"<índice de la tabla>:<último id mandado>"`, y `inicio` arranca el volcado. El servidor
     * es el que sabe el orden de las tablas (el plan): el cliente sólo devuelve el cursor tal como lo
     * recibió. Cuando una tabla se termina, el cursor salta a la siguiente en la misma respuesta, y al
     * terminar la última devuelve `null`.
     *
     * La memoria de cada pedido es la de un tramo, no la del volcado: es lo que hace que un médico con
     * años de historia pueda bajar todo sin que el servidor ni el teléfono se queden sin RAM.
     */
    private function paginaDelVolcado(
        SyncAppDataRequest $request,
        Medico $medicoModel,
        array $registrosMedicos,
        array $pacienteIds,
        array $numHistorias,
        ?array $estadoServicio,
        Carbon $syncedAt,
        array $resultadoChanges,
        string $cursor,
    ) {
        $plan = $this->planDelVolcado($medicoModel, $registrosMedicos, $pacienteIds, $numHistorias, true, null, true);
        $claves = array_keys($plan);

        if ($cursor === self::VOLCADO_INICIO) {
            $indice = 0;
            $desde = 0;
        } else {
            $partes = explode(':', $cursor);
            $valido = count($partes) === 2 && ctype_digit($partes[0]) && ctype_digit($partes[1])
                && (int) $partes[0] < count($claves);
            if (! $valido) {
                return response()->json(['message' => 'El cursor del volcado no es válido.'], 400);
            }
            $indice = (int) $partes[0];
            $desde = (int) $partes[1];
        }

        $limite = (int) $request->input('volcado_limite', self::FILAS_POR_PAGINA);
        $limite = min(self::FILAS_POR_PAGINA_TOPE, max(1, $limite));

        // El cupo de filas del pedido se reparte entre **varias tablas**: un pedido lleva tantas tablas
        // como entren en `$limite` filas. Sin esto, cada tabla costaba un pedido propio —y las vacías o
        // chicas también—, que es lo que hacía que la carga inicial fueran decenas de micro-pedidos.
        $datos = [];
        $filas = 0;
        $siguiente = null;

        while ($indice < count($claves)) {
            $clave = $claves[$indice];
            $restante = $limite - $filas;

            if ($restante <= 0) {
                // Se acabó el cupo justo al terminar una tabla: el cursor la deja lista para empezar.
                $siguiente = $indice . ':' . $desde;
                break;
            }

            $pagina = $plan[$clave]['consulta']
                ->where($plan[$clave]['columna'], '>', $desde)
                ->orderBy($plan[$clave]['columna'])
                ->limit($restante)
                ->get();

            $datos[$clave] = $pagina;
            $filas += $pagina->count();

            // Vino todo lo que se le pidió: la tabla puede tener más, así que el cursor sigue en ella desde
            // el último id mandado (y el pedido termina acá, para no pasarse del cupo).
            if ($pagina->count() >= $restante) {
                $siguiente = $indice . ':' . $pagina->last()->{$plan[$clave]['columna']};
                break;
            }

            // La tabla se terminó: sigue la próxima desde su primer id.
            $indice++;
            $desde = 0;
            $siguiente = $indice < count($claves) ? $indice . ':0' : null;
        }

        return response()->json($this->cuerpoDeLaRespuesta(
            $datos, $syncedAt, $medicoModel, $registrosMedicos, $estadoServicio,
            $resultadoChanges, collect([]), $siguiente, Carbon::now()->subDays(self::DIAS_DE_VENTANA)->toDateString(),
        ));
    }

    /**
     * El cuerpo del JSON de `/app/sync-app-data`, con **todas** sus claves: las que no vinieron en esta
     * respuesta van vacías (el app recorre una lista fija y una tabla vacía no hace nada).
     *
     * Es uno solo para el delta y para el volcado paginado: así el teléfono recibe exactamente la misma
     * forma de respuesta en los dos caminos.
     */
    /**
     * Todo lo clínico de **un** paciente, sin importar la ventana: sus citas, consultas, motivos, récipes y
     * documentos. Es lo que el app pide al abrir una ficha cuyo historial no bajó completo, y es la red de
     * seguridad de la ventana del volcado.
     *
     * El paciente se identifica por su **número de historia** (`numhistoria`), que es el que el app tiene en
     * la mano — y que es **por médico**: antes de mandar nada se comprueba que la historia sea de este
     * médico, así que pedir el número de otro devuelve 404 y no sus datos.
     */
    private function volcadoDeUnPaciente(
        Medico $medicoModel,
        array $registrosMedicos,
        ?array $estadoServicio,
        Carbon $syncedAt,
        array $resultadoChanges,
        int $numHistoria,
    ) {
        $historias = Historia::where(function ($query) use ($medicoModel, $registrosMedicos) {
            $query->where('medico_id', $medicoModel->id);
            if (! empty($registrosMedicos)) {
                $query->orWhereIn('reg_medico', $registrosMedicos);
            }
        })->where('numhistoria', $numHistoria)->get();

        if ($historias->isEmpty()) {
            return response()->json(['message' => 'Ese paciente no es de este médico.'], 404);
        }

        // La columna del número de historia no se llama igual en todas las tablas (TablasLegado): acá se
        // pregunta, en vez de escribir el nombre a mano.
        $de = fn (string $tabla) => TablasLegado::columnaHistoria($tabla);
        $colas = Cola::whereIn('reg_medico', $registrosMedicos)->where($de('cola'), $numHistoria)->get();

        $datos = [
            'pacientes' => Paciente::whereIn('id', $historias->pluck('paciente_id')->filter()->unique()->all())->get(),
            'historias' => $historias,
            'colas' => $colas,
            'consultas' => Consulta::whereIn('reg_medico', $registrosMedicos)->where($de('consultas'), $numHistoria)->get(),
            'motivo_consulta_paciente' => MotivoConsultaPaciente::whereIn('reg_medico', $registrosMedicos)
                ->where($de('motivo_consulta_paciente'), $numHistoria)->get(),
            'recipes' => Recipe::whereIn('reg_medico', $registrosMedicos)->where($de('recipes'), $numHistoria)->get(),
            // El récipe de la pareja de la consulta (ola §9): se suma acá por el mismo motivo que el examen
            // físico y los ecos —este pedido arma su payload a mano—; sin esta línea, la ficha de un paciente
            // fuera de la ventana llegaba con sus récipes pero sin la pareja.
            'recipes_pareja' => RecipePareja::whereIn('reg_medico', $registrosMedicos)
                ->where($de('recipes_pareja'), $numHistoria)->get(),
            // Los documentos, con la misma regla que el volcado: las plantillas vacías del legado no viajan.
            'constancia_obs' => ConstanciaObs::whereIn('reg_medico', $registrosMedicos)->where($de('constancia_obs'), $numHistoria)
                ->whereRaw("TRIM(COALESCE(observacion01, '')) <> ''")->get(),
            'reposo_paciente' => ReposoPaciente::whereIn('reg_medico', $registrosMedicos)->where($de('reposo_paciente'), $numHistoria)
                ->where(fn ($q) => $q->where('numdias', '>', 1)->orWhereRaw("TRIM(COALESCE(obser_reposo, '')) <> ''"))->get(),
            'referencia' => Referencia::whereIn('reg_medico', $registrosMedicos)->where($de('referencia'), $numHistoria)->get(),
            'informe' => Informe::whereIn('reg_medico', $registrosMedicos)->where($de('informe'), $numHistoria)
                ->whereRaw("TRIM(COALESCE(descripcion, '')) NOT IN ('', '.')")->get(),
            'hospitalizacion' => Hospitalizacion::whereIn('reg_medico', $registrosMedicos)->where($de('hospitalizacion'), $numHistoria)->get(),
            'dieta_paciente' => DietaPaciente::whereIn('reg_medico', $registrosMedicos)->where($de('dieta_paciente'), $numHistoria)->get(),
            'diagnostico_paciente' => DiagnosticoPaciente::whereIn('reg_medico', $registrosMedicos)
                ->where($de('diagnostico_paciente'), $numHistoria)->get(),
            // La evaluación física de la consulta (A4a de PLAN-MOVIL.md). Se sumó acá porque este pedido
            // arma su payload a mano y no pasa por el plan del volcado: sin ella, la ficha de un paciente
            // fuera de la ventana llegaba con la consulta y sus récipes pero **sin el examen**, y el médico
            // no podía ver lo que ya estaba cargado.
            'examen_fisico_nuevo' => ExamenFisico::whereIn('reg_medico', $registrosMedicos)
                ->where($de('examen_fisico_nuevo'), $numHistoria)->get(),
            // Y los antecedentes del paciente (son del paciente, no de la consulta: se llavean sólo por
            // `numhistoria`). Viajan con el mismo filtro por registro y por número que el resto.
            'antece_paciente' => AntecePaciente::whereIn('reg_medico', $registrosMedicos)
                ->where($de('antece_paciente'), $numHistoria)->get(),
            // Los cuatro estudios ecográficos (EC-A2 de PLAN-MOVIL.md). Se suman acá por el mismo motivo
            // que el examen físico: este pedido arma su payload a mano y no pasa por el plan del volcado,
            // así que sin estas líneas la ficha de un paciente **fuera de la ventana** llegaba con su
            // consulta pero sin sus ecos. La columna del número de historia se pregunta a `TablasLegado`
            // (`numhistoria` en el pélvico, `historia` en los obstétricos).
            'eco_pelvico' => EcoPelvico::whereIn('reg_medico', $registrosMedicos)
                ->where($de('eco_pelvico'), $numHistoria)->get(),
            'eco_obstetrico' => EcoObstetrico::whereIn('reg_medico', $registrosMedicos)
                ->where($de('eco_obstetrico'), $numHistoria)->get(),
            'eco_obstetrico_tercer_2' => EcoObstetricoSegundo::whereIn('reg_medico', $registrosMedicos)
                ->where($de('eco_obstetrico_tercer_2'), $numHistoria)->get(),
            'eco_obstetrico_tercer' => EcoObstetricoTercer::whereIn('reg_medico', $registrosMedicos)
                ->where($de('eco_obstetrico_tercer'), $numHistoria)->get(),
            // Los cuatro de la ola §10: se suman acá por el mismo motivo que los anteriores —este pedido
            // arma el payload a mano—, para que la ficha de un paciente fuera de la ventana llegue completa.
            'eco_doppler' => EcoDoppler::whereIn('reg_medico', $registrosMedicos)
                ->where($de('eco_doppler'), $numHistoria)->get(),
            'ultra_mama' => UltraMama::whereIn('reg_medico', $registrosMedicos)
                ->where($de('ultra_mama'), $numHistoria)->get(),
            'ultra_abdominal' => UltraAbdominal::whereIn('reg_medico', $registrosMedicos)
                ->where($de('ultra_abdominal'), $numHistoria)->get(),
            'ecocadiograma_fetal' => EcoCardiogramaFetal::whereIn('reg_medico', $registrosMedicos)
                ->where($de('ecocadiograma_fetal'), $numHistoria)->get(),
            'centros_medicos' => MedicalCenter::whereIn('id', $colas->pluck('medical_center_id')->filter()->unique()->all())->get(),
            // Las plantillas de texto del médico (ola §11 de PLAN-MOVIL.md): son del médico y no del
            // paciente, pero el app recorre una lista fija de tablas en cada respuesta y sin esta clave el
            // pedido de una ficha las dejaría como estaban (no las borra) — y el comentario de arriba pide
            // que toda clave exista siempre.
            'formato_print' => FormatoPrint::whereIn('reg_medico', $registrosMedicos)->get(),
        ];

        return response()->json($this->cuerpoDeLaRespuesta(
            $datos, $syncedAt, $medicoModel, $registrosMedicos, $estadoServicio, $resultadoChanges, collect([]),
        ));
    }

    /**
     * Las citas de un rango de días (`volcado_agenda=2026-03-10` o `2026-03-10,2026-03-20`), para cuando el
     * médico navega la agenda a un día anterior a la ventana.
     *
     * No hace falta nada más: los catálogos, las sedes y las fichas ya están en la copia local, y las citas
     * que llegan acá son citas **normales** —se pueden confirmar, cobrar y atender—, no una vista de sólo
     * lectura.
     */
    private function volcadoDeLaAgenda(
        Medico $medicoModel,
        array $registrosMedicos,
        ?array $estadoServicio,
        Carbon $syncedAt,
        array $resultadoChanges,
        string $rango,
    ) {
        $partes = array_map('trim', explode(',', $rango));
        try {
            $desde = Carbon::parse($partes[0])->startOfDay();
            $hasta = isset($partes[1]) && $partes[1] !== ''
                ? Carbon::parse($partes[1])->endOfDay()
                : $desde->copy()->endOfDay();
        } catch (\Throwable $e) {
            return response()->json(['message' => 'El rango de fechas no es válido.'], 400);
        }

        // Tope de un mes por pedido: el app pide por día o por semana, y así una fecha mal armada no se
        // lleva años de citas en una sola respuesta.
        if ($hasta->lessThan($desde) || $desde->diffInDays($hasta) > 31) {
            return response()->json(['message' => 'El rango de fechas no es válido.'], 400);
        }

        $colas = Cola::whereIn('reg_medico', $registrosMedicos)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();

        return response()->json($this->cuerpoDeLaRespuesta(
            [
                'colas' => $colas,
                'centros_medicos' => MedicalCenter::whereIn('id', $colas->pluck('medical_center_id')->filter()->unique()->all())->get(),
            ],
            $syncedAt, $medicoModel, $registrosMedicos, $estadoServicio, $resultadoChanges, collect([]),
        ));
    }

    private function cuerpoDeLaRespuesta(
        array $datos,
        Carbon $syncedAt,
        Medico $medicoModel,
        array $registrosMedicos,
        ?array $estadoServicio,
        array $resultadoChanges,
        $eliminados,
        ?string $volcadoSiguiente = null,
        ?string $ventanaDesde = null,
    ): array {
        $vacio = collect([]);

        return [
            'synced_at' => $syncedAt->toIso8601String(),
            'pacientes' => $datos['pacientes'] ?? $vacio,
            'historias' => $datos['historias'] ?? $vacio,
            'colas' => $datos['colas'] ?? $vacio,
            'consultas' => $datos['consultas'] ?? $vacio,
            'motivos' => $datos['motivos'] ?? $vacio,
            'motivos_consulta' => $datos['motivos_consulta'] ?? $vacio,
            'motivo_consulta_paciente' => $datos['motivo_consulta_paciente'] ?? $vacio,
            'recipes' => $datos['recipes'] ?? $vacio,
            // El récipe de la pareja (ola §9 de PLAN-MOVIL.md). El delta y el volcado lo arman desde `$plan`;
            // el pedido puntual de una ficha lo trae en su payload armado a mano. La clave tiene que existir
            // siempre: el app recorre una lista fija de tablas.
            'recipes_pareja' => $datos['recipes_pareja'] ?? $vacio,
            'vademecum' => $datos['vademecum'] ?? $vacio,
            'tratamientos' => $datos['tratamientos'] ?? $vacio,
            'tratamientos_detalle' => $datos['tratamientos_detalle'] ?? $vacio,
            // Las plantillas de texto del médico (ola §11 de PLAN-MOVIL.md). El delta y el volcado las arman
            // desde `$plan`; el pedido puntual de una ficha no las necesita por paciente, pero la clave tiene
            // que existir siempre: el app recorre una lista fija de tablas.
            'formato_print' => $datos['formato_print'] ?? $vacio,
            'constancia_obs' => $datos['constancia_obs'] ?? $vacio,
            'reposo_paciente' => $datos['reposo_paciente'] ?? $vacio,
            'referencia' => $datos['referencia'] ?? $vacio,
            'informe' => $datos['informe'] ?? $vacio,
            'diagnostico_paciente' => $datos['diagnostico_paciente'] ?? $vacio,
            // La evaluación física de la consulta (A2 de PLAN-MOVIL.md). El delta la arma solo desde
            // `$plan`, pero la clave tiene que estar siempre: el app recorre una lista fija de tablas.
            'examen_fisico_nuevo' => $datos['examen_fisico_nuevo'] ?? $vacio,
            // Los antecedentes del paciente (A4a/A4b). El delta y el volcado los arman desde `$plan`; el
            // pedido puntual de una ficha (`volcado_paciente`) los trae en su payload armado a mano. La
            // clave tiene que existir siempre por el mismo motivo que las de arriba.
            'antece_paciente' => $datos['antece_paciente'] ?? $vacio,
            // Los cuatro estudios ecográficos (EC-A2 de PLAN-MOVIL.md). El delta y el volcado los arman
            // desde `$plan`; el pedido puntual de una ficha (`volcado_paciente`) los trae en su payload
            // armado a mano. La clave tiene que existir siempre: el app recorre una lista fija de tablas.
            'eco_pelvico' => $datos['eco_pelvico'] ?? $vacio,
            'eco_obstetrico' => $datos['eco_obstetrico'] ?? $vacio,
            'eco_obstetrico_tercer_2' => $datos['eco_obstetrico_tercer_2'] ?? $vacio,
            'eco_obstetrico_tercer' => $datos['eco_obstetrico_tercer'] ?? $vacio,
            // Los otros cuatro estudios (ola §10): Doppler perinatal, mamario, abdominal y ecocardiograma
            // fetal. La clave tiene que existir siempre por el mismo motivo que las de arriba.
            'eco_doppler' => $datos['eco_doppler'] ?? $vacio,
            'ultra_mama' => $datos['ultra_mama'] ?? $vacio,
            'ultra_abdominal' => $datos['ultra_abdominal'] ?? $vacio,
            'ecocadiograma_fetal' => $datos['ecocadiograma_fetal'] ?? $vacio,
            // Los dos catálogos del antecedente (A4b de PLAN-MOVIL.md): de dónde sale el nombre que se
            // agrega y de dónde el de su tipo/agrupación.
            'antecedentes' => $datos['antecedentes'] ?? $vacio,
            'tipo_antecedente' => $datos['tipo_antecedente'] ?? $vacio,
            'diagnosticos' => $datos['diagnosticos'] ?? $vacio,
            'doctores' => $datos['doctores'] ?? $vacio,
            'especial' => $datos['especial'] ?? $vacio,
            'centros_medicos' => $datos['centros_medicos'] ?? $vacio,
            'offices' => $datos['offices'] ?? $vacio,
            'office_schedules' => $datos['office_schedules'] ?? $vacio,
            // La configuración va completa en cada respuesta, no por delta: son un puñado de
            // campos y el cliente la necesita entera para decidir cómo dibujar la agenda.
            // Si el médico no tiene fila de configuración (pasa: en el dump real está vacía),
            // el Resource resuelve los defaults sobre un modelo en blanco.
            'configuracion' => new ConfiguracionMedicoResource(
                Evolucion::whereIn('reg_medico', $registrosMedicos)->first() ?? new Evolucion(),
            ),
            // Completo en cada respuesta, por el mismo motivo que `configuracion`. Se busca por el
            // registro principal, que es bajo el que lo guarda `RecipeFormatoController`.
            'formato_recipe' => new RecipeFormatoResource(
                RecipeFormato::where('reg_medico', $medicoModel->regMedicoPrincipal())->first()
                    ?? new RecipeFormato(),
            ),
            // Catálogo de médicos del tenant (Paso 22.B), completo en cada respuesta por el mismo
            // motivo que `configuracion`: son pocas filas y el filtro de agenda los necesita todos,
            // no por delta.
            'medicos' => $this->medicosDelTenant($registrosMedicos),
            // Estado del servicio contratado, para que el app muestre el vencimiento y avise antes de que
            // venza (y no intente sincronizar cuando ya venció).
            'servicio' => collect($estadoServicio)->only(
                ['estado', 'plan', 'vence_el', 'dias_restantes', 'gracia_hasta', 'restricciones', 'caracteristicas', 'permite_imprimir']
            )->all(),
            'eliminados' => $eliminados,
            // Mapeo id temporal del cliente → id real, para que pueda soltar su fila provisional.
            'creados' => $resultadoChanges['creados'],
            // Creaciones que no se aplicaron (y no se van a aplicar reintentando): el cliente las
            // saca de su cola en vez de reintentarlas para siempre, y le avisa al usuario.
            'rechazados' => $resultadoChanges['rechazados'],
            // Volcado paginado: el cursor del tramo que sigue, o `null` si ya está todo. Sin volcado
            // paginado en curso es `null` y el app lo ignora.
            'volcado_siguiente' => $volcadoSiguiente,
            // Hasta dónde atrás llega el volcado que acaba de bajar (ver `DIAS_DE_VENTANA`). El app lo
            // guarda para saber qué días de la agenda y qué historiales le faltan pedir en línea.
            'ventana_desde' => $ventanaDesde,
        ];
    }

    /**
     * Médicos del tenant, para el filtro de agenda y el selector de Nueva Cita (Paso 22.C).
     *
     * `cola.medico` no es `medicos.id`: es la `clave` de `evolucion`, un correlativo (1, 2, 3…)
     * que identifica al médico **dentro de la instancia** de PowerBuilder (aclarado por Alexander,
     * ver ROADMAP.md Paso 22). El nombre se resuelve cruzando `evolucion.correo_med` con
     * `medicos.email` — no hay otra columna en común entre las dos tablas. Si una fila de
     * `evolucion` no tiene correo, o el correo no matchea a ningún `Medico`, el cliente igual
     * necesita la `clave` para poder filtrar esas citas (se muestra como "Médico N").
     *
     * @param list<string> $registrosMedicos
     * @return list<array{clave: int, id: ?int, name: ?string, lastname: ?string, prefix: ?string, user_id: ?int, especialidad: ?string}>
     */
    private function medicosDelTenant(array $registrosMedicos): array
    {
        $evoluciones = Evolucion::whereIn('reg_medico', $registrosMedicos)
            ->whereNotNull('clave')
            ->get();

        $correos = $evoluciones->pluck('correo_med')->filter()->unique()->values()->toArray();
        $medicosPorCorreo = Medico::whereIn('email', $correos)
            ->get()
            ->keyBy('email');

        return $evoluciones->map(function (Evolucion $evolucion) use ($medicosPorCorreo) {
            $medico = $evolucion->correo_med ? $medicosPorCorreo->get($evolucion->correo_med) : null;

            return [
                'clave' => (int) $evolucion->clave,
                'id' => $medico?->id,
                'name' => $medico?->name,
                'lastname' => $medico?->lastname,
                // Tratamiento ("Dr.", "Dra.") para el título de la agenda, y la cuenta de acceso para
                // saber cuál de estos médicos es quien tiene la sesión abierta (`users.id`).
                'prefix' => $medico?->prefix,
                'user_id' => $medico?->user_id,
                'especialidad' => $evolucion->especialidad,
                // Plantillas de mensaje (Paso 23): cada médico solo edita la suya propia (vía
                // `POST /app/configuracion`, resuelto por la cuenta logueada), pero al enviar un
                // recordatorio se usa la del médico **de la cita**, no la de quien tiene la sesión
                // abierta (una secretaria puede mandar recordatorios de citas de varios médicos del
                // mismo tenant) — de ahí que viajen acá, en el catálogo completo, y no solo en
                // `ConfiguracionMedicoResource`.
                'plantilla_cita' => $evolucion->plantilla_cita,
                'plantilla_cumple' => $evolucion->plantilla_cumple,
            ];
        })->values()->all();
    }

    /**
     * Aplica las operaciones que mandó el cliente. Lo único que devuelve es lo que el cliente no
     * puede deducir solo: qué id real le tocó a cada fila que creó offline, y qué creaciones se
     * rechazaron. El resto del resultado se ve reflejado en el delta que se calcula después,
     * usando el `since` viejo.
     *
     * @return array{creados: list<array{table: string, temp_id: int, id: int}>, rechazados: list<array{table: string, temp_id: int, motivo: string}>}
     */
    private function applyChanges(array $changes, Medico $medico, array $registrosMedicos, $relaciones): array
    {
        $pacienteIdsDelMedico = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
        $creados = [];
        $rechazados = [];
        // Id temporal del cliente → id real, para las citas del mismo lote que referencian a un
        // paciente recién creado (ver `paciente_temp_id`).
        $pacientesCreados = [];

        // Las creaciones van en orden de dependencia: una cita o una historia puede colgar de un
        // paciente del mismo lote, una consulta de una historia y un récipe de una consulta. El
        // cliente no tiene forma de garantizar el orden del arreglo.
        $changes = $this->enOrdenDeDependencia($changes);
        $clinicas = new CreacionesClinicas(
            $medico,
            $registrosMedicos,
            app(AsignarHistoria::class),
            // La regla del número de consulta vive en la Action compartida con la web (WEB-4.1).
            app(\App\Actions\Consulta\CrearConsulta::class),
            // Y las dos reglas de los motivos de una consulta, desde WEB-4.2.
            app(\App\Actions\Consulta\AgregarMotivoALaConsulta::class),
            app(\App\Actions\Consulta\QuitarMotivoDeLaConsulta::class),
            // Y la regla del récipe (el número, la cabecera y las filas), desde WEB-5.1.
            app(\App\Actions\Consulta\GuardarRecipe::class),
            // Y la de la evaluación física (la clave del legado `(historia, consulta)`, la fila que se
            // crea si falta y el "sólo lo que cambió"), desde A3 de PLAN-MOVIL.md.
            app(\App\Actions\Consulta\GuardarEvaluacionFisica::class),
            // El mapa del dato de esa evaluación, para devolverle al teléfono el id real de la fila.
            app(\App\Clinica\Consulta\ExamenFisicoDeLaConsulta::class),
            // Y el alta de un diagnóstico de la consulta (A4a), con el mapa del dato que valida el código
            // contra el catálogo `diagnosticos` del médico.
            app(\App\Actions\Consulta\AgregarDiagnosticoALaConsulta::class),
            // Y el alta de un antecedente del paciente (A4b), con el mismo mapa del dato de la sección
            // —`AntecedentesDelPaciente`— validando el código contra el catálogo `antecedentes` del médico.
            app(\App\Actions\Pacientes\AgregarAntecedenteAlPaciente::class),
            // Y el alta de los cuatro estudios ecográficos (EC-A3 de PLAN-MOVIL.md), cada uno con la Action de
            // su pantalla de la web: la clave del legado, la fila que se crea si falta, el "sólo lo que
            // cambió" con su rastro y el `-1`/`none` = sin dato. El sync no reimplementa esa regla.
            app(\App\Actions\Consulta\GuardarEcoGinecologico::class),
            app(\App\Actions\Consulta\GuardarEcoObstetrico::class),
            app(\App\Actions\Consulta\GuardarEcoObstetricoSegundo::class),
            app(\App\Actions\Consulta\GuardarEcoObstetricoTercero::class),
            // Y las de los **otros cuatro estudios** (ola §10 de PLAN-MOVIL.md): Doppler perinatal, mamario,
            // abdominal y ecocardiograma fetal.
            app(\App\Actions\Consulta\GuardarEcoDoppler::class),
            app(\App\Actions\Consulta\GuardarEcoMamario::class),
            app(\App\Actions\Consulta\GuardarEcoAbdominal::class),
            app(\App\Actions\Consulta\GuardarEcoCardiogramaFetal::class),
            // Y la del **récipe de la pareja** (ola §9 de PLAN-MOVIL.md): una por consulta, sin cabecera,
            // crea o corrige en el lugar. Es la misma Action de la pestaña de la web (WEB-5.1b).
            app(\App\Actions\Consulta\GuardarRecipeDeLaPareja::class),
            // Documentos de la consulta (constancia y reposo): las Actions de la web.
            app(\App\Actions\Consulta\GuardarConstanciaDeLaConsulta::class),
            app(\App\Actions\Consulta\GuardarReposoDeLaConsulta::class),
            app(\App\Actions\Consulta\GuardarReferenciaDeLaConsulta::class),
            app(\App\Actions\Consulta\GuardarInformeDeLaConsulta::class),
            app(\App\Actions\Consulta\GuardarHospitalizacionDeLaConsulta::class),
            app(\App\Actions\Consulta\GuardarDietaDeLaConsulta::class),
            app(\App\Actions\Consulta\EliminarRecipe::class),
        );
        $creadosAntes = count($creados);

        foreach ($changes as $change) {
            $table = $change['table'] ?? null;
            $recordId = $change['record_id'] ?? null;
            $operation = $change['operation'] ?? null;

            if ($operation === 'created' && in_array($table, SyncAppDataRequest::CLINICAL_TABLES, true)) {
                if (isset($change['temp_id']) && is_numeric($change['temp_id'])) {
                    $clinicas->aplicar($change, $pacientesCreados, $creados, $rechazados);
                }
                continue;
            }

            // Quitar un motivo de la consulta (Paso 18.B2). Va aparte de `TABLAS_BORRABLES`: esa lista
            // es de las tablas con id local propio en el teléfono (`cola`, `pacientes`) y de las
            // clínicas que se dan de baja por su id (`diagnostico_paciente`, `antece_paciente`).
            if ($operation === 'deleted' && $table === 'motivo_consulta_paciente') {
                if ($recordId) {
                    $clinicas->quitarMotivoDeConsulta((int) $recordId);
                }
                continue;
            }

            if ($operation === 'created') {
                $this->applyCreated(
                    $change,
                    $medico,
                    $registrosMedicos,
                    $creados,
                    $rechazados,
                    $pacientesCreados,
                );
                continue;
            }

            if ($operation === 'reorder') {
                $this->applyReorder($change, $registrosMedicos);
                continue;
            }

            // Qué se puede editar y qué se puede borrar son **dos listas distintas** desde A1
            // (PLAN-MOVIL.md): una consulta y un examen físico se editan pero no se borran.
            $tablasPermitidas = $operation === 'deleted'
                ? SyncAppDataRequest::TABLAS_BORRABLES
                : SyncAppDataRequest::TABLAS_EDITABLES;

            if (!in_array($table, $tablasPermitidas, true) || !$recordId || !$operation) {
                continue;
            }

            // Autorización: la fila tiene que ser de este médico. Para las clínicas la resuelve **el
            // `reg_medico` de la propia fila**, no el paciente: un diagnóstico de otro médico no se
            // toca ni aunque el `nrohistoria` coincida (el número es por médico).
            $fila = $this->filaDelMedico($table, (int) $recordId, $registrosMedicos, $pacienteIdsDelMedico);
            if (!$fila) {
                continue;
            }

            $yaEliminado = RegistroDeCambios::eliminado($table, (int) $recordId);

            if ($operation === 'deleted') {
                if (!$yaEliminado) {
                    $fila->delete();

                    // El registro de movimientos de los antecedentes (2026-10-20): lo que tenía al quitarse.
                    if ($table === 'antece_paciente') {
                        RegistrarMovimientoDeAntecedente::quitado(
                            $fila->getAttributes(), 'mobile', null, Carbon::parse($change['occurred_at'] ?? now()),
                        );
                    }
                }
                continue;
            }

            if ($operation === 'updated') {
                // Regla: un eliminado le gana a cualquier update posterior, sin importar timestamp.
                if ($yaEliminado) {
                    continue;
                }

                $column = $change['column'] ?? null;
                if (!in_array($column, SyncAppDataRequest::columnasEditables($table), true)) {
                    continue;
                }

                $occurredAt = Carbon::parse($change['occurred_at'] ?? now());

                $valor = $change['value'] ?? null;
                if (!$this->valorPermitido($table, $column, $valor)) {
                    continue;
                }

                // Confirmar, atender y cobrar **no son campos cualesquiera**: son acciones de
                // agenda, y se aplican con las mismas Actions que usa la web. Si esto se
                // reimplementara acá, la web y el móvil podrían empezar a comportarse distinto
                // sobre la misma base (PLAN-WEB.md, R3).
                if ($table === 'cola' && $this->aplicarAccionDeCita((int) $recordId, $column, $valor, $occurredAt)) {
                    continue;
                }

                // Last-write-wins por columna: si ya hay un cambio más nuevo registrado para
                // esta columna puntual, se descarta el que llegó (alguien escribió después).
                if (!RegistroDeCambios::permite($table, (int) $recordId, $column, $occurredAt)) {
                    continue;
                }

                $antes = $fila->getAttributes();
                $fila->{$column} = $valor;
                $fila->save();

                // El registro de movimientos de los antecedentes (2026-10-20): sólo si cambió algo visible.
                if ($table === 'antece_paciente') {
                    RegistrarMovimientoDeAntecedente::editado($antes, $fila->getAttributes(), 'mobile', null, $occurredAt);
                }

                // El rastro se escribe con el `reg_medico` **de la fila**: es el tenant de lo que se
                // acaba de editar y el que después acota el delta del teléfono. En `pacientes` la fila
                // no lo tiene (el paciente es uno solo para todos los médicos), así que sale del pivote.
                SyncChange::create([
                    'reg_medico' => $table === 'pacientes'
                        ? $this->regMedicoDePaciente($relaciones, (int) $recordId)
                        : $fila->reg_medico,
                    'table_name' => $table,
                    'record_id' => $recordId,
                    'operation' => 'updated',
                    'column_name' => $column,
                    'value' => $valor,
                    'occurred_at' => $occurredAt,
                    'source' => 'mobile',
                ]);
            }
        }

        return [
            'creados' => $creados,
            'rechazados' => $rechazados,
            'creo_pacientes' => $pacientesCreados !== [],
            'creo_clinicas' => collect($creados)->skip($creadosAntes)
                ->contains(fn ($c) => in_array($c['table'], SyncAppDataRequest::CLINICAL_TABLES, true)),
        ];
    }

    /**
     * Creaciones primero y en orden de dependencia (pacientes, historias, consultas, y lo que cuelga de
     * la consulta: motivos, récipes, la evaluación física y —desde A4a— el alta de un diagnóstico; y
     * —desde A4b— el alta de un antecedente, que cuelga de la historia; y —desde EC-A3— los cuatro estudios
     * ecográficos); el resto (ediciones, borrados, reordenamientos, citas nuevas) después, en el orden en que
     * llegó.
     *
     * @param list<array> $changes @return list<array>
     */
    private function enOrdenDeDependencia(array $changes): array
    {
        $prioridad = [
            'pacientes' => 0, 'historias' => 1, 'consultas' => 2, 'motivos_consulta' => 3,
            // Todo lo que **cuelga de la consulta** va después de ella: un motivo, un récipe y —desde A3
            // de PLAN-MOVIL.md— la evaluación física. La evaluación física comparte prioridad con las
            // otras dos porque no depende de ellas (las tres dependen sólo de la consulta), y ponerla en 4
            // es lo que hace que una consulta creada en el mismo lote ya tenga número cuando se busca su
            // fila; con la prioridad del "resto" el examen se rechazaría por no encontrar la consulta.
            'motivo_consulta_paciente' => 4, 'recipes' => 4, 'examen_fisico_nuevo' => 4,
            // Y el alta de un diagnóstico de la consulta (A4a): cuelga de `consultas` igual que las otras
            // tres y no depende de ellas, así que comparte el 4 — lo que importa es que vaya **después** de
            // la consulta, porque si la consulta se creó en este mismo lote necesita su número primero.
            'diagnostico_paciente' => 4,
            // El alta de un **antecedente del paciente** (A4b) no cuelga de la consulta sino de la
            // **historia**: con 3 queda después de `historias` y de `consultas`, así una historia llenada en
            // este mismo lote (`historia_temp_id`) ya tiene número cuando se resuelve. No depende de nada
            // más, así que comparte el grupo con los motivos del catálogo.
            'antece_paciente' => 3,
            // Y los cuatro **estudios ecográficos** (EC-A3): cuelgan de la consulta, así que van con el mismo 4
            // —después de `consultas`— para que una consulta creada en este mismo lote ya tenga sus números
            // (`numhistoria`/`nroconsulta`) cuando la Action del estudio busca la fila por la clave del legado.
            'eco_pelvico' => 4, 'eco_obstetrico' => 4,
            'eco_obstetrico_tercer_2' => 4, 'eco_obstetrico_tercer' => 4,
            // Los cuatro de la ola §10: también cuelgan de la consulta y van después de `consultas`.
            'eco_doppler' => 4, 'ultra_mama' => 4,
            'ultra_abdominal' => 4, 'ecocadiograma_fetal' => 4,
            // El récipe de la pareja (ola §9) cuelga de la consulta como el del paciente: con 4 va después de
            // `consultas`, así una consulta creada en este mismo lote ya tiene sus números cuando la Action
            // busca la pareja por `nrohistoria` + `nroconsulta`.
            'recipes_pareja' => 4,
        ];
        $resto = 5; // el grupo de todo lo que no es una creación clínica
        $grupos = array_fill(0, $resto + 1, []);
        foreach ($changes as $change) {
            $esCreacion = ($change['operation'] ?? null) === 'created';
            $grupos[$esCreacion ? ($prioridad[$change['table'] ?? ''] ?? $resto) : $resto][] = $change;
        }

        return array_merge(...$grupos);
    }

    /**
     * Crea una fila que el cliente ya venía mostrando localmente con su propio id.
     *
     * Tres cosas que no son obvias:
     * 1. **Idempotencia por `client_temp_id`**: si la respuesta anterior se perdió, el reintento
     *    devuelve el mismo id real en vez de crear una fila duplicada.
     * 2. **El tenant no se acepta del cliente**: `reg_medico` sale del médico autenticado (o de
     *    la historia del paciente), no de lo que haya mandado el teléfono.
     * 3. **Rechazo explícito**: descartar en silencio dejaría al cliente reintentando para
     *    siempre una fila que nunca va a entrar, y al médico viéndola como "pendiente" sin fin.
     *
     * @param array<int, int> $pacientesCreados id temporal -> id real de los pacientes del lote
     */
    private function applyCreated(
        array $change,
        Medico $medico,
        array $registrosMedicos,
        array &$creados,
        array &$rechazados,
        array &$pacientesCreados
    ): void {
        $table = $change['table'] ?? null;
        $tempId = $change['temp_id'] ?? null;

        // Sin `temp_id` no hay forma de contestarle al cliente cuál fila es cuál, así que no se
        // crea nada: aplicarla sería dejarle una fila fantasma imposible de reconciliar.
        if (!isset(SyncAppDataRequest::CREATABLE_COLUMNS[$table]) || $tempId === null || !is_numeric($tempId)) {
            return;
        }
        $tempId = (int) $tempId;

        $yaCreado = SyncChange::where('table_name', $table)
            ->where('client_temp_id', $tempId)
            ->where('operation', 'created')
            ->whereIn('reg_medico', $registrosMedicos)
            ->first();

        if ($yaCreado) {
            $creados[] = ['table' => $table, 'temp_id' => $tempId, 'id' => $yaCreado->record_id];
            // El reintento de una cita puede venir con el paciente ya creado en un lote anterior:
            // la referencia tiene que seguir resolviendo.
            if ($table === 'pacientes') {
                $pacientesCreados[$tempId] = $yaCreado->record_id;
            }
            return;
        }

        $rechazar = function (string $motivo) use ($table, $tempId, &$rechazados) {
            $rechazados[] = ['table' => $table, 'temp_id' => $tempId, 'motivo' => $motivo];
        };

        $columnas = array_intersect_key(
            (array) ($change['columns'] ?? []),
            array_flip(SyncAppDataRequest::CREATABLE_COLUMNS[$table]),
        );

        foreach (SyncAppDataRequest::REQUIRED_COLUMNS[$table] ?? [] as $obligatoria) {
            if (($columnas[$obligatoria] ?? null) === null) {
                $rechazar("Falta el campo obligatorio '{$obligatoria}'.");
                return;
            }
        }

        foreach ($columnas as $columna => $valor) {
            if (!$this->valorPermitido($table, $columna, $valor)) {
                $rechazar("El valor de '{$columna}' no es válido.");
                return;
            }
        }

        $occurredAt = Carbon::parse($change['occurred_at'] ?? now());

        if ($table === 'pacientes') {
            $this->crearPaciente(
                $columnas,
                $medico,
                $registrosMedicos,
                $tempId,
                $occurredAt,
                $creados,
                $pacientesCreados,
                $rechazar,
            );

            return;
        }

        $this->crearCola(
            $columnas,
            $change,
            $medico,
            $registrosMedicos,
            $tempId,
            $occurredAt,
            $creados,
            $pacientesCreados,
            $rechazar,
        );
    }

    /**
     * Alta de paciente desde el app. Replica lo que hace `PacienteSyncController` con lo que sube
     * el escritorio, con una diferencia que importa: **acá no hay `numhistoria`**.
     *
     * La ficha se busca por cédula antes de crearla porque en este esquema un paciente es uno
     * solo para todos los médicos (`pacientes`) y lo que cambia por médico es la relación
     * (`medico_pacientes`): si la secretaria da de alta a alguien que ya existe, corresponde
     * enlazarlo, no duplicarle la ficha.
     *
     * **No se crea `Historia`** justamente porque su `numhistoria` es único global y obligatorio:
     * ese número lo asigna el escritorio, y cuando lo haga, su propio sync crea la historia
     * (`Historia::updateOrCreate` por paciente + médico). Hasta entonces el paciente le llega al
     * teléfono igual, porque el delta de pacientes se calcula por el pivote, no por la historia.
     */
    private function crearPaciente(
        array $columnas,
        Medico $medico,
        array $registrosMedicos,
        int $tempId,
        Carbon $occurredAt,
        array &$creados,
        array &$pacientesCreados,
        callable $rechazar
    ): void {
        $regMedico = $medico->reg_medico ?: ($registrosMedicos[0] ?? null);
        if ($regMedico === null) {
            $rechazar('Este médico no tiene registro asignado.');
            return;
        }

        // La regla del alta —enlazar por cédula en vez de duplicar la ficha, completar solo los
        // campos vacíos de una ficha ajena, y no crear `historia`— vive en la Action, que es la
        // **misma** que usa la web clínica (PLAN-WEB.md, R3). Acá queda lo que es del sync: el id
        // temporal con el que el cliente reconoce la fila que creó estando offline.
        $paciente = app(CrearPaciente::class)->ejecutar($columnas, $medico, $regMedico);

        SyncChange::create([
            'reg_medico' => $regMedico,
            'table_name' => 'pacientes',
            'record_id' => $paciente->id,
            'client_temp_id' => $tempId,
            'operation' => 'created',
            'occurred_at' => $occurredAt,
            'source' => 'mobile',
        ]);

        $pacientesCreados[$tempId] = $paciente->id;
        $creados[] = ['table' => 'pacientes', 'temp_id' => $tempId, 'id' => $paciente->id];
    }

    /**
     * Alta de cita. El paciente llega de una de dos formas, nunca de las dos:
     *
     * - **`numhistoria`**: el camino normal. Se verifica contra `historias` que sea de este médico.
     * - **`paciente_temp_id`**: el paciente se creó en este mismo lote (o en uno anterior que el
     *   cliente todavía no vio resuelto) y no tiene número de historia. La cita se ancla con
     *   `paciente_sinhistoria_id`, la columna que el esquema legado ya trae para este caso.
     */
    private function crearCola(
        array $columnas,
        array $change,
        Medico $medico,
        array $registrosMedicos,
        int $tempId,
        Carbon $occurredAt,
        array &$creados,
        array $pacientesCreados,
        callable $rechazar
    ): void {
        $numhistoria = $columnas['numhistoria'] ?? null;
        $pacienteTempId = $change['paciente_temp_id'] ?? null;

        if ($numhistoria !== null) {
            $historia = Historia::where('numhistoria', $numhistoria)
                ->whereIn('reg_medico', $registrosMedicos)
                ->first();

            if (!$historia) {
                $rechazar('El número de historia no pertenece a este médico.');
                return;
            }

            $columnas['reg_medico'] = $historia->reg_medico;
        } elseif ($pacienteTempId !== null) {
            $pacienteId = $pacientesCreados[(int) $pacienteTempId] ?? null;
            if ($pacienteId === null) {
                // O la creación del paciente venía en este lote y fue rechazada, o la referencia
                // no corresponde a nada. Reintentar no lo va a arreglar: dejarla en la cola
                // sería tener una cita pendiente para siempre.
                $rechazar('El paciente de esta cita no se pudo crear.');
                return;
            }

            // El vínculo es del **consultorio**: se busca al paciente entre los registros que trabaja el
            // médico, sin preguntar por `medico_id` (lo pudo vincular otro doctor del mismo centro).
            $relacion = MedicoPaciente::whereIn('reg_medico', $registrosMedicos)
                ->where('paciente_id', $pacienteId)
                ->orderBy('id')
                ->first();
            if (!$relacion) {
                $rechazar('Ese paciente no es de este médico.');
                return;
            }

            $columnas['paciente_sinhistoria_id'] = $pacienteId;
            $columnas['reg_medico'] = $relacion->reg_medico
                ?: ($medico->reg_medico ?: ($registrosMedicos[0] ?? null));
        } else {
            $rechazar('La cita no indica a qué paciente pertenece.');
            return;
        }

        // `cola.medico` es la `clave` de `evolucion` (Paso 22.B), no `medicos.id` — son dos
        // numeraciones distintas que solo coinciden por casualidad en los datos de prueba de un
        // solo médico. Si el cliente ya la mandó (Paso 22.C: eligió un médico en el selector), se
        // respeta esa; si no (el caso común, un solo médico en la instancia), se resuelve la del
        // médico autenticado.
        if (!isset($columnas['medico'])) {
            $columnas['medico'] = $medico->claveDeEvolucion($registrosMedicos);
        }

        // La fila y su rastro en `sync_changes` los escribe la Action compartida con la web clínica
        // (PLAN-WEB.md, R3); acá le llega el `reg_medico` ya resuelto y el id temporal del cliente.
        $cola = app(CrearCita::class)->ejecutar(
            $columnas['reg_medico'],
            $columnas,
            $occurredAt,
            'mobile',
            $tempId,
        );

        $creados[] = ['table' => 'cola', 'temp_id' => $tempId, 'id' => $cola->id];
    }

    /**
     * Ver `ALLOWED_VALUES` y `DATE_COLUMNS`. Una columna sin dominio declarado acepta cualquier
     * valor.
     */
    private function valorPermitido(string $table, string $column, $valor): bool
    {
        if (in_array($column, SyncAppDataRequest::DATE_COLUMNS[$table] ?? [], true)) {
            // Una cita sin fecha no existe en la agenda: nulo tampoco vale.
            if (!is_string($valor)) {
                return false;
            }
            $fecha = \DateTime::createFromFormat('!Y-m-d', $valor);
            return $fecha !== false && $fecha->format('Y-m-d') === $valor;
        }

        $permitidos = SyncAppDataRequest::ALLOWED_VALUES[$table][$column] ?? null;

        if ($permitidos === null || $valor === null) {
            return true;
        }

        return is_numeric($valor) && in_array((int) $valor, $permitidos, true);
    }

    /**
     * Mueve una fila dentro de su grupo (una cita dentro de su día) corriendo las demás.
     *
     * La regla vive en `ReordenarCola`, compartida con la web: acá solo queda lo del sync — resolver
     * el tenant de la fila y pasar el movimiento tal como lo mandó el cliente (su `from` puede
     * componerse con otros reordenamientos pendientes). El porqué de mandar el movimiento y no el
     * resultado está en la Action.
     */
    private function applyReorder(array $change, array $registrosMedicos): void
    {
        $table = $change['table'] ?? null;
        if ((SyncAppDataRequest::ORDER_COLUMN[$table] ?? null) === null
            || (SyncAppDataRequest::ORDER_SCOPE_COLUMN[$table] ?? null) === null) {
            return;
        }

        // Tenancy: la fila tiene que ser de este médico, igual que en el resto del endpoint.
        $cita = Cola::where('id', (int) $change['record_id'])
            ->whereIn('reg_medico', $registrosMedicos)
            ->first();
        if (!$cita) {
            return;
        }

        app(ReordenarCola::class)->ejecutar(
            $cita,
            (int) $change['from'],
            (int) $change['to'],
            (string) $change['scope_date'],
            $registrosMedicos,
            Carbon::parse($change['occurred_at'] ?? now()),
            'mobile',
        );
    }

    /**
     * Aplica una edición de `cola` que en realidad es una **acción de agenda** (confirmar, atender,
     * cobrar) delegando en la Action compartida con la web. Devuelve `false` para las columnas que
     * no son acciones, que siguen por el camino genérico.
     *
     * Las que sí lo son no pueden seguir por ahí: si cada superficie tuviera su propia copia de la
     * regla (que atender implica confirmar, que el abono se suma o que el estado de pago se deriva)
     * la web y el móvil empezarían a comportarse distinto sobre la misma base (PLAN-WEB.md, R3).
     *
     * **La regla "cita atendida ⇒ tiene historia" (WEB-4.8) se descarta acá, no rompe el lote.** Marcar
     * `atendido = 1` sin historia lanza `CitaNoAtendible` (la regla vive en `AtenderCita`, que es la única
     * puerta a esa columna en las dos superficies). Un cambio que incumple una regla de negocio **no es un
     * lote mal formado**: se descarta —como el last-write-wins descarta el cambio que pierde—, el resto de
     * los cambios se aplica, y el teléfono se corrige solo en la sincronización siguiente, porque
     * sobreescribe su copia local con lo que el servidor le devuelve (el `atendido` sigue en 0). Es el
     * "descartar" que TODO.md §9 dejó propuesto; el canal de rechazos visible sigue siendo sólo el de las
     * **creaciones** (`rechazados`), que es una deuda anotada y no de esta tarea.
     *
     * El `catch` es puntual y no un `Throwable` genérico: cualquier otra excepción de la Action tiene que
     * seguir subiendo y verse como el error que es.
     */
    private function aplicarAccionDeCita(int $recordId, string $column, $valor, Carbon $occurredAt): bool
    {
        if (!in_array($column, ['estado', 'atendido', 'monto', 'monto_pagado'], true)) {
            return false;
        }

        $cita = Cola::find($recordId);
        if (!$cita) {
            return true; // la fila ya no existe: nada que aplicar, pero no cae al camino genérico
        }

        try {
            switch ($column) {
                case 'estado':
                    app(ConfirmarCita::class)->ejecutar($cita, (int) $valor, $occurredAt, 'mobile');
                    break;
                case 'atendido':
                    app(AtenderCita::class)->ejecutar($cita, (int) $valor === 1, $occurredAt, 'mobile');
                    break;
                case 'monto':
                    app(CobrarCita::class)->fijarMonto($cita, $valor === null ? null : (float) $valor, $occurredAt, 'mobile');
                    break;
                case 'monto_pagado':
                    app(CobrarCita::class)->fijarPagado($cita, $valor === null ? null : (float) $valor, $occurredAt, 'mobile');
                    break;
            }
        } catch (CitaNoAtendible $e) {
            // La cita se queda sin atender: el teléfono lo ve en el delta y revierte su marca.
        }

        return true;
    }

    private function regMedicoDePaciente($relaciones, int $pacienteId): ?string
    {
        return $relaciones->firstWhere('paciente_id', $pacienteId)?->reg_medico;
    }

    /**
     * La fila que el teléfono quiere tocar, si es de este médico; `null` si no existe o es ajena.
     *
     * Devuelve el modelo y no un booleano porque el que llama necesita lo mismo para escribir que para
     * el rastro (`sync_changes.reg_medico`): así la comprobación de tenant y el `find` son una sola
     * consulta, y no hay forma de autorizar una fila y terminar escribiendo otra.
     *
     * La regla es la misma para todas las tablas clínicas: **el `reg_medico` de la fila** tiene que ser
     * uno de los registros del médico autenticado. `pacientes` es la excepción, porque la ficha es una
     * sola para todos los médicos y el tenant está en el pivote `medico_pacientes`.
     *
     * @param  list<string>  $registrosMedicos
     * @param  list<int>  $pacienteIdsDelMedico
     */
    private function filaDelMedico(string $table, int $recordId, array $registrosMedicos, array $pacienteIdsDelMedico)
    {
        $fila = $this->modelFor($table)::find($recordId);

        if (!$fila) {
            return null;
        }

        if ($table === 'pacientes') {
            return in_array($recordId, $pacienteIdsDelMedico, true) ? $fila : null;
        }

        return in_array((string) $fila->reg_medico, $registrosMedicos, true) ? $fila : null;
    }

    /**
     * El modelo de cada tabla que el sync escribe por fila. Es la tabla de `TABLAS_EDITABLES` y
     * `TABLAS_BORRABLES` mapeada a su modelo: una tabla nueva en esas listas necesita su línea acá.
     */
    private function modelFor(string $table): string
    {
        return [
            'cola' => Cola::class,
            'pacientes' => Paciente::class,
            'consultas' => Consulta::class,
            'examen_fisico_nuevo' => ExamenFisico::class,
            'diagnostico_paciente' => DiagnosticoPaciente::class,
            'antece_paciente' => AntecePaciente::class,
            // EC-A3: los cuatro estudios ecográficos, que el médico edita desde el teléfono por el camino de
            // A1 (una columna a la vez, con su last-write-wins y su rastro `source = mobile`).
            'eco_pelvico' => EcoPelvico::class,
            'eco_obstetrico' => EcoObstetrico::class,
            'eco_obstetrico_tercer_2' => EcoObstetricoSegundo::class,
            'eco_obstetrico_tercer' => EcoObstetricoTercer::class,
            // Ola §10: los otros cuatro estudios, por el mismo camino.
            'eco_doppler' => EcoDoppler::class,
            'ultra_mama' => UltraMama::class,
            'ultra_abdominal' => UltraAbdominal::class,
            'ecocadiograma_fetal' => EcoCardiogramaFetal::class,
            // Documentos de la consulta: si el teléfono corrige una fila ya subida, se aplica por columna.
            'constancia_obs' => ConstanciaObs::class,
            'reposo_paciente' => ReposoPaciente::class,
            'referencia' => Referencia::class,
            'informe' => Informe::class,
            'hospitalizacion' => Hospitalizacion::class,
            'dieta_paciente' => DietaPaciente::class,
        ][$table];
    }

    private function deltaQuery($query, ?Carbon $since)
    {
        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        return $query;
    }
}

<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\PacienteSyncController;
use App\Http\Controllers\Api\ConsultaSyncController;
use App\Http\Controllers\Api\ColaSyncController;
use App\Http\Controllers\Api\ComentarioController;
use App\Http\Controllers\Api\LoginAppController;
use App\Http\Controllers\Api\CambiarPasswordController;
use App\Http\Controllers\Api\NotificacionCitaController;
use App\Http\Controllers\Api\RecipeVerificacionController;
use App\Http\Controllers\Api\RefreshAppController;
use App\Http\Controllers\Api\SyncAppDataController;
use App\Http\Controllers\Api\ConfiguracionMedicoController;
use App\Http\Controllers\Api\MotivoConsultaController;
use App\Http\Controllers\Api\CargaInicialController;
use App\Http\Controllers\Api\CambiosEscritorioController;
use App\Http\Controllers\Api\CanalEscritorioController;
use App\Http\Controllers\Api\ReportesEscritorioController;
use App\Http\Controllers\Api\ImagenesPcController;
use App\Http\Controllers\Api\RecipeFormatoController;
use App\Http\Controllers\Api\UploadServerController;
use App\Http\Controllers\Api\UsuariosEnLineaController;
use App\Http\Controllers\Comentarios\ImagenDelComentarioController;

use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Controllers\Pagos\WebhookSyPagoController;
use App\Services\WhatsAppService;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Devuelve la cuenta tal cual (incluye `must_change_password`): el app la usa al reabrir para saber si debe
// mostrar la pantalla de cambio de clave. Por eso queda abierta con clave temporal (ver EnsurePasswordChanged).
Route::middleware(['auth:api', 'account.active'])->get('/user', function (Request $request) {
    return $request->user();
});

// ** Estado para herramientas externas (2026-10-28) **
// Lo consulta el servidor MCP propio antes de desplegar a producción: quiénes están conectados ahora. La
// señal es la MISMA del panel de Root (`user-is-online-<id>` en caché, puesta por `UserOnlineMiddleware`),
// no una sesión del API: un token no significa estar en línea. Sólo el Root puede llamarlo (403 al resto),
// y la comprobación vive en el controlador: en el API el guard por defecto es `api` y los roles son `web`.
Route::middleware(['auth:api', 'account.active'])->get('/estado/usuarios-en-linea', [UsuariosEnLineaController::class, 'index']);

// Rutas con límite de tasa para la recepción de lotes/paquetes desde PowerBuilder
Route::middleware('throttle:1000,1')->group(function () {
    Route::post('/sync/upload-batch', [SyncController::class, 'uploadBatch']);
    Route::post('/pacientes/sincronizar', [PacienteSyncController::class, 'sincronizar']);
    Route::post('/consultas/sincronizar', [ConsultaSyncController::class, 'sincronizar']);
    Route::post('/cola/sincronizar', [ColaSyncController::class, 'sincronizar']);
});

// Carga inicial completa desde el escritorio PowerBuilder (botón "Sincronización completa").
// Sin el `throttle:api` del grupo (60/min por IP): una carga son cientos de lotes seguidos y ese
// límite la frenaba con 429. Tiene su propio límite, más amplio.
Route::prefix('sync/carga-inicial')
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:600,1')
    ->group(function () {
        Route::post('/iniciar', [CargaInicialController::class, 'iniciar']);
        Route::post('/lote', [CargaInicialController::class, 'lote']);
        Route::post('/finalizar', [CargaInicialController::class, 'finalizar']);
    });

// Sincronización incremental del escritorio (Fase 2). Mismo criterio de límites que la carga.
Route::prefix('sync/cambios')
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:600,1')
    ->group(function () {
        Route::post('/estado', [CambiosEscritorioController::class, 'estado']);
        Route::post('/subir', [CambiosEscritorioController::class, 'subir']);
    });

// Imágenes clínicas que el escritorio guarda como RUTA del disco de la PC (WEB-T.1): la herramienta
// `bridge/subir-imagenes` pide la lista de pendientes, sube cada archivo y registra lo que no pudo. Misma autenticación
// que el resto del sync (`SyncAuthService`). Límite propio y con prefijo (un `throttle` sin nombre comparte contador con
// los demás grupos, trampa 78): 300 por minuto alcanza de sobra para 1 o 2 archivos a la vez.
Route::prefix('sync/imagenes')
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:300,1,imagenes-pc')
    ->group(function () {
        Route::post('/pendientes', [ImagenesPcController::class, 'pendientes']);
        Route::post('/subir', [ImagenesPcController::class, 'subir']);
        Route::post('/omitir', [ImagenesPcController::class, 'omitir']);
    });

// ** Canal de publicación del escritorio (instalador "Standalone") **
// Lo baja el launcher que se le manda al consultorio: el manifiesto y los archivos van SIN credencial (como
// hoy el paquete), y publicar exige `X-API-KEY` (ESCRITORIO_CANAL_KEY). El manifiesto es la única verdad: un
// archivo que no está listado ahí no se sirve. Se sirven con `Storage::response()` desde el disco `escritorio`.
Route::prefix('escritorio')->group(function () {
    Route::middleware('throttle:600,1,canal-escritorio')->group(function () {
        Route::get('/canal', [CanalEscritorioController::class, 'manifiesto']);
        Route::get('/descarga', [CanalEscritorioController::class, 'descarga']);
        Route::get('/canal/{ruta}', [CanalEscritorioController::class, 'archivo'])->where('ruta', '.*');
        Route::get('/launcher', [CanalEscritorioController::class, 'launcher']);
    });

    Route::prefix('publicar')->middleware(['clave.canal', 'throttle:120,1,publicar-escritorio'])->group(function () {
        Route::post('/iniciar', [CanalEscritorioController::class, 'iniciar']);
        Route::post('/archivo', [CanalEscritorioController::class, 'subirArchivo']);
        Route::post('/cerrar', [CanalEscritorioController::class, 'cerrar']);
        Route::post('/launcher', [CanalEscritorioController::class, 'subirLauncher']);
    });

    // Reportes de fallo del consultorio (instalador, launcher, sync). Público: el que falla puede ser el
    // paso previo a tener credencial. La bandeja está en /admin/reportes-escritorio.
    Route::post('/reportes', [ReportesEscritorioController::class, 'guardar'])
        ->middleware('throttle:30,1,reportes-escritorio');
});

// ** App para notificación médica ** //
Route::post('/app/login', [LoginAppController::class, 'login']);

// Cambio de clave: vive FUERA del grupo con `password.changed`, que bloquea todo lo demás mientras la clave sea temporal.
Route::middleware(['auth:api', 'account.active'])->post('/app/cambiar-password', [CambiarPasswordController::class, 'cambiar']);

Route::middleware(['auth:api', 'account.active', 'password.changed'])->group(function () {
    Route::post('/app/refresh-data', [RefreshAppController::class, 'refreshData']);
    Route::post('/app/sync-app-data', [SyncAppDataController::class, 'sync']);

    // El QR de verificación de un récipe (WEB-5.6) se pide al imprimir: es online-only y usa la misma
    // Action de la web, así que reimprimir devuelve el mismo token.
    Route::post('/app/recipes/verificacion', [RecipeVerificacionController::class, 'obtener'])
        ->middleware('throttle:60,1');

    // Notificar al paciente de una cita. No va por `sync-app-data` a propósito: mandar un
    // mensaje es irreversible y la cola de sync reintenta — ver NotificacionCitaController.
    Route::post('/app/citas/{cola}/notificar', [NotificacionCitaController::class, 'enviar'])
        ->middleware('throttle:60,1');

    // Datos de reporte (Diseño de reportes). Tampoco va por `sync-app-data` — ver
    // ConfiguracionMedicoController.
    Route::post('/app/configuracion', [ConfiguracionMedicoController::class, 'actualizar']);

    // Formato de impresión del récipe + firma/sello (ROADMAP.md Paso 18.A). Mismo criterio: online-only.
    Route::post('/app/configuracion/formato-recipe', [RecipeFormatoController::class, 'actualizar']);

    // Alta de un motivo en el catálogo de motivos de consulta (ROADMAP.md Paso 18.B2). Online-only:
    // crear motivos es poco frecuente, así que no pasa por la cola de sync. Ver MotivoConsultaController.
    Route::post('/app/motivos-consulta', [MotivoConsultaController::class, 'crear'])
        ->middleware('throttle:60,1');

    // **"Ayúdanos a mejorar"** desde el app (ROADMAP §9): el comentario del médico o de su secretaría, con sus
    // capturas opcionales, y los suyos con la respuesta. Online-only, como el alta de motivos: un comentario no se
    // encola (si no hay señal, el app avisa y conserva lo escrito) y una imagen no puede viajar por el sync de
    // columnas. La regla del alta es la Action `EnviarComentario`, la misma de `/clinica/ayuda`.
    Route::get('/app/comentarios', [ComentarioController::class, 'index']);
    Route::post('/app/comentarios', [ComentarioController::class, 'guardar'])->middleware('throttle:30,1');

    // La captura adjunta, para **su autor**: la misma puerta que «Mis comentarios» de la web. El archivo vive en
    // el disco privado `comentarios` y no hay ninguna URL pública que lo alcance, así que el app la pide con su
    // token y la pinta como miniatura; un comentario de otra cuenta da 404.
    Route::get('/app/comentarios/{comentario}/imagenes/{imagen}', [ImagenDelComentarioController::class, 'delAutor']);
});

Route::prefix('upload-servers')->group(function () {
    // Listar todos los registros de subida
    Route::get('/', [UploadServerController::class, 'index']);

    // Crear un nuevo registro de subida
    Route::post('/', [UploadServerController::class, 'store']);

    // Obtener la última subida exitosa de una entidad (ej: /api/upload-servers/last/pacientes?batch_type=nuevos)
    Route::get('/last/{entityType}', [UploadServerController::class, 'getLastUpload']);

    // Consultar el detalle de una subida por ID
    Route::get('/{id}', [UploadServerController::class, 'show']);
});

// Rutas de Webhook para Meta.
//
// El GET es la verificación de Meta (`hub.verify_token`) y el POST es el que trae los mensajes: ése va con
// **firma obligatoria** (`whatsapp.firma`) y con `throttle`. Sin la firma, cualquiera con la URL podía
// escribirle a las pacientes y crear citas — el mínimo de la auditoría del chatbot (2026-10-17, §6).
Route::get('/whatsapp/webhook', [WhatsAppWebhookController::class, 'verify']);
Route::middleware(['whatsapp.firma', 'throttle:120,1'])
    ->post('/whatsapp/webhook', [WhatsAppWebhookController::class, 'handle']);

// Ruta endpoint para enviar recordatorio a un paciente.
//
// ⚠️ Estaba fuera de `auth:api`: era pública, así que cualquiera con la URL podía disparar
// mensajes con el token de Meta del consultorio (y gastar su cupo). Ahora exige autenticación y
// tiene throttle propio. No la usa ninguno de los dos apps (se verificó en el Kotlin y en el
// Flutter) — el app usa `/app/citas/{cola}/notificar`, que además valida la cita y deja registro.
Route::middleware(['auth:api', 'throttle:60,1'])->post('/whatsapp/send-reminder', function (Request $request, WhatsAppService $whatsAppService) {
    $request->validate([
        'phone' => 'required|string',
        'patient_name' => 'required|string',
    ]);

    // Enviar plantilla "notificacion_paciente" con el nombre como parámetro {{1}}
    $result = $whatsAppService->sendTemplate(
        $request->input('phone'),
        'notificacion_paciente',
        [$request->input('patient_name')]
    );

    return response()->json($result);
});

// **Webhook de SyPago** (2026-10-21): avisa el resultado de un cobro. Sin `auth:api` (lo llama SyPago),
// autenticado por el token secreto del método en la URL, con throttle y con reconsulta antes de aprobar.
Route::middleware(['throttle:60,1'])
    ->post('/pagos/sypago/{token}', [WebhookSyPagoController::class, 'handle'])
    ->name('pagos.webhook.sypago');


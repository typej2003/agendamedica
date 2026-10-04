# MedicAPI — backend de AppDDR

Guía de entrada para trabajar en este repo. **Antes de escribir código, leé "Antes de empezar"** (rama
y entorno) y la wiki indicada al final: este repo no lleva la especificación funcional.

## Antes de empezar: rama y entorno

El working tree está en la rama **`desarrollo`** y el código está presente. Dos advertencias:

- **La rama `main` de este repo contiene únicamente el archivo `leeme`.** No es una base limpia ni una
  versión anterior útil: no cambies a `main` esperando encontrar el backend.
- Remoto: `github.com/typej2003/agendamedica` — es el repo compartido con el sistema legado, de ahí el
  nombre.

**`vendor/` y `.env` ya existen (verificado 2026-09-23)**, con un entorno local funcional: SQLite
(`DB_CONNECTION=sqlite`, `database/database.sqlite`, con datos de prueba ya sembrados — 7 médicos, 9
usuarios) en vez del MySQL con el esquema migrado que se usaría en producción. `php artisan serve`
corre tal cual. Si en una máquina nueva no existen:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# editar .env: DB_DATABASE / DB_USERNAME / DB_PASSWORD (SQLite local o el MySQL con el esquema migrado)
php artisan storage:link   # necesario para servir el logo (Paso 17) y la firma/sello del récipe (Paso 18.A)
php artisan route:list
```

⚠️ **No hay `.env.testing`**: correr `php artisan test`/PHPUnit con `RefreshDatabase` pisaría con
migraciones la base SQLite de desarrollo local (la que trae los datos de prueba de arriba). Los tests
que sí tocan base de datos usan `DatabaseTransactions` (rollback automático) — ver
`tests/Feature/ConfiguracionMedicoTest.php` como referencia. La suite completa (`php artisan test`)
corre sin filtro desde que se eliminó el `Medico/ListMedicos.php` duplicado (commit `dfc6ce6`).

Dos datos del entorno que no están en `.env.example`: las variables de **WhatsApp**
(`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_VERIFY_TOKEN`) las lee `config/services.php`
pero no aparecen en el ejemplo — hay que agregarlas a mano. Y **`SYNC_API_KEY`** (clave de sync del
escritorio) tampoco está en el ejemplo: `config/app.php` la lee con un default de transición (ver trampas
conocidas).

## Qué es

Backend / API en la nube de AppDDR, y a la vez el **receptor de la sincronización del sistema legado**
(PowerBuilder). Fuente de verdad de la **app activa**, [`../DoctorisimoMobile/`](../DoctorisimoMobile/AGENTS.md)
(Flutter), que sincroniza contra `POST /app/sync-app-data` (`SyncAppDataController`) y por defecto apunta
a una instancia local de este backend (`http://10.0.2.2:8000/api` desde el emulador).

También existe [`../DoctorisimoApp/`](../DoctorisimoApp/AGENTS.md), la app Android **antigua y
discontinuada** (Kotlin) — no la tomes como referencia de lo que este backend debe exponer hoy: apunta a
un backend ya **desplegado** (`https://mercadoexpres.com/api/`) que puede divergir de este código, y usa
endpoints previos (`app/refresh-data`) en vez de `sync-app-data`.

**Stack:** Laravel 8 (`laravel/framework ^8.0`), PHP `^8.0` (composer configurado con `platform 8.2.12`),
MySQL (`DB_CONNECTION=mysql`), tokens con **Laravel Sanctum 2.15**, roles/permisos con
**spatie/laravel-permission 6.25**, panel web con **Livewire 2.12**, CORS con `fruitcake/laravel-cors`.

Hay **dos superficies distintas** en el mismo Laravel, no las mezcles:

- **API** (`routes/api.php`) — la consume el app Android y el sistema legado para sincronizar. JSON.
- **Panel web** (`routes/web.php` + `routes/web/*.php`) — administración con Livewire, autenticación por
  sesión, roles de Spatie (`RoleAndUserSeeder` crea `Root`, `Medico`, `Secretaria`, `Paciente`,
  `Representante`).

## Estructura

| Ruta | Qué contiene |
|---|---|
| `routes/api.php` | Todos los endpoints de app y de sync. **Es el mejor mapa del backend: empezá por acá.** |
| `routes/web.php`, `routes/web/{admin,medico,customer}.php` | Panel web Livewire + login por sesión. |
| `app/Http/Controllers/Api/` | Controladores de la API (login, refresh, sync, upload, webhook WhatsApp). Incluye `AppAgendaMedicaController` y los `" - copia.php"`, que están muertos (ver trampas). |
| `app/Http/Controllers/Auth/` | Login web del panel (`CustomLoginController`). |
| `app/Http/Livewire/` | Componentes del panel: `Admin/*` (listados ~CRUD, `CargarSql`), `Medico/*`, `Components/*` (agenda/calendario), `Dashboard/*`, `Layouts/*`. |
| `resources/views/` | Blade: `auth/` (login + registro de médico/paciente), `livewire/` (una vista por componente, `admin/`, `medico/`, `dashboard/`, `layouts/`, `components/`) y `layouts/app.blade.php`. |
| `public/` | Document root (`index.php`, `.htaccess`); los assets propios llevan el nombre del legado: `public/css/gineco.css`, `public/js/gineco.js`. |
| `app/Models/` | Eloquent sobre las tablas del legado (`Cola`, `Paciente`, `Historia`, `Consulta`, `Medico`, `MedicalCenter`, `MotivoCita`, `Evolucion`, `UploadServer`, `User`…). |
| `app/Services/WhatsAppService.php` | Envío de plantillas por WhatsApp Cloud API (Meta, Graph API v19.0); lee `config('services.whatsapp.*')`. |
| `database/migrations/2026_08_25_055351_create_medical_tables_schema.php` | **Migración núcleo**: crea el esquema médico completo migrado del legado (~120 tablas, ~1650 líneas). Referencia obligatoria. |
| `database/migrations/2026_0*` | Tablas nuevas del proyecto (users, medicos, historias, medical_centers, medico_pacientes, upload_servers, offices, permisos). |
| `database/seeders/` | Países / estados / ciudades / especialidades / roles / datos médicos de ejemplo. |
| `config/` | Config Laravel estándar (`auth.php` define el guard `api` con driver **sanctum**; `services.php` las credenciales de WhatsApp). |
| `tests/` | `Feature/`: tests reales con `DatabaseTransactions` (sync del legado, credenciales, configuración, citas, récipes…). `php artisan test` corre la suite completa. |

## Endpoints principales (estado en la rama `desarrollo`)

**App móvil**

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/app/login` | `LoginAppController@login` — devuelve `access_token` Sanctum + `user_type` + roles/permisos. Desde el Paso 26.D también `must_change_password` (en la raíz y dentro de `user`): `true` si la clave es temporal. Es lo único que se agregó; si la clave es temporal o la cuenta está bloqueada, el login igual da token y lo que se corta es el resto del API (ver abajo). |
| `POST` | `/api/app/cambiar-password` | `CambiarPasswordController@cambiar` — `password_actual`, `password_nueva` (+`_confirmation`, mín. 8). Es lo único (junto a `GET /api/user`) que deja pasar `EnsurePasswordChanged` mientras la clave es temporal; las demás rutas `auth:api` responden 403 `password_change_required`. |
| `POST` | `/api/app/refresh-data` | `RefreshAppController@refreshData` — protegido por `auth:api`; acepta `mes`/`anio` y devuelve citas, colas, pacientes, motivos, centros médicos, historias y evoluciones del médico (o del paciente). |
| `POST` | `/api/app/sync-app-data` | `SyncAppDataController@sync` — sync delta real (push + pull), ver `12-arquitectura-offline-sync.md`. Desde el Paso 18.B también crea historia, consulta y récipe (`App\Sync\CreacionesClinicas`, número asignado por el servidor); desde el 18.B2, además, motivos de consulta: baja `motivos_consulta` y `motivo_consulta_paciente`, acepta la creación `motivo_consulta_paciente` (agrega un motivo del catálogo a una consulta) y `deleted` sobre ella lo quita. |
| `POST` | `/api/app/citas/{cola}/notificar` | `NotificacionCitaController@enviar` — WhatsApp, online-only, no pasa por la cola de sync. |
| `POST` | `/api/app/configuracion` | `ConfiguracionMedicoController@actualizar` — datos de reporte del médico (Paso 17: especialidad, logo, pie de récipe/informe) y desde el Paso 23 las plantillas de mensaje (`plantilla_cita`/`plantilla_cumple`); online-only, misma razón que el de notificar. |
| `POST` | `/api/app/motivos-consulta` | `MotivoConsultaController@crear` — alta en el catálogo de motivos de consulta del médico (Paso 18.B2), online-only: `descripcion` → `id`, `codemotivo` (correlativo de 4 dígitos); si ya hay una igual devuelve la existente (200) en vez de duplicar. |
| `POST` | `/api/app/configuracion/formato-recipe` | `RecipeFormatoController@actualizar` — formato de impresión del récipe (alineación/fuente/estilo por elemento, color de línea, tamaño del logo) + firma/sello (Paso 18.A). Parcial, online-only; el sync lo devuelve completo en `formato_recipe`. |

**Cuentas de acceso** (Paso 26, `app/Services/CuentaService.php`). **El inicio de sesión (API y web) no se
tocó**: `users` con roles Spatie (`Root`, `Administrador`, `Medico`…) y `medicos.user_id` como vínculo con el
doctor, como ya era. Lo nuevo se apoya en eso:
- `users.must_change_password`, `users.is_active`, `users.blocked_reason` (migración `2026_10_03_200000`).
- Toda escritura de claves, roles y bloqueos pasa por `CuentaService`, que escribe `users.password` **y** espeja
  `medicos.password` (el login prueba `users` y, si falla, `medicos`: escribir una sola dejaría dos claves válidas).
- Dos middlewares que actúan **después** de iniciar sesión: `EnsurePasswordChanged` (con clave temporal solo pasan
  `POST /api/app/cambiar-password` y `GET /api/user`; el resto responde 403 `password_change_required`; en web
  redirige a `/cambiar-password`) y `EnsureAccountActive` (cuenta bloqueada → 403 `account_blocked` / cierra la
  sesión web). Bloquear borra los tokens y **no** toca las credenciales de sync del escritorio.
- `User::esAdministrador()` (rol Root o Administrador), `User::administradores()` (scope; no usar `User::role([...])`
  de Spatie con roles que pueden no existir: lanza excepción) y `User::medico()`.
- El primer administrador se crea con `php artisan cuentas:admin {email}`.
- **Sección "Usuarios" del panel** (`/admin/cuentas`, componente Livewire `Admin\Cuentas`, ruta en
  `routes/web/cuentas.php`; Root o Administrador): lista de médicos y de administradores, alta con clave temporal
  (se muestra una sola vez), crear acceso a un médico sin cuenta y **editar** nombre, correo, teléfono y licencia
  (el `reg_medico` no se edita: es la llave de sus datos en la nube). Cada fila trae "Editar" y un **menú de 3
  puntos** con Roles (los marcados quedan exactamente así: `CuentaService::asignarRoles`; solo un Root da o quita
  Root; nadie se quita su propio acceso ni deja sin administradores), Resetear clave, Generar API key (el mismo
  trait `Concerns\EmiteApiKeys` que usa "API Keys") y Bloquear/Desbloquear. **Sustituye** al enlace "Usuarios" del
  panel lateral y a las tarjetas del escritorio
  Root; el `ListUsers` viejo (`/users`, `/admin/users`) sigue existiendo pero ya no está enlazado (reemplaza TODOS
  los roles de un usuario al editarlo y fija claves sin marcarlas como temporales). Cada acción de Livewire vuelve a
  comprobar el permiso en `hydrate()`: la ruta solo protege la carga de la página. "Permisos de Usuario" se quitó del panel lateral
  (no funcionaba; `/users/permissions` sigue en `routes/web.php`, sin enlace). **Cerrar sesión** pide confirmación
  en el panel lateral y en el menú del usuario (`confirmarCierreSesion` en `layouts/app.blade.php`, SweetAlert2).
- **Sección "API Keys"** (`/admin/api-keys`, `Admin\ApiKeys`, misma ruta-archivo `routes/web/cuentas.php`): genera y
  revoca la credencial de sync por médico (`SyncCredencialService`, el mismo que usa `php artisan sync:credencial`)
  y muestra por médico la última sincronización del escritorio y del app, la carga inicial, las API keys activas y
  los conteos de pacientes e historias (`SyncResumenService`, en lote por página). El token en claro se muestra una
  sola vez (copiar o descargar `sync-token.txt`); en la base solo queda su SHA-256.

**Sync del sistema legado PowerBuilder**

Todos autentican con `App\Services\SyncAuthService`: `X-API-KEY` (clave estática de transición) o
`Authorization: Bearer` (credencial por equipo, `php artisan sync:credencial emitir|listar|revocar`).
Hasta el 2026-09-27 los tres `.../sincronizar` no tenían ninguna autenticación.

*Carga inicial* (botón "Sincronización completa" del escritorio; sin el `throttle:api` de 60/min, con
`throttle:600,1` propio). Reglas en `App\Services\CargaInicialService`; tablas aceptadas en
**`config/sync_legado.php`** (lista blanca, 102 tablas); tests en `tests/Feature/CargaInicialTest.php`.

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/sync/carga-inicial/iniciar` | `CargaInicialController@iniciar` — solo si el `reg_medico` no tiene datos (primera carga); si hay una carga cortada, la retoma y devuelve cuántas filas tiene de cada tabla. |
| `POST` | `/api/sync/carga-inicial/lote` | `@lote` — idempotente por posición (`desde`): reenviar un lote no duplica; `409 posicion_incorrecta` + `esperado` si hay hueco. `pacientes` crea paciente + `medico_pacientes` + `historias`; el resto se inserta con el `reg_medico` de la carga. |
| `POST` | `/api/sync/carga-inicial/finalizar` | `@finalizar` — cierra solo si llegaron todas las filas anunciadas. Después, `iniciar` para ese médico da `409 carga_ya_completa`. |

*Sincronización de cambios* (Fase 2, mismos límites; `App\Sync\Escritorio\CambiosEscritorio`, tests en
`tests/Feature/CambiosEscritorioTest.php`, diseño en `../bridge/DISENO-FASE-2.md`):

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/sync/cambios/estado` | `CambiosEscritorioController@estado` — si el médico completó la carga inicial y qué tablas vigilar. |
| `POST` | `/api/sync/cambios/subir` | `@subir` — aplica lo que cambió en el escritorio. Traduce números de historia/consulta con `clave_escritorio` (historias, consultas, cola); "gana la última edición" por columna con `sync_changes` (solo cuentan ediciones del app). |

*Endpoints viejos* (grupo con `throttle:1000,1`; los usaban los botones que el escritorio ya no tiene):

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/sync/upload-batch` | `SyncController@uploadBatch` — subida genérica por nombre de tabla, inserta en chunks de 500 **sin deduplicar** y en **cualquier** tabla que exista (no usa la lista blanca). Caso especial para `paciente(s)`. |
| `POST` | `/api/pacientes/sincronizar` | `PacienteSyncController@sincronizar`. |
| `POST` | `/api/consultas/sincronizar` | `ConsultaSyncController@sincronizar`. |
| `POST` | `/api/cola/sincronizar` | `ColaSyncController@sincronizar` — inserta solo colas inexistentes; con `es_ultimo_lote` deja bitácora en `upload_servers`. |

**WhatsApp** (Meta Cloud API): `GET|POST /api/whatsapp/webhook` (`WhatsAppWebhookController`),
`POST /api/whatsapp/send-reminder` (plantilla `notificacion_paciente`), y `GET /test-whatsapp` en
`routes/web.php` (endpoint de prueba con número hardcodeado). También `GET|POST /api/upload-servers/*`
para auditar lotes de sync.

## Cómo verificar cambios

```powershell
php artisan route:list            # mapa real de rutas (después de armar el entorno, ver arriba)
php artisan migrate --pretend     # revisar el SQL de una migración nueva
php artisan test                  # PHPUnit; los tests presentes son plantillas de Laravel
```

**Nunca commitear `.env` ni datos reales de pacientes** (el repo arrastra el esquema y los seeders del
sistema legado).

## Convenciones y nomenclatura del dominio

- **El esquema es del legado**: tablas en `snake_case` español (`cola`, `consultas`, `motivos_consulta`,
  `examen_fisico`…), con nombres de columna heredados de PowerBuilder. No renombres ni "normalices" sin
  confirmar: hay compatibilidad de datos en juego. Detalle en
  [../Docs/Wiki/07-modelo-de-datos.md](../Docs/Wiki/07-modelo-de-datos.md).
- **`reg_medico`** es el registro profesional del médico y funciona como **clave de negocio** (el
  modelo `MedicoRegistro` lo liga a `medicos.id`); **`numhistoria`** es la clave de negocio del paciente.
  Casi todas las consultas cruzan por esas dos columnas, no por `id`.
- La tabla pivote `medico_pacientes` relaciona médico ↔ paciente y lleva `numhistoria` y `reg_medico`.
- Código y datos en **español**, archivos UTF-8.
- Los controladores de API crecen rápido y son muy explícitos (mapeos manuales a mano). Si agregás un
  campo a una respuesta, revisá **todos** los caminos por tipo de usuario (`Medico`, `Paciente`, `Root`).

## Trampas conocidas (verificadas al crear esta guía)

1. **Hay un renombrado a medias en los controladores de sync.** Aparecen expresiones duplicadas del mismo
   campo, del tipo `$request->input('reg_medico') ?? $request->input('reg_medico')` y
   `->orWhere('reg_medico', …)->orWhere('reg_medico', …)`. Releé el archivo completo antes de editar y no
   copies ese patrón.
2. **La clave estática de sync tiene un default público:** `config/app.php` →
   `'sync_api_key' => env('SYNC_API_KEY', ...)`, con el mismo valor que tenía hardcodeado el `.pbl`
   (se dejó para no cortar las instalaciones durante la transición). Hasta definir `SYNC_API_KEY` en el
   `.env` (o `php artisan sync:clave-estatica --rotar`), cualquiera que conozca ese valor puede subir
   datos. No lo repitas en otros archivos.
3. **Laravel convierte `""` en `null`** (middleware global `ConvertEmptyStringsToNull`) y recorta
   espacios (`TrimStrings`). Para datos del legado eso rompe columnas `NOT NULL` (p. ej.
   `imagen_pacientes.imagen`, vacía en casi todas las filas) y altera los textos: los endpoints de la
   carga inicial leen el JSON crudo (`$request->getContent()`) a propósito.
4. **El app Android espera `evolucion` (objeto) y este backend devuelve `evoluciones` (array)** —
   `RefreshAppController`. Confirmá contra el backend desplegado antes de tocar cualquiera de los dos
   lados.
5. **El endpoint `POST /api/request-date`** que llama el app (pantalla vieja `AgendaActivity`) **no
   existe** en `routes/api.php` de esta rama: el backend desplegado y el repo divergen.
   El flujo vivo del app es `POST /api/app/refresh-data`.
6. **Archivos muertos** que parecen vivos y engañan al leer: los que terminan en `" - copia.php"`
   (`UploadServerController - copia.php`, `CargarSql - copia.php`), y
   **`AppAgendaMedicaController`** (300 líneas, método `authCitaMedica`): es una versión anterior del
   login + agenda que **no está enganchada en ninguna ruta**. No lo edites ni lo tomes de modelo; el
   camino vivo es `LoginAppController` + `RefreshAppController`.
7. **`RefreshAppController`** resuelve el usuario con `$request->user()` y, si no hay, con `user_id` del
   body. Tenelo presente al depurar 401.
8. **El rol `Administrador` no existe en el seeder pero sí en las rutas**: `routes/web.php` y
   `DashboardController` lo referencian (`role:Root|Administrador`), mientras que `RoleAndUserSeeder`
   solo crea `Root`, `Medico`, `Secretaria`, `Paciente` y `Representante`. Si tocás permisos, verificá
   cuál de los dos lados es el que manda.
9. **`RoleAndUserSeeder` crea un usuario Root por defecto con contraseña conocida**
   (`root@admin.com` / `12345678`). No lo dejes habilitado en un entorno publicado.

## Dónde está el detalle funcional

Este repo no lleva la especificación: está en la wiki.
[Índice](../Docs/Wiki/index.md) ·
[Modelo de datos](../Docs/Wiki/07-modelo-de-datos.md) ·
[Arquitectura offline/sync](../Docs/Wiki/12-arquitectura-offline-sync.md) ·
[Agenda](../Docs/Wiki/02-modulo-agenda.md) ·
[Notificaciones y recordatorios](../Docs/Wiki/03-notificaciones-recordatorios.md) ·
[Récipes y solicitudes](../Docs/Wiki/05-recipes-y-solicitudes.md) ·
[Contexto general](../AGENTS.md).

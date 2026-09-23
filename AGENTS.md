# MedicAPI — backend de AppDDR

Guía de entrada para trabajar en este repo. **Antes de escribir código, leé "Antes de empezar"** (rama
y entorno) y la wiki indicada al final: este repo no lleva la especificación funcional.

## Antes de empezar: rama y entorno

El working tree está en la rama **`desarrollo`** y el código está presente. Dos advertencias:

- **La rama `main` de este repo contiene únicamente el archivo `leeme`.** No es una base limpia ni una
  versión anterior útil: no cambies a `main` esperando encontrar el backend.
- Remoto: `github.com/typej2003/agendamedica` — es el repo compartido con el sistema legado, de ahí el
  nombre.

**`vendor/` y `.env` no existen todavía**, así que ningún comando `php artisan` va a funcionar hasta
armar el entorno (PHP 8.2.1 y Composer 2.9.7 ya están instalados en la máquina):

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
# editar .env: DB_DATABASE / DB_USERNAME / DB_PASSWORD contra el MySQL con el esquema migrado
php artisan route:list
```

Dos datos del entorno que no están en `.env.example`: las variables de **WhatsApp**
(`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_VERIFY_TOKEN`) las lee `config/services.php`
pero no aparecen en el ejemplo — hay que agregarlas a mano. Y **`sync_api_key` no existe ni en
`.env.example` ni en `config/app.php`** (ver trampas conocidas).

## Qué es

Backend / API en la nube de AppDDR, y a la vez el **receptor de la sincronización del sistema legado**
(PowerBuilder). Fuente de verdad del cliente Android
([../DoctorisimoApp/AGENTS.md](../DoctorisimoApp/AGENTS.md)). El backend desplegado al que apunta el app
es `https://mercadoexpres.com/api/`.

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
| `tests/` | Solo las plantillas de Laravel (`ExampleTest` en `Feature/` y `Unit/`, `CreatesApplication`, `TestCase`): no hay cobertura real. |

## Endpoints principales (estado en la rama `desarrollo`)

**App móvil**

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/app/login` | `LoginAppController@login` — devuelve `access_token` Sanctum + `user_type` + roles/permisos. |
| `POST` | `/api/app/refresh-data` | `RefreshAppController@refreshData` — protegido por `auth:api`; acepta `mes`/`anio` y devuelve citas, colas, pacientes, motivos, centros médicos, historias y evoluciones del médico (o del paciente). |

**Sync del sistema legado PowerBuilder** (grupo con `throttle:1000,1`)

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/sync/upload-batch` | `SyncController@uploadBatch` — subida genérica por nombre de tabla; valida `X-API-KEY`, sanea el nombre de tabla, inserta en chunks de 500. Caso especial para `paciente(s)`. |
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
2. **`SyncController` valida la API key contra un default hardcodeado:**
   `config('app.sync_api_key', 'MiClaveSecreta123!')`. Esa clave **no está definida ni en `.env.example`
   ni en `config/app.php`**, así que el valor por defecto es el que rige hoy. No lo versiones ni lo
   repitas en otros archivos.
3. **El app Android espera `evolucion` (objeto) y este backend devuelve `evoluciones` (array)** —
   `RefreshAppController`. Confirmá contra el backend desplegado antes de tocar cualquiera de los dos
   lados.
4. **El endpoint `POST /api/request-date`** que llama el app (pantalla vieja `AgendaActivity`) **no
   existe** en `routes/api.php` de esta rama: el backend desplegado y el repo divergen.
   El flujo vivo del app es `POST /api/app/refresh-data`.
5. **Archivos muertos** que parecen vivos y engañan al leer: los que terminan en `" - copia.php"`
   (`UploadServerController - copia.php`, `CargarSql - copia.php`), y
   **`AppAgendaMedicaController`** (300 líneas, método `authCitaMedica`): es una versión anterior del
   login + agenda que **no está enganchada en ninguna ruta**. No lo edites ni lo tomes de modelo; el
   camino vivo es `LoginAppController` + `RefreshAppController`.
6. **`RefreshAppController`** resuelve el usuario con `$request->user()` y, si no hay, con `user_id` del
   body. Tenelo presente al depurar 401.
7. **El rol `Administrador` no existe en el seeder pero sí en las rutas**: `routes/web.php` y
   `DashboardController` lo referencian (`role:Root|Administrador`), mientras que `RoleAndUserSeeder`
   solo crea `Root`, `Medico`, `Secretaria`, `Paciente` y `Representante`. Si tocás permisos, verificá
   cuál de los dos lados es el que manda.
8. **`RoleAndUserSeeder` crea un usuario Root por defecto con contraseña conocida**
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

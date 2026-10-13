# Trampas conocidas de MedicAPI

Son las trampas **medidas** del repo (no teoría): varias ya costaron una tarde de depuración. Se leen
**antes de escribir código** y **cada tarea agrega la suya al final**, con la fecha y la tarea que la
encontró. El estado del código, los comandos y el mapa del repo están en
[`AGENTS.md`](AGENTS.md). Van **93** y el archivo se lee entero en una sola pasada.

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
   (`root@admin.com` / `12345678`), igual que `UserSeeder`, `MedicalDataSeeder` y `FakeClinicalDataSeeder`
   (médicos de ejemplo, también con `12345678`). Con `APP_ENV=production` ya **no** se siembran. Si una base
   publicada los tiene de antes: crea tu administrador con `php artisan cuentas:admin` y corre
   `php artisan produccion:limpiar-pruebas` (simula por defecto; `--confirmar` borra; `--conservar=correo`;
   ver el Paso 28 del `ROADMAP.md`).
10. **`->timestamp()` no nullable en la primera columna TIMESTAMP de una tabla se auto-actualiza sola** en
    MySQL/MariaDB con `explicit_defaults_for_timestamp` deshabilitado (el default en MariaDB y MySQL ≤ 8.0):
    el motor le agrega `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`, así que **cualquier UPDATE de
    la fila pisa esa columna con "ahora"** si el UPDATE no la menciona. Pasó de verdad (2026-10-07): las API
    keys del escritorio "duraban 24 horas" porque `sync_credentials.expires_at` era `timestamp NOT NULL` y
    `SyncAuthService` guarda `last_used_at` en cada request; la credencial vencía en su primer uso. Para
    fechas que no son "cuándo se tocó la fila" usá `->dateTime()` (además evita el techo de 2038 y la
    conversión por zona horaria). Corregido por `2026_10_07_120000_corrige_expires_at_de_sync_credentials`;
    `expires_at` era la única columna del esquema con ese patrón.
11. **`@disabled`, `@checked` y `@selected` de Blade son de Laravel 9, no del 8.** Este repo está en
    Laravel 8: la directiva **no compila** y el navegador muestra el texto `@disabled(…)` al lado del
    control (pasó en el `select` de sede de "Nueva cita" y en el botón del envío del día, WEB-2.6/2.7). Se
    escribe el atributo: `@if ($x) disabled @endif`.
12. **El `->boolean()` de Laravel no sirve igual en SQLite que en MySQL, y las fechas casteadas llevan
    hora.** Dos cosas que se midieron al hacer WEB-2.9 y conviene no repetir:
    - `evolucion.enviar_cumpleanos` es un **entero nullable** (1 = encendido, `null` = apagado) y **no**
      un `boolean`: en SQLite el `boolean` no tiene afinidad y un `false` casteado se guarda como `''`
      (texto), que `where('enviar_cumpleanos', true)` levanta como si estuviera encendido. Con el entero,
      apagado es `null` y nada más.
    - Una columna `date` casteada a `'date'` **se escribe con la hora** (`2026-10-10 00:00:00`, la del
      `$dateFormat` del modelo), así que `where('fecha_cumple', '2026-10-10')` **no la encuentra**: hay
      que usar `whereDate('fecha_cumple', '2026-10-10')`. Pasó en
      `App\Cumpleanos\RegistroDeCumpleanos` y el síntoma era un índice único que "no protegía nada".
13. **El `LIKE` de SQLite sólo pliega mayúsculas ASCII, y las fechas nulas del legado son una familia.**
    Dos cosas medidas al cerrar WEB-3.1:
    - Buscar `jaquez` **no** encontraba `Jáquez` en SQLite (0 resultados), mientras que en MySQL la colación
      `utf8mb4_unicode_ci` lo encontraba: la misma búsqueda se comportaba distinto en desarrollo y en
      producción. Cuando la comparación tenga que ser la misma en los dos motores, se normaliza la
      **columna** con `REPLACE` (existe en SQLite y en MySQL) y la palabra en PHP, como hace
      `App\Clinica\Pacientes\BusquedaDePacientes`.
    - `FechaClinica` cubre la familia `0000-00-*`, no sólo `0000-00-00`: Carbon lee `0000-00-01` como el
      **año -1** (no lanza excepción) y una edad calculada con eso daba **2026 años**. Una edad tampoco se
      calcula con un año imposible ni con una fecha futura, porque `diffInYears` es absoluto.
14. **El modelo `SegEmp` apunta a `seg_emps`, que no existe** (la tabla del legado es `seg_emp`, singular,
      como la creó `2026_09_27_110000_create_tablas_legado_faltantes`). Su relación `Paciente::segEmp()`
      revienta si se usa; el catálogo de seguros de la ficha de paciente (WEB-3.2) se lee con
      `App\Clinica\Pacientes\CatalogoDeSeguros` sobre el nombre real. Corregir el `$table` del modelo o
      borrarlo (no lo usa nadie) está anotado en `../ROADMAP.md` §3: **no** es de la tarea que lo encontró.
      Y ojo con el modelo `Paciente`: su `$fillable` lista `numhistoria`, que **no** es columna de
      `pacientes` (vive en `medico_pacientes` y en `historias`). No rompe por sí solo —la lista blanca de
      `SyncAppDataRequest` tampoco deja pasar esa clave y el sync del móvil exige cédula para crear un
      paciente—, pero es una entrada fantasma que invita a escribirla.
15. **`where(new Expression(...))` de Laravel 8 no sirve para una condición SQL cruda: la mete en los
      *bindings*.** Medido al hacer WEB-3.3: el filtro "Sin historia" del listado de pacientes
      (`App\Clinica\Pacientes\FiltroDePacientes`) devolvía **cero** filas con
      `->where(FiltroDePacientes::expresionSinHistoria())` mientras la misma tabla mostraba "Sin historia"
      en la fila — y el contador del segmentado acusaba lo mismo. Con **`->whereRaw(...)`** la condición se
      pega tal cual y el filtro y su contador coinciden. Si una condición tiene que ser idéntica en SQLite y
      en MySQL, seguí el patrón de `BusquedaDePacientes`: `TRIM`/`COALESCE`/`REPLACE`/`LOWER` existen en los
      dos y la palabra se normaliza en PHP.
16. **El `<x-ui.buscador>` no manda ningún token: es un `GET`.** Consecuencia al probar con Playwright:
      en un listado **sin** formularios de escritura (el listado de pacientes, o la ficha de un paciente que
      ya tiene historia) **no hay ningún `<input name="_token">` en la página** —el layout clínico tampoco
      publica `meta[name=csrf-token]`—, así que un `fetch` a mano con el token "de la página" no tiene de
      dónde sacarlo. Para disparar un POST desde el navegador hay que leer el token de una pantalla que sí
      tenga un formulario con `@csrf` (p. ej. el alta o la edición de un paciente), que es la misma sesión.
17. **`<x-ui.campo>` busca el error con la misma clave que `for`, no con el `name` del control.** Medido al
      hacer WEB-3.4: el diálogo del representante tiene que usar `id="representante-cedula"` para no chocar
      con el `cedula` del diálogo del cambio de cédula de la misma pantalla, y su `name` es `cedula` (que es
      lo que valida el servidor). Con `for="representante-cedula"` el `@error` buscaba esa clave, no existía,
      y **el mensaje no aparecía debajo del campo** —aunque el error sí estaba en la bolsa y la franja de
      arriba lo mostraba—. El componente acepta ahora `error="cedula"` para separar el `id`/`for` de la clave
      del error: si el `id` de un control no es el `name`, hay que pasar `error`.
18. **`/clinica/pacientes/{paciente}/historia` es dos rutas: `POST` (asignar el número, WEB-3.3) y `GET` (la
      historia, WEB-3.5).** Comparten el path y cambian el verbo, que es lo correcto en REST —y Laravel las
      distingue por método—, pero tiene una consecuencia al escribir tests: **`route('clinica.pacientes.asignar-historia', $p)`
      y `route('clinica.pacientes.historia', $p)` devuelven el mismo texto**. Una aserción del tipo
      `assertDontSee(route('clinica.pacientes.asignar-historia', $p))` sobre la ficha dejó de probar lo que
      probaba —el enlace "Ver la historia" tiene ese href— y hubo que medir la ausencia por **el formulario**
      (`action="…"`). Si agregás una ruta hermana, comprobá que los `assertDontSee` de URL sigan diciendo algo.
19. **La regla "la consulta es sólo del médico" decide tres cosas que no pueden divergir — y desde
    WEB-4.9 incluye "Atender".** La ruta del shell está en un grupo `role:Root|Medico` **dentro** del grupo
    `clinica.contexto` (no al revés: el contexto se necesita para leer la consulta) y la secretaría recibe
    **403**, que es lo que el test afirma (`assertForbidden`). De la misma regla dependen la fila de la
    agenda —que a la secretaría **no** le pinta "Atender" (ni el botón ni el ítem del menú) ni "Editar
    consulta"— y el **rechazo del POST `/clinica/agenda/{cola}/atender`**, que responde **403** y **no toca
    nada**: ni `cola.atendido`, ni la confirmación que "atender implica confirmada", ni `sync_changes` (una
    versión anterior marcaba la cita y devolvía a la agenda con un aviso; el usuario la cambió el
    2026-10-11: **"Atender es sólo del médico"**). Por eso la regla vive en `User::trabajaLaConsulta()` y la
    bandera **baja desde `AgendaController`** hasta la fila (`AgendaVista` → `JornadaVista` →
    `FilaDeCitaVista`): no se consulta el rol en la vista. El chequeo del controlador va **antes** de
    resolver la cita, igual que el middleware: la respuesta no depende de si la cita existe.
20. **"Editar consulta" abre un diálogo que no navega, y el "Atender" de una cita sin confirmar vive en
    el menú.** Dos consecuencias para los tests de navegador (Playwright): (a) el botón de la fila **no**
    envía el formulario —`shell.js` lo intercepta para ofrecer "re-atender o consulta nueva"—, así que
    `waitForNavigation` después del clic **tiene que ir después de apretar el botón del diálogo**, no antes;
    (b) con la cita sin confirmar la acción "Atender" está **dentro del `<details class="menu-acciones">`**
    (cerrado, no visible) y el botón principal es "Confirmar": para tocar "Atender" hay que **confirmar
    primero**, que es el camino real del mostrador.
21. **"Ahora" para la agenda es el del consultorio, no el del servidor.** `LectorDeAgenda` usaba
    `Carbon::now()` (UTC) para decidir si cada fila ofrece "Confirmar" (desde una hora antes) y "Atender"
    (sólo el día de la cita): en un consultorio de Venezuela (UTC-4) esos botones se **apagaban a las 20:00
    locales**, porque en UTC ya es el día siguiente. Es `FechaDelConsultorio::ahora()` (lo mismo que la
    decisión de cumpleaños, WEB-2.9). Si algo decide "hoy" o "ahora" para el negocio, va por ahí — y un test
    con `Carbon::setTestNow()` **no lo ve** (los dos relojes quedan congelados en el mismo instante).
22. **Una ruta con dos parámetros no se arma concatenando el segundo.** Medido al hacer WEB-4.2: el "Quitar"
    de un motivo tiene que apuntar a `DELETE /clinica/consulta/{consulta}/motivos/{motivo}`, y el ViewModel
    recibía el prefijo con `route('clinica.consulta.motivos.quitar', [$consulta->id, 0])` para después
    pegarle el id de la fila. El `0` queda en la URL **como segmento** (`…/motivos/0/5433`), que no matchea
    ninguna ruta y da **404** (el `{motivo}` de esa ruta es un `int` tipado, así que un segmento de más no
    entra). La URL de la fila se arma con **una función que recibe el id** y llama a `route(..., [$consulta, $id])`
    (el ViewModel la recibe ya resuelta por el controlador, como todas las URLs de la web clínica). Cualquier
    `route()` con dos parámetros donde uno sale de la fila tiene el mismo problema.
23. **Un `[hidden]` con una utilidad de flex de Bootstrap se ve igual.** Medido al verificar WEB-4.2 en
    Chrome: el aviso "hay cambios sin guardar" es `.alert alert-warning d-flex … hidden` y **se veía** aunque
    el JS lo dejara oculto, porque Bootstrap define `.d-flex { display: flex !important }` y eso le gana al
    `[hidden] { display: none }` del navegador (el `!important` del atributo no existe: es una regla de la
    hoja del user-agent). `doctorisimo-ui.css` trae ahora `[hidden] { display: none !important }`, así que el
    atributo vuelve a significar lo que dice en toda la web. Si un elemento oculto "aparece", mirá primero
    qué clase de display tiene.
24. **El buscador en vivo y los resultados que pinta el servidor no pueden compartir el `id`.** Medido al
    hacer WEB-4.2: `<x-ui.buscador-vivo>` ya renderiza su `#…-resultados`, así que la sección que además
    pinta el catálogo con el servidor (para que funcione sin JS) tenía **dos** elementos con el mismo `id`:
    `getElementById` devolvía el primero y el `fetch` del buscador llenaba el segundo. La sección de motivos
    escribe el campo a mano (mismas clases y mismos `aria-*`) y deja el `#motivo-resultados` una sola vez.
25. **`examen_fisico_nuevo.consulta` es el NÚMERO de la consulta, no su id — y el camino genérico del
    sync lo traducía al id.** Medido al verificar WEB-4.3 en Chrome: el examen físico de la consulta vive
    en `examen_fisico_nuevo` y su clave es `(historia, consulta)`, donde `historia` es el **número de
    historia** y `consulta` el **número de la consulta** (`nroconsulta`) —así la declara el DataWindow del
    escritorio, `d_exa_fi_fisico.srd`, y así la escribe con `INSERT ... VALUES (:Historia, :Consulta, …)`
    (`w_consulta_principal.srw:5020`) y la vuelve a buscar el `UPDATE` (`:2790`)—. En la base real, de las
    19.900 filas de un médico, **19.899** cruzan por `(numhistoria, nroconsulta)` y **ninguna** por el id.
    Tres consecuencias: (a) `App\Clinica\Consulta\ExamenFisicoDeLaConsulta` busca por el número —buscar por
    el id **no encuentra la fila del escritorio y crea una nueva al lado**—; (b)
    `CambiosEscritorio::guardarGenerico` **traducía** esa columna al id del API con el resto de las tablas
    hijas (que sí lo necesitan, como `examen_paciente`), así que el escritorio escribía en otro espacio de
    claves que la carga inicial y que la web: ahora `traducir()` devuelve el número por referencia y el
    examen lo usa (test en `CambiosEscritorioTest::test_el_examen_fisico_guarda_el_numero_de_la_consulta_y_no_su_id`);
    y (c) al limpiar datos de esa tabla, **acotá siempre por `historia` y `reg_medico`**: filtrar sólo por
    `consulta` se lleva las filas de todos los pacientes que tengan una consulta con ese número (pasó al
    escribir el ayudante de verificación de WEB-4.3: borró 6 filas del dataset de prueba).
26. **`examen_fisico_nuevo` y `consultas` no guardan "el examen físico" donde los nombres sugieren.** El
    examen general está **partido**: la antropometría y los signos en `consultas` (`peso`, `talla`, `fc`,
    `pp`, `circcefalica`, `circtoraxica`, `circabdominal`) y la tensión y los doce hallazgos en
    `examen_fisico_nuevo`. Y las columnas `consultas.faringe`, `nariz`, `oido`, `laringe`, `cuello` y
    `resultadoexamencomp`, que parecen el examen general, son la **copia que el escritorio hace de ítems de
    F6** (`faringe` recibe la citología, `nariz` el texto del examen, `laringe` el tratamiento, `oido` el
    resultado de otros estudios, `cuello` la histeroscopia: `w_consulta_principal.srw:2816-2837`); por eso
    están casi vacías en los datos reales. El mapa completo, con su fuente, está en
    `App\Clinica\Consulta\ExamenFisicoDeLaConsulta` y en la wiki `04-historia-clinica.md`.
27. **`FakeClinicalDataSeeder` siembra las historias 1001–1050 y reparte 80 consultas al azar entre ellas.**
    Medido al arreglar el test flaky de WEB-4.5: `ConsultaWebTest::test_la_numeracion_sigue_el_correlativo_de_la_historia`
    fallaba ~1 de cada 4 corridas porque buscaba la consulta por `numhistoria` **sin acotar al `reg_medico`
    de la prueba** y leía la fila sembrada de `gineco-00001` (que ese día tenía 4, 5 o 7 consultas) en vez de
    la suya. **Regla para los tests:** toda aserción sobre una tabla que el seeder también llena (`consultas`,
    `cola`, `pacientes`…) va acotada por el `reg_medico` (o el `paciente_id`) que la prueba creó —el helper
    `paciente()` del test usa `Historia::create(['numhistoria' => 1001])`, que colisiona con la siembra—; y si
    el test crea su propio caso, que el número de historia no caiga en el rango sembrado. No es un problema de
    la regla del correlativo: `CrearConsulta` mide sobre `reg_medico` + `numhistoria` y siempre estuvo bien.
28. **`diagnostico_paciente` se llavea por `nrohistoria` (no `numhistoria`) y su descripción vive en
    `detalle_diagnostco`.** Medido al hacer WEB-4.5: las columnas de la tabla son `nrohistoria`,
    `nroconsulta`, `codediagnostico` (las tres, clave), `detalle_diagnostco` (char(100)) y `orden` —
    `consultas` y `motivo_consulta_paciente`, en cambio, usan `numhistoria`—, así que un `where('numhistoria', …)`
    contra esa tabla **no encuentra nada** (y una aserción con esa clave falla en silencio). Y
    `detalle_diagnostco` **no es un campo aparte**: el `INSERT` del escritorio copia ahí la `descripcion` del
    catálogo (`w_consulta_principal.srw:11860-11868`) porque el DataWindow de la pestaña **no cruza** con
    `diagnosticos` —su `retrieve` es sólo de `diagnostico_paciente`—, o sea que una fila creada sin detalle se
    ve **vacía** en el escritorio; es también la única columna editable de la tabla (`edit.limit=100`, y la
    guarda el `dw_8.update()` del `ue_guardar`, `:2709`). `orden` queda `null`, como lo deja la fuente.
29. **`consultas.observaciones` es la COLPOSCOPIA, no un campo de la evolución — y la tabla `evolucion` no
    tiene nada que ver con la evolución de una consulta.** Medido al hacer WEB-4.6, que es el ítem 9 del
    panel y el plan de la web listaba como *"evolución, observaciones"* (`PLAN-WEB.md` §2). Son **dos
    confusiones de nombre** que cuestan caro:
    - La **evolución** es una **columna de la consulta**: `consultas.evolucion` (`longText`), llaveada por
      `numhistoria` + `nroconsulta` —así la escribe el `UPDATE "consultas"` de la ventana
      (`w_consulta_principal.srw:2835`, tomada del campo en `:2738`) y así la relee
      (`SELECT evolucion, fecha … WHERE numhistoria = :historia AND nroconsulta = :alNewRow`,
      `:4218-4242`)—. **La tabla `evolucion` es la configuración del médico**: su logo, su membrete, su
      horario, su `contrasena` del sistema viejo y la `clave` con la que `cola.medico` lo identifica
      (una fila por médico). Un `where('numhistoria', …)` contra `evolucion` no significa nada y un
      `Evolucion::create()` crea una fila de configuración.
    - **`consultas.observaciones`** (la columna vecina) **no es el segundo campo de la evolución**: el
      `ue_guardar` la escribe desde el campo *Colposcopia* de la solapa *Examen Ginecológico* —
      `s_obs = tab_1.tabpage_examen.tab_7.tabpage_31.mle_4.text` (`:2757`) y `"observaciones" = :s_obs`
      (`:2836`), con el comentario `SET "colposcopia" = :s_obs` en `:2766`— y al recargar la vuelve a poner
      en **ese** campo (`:2958`). `tabpage_31` es la solapa de colposcopia (sus botones imprimen
      `d_colposcopia_1`/`d_colposcopia_2`, `:10608`/`:10614`; su catálogo se `retrieve('CO')`, `:3043`).
      Es de **F6/WEB-4.7** y la sección de la web **no la lee, no la edita y no la borra** (un test lo
      fija). Lo mismo vale para el **tratamiento** (`tabpage_40.mle_11` → la copia `consultas.laringe`,
      `:2762` y `:2832`), que es la pestaña *TRATAMIENTO* del panel (F5).
    Las **plantillas** de la evolución son la lista `formato_print` con `tipo = 'EV'` (el `dw_84`, dataobject
    `d_l_enfe_actu_para`, `retrieve('EV')` en `:3055`; el doble clic copia la `descripcion` en el campo,
    `:12002`); es el **mismo** DataWindow que usa la enfermedad actual, que se distingue por `tipo = 'EA'`.

30. **La regla `mimetypes` de Laravel 8 rechaza TODA subida de un formulario (y `getPath()` de un archivo
    subido es vacío).** Medido al hacer WEB-4.7, con la primera versión de la validación de la subida de
    imágenes: `validateMimetypes()` exige `$value->getPath() !== ''`
    (`vendor/laravel/framework/src/Illuminate/Validation/Concerns/ValidatesAttributes.php:1356`) y el
    `getPath()` de un `UploadedFile` que llega en un `POST` —y el de los `UploadedFile::fake()` de los
    tests— es la **cadena vacía**, así que la condición nunca se cumple y **todo** se rechaza con el
    mensaje "must be a file of type: …", incluidos los PNG reales. Lo que sí mide el tipo es
    `getMimeType()` (finfo sobre el contenido), así que la política de `App\Clinica\Archivos\ArchivosClinicos`
    valida con un **cierre** que lo compara contra la lista blanca. Si una regla de archivo "no deja pasar
    nada", mirá primero `getPath()` antes de dudar del archivo.

31. **`UploadedFile::getMimeType()` (finfo) usa la EXTENSIÓN del nombre como pista cuando el contenido no
    alcanza, así que "tipo real" no es tan real.** Medido también en WEB-4.7: un `<?php echo "hola";`
    llamado `foto.png` se detecta **`image/png`** (y un `.png.exe` con un PNG adentro se detecta
    `application/x-ms-dos-executable`: la pista es la última extensión). O sea que el MIME detectado **no**
    distingue por sí solo un archivo con contenido arbitrario y nombre de imagen. Lo que protege de verdad
    —y por eso la política hace **las dos** comprobaciones— es: (a) la **extensión** del nombre que mandó
    el cliente contra la lista blanca, y (b) que el archivo se guarde **con la extensión del contenido y
    un nombre que arma el servidor**, en un disco privado fuera de `public/`, así que nunca queda un `.php`
    (ni un `.png` con PHP adentro *servido por el servidor web*, que igual no se ejecutaría). Un test
    (`ConsultaImagenesWebTest::test_el_mime_de_finfo_usa_la_extension_como_pista_y_eso_esta_cubierto`)
    fija el comportamiento de finfo para que no vuelva a sorprender.

32. **`imagen_consulta.imagen` es `NOT NULL` y en la base real guarda una RUTA ABSOLUTA del disco de la PC
    del consultorio — y una fila con `imagen` relativa NO se distingue de una del escritorio.** Medido al
    hacer WEB-4.7 en Chrome: la primera versión de la Action guardaba la ruta en una **columna nueva**
    `archivo` y dejaba `imagen` en `null`; el `INSERT` explotó con
    `NOT NULL constraint failed: imagen_consulta.imagen` (el esquema del legado la declara obligatoria,
    migración `2026_08_25_055351:1219`). Y al revés: escribir la ruta relativa en `imagen` sin más hace
    que la lectura no sepa si esa fila es de la web (el archivo está en el servidor) o del escritorio (la
    ruta apunta al disco de **esa** PC) — y en los datos reales la columna trae
    `C:\Users\IBYTE\Downloads\20211025_121548.jpg`, así que las dos formas conviven. La solución que quedó
    es **una sola columna de ruta (`imagen`) más una columna nullable nueva `disco` como marca de origen**:
    con `disco` la subió la web, sin `disco` es del escritorio. Cualquier consumidor de esa tabla (la
    descarga, el listado, y el proceso de copia de WEB-T.1 que va a leer las viejas) tiene que mirar
    `disco`, **no** el formato de la ruta.

33. **El `_token` de la sesión se rota al responder, así que un verificador de navegador no puede leerlo una
    sola vez y usarlo dos veces.** Medido al verificar WEB-4.8 en Chrome: el script leía el
    `input[name="_token"]` en la pantalla de la consulta, lo usaba para el `POST` del cierre (que funciona) y
    **volvía a usarlo** para el segundo `POST` —el del mostrador que prueba el rechazo de la regla—. Ese
    segundo POST respondía **419** y no aplicaba nada: el síntoma era una cita que quedaba sin marcar *a pesar
    de que la pantalla mostraba el aviso de éxito*. **Regla para los verificadores**: el token se relee
    **después de cada navegación**, de un formulario `@csrf` de la página en la que se está parado (el layout
    clínico no publica `meta[name=csrf-token]`, ver la trampa 16), y un `419` **no** se interpreta como "la
    acción falló". Si un verificador dice "la acción no se aplicó" pero la pantalla dice que sí, mirá primero
    el status del POST.

34. **Un ayudante de verificación que se apoya en lo que dejó la corrida *anterior* elige la fila
    equivocada** — y el síntoma parece un bug de la aplicación. Medido al verificar WEB-4.8 en Chrome: el
    ayudante calculaba el próximo `numhistoria` leyendo **sólo `historias`**, pero la corrida anterior había
    dejado una fila en el pivote `medico_pacientes` con ese mismo número (la cita "sin historia" de su caso).
    Resultado: la corrida nueva creaba **su cita con el mismo número que la cita vieja de la anterior**, y el
    cierre —que busca la cita por `numhistoria` + fecha, como corresponde— encontraba **la vieja** (la de
    `numorden` más alto) y la marcaba a ella. Dos cosas arreglan esto y conviene copiarlas: (a) el correlativo
    del ayudante se calcula con **las dos fuentes** (`historias` **y** `medico_pacientes`), que es la misma
    cuenta que `App\Actions\Pacientes\AsignarHistoria::siguienteNumero`; y (b) la fila de la corrida se crea
    con el **`numorden` más alto del día**, para que el desempate por orden (el de `ArmadorDeAgenda`) no la
    deje atrás. Es una variante de la trampa 27 del lado del verificador: **la base de desarrollo se deja como
    quede, así que las corridas se acumulan y el ayudante tiene que ser idempotente consigo mismo**. De paso,
    la app sí tenía algo que corregir que la corrida destapó: `CitaDeLaConsulta` ahora prefiere una cita
    **viva** sobre una **movida por el escritorio** del mismo día (la movida es la fila vieja y el paciente ya
    está en la fecha nueva).
    **Y hay una TERCERA fuente que la cuenta de `AsignarHistoria::siguienteNumero` no mira: `cola`.**
    Medido al hacer **WEB-4.9** (2026-10-11): el verificador de **WEB-4.8** crea a propósito una fila que
    **viola** la regla "atendido ⇒ tiene historia" —una cita `atendido = 1` con un `numhistoria` que **no**
    tiene fila en `historias`—, así que esa corrida deja un número **usado en `cola` y en ningún otro lado**.
    El ayudante que calculaba el correlativo con `historias` + `medico_pacientes` volvió a elegir ese número,
    la corrida nueva creó **una segunda cita del mismo `numhistoria` para hoy** y el verificador —que ubicaba
    la fila por el nombre del paciente— leyó **la vieja** (el síntoma fue "la cita sin confirmar ya está
    atendida"). Lo que lo arregla: (a) el correlativo del ayudante cuenta **también `cola`**; y (b) el
    verificador ubica la fila por el **enlace a la ficha** (`pacientes.id`, que es único), no por el texto del
    nombre. La Action `AsignarHistoria` **no** se toca por esto —en datos sanos esa tercera fuente no existe
    (0 filas en la base)—: es una regla del **verificador**, no del producto.

35. **El diálogo de "Atender" está en el HTML de todos los roles, y el panel de "sin historia" escribe
    "atender" en minúscula.** Medido al hacer **WEB-4.9** (2026-10-11), al escribir los tests y el
    verificador de que la secretaría no ve "Atender":
    - `resources/views/clinica/agenda/index.blade.php` incluye `_dialogo-atender` **siempre** —es **un**
      diálogo para toda la página, que los botones de las filas llenan con sus `data-*`—, así que con la
      secretaría el markup del diálogo (`#dialogo-atender`, `#atender-form`, el botón "Abrir la consulta")
      **igual está** y el diálogo queda inerte porque no hay ningún `form[data-atender]` que lo abra. "La
      secretaría no ve Atender" **no** se puede medir por la ausencia del diálogo ni del texto "Abrir la
      consulta": se mide por el `form[data-atender]` de la **fila** (o por su URL, que es lo más difícil de
      confundir).
    - Y `assertDontSee('Atender')` sólo es fiable por la **mayúscula**: el panel "Pacientes sin historia
      **a atender**" (WEB-2.11, `PacientesSinHistoriaVista::titulo()`) escribe la palabra en minúscula en
      la misma pantalla, y `assertDontSee` distingue mayúsculas. Un verificador que busque `atender` sin
      distinguirlas lo va a encontrar en el título del panel y va a creer que la acción sigue ahí.

36. **"Hoy" del negocio nunca es `now()` ni `Carbon::today()`: es `FechaDelConsultorio`.** Medido en la revisión
    general de la web (2026-10-11): `config('app.timezone')` es **UTC** (a propósito: ver `config/clinica.php`) y
    Venezuela es UTC-4, así que desde las 8 pm locales `Carbon::today()` ya es **mañana**. La agenda, "Nueva
    cita", "Enviar a todos", Motivos y Días no laborables abrían en el día siguiente, y "Hoy" y el marcado de hoy
    en semana y mes también. Lo que decide **qué día es** (día por defecto, "hoy", "pendiente desde hoy", mes en
    curso) usa `FechaDelConsultorio::hoy()` / `::ahora()`, y `::hoyComoFecha()` cuando se compara contra fechas
    armadas con `Carbon::createFromFormat('!Y-m-d', …)`. Las **marcas de tiempo** (`created_at`, `cuando` del
    sync) siguen con `now()`. La prueba: `Carbon::setTestNow('2026-10-11 00:30:00')` (UTC) = 20:30 del 10/10 en
    Caracas (`AgendaWebTest`, sección "Hoy es el del consultorio"); sin `tearDown` que la limpie se filtra a
    las demás pruebas.

37. **El encabezado de la web clínica tiene un `<form>` (cerrar sesión) antes del contenido.** Medido al agregar el
    menú "Mi cuenta" (2026-10-11): todos los verificadores elegían el consultorio con
    `locator('form button').first()`, que ahora es **"Cerrar sesión"**. Se usa `locator('main form button')`
    (el formulario de contexto está dentro de `<main>`). Dos detalles más al medir en Playwright: los ítems de
    un `<details>` **cerrado** tienen caja y `offsetParent` no nulo (hay que excluir `.menu-lista` al contar
    botones visibles), y la transición del enlace "Saltar al contenido" dura 100 ms (esperar antes de capturar).

38. **El `locale` de la app es `en` y toda regla de validación sin mensaje propio sale en inglés.** Medido en la
    revisión general de la web (2026-10-11): "The nuevo cedula field is required when paciente id is not
    present." en "Nueva cita". La API comparte esa configuración, así que **no** se cambia el locale por una
    pantalla: cada `validate()`/`FormRequest` de la web clínica lleva **un aviso por regla** (`messages()` o el
    segundo argumento de `validate`). Hay una prueba "todos los errores … están en español" por formulario
    (`AgendaWebTest`, `PacientesAltaEdicionWebTest`): al agregar una regla, falla hasta que tenga su texto.
    Quedan **sin esa prueba** el diálogo del representante, los motivos, sedes y consultorios.
    Y una más de entorno: `php artisan test a.php b.php` **solo corre el primero**; se corren por separado.

39. **El vademécum de la pestaña de récipes es `vademecum`, no `vademecum_m`.** Medido al hacer WEB-5.1
    (2026-10-11). Las dos tablas existen (`vademecum_m` con `codemedicina char(200)` y `presentacion char(35)`, y
    en la base local sólo la tienen `gineco-prueba-03` a `06`), y el nombre invita a pensar que `_m` es "el de la
    consulta". No lo es: `d_medicamentos_listado.srd` (la lista de la pestaña, `w_consulta_principal.srw:12694`,
    `:13070`, `:14206`) y `d_medicamentos_recipe_con.srd` (la grilla del récipe, que cruza por
    `recipes.codemedicina = vademecum.codemedicina`) leen **`vademecum`**, y el alta (`w_nuevo_vademecum`, abierta
    desde `:13239`) guarda con `d_a_vademecum_go.srd`, que actualiza **`vademecum`**. `w_nuevo_vademecum_m` y
    `w_nuevo_vademecum_mante` usan **los mismos** DataWindows (`d_b_vademecum`, `d_a_vademecum_go`; `update=
    "vademecum"`): `vademecum_m` es una copia que sólo aparece en `respaldos/` y en una pantalla alterna.
    El móvil también lee `vademecum`. La web escribe y busca ahí; `vademecum_m` no se toca.

40. **El `created` de `recipes` en `sync_changes` es el de la CABECERA (`recipe_detalle.id`), no el de la fila.**
    Es una convención heredada del móvil (`CreacionesClinicas::anotar('recipes', $detalle->id, …)`) y no es
    casual: el reemplazo de la tabla `recipe_detalle` que hace el escritorio (no tiene clave primaria) conserva
    las cabeceras que el app anotó con ese `created` (`config/sync_legado.php` → `sin_clave_con_altas_del_app`,
    `CambiosEscritorio::reemplazarTabla`). Tres consecuencias de WEB-5.1: (a) `GuardarRecipe` anota el `created`
    **de la cabecera** y **ninguno por fila** —el `record_id` de una fila podría coincidir con el de una cabecera
    ajena y blindarla del reemplazo, dejando cabeceras duplicadas—; (b) ese reemplazo sólo respetaba
    `source = 'mobile'`, así que una cabecera creada por la web **se perdía** en la siguiente subida de
    `recipe_detalle` del escritorio: ahora respeta `mobile` **y** `web`; (c) los `deleted` sí son **por fila**
    bajo `recipes` (con el id de la fila) porque es lo que el pull del móvil usa para soltarla (`eliminados`,
    que el teléfono aplica con `DELETE … WHERE id IN (…)` sobre su espejo de `recipes`).

41. **La clave del escritorio en `recipes` es `(nrohistoria, nroconsulta, codemedicina)`: un medicamento no puede
    estar dos veces en la misma consulta — y la web (como el móvil) sí lo permite entre récipes distintos.**
    Medido al hacer WEB-5.1 (esquemas `esquemas-asa/*.sql`: `PRIMARY KEY ("nrohistoria","nroconsulta",
    "codemedicina")`). Dentro de **un** récipe la web no repite medicamento (la Action lo descarta y el editor no
    lo agrega dos veces), pero el mismo medicamento en el récipe 1 y en el 2 de una consulta es válido en MySQL
    (`id` propio) y **chocaría con la clave del escritorio si alguna vez bajara al escritorio (`cambios/bajar` quedó descartada el 2026-10-11, así que hoy no aplica)**. Anotado en
    `PENDIENTES-POWERBUILDER.md`; no se restringe ahora porque el móvil ya lo permite y la decisión del usuario
    es "varios récipes por consulta". Y dos detalles de la misma fuente: el escritorio numera `orden` **desde 0**
    (`orden = dw_9.rowcount()`, `:13351`) y el móvil desde 1, así que las filas nuevas siguen **al mayor** `orden`
    existente (no renumeran las viejas); y `recipes.recipe` es `NULL` en 281.630 de 281.716 filas de la base
    local: **`NULL` es el récipe 1** (`RecipesDeLaConsulta::numeroDeGrupo`), la historia del paciente usa esa
    misma definición y editar uno de esos récipes **no escribe `recipe` en sus filas** (sólo las nuevas llevan 1).

42. **En un verificador con buscador en vivo, "hay resultados visibles" no prueba que sean los de lo que se
    escribió; y `opacity` en una fila de resultado deja ver lo que hay debajo.** Medido al verificar WEB-5.1 en
    Chrome: (a) la lista de la búsqueda anterior sigue visible mientras llega la respuesta nueva, así que un
    `waitForFunction(!resultados.hidden)` devolvía enseguida y el verificador leía los resultados **viejos**
    (parecía que "adevit" devolvía KEFORAL). Se espera la **respuesta del servidor a esa consulta**
    (`waitForResponse` por la URL `?q=…`) y después el pintado; (b) `.resultado-recipe:disabled { opacity: .6 }`
    volvía **translúcida** la fila de "ya está en el récipe" y el texto de la ayuda se veía a través de la
    lista flotante: el estado "ya está" se marca con el color del texto, no con `opacity` del contenedor. Y de
    paso, **un hallazgo que no es de esta tarea**: en las secciones de `consulta.js` (antecedentes,
    diagnósticos…) «Cancelar» con cambios pide confirmación **y** después el `beforeunload` del navegador pide
    otra vez (doble aviso); el editor de récipes lo evita (`saliendo = true` al confirmar) y las otras quedan
    sin cambiar.

43. **`recipes_pareja` no tiene clave primaria en el escritorio: lo sube ENTERO, y el reemplazo borraba lo que la web
    creó.** Medido al hacer WEB-5.1b (2026-10-11). El registro de cambios del PowerBuilder vigila las tablas **con**
    clave con tres triggers de fila (`_ins`, `_upd`, `_del`) y las **sin** clave con uno de sentencia que anota `T`
    ("reenviar la tabla entera"): `recipes_pareja` tiene **sólo** el trigger base
    (`Docs/MICONSULTAGI/bridge/quitar-registro-cambios.sql:215`, junto a `recipes`, que tiene los cuatro). Entonces
    `CambiosEscritorio::reemplazarTabla` hace `DELETE ... WHERE reg_medico = …` y vuelve a insertar lo que llegó: las
    filas de la web **se perdían** en la primera subida de esa tabla. La solución es la de la trampa 40 con una
    diferencia: `config/sync_legado.php → sin_clave_con_altas_del_app` suma `'recipes_pareja' => 'recipes_pareja'` y
    `GuardarRecipeDeLaPareja` anota **un `created` por fila** (la fila **es** la tabla: no hay cabecera, así que el
    `record_id` no puede chocar con el de otra cosa). **Lo que no se resuelve:** una fila que **creó el escritorio**
    no tiene `created` de la web, así que si la web la edita o la borra y el escritorio vuelve a subir la tabla, el
    reemplazo la deja como la mandó el escritorio (la edición se pierde y la borrada vuelve). Sólo ocurre en un
    consultorio que use los dos sistemas, y el escritorio **no recibe nada de la web** (la bajada se descartó el
    2026-10-11). Antes de asumir que una tabla "se reemplaza bien", mirá si sus triggers son los tres de fila o el de
    sentencia. El test `CambiosEscritorioTest::test_reemplazar_recipes_pareja_…` **falla** si se saca la línea de la
    configuración (se comprobó).

44. **El récipe de la pareja guarda y elimina en la MISMA URL (cambia el verbo), y el menú de eliminar no puede ir
    dentro del formulario del editor.** Medido al hacer WEB-5.1b: `POST /clinica/consulta/{id}/recipe-pareja` crea o
    corrige y `DELETE` la misma ruta elimina (no hay número de récipe: es uno por consulta). Dos consecuencias, la
    primera pariente de la trampa 18: (a) `route('clinica.consulta.recipe-pareja.guardar', …)` y `…eliminar` devuelven
    **el mismo texto**, así que `assertDontSee($urlEliminar)` sobre la pantalla vacía falló porque el `action` del
    formulario de guardar **es** esa URL: la ausencia del "eliminar" se mide por el formulario (`data-recipe-salir`) o
    por su texto, no por el `href`/`action`; (b) un `<form method="DELETE">` **no se anida** dentro del `<form>` del
    editor (HTML no admite formularios anidados), así que el menú de eliminar vive **fuera**, en la barra de las
    pestañas. Y esa separación trae su propio detalle de JS: `consulta-recipes.js` marca `saliendo = true` al guardar,
    pero el formulario de eliminar es otro; el atributo `data-recipe-salir` lo cubre **después** del `confirm` de
    `shell.js` (oyente en `document`, registrado antes) mirando `defaultPrevented`, para que confirmar la baja con
    cambios sin guardar no sume un segundo aviso del navegador (el verificador cuenta los diálogos **por tipo**).

45. **Playwright entrega el `confirm` de la página y el `beforeunload` del navegador por el mismo evento `dialog`:
    distinguilos por `dialog.type()`.** Medido al verificar WEB-5.1b en Chrome: un verificador que sólo cuenta
    "diálogos" no sabe si la pantalla pidió confirmación o si el navegador avisó de cambios sin guardar (`'confirm'`
    contra `'beforeunload'`; el `message` del segundo llega **vacío**: el navegador pone su propio texto). Las dos
    comprobaciones de la tarea —cambiar de pestaña con cambios avisa **una** vez (`beforeunload`) y eliminar con
    cambios pide **una** confirmación (`confirm`) y ningún `beforeunload`— se escriben con el tipo en la mano. El
    `beforeunload` sólo se dispara si la página tuvo interacción del usuario (Playwright la da al hacer `fill`).
    Y una cosa que cualquier verificador que cree la pareja debe recordar: **un récipe de la pareja sin
    medicamentos no existe** (los datos viven en las filas), así que la pantalla exige al menos un medicamento y la
    baja de todos es "Eliminar el récipe de la pareja".

46. **`route()` devuelve URLs ABSOLUTAS: lo que un botón pasa en `data-*` o en un campo oculto no es una ruta.** Medido al
    verificar WEB-5.2 en Chrome (los tests no lo vieron, porque pasaban rutas relativas a mano): el diálogo «Imprimir récipe»
    manda `siguiente` (a qué vista previa seguir) y los botones lo llevan de `route(...)`, o sea
    `http://127.0.0.1:8000/clinica/…`. La validación —que sólo acepta una ruta `/clinica/…/imprimir` para no ser una redirección
    abierta— lo **rechazaba**, el POST volvía con un error y **no se guardaba nada**, sin ningún síntoma en la pantalla de la
    consulta. `ImpresionDeRecipeController::rutaDeEsteSitio` reduce a ruta una URL **del mismo host** (sin usuario ni
    clave) **antes** de validar; cualquier otra cosa sigue rechazándose. Regla: un campo que viaja de la vista al servidor y
    decide una redirección se prueba con **la URL que arma `route()`** y con la de otro sitio, no sólo con un path a mano.
47. **`JsonResource` envuelve la respuesta en `data`, y `actingAs` se queda en el test.** Dos tropiezos de WEB-5.2: (a) el
    JSON de `RecipeFormatoResource` llega como `{"data": {…}}` en `actualizar` (no así dentro de `sync-app-data`, donde lo
    pone `formato_recipe` a mano), así que una aserción de las claves del Resource se hace sobre `json('data')`; (b) dentro de
    **un mismo test**, después de un `actingAs($user)` el siguiente `get()` «de invitado» **sigue autenticado**: la prueba del
    login va en su propio método (o antes de cualquier `actingAs`), no al final de otra.
48. **La hoja del récipe mide lo que el servidor cree que mide: `MedidasDeLaMitad` y `clinica-recipe.css` son gemelas.** El
    navegador no sabe pasar a una segunda hoja repitiendo el membrete y la firma, así que `DistribuidorDeRecipe` decide cuántos
    medicamentos entran **midiendo** (`MedidorDeTexto`: el ancho de cada letra de Arimo, que es la fuente del cuerpo) contra el alto
    que queda en la mitad. Cada constante de esa cuenta (márgenes, línea separadora, alto de la franja de firma, interlínea…) tiene su
    gemela en el CSS; si se cambia una sola, el cuerpo se desborda **sin avisar** —el CSS deja `overflow` visible a propósito: un
    desborde que se ve se corrige, uno que se recorta es texto clínico que se pierde—. Lo que lo ata es el verificador de Chrome
    (`verificar-recipe-impresion-web.js`): mide `scrollHeight - clientHeight` de **cada cuerpo** con `media: 'print'` y compara las
    páginas del PDF con las hojas del DOM, en cuatro membretes pesados. Tres detalles medidos al escribirlo: la hoja mide
    **209,5 mm y no 210** (con la altura exacta de la página un redondeo del navegador empuja una hoja en blanco); la **línea
    separadora es un `border-top` y no un fondo** (Chrome no imprime los fondos sin «gráficos de fondo»; `print-color-adjust: exact`
    cubre el resto); y en pantalla la hoja se achica con `zoom` (que sí cambia el layout, a diferencia de `transform`), con
    `zoom: 1 !important` al imprimir.
49. **La secretaría entra por el acceso de SISTEMA, no por «Médico» ni «Paciente».** Un verificador que intenta loguearla con
    `label[for=type_medico]` o `type_paciente` se queda en `/login` **sin ningún error**, y lo que se ve parece un bug de la pantalla
    (el botón «no está»): la cuenta es de `users` con rol `Secretaria`, y ese modelo se autentica con el interruptor
    `#systemAccessCheck` (`user_type = Root`, que en realidad significa «modelo `User`»). Se verifica en una **sesión nueva**
    (`browser.newContext()`), así que no hace falta cerrar sesión. Los 403 que el verificador provoca a propósito dejan un
    «Failed to load resource» en la consola: se tolera sólo ese número, no el resto.
50. **Para leer un PDF desde un verificador sólo hay `pdftotext`.** En esta máquina existe `pdftotext` (poppler, `/mingw64/bin`) y **no**
    `pdfinfo`/`pdftoppm`: las páginas de un PDF de Chrome se cuentan por los `\f` que `pdftotext` deja entre ellas, el tamaño sale del
    `/MediaBox` del archivo (A4 horizontal = 841,9 × 595,3 pt; Chrome lo escribe como 841,92 × 594,96) y la «rasterización» de la hoja
    se hace con una captura en `emulateMedia({ media: 'print' })`. El texto de `-layout` pone las dos mitades de la hoja en la misma
    línea: se compara con los espacios colapsados y por fragmentos de **una** mitad.
51. **`wire:model` en un `array` de casillas pierde lo tildado antes (panel de Especialidades).** En la ventana de
    «Especialidades» del médico (`Admin\Especialidades`, 2026-10-11) las casillas del catálogo estaban con
    `wire:model="seleccionadas"`: cada clic dispara **su propia** petición y, con dos clics seguidos, la segunda viaja antes de
    que vuelva la primera, así que se guardaba **sólo la última tildada**. Medido en Chrome: tildar Cardiología y Dermatología
    y «Guardar» dejaba **1 asignada** y la fila con una sola; con `wire:model.defer` (una sola petición, la del formulario)
    quedan las dos. El test de Livewire **no** lo ve —`->set('seleccionadas', [1, 2])` manda el array completo en una petición—:
    lo cazó el verificador de Chrome (`verificar-especialidades-admin-web.js`), que es la razón para no confiar sólo en el test.
    Regla: en un formulario con botón «Guardar», las casillas de un array van con **`.defer`**.
52. **El `favicon-16x16.png` que falta es del armazón del panel, no de la pantalla que se está mirando.** Chrome pide el favicon
    en la **primera** navegación después del login y deja un `404 /favicon-16x16.png` en la consola y en las respuestas (medido el
    2026-10-11: pasa igual entrando primero a `/admin/cuentas`). Un verificador que cuente «errores de consola» tiene que
    distinguirlo del error propio —el de esta pantalla se mide por las **respuestas** ≥ 400 y por el resto de la consola— o va a
    dar rojo por algo que no es suyo. No se arregló (falta el archivo, no es de esta tarea).
53. **Las cuatro tablas de las solicitudes tienen clave primaria en el PowerBuilder: se suben por fila y no hay nada que "proteger" con
    `sin_clave_con_altas_del_app`.** Medido al hacer WEB-5.3a (2026-10-11). `constancia_obs`, `reposo_paciente`, `referencia` e `informe` llevan los
    tres triggers de fila (`op = I/U/D`, ver `registro-cambios.js` y `quitar-registro-cambios.sql`) y no el de sentencia (`op = T`) de
    `recipes_pareja`, así que `CambiosEscritorio` las trata con `guardarGenerico`/`borrarGenerico` **por clave** y jamás con `reemplazarTabla`: una
    fila que creó la web no se borra por una subida de **otra** fila (lo fija `CambiosEscritorioTest::test_la_subida_fila_por_fila_de_las_solicitudes_…`).
    Lo que **sí** puede pasar —y no se resuelve— es que el escritorio vuelva a subir **la misma clave** (una consulta que existe en los dos lados): el
    `UPDATE` de `guardarGenerico` no consulta `sync_changes`, así que pisa lo que la web editó antes; y una fila **del escritorio** que la web borra
    vuelve si el escritorio la re-sube. Sólo ocurre en un consultorio que use los dos sistemas, y el escritorio no recibe nada de la web. Las Actions
    dejan igual su `created`/`updated`/`deleted` con `source = web`: es lo que el móvil lee (`updated_at` para el delta y `deleted` para `eliminados`).
    Antes de agregar una tabla a `sin_clave_con_altas_del_app`, comprobá que sea de las de sentencia.
54. **`FechaClinica::paraInput` descarta las fechas futuras: sirve para un nacimiento, no para el inicio de un reposo.** Medido al escribir
    `ReposoDeLaConsulta` (2026-10-11): `paraInput` devuelve `''` para cualquier fecha posterior a hoy (es la vara de `fnacimiento`), así que un reposo que
    **empieza mañana** habría caído en el día de la consulta sin avisar, en la pantalla y en la hoja. Las fechas de un documento se leen con
    `ReposoDeLaConsulta::fechaDeInicio` (acepta el futuro, cae en un respaldo sólo con `0000-00-*`, vacío o basura). Misma familia que la trampa 12: una columna
    `date` casteada se escribe con la hora.
55. **`pdftotext` no sirve para comprobar el ORDEN del texto justificado ni de las dos mitades de una hoja.** Medido al verificar WEB-5.3a en Chrome: con
    `-layout` las dos mitades salen mezcladas en la misma línea (trampa 50) y **sin** `-layout` el texto justificado (`text-align: justify`) se lee por
    **columnas de palabras** (`informe1 … informe165` salía `…, 168, 172, 174, 178…` aunque el DOM está en orden). El verificador comprueba entonces que **estén
    todas las palabras, cada una una vez** (`completa()` ordena antes de comparar) y el **orden** lo fijan los tests de PHP sobre el HTML
    (`ImpresionDeSolicitudesWebTest::palabrasDelCuerpo`). Y una trampa del propio verificador: `ayudante largo N` reescribe el texto con otro prefijo (`largoN`),
    así que el prefijo que se busca en el PDF tiene que ser el del último texto escrito.

56. **`vademecum` tiene clave en el escritorio y se sube por fila; `recipe_grupo` y `recipe_grupo_detalle` no, y se suben ENTERAS.** Medido al hacer
    WEB-8.1a (2026-10-11), mirando los triggers de `quitar-registro-cambios.sql`: `vademecum` lleva los tres de fila (`_ins`, `_upd`, `_del`, clave
    `codemedicina`), así que `CambiosEscritorio` la trata con `guardarGenerico`/`borrarGenerico` y una fila creada por la web no corre riesgo (sólo
    chocaría un código que el escritorio y la web den a la vez). `recipe_grupo` y `recipe_grupo_detalle` tienen **sólo** el trigger de sentencia
    (`op = T`): `reemplazarTabla` borraba todo lo del médico, así que cada fila de la web anota su `created` bajo el nombre de **su** tabla y
    `config/sync_legado.php → sin_clave_con_altas_del_app` suma las dos líneas (los tests `CambiosEscritorioTest::test_reemplazar_los_tratamientos_…`
    y `…_sin_las_lineas_de_config_…` fallan/contrastan si se sacan). Sin resolver, igual que la trampa 43: una fila que creó el escritorio y la web
    editó o borró vuelve a su versión si el escritorio re-sube la tabla, y el reemplazo reinserta con ids nuevos (el teléfono puede ver duplicados:
    anterior a esta tarea).
57. **El `deleted` de `sync_changes` tiene que llamarse como la tabla LOCAL del teléfono.** El pull del móvil (`eliminados`) manda `table_name` y
    `record_id` y la app borra de la tabla local de ese nombre; ignora las que no conoce. Sus tablas de tratamientos se llaman `tratamientos` y
    `tratamientos_detalle`, no `recipe_grupo(_detalle)`: un `deleted` bajo el nombre del servidor no llega a ninguna fila. `RastroDeTratamientos` deja
    los dos (el del servidor y el del teléfono). `vademecum` y `recipes` se llaman igual y no necesitan alias. Medido leyendo `LocalDatabase.deleteByRemoteIds`.
58. **La barra superior de `/clinica` ya está llena a 768 px: un acceso más la desborda.** Con las fichas del contexto, Sedes, Consultorios, Cambiar
    consultorio y la cuenta, agregar «Catálogos» dejó 55 px de scroll horizontal a 768 en TODAS las pantallas del médico (lo midió el verificador de
    WEB-8.1a; `verificar-menu-por-especialidad-web.js` también lo habría cazado). Las entradas nuevas van al menú lateral (`ArmazonVista::menu()`).
    Y un medidor que no eligió consultorio ve la pantalla de contexto, sin fichas ni accesos: no prueba nada.
59. **Tres tests dependen de la hora real y fallan desde las 20:00 de Caracas, y `php artisan test` completo se queda sin memoria con el límite de 128 MB.**
    `AgendaVistasTest::…url_mal_formada`, `FormularioDePacienteVistaTest::…si_de_atender_hoy…` y `PacientesAltaEdicionWebTest::…si_de_atender_hoy…` comparan contra
    «hoy» con el reloj real: pasadas las 20:00 locales el día UTC ya es el siguiente (fallan también sin los cambios de WEB-8.1a, medido con `git stash`).
    Y la suite entera aborta con «Allowed memory size of 134217728 bytes exhausted» si algún test falla con una página enorme (Ignition renderiza el error):
    `php -d memory_limit=2G artisan test` o por carpeta (`tests/Unit`, `tests/Feature`).
60. **Las cinco tablas de WEB-8.1b se suben por fila: no hay nada que proteger, y el riesgo real es otro.** Medido al hacer WEB-8.1b (2026-10-11) mirando
    `quitar-registro-cambios.sql`: `diagnosticos`, `motivos_consulta`, `examenes`, `tipos_examenes` y `doctores` (y `especial`) llevan los tres triggers de fila
    (`_ins`, `_upd`, `_del`) y no el de sentencia, así que `CambiosEscritorio` las trata con `guardarGenerico`/`borrarGenerico` **por clave** y jamás con
    `reemplazarTabla` (el test `CambiosEscritorioTest::test_la_subida_fila_por_fila_de_los_catalogos_clinicos_…` falla si alguna entra a
    `sin_clave_con_altas_del_app` o si una subida de otra clave borra una fila de la web). Lo que **sí** puede pasar, sin resolver (igual que 53/56): el
    escritorio sube la misma clave (`UPDATE` sin mirar `sync_changes`) y pisa lo que la web editó, o re-sube una fila que la web quitó. **La cédula de
    `doctores` no se edita desde la web** por esa razón: la copia del PowerBuilder seguiría con la clave vieja y su próxima subida crearía un duplicado, y
    `referencia.ceduladoctor` enlaza por ella. El móvil baja `diagnosticos`, `motivos_consulta`, `doctores` y `especial` por delta y sus bajas viajan por
    `eliminados` con el mismo nombre de tabla (no necesitan alias, a diferencia de los tratamientos, trampa 57); **no** guarda `examenes` ni `tipos_examenes`.
61. **Las altas rápidas de motivos y diagnósticos comparan en mayúsculas pero CON tildes.** `AgregarMotivoAlCatalogo`/`AgregarDiagnosticoAlCatalogo` usan
    `mb_strtoupper(trim())`, así que `PELVICO` y `PÉLVICO` no se ven como el mismo nombre y el alta rápida crearía el duplicado. El ABM (`CrearMotivoEnElCatalogo`,
    `CrearDiagnosticoEnElCatalogo`) busca **antes** con `BusquedaDePacientes::normalizar` y devuelve el existente; el alta rápida de la consulta **no se tocó** (su
    comportamiento no cambia). Lo mismo en la edición (`EditarDescripcionDelCatalogo`): un duplicado histórico se puede guardar sin cambiarle el nombre.
62. **Editar archivos desde la herramienta de shell en esta máquina.** Medido en WEB-8.1b: (a) `python` en modo texto escribe **CRLF** en Windows (un archivo LF
    del repo pasa a CRLF; se abre con `newline=''` o en binario); (b) las barras invertidas dobles de un comando pueden llegar colapsadas y dejar el
    `namespace`/`use` roto o un CRLF convertido en un salto real dentro de un literal PHP (se detecta con `php -l`; se evita con `chr(92)`, con la herramienta
    Edit o escribiendo el texto con Write y leyéndolo desde Python); (c) un heredoc largo con comillas simples puede abortar sin crear el archivo y sin avisar. Y de los
    verificadores: el ayudante que siembra de forma idempotente **acumula uso** corrida tras corrida («está en 2 consultas»), así que se compara el texto del aviso y no el número.
63. **Un formulario (buscador, segmentado, select) dentro del slot `acciones` de `<x-ui.page-head>` desalinea el encabezado.** Medido el 2026-10-12 en Pacientes y en los siete catálogos: el slot llevaba el botón primario **más** un buscador y un segmentado en `div.w-100`; ese bloque (124 px de alto en Pacientes, 166 px en Medicamentos) no cabe al lado del título, así que `.page-head` (flex con `flex-wrap`) lo bajaba a una segunda línea (el botón quedaba 62 px por debajo del borde superior, a mitad de pantalla) y, con `align-items: center`, cualquier bloque alto centraba al título contra él. La regla: el encabezado lleva **título, subtítulo y botones**; lo que filtra la lista va en `<x-ui.barra-herramientas>` debajo (`.barra-herramientas`, hermana de `.agenda-barra`) y `.page-head` alinea arriba (`align-items: flex-start`). Los demás casos del slot (el estado del envío automático, el contador del récipe, el botón dentro del `<form>` de «Terminar la consulta») son una sola fila de 36 px y quedan bien. `.agent-tools/verificar-encabezados-y-submenus-web.js` mide el tope del título y de cada acción contra el del encabezado en 20 pantallas a 3 anchos y falla si pasan de 2 px o si el slot trae `form`, `select`, `input` o `btn-group`.
64. **El grupo «Catálogos» del menú lateral es un `<details>`, no un enlace.** Su `<summary>` no navega (antes «Catálogos» llevaba al primer catálogo): un verificador que hacía `a[title="Catálogos"]` debe usar `#clinica-sidebar details.nav-grupo > summary` y el clic en el hijo (`.nav-submenu a`); `/clinica/catalogos` sigue redirigiendo al primero. Con la barra plegada a 72 px los hijos son iconos con `title` (`.link-text` oculto) y el ancho se anima: se mide **después** de `--ds-dur-panel` (inmediatamente da 96 px, no 72). El icono del padre es `bi-book` para no repetir el `bi-capsule` de Medicamentos.
65. **El last-write-wins del sync no deja pasar dos guardados de la misma columna en el mismo segundo (ni con el reloj congelado de un test).** Medido al hacer WEB-8.2 (2026-10-12): `RegistroDeCambios::permite` exige que el último rastro sea **estrictamente anterior** a la edición nueva, y `occurred_at` tiene resolución de un segundo. `GuardarMembreteDelMedico` deja un `updated` por columna de `evolucion` con `source = web`, así que en un test con `Carbon::setTestNow` fijo el **segundo** guardado del logo (o de cualquier texto) se **bloquea** en silencio y el archivo viejo parece "no reemplazado" —el test pasaba por la razón equivocada—: hay que **avanzar el reloj** entre guardados (`avanzar()` en `FormatoDeImpresionWebTest`). En Chrome pasa igual si dos guardados caen en el mismo segundo: el verificador espera 1,2 s antes del segundo guardado del logo. `recipe_formatos` no pasa por `sync_changes` y no tiene este guard.
66. **El formato del médico se guarda bajo `regMedicoPrincipal()`, que no es el `reg_medico` del consultorio, y el sync no lo lee igual.** `Medico::regMedicoPrincipal()` es la **primera** fila de `medico_registros` (relación `hasOne`) y la usan el móvil, la hoja de WEB-5.2 y la pantalla de WEB-8.2; el contexto de la web, en cambio, sólo ofrece los registros cuya ficha de `medicos` tiene ese `reg_medico` (`ContextoTrabajo::disponibles`), que puede ser otro. Por eso la pantalla avisa cuando el consultorio elegido no es el del registro principal. Dos divergencias **anteriores y sin resolver**: `SyncAppDataController` manda `configuracion` con `Evolucion::whereIn('reg_medico', <todos>)->first()` (sin orden ni principal) y `CumpleanosController` guarda su configuración bajo el `reg_medico` del **contexto**: un médico con filas de `evolucion` bajo dos registros puede ver en el teléfono una fila distinta de la que imprime la web. Con un solo registro (lo común) no pasa. Para probarlo en un test: borrar sus `medico_registros`, crear primero el principal y después el de `medicos.reg_medico`.
67. **Logo, firma y sello son públicos por URL, y el API los acepta como SVG.** Viven en el disco `public` (`logos/`, `recipe/firmas/`, `recipe/sellos/`) con nombre aleatorio —no secreto— porque el móvil los baja por URL. Y la regla `image|max:2048` del API de Laravel 8 **acepta SVG** (y BMP/GIF): un SVG con `<script>` abierto desde su URL se ejecutaría en el origen de la aplicación. La web de WEB-8.2 es más estricta (`ImagenesDelMembrete`: JPG, PNG o WEBP por MIME, extensión y `getimagesize`) pero **el API no se tocó** por la orden de no cambiar su comportamiento: endurecerlo es una línea (agregar la misma regla en `ReglasDelFormato::reglas()`/`ConfiguracionMedicoController`) y queda **pendiente de decisión**, igual que moverlos a un disco privado con ruta autorizada (cambia el contrato con el móvil).
68. **Dos tropiezos de pruebas de WEB-8.2.** (a) Un test "unitario" de un ViewModel que lee `old()` o `FechaDelConsultorio` necesita `Tests\TestCase` (arranca la app): con `PHPUnit\Framework\TestCase` **pasa solo y falla dentro de la suite completa** (depende de qué dejó cargado el test anterior). (b) Los grupos de botones de `.btn-check` (alineación, color de la línea) tienen el `<input>` oculto: `check(..., { force: true })` de Playwright hace clic donde no hay nada y falla con "did not change its state"; se hace clic en su **`label[for=…]`**. Además, `UploadedFile::fake()->image('x.png')->size(3000)` sirve para probar el tope de tamaño sin generar un archivo grande, y `Evolucion::flushEventListeners()` en `tearDown` limpia un `saving` de prueba.
69. **`motivo_cita` no tiene clave primaria en el escritorio: lo sube ENTERO y el reemplazo borraba lo de la web y el precio, la sede y la marca de lo del escritorio.** Medido al hacer WEB-8.1c (2026-10-12). El legado sólo declara un índice único llamado `primarykey` (`CREATE UNIQUE INDEX "primarykey" ON "DBA"."motivo_cita" ("codigo")`, `esquemas-asa/*.sql`) y ninguna PRIMARY KEY, así que el registro de cambios del puente le pone **sólo el trigger de sentencia** (`op = T`, mismo caso que `recipes_pareja`, trampa 43): `quitar-registro-cambios.sql` lista únicamente `tr_ddrsync_motivo_cita`, sin los `_ins/_upd/_del` de las tablas con clave. `CambiosEscritorio::reemplazarTabla` hace `DELETE ... WHERE reg_medico = …` y reinserta lo que llegó, que son **sólo `codigo` y `tipo_atencion`**. Dos pérdidas, las dos reproducidas con un test: (a) **los motivos que crea la web** desaparecían en la primera subida; (b) los de **origen escritorio perdían `precio`, `office_id` y `admite_varias_citas_pendientes`** (la nota de WEB-2.17 en `PENDIENTES-POWERBUILDER.md` decía que la marca «nunca se pisa», cierto sólo para tablas con clave: acá la borraba el reemplazo). Se resolvió con la configuración, no con código nuevo por tabla: `motivo_cita` entra a `sin_clave_con_altas_del_app` (`CrearMotivoDeCita` anota un `created` por fila con `source = web`, que el reemplazo respeta) y la clave nueva `columnas_del_app_en_el_reemplazo` (`clave = codigo`, `columnas = office_id, precio, admite_varias_citas_pendientes`) restaura esas tres columnas en cada fila que el escritorio vuelve a mandar desde la fila anterior con el mismo `codigo` (la que no tiene sede, si hay varias: las variantes por sede que no creó la web se pierden en esa subida). Los tres tests de `CambiosEscritorioTest` (`…motivo_cita…`) fallan si se sacan las líneas. **Sin resolver**, igual que 43/53/56/60 y sólo en un consultorio que use los dos sistemas: el **nombre** que manda el escritorio pisa un renombre de la web, y un motivo que la web quitó vuelve si el escritorio lo re-sube. El desplegable de citas del escritorio sólo lee los **dos primeros caracteres** del código (`left(ddlb.text,2)`): por eso el correlativo de la web arranca en dos dígitos y pasar de 99 motivos de cita por médico no es un caso real. Antes de decir que una tabla «se sube por clave», mirá sus triggers.
70. **El `<select>` de «Nueva cita» y el precio del monto vacío salen del MISMO catálogo (`MotivosDeCita::de`), que desde WEB-8.1c es sólo «la sede elegida + las sin sede».** Antes devolvía todos los motivos del médico con uno por código (incluidos los de otras sedes); ahora los de otra sede **no se ofrecen y tampoco se resuelven**: un código de otra sede posteado a mano (o el de una cita vieja que se edita en otra sede) **no encuentra su precio** y el monto vacío queda sin monto, y no aplica su marca de varias citas (`AgendamientoDeCitas` usa la misma lista). El formulario conserva en el `<select>` el motivo que la cita ya traía, con «no está en el catálogo de esta sede». Dos detalles de la lista que se ve: si un motivo de la sede y otro «de todas» se llaman igual (sin tildes ni mayúsculas) se muestra **uno**, el de la sede (`FormularioDeCitaVista::opcionesDeMotivo`), pero la lista que resuelve conserva los dos; y el filtro de la agenda (`de($regMedico)` sin sede) sigue viendo el catálogo del médico con un motivo por código. El código del motivo nuevo **no se pide**: lo asigna `ReglasDelMotivoDeCita::siguienteCodigo` (mayor **numérico** + 1, dos dígitos, dentro de la transacción con las filas del médico bloqueadas); los códigos no numéricos (`PRIM`, `ECO4D`) no cuentan. Y la edición **no renombra** una razón que sólo difiere en mayúsculas: sin eso, editar el precio de «Ecografía 4D» la dejaba en «ECOGRAFÍA 4D» (el alta sí guarda en mayúsculas).
71. **Tres tropiezos de la verificación de WEB-8.1c.** (a) Una clave de orden con separador (`código . '|' . sede . '|' . id`) pone `ECO4D` **antes** de `ECO` (`'4'` es menor que `'|'`): el código se completa a ancho fijo con espacios antes de unir (`MotivosDeCitaDelCatalogo`), y el verificador viejo de WEB-2.17, que tomaba «la primera fila que contiene `Código ECO`», marcó `ECO4D` sin que nadie lo notara hasta que la cita no encontró el motivo en la sede. (b) Un `a[href$="/clinica/catalogos/motivos-cita"]` con `.first()` toma el enlace del **menú lateral** (dentro de un `<details>` cerrado: Playwright espera a que sea visible y se cuelga 30 s) y no el del «Más» de la agenda; y desde WEB-8.2 el menú tiene **dos** grupos plegables (`Catálogos` y `Configuración`): `details.nav-grupo` solo da *strict mode violation* —se escoge con `:has(> summary[title="Catálogos"])`—, y `.nav-submenu a .link-text` suma el hijo de Configuración con texto vacío si está plegado (los verificadores de 8.1b y de encabezados ya estaban rotos por eso y se arreglaron acá). (c) Un verificador que rellena el diálogo del duplicado y cambia **sólo la sede** reutiliza el texto sin tildes del intento anterior: guarda `NANDU` y no `ÑANDÚ` (la razón se guarda como se escribe, en mayúsculas), así que la comprobación «dos motivos con el mismo nombre en sedes distintas» tiene que volver a escribir el nombre completo.
72. **`hospitalizacion` se sube por fila y `dieta_paciente` ENTERA: dos tablas del mismo tipo de documento, dos caminos.** Medido al hacer WEB-5.3b (2026-10-12) mirando `quitar-registro-cambios.sql`: `hospitalizacion` lleva los tres triggers de fila (`_ins`, `_upd`, `_del`) —y `PRIMARY KEY (nrohistoria, nroconsulta)` en `esquemas-asa`—, así que `CambiosEscritorio` la trata con `guardarGenerico`/`borrarGenerico` y una orden que creó la web no corre riesgo (el test `CambiosEscritorioTest::test_la_subida_fila_por_fila_de_la_hospitalizacion_…` falla si entra a `sin_clave_con_altas_del_app`). `dieta_paciente`, en cambio, tiene **sólo** `tr_ddrsync_dieta_paciente` (sentencia, `op = T`) y en el esquema real no hay clave primaria, sólo `CREATE UNIQUE INDEX "primarykey" … ("histroria","consulta")` (como `motivo_cita`, trampa 69): el reemplazo borraba las dietas de la web. Se arregló con la línea `'dieta_paciente' => 'dieta_paciente'` en la config y un `created` por fila en `GuardarDietaDeLaConsulta` (dos tests, uno de contraste sin la línea). **Sin resolver**, sólo en un consultorio que use los dos sistemas: si el escritorio sube la **misma clave** (`UPDATE` de `hospitalizacion`, o el reemplazo de `dieta_paciente`), lo editado en la web se pisa (como la 53); y si tras un reemplazo quedan dos filas de la misma consulta, la web lee la de menor `id`. Antes de decir «se sube por clave», mirá los triggers.
73. **`dieta_paciente` y `hospitalizacion` tienen columnas que parecen editables y no lo son, y la columna de la historia se llama `histroria`.** `dieta_paciente.histroria` (errata del esquema) y `consulta` —el **número**, no el id— son la clave; `where('nrohistoria', …)` no encuentra nada. `cod_dieta` es `char(2)` y las plantillas `formato_print` traen códigos de 4-5 caracteres: el `INSERT` del escritorio los guarda tal cual en ASA, pero en MySQL estricto daría «Data too long», así que la web no copia el código de la plantilla (queda `NULL`). `calorias` no la escribe nadie y `ejecutada` sólo nace como `'S'`. En `hospitalizacion`, `indiprev` y `habitacion` (con valores `Semiprivada/Privada/Semisuite/Suite` sólo en la hoja) no las edita ninguna pantalla ni las imprime la hoja: están vacías en las 119.299 filas. Por eso `QueEsUnDocumento::hospitalizacion` **no** cuenta `indiprev`/`habitacion` y las Actions las dejan como estén. El escritorio tampoco deja guardar una dieta libre (sin fila no guarda: la fila nace al «pasar» una plantilla); la web sí.
74. **Seis pestañas no caben en un `btn-group` ni a 1280 ni a 375, y el verificador de WEB-5.3a no lo vio porque eran cuatro.** Medido en Chrome (WEB-5.3b): con «Dieta / Recomendaciones» el segmentado de las solicitudes empujaba la página 10 px a 1280 y 67 px a 375 en **todas** las pestañas (la columna de la consulta es angosta junto al panel de 13 ítems; el `.btn` no puede achicarse por debajo de su palabra más larga). Se resolvió con la clase `pestanas-en-fila` (botones sueltos con `flex-wrap`, selector `.btn-group.pestanas-en-fila > .btn.btn` para ganarle a `.btn-group > .btn:not(:last-child)` de Bootstrap) en vez de tocar `.recipe-pestanas`, que sigue siendo el grupo unido de los récipes. Y dos detalles de pruebas: el HTML pega el rótulo «aparte» al texto (`</strong>texto`, el CSS lo pone en su línea), así que un helper que cuente palabras con `strip_tags` junta `PROVISIONAL:palabra1` en una sola; y un `python`/heredoc con acentos graves dentro de comillas dobles de bash ejecuta el texto entre ellos como comando (un comentario CSS con `` `pestanas-en-fila` `` perdió el nombre): escribir el script con Write.
75. **`examen_paciente`, `examen_obs`, `radiologia_paciente` y `radiologia_obs` se suben por fila y NO necesitan protección — pero la misma clave sí se pisa.** Medido al hacer WEB-5.4 (2026-10-12) mirando
    `quitar-registro-cambios.sql` y los esquemas de `esquemas-asa/`: las cuatro tienen clave primaria (`(nrohistoria, nroconsulta, codeexamen)`, `(numhistoria, numconsulta)`, `(nrohistoria, nroconsulta, coderadio)` y
    `(numhistoria, numconsulta)`) y los tres triggers de fila, así que `CambiosEscritorio` las trata con `guardarGenerico`/`borrarGenerico` **por clave** y jamás con `reemplazarTabla`: lo que el escritorio sube y borra de **otra** consulta
    no toca lo que creó la web (el test `CambiosEscritorioTest::test_la_subida_fila_por_fila_de_laboratorio_y_estudios_…` falla si alguna entra a `sin_clave_con_altas_del_app`). Lo que **no** se resuelve, sólo en un consultorio que use
    los dos sistemas y igual que 53/60/72: si el escritorio sube **la misma clave** (por ejemplo el `examen_obs` de una consulta que existe en los dos lados), su `UPDATE` no consulta `sync_changes` y pisa lo editado en la web, y un examen que la
    web quitó vuelve si el escritorio lo re-sube. `traducir()` convierte el `nroconsulta` del escritorio en el **número** de la consulta del API (no su id), así que web y escritorio comparten el espacio de claves. El móvil no baja ninguna de las
    cuatro (`SyncReportesTest`). Antes de decir «se sube por clave», mirá los triggers.
76. **«Rutina» y «estudio» no son lo que sugieren sus nombres.** Medido al leer `w_consulta_principal.srw:16269-17300`: (a) la pestaña **Rutinas de exámenes** no tiene paquetes propios: lista los **tipos de examen** (`d_tipos_examenes`, sin el `002`) y su botón
    *Pasar* inserta en `examen_paciente` **todos los exámenes de ese tipo** (`d_listado_tipos_examenes`): una rutina **es** un tipo, y escribe las mismas filas que la pestaña de exámenes sueltos (el escritorio ni comprueba duplicados: la clave primaria
    haría fallar el `INSERT` sin avisar); (b) la **Solicitud de estudios** no usa la tabla `radiologias` —aunque `radiologia_paciente`, `radiologia_obs` y `radiologias` parezcan una familia— sino el catálogo **`examenes` con `codetipo = '002'`**: el
    `DataWindow` de la lista cruza `radiologia_paciente.coderadio = examenes.codeexamen` y exige el tipo `002` (4.260 de 4.264 filas lo cumplen; ninguna cruza con `radiologias`, que tiene 84 filas con `opciones` y `tipo` vacíos). Por eso el
    catálogo de estudios **es** el de exámenes y no hace falta un ABM aparte; un examen de laboratorio **no** se puede pedir como estudio ni al revés (`CatalogoParaPedirExamenes`), y quitar un examen del catálogo con un estudio pedido **tiene que**
    contar como «en uso» (`UsoDeUnItemDelCatalogo::examen` sumó `radiologia_paciente`). (c) `radiologia_paciente.orden` lo llena el escritorio con la **cantidad de filas del catálogo**, el mismo número para todos (sólo 740 de 4.264 filas lo tienen), y
    `nroopcion` siempre vale 1 (escrito a mano en los dos `INSERT`): no son un orden ni una variante.
77. **Tres tropiezos de la verificación de WEB-5.4.** (a) `pdftotext -layout` mezcla las dos mitades de una hoja en la misma línea (trampa 50): con una orden **que continúa en la otra mitad** un nombre de varias palabras («WEB54 EXAMEN 037 DEL PERFIL») sale cortado por el
    texto de la otra mitad y un contador por nombre da de menos (84 de 100). El texto sin `-layout` (`crudo`) sí conserva cada nombre entero cuando el párrafo es una lista corta por elemento. (b) Un ayudante que **siembra de forma idempotente y acumula**
    rompe la corrida siguiente: la primera versión agregaba los exámenes «extra» de la orden larga **al mismo tipo** (`W54C`) que la rutina de 100, así que en la segunda corrida la rutina traía 249 y el verificador fallaba; los extras van en su propio tipo
    (`W54D`) y el ayudante migra los que ya estaban (misma familia que la trampa 34). (c) `assertSessionHasErrors($claves, $formato, $bolsa)`: el **tercer** argumento es la bolsa de errores, no un mensaje (pasarle un texto de caso hace fallar con «false is true»
    sin decir por qué). Y de nuevo la trampa 62: un `python` en heredoc con `'''` o comillas simples dentro aborta el comando entero sin crear nada; el script va con la herramienta Write y se corre aparte.
78. **Una ruta pública NO puede estar en el grupo `web`: abre sesión y manda cookies sólo con entrar.** Medido al hacer WEB-5.6 (2026-10-12): todo `routes/web*.php` pasa por `web` (`StartSession`, `EncryptCookies`, CSRF y `UserOnlineMiddleware`), así que la página que abre una farmacia al escanear el QR habría recibido una cookie de sesión y habría tocado la base por visitante. `/verificar` vive en `routes/verificar.php`, cargado por `RouteServiceProvider` con un grupo propio (`verificacion` en `Kernel`: sólo `VerificacionPublica`, que pone `noindex`, `no-referrer`, `no-store` y una CSP cerrada). Consecuencias: la vista no puede usar `@csrf`, `old()`, `session()` ni `$errors`; los formularios son `GET`; y como `StartSession` no corre, **tampoco existe el login**: nada de `auth()` ahí. Y el `throttle` sin nombre **comparte contador entre rutas** (la clave es dominio + IP): `throttle:10,1,verificar-codigo` y `throttle:60,1,verificar-token` llevan prefijo para tener cada uno el suyo (un test lo fija). `php artisan route:list` sigue roto por las credenciales de WhatsApp (trampa de entorno): las rutas se comprueban con tests.
79. **El QR sale de la configuración, no de la petición, y el SVG de `bacon/bacon-qr-code` trae medidas y fondo propios.** (a) La dirección del QR es `config('clinica.verificacion.url')` (`VERIFICACION_URL`) o `APP_URL`: la hoja se imprime desde cualquier host y el QR se escanea desde un teléfono, así que `route()` (que usa el host de la petición) daría un QR a `localhost` en desarrollo o a otro dominio detrás de un proxy. En una prueba de navegador hay que arrancar `php artisan serve` con `VERIFICACION_URL=<base>` para que el QR apunte al servidor de la prueba. (b) `GeneradorDeQr` quita la declaración `<?xml?>` y los `width`/`height` de la etiqueta `<svg>` (la hoja lo dimensiona por CSS a 16 × 16 mm; el `<rect>` interior conserva sus medidas) y le pone `shape-rendering="crispEdges"`. (c) Una URL de 77 bytes con corrección **M** es la **versión 5** (37 × 37 módulos): con 2 de silencio por lado son 41 módulos en 16 mm = 0,39 mm por módulo; se lee desde el PDF a 300, 200 y 150 dpi. Si la dirección base fuera mucho más larga pasa a la versión 6 (0,36 mm) y hay que volver a medir. (d) El QR va **por encima del sello** (`z-index` y fondo blanco): con el sello a la izquierda o al centro el sello le tapa un pedazo pero el QR nunca queda tapado. Verificar la decodificación exige rasterizar el PDF: en esta máquina no hay `pdftoppm`, así que se usa PyMuPDF + OpenCV (`.agent-tools/decodificar-qr-pdf.py`, en un venv fuera del repo).
80. **La verificación pública identifica al récipe, no a su contenido: un récipe nuevo con el mismo número hereda el token, y `recipe_verificaciones` no se limpia sola.** Decisión de WEB-5.6 (el token sobrevive a las ediciones): la fila se llavea por `reg_medico` + `nrohistoria` + `nroconsulta` + `tipo` + `recipe`. Si el médico **elimina** el récipe 2 y crea otro, el nuevo vuelve a ser el 2 (`siguienteNumero` sólo cuenta lo que existe) y **recibe el QR del anterior**; las hojas viejas impresas pasan a mostrar el récipe nuevo. Es coherente con «siempre el récipe vivo» pero conviene saberlo: para cortar un QR viejo se **anula**, no se elimina el récipe. Tampoco hay limpieza: eliminar un récipe o una consulta deja su fila (inofensiva: la página dice «ya no existe»). `ObtenerVerificacionDeRecipe` atrapa cualquier `QueryException` del `INSERT` y re-lee por récipe: si no es la carrera del índice único, reintenta 5 veces con credenciales nuevas y vuelve a lanzar. El récipe de la pareja guarda `recipe = 1` (no `NULL`) para que el índice único valga igual en SQLite y en MySQL. La ruta de «Anular» da 404 si el récipe nunca se imprimió (no hay fila). Y en un test que fuerza la carrera con `RecipeVerificacion::creating(...)`, hay que limpiar con `RecipeVerificacion::flushEventListeners()` en `tearDown` (misma familia que la trampa 68).
81. **Una hoja en blanco son renglones que se cortan, no renglones que se miden.** Medido al hacer WEB-5.5b (2026-10-12): el cuerpo de una hoja real se reparte por
    `MedidasDeLaMitad` (gemelo del CSS, trampa 48), pero el de un formato en blanco no necesita esa cuenta: `.cuerpo-en-blanco` es una columna `flex` cuyos bloques toman el
    alto por su peso (`seccion-peso-N`) y cada bloque es una `grid` de filas fijas de 8 mm con `overflow: hidden`, de modo que un renglón a medias **pierde su raya
    inferior** y no queda a la vista. Los renglones son 40 `<span>` por bloque (de sobra). La raya es un `border-bottom` de .3 mm en `#3c3c46` (no un fondo: Chrome no
    imprime fondos sin «gráficos de fondo», trampa 48). Si se agrega un formato con más bloques, se ajustan los `peso`, no una constante de alto. Y como `_documento-mitad` y
    `_recipe-mitad` sirven a la hoja real **y** a la en blanco, la rama `enBlanco` va con `?? false` y un test (`test_las_hojas_reales_no_cambian`) fija que la hoja real no
    la trae.
82. **Los títulos de los documentos viven en `DocumentoImpreso::TITULOS`, y un `?modo=` no se guarda.** (a) `SolicitudesParaImprimir` ya no escribe los títulos a mano: lee de esa
    constante, igual que `FormatosEnBlanco`; si se cambia un título hay que cambiarlo ahí (un test compara la hoja en blanco contra la constante). (b) El récipe en blanco
    elige el modo con un enlace GET a su propia dirección (`?modo=rp_indicaciones|solo_indicaciones`), **no** pasa por `recipes/opciones-de-impresion`, que sí guarda en
    `recipe_formatos`: un valor inventado cae en el modo guardado. (c) Un verificador que busca «cédulas» con `/[VEJG]-\d{5,}/` se topa con el **RIF** del membrete
    (`J-88888888-8`): hay que quitarlo del texto antes de comprobar que no hay datos de paciente.
83. **`finfo` no es una fuente fiable para un HEIC, y `mimetypes`/`getMimeType` tampoco lo son para decidir qué se importa.** Medido en WEB-T.1 (2026-10-12): en esta máquina `finfo` sí da `image/heic` para una caja `ftyp heic` mínima, pero según la versión de libmagic sale `image/heif` o `application/octet-stream`, y (trampa 31) usa la extensión como pista. La importación de archivos de la PC **no usa `finfo`**: `App\Clinica\Archivos\TipoRealDeImagen` decide por la **cabecera** (JPEG `FF D8 FF`, PNG, GIF, `RIFF…WEBP`, y la caja ISO `ftyp` por su marca principal) y la herramienta `bridge/subir-imagenes.js` repite exactamente la misma regla; si se cambia una, hay que cambiar la otra (el self-test y `TipoRealDeImagenTest` fijan los mismos casos). AVIF comparte el contenedor y se rechaza por su marca (`avif`/`avis`, principal o compatible). `ArchivosClinicos::EXTENSIONES`/`mimes()` (el formulario de la web) **no** incluyen HEIC: la importación usa `EXTENSIONES_IMPORTADAS`/`mimesImportados()`.
84. **`imagenes_de_la_pc` es la fuente de "qué falta", y sólo `imagen_consulta` cambia su columna legada.** Una referencia está *hecha* si hay un registro `subida` —u `omitida`— cuyo `ruta_pc` **o** cuya `ruta` de la nube es el valor actual de la celda (`ReferenciasDeImagenes::registro`): sin la segunda mitad, `imagen_consulta` (que después de migrar guarda la ruta relativa) volvería a ser pendiente para siempre. Si el escritorio vuelve a subir la fila con **otra** ruta de PC, deja de coincidir y vuelve a ser pendiente (se reemplaza el archivo y se borra el anterior). **Sin resolver**, sólo en un consultorio que use los dos sistemas: si el escritorio re-sube la **misma** fila de `imagen_consulta` (su `UPDATE` no mira `sync_changes`, trampa 53), la columna `imagen` vuelve a la ruta de la PC con `disco` aún puesto; `ImagenesDeLaConsulta::fila` la lista como «En el disco del consultorio» (la ruta no es relativa) aunque el archivo siga en la nube, y `imagenes_de_la_pc` conserva el vínculo. El rastro de la migración usa `source = migracion` (columna `varchar(20)`): no es `web`, `mobile` ni `escritorio`, así que no entra en el last-write-wins de nadie ni lo cuenta `SyncResumenService`. Las demás tablas (`imagen_pacientes`, `imagenes`, `pacientes.foto_pac`) no se tocan y **no dejan rastro** en `sync_changes`.
85. **`whereExists` de Laravel 8 sólo acepta un `Closure`, y los nombres de tabla no se envuelven dos veces.** Dos tropiezos de WEB-T.1: (a) pasar un `Builder` a `whereExists` da `TypeError` (la subconsulta se arma como cierre); (b) un identificador ya envuelto (`"imagen_consulta"`) pasado a `select("{$t}.id")` se vuelve a entrecomillar y revienta: a los métodos del Builder van los nombres **planos** y sólo dentro de `whereRaw`/`DB::raw` se usa `DB::getQueryGrammar()->wrap()`. Los nombres de tabla y columna que llegan a SQL salen **siempre** de la lista blanca `config/imagenes_pc.php` (`CatalogoDeImagenes`), nunca del cliente. Y `pacientes` no tiene `reg_medico`: su dueño es `medico_pacientes` (`dueno = paciente`).
86. **Un POST más grande que `post_max_size` llega SIN cuerpo, y un archivo mayor que `upload_max_filesize` llega con el campo en error.** El PHP por defecto tiene 2M/8M y el tope de la importación es 10 MB: `POST /api/sync/imagenes/subir` distingue los dos casos y responde **413 `limite_del_servidor`** (el cuerpo vacío con `Content-Length` > 0, o `UPLOAD_ERR_INI_SIZE`), que la herramienta trata como **error pendiente** —no como omisión: es de configuración del servidor, no del archivo—. En producción hay que subir `upload_max_filesize` a 12M y `post_max_size` a 14M (ROADMAP §5). Para probarlo en local sin tocar el `php.ini` del sistema: copiar el `php.ini` a una carpeta y arrancar `php artisan serve` con la variable de entorno `PHPRC` apuntando a ella (el `php -S` hijo la hereda; `php -d` no pasa por `artisan serve`). Y un detalle de los scripts de edición: un `python` en heredoc convierte `\x00`, `\n` y `\\` de los literales (llegó un byte NUL a una expresión regular de PHP): los scripts con regex o saltos van con la herramienta Write y se corren aparte, y se comprueba con `php -l` y un `grep`.
87. **El recordatorio lee la plantilla por la `clave` de la cita, no por el registro: sin fila del escritorio la plantilla guardada no se usaba nunca.** Medido al hacer WEB-8.4 (2026-10-12). `EnviarRecordatorio` y `RecordatoriosDeAgenda` resuelven `evolucion` con `reg_medico` + `clave = cola.medico`, y `cola.medico` sale de `Medico::claveDeEvolucion` (por `correo_med`). Un médico que nunca tuvo la configuración del escritorio guarda su plantilla (web o móvil) en una fila nueva con `clave = 0` y sin `correo_med`, así que sus citas nacen con `medico = null` y el recordatorio ignoraba lo escrito. Ahora, si la cita no se resuelve por clave, vale la plantilla de la **primera fila de `evolucion` del registro** (la misma que escribe `GuardarMembreteDelMedico`); si la fila existe pero la plantilla está vacía sigue valiendo el mensaje por defecto. Límite: en un registro compartido por varios médicos sin fila propia, la cita toma la plantilla de la primera fila. Y la pantalla guarda bajo el **registro principal** (como 8.2, trampa 66) mientras el saludo y la pantalla de Cumpleaños leen y escriben bajo el `reg_medico` del **contexto**: con un solo registro (lo común) coinciden.
88. **`formato_print` sube ENTERA desde el escritorio (trigger de sentencia) y el móvil no la baja.** Medido al hacer WEB-8.4 en `quitar-registro-cambios.sql` (sólo `tr_ddrsync_formato_print`) y `f_agregar_datos.srf:1684` (índice único `primarykey`, sin PRIMARY KEY): `CambiosEscritorio::reemplazarTabla` borraba todo lo del médico, así que `config/sync_legado.php → sin_clave_con_altas_del_app` suma `formato_print` y `CrearPlantillaDeTexto` anota un `created` por fila (dos tests en `CambiosEscritorioTest`, uno de contraste sin la línea). Sin resolver, sólo en un consultorio que use los dos sistemas: una plantilla del escritorio que la web edita o borra vuelve a su versión cuando el escritorio re-sube la tabla (el reemplazo reinserta con ids nuevos), y el `codigo` correlativo de la web puede coincidir con uno que cree el escritorio (la nube no tiene índice único). El móvil no guarda `formato_print`: el `deleted` es sólo rastro.
89. **Una petición de la misma sesión entre el POST y la redirección se come el aviso o los errores.** Medido al verificar WEB-8.4 en Chrome: el editor de mensajes pide la vista previa con *debounce* de 250 ms; si se aprieta «Guardar» antes de que dispare, esa petición (misma sesión) llega entre el 302 y el GET y consume lo que `with()`/`withErrors()` dejó para la pantalla siguiente (el síntoma: el tope de 500 rechazado y **ninguna** señal en pantalla). `plantillas-mensaje.js` cancela el temporizador y aborta la petición al enviar el formulario; un verificador espera la respuesta de la vista previa antes de guardar. Y de paso: `fill` de Playwright respeta el `maxlength` del campo, así que probar el rechazo del servidor exige quitarlo antes.
90. **Dos detalles de los SMS y del nombre del paciente en los recordatorios.** (a) Un SMS no mide siempre 160: con el alfabeto GSM-7 caben 160 (153 por trozo si se parte) y un solo carácter fuera de él (`á`, `í`, `ó`, `ú`, un emoji) pasa todo a Unicode con 70 (67 por trozo); `{ } [ ] \ ^ ~ | €` ocupan dos lugares (`App\Notificaciones\LargoDeSms`). La vista previa cuenta sobre el mensaje con datos de ejemplo —el paciente de ejemplo es «Ana Ejemplo», sin tilde, para no forzar Unicode— y avisa que el largo real varía con los nombres. (b) **Diferencia anterior a WEB-8.4, sin resolver:** el diálogo «Recordar al paciente» de la agenda arma `{paciente}` como «Apellidos, Nombres» (`CitaDeAgenda`) y `EnviarRecordatorio` como «Nombres Apellidos»; el texto restante es idéntico. (c) La enfermedad actual (`EA`) es un tipo de `formato_print` del escritorio que la consulta de la web **todavía no ofrece** (WEB-4.2 no lleva plantillas): el catálogo lo permite mantener y lo rotula así.
91. **El rastro lleva el usuario sólo si pasa por el modelo `SyncChange`; un `INSERT` directo se lo salta.** Medido al hacer WEB-8.5 (2026-10-12): `sync_changes.user_id` se llena en el evento `creating` del modelo (`App\Sync\UsuarioDelRastro`: la sesión del guard `web`, nunca en peticiones `api/*`) y sólo cuando `source` es `web` o `api` (el `api` es el que dejan `Cola` y `Paciente` al borrarse, que desde la web también son de una sesión). Hay ~25 archivos que escriben el rastro y todos usan `SyncChange::create` (directo o por `RegistroDeCambios`); `RastroConUsuarioTest` falla si aparece un `DB::table('sync_changes')->insert` o `SyncChange::insert/upsert`. Un cambio con `source = web` pero sin sesión (consola, job) queda en `NULL`, y todo lo anterior a la migración es «sin usuario»: no hay de dónde recuperarlo. En los tests, `actingAs` sobre el guard web basta; el usuario de un cambio del móvil (`Sanctum::actingAs`) nunca se anota.
92. **La bitácora lee `sync_changes`, que no es una bitácora completa.** (a) Sólo están las escrituras que dejan rastro: sedes, consultorios, cumpleaños y el alta de pacientes no aparecen. (b) Se guarda el valor nuevo y no el anterior, así que no hay «de X a Y»; por privacidad sólo se muestran monto, hora, fecha y atención de una cita (`DiccionarioDeLaBitacora::VALORES_VISIBLES`) y el SQL ni siquiera trae el `value` del resto. (c) Una baja deja la fila sin existir: no se puede relacionar con un paciente y el filtro por paciente no la incluye. (d) `RastroDeTratamientos` deja dos `deleted` por baja (el del servidor y el alias del teléfono): la bitácora excluye `tratamientos`/`tratamientos_detalle`, y la migración de imágenes (`source = migracion`). (e) Se agrupa por usuario + origen + tabla + registro + `SUBSTR(occurred_at, 1, 19)` (el segundo): una acción que toca varios registros son varias líneas. (f) Sin total de páginas: contarlo recorrería la tabla; «Más antiguas/Más recientes».
93. **Una secretaria compartida entre consultorios no se administra desde la web del médico.** `CuentaService::secretariaCompartida` mira si tiene vínculos fuera de los registros del médico: restablecer su clave o bloquearla afectaría al otro consultorio y, con una clave que el médico conoce, vería datos ajenos, así que sólo se le puede quitar SU acceso (`quitarAccesoDeSecretaria` borra sus vínculos y, si no le queda ninguno, la deja inactiva con `blocked_reason`). Una cuenta sin vínculos ya no aparece en la lista de nadie ni se puede volver a crear con el mismo correo (se rechaza, no se adopta): la reactiva un administrador. Y en los verificadores de navegador: la clave temporal viaja por la sesión flash (se pierde al recargar) y el diálogo de bloqueo se llena por JS, así que se prueba con la sesión del médico y otra sesión limpia para ella.
94. **`@selected`, `@checked` y `@disabled` no existen en Blade de Laravel 8: llegan en el 9.** Medido al hacer WEB-6.1 (2026-10-13) con el desplegable del eco pélvico: la directiva hay que escribirla con un `@if` (`<option value="…" @if (… === …) selected @endif>`). El repo ya lo tenía anotado para `@disabled` en `clinica/agenda/formulario.blade.php:33` y `envio.blade.php:99`; es la misma regla para las otras dos.
95. **`Get-Content -Raw` sin `-Encoding` lee los archivos UTF-8 de este repo como ANSI y los reescribe con mojibake.** Medido al hacer WEB-6.1: un reemplazo de rótulos con el par `Get-Content -Raw` + `Set-Content -Encoding UTF8` dejó *todos* los acentos de `SolicitudesParaImprimir.php` como `Ã³` (79 ocurrencias) y el archivo pasó `php -l` igual: el error sólo se ve en la pantalla. La reparación es leer el archivo como UTF-8 y escribir sus bytes en CP1252 (`mb_convert_encoding($t, 'Windows-1252', 'UTF-8')`, hecho en PHP) y **verificar con un `grep` de `Ã`**. Regla: para editar texto de este repo, o se usa la herramienta de edición, o se pasa `-Encoding UTF8` en las dos puntas; y ningún archivo generado o editado a mano debe quedar con BOM.
96. **Encender un ítem del panel del shell rompe todas las aserciones que fijan el conteo, y son más de las que se ven.** Medido en WEB-6.1: **trece** lugares. El unit del shell (`ConsultaShellVistaTest`: `assertSame(10, totalListos)`, el array de `tarea` por ítem y la lista de los que tienen enlace) y **once** tests de feature con `assertSee('10 de 13 disponibles')`. El decimotercero —`ConsultaRecipesWebTest`, que cuenta `substr_count($panel, 'Próximamente')` esperando 3— **no** aparece en ninguna búsqueda del string del conteo: lo encontró la suite completa. Antes de encender un ítem, buscar `de 13`, `totalListos` y `Próximamente`.
97. **La fecha de un estudio es `NOT NULL` y al corregir no se reusa la de la fila.** Medido al verificar WEB-6.1 en Chrome: `GuardarEcoGinecologico` completaba la fecha vacía con `filaParaEscribir()->fecha`, o sea con la que ya tenía el estudio, así que el médico no podía dejar el campo en blanco (guardaba la vieja) ni cambiarla a hoy. El escritorio propone `today()` al crear (`w_cargar_visualizar_ecografia.srw:223`) y eso es lo que vale cuando el campo llega vacío: se completa con `FechaDelConsultorio::hoyComoFecha()`, no con lo guardado.
98. **La FUR del eco pélvico no sale de la consulta ni de `historias`: sale del control prenatal.** Al entrar a la pestaña *Ginecologico*, el escritorio hace `SELECT "pre_natal_observaciones"."fur" … WHERE historia = :historia` y la copia en el campo si el eco no tenía ninguna (`w_consulta_principal.srw:21391-21405`). En la nube la lectura se acota al `reg_medico` y, si la historia tiene varias gestas, se toma la FUR **más reciente** (`App\Clinica\Consulta\FurDelControlPrenatal`). Y ojo con las fechas del informe: la cabecera imprime **`consultas.fecha`**, no la del estudio (`d_eco_pelvico_print.srd`), y las unidades de las medidas (MHz/mm/cc) están sólo en el DataWindow de impresión, no en la pantalla: el mapa del dato las guarda como `unidad` para la hoja.
99. **Un DataWindow con el mismo nombre base y sin `_2` es un HUÉRFANO, y leerlo da el número y los campos equivocados.** Medido al hacer WEB-6.6 (2026-10-13), y me costó un error real: implementé la pestaña *Prenatal* del control prenatal desde `d_pre_natal_observa.srd`, que **ninguna ventana del `.pbl` usa** (grep de `dataobject=` sobre todos los `.srw`: sin resultados). La pestaña viva usa **`d_pre_natal_observa_2.srd`** (`w_consulta_principal.srw:24725`; también `w_consulta_principal_modificar.srw:20606` y `..._vieja.srw:18379`). Consecuencias de haber leído el huérfano: (a) la **FPP** que creí del control prenatal sumaba **270** y la viva suma **281** (`d_pre_natal_observa_2.srd:38`) —y hay un tercer número, **283**, en los reportes del control y en todos los ecos: `d_pre_natal_observa_reporte.srd:45`, `d_eco_obstetrico_trimestral_nueva.srd:183`—; y (b) el huérfano tiene **9 columnas** y el vivo **16**: la pestaña viva edita además `partos`, `cesarea`, `abortos`, `otros`, `final`, `multiple` y `cantidad` (`d_pre_natal_observa_2.srd:15-21`, que el código de la ventana escribe en `:24503`, `:24511` y `:24513`), y `parto` —el rótulo *Parto:* del huérfano— está `visible="0"` en el vivo. Regla: antes de tomar un `.srd` como fuente, **confirmar que alguna ventana lo asigne** (`Select-String -Path *.srw -Pattern 'dataobject\s*=\s*"d_lo_que_sea"'`), y desconfiar de la variante sin sufijo cuando existe una con `_2`.
100. **`orderByDesc(DB::raw(...))` no aplica la dirección en Laravel 8.** Medido en WEB-6.6 con el `COALESCE(gesta_clave, 1)` de las gestas: `->orderByDesc(DB::raw('COALESCE(gesta_clave, 1)'))` devolvía **la primera** fila en lugar de la última (el `max()` con la misma expresión sí funcionaba). El `DESC` va dentro del `orderByRaw('COALESCE(gesta_clave, 1) DESC')`.
101. **Una fachada que reenvía con menos argumentos se come el parámetro nuevo, y PHP no se queja.** Medido al hacer WEB-6.6 (2026-10-13), y me costó una tarde: agregué `?int $gesta = null` a `SolicitudesParaImprimir::documento()` y al llamarlo desde el controlador la hoja seguía saliendo con la gesta equivocada. El controlador **no** llama a `SolicitudesParaImprimir`: llama a **`DocumentosParaImprimir::documento()`**, una fachada que despacha entre récipes y solicitudes y que seguía teniendo cuatro parámetros, así que el quinto se descartaba en silencio (a un método de usuario se le pueden pasar argumentos de más: no hay error, `func_get_args()` los ve pero el parámetro no existe). La traza que lo encontró fue `func_get_args()` **dentro** del método destino: decía `array:4` cuando el llamador creía pasar cinco. Antes de agregar un parámetro a un método que tiene una fachada, buscá **todas** las llamadas (`grep 'documento('`), no sólo la de la clase que estás tocando.

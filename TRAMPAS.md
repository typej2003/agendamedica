# Trampas conocidas de MedicAPI

Son las trampas **medidas** del repo (no teoría): varias ya costaron una tarde de depuración. Se leen
**antes de escribir código** y **cada tarea agrega la suya al final**, con la fecha y la tarea que la
encontró. El estado del código, los comandos y el mapa del repo están en
[`AGENTS.md`](AGENTS.md). Van **34** y el archivo se lee entero en una sola pasada.

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
19. **La consulta tiene su propio grupo de roles, y la secretaría no entra.** Medido al hacer WEB-4.1:
    `role:Root|Medico` **dentro** del grupo `clinica.contexto` (no al revés: el contexto se necesita para leer
    la consulta) y la secretaría recibe **403**, que es lo que el test afirma (`assertForbidden`). Tres cosas
    dependen de esa misma regla y **no pueden divergir**: la ruta del shell, el redirect del mostrador cuando
    la secretaría toca "Atender" (la cita **sí** queda atendida y vuelve a la agenda con el aviso, en vez de
    caer en el 403) y el botón "Editar consulta" de la agenda, que a ella **no se le ofrece**. Por eso la
    regla vive en `User::trabajaLaConsulta()` y la bandera **baja desde `AgendaController`** hasta la fila
    (`AgendaVista` → `JornadaVista` → `FilaDeCitaVista`): no se consulta el rol en la vista.
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


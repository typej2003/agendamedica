# MedicAPI — backend de AppDDR

Guía de entrada para trabajar en este repo. **Antes de escribir código, leé "Antes de empezar"** (rama
y entorno) y la wiki indicada al final: este repo no lleva la especificación funcional.

## Antes de empezar: rama y entorno

El working tree está en la rama **`desarrollo`** y el código está presente. Dos advertencias:

- **Las dos ramas llevan el mismo código.** `main` y `desarrollo` tienen el **árbol idéntico** desde el
  merge `7429df8` (2026-10-13): `desarrollo` es donde se trabaja y `main` es la punta publicada, que se
  actualiza mergeando. (`main` **no** es "sólo el `leeme`": eso decía esta guía y era falso — antes del
  merge tenía 354 archivos y su historial propio, con el fix de `sync_credentials.expires_at` del 07/10, que
  el merge conservó.) Pushear `main` o `desarrollo` da lo mismo, pero **la rama de trabajo es `desarrollo`**.
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

⚠️ **Agilizar el desarrollo (decisión del usuario, 2026-10-14): la suite completa NO se corre por rutina.** Se corre
el filtro de la tarea (`php -d memory_limit=2G artisan test --filter=…`) y **el usuario avisa cuándo correrla entera**,
antes de subir. Motivo: tarda unos seis minutos y frena el desarrollo. Los números de más abajo son mediciones
puntuales, no un paso obligatorio de cada cambio.
📝 **Tratamiento** (WEB-5.7, `?seccion=tratamiento`): el ítem TRATAMIENTO del legado, un texto libre con plantillas `TR` en
`consultas.laringe`, entre Récipes y Solicitudes. **Es `TextosDelExamen::TRATAMIENTO`** (marcado `panel`, así que `claves()` no lo
cuenta entre las solapas del examen; la ruta de guardado, que es la misma, usa `claves(true)`), con el mismo ViewModel
(`TextoDelExamenVista`) y el mismo parcial (`_evaluacion-texto`, vía `_tratamiento`), y `ConsultaTextoDelExamenController` vuelve a
su propia sección. Es el «PLAN DE TRATAMIENTO» del resumen médico. Tests: `ConsultaTratamientoWebTest`.

📋 **Listados clínicos entre fechas** (WEB-6.8, `/clinica/listados`, sólo `role:Root|Medico`): una pantalla parametrizada por
`?listado=` (catálogo en `App\Clinica\Listados\ListadosDisponibles`, selector en `index`), con los tres del alcance hechos
(2026-10-14): **embarazos y partos**, **pacientes por rango** y **consultas por rango**. Cada listado suma su filtro, su lectura
(`EmbarazosEntreFechas`, `PacientesPorRango`, `ConsultasPorRango`), su ViewModel en `Listados/Vistas/` y su parcial en
`resources/views/clinica/listados/`; la cuenta de edad (`RangoDeEdades`) y la de fechas (`RangoDeFechas`) se comparten. El
estado civil se filtra por `codeestado` (no por `codesegemp`, como dos DataWindows del legado). *Consultas por rango*: la fecha es
la de la consulta, una fila por consulta (sus listas se leen por lote y se pegan) y motivo, diagnóstico, medicamento y
antecedente son **texto escrito** que se busca por nombre (`BusquedaDePacientes`: sin acentos, todas las palabras, comodines
de `LIKE` como texto; sin `||` porque en MySQL es un `OR`). Tests:
`ListadosWebTest`, `ListadosPacientesWebTest`, `ListadosConsultasWebTest`,
`Unit/Clinica/{ListadoDeEmbarazosTest,ListadoDePacientesTest,ListadoDeConsultasTest}`; verificador
en Chrome `.agent-tools/verificar-listados-web.js`.

🏷️ **Separación por especialidad (2026-10-18).** Lo que las pantallas compartidas ofrecen depende de la especialidad del
contexto, y la decisión vive en un solo lugar por tema:
- **Listados**: `ListadosDisponibles::ESPECIALIDAD` declara de qué especialidad es cada reporte. *Embarazos y partos* es de
  ginecología/obstetricia: pediatría no lo ve en el menú y un `?listado=embarazos` **vuelve al listado por defecto** de la
  especialidad (a diferencia de las secciones de la consulta, que siguen abriendo por URL directa: acá la pantalla es de sólo
  lectura y no hay gestas que listar). Sin especialidad en el contexto no se filtra nada.
- **Plantillas de texto**: `App\Clinica\Catalogos\PlantillasDeLaEspecialidad` traduce `AlcanceDeLaEspecialidad::tiposDePlantillaOcultos()`
  a los tipos del ABM. En pediatría no se ofrecen `CO`, `CI`, `HI`, `RE`, `EG`, `UG`, `O1`-`O3`, `ED`, `UM`, `UA`, `EM` ni `ER`; quedan
  `EV`, `IM`, `DI`, `EA`, `TR` y `RF` (su solapa *Examen físico* lo usa). Se filtra el listado, el filtro por tipo, el
  `<select>` del diálogo y el subtotal. **Un tipo del legado que el catálogo no conoce no se oculta nunca** (esconderlo borraría
  datos del médico), y editar por URL directa una plantilla de otra especialidad la abre y conserva su tipo. Ginecología no cambia.
- **Formatos en blanco**: los informes de los ecos que la especialidad no tiene no se ofrecen (`FormatosEnBlanco::porEstudio()` +
  `AlcanceDeLaEspecialidad::formatosEnBlancoOcultos()`): en pediatría son los dos obstétricos y el Doppler. El resto del catálogo
  (récipe, informe, constancia, reposo, referencia, hospitalización, dieta, laboratorio y estudios) se ofrece siempre.
Tests: `Unit/Clinica/{PlantillasDeLaEspecialidadTest,AlcanceDeLaEspecialidadTest,ListadoDeEmbarazosTest}`, `MenuDelArmazonWebTest`,
`PlantillasDeTextoWebTest` y `FormatosEnBlancoWebTest`.

🧬 **Subespecialidades (postgrados) y catálogo de la consulta por especialidad (2026-10-19).** Una **subespecialidad** cuelga
de una especialidad (nefrología pediátrica es un postgrado de pediatría) y un médico puede tener varias, en una o en varias
especialidades. Los postgrados **de la especialidad del contexto se suman**: encienden módulos del menú y secciones de la
consulta.
- **Tablas**: `subespecialidades` (catálogo), `medico_subespecialidad` (qué tiene cada médico) y `subespecialidad_modulo`
  (módulos del menú, con `implementado`/`visible_para`/`orden`, igual que `especialidad_modulo`). Migración
  `2026_10_19_120000_create_subespecialidades`.
- **Dónde se declaran**: en el manifiesto (`config/especialidades/<especialidad>.php` → `subespecialidades`), con `modulos`
  (menú) y `secciones` (consulta). `ManifiestoEspecialidad::normalizarSubespecialidades()` valida slug y nombre;
  `RegistroEspecialidades` rechaza el slug repetido entre especialidades y `EspecialidadesYModulosSeeder` siembra todo
  (`SUBESPECIALIDAD_IMPLEMENTADA` dice qué módulo ya tiene pantalla).
- **Contexto**: `ContextoTrabajo::actual()` resuelve `subespecialidades` (las del médico de la especialidad activa) y
  `secciones_postgrados` (las claves que declara el manifiesto); `ContextoTrabajo::modulos()` une los módulos de la
  especialidad con los de sus postgrados, sin duplicar. El middleware comparte esa colección como `$modulos`.
- **Catálogo de la consulta**: `SeccionesDeLaConsulta` tiene **dos catálogos** (`ITEMS_GINECOLOGIA` e `ITEMS_PEDIATRIA`) y un
  mapa `EXTRA` de secciones que agrega un postgrado (hoy `indicadores` = ítem 5 de pediatría, nefrología). El pediátrico es el
  árbol de PediaReport: 14 ítems sin nefrología y 15 con ella; sin estudio ecográfico ni control de embarazo. Los ítems que ya
  tienen pantalla en otro lado **redirigen** desde `ConsultaController::destinoDeLaSeccionPediatrica()` (inmunizaciones →
  carnet de vacunas; alimentación → *Dieta*; representante → ficha), y sólo si la sección existe en el catálogo de esa
  especialidad. **Exámenes/Estudios y Alimentación salieron de esa lista el 2026-10-19**: cada una tiene sección
  propia, como el escritorio.
- **Admin**: `/admin/especialidades` asigna los postgrados de las especialidades que el médico ya tiene
  (`CuentaService::asignarSubespecialidades()`); quitar una especialidad quita sus postgrados (`postgradosQuitados` en el
  resultado de `asignarEspecialidades()`).
- **Indicadores incidentes (nefrología pediátrica, 2026-10-19, `3a05bc7` + corrección el mismo día)**: el ítem 5
  pediátrico tiene pantalla. El escritorio guarda **dos cosas** y la web escribe las dos: los **números** en
  **`examenes_paciente_consulta`** —una fila por consulta con clave `hhistoria` + `hconsulta`; `UPDATE`/`INSERT` en
  `w_consulta_principal.srw:2924-2960` y `:3554`, se releen con `dw_76.retrieve` `:25651`— y el **texto** compuesto
  `ETIQUETA: valor.` (26 campos de `ue_guard_exam` `:25090-25204`) en `consultas.resultadoexamencomp` (`:2821`,
  `:2850`), que es lo que imprimen el resumen médico y el pre-operatorio. ⚠️ Antes se creía que los números no se
  guardaban: **sí se guardan**, en esa tabla. `App\Clinica\Consulta\IndicadoresIncidentes` es el mapa (33 campos:
  Sangre, Orina y Gases; los gases, el volumen, el Na urinario y la creatinina calculada se guardan pero **no** entran
  en el texto, `:25191-25200`), `GuardarIndicadoresIncidentes` escribe sólo lo que cambió con rastro por columna en
  las dos tablas, `IndicadoresIncidentesVista` + `_indicadores.blade.php` son la pantalla, `IndicadoresIncidentes` del
  shell la monta en la sección `indicadores` y `ConsultaIndicadoresController` la ruta
  `POST /clinica/consulta/{consulta}/indicadores`. Modelo: `App\Models\ExamenPacienteConsulta`. Tests:
  `Unit/Clinica/IndicadoresIncidentesTest` (7) y `Feature/ConsultaIndicadoresWebTest` (6).
- **Neumología pediátrica (2026-10-19, `fe788ff`)**: **como en el escritorio**, una **solapa dentro de Evaluación
  física** (`PestanasDelExamen::NEUMOLOGIA`) que sólo se ofrece si el médico tiene el postgrado
  (`subespecialidad.secciones` → `secciones_postgrados`). Los tres informes: R.X. de tórax → `consultas.rayosx`,
  espirometría → `consultas.faringe` (la columna de la citología de ginecología: el escritorio las comparte) y
  broncoscopía → `consultas.broncoscopia`. `NeumologiaDeLaConsulta` es el mapa, `GuardarNeumologiaDeLaConsulta` la
  escritura (sólo lo que cambió, rastro por columna), `NeumologiaVista` + `_evaluacion-neumologia.blade.php` la
  pantalla y `ConsultaNeumologiaController` la ruta `POST /clinica/consulta/{consulta}/neumologia`. Una URL directa a
  la solapa sin el postgrado abre la de entrada de la especialidad. Tests: `Unit/Clinica/NeumologiaDeLaConsultaTest` (2)
  y `Feature/ConsultaNeumologiaWebTest` (4).
- **Procedimientos (gastroenterología pediátrica, 2026-10-19)**: el ítem 8 del árbol de PediaReport
  (`w_consulta_principal.srw:2671`, «PROCEDIMIENTOS») con las **dos endoscopías digestivas** como pestañas:
  `superior` → `endosc_diges_super` y `inferior` → `endosc_diges_infer` (una fila por consulta, clave `historia` +
  `consulta`; el inferior guarda esófago/estómago/duodeno con un estado `char(1)`). `full_texto` es lo que imprime el
  informe; las columnas `ima_*`/`obs_ima_*` son rutas de la PC del consultorio y **no se tocan**.
  `EndoscopiaDigestiva` es el mapa, `GuardarEndoscopiaDigestiva` la escritura (sólo lo cambiado, rastro por columna),
  `ProcedimientosVista` + `_procedimientos.blade.php` la pantalla, `ConsultaProcedimientosController` la ruta
  `POST /clinica/consulta/{consulta}/procedimientos/{tipo}` y la sección `procedimientos` del catálogo (postgrado
  `gastroenterologia-pediatrica`, cuya `secciones` pasó de `endoscopias` a `procedimientos`). Modelos
  `EndoscopiaSuperior` y `EndoscopiaInferior`. La **tercera pestaña es la eco abdominal** (el ítem también abre
  `w_cargar_visualizar_eco_abdominal`): reusa `EcoAbdominalVista` y su parcial del estudio, y el guardado lleva
  `volver = procedimientos` para volver a esta sección (`ConsultaEstudioController::destino`). Tests:
  `Unit/Clinica/EndoscopiaDigestivaTest` (5) y `Feature/ConsultaProcedimientosWebTest` (7).
- **I.Q. (cirugía pediátrica, 2026-10-19)**: el último ítem del árbol (`w_consulta_principal.srw:2717`, después de la
  pre-factura; el catálogo lo ancla al final porque la pre-factura no tiene sección — `ANCLAS_SIN_SECCION`). Tiene sus
  **tres pestañas**: **intervenciones** (`interven_consulta`, una fila por `codigo_inter` `char(3)`, alta y baja desde
  la web), **evaluación pre-operatoria** (`pre_aneste`: `tipo`, `resultado`, `emergencia` `S`/`N`) y **riesgo
  quirúrgico** (`riesgo` de catálogo + `riesgo_paciente` marcado al **paciente** + `riesgo_paciente_opera` con el
  resumen: `puntos` = suma de lo marcado, `clase`, `conclusion`, `nombre_intervencion`). Clases:
  `IntervencionesDeLaConsulta`, `EvaluacionPreoperatoria`, `RiesgoQuirurgico`; Actions `Guardar/QuitarIntervencion`,
  `GuardarEvaluacionPreoperatoria` y `GuardarRiesgoQuirurgico` (todas con rastro por operación/columna); ViewModels
  `QuirurgicoVista` + `RiesgoQuirurgicoVista` con los parciales `_quirurgico` y `_quirurgico-riesgo`; controladores
  `ConsultaQuirurgicoController`, `ConsultaIntervencionesController` y `ConsultaRiesgoQuirurgicoController`. Modelos
  `IntervencionConsulta`, `PreAnestesico`, `Riesgo`, `RiesgoPaciente` y `RiesgoPacienteOpera`. Tests:
  `Unit/Clinica/EvaluacionPreoperatoriaTest` (2) y `Feature/ConsultaQuirurgicoWebTest` (10).
- **Eco renal (nefrología pediátrica, 2026-10-19)**: la **segunda solapa de Indicadores incidentes**, que es de donde
  el escritorio lo abre (`w_cargar_visualizar_renal`, DataWindow `d_ultra_rinom`). Una fila por consulta en
  **`ultra_renal`** (clave `historia` + `consulta`), con las medidas de los dos riñones por prefijo (`rd_*` derecho,
  `ri_*` izquierdo: longitud, transverso, anteroposterior, complejo, LOE, litiasis y dilatación), más el texto básico,
  la conclusión, la sugerencia, los médicos y los datos del equipo. `EcoRenal` es el mapa, `GuardarEcoRenal` la
  escritura (sólo lo cambiado, rastro por columna), `EcoRenalVista` + `_eco-renal.blade.php` la pantalla,
  `ConsultaEcoRenalController` la ruta `POST /clinica/consulta/{consulta}/eco-renal` y `UltraRenal` el modelo. Tests:
  `Unit/Clinica/EcoRenalTest` (2) y dos de `Feature/ConsultaIndicadoresWebTest`.
- **Formatos en blanco de pediatría (2026-10-19)**: cinco entradas nuevas en `FormatosEnBlanco` —`endoscopia-superior`,
  `endoscopia-inferior`, `evaluacion-preoperatoria`, `indicadores-incidentes` y `eco-renal`—, con sus constantes y
  títulos en `DocumentoImpreso`. El catálogo pasa de **13 a 18** formatos. Tests: `FormatosEnBlancoWebTest` y
  `Unit/Clinica/FormatosEnBlancoVistaTest` (actualizados al número nuevo).
- **Informe impreso de las endoscopías (2026-10-19)**: `SolicitudesParaImprimir` ofrece `endoscopia-superior` e
  `endoscopia-inferior` **sólo si tienen texto** y arma la hoja con el mismo `textoDelExamen` que las solapas de texto
  del examen; el cuerpo es el `full_texto` del escritorio o, si está vacío, los hallazgos y la conclusión. Iconos en
  `DocumentosParaImprimir`. Tests: `Feature/ConsultaEndoscopiaImpresionWebTest` (3).
- **Informe impreso de la evaluación pre-operatoria (2026-10-19)**: `SolicitudesParaImprimir` ofrece
  `evaluacion-preoperatoria` si la consulta tiene algo cargado (la evaluación, el riesgo o las intervenciones) y arma la
  hoja con la intervención, la evaluación pre-anestésica (tipo, resultado y emergencia), el riesgo con sus **puntos** y
  su clase, y la conclusión. Icono en `DocumentosParaImprimir`. Test: uno de
  `Feature/ConsultaEndoscopiaImpresionWebTest` (4 en total).
- **Dosis pediátrica por peso en los récipes (2026-10-19)**: `App\Clinica\Consulta\DosisPediatrica` es **la fórmula
  del escritorio** (`pw9_ws/w_agregar_nueva_gesta_medicamento.srw:223-254`): el peso del examen físico de la consulta
  (`consultas.peso`), **topeado en 30 kg**; `posologia = (dosis × peso) / cada`; con presentación (`pvc` mg / `pvs` ml)
  pasa a **ml** (`pvs × posologia / pvc`) y con concentración a **gotas**; el intervalo del rótulo es `24 / cada` (el
  vademécum guarda **veces al día**) **sin truncar**, a diferencia del `integer hot` del fuente (corregido a pedido del
  usuario el 2026-10-19). Si el vademécum no dice `dosificacion = 'SI'`, queda su `uso`.
  `RecipesDeLaConsulta::medicamentos()` trae las columnas de la dosis y `ConsultaRecipesController::medicamentos()`
  devuelve **`indicacionSugerida`** y **`dosisCalculada`** con el peso de la consulta; `consulta-recipes.js` la carga en
  el campo de indicaciones (editable) y la muestra al pasar por encima. **El ABM del vademécum ya carga los siete
  campos** (`dosificacion`, `dosis`, `cada`, `durante`, `pvc`, `pvs`, `concentracion`) en `/clinica/catalogos/medicamentos`,
  en un bloque «Dosis por kilo»; `dosificacion = 'SI'` es la marca que enciende el cálculo (no hay columna aparte).
  Tests: `Unit/Clinica/DosisPediatricaTest` (7), `Feature/ConsultaRecipesDosisWebTest` (3) y
  `Feature/MedicamentoDosisCatalogoWebTest` (4).
- **Exámenes/Estudios (2026-10-19)**: el **ítem 6** de la consulta pediátrica **dejó de redirigir** a la solapa
  *Resultado de exámenes* de la evaluación física (Alexander: «está apareciendo lo mismo que en evaluación física»;
  el escritorio los tiene como dos ventanas distintas). Tiene sección propia (`?seccion=examenes-estudios`) con sus
  **tres solapas**: *Exámenes* → `consultas.nariz`, *Imagenología* → `consultas.laringe` y *Estudios exploratorios* →
  `consultas.oido` (`w_consulta_principal.srw:1650-1670`, escritura `:2852-2855`). No hay tablas ni Actions nuevas:
  las tres son claves de `TextosDelExamen` con `panel` (no son solapas del examen), usan `TextoDelExamenVista`,
  `GuardarTextoDelExamen` y el parcial `_evaluacion-texto`; lo propio es `PestanasDeExamenesEstudios` +
  `SeccionDeExamenesEstudiosVista` + `_examenes-estudios.blade.php`. Sus plantillas son de tipo **`EX`**, **`IG`** y
  **`EE`** (`TiposDePlantillaDeTexto`, y `AlcanceDeLaEspecialidad::PLANTILLAS_SOLO_PEDIATRIA` las oculta fuera de
  pediatría). ⚠️ Las columnas se comparten con ginecología con **otro significado** (`oido` = *Resultado de
  exámenes*, `laringe` = plan de tratamiento): cada especialidad ofrece sólo las suyas y **no se renombran**.
  Tests: `Feature/ExamenesEstudiosPediatriaWebTest` (5) y `Unit/Clinica/ExamenesEstudiosDeLaConsultaTest` (6).
- **Alimentación con sección propia (2026-10-19)**: en pediatría la dieta es el **ítem 9** de su árbol y era
  **además** una pestaña de *Solicitudes* renombrada *Alimentación*, así que se veía dos veces y al guardar el médico
  aterrizaba en *Solicitudes* (Alexander, O-40 y O-41). Ahora `?seccion=alimentacion` es su pantalla —el parcial
  `_alimentacion` reusa `_solicitudes-dieta`, con `DietaVista`, el catálogo `dietas` y las plantillas `DI`—, la pestaña
  **no se ofrece** en pediatría (`AlcanceDeLaEspecialidad::pestanasDeSolicitudesOcultas()` +
  `PestanasDeSolicitudes::ocultas`, y una URL directa a `&solicitud=dieta` cae en la constancia),
  `ConsultaDietaController::destino()` vuelve a la sección y los rótulos dicen «alimentación» (`DietaVista`). Ginecología
  no cambia. Tests: `AlimentacionPediatricaWebTest` (4) y `SubespecialidadesTest`; verificador
  `../.agent-tools/verificar-consulta-alimentacion-web.js`.
- **Falta**: sólo la **pre-factura** (F9, administración: se deja para después). El árbol de PediaReport, sus cinco
  postgrados y sus informes impresos están implementados.
Tests: `SubespecialidadesTest`, `Unit/Clinica/SeccionesPediatricasTest`, `EspecialidadesDelMedicoTest`,
`Unit/Clinica/{AlcanceDeLaEspecialidadTest,PlantillasDeLaEspecialidadTest}`.

✅ **La suite ya no toca la base de desarrollo**: el bloque `<php>` de `phpunit.xml` apunta los tests a
`database/database.testing.sqlite` (que `database/.gitignore` ya ignora) y `tests/bootstrap.php` la arma
sola —migrada y con los seeders— antes del primer test, así que no hay ningún paso previo que recordar.
Si alguien cambia ese `DB_DATABASE` por otra base, el bootstrap avisa por stderr y no migra nada.

🧪 **La suite también corre contra MariaDB/MySQL** (2026-10-19): `phpunit.mariadb.xml` + `composer test:mariadb`
(o `php vendor/bin/phpunit -c phpunit.mariadb.xml`). Es la única forma de ver lo que SQLite **no** ve: anchos de
columna (`1406 Data too long`), tipos (`1366 Incorrect integer/decimal value`), fechas inválidas y colaciones.
La base es `medicapi_testing` (el nombre tiene que terminar en `_testing`: el bootstrap la vacía con
`migrate:fresh` y **no** toca ninguna otra), y el host/puerto/usuario/clave salen del bloque `mysql` del `.env`
(acá, el MariaDB local en `127.0.0.1:3307`: `A:\Dev\Tools\mariadb-10.6.28-winx64\bin\mariadbd.exe
--defaults-file=...\data\my.ini --port=3307`). **`php artisan test` siempre usa `phpunit.xml`**: el perfil se
invoca con PHPUnit directo. La primera corrida destapó 1.378 errores que SQLite tapaba: casi todos fixtures de
los tests con `reg_medico` de 23-25 caracteres en una columna `VARCHAR(20)`; la regla que quedó es que **todo
valor de fixture respeta el ancho y el tipo reales de la columna del legado** (ver la trampa 141).

Los tests que tocan base de datos usan `DatabaseTransactions` (rollback automático) — ver
`tests/Feature/ConfiguracionMedicoTest.php` como referencia. La suite completa (`php artisan test`)
corre sin filtro desde que se eliminó el `Medico/ListMedicos.php` duplicado (commit `dfc6ce6`). La última medición **completa**, tras «Ayúdanos a mejorar» (2026-10-14), fue **1977 en verde y 3 que fallan**; después, los cuatro estudios (WEB-6.3 y WEB-6.4, 2026-10-14: Doppler perinatal, ecocardiograma fetal, mamario y abdominal) se verificaron con los **filtros de la tarea** (275, 352, 501, 409 y 63 en verde según la tarea, el último del listado de embarazos y partos) y **no se volvió a correr la suite completa**, por la decisión de arriba (las 3 son las de WhatsApp; incluye «Ayúdanos a mejorar»: `Feature/AyudaWebTest`, 5 tests, y `Feature/ComentariosAdminTest`, 7); la anterior, **tras WEB-6.5 (2026-10-13, el examen con sus cuatro solapas de texto), fue 1897 en verde y 3 que fallan** (las 3 son de `WhatsAppWebhookTest`, que espera 500 y recibe 200/403 porque faltan las credenciales de WhatsApp: es de entorno, no del código); la anterior, **tras el primer paso del mismo WEB-6.5 (la colposcopía y sus imágenes), fue 1891 en verde y 3 que fallan**; la anterior, **tras el mismo WEB-6.5 sin las imágenes, fue 1885 en verde y 3 que fallan**; y la anterior, **tras WEB-6.2 (2026-10-13, el eco obstétrico y su formato en blanco), fue 1869 en verde y 3 que fallan**; la anterior, **tras WEB-6.1 (2026-10-13, el estudio ecográfico), fue 1788 en verde y 3 que fallan** (1758 + 30 nuevos; las 3 son las de WhatsApp); la anterior, **tras WEB-T.1 (2026-10-12, la subida de las imágenes de la PC), fue 1639 en verde y 3 que fallan** (1597 + 42 nuevos; las 3 son las de WhatsApp; medida pasadas las 20:00 de Caracas, así que fallan además los 3 tests de la hora, trampa 59: 1636 + 6 fallas); la anterior, **tras WEB-5.5b (los formatos en blanco), fue 1597 en verde y 3 que fallan** (1564 + 33 nuevos; las 3 son las de WhatsApp); la anterior, **tras WEB-5.6 (el QR de verificación del récipe), fue 1564 en verde y 3 que fallan** (1514 + 37 de integración + 13 unitarias; las 3 son las de WhatsApp); la anterior, de
medición (2026-10-11, tras **WEB-8.1a**, los catálogos de medicamentos y tratamientos) es **1365 en
verde y 3 que fallan**; **la última (2026-10-12, tras WEB-5.4, laboratorio y estudios) es 1514 en verde y 3 que fallan** (1453 + 61 nuevos; las 3 son las de WhatsApp; antes, tras WEB-5.3b, 1449; tras WEB-8.1c, 1400) (1365 + 41 nuevos − 6 del ViewModel anterior que se reemplazó; las 3 son las de WhatsApp) (2026-10-12, tras **WEB-8.2**: 1321 + 35 + 9; antes 1319 tras **WEB-8.1b**; los 3 tests de la hora pasan de mañana y fallan pasadas las 20:00 de Caracas, trampa 59) por las credenciales de WhatsApp (es de entorno, no del código; medido varias veces,
una con la base de tests borrada) **más 3 tests que dependen de la hora real y fallan pasadas las 20:00 de
Caracas** (trampa 59: se mide por carpeta con `php -d memory_limit=2G artisan test tests/Unit` y `tests/Feature`). El test que era **flaky**
(`tests/Feature/ConsultaWebTest::test_la_numeracion_sigue_el_correlativo_de_la_historia`) **quedó
arreglado en WEB-4.5**: la causa era del test —buscaba la consulta por `numhistoria` sin acotar al
`reg_medico` de la prueba y terminaba leyendo las filas que siembra `FakeClinicalDataSeeder`, que reparte
80 consultas al azar entre las historias 1001–1050—, no de la regla del correlativo; se verificó con **30
corridas seguidas en verde**. Ver la trampa 27.

⚠️ **Las filas largas de la tabla de "Estructura" (las de `app/Http/Controllers/Clinica/`, `app/Actions/` y
`app/Clinica/`) no entran en la lectura de 2000 caracteres por línea de algunas herramientas**: lo que
**WEB-4.4 (2026-10-10)** agregó a esas tres filas —y que hay que leer en el archivo, no acá— es:
`ConsultaAntecedentes` (**WEB-4.4: los antecedentes del paciente** en `?seccion=antecedentes`, con
`POST /clinica/consulta/{consulta}/antecedentes`, `…/antecedentes/crear` y el JSON `…/antecedentes/buscar`;
**los antecedentes son del paciente y no de la consulta**, así que su Action vive en `Actions\Pacientes`),
`Pacientes/{GuardarAntecedentes,AgregarAntecedenteAlCatalogo,ResultadoDeAntecedentes}`
(`GuardarAntecedentes` escribe **sólo lo que cambió** de `antece_paciente` —`detalles` y `tipo2` de una fila
que ya estaba, `created` de la nueva y `deleted` de la baja— con el last-write-wins por columna y su rastro
en `sync_changes` con `source = web`; `AgregarAntecedenteAlCatalogo` es el alta del catálogo con el
correlativo de cuatro dígitos; **no cambia el comportamiento del sync**) y
`App\Clinica\Consulta\AntecedentesDelPaciente` (el mapa del dato: los tres catálogos, los "vacíos" del
legado, la casilla de Alerta `tipo2 = 'A'` y el orden de los grupos, que es el de las solapas del
escritorio).
Lo que **WEB-4.5 (2026-10-10)** agregó a esas tres filas es: `ConsultaDiagnosticos` (**WEB-4.5: los
diagnósticos de la consulta** en `?seccion=diagnostico`, con `POST /clinica/consulta/{consulta}/diagnosticos`
y `…/diagnosticos/crear`; **la fila sí es de la consulta** —`diagnostico_paciente` se llavea por
`nrohistoria` + `nroconsulta` + `codediagnostico`—, así que la Action vive en `Actions\Consulta`),
`Consulta/{GuardarDiagnosticosDeLaConsulta,ResultadoDeDiagnosticos,AgregarDiagnosticoAlCatalogo,ReglasDelCatalogo}`
(`GuardarDiagnosticosDeLaConsulta` escribe **sólo lo que cambió** —el `detalle_diagnostco` de una fila que
ya estaba, `created` de la nueva con la descripción del catálogo copiada como el `INSERT` del escritorio, y
`deleted` de la baja— con el last-write-wins por columna y su rastro en `sync_changes` con `source = web`;
`AgregarDiagnosticoAlCatalogo` es el alta del catálogo con el correlativo de cuatro dígitos,
`ReglasDelCatalogo` es la regla que comparte con `AgregarMotivoAlCatalogo` —mayúsculas y correlativo— y
`ResultadoDeDiagnosticos` distingue "no había cambios" de "otro equipo editó"; **el sync no cambió**) y
`App\Clinica\Consulta\DiagnosticosDeLaConsulta` (el mapa del dato: el catálogo del médico **sin tildes** y
**un diagnóstico por nombre**, las filas de la consulta con el nombre resuelto por catálogo, los "vacíos"
del legado y que **no hay tipos ni CIE** en la fuente).
Lo que **WEB-4.6 (2026-10-10)** agregó a esas tres filas es: `ConsultaEvolucion` (**WEB-4.6: la evolución de
una consulta** en `?seccion=evolucion`, con `POST /clinica/consulta/{consulta}/evolucion`; **la evolución es
una COLUMNA de la consulta** —`consultas.evolucion`, llaveada por `numhistoria` + `nroconsulta`—, así que la
Action vive en `Actions\Consulta`), `Consulta/GuardarEvolucionDeLaConsulta` (escribe **sólo `evolucion`** con
el last-write-wins por columna y su rastro en `sync_changes` con `source = web`; **el sync no cambió y no
había regla que extraer**: el móvil no escribe esa columna y el escritorio la sube por su camino genérico) y
`App\Clinica\Consulta\{EvolucionDeLaConsulta,TextoDeLaConsulta}` (el mapa del dato —la columna, su fuente y
las **plantillas** de `formato_print` con `tipo = 'EV'`— y el helper de los "vacíos" del legado: `NULL`,
`''` y el literal `none`; ver la trampa 29).
Lo que **WEB-4.7 (2026-10-11)** agregó a esas tres filas es: `ConsultaImagenes` (**WEB-4.7: los exámenes y
las imágenes de una consulta** en `?seccion=examenes`, con `POST /clinica/consulta/{consulta}/examenes`
subir, `DELETE …/examenes/{imagen}` quitar y **`GET …/examenes/{imagen}` la descarga autorizada**; la
descarga es el **único camino al archivo**, porque vive en un disco privado), `Consulta/{GuardarImagenDeLaConsulta,QuitarImagenDeLaConsulta}`
(la primera guarda el archivo en el disco privado, crea la fila y **borra el archivo si la fila falla**; la
segunda borra la fila y **sólo** el archivo del disco privado —una fila del escritorio tiene su ruta en la
PC del consultorio y no se toca—; las dos dejan su rastro `created`/`deleted` en `sync_changes` con
`source = web`, y **el sync no cambió**) y `App\Clinica\Archivos\ArchivosClinicos` (la **política de la
subida** en una clase sin estado: disco, carpeta por médico, tipos y tope, y el nombre que arma el
servidor; la usan la web y —cuando suba imágenes— el móvil; ver las trampas 30 a 32) más
`App\Clinica\Consulta\{ExamenesDeLaConsulta,ImagenesDeLaConsulta}` (el mapa del dato: los exámenes de
`examen_paciente` con su catálogo y la observación del conjunto de `examen_obs`, las dos formas de fila de
`imagen_consulta` y la comprobación de dueño de la descarga) y sus `Vistas/{SeccionDeExamenesVista,ImagenesDeLaConsultaVista}`.
Lo que **WEB-4.8 (2026-10-11)**, el cierre de F4, agregó a esas tres filas es: `ConsultaCerrar` (**WEB-4.8:
cerrar la consulta** —`POST /clinica/consulta/{consulta}/cerrar`—, la acción primaria del shell: termina la
atención, la cita de hoy queda atendida y se vuelve a la agenda. **No crea ningún estado nuevo**: la
fuente lo confirma —el escritorio marca `cola.atendido = 1` en el `open` de `w_consulta_principal`
(`:4416-4421`) y sus botones de salida sólo cierran la ventana—; sólo se ofrece **para la consulta de
hoy** y el **404**/**403** son los del grupo de la consulta), `Consulta/CerrarConsulta` (la Action: resuelve
la cita del día, delega la marca en `AtenderCita` y devuelve qué pasó —los tres desenlaces de
`ConsultaCerrada`: la cita quedó atendida, no había cita de ese día o el escritorio la había movido—) y
`Clinica/Consulta/{CitaDeLaConsulta,CitaEncontrada}` (el mapa del vínculo —**`cola` no tiene `nroconsulta`**,
así que la cita se busca por `numhistoria` + fecha, y el segundo intento es el ancla
`paciente_sinhistoria_id`— y el value object que dice si la cita es terminable). La misma tarea puso la
regla **"cita atendida ⇒ tiene historia"** en `Actions/Agenda/{AtenderCita,CitaNoAtendible}`: al marcar
sin número la resuelve del vínculo del paciente y, si no hay ninguno, **rechaza** —la web avisa y el sync
la **descarta**—; `Vistas/ConsultaShellVista` ganó `urlTerminar()`/`avisoTerminar()` y el panel quedó entonces en
**8 de 13** (hoy, tras F5, F6 y WEB-5.7, está en **13 de 14**: sólo falta la pre-factura → F9).

Lo que **WEB-5.1 (2026-10-11)**, los récipes de la consulta, agregó a esas tres filas es: `ConsultaRecipes`
(**WEB-5.1: los récipes de una consulta** en `?seccion=recipes`, con `&recipe=nuevo` o `&recipe=2` para el
editor de uno; `POST /clinica/consulta/{consulta}/recipes` crea, `POST …/recipes/{numero}` edita **en el lugar**,
`DELETE …/recipes/{numero}` elimina el récipe entero, y los JSON `GET …/recipes/medicamentos?q=` y
`…/recipes/tratamientos?q=` buscan en el vademécum y en los tratamientos del médico; `POST …/recipes/medicamentos`
es el **alta rápida** del medicamento que falta, online-only y con JSON para no perder lo que hay en el editor),
`Consulta/{GuardarRecipe,EliminarRecipe,ResultadoDeRecipe,AgregarMedicamentoAlVademecum}` (**R3**:
`GuardarRecipe` es **la** regla de escritura del récipe —el número que sigue, la cabecera con `fe_vence = 30` y
la forma de las filas— y **la usa también `CreacionesClinicas::crearRecipe`** del móvil (con `$origen = null`: el
móvil anota su propio rastro), que ya no tiene `siguienteRecipe`; al editar compara por `codemedicina` y escribe
**sólo lo que cambió** con el last-write-wins por columna; el vademécum es **`vademecum`**, no `vademecum_m`, ver
las trampas 39 a 42) y `App\Clinica\Consulta\RecipesDeLaConsulta` (la lectura y **la definición del
agrupamiento**: `nrohistoria` + `nroconsulta` + `recipe`, con **`NULL` = récipe 1**, que usa también
`HistoriaDelPaciente`; el vademécum **sin tildes** por comercial o genérico con un medicamento por
`nombre/presentación`, los tratamientos con sus medicamentos y la comprobación de que un medicamento nuevo del
récipe es del vademécum o de un tratamiento del médico: no hay texto libre) más
`Vistas/SeccionDeRecipesVista` (lista y editor). El móvil: `recipes` baja por delta de `updated_at` (cada edición
lo cambia) y las **bajas** viajan en `eliminados` con un `deleted` por fila bajo `recipes`; el reemplazo de
`recipe_detalle` del escritorio ahora respeta también las cabeceras anotadas con `source = web`.

Lo que **WEB-5.1b (2026-10-11)**, el récipe de la pareja, agregó a esas tres filas es: `ConsultaRecipeParejaController`
(**WEB-5.1b: el récipe de la pareja** en `?seccion=recipes&recipe=pareja`, la segunda pestaña de la sección; **uno
por consulta**, así que no lleva número: `POST /clinica/consulta/{consulta}/recipe-pareja` lo crea o lo corrige **en el
lugar** y `DELETE` la misma ruta lo elimina entero; los buscadores del vademécum y de los tratamientos y el alta rápida
son los de `ConsultaRecipesController`, sin duplicar), `Consulta/{GuardarRecipeDeLaPareja,EliminarRecipeDeLaPareja,
ResultadoDeRecipeDeLaPareja,ItemsDeRecipe}` (`GuardarRecipeDeLaPareja` escribe **sólo lo que cambió** en
`recipes_pareja` —`cantidad`, `indicaciones`, `cedula`, `nombre`, filas nuevas y bajas— con el last-write-wins por
columna, `updated_at` nuevo y el rastro `source = web` (un `created` **por fila**: es lo que la protege del reemplazo
de la tabla que sube el escritorio, `sin_clave_con_altas_del_app`); `ItemsDeRecipe` es la normalización de los
medicamentos que ahora comparten `GuardarRecipe` y esta Action) y `App\Clinica\Consulta\RecipeDeLaPareja` (la
lectura: la cédula y el nombre salen de la **primera** fila, y `resolverItems` aplica la misma comprobación de
catálogo que el récipe del paciente, `RecipesDeLaConsulta::resolverItemsContra`) más `Vistas/{SeccionDeRecipeDeLaParejaVista,
PestanasDeRecipes,ComparteElEditorDeRecipes}` (siempre es el editor; el trait lleva la caja de herramientas, los topes y
las filas del editor que comparten los dos ViewModels) y el modelo `App\Models\RecipePareja`. **Sin columnas ni
tablas nuevas.** ⚠️ Esa nota decía que «el móvil no sincroniza `recipes_pareja`» y **ya no es cierto**: desde la ola
§9 de [PLAN-MOVIL.md](../PLAN-MOVIL.md) el app la **baja** (delta, volcado y `volcado_paciente`, con la columna
**`nrohistoria`**) y **la crea** (`CLINICAL_TABLES` + `CreacionesClinicas::crearRecipeDeLaPareja`, que llama a esta
misma Action con los `items` y la cédula y el nombre en el change, no en `columns`). Ver las trampas 43 a 45.

Lo que **WEB-5.2 (2026-10-11)**, imprimir el récipe, agregó: `ImpresionDeRecipeController` (**la hoja A4 horizontal que imprime el
navegador**: `GET /clinica/consulta/{consulta}/recipes/{n}/imprimir` y `…/recipe-pareja/imprimir` en el grupo `role:Root|Medico`,
`GET /clinica/agenda/{cola}/recipes` —la lista de los récipes de una cita atendida— y `…/recipes/imprimir?recipe=N|pareja|todos` con los
roles de la agenda, y `POST /clinica/recipes/opciones-de-impresion`, que guarda modo y nombre en el formato del médico y sigue a la
vista previa; **dos caminos y una sola hoja**, `vistaPrevia()`), `Recipes/GuardarOpcionesDeImpresion` (escribe las dos columnas
**nullable nuevas** de `recipe_formatos`, `modo_impresion` y `nombre_medicamento`, **sin mover `updated_at`** de una fila que ya existe y sin
crear fila hasta la primera elección) y `App\Clinica\Impresion\` (sin HTTP: `MembreteDelMedico`/`Membrete` —`recipe_formatos` +
`evolucion` bajo el **registro principal** del médico—, `RecipesParaImprimir`/`DocumentoDeRecipe`/`ItemImpreso` —el nombre comercial o
genérico—, `ProximaCitaDelRecipe` —`fecha > hoy`, de ese médico, la hora sólo en sede por hora—, `RecipesPorCita` —de la cita atendida a su
consulta, y en lote para la agenda—, `OpcionesDeRecipe` y **la regla de la hoja**: `MedidorDeTexto` + `MedidasDeLaMitad` +
`DistribuidorDeRecipe`: letra de 10, 9 u 8 pt, gana el escalón con menos hojas y el récipe sigue en otra hoja sin partir un medicamento)
más sus `Vistas/` (`HojasDeRecipeVista`, `OpcionesDeImpresionVista`, `RecipesDeLaCitaVista`). Las vistas están en
`resources/views/clinica/imprimir/` y los componentes reutilizables en `resources/views/components/impresion/`
(`<x-impresion.membrete|firma|pie>`); CSS `public/css/clinica-impresion.css` + `clinica-recipe.css`, JS
`public/js/clinica/imprimir-recipe.js` y **fuentes auto-alojadas** en `public/fonts/recipe/`. **El API del móvil no cambió**: el JSON de
`RecipeFormatoResource` conserva sus 6 claves y `RecipeFormatoController@actualizar` no toca las columnas nuevas. Además, el formulario de la cita
(`CitaController`, `FormularioDeCita`, `GuardarCitaRequest`) admite `volver_consulta` (`App\Clinica\Agenda\VueltaDeLaCita`) para que
«Hacer cita» desde los récipes vuelva a ellos, la fila de la agenda (`FilaDeCitaVista::imprimirRecipe`) ofrece «Imprimir récipe» en la cita
atendida con récipes y la historia del paciente enlaza la hoja de cada récipe al médico. Ver las trampas 46 a 50.

Lo que **WEB-5.3a (2026-10-11)**, las solicitudes de la consulta, agregó: controladores `ConsultaConstanciaController`, `ConsultaReposoController`,
`ConsultaReferenciasController` (el buscador JSON de `doctores`, referir, el motivo, quitar y el alta rápida del colega) y `ConsultaInformeController`
—cada uno con `guardar`/`eliminar`, en el grupo `role:Root|Medico` de la consulta— y `ImpresionDeDocumentosController` (**la hoja de media A4 de cada
documento**: `GET /clinica/consulta/{consulta}/documentos/imprimir?documento=constancia|reposo|referencia-<médico>|informe|todos` para el médico y, con los
roles de la agenda, `GET /clinica/agenda/{cola}/documentos` —la lista de **todo lo imprimible** de la cita atendida— y `…/documentos/imprimir`, que
**reemplazan** a `agenda.recipes*` de WEB-5.2). La sección es el ítem 11 del shell (`?seccion=solicitudes&solicitud=`; `ConsultaShellVista::SECCION_SOLICITUDES`,
**10 de 13**) con `Vistas/{SeccionDeSolicitudesVista,PestanasDeSolicitudes,ConstanciaVista,ReposoVista,ReferenciasVista,InformeVista}` y los parciales
`clinica/consulta/_solicitudes*`; `PestanasDeSolicitudes` es el único lugar donde se agrega una pestaña. **Lectura** en `App\Clinica\Consulta\`:
`QueEsUnDocumento` (**la** regla de qué es un documento y qué es una plantilla vacía del legado: la usan la sección, `HistoriaDelPaciente`, la impresión y,
en SQL, `DocumentosPorCita`), `ConstanciaDeLaConsulta`, `ReposoDeLaConsulta` (con la cuenta de las fechas: hasta = inicio + días − 1, reintegro = inicio + días,
y `fechaDeInicio`, que acepta fechas futuras), `ReferenciasDeLaConsulta`, `DoctoresDelMedico` e `InformeDeLaConsulta`. **Escritura** en `App\Actions\Consulta\`
(`GuardarConstanciaDeLaConsulta`, `GuardarReposoDeLaConsulta`/`Eliminar…`, `GuardarReferenciaDeLaConsulta`/`Eliminar…`, `AgregarDoctorAlCatalogo`,
`GuardarInformeDeLaConsulta`/`Eliminar…`, con `ResultadoDeSolicitud` y el trait `EscribeConRastro`): una fila por consulta —por médico en la referencia—,
**sólo lo que cambió**, rastro por columna en `sync_changes` (`source = web`) y last-write-wins; **sin columnas ni tablas nuevas** (los diagnósticos editados
de la constancia usan `constancia_obs.observacion`, que el escritorio no escribe). **Impresión** en `App\Clinica\Impresion\`: `DocumentoImpreso`,
`SolicitudesParaImprimir`, `DocumentosParaImprimir` (la lista y la clave común de récipes y solicitudes: `recipe-1`, `constancia`, `referencia-3`…),
`DistribuidorDeDocumento` (+ `MedidasDeLaMitad::altoDelCuerpoDeDocumento`: el texto sigue en la otra mitad y, si hace falta, en más hojas),
`PacienteImpreso` (la cabecera del paciente, extraída de `RecipesParaImprimir`), `DocumentosPorCita` (antes `RecipesPorCita`) y
`Vistas/{HojasDeDocumentoVista,HojasImpresasVista,DocumentosDeLaCitaVista}`; vistas `clinica/imprimir/{hojas,_documento-mitad,documentos-de-la-cita}`, CSS
`public/css/clinica-documento.css` (gemelo de las medidas) y JS `public/js/clinica/consulta-solicitudes.js`. **El API del móvil no cambió** (baja las cuatro
tablas por el delta de `updated_at` y las bajas por `eliminados`; un test lo fija) y **la subida del escritorio tampoco**: las cuatro tablas tienen clave
primaria y se suben por fila. Ver las trampas 53 a 55.

Lo que **WEB-5.3b (2026-10-12)**, hospitalización y dieta / recomendaciones, agregó: controladores `ConsultaHospitalizacionController` y `ConsultaDietaController` (`guardar`/`eliminar`, en el grupo `role:Root|Medico` de la consulta; `POST`/`DELETE /clinica/consulta/{consulta}/hospitalizacion` y `/dieta`), las pestañas `hospitalizacion` y `dieta` de `PestanasDeSolicitudes` (orden del escritorio) con `Vistas/{HospitalizacionVista,DietaVista}` y los parciales `clinica/consulta/_solicitudes-{hospitalizacion,dieta}`. **Lectura** en `App\Clinica\Consulta\`: `HospitalizacionDeLaConsulta` (una por consulta; sólo `hospital`, `diagprev` y `dieta`: `indiprev`/`habitacion` no se tocan) y `DietaDeLaConsulta` (clave `histroria` + `consulta`; plantillas `formato_print` `DI`); `QueEsUnDocumento::{hospitalizacion,dieta}`. **Escritura** en `App\Actions\Consulta\`: `Guardar/EliminarHospitalizacionDeLaConsulta` y `Guardar/EliminarDietaDeLaConsulta` (trait `EscribeConRastro`, `source = web`; no crean filas vacías). Modelos `Hospitalizacion` y `DietaPaciente`. **Impresión:** `SolicitudesParaImprimir` suma `hospitalizacion` y `dieta` (claves y `DocumentoImpreso::{HOSPITALIZACION,DIETA}`), `DocumentosPorCita` los cuenta en SQL y `HistoriaDelPaciente` los lista. **Sync:** `dieta_paciente` entra a `sin_clave_con_altas_del_app` (el escritorio la sube entera); `hospitalizacion` se sube por fila; el móvil no baja ninguna. CSS `.pestanas-en-fila`. Sin columnas ni tablas nuevas. Ver las trampas 72 a 74.

Lo que **WEB-5.4 (2026-10-12)**, laboratorio y estudios, agregó: controladores `ConsultaLaboratorioController` y `ConsultaEstudiosController` (`POST`/`DELETE /clinica/consulta/{consulta}/laboratorio` y `/estudios` —guardar la orden **entera** y eliminarla, en el grupo `role:Root|Medico` de la consulta— y los JSON `…/laboratorio/catalogo?q=&tipo=`, `…/laboratorio/rutinas/{tipo}` y `…/estudios/catalogo?q=`), las pestañas `laboratorio` y `estudios` de `PestanasDeSolicitudes` (al principio, como el escritorio; la sección sigue abriendo en la constancia) con `Vistas/OrdenVista` (los dos, `OrdenVista::laboratorio()`/`::estudios()`), los parciales `clinica/consulta/_orden-editor`, `_orden-fila` y `_solicitudes-{laboratorio,estudios}` y el JS `public/js/clinica/consulta-ordenes.js` (lista, buscador, rutinas y aviso de cambios sin guardar). **Lectura** en `App\Clinica\Consulta\`: `CatalogoParaPedirExamenes` (el catálogo `examenes` partido por el tipo `002`: laboratorio = todo menos `002`, estudios = sólo `002`; el buscador **sin tildes**, un examen por nombre y tipo y las **rutinas** = los tipos de examen con exámenes), `LaboratorioDeLaConsulta` (reusa `ExamenesDeLaConsulta` de WEB-4.7, que ganó `codetipo`, y le suma el orden por tipo y nombre), `EstudiosDeLaConsulta` y `QueEsUnDocumento::{laboratorio,estudios}`. **Escritura** en `App\Actions\Consulta\`: `GuardarOrdenDeLaboratorio`/`EliminarOrdenDeLaboratorio`, `GuardarSolicitudDeEstudios`/`EliminarSolicitudDeEstudios` y `ResultadoDeOrden` (trait `EscribeConRastro`): lo marcado es lo que queda, sin reescribir lo que ya estaba (`resultado`/`vinculante` quedan), un examen nuevo tiene que ser del catálogo del médico y no se crean filas vacías; rastro `created`/`deleted` por fila y `updated` por columna (`observacion`, `orden`), `source = web`. Modelos `ExamenPaciente`, `ExamenObs`, `RadiologiaPaciente` y `RadiologiaObs`. **Impresión:** `SolicitudesParaImprimir` suma `laboratorio` y `estudios` (claves y `DocumentoImpreso::{LABORATORIO,ESTUDIOS}`), `DocumentosPorCita` los cuenta en SQL, `HistoriaDelPaciente` los lista y `DistribuidorDeDocumento` ganó el **párrafo de lista** (`DocumentoImpreso::parrafoDeLista`, con `items`: se corta entre elementos y la parte que sigue repite el rótulo con «(cont.)»). `UsoDeUnItemDelCatalogo::examen` cuenta también `radiologia_paciente`. **Sync:** las cuatro tablas se suben por fila (clave primaria, tres triggers) y no necesitan `sin_clave_con_altas_del_app`; el móvil no las baja. **Sin columnas ni tablas nuevas.** Ver las trampas 75 a 77.

Lo que **WEB-5.6 (2026-10-12)**, el QR de verificación del récipe, agregó: `VerificacionPublicaController` (**la página pública** `GET /verificar/{token}` y `GET /verificar?codigo=`, en `routes/verificar.php` con el grupo de middleware `verificacion` —**fuera de `web`**: sin sesión ni cookies—, `throttle` propio por ruta, y el middleware `VerificacionPublica` con `X-Robots-Tag`, `Referrer-Policy`, `no-store` y CSP), `ConsultaRecipeVerificacionController` (`POST /clinica/consulta/{consulta}/recipes/{n}/verificacion/{anular|reactivar}` y los de `recipe-pareja`, en el grupo `role:Root|Medico`; 404 si el récipe nunca se imprimió), **Actions** `Recipes\{ObtenerVerificacionDeRecipe,AnularVerificacionDeRecipe,ReactivarVerificacionDeRecipe}` (la primera es idempotente y segura ante dos impresiones a la vez), el modelo `RecipeVerificacion` (tabla **nueva** `recipe_verificaciones`, fuera de `config/sync_legado.php`) y `App\Clinica\Verificacion\` (`CredencialesDeVerificacion` —token y código—, `UrlDeVerificacion` —`VERIFICACION_URL`/`APP_URL`—, `GeneradorDeQr` —el SVG—, `LectorDeVerificacion` —**la única que decide qué es público**: nada del paciente ni las indicaciones—, `VerificacionesDeLaConsulta`, `RecetaVerificada`, `EstadoDeLaVerificacion` y `Vistas/VerificacionPublicaVista`) más, en `App\Clinica\Impresion\`, `QrDelRecipe` y `QrParaLosRecipes` (le pone el QR a los récipes que imprimen el médico y la secretaría; `DocumentoDeRecipe::conQr`). Vistas `resources/views/verificacion/*` (+ `public/css/verificacion.css`), `clinica/imprimir/_recipe-qr` y `clinica/consulta/_recipes-verificacion`. Dependencia nueva de Composer: `bacon/bacon-qr-code ^2.0` (con `dasprid/enum`). **El API del móvil no cambió.** Ver las trampas 78 a 80.

Lo que **WEB-5.5b (2026-10-12)**, los formatos en blanco, agregó: `FormatosEnBlancoController` (`GET /clinica/formato/en-blanco/{formato}`, `clinica.formato.en-blanco`, dentro del grupo `role:Root|Medico` de «Formato de impresión»), `App\Clinica\Impresion\FormatosEnBlanco` (el catálogo: **nueve** formatos entonces, **once** desde que WEB-6.2 sumó los dos del eco obstétrico —la clave del formato **lleva guion**, `[a-z][a-z-]*` en la ruta: trampa 111—), `Vistas\HojaEnBlancoVista` (arma las mitades con `enBlanco`), la constante `DocumentoImpreso::TITULOS` (los títulos que comparten la hoja real y la en blanco, más `SUFIJOS`/`tituloConSufijo()` para el trimestre de los ecos), los parciales `_datos-en-blanco` y `_seccion-en-blanco`, la vista `clinica/imprimir/en-blanco`, la tarjeta `clinica/formato/_en-blanco` y `public/css/clinica-en-blanco.css`. Sólo lee: ni filas, ni `sync_changes`, ni token, ni QR; el modo del récipe sale de `?modo=` sin guardarse. Tests: `FormatosEnBlancoWebTest` y `Unit/Clinica/FormatosEnBlancoVistaTest`. Trampas 81, 82 y 111.

Lo que **la hoja de la colposcopía y la histeroscopía (2026-10-18)** agregó: un **tercer modo** en `App\Clinica\Impresion\ModoDeLaHoja`
(`MITAD_SUPERIOR`, además de `MITAD` y `COMPLETA`) y en `MedidasDeLaMitad::deLaMitadSuperior()`: **la página es un A4 vertical y
el informe usa sólo su mitad de arriba** (182 × 134,25 mm útiles; la mitad de abajo queda en blanco, como el escritorio), con la
**imagen del examen en esa misma página y debajo del texto** —Alexander, 2026-10-18: dejó de salir en una hoja A4 aparte—. El
reparto (`DistribuidorDeDocumento`) la reserva el alto del bloque de imágenes —`MedidasDeLaMitad::altoDeLasImagenes()`, 32 mm con
una y 28 con dos, contra 96/76 de la hoja completa— y las imágenes viajan con la última mitad; el parcial de la figura es
`_informe-imagen` (lo comparten la hoja del eco y el informe), la clase de la hoja la da `ModoDeLaHoja::clasesDeLaHoja()` y el CSS
vive en `.recipe-hoja-mitad-superior` + `.documento-imagenes` de `clinica-documento.css`. Tests: `ConsultaColposcopiaWebTest`
(la hoja de una sola página y el informe largo que sigue en otra media página con las imágenes al final), `ConsultaHisteroscopiaWebTest`
e `ImpresionDeHojaCompletaWebTest`; verificador de Chrome `../.agent-tools/verificar-consulta-colposcopia-web.js`.
Lo que **WEB-8.1a (2026-10-11)**, los catálogos de medicamentos y tratamientos, agregó: controladores `CatalogoController` (la entrada: redirige al primer catálogo),
`MedicamentoCatalogoController` (`/clinica/catalogos/medicamentos`: listado paginado con `?buscar=`, `?editar=`/`?nuevo=` que abren el diálogo, `POST` alta/edición, `DELETE`
quitar y el JSON `…/buscar` del editor de tratamientos) y `TratamientoCatalogoController` (`/clinica/catalogos/tratamientos`: lista, editor `nuevo`/`{id}/editar`, `POST` crear/guardar, `DELETE`),
todos en un grupo `role:Root|Medico` (la secretaría: 403) acotado al `reg_medico` del contexto. **Actions** en `App\Actions\Catalogos\`: `CrearMedicamentoEnElCatalogo` (usa
`AgregarMedicamentoAlVademecum`, WEB-5.1, que ganó un parámetro opcional), `EditarMedicamentoDelVademecum`, `QuitarMedicamentoDelCatalogo` (comprueba el uso y borra en una transacción con
candado), `GuardarTratamiento`, `QuitarTratamiento`, el trait `RastroDeTratamientos` (el `deleted` del servidor **y** el que entiende el teléfono, trampa 57) y `CamposDelMedicamento`
(los campos del ABM del escritorio y sus topes), con el trait `EscribeConRastro` de WEB-5.3a. **Lectura** en `App\Clinica\Catalogos\`: `MedicamentosDelCatalogo`, `TratamientosDelCatalogo`,
`UsoDeUnMedicamento` (qué es "en uso"), `CatalogosDisponibles` (el índice extensible: WEB-8.1b suma una línea) y `Vistas/{MedicamentosVista,TratamientosVista,EditorDeTratamientoVista}`; el editor
reutiliza los parciales y `consulta-recipes.js` del récipe (`ComparteElEditorDeRecipes` ganó `ofreceTratamientos/ofreceCrearMedicamento/ofreceOrdenar`). `RecipesDeLaConsulta` ganó
`palabrasDeBusqueda`/`medicamentoCoincide`/`resolverItemsDelMedico` y el tope opcional de `tratamientos()` (sin cambiar lo que hacía). Sync: `recipe_grupo(_detalle)` en
`sin_clave_con_altas_del_app`; el móvil no cambió. Ver las trampas 56 a 59.

Lo que **WEB-8.1b (2026-10-11)**, los catálogos clínicos, agregó: controladores `CatalogoSimpleController` (la base: listado paginado con `?buscar=`, `?editar=`/`?nuevo=`, `POST` alta/edición y `DELETE` quitar) con sus hijos `DiagnosticoCatalogoController`
(`/clinica/catalogos/diagnosticos`), `MotivoConsultaCatalogoController` (`…/motivos-consulta`) y `TipoExamenCatalogoController` (`…/tipos-examen`), más `ExamenCatalogoController` (`…/examenes`, con filtro `?tipo=`) y
`DoctorCatalogoController` (`…/medicos-referidos`), todos en el grupo `role:Root|Medico` y acotados al `reg_medico` del contexto. **Actions** en `App\Actions\Catalogos\`: `Crear{Diagnostico,Motivo,Doctor}EnElCatalogo` (envuelven las altas rápidas y
suman la comparación sin tildes y el `created`), `CrearExamenEnElCatalogo`, `CrearTipoDeExamenEnElCatalogo`, `Editar{Diagnostico,Motivo,TipoDeExamen}DelCatalogo` (sobre la base `EditarDescripcionDelCatalogo`), `EditarExamenDelCatalogo`,
`EditarDoctorDelCatalogo` (la cédula no se edita) con `CamposDelDoctor`, `Quitar{Diagnostico,Motivo,Examen,TipoDeExamen,Doctor}DelCatalogo` (sobre `QuitarDelCatalogo`: transacción con candado, no borra lo que está en uso) y `ResultadoDeEdicion`.
**Lectura** en `App\Clinica\Catalogos\`: `CatalogoSimpleDelCatalogo` + `DefinicionDeCatalogoSimple` (diagnósticos, motivos y tipos comparten lectura, ViewModel y vista), `ExamenesDelCatalogo`, `DoctoresDelCatalogo`,
`UsoDeUnItemDelCatalogo` (qué es «en uso» en cada catálogo), `PaginaUnaColeccion` y `Vistas/{CatalogoSimpleVista,ExamenesVista,DoctoresVista,ComparteElListadoDeCatalogo}`; vistas `clinica/catalogos/{simple,examenes,doctores}.blade.php`; modelos `Examen` y `TipoExamen`.
Sin columnas nuevas; las cinco tablas se suben por fila desde el escritorio y el móvil no cambió. Ver las trampas 60 a 62.

Lo que **WEB-8.2 (2026-10-12)**, el formato de impresión del médico, agregó: controladores `FormatoDeImpresionController` (`GET /clinica/formato` la pantalla y `GET /clinica/formato/vista-previa` la hoja de prueba, con `?incrustada=1` para el `<iframe>`) y `FormatoDeImpresionGuardarController` (`POST /clinica/formato/{membrete,logo,estilo,firma,restablecer}`, una por sección), todos en el grupo `role:Root|Medico` (la secretaría: 403). **Actions** en `App\Actions\Configuracion\`: `GuardarMembreteDelMedico` (escribe `evolucion` sólo lo que cambia, con el logo, y deja el rastro `source = web` y el last-write-wins cuando recibe `$origen`), `GuardarFormatoDelRecipe` (escribe `recipe_formatos`, con `restablecer()`), `ReglasDelFormato`, `LimitesDelMembrete` y `ResultadoDelMembrete`; **el API del móvil (`ConfiguracionMedicoController`, `RecipeFormatoController`) las usa** con `$origen = null` y su JSON no cambió. **Lectura/archivos** en `App\Clinica\Impresion\`: `FormatoDelMedico`/`FormatoGuardado` (las dos filas bajo el registro principal), `RecipeDeEjemplo` y `Vistas/{FormatoDeImpresionVista,HojaDePruebaVista}` (la hoja de prueba reusa `HojasDeRecipeVista`), y `App\Clinica\Archivos\ImagenesDelMembrete` (disco `public`, carpetas `logos/`, `recipe/firmas/`, `recipe/sellos/`, tipo real y borrado del anterior si nadie lo usa). Vistas `clinica/formato/*` y `clinica/imprimir/hoja-de-prueba`, JS `public/js/clinica/formato-impresion.js`. El menú ganó el grupo «Configuración» (`ArmazonVista::menu()`). Sin columnas ni tablas nuevas. Ver las trampas 65 a 68.

Lo que **WEB-8.1c (2026-10-12)**, el catálogo de motivos de cita con su monto, agregó: controlador `MotivoCitaCatalogoController` (`/clinica/catalogos/motivos-cita`: listado paginado con `?buscar=`, `?editar=`/`?nuevo=` que abren el diálogo, `POST` alta/edición, `PATCH {id}/alternar` —la marca de varias citas de WEB-2.17— y `DELETE {id}` quitar; `pantallaAnterior()` redirige con **301** la dirección vieja `/clinica/agenda/motivos`, que conserva su nombre de ruta `clinica.agenda.motivos`), en el grupo `role:Root|Medico` (la secretaría: 403) y acotado al `reg_medico` del contexto; **reemplazó** a `MotivoCitaController`, a `Agenda\Vistas\MotivosDeCitaVista` y a la vista `clinica/agenda/motivos.blade.php`. **Actions** en `App\Actions\Catalogos\`: `CrearMotivoDeCita` (razón en mayúsculas, precio, sede y marca; **código asignado por el servidor**: mayor numérico del médico + 1 con dos dígitos, con las filas del médico bloqueadas; rechaza el duplicado dentro de la misma sede), `EditarMotivoDeCita` (**la única puerta** a razón/precio/sede/marca: sólo lo que cambió, rastro por columna, no renombra por una diferencia de mayúsculas; la usan el diálogo y el interruptor), `QuitarMotivoDeCita` (sobre `QuitarDelCatalogo`: en uso = citas del médico con ese código en `cola.tipo` o `cola.motivo`, de cualquier fecha, salvo que otra fila conserve el código) y `ReglasDelMotivoDeCita` (razón, precio, código y la clave sin tildes). **Lectura** en `App\Clinica\Catalogos\`: `MotivosDeCitaDelCatalogo` (todas las filas del médico, repetidas incluidas, con `repetidos` y `con_el_mismo_codigo`; las sedes del formulario), `UsoDeUnItemDelCatalogo::motivoDeCita` y `Vistas/MotivosDeCitaVista`; vista `clinica/catalogos/motivos-cita.blade.php` (reusa `catalogos.js`). **El formulario de la cita cambió**: `MotivosDeCita::de()` ofrece **la sede elegida + las sin sede** (antes todos los del médico), `FormularioDeCitaVista` lleva el precio vigente en cada opción, la ayuda del monto («Vacío = el precio del motivo (150,00).») y «Administrar motivos» sólo para el médico (`FormularioDeCita::armar` recibe `puedeAdministrarMotivos`), con el JS en `cita-formulario.js`; el «Más» de la agenda enlaza al catálogo sólo para el médico (`AgendaVista::ofreceLosMotivos`). **Sync:** `motivo_cita` no tiene clave en el escritorio y sube **entera**: se agregó a `sin_clave_con_altas_del_app` y la clave nueva `columnas_del_app_en_el_reemplazo` de `config/sync_legado.php` (usada por `CambiosEscritorio::reemplazarTabla`) restaura `office_id`, `precio` y `admite_varias_citas_pendientes` de lo que el escritorio vuelve a mandar; el móvil no cambió (baja las ediciones por delta y las bajas por `eliminados`). `MotivoCita` ganó el cast `office_id` entero. **Sin columnas ni tablas nuevas.** Ver las trampas 69 a 71.

Lo que **WEB-8.4 (2026-10-12)**, las plantillas, agregó: controladores `PlantillasDeMensajeController` (`GET /clinica/mensajes`; `POST /clinica/mensajes/{cita|cumple}` guarda, `…/restablecer` y el JSON `…/vista-previa`, que no guarda) y `PlantillaDeTextoCatalogoController` (`/clinica/catalogos/plantillas-texto`: listado con `?buscar=` y `?filtro=` por tipo, `POST` alta/edición y `DELETE`), en el grupo `role:Root|Medico`. **Actions:** `Configuracion\GuardarPlantillasDeMensaje` (delega en `GuardarMembreteDelMedico`, la regla que también usa `ConfiguracionMedicoController`, sin cambiar su JSON), `Catalogos\{Crear,Editar,Quitar}PlantillaDeTexto` y `ReglasDeLaPlantillaDeTexto`. **Sin HTTP:** `App\Notificaciones\{PlantillaDeMensaje,PlantillasDeMensaje,AnalisisDeUnaPlantilla,LargoDeSms}` (etiquetas, vista previa y cuenta de SMS), `Clinica\Mensajes\{PlantillasDelMedico,Vistas\PlantillasDeMensajeVista}`, `Clinica\Catalogos\{PlantillasDeTextoDelCatalogo,TiposDePlantillaDeTexto,Vistas\PlantillasDeTextoVista}` y el modelo `FormatoPrint`. Vistas `clinica/mensajes/*` y `clinica/catalogos/plantillas-texto`, JS `plantillas-mensaje.js`. `EnviarRecordatorio` y `RecordatoriosDeAgenda` ganaron el respaldo de plantilla del registro (trampa 87); `config/sync_legado.php` suma `formato_print` (trampa 88). **Sin columnas ni tablas nuevas.** Ver las trampas 87 a 90.

Lo que **WEB-8.5 (2026-10-12)**, usuarios del consultorio y bitácora, agregó: controladores `UsuarioController` (`/clinica/usuarios`: `GET` lista, `POST` crea, `POST {usuario}/restablecer|bloquear|desbloquear`, `DELETE {usuario}` quita el acceso) y `BitacoraController` (`GET /clinica/bitacora`), los dos en el grupo `role:Root|Medico` (la secretaría: 403). **Reglas** en `App\Services\CuentaService` (`crearSecretaria`, `secretariasDe`, `esSecretariaDe`, `secretariaCompartida`, `restablecerClaveDeSecretaria`, `bloquearSecretaria`, `desbloquearSecretaria`, `quitarAccesoDeSecretaria`: sólo cuentas solo-secretaría vinculadas por `medico_registros.user_id`; el panel `/admin/cuentas` no cambió). **Lectura** en `App\Clinica\Bitacora\`: `CambiosDelConsultorio` (grupos paginados en el servidor sobre `sync_changes`, detalle, paciente y usuarios), `FiltroDeBitacora`, `DiccionarioDeLaBitacora` (etiquetas de tablas y columnas, valores visibles, cómo llegar al paciente) y `Vistas/BitacoraVista`; `App\Clinica\Usuarios\Vistas\UsuariosVista`. Vistas `clinica/usuarios/index` y `clinica/bitacora/index`, JS `usuarios.js`. **Rastro con usuario:** migración `2026_10_12_200000` (`sync_changes.user_id` nullable + índices `sync_changes_bitacora_idx` y `sync_changes_bitacora_usuario_idx`); el modelo `SyncChange` (`creating`) llama a `App\Sync\UsuarioDelRastro` cuando `source` es `web` o `api`, así que **toda Action que deje su rastro por el modelo lleva el usuario** sin tocarla (no hay `INSERT` directo a `sync_changes`: `RastroConUsuarioTest` lo vigila); móvil, escritorio, migración y consola quedan en `NULL` y el JSON del sync no cambió. El menú «Configuración» ganó «Usuarios» y «Bitácora». Ver las trampas 91 a 93.

Lo que **WEB-6.1 (2026-10-13)**, el **estudio ecográfico** —el primero de F6—, agregó a esas tres filas es: `ConsultaEstudio`
(**el ítem 5 del panel**: la sección `?seccion=estudio&tipo=` y su escritura por `POST /clinica/consulta/{consulta}/estudio/{tipo}`,
en el grupo `role:Root|Medico`; el `{tipo}` deja lugar a los otros seis estudios del ítem —WEB-6.2 a WEB-6.4— sin ruta nueva),
`Consulta/{GuardarEcoGinecologico}` (la Action del estudio: **una fila por consulta**, con la clave del DataWindow
`numhistoria` + `nroconsulta`, el rastro por columna en `sync_changes` con `source = web` y el last-write-wins del sync, con el
trait `EscribeConRastro`; la fecha del estudio se completa con hoy si viene vacía y **no** se crea una fila sin datos:
`QueEsUnDocumento::ecografia`) y `App\Clinica\Consulta\` (`EcoGinecologico`, **el mapa del dato** del eco pélvico —35 columnas
en nueve bloques, con los códigos de los desplegables del DataWindow, los rótulos del escritorio y las unidades del informe— y
su lectura, `EstudioEcografico` —el objeto de valores, con los vacíos del legado—, `FurDelControlPrenatal` —la FUR que el
escritorio copia del control prenatal al entrar a la pestaña— y `Vistas/{SeccionDeEstudioVista,TiposDeEstudio,EcoGinecologicoVista}`,
más los parciales `clinica/consulta/_estudio*` y el bloque del aviso de cambios sin guardar en `consulta.js`). El **informe** sale
en media hoja por el motor de documentos (`DocumentoImpreso::ECO_PELVICO` + su `SolicitudesParaImprimir::ecoPelvico`, los
párrafos armados del mapa del dato): lo imprime el médico desde su sección y la secretaría desde la cita atendida, lo lista la
historia del paciente y **sólo se ofrece si el estudio tiene datos** (o el informe en texto libre del escritorio, que se muestra y
todavía no se edita). El **panel pasa de 10 a 11 de 13** ítems disponibles. Tests: `ConsultaEcoWebTest` (14),
`ImpresionDeEcoPelvicoWebTest` (8) y `Unit/Clinica/EcoGinecologicoTest` (8). Verificación en Chrome:
`../.agent-tools/verificar-consulta-eco-web.js` con `../.agent-tools/consulta-eco-preparar.php`. Trampas 94 a 98 (Blade 8 sin
`@selected`, la codificación de PowerShell, las trece aserciones del conteo del panel, la fecha del estudio y la FUR del
prenatal).

Lo que **WEB-6.6 (2026-10-13, en curso)**, el **control prenatal**, agregó a esas tres filas: `ConsultaEmbarazo`
(**el ítem 6 del panel**: la sección `?seccion=embarazo&pestana=`, con `POST …/embarazo/prenatal` (la gesta),
`…/embarazo/laboratorio` y `…/embarazo/controles`; el **dato es del embarazo y no de la consulta** —las tres
tablas se llavean por `historia`/`gesta_clave`/`fecha`—, así que el controlador resuelve la consulta del
contexto y usa su `numhistoria`), `Actions/Embarazo/{GuardarGestaDelEmbarazo,GuardarLaboratorioDelEmbarazo,
GuardarControlDelEmbarazo}` (las tres con `EscribeConRastro`: sólo lo que cambió, rastro por columna en
`sync_changes` con `source = web` y last-write-wins; una gesta necesita **FUR** y un laboratorio o un control
necesitan **fecha**, que son parte de su clave) y `App\Clinica\Embarazo\` (`EdadGestacional` —**la regla de la
edad gestacional y la FPP**, copiada verbatim del escritorio: semanas = `int(días/7)`, días = `mod(días,7)`, y
la FPP **conserva el número de cada pantalla**: 281 la pestaña viva del control prenatal y 283 los reportes y
los ecos—, `Gesta`/`GestasDeLaHistoria` —la gesta y su lectura, con `gesta_clave` **nullable**: una fila sin
número es la gesta 1—, `Vistas/{PestanasDelEmbarazo,SeccionDeEmbarazoVista,PrenatalVista,FechasDelEmbarazoVista}`
—`FechasDelEmbarazoVista` es **un solo** ViewModel para *Laboratorio* y *Controles*, que son la misma forma—,
los modelos `PreNatalObservacion`/`PrenaExamenB`/`PreNatalDesarrolloFino` —cada uno con su **mapa de campos** en
`campos()`—, el motor de la **hoja del informe** (`DocumentoImpreso::CONTROL_DE_EMBARAZO` +
`SolicitudesParaImprimir::informeDelEmbarazo`, con `&gesta=` en la ruta de impresión para elegir la gesta), los
parciales `clinica/consulta/_embarazo*` y el aviso sin guardar genérico (`avisoDeFormulario`) que `consulta.js`
ata por `data-consulta-fechas`). El panel pasa de 11 a **12 de 13**. Las **cuatro pestañas** del escritorio están
(*Prenatal*, *Laboratorio*, *Controles* y *Reporte*). ⚠️ **Tres tablas que no son las que parecen** (las tres de
la solapa vieja y oculta, sin datos en el dump): `prena_exames_b` (no `pre_natal_examenes`),
`pre_natal_desarrollo_fino` (no `pre_natal_desarrollo`) y el DataWindow `d_pre_natal_observa_2.srd` (no el
huérfano sin `_2`): ver la **trampa 99**. Los cuatro reactivos del laboratorio (`hiv`, `vdrl`, `toxo_igm`,
`toxo_igg`) se guardan con el **código de una letra** del escritorio y no con su rótulo, y los números
declaran el `char(5)` de su columna: guardar el texto desbordaba el `VARCHAR(1)` en el MySQL de producción
(«1406 Data too long for column 'hiv'») sin que SQLite lo delatara —**trampa 140**—. Verificación en Chrome:
`../.agent-tools/verificar-consulta-prenatal-web.js`.

Lo que **WEB-6.2 (2026-10-13)**, el **eco obstétrico** —el segundo de F6—, agregó: los **dos tipos nuevos de la
sección de estudio** (`TiposDeEstudio::{OBSTETRICO,TERCERO}`; la clave va **sin guion** para no tocar el
`where('tipo','[a-z]+')` de la ruta), `Consulta/{GuardarEcoObstetricoBase,GuardarEcoObstetrico,GuardarEcoObstetricoTercero}`
(la regla de guardado **una sola vez** en la base abstracta —una fila por consulta, sólo lo que cambió, rastro
`source = web`, y un eco sin datos no es documento— con dos subclases finas que dicen sólo su tabla, su modelo, su
mapa y su lectura), `App\Clinica\Consulta\{EcoObstetrico,EcoObstetricoTercero,MapaDelEcoObstetrico}` (las dos
lecturas —clave `historia` + `consulta`, una fila por consulta— y sus **mapas del dato**: 38 columnas en seis
bloques el del 1.er trimestre y 55 el del 2.º/3.er, sacados del DataWindow **vivo** de cada uno —trampa 102—, con
los códigos de sus desplegables), `Vistas/{EcoObstetricoVista,EcoObstetricoTerceroVista}` (el segundo **hereda**
del primero: mismo formulario por bloques, otro mapa y otro título) más `Vistas/OpcionesDelDesplegable` (la
opción **vacía** adelante y el código desconocido del escritorio al final: la regla que el eco pélvico ya tenía y
a los dos obstétricos les faltaba —trampa 112—), los **parciales** `_estudio-obstetrico` y
`_estudio-tercero` (el contenedor arma el nombre del parcial con la clave del tipo: **un tipo nuevo necesita el
suyo**, trampa 103) y el **motor de las dos hojas** (`DocumentoImpreso::{ECO_OBSTETRICO,ECO_OBSTETRICO_TERCERO}` +
`SolicitudesParaImprimir::hojaDelEcoObstetrico`, parametrizada por mapa y clave: el título con su trimestre sale de
`DocumentoImpreso::SUFIJOS`/`tituloConSufijo()`, que también usa la hoja en blanco). El **formato en blanco** de los
dos ecos entra al catálogo de WEB-5.5b (`FormatosEnBlanco`, entradas **décima y undécima**: la ficha del embarazo
—Gesta/FUR/EG, y FPP en el del 3.º— como datos cortos y **los bloques de su informe** como secciones de renglones);
el **eco pélvico** sigue sin el suyo. ⚠️ **El 2.º y el 3.er trimestre son la misma tabla y la misma pantalla** en el
escritorio (cargar el 3.º pisa el 2.º) y **no hay fetos múltiples**: el selector de gemelos no tiene respaldo (el
`retrieve` de la ventana pasa tres argumentos a un DataWindow de dos, y el dump lo confirma). Verificación en Chrome:
`../.agent-tools/verificar-consulta-estudio-web.js` con `../.agent-tools/consulta-estudio-preparar.php` (los tres
tipos de la sección). Trampas 103, 111 y 112.

Lo que **WEB-6.5 (2026-10-13)**, las **solapas del examen** —el ítem 4 del panel—, agregó: el examen pasó a ser
un **contenedor de solapas** (`?seccion=evaluacion&pestana=`: `Vistas/PestanasDelExamen` —el catálogo, donde se agrega una
solapa— y `Vistas/SeccionDeEvaluacionVista`, con un parcial `_evaluacion-<clave>` por solapa; **la evaluación física de
WEB-4.3 pasó a ser la solapa `fisico`**, con su contenido en `_evaluacion-fisico`, sin cambiar su comportamiento) y su
primera solapa nueva: la **colposcopía** (la `tabpage_31` del escritorio). Lo que trae: `App\Clinica\Consulta\ColposcopiaDeLaConsulta`
(la lectura: el texto canónico vive en **`consultas.observaciones`** —es lo que imprime el informe— con la copia
`examen_fisico_nuevo.colposcopia` como respaldo, más sus plantillas `formato_print` tipo `CO`), `Vistas/ColposcopiaVista` (la
solapa: el campo, las plantillas, el aviso de cambios sin guardar, la pastilla y el botón de imprimir),
`Actions/Consulta/GuardarColposcopiaDeLaConsulta` (escribe **las dos** columnas del texto, **sólo lo que cambió**, con una fila
de rastro por columna **en su tabla** —`consultas` y `examen_fisico_nuevo`—, `source = web` y el last-write-wins del sync: la
misma convención de `GuardarEvaluacionFisica`), el parcial `_evaluacion-colposcopia` y el JS genérico de plantillas de texto
(`[data-consulta-plantillas]` + `[data-plantilla-texto]` + `[data-plantilla-destino]` en `consulta.js`, con el aviso derivado
del id del formulario). Y `App\Clinica\Consulta\PlantillasDelTexto`: la lista de plantillas de una sección por `tipo`, extraída
de `EvolucionDeLaConsulta` (WEB-4.6) para que la comparta la colposcopía. La **hoja** entra al motor de documentos
(`DocumentoImpreso::COLPOSCOPIA`, «INFORME DE COLPOSCOPÍA»; `QueEsUnDocumento::colposcopia` = tiene texto),
`SolicitudesParaImprimir` la ofrece y la arma (su cuerpo son los párrafos del texto), `DocumentosParaImprimir` le pone el icono
y `HistoriaDelPaciente`/`DocumentosPorCita` la listan. Y las **imágenes del examen** (el segundo paso de la tarea):
`App\Clinica\Consulta\TagDeImagen` (el vocabulario del legado de `imagen_consulta.observacion`: `'@'` colposcopía —con el
alias `'colpo'` que escribe su botón—, `'H'`, `'p'`, `'m'`, `'a'`, `'o1'`, `'o3'`), `ImagenesDeLaConsulta::deUnExamen()`
(las filas etiquetadas para una solapa) y `GuardarImagenDeLaConsulta` con su tercer parámetro renombrado a `$observacion`
(la columna es la etiqueta en las solapas y la descripción en la sección genérica: el controlador decide y **rechaza una
descripción que sea una etiqueta**), más `ImagenesDeLaConsultaVista` (su `$examen` opcional dice la etiqueta y si se pide
descripción), el parcial compartido `_imagenes` (lo usan la sección de exámenes y la solapa; el `examen` viaja en un campo
oculto) y el JS de la subida, que ahora recorre `[data-consulta-archivo]` y lee el cartel de `data-aviso-id`. ⚠️ La columna
`observacion` tiene **dos dueños** (trampa 116) y su significado viejo no se reescribe: las filas del escritorio quedan como
están. Y las **cuatro solapas de texto** están: la regla se escribe **una vez** en `App\Clinica\Consulta\TextosDelExamen`
(el mapa del dato: la columna de `consultas` de cada una, su `tipo` de plantillas, su hoja y su etiqueta de imágenes —la
**colposcopía** `observaciones` + la copia `examen_fisico_nuevo.colposcopia`, **citología** `faringe` `CI`, **histeroscopía**
`cuello` sin plantillas y **resultado de exámenes** `oido` `RE`—), `TextoDelExamen` (la lectura, con la copia donde existe),
`Actions\Consulta\GuardarTextoDelExamen` (la escritura: sólo lo que cambió, rastro por columna en su tabla, `source = web` y
last-write-wins), `Vistas\TextoDelExamenVista` (el ViewModel de las cuatro, con la clave como `name` del campo) y **un solo**
parcial `_evaluacion-texto`, más **una** ruta `POST /consulta/{consulta}/examen/{solapa}` acotada al catálogo
(`ConsultaTextoDelExamenController`); `SeccionDeEvaluacionVista::parcial()` manda las cuatro solapas de texto a ese parcial.
El **«INFORME DE HISTEROSCOPÍA»** también entra al motor de documentos (`DocumentoImpreso::HISTEROSCOPIA`, el título de
`d_histeroscopia.srd:27`, cuyo cuerpo es el texto de `cuello`; `SolicitudesParaImprimir` arma las dos hojas de texto con el
mismo método). Tests: `ConsultaColposcopiaWebTest` (28). Verificación en Chrome:
`../.agent-tools/verificar-consulta-colposcopia-web.js` con `../.agent-tools/consulta-colposcopia-preparar.php` (recorre las
cuatro solapas: cada una con sus plantillas, su guardado y su columna, la hoja de histeroscopía y las tres anchuras) y el de
WEB-4.7, `verificar-consulta-examenes-web.js`, que comparte el parcial `_imagenes` y se actualizó en sus tres aserciones del
panel, que seguían esperando «8 de 13». Trampas 114 a 120. **WEB-6.5 queda cerrado.**

**Encabezado y menú (2026-10-12):** el `<x-ui.page-head>` lleva **sólo título, subtítulo y botones**; el buscador y los filtros van en `<x-ui.barra-herramientas>` (slot por defecto = buscador, slot `filtros` = segmentados/selects), debajo del encabezado. Los catálogos ya no tienen índice de pestañas: son **submenús del grupo plegable «Catálogos»** de la barra lateral (`ArmazonVista::menu()` devuelve el ítem con `hijos`; `CatalogosDisponibles::submenu()` es la fuente única; `<details class="nav-grupo">` en `clinica/layout`). Ver las trampas 63 y 64.

**El inicio del médico tiene su acción primaria en el encabezado (decisión del usuario, 2026-10-19):** `InicioVista::urlNuevaCita()` —**«Nueva cita»**, `/clinica/agenda/nueva` con la **fecha del inicio y la sede del contexto** ya puestas—, **siempre**, con la lista llena o vacía. No crea la cita: abre el formulario de la agenda, así que la jornada, el cupo y el turno siguen pasando por sus reglas. *(Antes era «Agregar paciente», O-34 del 2026-10-18; el alta del que llega sin cita sigue en `/clinica/pacientes/nuevo` y en la ficha con «¿Atender ahora?», O-05.)* La tarjeta del vacío no repite el botón (sólo ofrece la agenda) y el tour del inicio usa `[data-tour=inicio-nueva-cita]`. Tests: `InicioWebTest` y `Unit/Clinica/InicioVistaTest`; verificador `../.agent-tools/verificar-inicio-medico-web.js`.

Variables del entorno que se leen por `config/services.php` y que **la instancia local no tiene** (los
tests que las necesitan fallan sin ellas, y `php artisan route:list` también): las de **WhatsApp**
(`WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_VERIFY_TOKEN` y, desde el 2026-10-18,
**`WHATSAPP_APP_SECRET`** —sin él el POST del webhook responde **503** y no acepta nada: es la firma de
Meta—, están en `.env.example` con valor vacío, pero no en el `.env`) y, desde el portal público de reservas
(2026-10-19), **`WHATSAPP_TEMPLATE_CODIGO`** (la plantilla aprobada para el código de 6 dígitos; sin ella el
portal manda el código por SMS y, en local, lo simula). También están las del **SMS por
Twilio** (`TWILIO_ACCOUNT_SID`, `TWILIO_AUTH_TOKEN`, `TWILIO_FROM`). El correo usa el **SMTP del servidor**
(`MAIL_*`). Y **`SYNC_API_KEY`** (clave de sync del escritorio) tampoco está en el ejemplo: `config/app.php`
la lee con un default de transición (ver trampas conocidas).

**El chatbot de WhatsApp (2026-10-18).** El agente virtual es el proyecto de otro desarrollador; lo que
se hizo acá, con él ya informado, fue el **mínimo antes de producción** de la auditoría
([Docs/tecnico/auditoria-chatbot-whatsapp.md](../Docs/tecnico/auditoria-chatbot-whatsapp.md), §6): la
firma `X-Hub-Signature-256` (middleware `whatsapp.firma`, alias en `Kernel.php`), `throttle:120,1`, la
deduplicación por `messageId` con 500 ante fallo (`WhatsAppWebhookController`), TLS verificado en
`GeminiService`, las credenciales de `WhatsAppService` casteadas a `string` y **la reserva por
`CrearCita`** con el lugar resuelto por `ArmadorDeAgenda` (`PacienteAtencionContextService::
primerLugarDisponible`). `WhatsAppAIService` y el job `ProcessWhatsappMessage` eran código muerto y se
borraron. Tests: `WhatsAppWebhookTest` (17) y `ChatbotReservaTest` (5). **No se le cambió el producto**
(los atajos, el prompt, el registro y los mensajes siguen igual), y **sigue sin verificarse la identidad**
de la paciente: no exponer el webhook en producción sin resolver eso. Trampas 130 a 132.
**Puesta en el servidor:** `php artisan whatsapp:diagnostico` (lee la config y dice qué falta),
`--firmar` (POST firmado al `APP_URL` con un evento de status: valida firma/throttle sin contestarle a
nadie; `--url=` apunta a un `serve` local) y `--enviar-a=<número>` (texto real por la Graph API). Sin
`WHATSAPP_APP_SECRET` el POST responde **503** y, si el deploy usa `config:cache`, la variable nueva no se
ve hasta `config:clear`/`config:cache`. Procedimiento completo en
`../Docs/tecnico/auditoria-chatbot-whatsapp.md` §11-§12. **Lo que ofrece a la paciente:** hasta **tres
días con lugar** (comprobados con `primerLugarDisponible`, los mismos que acepta la reserva) y **sin decir
cupos**; el texto lo arma `App\Support\DisponibilidadEnTexto` y la elección por número/día la interpreta
`PacienteAtencionContextService::interpretarDiaElegido`. Tests: `ChatbotDisponibilidadTest`.

**El triaje con Jev AI (2026-10-18).** `php artisan jev:triaje` mide si Jev acierta la **intención** y la
**urgencia** de un mensaje de WhatsApp antes de decidir si se integra (ROADMAP §10.3). Cliente:
`App\Services\JevService` (contrato `POST /v1/systemone`: `state` + `questions` de tipo `choice`/`score`/`noul`).
Corpus **sintético** de 28 mensajes en `database/datos/triaje-whatsapp.json`; la clave va en el `.env` como
**`JEV_API_KEY`** (nunca en el código) y hay `JEV_BASE_URL`/`JEV_MODEL` para el Jev oficial o DigitalOcean.
Tests: `TriajeJevTest` (6, con `Http::fake()`). Detalle: `../Docs/tecnico/jev-triaje.md`. **Casos de uso
con ejemplos verificados** (choice, score y noul, con request/respuesta y costos): `../Docs/tecnico/jev-casos-de-uso.md`.

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
| `routes/web.php`, `routes/web/{admin,medico,customer,cuentas,clinica}.php` | Panel web Livewire + login por sesión, y la **web clínica** (`clinica.php`: contexto, pacientes —la ficha, el **alta y la edición** de WEB-3.2 en `/clinica/pacientes/nuevo` y `/clinica/pacientes/{paciente}/editar`, **asignar el número de historia** de WEB-3.3 en `POST /clinica/pacientes/{paciente}/historia` con el filtro **`?historia=sin-historia`** del listado, **la historia del paciente** de WEB-3.5 en `GET /clinica/pacientes/{paciente}/historia` y `/historia/imprimir`, el buscador JSON del alta y las **citas del paciente** de WEB-2.15—, agenda —con el recordatorio por cita y las acciones del día de WEB-2.6/2.7, los días no laborables de WEB-2.8a, los **motivos de cita** de WEB-2.17 —desde WEB-8.1c en `/clinica/catalogos/motivos-cita`; `/clinica/agenda/motivos` redirige— y **"Pasar a Cita Formal"** de WEB-2.11—, **sedes** de WEB-2.8b.1 y **consultorios** de WEB-2.8b.2, con sus **bloques de horario** de WEB-2.8b.3, y **cumpleaños** de WEB-2.9 en `/clinica/cumpleanos`). |
| `routes/web/reservas.php` | **Portal público de reservas** (`/reservar`, `/reservar/{medico}`, `/…/codigo` y `/…/confirmar`), sin sesión de paciente: catálogo, horario, código de 6 dígitos y comprobante. |
| `app/Http/Controllers/Api/` | Controladores de la API (login, refresh, sync, upload, webhook WhatsApp). Incluye `AppAgendaMedicaController` y los `" - copia.php"`, que están muertos (ver trampas). |
| `app/Http/Controllers/Auth/` | Login web **un solo formulario** (`CustomLoginController`) y la pregunta de área (`ElegirPanelController`), más el cambio de contraseña. |
| `app/Reservas/` | **Portal público de reservas** (ROADMAP §10.5): `CatalogoDeMedicos` (solo médicos con agenda), `DisponibilidadPublica` (próximos días con un horario, sobre `primerLugarDisponible`), `CodigoDeVerificacion` (WhatsApp con plantilla / SMS / simulado en local), `SolicitarCodigoDeReserva`, `ConfirmarReservaPublica` y `ReservaPendiente` (hash del código en la sesión). La cita sale por `AgendamientoDeCitas` **sin numerar historia** y con `estado = 2`. |
| `app/Http/Controllers/Clinica/` | Web clínica (`/clinica`), **controladores delgados por responsabilidad** (ver `../Docs/Web/guia-arquitectura-web.md`): `Shell` e `Contexto` (inicio y elegir consultorio), `Paciente` (listado, ficha, **alta y edición de la ficha** (WEB-3.2: `/clinica/pacientes/nuevo` y `/clinica/pacientes/{paciente}/editar`), **asignar el número de historia** (WEB-3.3: `POST /clinica/pacientes/{paciente}/historia`, con la Action `AsignarHistoria` y el **404** del paciente ajeno; el listado acepta `?historia=todos\|sin-historia\|con-historia`) y el buscador JSON del alta de cita), `CitasDelPaciente` (WEB-2.15: las citas de **un paciente** con modificar/eliminar y el cambio de cédula, `/clinica/pacientes/{paciente}/citas`), `Agenda` (**lectura**: día/semana/mes e imprimir), `Cita` (alta, edición y baja de una cita, con `GuardarCitaRequest`), `Mostrador` (confirmar, atender, cobrar, reordenar y **"Pasar a cita formal"** de WEB-2.11, `POST /clinica/agenda/{cola}/pasar-a-cita-formal`; **desde WEB-4.9 "atender" es sólo del médico**: la secretaría conserva confirmar, cobrar, agendar, mover y reordenar, y el `POST …/atender` le responde **403** sin marcar la cita), `Recordatorio` (el recordatorio por cita, el envío del día y su ventana), `NoLaborable` (WEB-2.8a: días no laborables), `Sede` (WEB-2.8b.1: `/clinica/sedes`), `Consultorio` (WEB-2.8b.2: modalidad y duración, `/clinica/consultorios`), `Horario` (WEB-2.8b.3: bloques de día, hora y cupo — el solape con **otro** consultorio del mismo médico avisa y guarda; el del **mismo** consultorio se rechaza), `MotivoCitaCatalogo` (WEB-8.1c, antes `MotivoCita` de WEB-2.17: el catálogo de motivos de cita del médico con su precio, sede y la marca `admite_varias_citas_pendientes`, en `/clinica/catalogos/motivos-cita`; sólo médico) y `Cumpleanos` (WEB-2.9: `/clinica/cumpleanos` —los que cumplen años hoy, el envío a mano y la configuración del automático—) y `Representante` (WEB-3.4: **el representante del paciente** —el adulto que responde por él y el que presta el teléfono para el recordatorio—, con `POST /clinica/pacientes/{paciente}/representante` como **upsert por `numhistoria`** y el bloque de la ficha que arma `bloque()`), e `HistoriaDelPaciente` (WEB-3.5: **la historia del paciente** —`GET /clinica/pacientes/{paciente}/historia`, de sólo lectura, y su `/historia/imprimir`—, que lee con `HistoriaDelPaciente` y arma los dos ViewModels de `Clinica/Historia/Vistas/`), y `Consulta` (**F4/WEB-4.1: la consulta** —`GET /clinica/consulta/{consulta}`—, que lee con `App\Clinica\Consulta\ConsultasDelContexto` (de quién es la consulta y cuál es su paciente, por `numhistoria` del pivote; una consulta de otro consultorio da 404) y arma `ConsultaShellVista`, el **shell con el panel de los 13 ítems del legado** y su estado ("Próximamente" con la tarea que lo trae); vive en un **grupo de rutas propio con `role:Root|Medico`** porque la consulta es sólo del médico (PLAN-WEB.md §3.14) y la secretaría recibe 403), `ConsultaMotivos` (**WEB-4.2: los motivos de una consulta** —`?seccion=motivos` en el shell—: agregar uno del catálogo, **crear el que falta online** y quitarlo, con el buscador en vivo en `GET /clinica/consulta/{consulta}/motivos/buscar`; la regla del catálogo y de la fila son Actions) y `ConsultaEnfermedad` (**WEB-4.2: la enfermedad actual** —`?seccion=enfermedad`—, editable y con guardado explícito por `POST /clinica/consulta/{consulta}/enfermedad`) y `ConsultaEvaluacion` (**WEB-4.3: la evaluación física** —`?seccion=evaluacion`—, el **examen general** y el **ginecológico** con guardado explícito por `POST /clinica/consulta/{consulta}/evaluacion`; sus campos viven en **dos** tablas, `consultas` y `examen_fisico_nuevo`). Las **secciones de la consulta no son pantallas**: se abren dentro del shell con `?seccion=` (el panel del legado y su área de trabajo), y su contenido lo lee `App\Clinica\Consulta\MotivosDeLaConsulta` (los motivos) y `App\Clinica\Consulta\ExamenFisicoDeLaConsulta` (la evaluación física, que además es el **mapa de campos** de esa sección). Ninguno tiene SQL ni arma textos: leen con una consulta, arman un ViewModel y devuelven la vista. Los `reg_medico` del médico los resuelve el trait `Concerns/ResuelveLosRegistrosDelMedico` (lo usan `Mostrador` y `Paciente`): el correlativo del número de historia tiene que dar igual en la web y en el sync. Y `ConsultaDiagnosticos` (**WEB-4.5: los diagnósticos de una consulta** —`?seccion=diagnostico`—: guardar el detalle editado junto con lo tildado y lo quitado por `POST /clinica/consulta/{consulta}/diagnosticos`, y **crear el que falta** en el catálogo por `…/diagnosticos/crear`; la regla es una Action de `Actions\Consulta` porque la fila **cuelga de la consulta**), y `ConsultaImagenes` (**WEB-4.7: los exámenes y las imágenes de una consulta** —`?seccion=examenes`—: **sube una imagen** a un disco privado por `POST /clinica/consulta/{consulta}/examenes`, la **quita** por `DELETE …/examenes/{imagen}` y la **sirve** por `GET …/examenes/{imagen}`, que es el **único camino al archivo**; el disco es privado (`storage/app/clinica`, fuera de `public/`), el nombre del archivo lo pone el servidor y la autorización es el `reg_medico` del contexto **y** la consulta —el médico ajeno recibe 404 y la secretaría 403—; la política de lo que se acepta es `App\Clinica\Archivos\ArchivosClinicos`). |
| `app/Actions/` | **Dominio compartido entre la web y el sync** (R3 de `PLAN-WEB.md`): `Agenda/{CrearCita,EditarCita,EliminarCita,ConfirmarCita,AtenderCita,CobrarCita,ReordenarCola}`, `Pacientes/{CrearPaciente,GuardarPaciente,IdentidadDePaciente,CambiarCedula}` —`CrearPaciente` es la regla del **alta** (enlaza por cédula, completa solo los campos vacíos y no crea historia) y la siguen usando el sync del móvil y el alta de la cita; **`GuardarPaciente` (WEB-3.2)** es la del **alta y la edición** de la ficha y **delega el alta en `CrearPaciente`**, y **`IdentidadDePaciente`** es el único lugar donde vive "una cédula que ya es de otro paciente se rechaza" (la usan las tres, con el mismo mensaje que el cambio de cédula de WEB-2.15)—, `Pacientes/AsignarHistoria` (WEB-2.11: **el número de historia** —el correlativo del médico, con reintento e idempotencia— y el enlace de las citas que estaban "sin historia"; la usa la web con "Pasar a cita formal" **y** el sync del móvil, que antes la tenía adentro de `CreacionesClinicas`), `Notificaciones/EnviarRecordatorio` (WEB-2.6: los tres canales, con el registro del envío), `Cumpleanos/EnviarSaludoDeCumpleanos` (WEB-2.9: el saludo de cumpleaños, con su registro en `notificaciones_cumple`) y `Consultorio/{GuardarSede,GuardarConsultorio,GuardarBloqueHorario}` (WEB-2.8b: la sede con el estado y el país derivados de la ciudad, el consultorio con `reg_medico`/`medico_id` del contexto y el bloque de horario con las dos reglas del solape), y **`Consulta/{CrearConsulta,AbrirConsulta}` (WEB-4.1)**: `CrearConsulta` es **la regla del `nroconsulta`** (la última **de esa historia** + 1) y la usan la web al atender y el sync del móvil, que antes la tenía adentro de `CreacionesClinicas` —el mismo camino que hizo `AsignarHistoria` en WEB-2.11—; `AbrirConsulta` es **"Atender" abre la consulta**: recibe la cita, resuelve de quién es y devuelve la consulta **del día** (la que ya existe hoy o una nueva con `nueva: true`), con su historia y su `paciente_id`; **no marca `atendido`** (eso es `AtenderCita`) y **exige historia** (sin número lanza `InvalidArgumentException`, y la web manda a asignarla antes). Las dos superficies las usan: **ninguna regla de negocio se escribe dos veces**. **`Consulta/{AgregarMotivoAlCatalogo,AgregarMotivoALaConsulta,QuitarMotivoDeLaConsulta,GuardarEnfermedadActual}` (WEB-4.2)**: `AgregarMotivoAlCatalogo` es **el alta del catálogo de motivos** (las mayúsculas, el tope de 40, el correlativo de 4 dígitos y el "si ya existe uno igual devuelve el existente") y la usan el endpoint del app (`POST /api/app/motivos-consulta`) **y** la web —el servicio `App\Services\CatalogoMotivosConsulta` se borró porque quedó sin uso—; `AgregarMotivoALaConsulta` y `QuitarMotivoDeLaConsulta` son la fila de `motivo_consulta_paciente` (idempotente al agregar, sin tocar el catálogo al quitar) y las usan la web y el sync del móvil, **sin cambiar su comportamiento**; `GuardarEnfermedadActual` escribe `consultas.enfermedadactual` con el last-write-wins por columna y su rastro en `sync_changes` (`source = web`). **`Consulta/{GuardarEvaluacionFisica,ResultadoDeEvaluacion}` (WEB-4.3)**: `GuardarEvaluacionFisica` escribe la **evaluación física** —las siete columnas de `consultas` y las trece de `examen_fisico_nuevo`— **sólo lo que cambió**, con el mismo last-write-wins por columna y una fila de rastro por columna en la tabla que corresponde; **no toca** `colposcopia` (F6) ni los resúmenes `total_f`/`total_g`/`total_general` (los arma el escritorio). `ResultadoDeEvaluacion` distingue "no había cambios" de "otro equipo editó más recientemente": los dos llegan como cero columnas guardadas y son mensajes distintos. **`Consulta/{GuardarDiagnosticosDeLaConsulta,ResultadoDeDiagnosticos,AgregarDiagnosticoAlCatalogo,ReglasDelCatalogo}` (WEB-4.5)**: `GuardarDiagnosticosDeLaConsulta` aplica en una transacción la fila nueva (con la descripción del catálogo copiada en `detalle_diagnostco`, como el `INSERT` del escritorio, y `orden` nulo), el `detalle_diagnostco` editado de una fila que ya estaba y la baja —**sólo lo que cambió**, con el last-write-wins por columna y el rastro por columna en `sync_changes` con `source = web`—; `AgregarDiagnosticoAlCatalogo` es el alta del catálogo (correlativo de cuatro dígitos, mayúsculas, sin duplicar) y **comparte la regla con los motivos** vía `ReglasDelCatalogo` —que es el helper nuevo—; **el sync del escritorio no cambió** (sube `diagnostico_paciente` por su camino genérico, que hace un `UPDATE` con todas las columnas del lote: no es la regla de la Action) y el móvil sólo la baja. |
| `app/Notificaciones/` | Reglas sin estado de los recordatorios (WEB-2.6), portadas del móvil (`recordatorio.dart`): `Canal` (sms/whatsapp/correo), `Telefono` (normalización a internacional), `Correo` (validación), `MensajeDeRecordatorio` (plantilla + etiquetas), `ExcepcionDeEnvio` (el envío ni se intentó: falta el dato o el proveedor) y **`DespachadorDeCanales`** (WEB-2.9: **el envío por los tres canales**, con las credenciales del servidor y el registro de la respuesta del proveedor; lo comparten el recordatorio de cita y el saludo de cumpleaños). |
| `app/Cumpleanos/` | El **saludo de cumpleaños** (WEB-2.9) sin HTTP ni base: `Cumpleanos` (la regla de día y mes, con el **29/02 saludado el 28** en año no bisiesto), `Cumpleano` (un paciente que cumple hoy: edad, contacto, qué dato le falta), `CumpleanosDeHoy` (la lectura por `medico_pacientes`, acotada al `reg_medico`), `ConfiguracionDeCumpleanos` (interruptor, canal y hora **con los valores por defecto**, que no viven en la base), `MensajeDeCumpleanos` (la plantilla y sus etiquetas `{paciente}`/`{doctor}`), `RegistroDeCumpleanos` (a quién ya se le mandó, contra `notificaciones_cumple`) y `Vistas/CumpleanosVista`. |
| `app/Clinica/FechaDelConsultorio.php` | `FechaDelConsultorio`: "qué día es hoy" y "qué hora es" en la **zona del consultorio** (`config/clinica.php` → `CLINICA_ZONA`, por defecto `America/Caracas`), no en UTC como `config('app.timezone')`. Lo usa la decisión de a quién le toca el saludo y a qué hora sale. |
| `app/Clinica/` | La web clínica sin HTTP: `ContextoTrabajo` (médico/especialidad/sede de la sesión); `Agenda/` con las reglas (`ArmadorDeAgenda` —`citas()`, `citasDelPaciente()`, `jornadaPara()`, `citaPendiente()` y `desdeFilas()`, que resuelve cualquier consulta de `cola` con la misma ficha y la misma sede—, `Jornada`, `CitaDeAgenda`, `DiasNoLaborables`), la lectura (`LectorDeAgenda` —la agenda del rango y, con `sinHistoria()`, las citas del día cuyo paciente todavía no tiene historia (WEB-2.11)—, `ParametrosDeAgenda`, `MotivosDeCita` —el catálogo del médico: el `<select>` del formulario (la sede elegida más los sin sede, WEB-8.1c), el filtro de la agenda y la marca `admite_varias_citas_pendientes` de WEB-2.17; el ABM es `Catalogos\MotivosDeCitaDelCatalogo`—, `RecordatoriosDeAgenda`), la escritura de la web (`AgendamientoDeCitas`, `PacientesDeCita`, `FormularioDeCita`, `ResultadoDeAgendamiento`) y `Vistas/` (los ViewModels de la agenda, entre ellos `PacientesSinHistoriaVista` y `FilaSinHistoriaVista` del panel de WEB-2.11); `Pacientes/` (`PacientesDelConsultorio` —con `BusquedaDePacientes`, la búsqueda por palabras y sin acentos, `CamposDePaciente`, la limpieza de los campos sucios del legado, `FiltroDePacientes` —WEB-3.3: **la** definición de "sin historia" en SQL, la usan el filtro del listado y sus contadores— y `vinculo()`, el `paciente_id`+`numhistoria`+`reg_medico` del pivote que decide de quién es el paciente y si hay algo que numerar—, `CatalogoDeSeguros` —WEB-3.2: el catálogo `seg_emp` del `reg_medico`, del que sale el `<select>` de seguro de la ficha y el **nombre** con el que la ficha lo muestra—, `CitasDelPaciente` —WEB-2.15: las citas de un paciente, leídas con el mismo `ArmadorDeAgenda`—, `RepresentanteDelPaciente` —WEB-3.4: **el** representante de una historia, o `null`; el esquema del legado tiene `PRIMARY KEY (numhistoria)`, así que es **una fila por paciente** y no una lista—, `CatalogoDeParentescos` —WEB-3.4: el catálogo **de AppDDR** (tabla `parentesco`), del que sale el `<select>` y el **nombre** con el que se muestra el `codeparentesco` (un código que no esté se muestra tal cual: los del legado son desconocidos)— y sus `Vistas/`, con `FormularioDePacienteVista` para el alta/edición —WEB-3.2—, `FichaDePacienteVista` con la acción de asignar la historia —WEB-3.3— y `RepresentanteDePacienteVista` —WEB-3.4, el bloque de la ficha—); `Historia/` (`HistoriaDelPaciente` —WEB-3.5: **la lectura de la historia del paciente**, sus consultas con los motivos y diagnósticos ya resueltos por catálogo, sus antecedentes con la marca de alerta (`tipo2 = 'A'`) y sus documentos **sin las plantillas vacías** que el escritorio crea al abrir una consulta— y sus `Vistas/`, con `HistoriaDePacienteVista` —la pantalla— y `ResumenDeHistoriaVista` —la impresión—); `Consultorio/` (`SedesDeLaClinica`, `ConsultoriosDelMedico` y sus `Vistas/`); `Consulta/` (**F4**: `ConsultasDelContexto` —de quién es una consulta y cuál es su paciente, por `numhistoria` del pivote; la misma vara para la pantalla y para las acciones que escriben—, `PacienteDeLaCita` y `MotivosDeLaConsulta` —WEB-4.2: el catálogo del médico **sin distinguir tildes** y **un motivo por nombre, el de código más bajo** (el legado trae repetidos), los motivos de una consulta y si un texto ya es un motivo del catálogo—, con sus `Vistas/`: `ConsultaShellVista` (el panel de los 13 ítems y su estado), `SeccionDeMotivosVista` y `EnfermedadActualVista`), y —**WEB-4.3**— `EvaluacionFisica` (el objeto de valores de la sección: las columnas que **se editan**, las que sólo se leen de `examen_fisico_nuevo` —como `total_f`/`total_g`— y la normalización de los "vacíos" del legado: `NULL`, `''` y el literal `none`), `ExamenFisicoDeLaConsulta` (**el mapa de campos** de la evaluación física —qué columna, con qué rótulo y de qué tabla, con su fuente del PowerBuilder— y la lectura de las dos tablas) y `Vistas/EvaluacionFisicaVista` (los tres bloques del formulario, el **IMC derivado** y los resúmenes del escritorio); y `Vistas/` (lo compartido: `ArmazonVista`, `ConValoresDeFormulario`, `ContextoVista`, `InicioVista`). **Un ViewModel por pantalla**: recibe lo ya leído y devuelve los textos, estados y URLs que la vista pinta. —**WEB-4.5**: `DiagnosticosDeLaConsulta` (el catálogo del médico **sin tildes** con **un diagnóstico por nombre** —el de código más bajo—, las filas de la consulta con el nombre resuelto por catálogo y los "vacíos" del legado; no hay tipos ni CIE) y `Vistas/SeccionDeDiagnosticosVista` (las filas con su detalle editable, el buscador, los pendientes y el `?seccion=`). |
| `app/Http/Livewire/` | Componentes del panel: `Admin/*` (listados ~CRUD, `CargarSql`), `Medico/*`, `Components/*` (agenda/calendario), `Dashboard/*`, `Layouts/*`. |
| `resources/views/` | Blade: `auth/` (login + registro de médico/paciente), `livewire/` (una vista por componente, `admin/`, `medico/`, `dashboard/`, `layouts/`, `components/`), `clinica/` (web clínica: `layout.blade.php` + una vista por pantalla, sin lógica ni estilos ni JS inline), **`components/ui/`** (los componentes `<x-ui.*>` de la web clínica: `page-head`, `tarjeta`, `estado`, `identidad`, `tabla`, `menu`, `dialogo`, `campo`…) y `layouts/app.blade.php` (armazón compartido con el sitio público). El JS de `/clinica` está en `public/js/clinica/`. |
| `public/` | Document root (`index.php`, `.htaccess`) y los assets propios: `css/gineco.css` (**la paleta**, en tokens `--ds-*`, más los estilos de marca del sitio público), `css/doctorisimo-ui.css` (**el sistema de la aplicación**: Bootstrap recoloreado + armazón, se carga después de Bootstrap), `js/gineco.js`. |
| `app/Support/` | Utilidades sin estado (`FechaClinica`, `RestriccionesPlan`, `IconoModulo`, …). |
| `app/Models/` | Eloquent sobre las tablas del legado (`Cola`, `Paciente`, `Historia`, `Consulta`, `Medico`, `MedicalCenter`, `MotivoCita`, `Evolucion`, `UploadServer`, `User`…). |
| `app/Services/WhatsAppService.php` | Envío de plantillas por WhatsApp Cloud API (Meta, Graph API v19.0); lee `config('services.whatsapp.*')`. ⚠️ Su constructor tipa las credenciales como `string`: **verificá `config('services.whatsapp.*')` antes de instanciarlo** (por eso `EnviarRecordatorio` lo resuelve con `app()` después del chequeo, y no por inyección). |
| `app/Services/SmsService.php` | SMS por **Twilio** desde el servidor (WEB-2.6); lee `config('services.twilio.*')` (`TWILIO_*`). Devuelve la respuesta cruda del proveedor, como `WhatsAppService`. |
| `app/Mail/RecordatorioDeCita.php` + `resources/views/emails/recordatorio-cita.blade.php` | El correo del recordatorio (WEB-2.6), con el **médico de la cita como remitente** y `Reply-To` al mismo. `SaludoDeCumpleanos` + `emails/saludo-cumpleanos.blade.php` (WEB-2.9) es el del saludo de cumpleaños: **misma forma y mismo remitente**, sólo cambia el asunto (por eso el despachador de canales los manda igual). |
| `app/Console/Commands/EnviarCumpleanosCommand.php` + `app/Console/Kernel.php` | **`cumpleanos:enviar`** (WEB-2.9): recorre los médicos con el envío automático activo y les manda el saludo a los que cumplen años hoy, **sin repetir** (el registro manda). Tiene `--fecha=AAAA-MM-DD` y `--simular` (no manda ni registra). Es la **única tarea programada** del proyecto: `Kernel::schedule()` la corre **cada 15 minutos** porque la hora es por médico. ⚠️ En producción necesita el **cron del servidor** (`schedule:run`). |
| `database/migrations/2026_08_25_055351_create_medical_tables_schema.php` | **Migración núcleo**: crea el esquema médico completo migrado del legado (~120 tablas, ~1650 líneas). Referencia obligatoria. |
| `database/migrations/2026_0*` | Tablas nuevas del proyecto (users, medicos, historias, medical_centers, medico_pacientes, upload_servers, offices, permisos, **`notificaciones_cumple`** —WEB-2.9: el registro de los saludos de cumpleaños, con índice único por `(paciente_id, fecha_cumple)`— y **`parentesco`** —WEB-3.4: el catálogo de parentescos del representante, de AppDDR, con `codigo` `char(1)` único—). |
| `database/seeders/` | Países / estados / ciudades / especialidades / roles / **`ParentescoSeeder`** (WEB-3.4: el catálogo de parentescos del representante, idempotente con `updateOrCreate`, y es **catálogo**: corre también en producción) / **`EspecialidadesYModulosSeeder`** (el marco multi-especialidad: los módulos de cada especialidad con `implementado`/`visible_para`; siembra el **núcleo** —agenda y pacientes— para **todas** las filas del catálogo, no sólo las que tienen manifiesto, y es **catálogo**: en una base ya desplegada hay que correrlo una vez) / datos médicos de ejemplo. |
| `config/` | Config Laravel estándar (`auth.php` define el guard `api` con driver **sanctum**; `services.php` las credenciales de WhatsApp). |
| `tests/` | `bootstrap.php` arma sola la base de tests aislada (`database/database.testing.sqlite`). `Feature/`: tests reales con `DatabaseTransactions` (sync del legado, credenciales, configuración, citas, récipes…). `php artisan test` corre la suite completa. |

## Endpoints principales (estado en la rama `desarrollo`)

**App móvil**

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/app/login` | `LoginAppController@login` — devuelve `access_token` Sanctum + `user_type` + roles/permisos. Desde el Paso 26.D también `must_change_password` (en la raíz y dentro de `user`): `true` si la clave es temporal. Es lo único que se agregó; si la clave es temporal o la cuenta está bloqueada, el login igual da token y lo que se corta es el resto del API (ver abajo). |
| `POST` | `/api/app/cambiar-password` | `CambiarPasswordController@cambiar` — `password_actual`, `password_nueva` (+`_confirmation`, mín. 8). Es lo único (junto a `GET /api/user`) que deja pasar `EnsurePasswordChanged` mientras la clave es temporal; las demás rutas `auth:api` responden 403 `password_change_required`. |
| `POST` | `/api/app/refresh-data` | `RefreshAppController@refreshData` — protegido por `auth:api`; acepta `mes`/`anio` y devuelve citas, colas, pacientes, motivos, centros médicos, historias y evoluciones del médico (o del paciente). |
| `POST` | `/api/app/sync-app-data` | `SyncAppDataController@sync` — sync delta real (push + pull), ver `12-arquitectura-offline-sync.md`. Desde el Paso 18.B también crea historia, consulta y récipe (`App\Sync\CreacionesClinicas`, número asignado por el servidor); desde el 18.B2, además, motivos de consulta: baja `motivos_consulta` y `motivo_consulta_paciente`, acepta la creación `motivo_consulta_paciente` (agrega un motivo del catálogo a una consulta) y `deleted` sobre ella lo quita. |
| `POST` | `/api/app/citas/{cola}/notificar` | `NotificacionCitaController@enviar` — recordatorio al paciente por `whatsapp`, `sms` (Twilio) o `correo` (SMTP del servidor), online-only, no pasa por la cola de sync. La lógica es `App\Actions\Notificaciones\EnviarRecordatorio`, **la misma que usa la web**. |
| `POST` | `/api/app/configuracion` | `ConfiguracionMedicoController@actualizar` — datos de reporte del médico (Paso 17: especialidad, logo, pie de récipe/informe) y desde el Paso 23 las plantillas de mensaje (`plantilla_cita`/`plantilla_cumple`); online-only, misma razón que el de notificar. |
| `POST` | `/api/app/motivos-consulta` | `MotivoConsultaController@crear` — alta en el catálogo de motivos de consulta del médico (Paso 18.B2), online-only: `descripcion` → `id`, `codemotivo` (correlativo de 4 dígitos); si ya hay una igual devuelve la existente (200) en vez de duplicar. |
| `POST` | `/api/app/configuracion/formato-recipe` | `RecipeFormatoController@actualizar` — formato de impresión del récipe (alineación/fuente/estilo por elemento, color de línea, tamaño del logo) + firma/sello (Paso 18.A). Parcial, online-only; el sync lo devuelve completo en `formato_recipe`. |

**Cuentas de acceso** (Paso 26, `app/Services/CuentaService.php`). **El mecanismo de inicio de sesión (API y
web)**: `users` con roles Spatie (`Root`, `Administrador`, `Medico`…) y `medicos.user_id` como vínculo con el
doctor, como ya era. **Desde el 2026-10-19 el login web es uno solo** (pedido del usuario): la pantalla pide
correo y contraseña, sin selector de tipo, y `App\Services\AutenticacionPorCorreo` prueba `users`, `medicos` y
`pacientes` en ese orden (crea la cuenta y asigna el rol al médico/paciente del legado la primera vez). A dónde
entra cada rol lo decide **`User::rutaDeInicio()`**: `clinica.inicio` para médico y secretaría y `dashboard` para
administración (y para el paciente, mientras no exista `/consultorio`). Si la cuenta puede trabajar en **más de
un área** (médico + administrador), **`User::rutaDeEntrada()`** manda a **`/elegir-panel`** en vez de elegir por
ella; esa pantalla lista `User::panelesDisponibles()` y no guarda preferencia. El alta de cuentas web sigue
cerrada (`Auth::routes(['register' => false])`): el registro de médicos con aprobación y el de pacientes son las
fases siguientes. Lo nuevo se apoya en eso:
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
- **Registros de datos** (menú de 3 puntos de un médico → "Registros de datos"; `CuentaService::registrosDe/registrosAsignables/asignarRegistro/quitarRegistro`):
  un administrador le da o le quita acceso a los datos de otro `reg_medico`. El sync lee agenda/consultas/récipes por `medico_registros`
  y pacientes por `medico_pacientes`, así que asignar hace las dos cosas (y quitar las deshace, sin borrar datos). Solo se asignan `reg_medico`
  que ya existen; el propio no se quita. Sirve para compartir consultorio y para **depurar**: crea un médico de prueba ("Nuevo médico"), asígnale el
  `reg_medico` del doctor, entra con él y quítalo al terminar. Mirar es seguro; editar algo existente (confirmar/cobrar una cita) queda en los datos
  del registro ajeno, y el servicio contratado se mide por el mejor estado entre sus registros.
- **Sección "Usuarios" del panel** (`/admin/cuentas`, componente Livewire `Admin\Cuentas`, ruta en
  `routes/web/cuentas.php`; Root o Administrador): lista de médicos y de administradores, alta con clave temporal
  (se muestra una sola vez), crear acceso a un médico sin cuenta y **editar** nombre, correo, teléfono y licencia
  (el `reg_medico` no se edita: es la llave de sus datos en la nube). Alta y edición llevan también el **prefijo**
  (`medicos.prefix`, texto libre de hasta 20 caracteres: "Dr", "Dra", "Ing"…; el modelo le agrega el punto y
  lo deja en `null` si queda vacío). `cambios/estado` lo devuelve como `medico_prefix` junto a `medico_nombre`
  (sin prefijo): GinecoReport arma con ellos su `Doct`. Cada fila trae "Editar" y un **menú de 3
  puntos** con Roles (los marcados quedan exactamente así: `CuentaService::asignarRoles`; solo un Root da o quita
  Root; nadie se quita su propio acceso ni deja sin administradores), Resetear clave, Generar API key (el mismo
  trait `Concerns\EmiteApiKeys` que usa "API Keys") y Bloquear/Desbloquear. **Sustituye** al enlace "Usuarios" del
  panel lateral y a las tarjetas del escritorio
  Root; el `ListUsers` viejo (`/users`, `/admin/users`) sigue existiendo pero ya no está enlazado (reemplaza TODOS
  los roles de un usuario al editarlo y fija claves sin marcarlas como temporales). Cada acción de Livewire vuelve a
  comprobar el permiso en `hydrate()`: la ruta solo protege la carga de la página. "Permisos de Usuario" se quitó del panel lateral
  (no funcionaba; `/users/permissions` sigue en `routes/web.php`, sin enlace). **Cerrar sesión** pide confirmación
  en el panel lateral y en el menú del usuario (`confirmarCierreSesion` en `layouts/app.blade.php`, SweetAlert2).
- **Sección "Especialidades"** (`/admin/especialidades`, `Admin\Especialidades`, misma ruta-archivo
  `routes/web/cuentas.php`): con qué especialidades trabaja cada médico — es lo que enciende los módulos de la web
  clínica (`specialties` + pivote `medico_specialty`, ver `ContextoTrabajo`; **no** confundir con `especial`, el
  catálogo del legado por médico). Lista los médicos con sus especialidades y sus pastillas **Todos / Sin
  especialidad / Con especialidad** (los que no tienen ninguna no ven ningún módulo), y la ventana de asignación
  tilda las del catálogo: la regla es `CuentaService::asignarEspecialidades` (`sync` del pivote) y **se puede
  dejar al médico sin ninguna** — se avisa, no se bloquea. El catálogo **no** se crea ni se edita acá: lo siembran
  los manifiestos (`config/especialidades/*.php` con `EspecialidadesYModulosSeeder`). Tests en
  `tests/Feature/EspecialidadesDelMedicoTest.php`; verificación en Chrome en
  `../.agent-tools/verificar-especialidades-admin-web.js` (trampa 51: las casillas del array van con `.defer`).
  ⚠️ **El menú del consultorio sale de `especialidad_modulo`, no del consultorio**: el **núcleo compartido**
  (agenda y pacientes, `EspecialidadesYModulosSeeder::NUCLEO_IMPLEMENTADO`) está implementado para **todas**
  las especialidades del catálogo (PLAN-WEB.md R1) y los módulos **propios** de cada una viven en
  `PROPIOS_IMPLEMENTADOS` (hoy vacío). Sin el núcleo, una especialidad sin manifiesto dejaba al médico con el
  menú vacío aunque tuviera la especialidad activa y su consultorio cargado (medido 2026-10-11: era el caso
  de 7 de las 8 filas del catálogo). Un médico **sin consultorios** entra igual y ve sus módulos, pero **no
  puede agendar** (la jornada se arma por sede). En `/clinica/contexto` el campo es el **consultorio**
  (`offices`) —se rotula así desde el 2026-10-11, antes decía "Sede"— y el aviso manda a
  `/clinica/consultorios` (la sede, `medical_centers`, se crea en `/clinica/sedes`). Verificación en Chrome:
  `../.agent-tools/verificar-menu-por-especialidad-web.js`. En una base ya desplegada, el paso del final del
  despliegue es **`php artisan especialidades:sembrar`** (ver "Cómo verificar cambios").
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

**La lista blanca es por especialidad (WEB-7.3, 2026-10-13).** `config/sync_legado.php` → `tablas` es la
lista **histórica** (el núcleo y lo de ginecología, 101 tablas) y `tablas_por_especialidad` +
`columnas_equivalentes` se **derivan de los manifiestos** (`config/especialidades/<slug>.php` →
`sync.tablas`; hoy pediatría agrega 56). `App\Sync\Escritorio\TablasDelSync` combina: `para($especialidad)`
es lo que se le **ofrece** al escritorio (`cambios/estado`, `carga-inicial/iniciar`, la restauración) y
`aceptadas()` la **unión**, que es lo que el API acepta escribir; una especialidad sin manifiesto sigue con
la histórica. Para un médico con **varias especialidades** (el pivote `medico_specialty` lo permite) se le
ofrece la unión de todas: con una sola, las tablas de la otra no recibirían triggers y no subirían nunca.
`columnas_equivalentes` traduce nombres de columna que el escritorio llama distinto —en la
fila **y en la clave** del cambio—: es lo que hace que `examen_fisico_nuevo` de pediatría (clave
`nrohistoria`/`nroconsulta`) caiga en `historia`/`consulta` sin columnas nuevas. ⚠️ **La clave de `cola`
NO es única**: la primaria del legado es `fecha` + `numhistoria` (no `fecha + hora_ini`), así que dos
citas a la misma hora son legítimas; `cola.clave_escritorio` lleva sólo un índice común y
`CambiosEscritorio::citaDelCambio` resuelve la cita por la clave que manda el escritorio —si trae
`hora_ini` busca por `clave_escritorio` y si no (pediatría) por `fecha` + historia— (trampa 109). Tests en
`tests/Feature/PediatriaSyncTest.php`. El relevamiento de una especialidad nueva son tres tools
(`../bridge/tools/`), contados en `../Docs/Web/relevamiento-pediatria.md`.

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/sync/carga-inicial/iniciar` | `CargaInicialController@iniciar` — solo si el `reg_medico` no tiene datos (primera carga); si hay una carga cortada, la retoma y devuelve cuántas filas tiene de cada tabla. |
| `POST` | `/api/sync/carga-inicial/lote` | `@lote` — idempotente por posición (`desde`): reenviar un lote no duplica; `409 posicion_incorrecta` + `esperado` si hay hueco. `pacientes` crea paciente + `medico_pacientes` + `historias`; el resto se inserta con el `reg_medico` de la carga. |
| `POST` | `/api/sync/carga-inicial/finalizar` | `@finalizar` — cierra solo si llegaron todas las filas anunciadas. Después, `iniciar` para ese médico da `409 carga_ya_completa`. |

*Sincronización de cambios* (Fase 2, mismos límites; `App\Sync\Escritorio\CambiosEscritorio`, tests en
`tests/Feature/CambiosEscritorioTest.php`, diseño en `../bridge/DISENO-FASE-2.md`):

| Método | Ruta | Controlador |
|---|---|---|
| `POST` | `/api/sync/cambios/estado` | `CambiosEscritorioController@estado` — si el médico completó la carga inicial y qué tablas vigilar; el nombre/prefijo del médico y el **servicio contratado** (`servicio_estado|plan|vence|dias|gracia_hasta`). Nunca se bloquea por servicio vencido. |
| `POST` | `/api/sync/cambios/subir` | `@subir` — aplica lo que cambió en el escritorio. Traduce números de historia/consulta con `clave_escritorio` (historias, consultas, cola); "gana la última edición" por columna con `sync_changes` (solo cuentan ediciones del app). |

*Imágenes de la PC del consultorio (WEB-T.1, 2026-10-12)*: la herramienta `../bridge/subir-imagenes.exe` (Node, sin dependencias; instructivo
`../bridge/LEEME-subir-imagenes.md`) sube los archivos cuya **ruta** guarda el escritorio. Misma autenticación del sync (`SyncAuthService`: credencial por equipo
o `X-API-KEY` + `reg_medico` en el cuerpo; 403 `medico_ajeno`, 402 servicio vencido), `throttle:300,1,imagenes-pc`, controlador `ImagenesPcController`:

| Método | Ruta | Qué hace |
|---|---|---|
| `POST` | `/api/sync/imagenes/pendientes` | `{reg_medico?, cursor?, limite?, reintentar_omitidas?, tabla?}` → `items[{tabla,fila_id,columna,ruta_pc}]`, `siguiente` (cursor), `pendientes_total` (1.ª página), `tope_bytes`. Sólo valores con ruta; excluye lo `subida`/`omitida` para el valor actual. |
| `POST` | `/api/sync/imagenes/subir` | multipart: `archivo` + `tabla`, `fila_id`, `columna`, `ruta_pc`, `sha256`. Valida lista blanca, fila del médico (404 `fila_inexistente`), ruta vigente (409 `fila_cambio`), tope 10 MB (422 `demasiado_grande`), **tipo por cabecera** (422 `tipo_no_permitido`, con HEIC), sha256 (422 `sha256_no_coincide`), límites de PHP (413 `limite_del_servidor`). Idempotente (`ya_estaba`), `reemplazada` si cambió el contenido, borra el archivo si la base falla. |
| `POST` | `/api/sync/imagenes/omitir` | `{items:[{tabla,fila_id,columna,ruta_pc,motivo}]}` (≤ 200): registra `omitida` con motivo (`no_existe`, `tipo_no_permitido`, `demasiado_grande`, `no_legible`, `ruta_no_valida`, `otro`); nunca degrada lo ya subido. |

Tabla **nueva** `imagenes_de_la_pc` (`App\Models\ImagenDeLaPc`; única por `reg_medico`+`tabla`+`fila_id`+`columna`), lista blanca en `config/imagenes_pc.php`
(`App\Sync\ImagenesPc\{CatalogoDeImagenes,ReferenciasDeImagenes}`), Actions `App\Actions\Imagenes\{SubirImagenDeLaPc,OmitirImagenDeLaPc,ResultadoDeImagenDeLaPc}`,
detección `App\Clinica\Archivos\TipoRealDeImagen` y el lector `App\Clinica\Archivos\ImagenesMigradas` (fila legada → archivo en la nube, para cuando la web muestre
`imagen_pacientes`/`imagenes`/`foto_pac`). Los archivos van a `storage/app/clinica/importadas/<reg_medico>/<tabla>/<año-mes>/<40 al azar>.<ext del contenido>`. Sólo
`imagen_consulta` se reescribe (`imagen` relativa + `disco`, rastro `updated` por columna con `source = migracion`); la web muestra el nombre que tenía en la PC y un HEIC
como descarga (`ConsultaImagenesController::descargar`); quitar una imagen migrada borra también su registro. Comando **`php artisan imagenes:estado --reg-medico=<m>|--todos`**
(referencias / subidas / omitidas por motivo / pendientes / peso; sin escribir). Tests: `ImagenesDeLaPcTest` (30), `ImagenesDeLaPcWebTest` (5), `Unit/TipoRealDeImagenTest` (7). Ver las trampas 83 a 86.

*Restauración de una base desde la nube* (una sola vez, para cuando se pierde la PC). **No** es la
bajada: `cambios/bajar` (Fase 2.B) **está descartada** (decisión final del usuario, 2026-10-11: el
PowerBuilder no se actualiza, la web lo reemplaza y el sync del escritorio es sólo de subida; lo único que
falta es subir las imágenes, WEB-T.1). `App\Sync\Escritorio\RestauracionEscritorio` +
`php artisan sync:restaurar-escritorio --reg-medico=<medico> [--salida=<carpeta>] [--zip]` escribe
`manifiesto.txt` + `datos/<tabla>.tsv` con las tablas de `config/sync_legado.php`, usando las
traducciones inversas (`EstadoCita::aEscritorio`, `AtencionCita::aEscritorio`, pacientes por
`historias.clave_escritorio`) y dejando afuera lo excluido (`evolucion.logo/contrasena/sms_*`). Del
lado de la PC lo aplica `../bridge/restaurar-escritorio.vbs` (ADODB sobre el DSN de `cdatabase.ini`,
con el esquema real de la base local). Tests en `tests/Feature/RestauracionEscritorioTest.php`;
instructivo en `../bridge/LEEME-restauracion.md`.

**Planes de servicio y vencimiento** (ROADMAP Paso 27). Tablas `planes` (catálogo; `restricciones` en JSON leído con
`App\Support\RestriccionesPlan`, que completa con valores por defecto lo que falte) y `reg_medico_servicio` (una fila
por contratación/renovación, copia restricciones y monto del plan; el vigente es la activa de mayor `vence_el`).
**No toca `medicos`.** `App\Services\ServicioService` resuelve el estado: `vigente`, `gracia` (5 días, todavía
sincroniza), `vencido` y `sin_servicio`. **Solo bloquea la sincronización**: 402 `servicio_vencido`/`sin_servicio`
(`error` y `code`) en `sync/cambios/subir`, `sync/carga-inicial/*` y `app/sync-app-data`; `cambios/estado` no se
bloquea, y `sync-app-data` devuelve un bloque `servicio`. Un médico nuevo recibe el plan `es_default` **gratis por 1
mes** (evento `MedicoRegistrado`, lo dispara el modelo `Medico`; el default puede ser de pago); quien sincroniza desde
el escritorio, **1 año gratis una sola vez** (evento `EscritorioSincronizo`, plan `powerbuilder`). Se opera con
`php artisan servicio ver|renovar|planes`; configuración en `config/servicios.php`. **Sección "Planes y servicios" del
panel** (`/admin/servicios`, `Admin\Servicios`, Root o Administrador): pestaña *Servicios por médico* (plan, vencimiento y
estado; contadores que filtran; botón **Modificar** con **Renovar** (plan/meses/monto/nota, continúa donde termina el
actual), **Reemplazar** (otro plan **desde hoy**, sin conservar el tiempo; cancela todo lo activo y crea el nuevo en una
transacción) y **Quitar plan** (cancela todos los períodos activos: sin servicio y sin sincronizar ya; sin deshacer, se
arregla asignando un plan); Reemplazar y Quitar plan piden **motivo** y lo dejan en `nota` con quién y cuándo
(`ServicioService::reemplazar`/`cancelarActivos`); y **Historial** con cancelar un período) y pestaña *Planes* (alta/edición: frecuencia, monto, tachado con el ahorro, orden, visible,
activo, predeterminado; no se borran, se desactivan; el predeterminado no se desmarca ni se desactiva, se marca otro).
Los planes **todavía no tienen restricciones editables** en el panel. Falta: límites (`max_medicos`,
`max_historico_meses`) y el manejo del 402 en el app.

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

**Al final de cada despliegue** hay que correr **`php artisan especialidades:sembrar`**. El catálogo
multi-especialidad (`specialties` + `especialidad_modulo`) es **catálogo y no viaja por migración**, así que
en un servidor que ya tenía datos las especialidades que ya existían se quedan sin módulos y el consultorio
del médico sale **vacío** ("No hay módulos habilitados en este consultorio todavía"), aunque tenga la
especialidad activa y su consultorio cargado (medido el 2026-10-11: 7 de las 8 especialidades del catálogo).
El comando es **idempotente** —sólo agrega lo que falta, no renombra, no borra y no pisa lo que no
administra—, tiene **`--simular`** para mirar sin escribir y **termina en error** si algo no se pudo arreglar
(por ejemplo un módulo del núcleo desactivado a mano en `modulos_clinicos`), para que el despliegue no pase
como bueno. Corre después de `migrate --force`; las reglas viven en los seeders, no en el comando
(`app/Console/Commands/SembrarEspecialidadesCommand.php`, tests en
`tests/Feature/SembrarEspecialidadesCommandTest.php`).

**Nunca commitear `.env` ni datos reales de pacientes** (el repo arrastra el esquema y los seeders del
sistema legado).

**La base de desarrollo es dummy y se deja como quede** (decisión del usuario, 2026-10-10): un test de
navegador puede crear datos y **no borrarlos**, y no hace falta que los verificadores limpien nada. Si
alguna vez hace falta limpiar a mano, va **por `id`** y contra la base de desarrollo (`database.testing.sqlite`
la arma sola la suite): dos limpiezas por `numhistoria`/`nroconsulta` ya borraron filas de otros pacientes
(6 de `consultas` y 6 de `examen_fisico_nuevo`).

## Sistema visual (web clínica y panel)

**Antes de tocar una pantalla de `/clinica` leé [`../Docs/Web/guia-ui.md`](../Docs/Web/guia-ui.md) (cómo se ve: tokens,
componentes `<x-ui.*>`, estados, checklist) y [`../Docs/Web/guia-arquitectura-web.md`](../Docs/Web/guia-arquitectura-web.md)
(cómo se construye: controlador delgado → consulta/acción → ViewModel en `app/Clinica/<Modulo>/Vistas/` → Blade que solo
pinta).** Componentes en `resources/views/components/ui/`; JS de cada pantalla en `public/js/clinica/`.

**La paleta sale del logo de la app** (`../DoctorisimoMobile/assets/icon/icon.png`), medido píxel a píxel:
violeta `#783080` (primario, 8,3:1 con blanco), azul marino `#201048` (texto, 17,1:1) y el celeste del
latido `#40b0d8` (acento decorativo, no sirve como texto). Está en **un solo lugar**: el bloque `:root` de
`public/css/gineco.css`, como tokens `--ds-*`, con la relación de contraste anotada en cada uno. Los nombres
viejos (`--primary-color`, `--accent-color`…) siguen existiendo apuntando a los nuevos, así que las vistas
que ya los usaban cambiaron de color sin tocarlas.

- **`public/css/gineco.css`** — los tokens + los estilos de marca del sitio público (`hero-section`,
  `service-card`, `btn-primary-gineco`, footer).
- **Rediseño de UI (2026-10-11), `/clinica` y panel por igual**: tipografía **Inter** variable **auto-alojada**
  (`public/fonts/inter/`, licencia OFL; sin CDN), **barra lateral indigo de alto completo** con la marca arriba
  (tokens `--ds-barra*`) y plegable a 72 px, barra superior delgada, botones y campos de **36 px**, pastillas de
  8 px, `<x-ui.dialogo lateral>` para los formularios y `<x-ui.avisos />` para los avisos del panel. El panel
  (`resources/views/livewire/admin/*`) ya usa `<x-ui.page-head>` y `<x-ui.avisos />`; **quedan** sus
  modales de Bootstrap (se ven bien pero no son el panel lateral), algunos `style=` de los dashboards y el
  `paciente-dashboard`. Detalle y reglas en [`../Docs/Web/guia-ui.md`](../Docs/Web/guia-ui.md).
- **`public/css/doctorisimo-ui.css`** — **el sistema de la aplicación**, y se carga **después de Bootstrap**:
  recolorea Bootstrap por variables (`--bs-primary`, `--bs-body-color`, `--bs-*-bg-subtle`…) más las reglas
  donde Bootstrap tiene el color fijo (`btn-*`, `badge bg-*`, `table-dark`, `code`). Por eso las ~50 vistas
  del panel adoptaron la paleta sin editarlas una por una. Trae además el armazón: `.app-sidebar`,
  `.app-topbar`, `.page-head`, `.stat-card`, `.empty-state`, `.chip`.
- Se carga en `layouts/app.blade.php` (panel **y** sitio público: es el mismo armazón de Livewire) y en
  `clinica/layout.blade.php`, `auth/login.blade.php` y `auth/elegir-panel.blade.php`.
- **Cuando algo se ve mal, no se adivina: se mide.** `../.agent-tools/contraste-ui-web.js` recorre las
  pantallas, resuelve el fondo efectivo de cada texto y calcula la relación WCAG (umbral 4,5:1 / 3:1 para
  texto grande). Deja el conteo de incumplimientos por pantalla; hoy son **0**.
  `../.agent-tools/capturas-ui-web.js` saca las capturas de todas las pantallas (público, clínica y panel,
  escritorio y celular) para revisarlas.

**Trampa medida (2026-10-08):** un botón deshabilitado **no** usa `--bs-btn-color` sino
`--bs-btn-disabled-color`, que cada variante de Bootstrap fija con su propio color. Sobreescribir solo la
primera deja los botones deshabilitados con el color viejo (el "Atender" de la agenda quedaba en 1,8:1).
Los `btn-outline-*` deshabilitados se unifican en gris en `doctorisimo-ui.css`. Y desde WEB-4.2 la hoja trae `[hidden] { display: none !important }`: Bootstrap pisaba el atributo `hidden` con `.d-flex` (ver trampa 23).

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

## Trampas conocidas

Las trampas **medidas** del repo están en **[`TRAMPAS.md`](TRAMPAS.md)** (van **90** y el archivo se lee
entero en una sola pasada). **Leélo antes de escribir código**: el esquema del legado, los `where`
crudos, los roles de la consulta (**trampa 19: "Atender" es sólo del médico**, WEB-4.9), los `[hidden]`,
`examen_fisico_nuevo`, las **reglas de archivo de
Laravel** (trampas 30 a 32: `mimetypes` rechaza toda subida, `finfo` usa la extensión como pista y
`imagen_consulta.imagen` es `NOT NULL`) y **los verificadores de navegador** (trampas 33 a 35: el `_token`
se rota al responder, los ayudantes que se apoyan en lo que dejó la corrida anterior eligen la fila
equivocada y el diálogo de "Atender" está en el HTML de todos los roles) se pagan caros. Cada tarea
nueva agrega la suya **al final de ese archivo**, con la tarea y la fecha que la encontró.

## Dónde está el detalle funcional

Este repo no lleva la especificación: está en la wiki.
[Índice](../Docs/Wiki/index.md) ·
[Modelo de datos](../Docs/Wiki/07-modelo-de-datos.md) ·
[Arquitectura offline/sync](../Docs/Wiki/12-arquitectura-offline-sync.md) ·
[Agenda](../Docs/Wiki/02-modulo-agenda.md) ·
[Notificaciones y recordatorios](../Docs/Wiki/03-notificaciones-recordatorios.md) ·
[Récipes y solicitudes](../Docs/Wiki/05-recipes-y-solicitudes.md) ·
[Contexto general](../AGENTS.md).

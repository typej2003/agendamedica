<?php

/*
|--------------------------------------------------------------------------
| Imágenes que el escritorio guarda como RUTA del disco de la PC (WEB-T.1)
|--------------------------------------------------------------------------
|
| Lista blanca de (tabla, columnas) cuyo valor es la ruta de un archivo de imagen en la PC del consultorio. Es la ÚNICA
| puerta de `POST /api/sync/imagenes/*`: ninguna otra tabla ni columna se puede pedir, subir ni omitir. Se armó
| revisando el esquema real de la base del legado (`Docs/MICONSULTAGI-legado/esquemas-asa/*.sql`) y las migraciones
| (2026-10-12): estas son las columnas que guardan una ruta de archivo de imagen.
|
|  - `columnas`: las columnas con la ruta.
|  - `dueno`:    cómo se sabe de qué médico es la fila. `reg_medico` = la columna de la tabla; `paciente` = la tabla
|                no tiene `reg_medico` (la ficha del paciente es compartida) y el dueño es el médico que tiene al
|                paciente en `medico_pacientes`.
|  - `reescribe`: `true` si, al subir, se reescribe la columna legada (`imagen` = ruta relativa al disco y `disco` =
|                `clinica`), porque la web ya sabe servir esa tabla (`imagen_consulta`, WEB-4.7). En las demás la
|                columna NO se toca (el escritorio sigue leyéndola y su subida podría revertirla): el archivo queda
|                sólo en `imagenes_de_la_pc`, a la espera de las pantallas que lo muestren.
|
| Una tabla que no exista en la base se ignora sola. `paciente_chamo` (la ficha del paciente pediátrico) estuvo en esa
| situación hasta que WEB-7.4.1 la creó (2026-10-13): desde entonces su foto también sube, sin tocar la herramienta.
|
| Quedan FUERA a propósito:
|  - `evolucion.logo` (y `evolucion_copy.logo`): el logo del membrete lo maneja WEB-8.2 y está en `columnas_excluidas`
|    de `sync_legado.php`; en el API es una ruta de Storage del disco `public`, no una ruta de Windows.
|  - `recipe_formatos.firma` / `sello`: ya son archivos del API (WEB-8.2).
|  - `medicos.photo_path`: es de AppDDR, no del legado.
*/

return [

    'columnas' => [
        'imagen_consulta' => ['columnas' => ['imagen'], 'dueno' => 'reg_medico', 'reescribe' => true],
        'imagen_pacientes' => ['columnas' => ['imagen', 'imagen2'], 'dueno' => 'reg_medico', 'reescribe' => false],
        'imagen_pacientes_2' => ['columnas' => ['imagen', 'imagen2'], 'dueno' => 'reg_medico', 'reescribe' => false],
        'imagenes' => ['columnas' => ['imagen'], 'dueno' => 'reg_medico', 'reescribe' => false],
        'pacientes' => ['columnas' => ['foto_pac'], 'dueno' => 'paciente', 'reescribe' => false],
        'paciente_chamo' => ['columnas' => ['foto_paciente'], 'dueno' => 'reg_medico', 'reescribe' => false],
    ],

    // Cuántas referencias devuelve una página de `pendientes` (y el tope que se acepta).
    'pagina' => 500,
    'pagina_maxima' => 1000,

    // Cuántas omisiones acepta una llamada a `omitir`.
    'omitir_maximo' => 200,

];

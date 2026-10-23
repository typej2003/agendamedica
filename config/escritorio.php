<?php

/*
|--------------------------------------------------------------------------
| Canal de publicación del escritorio (instalador "Standalone")
|--------------------------------------------------------------------------
|
| El launcher que se le manda al consultorio (un solo `.exe`) se baja de acá:
|
|   GET /api/escritorio/canal            -> el manifiesto vigente (texto plano)
|   GET /api/escritorio/canal/{ruta}     -> un archivo de esa versión
|   GET /api/escritorio/launcher         -> el propio launcher, para reenviarlo
|   POST /api/escritorio/publicar/...    -> publicar una versión (con X-API-KEY)
|
| Los archivos viven en el disco `escritorio` (ver config/filesystems.php), que es privado: la única
| puerta es la ruta del API. El manifiesto **no** se escribe hasta que la versión está completa, así
| que una subida cortada nunca deja a un consultorio bajando archivos a medias.
|
*/

return [

    /*
    | Disco donde se guardan las versiones publicadas. Su raíz es `storage/app/escritorio`: fuera de
    | `public/` y sin enlace simbólico, igual que `clinica` y `comentarios`. Se sirve con
    | `Storage::response()` desde el controlador, nunca por URL directa.
    */
    'disk' => env('ESCRITORIO_DISK', 'escritorio'),

    /*
    | Prefijo dentro del disco. Cambiarlo obliga a republicar (el manifiesto y las versiones cuelgan
    | de acá).
    */
    'prefijo' => 'canal',

    /*
    | Nombre con el que se publica el launcher (el `.exe` que se le manda al consultorio). No viaja en
    | el manifiesto: el launcher no se auto-actualiza, se reemplaza a mano.
    */
    'launcher' => 'instalar.exe',

    /*
    | Clave para PUBLICAR. No hace falta para descargar: el consultorio baja sin credencial, como hoy
    | con el paquete. Se genera con `php artisan escritorio:canal-clave --rotar`.
    */
    'clave' => env('ESCRITORIO_CANAL_KEY'),

    /*
    | Ejecutables de la aplicación que el launcher acepta encontrar en la carpeta donde se corre. Si
    | mañana se suma una especialidad, se agrega acá (o se pasa `--apps` al publicar) y sube la
    | versión: el launcher lo lee del manifiesto, no lo tiene escrito.
    */
    'apps' => [
        'ginecoreport.exe',
        'pediareport.exe',
    ],

    /*
    | Tope por archivo. El publicador manda un archivo por request, así que este número tiene que
    | entrar cómodo en `upload_max_filesize` y `post_max_size` del php.ini.
    */
    'max_bytes' => 64 * 1024 * 1024,

    /*
    | Cuántas versiones se conservan. Las viejas se borran al publicar una nueva; 3 deja margen para
    | un consultorio que esté bajando justo cuando se publica.
    */
    'conservar' => 3,

];

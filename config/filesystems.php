<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */

    'default' => env('FILESYSTEM_DRIVER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Default Cloud Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Many applications store files both locally and in the cloud. For this
    | reason, you may specify a default "cloud" driver here. This driver
    | will be bound as the Cloud disk implementation in the container.
    |
    */

    'cloud' => env('FILESYSTEM_CLOUD', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => env('APP_URL').'/storage',
            'visibility' => 'public',
        ],

        /*
         * **Los archivos clínicos que sube un usuario** (WEB-4.7): las imágenes de una consulta.
         *
         * Es un disco **privado** a propósito y por eso **no** se parece al `public`: su raíz está fuera
         * de `public/` (`storage/app/clinica`, como el disco `local` con su `storage/app`), no declara
         * `url` y **no** tiene enlace simbólico en `public/`. Ninguna URL del servidor web lo alcanza: el
         * único camino a un archivo es la ruta autorizada del API, que comprueba el `reg_medico` del
         * contexto contra el dueño de la fila. La decisión y su porqué están en
         * `App\Clinica\Archivos\ArchivosClinicos` y en la wiki `07-modelo-de-datos.md`.
         */
        'clinica' => [
            'driver' => 'local',
            'root' => storage_path('app/clinica'),
            'visibility' => 'private',
        ],

        /*
         * **Las imágenes adjuntas a un comentario** de "Ayúdanos a mejorar" (O-25): la captura que el usuario
         * pega o elige.
         *
         * Mismo criterio que `clinica` y por el mismo motivo: una captura puede traer datos de un paciente, así
         * que vive **fuera de `public/`** (`storage/app/comentarios`), sin `url` y sin enlace simbólico. El único
         * camino al archivo es la ruta autorizada: el **autor** del comentario en «Mis comentarios» o quien
         * atiende la bandeja en `/admin`. Es un disco aparte del clínico porque estos archivos no son parte de
         * la historia clínica y se borran cuando el reporte se descarta; la decisión está en
         * `App\Clinica\Ayuda\ArchivosDeLosComentarios`.
         */
        'comentarios' => [
            'driver' => 'local',
            'root' => storage_path('app/comentarios'),
            'visibility' => 'private',
        ],

        /*
         * **La foto del carnet de una solicitud de registro de médico** (ROADMAP §10.4, fase 2). Privado, igual
         * que los adjuntos de los comentarios: el único camino al archivo es la ruta de la bandeja de `/admin`.
         * Ver `App\RegistroDeMedicos\CarnetDeLaSolicitud`.
         */
        'solicitudes' => [
            'driver' => 'local',
            'root' => storage_path('app/solicitudes'),
            'visibility' => 'private',
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];

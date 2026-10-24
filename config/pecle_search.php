<?php

/*
 * PecleSearch — corrección silenciosa de errores de tecleo en el buscador.
 *
 * El paquete trae los valores por defecto (`vendor/edyjen/pecle-search/config/pecle_search.php`)
 * y esta configuración sólo los que AppDDR decide distinto: qué se indexa y cómo se mantiene.
 *
 * El **índice no es la búsqueda**: el listado de pacientes sigue resolviendo por `LIKE` con
 * `BusquedaDePacientes` (por palabras, sin acentos y con el n.º de historia), porque así encuentra
 * por cualquier parte de la palabra —no sólo por el comienzo— y no depende de que el índice esté
 * construido. El índice de acá es el **vocabulario del corrector**: con los nombres ya escritos
 * en el consultorio, "Gonzales" se busca como "gonzalez" y la búsqueda de siempre encuentra a
 * González.
 */
return [

    'enabled' => env('PECLE_SEARCH_ENABLED', true),

    'indexes' => [
        // El nombre del paciente, que es lo que se escribe mal al buscarlo. La cédula y el n.º de
        // historia no entran: el corrector no toca palabras con dígitos y el `LIKE` ya los encuentra.
        'pacientes' => [
            ['model' => App\Models\Paciente::class, 'columns' => ['nombres', 'apellidos']],
        ],
    ],

    // **Apagada a propósito.** La sincronización del paquete reindexa en el `saved` de cada modelo
    // y, con la cola en `sync`, además reconstruye el vocabulario entero en el mismo request: la
    // carga inicial del escritorio crea miles de pacientes en una transacción y pagaría ese costo
    // por fila. El índice se reconstruye con `php artisan pecle-search:rebuild pacientes`
    // (el despliegue lo corre una vez; después, a demanda o con un cron nocturno).
    'sync' => [
        'enabled' => false,
        'vocabulary_delay_seconds' => 30,
    ],
];

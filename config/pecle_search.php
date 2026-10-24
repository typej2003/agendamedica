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

    // **Encendida.** Un guardado suelto —la web, el móvil, la subida incremental del escritorio— reindexa
    // ese paciente y refresca el vocabulario en el acto (~30 ms medidos con 6.000 pacientes): si el médico
    // crea o corrige un paciente, el corrector ya lo conoce. Lo que queda afuera son las **cargas
    // masivas**: `CargaInicialService` envuelve el lote en `Paciente::withoutEvents()` y, al completarse
    // la carga, `CargaInicialCompletada` manda a reconstruir el índice después de responder. El rebuild
    // nocturno (`Kernel::schedule`) sigue siendo la red de lo que entre sin eventos de Eloquent.
    'sync' => [
        'enabled' => true,
        'vocabulary_delay_seconds' => 30,
    ],
];

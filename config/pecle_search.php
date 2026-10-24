<?php

/*
 * PecleSearch — la búsqueda de pacientes por tokens y la corrección de errores de tecleo.
 *
 * El paquete trae los valores por defecto (`vendor/edyjen/pecle-search/config/pecle_search.php`)
 * y esta configuración sólo los que AppDDR decide distinto: qué se indexa y cómo se mantiene.
 *
 * El **índice es la búsqueda**: `BusquedaDePacientes` resuelve cada palabra contra `search_tokens`
 * (tiene que ser el comienzo de una palabra indexada), así no hay ningún `LIKE` sobre `pacientes`
 * —que con millones de filas es un full scan—. El corrector ayuda a que una palabra escrita mal
 * llegue a la que existe. El `LIKE` queda sólo como respaldo mientras el índice no esté construido.
 *
 * ⚠️ La comparación con `search_tokens.entity_id` **necesita un `CAST` del id del paciente a texto**:
 * sin él, el motor no usa el índice y la consulta se vuelve un producto cartesiano (medido: 15 s en
 * SQLite y >8 s en MariaDB con 200.000 pacientes). Ver `BusquedaDePacientes::porTokens()`.
 */
return [

    'enabled' => env('PECLE_SEARCH_ENABLED', true),

    'indexes' => [
        // Nombres y apellidos: el vocabulario grande, lo que se escribe mal y lo que hay que indexar.
        // La cédula y el n.º de historia **no** van acá: son números y se buscan por prefijo (cédula) o
        // por contiene (historia) sobre las filas del consultorio, que son unas pocas miles y ya son la
        // tabla que manda en el plan. Así el índice no engorda un token por paciente ni cambia de forma
        // cuando esto se despliega sobre un índice viejo.
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

<?php

namespace App\Providers;

use App\Events\ActividadWebRegistrada;
use App\Events\CargaInicialCompletada;
use App\Events\EscritorioSincronizo;
use App\Events\MedicoRegistrado;
use App\Listeners\GuardarUltimaActividadWeb;
use App\Listeners\OtorgarAnioPowerBuilder;
use App\Listeners\OtorgarServicioDePrueba;
use App\Listeners\ReconstruirIndiceDePacientesTrasLaCarga;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
        // Planes de servicio: prueba gratis al registrarse y año de cortesía para quien viene del escritorio.
        MedicoRegistrado::class => [
            OtorgarServicioDePrueba::class,
        ],
        EscritorioSincronizo::class => [
            OtorgarAnioPowerBuilder::class,
        ],
        // Última actividad web (sección "API Keys"): el listener es `ShouldQueue`.
        ActividadWebRegistrada::class => [
            GuardarUltimaActividadWeb::class,
        ],
        // Carga inicial del escritorio: los pacientes no se indexaron uno por uno (PecleSearch), así que
        // al terminar se rehace el índice del buscador después de responder.
        CargaInicialCompletada::class => [
            ReconstruirIndiceDePacientesTrasLaCarga::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}

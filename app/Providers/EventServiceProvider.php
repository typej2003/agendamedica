<?php

namespace App\Providers;

use App\Events\EscritorioSincronizo;
use App\Events\MedicoRegistrado;
use App\Listeners\OtorgarAnioPowerBuilder;
use App\Listeners\OtorgarServicioDePrueba;
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

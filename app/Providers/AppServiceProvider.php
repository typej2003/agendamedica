<?php

namespace App\Providers;

use App\Http\View\Composers\ArmazonClinicaComposer;
use App\Http\View\Composers\TourDeLaPantallaComposer;
use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Tablero de Laravel Pulse (`/pulse`, 2026-10-24). Pulse define su propio gate `viewPulse`, que
        // deja entrar sólo en `local`; acá se pisa para que la regla sea la del proyecto y valga en
        // cualquier entorno: **únicamente el rol Root**. El resto de las cuentas recibe 403, sin listas
        // de correos. Se admite un usuario nulo porque el middleware de Pulse autoriza antes del login.
        Gate::define('viewPulse', function (?User $user = null) {
            return $user !== null && $user->hasRole('Root');
        });

        // La paginación por defecto de Laravel es la de Tailwind. En las pantallas que **no** son Livewire
        // (por ejemplo la lista de pacientes del consultorio) eso deja los SVG de las flechas sin tamaño:
        // se estiraban a ~1600px y la página terminaba en un bloque gigante. Los componentes Livewire ya
        // fijan `$paginationTheme = 'bootstrap'` uno por uno; esto fija el valor por defecto para el resto.
        Paginator::useBootstrap();

        // El armazón de la web clínica (barra, accesos y menú) lo arma un ViewModel, no el layout.
        View::composer('clinica.layout', ArmazonClinicaComposer::class);
        View::composer('clinica.layout', \App\Http\View\Composers\PasoEsencialComposer::class);

        // El tour guiado de la pantalla (A-15) también: el layout lo pinta si la ruta tiene uno y el usuario
        // todavía no lo vio. Los controladores de las pantallas no se tocan. Desde O-31 la **consulta** recibe
        // `$tour` además en su propia vista: su botón de reabrir va dentro de la fila del encabezado, junto a
        // Guardar y Terminar, y no en la fila de arriba (que ahí no se pinta).
        View::composer(['clinica.layout', 'clinica.consulta.ver'], TourDeLaPantallaComposer::class);

        // La tarjeta «Cargar el catálogo inicial» de las pantallas de catálogo (WEB-12.8): sale de la ruta y del contexto, sin tocar cada controlador.
        View::composer('clinica.catalogos.*', \App\Http\View\Composers\NovedadesDeCatalogoComposer::class);
    }
}

<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
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
        // La paginación por defecto de Laravel es la de Tailwind. En las pantallas que **no** son Livewire
        // (por ejemplo la lista de pacientes del consultorio) eso deja los SVG de las flechas sin tamaño:
        // se estiraban a ~1600px y la página terminaba en un bloque gigante. Los componentes Livewire ya
        // fijan `$paginationTheme = 'bootstrap'` uno por uno; esto fija el valor por defecto para el resto.
        Paginator::useBootstrap();
    }
}

<?php

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as Middleware;

class EncryptCookies extends Middleware
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array
     */
    protected $except = [
        // La regionalización del dispositivo (WEB-13.5): la escribe el navegador y la valida el middleware.
        \App\Support\Regionalizacion::COOKIE,
    ];
}

<?php

/*
|--------------------------------------------------------------------------
| Autenticación en español (O-15)
|--------------------------------------------------------------------------
|
| Los textos de `Auth::routes()` (el registro, la verificación de correo y el restablecimiento). El login de
| la app tiene sus propios mensajes en español; estos cubren el resto de la familia, que con `locale = es`
| mostraría claves crudas si faltaran.
|
*/

return [

    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña es incorrecta.',
    'throttle' => 'Demasiados intentos. Vuelve a intentarlo en :seconds segundos.',

];

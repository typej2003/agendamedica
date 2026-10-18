<?php

/*
|--------------------------------------------------------------------------
| Contraseñas en español (O-15)
|--------------------------------------------------------------------------
|
| La app usa `Auth::routes(['login' => false])`, así que el «olvidé mi contraseña» y el restablecimiento
| siguen vivos y sus mensajes salen de estos textos. Con `locale = es` y sin este archivo, la pantalla
| mostraría la clave (`passwords.sent`).
|
*/

return [

    'reset' => 'Tu contraseña fue restablecida.',
    'sent' => 'Te enviamos por correo el enlace para restablecer tu contraseña.',
    'throttled' => 'Espera un momento antes de volver a intentarlo.',
    'token' => 'El enlace para restablecer la contraseña no es válido o ya venció.',
    'user' => 'No encontramos ningún usuario con ese correo.',

];

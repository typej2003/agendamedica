{{-- La plantilla de una fila del editor de récipes: `consulta-recipes.js` la clona al agregar un medicamento, así que
     el marcado de una fila vive una sola vez (el mismo que pintan las filas del servidor, `_recipes-fila`).

     La comparten el editor de récipes de la consulta (A-04), el de la pareja y el del catálogo de tratamientos
     (WEB-8.1a), que es el mismo editor. Recibe `$seccion` para los topes de la fila. --}}
<template id="recipe-fila-plantilla">
    @include('clinica.consulta._recipes-fila', ['fila' => ['clave' => '__CLAVE__', 'codigo' => '', 'nombre' => '', 'generico' => null, 'cantidad' => '1', 'indicaciones' => '']])
</template>

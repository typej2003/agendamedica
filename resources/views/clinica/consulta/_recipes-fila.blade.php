{{-- Un medicamento del récipe que se edita (WEB-5.1): la cantidad y las indicaciones son lo único que se
     escribe; el código viaja oculto y el nombre es sólo de pantalla (el servidor lo vuelve a resolver contra
     el vademécum y nunca lo toma del formulario).

     Lo usan dos cosas con **el mismo marcado**: las filas que el servidor pinta y la plantilla que el script
     clona al agregar un medicamento (`__CLAVE__` es la clave de la fila dentro del formulario). Así el script
     no arma HTML. --}}
<li class="recipe-fila" data-recipe-fila data-codigo="{{ $fila['codigo'] }}">
    <input type="hidden" name="items[{{ $fila['clave'] }}][codemedicina]" value="{{ $fila['codigo'] }}">
    <input type="hidden" name="items[{{ $fila['clave'] }}][nombre]" value="{{ $fila['nombre'] }}">

    <div class="recipe-fila-datos">
        <span class="recipe-nombre" data-recipe-nombre>{{ $fila['nombre'] }}</span>
        <span class="texto-tenue small recipe-generico" data-recipe-generico>{{ $fila['generico'] }}</span>

        <div class="recipe-fila-campos">
            <div class="recipe-cantidad-campo">
                <label class="form-label" for="recipe-cantidad-{{ $fila['clave'] }}">Cantidad</label>
                <input type="number" inputmode="numeric" class="form-control form-control-sm"
                       id="recipe-cantidad-{{ $fila['clave'] }}" name="items[{{ $fila['clave'] }}][cantidad]"
                       min="1" max="{{ $seccion->cantidadMaxima() }}" value="{{ $fila['cantidad'] }}">
            </div>
            <div class="recipe-indicaciones-campo">
                <label class="form-label" for="recipe-indicaciones-{{ $fila['clave'] }}">Indicaciones</label>
                <textarea class="form-control form-control-sm" rows="2"
                          id="recipe-indicaciones-{{ $fila['clave'] }}" name="items[{{ $fila['clave'] }}][indicaciones]"
                          maxlength="{{ $seccion->largoIndicaciones() }}"
                          placeholder="Cómo se toma, cada cuánto y por cuántos días">{{ $fila['indicaciones'] }}</textarea>
            </div>
        </div>
    </div>

    {{-- El orden del medicamento, sólo donde importa (los tratamientos del catálogo, WEB-8.1a): el orden de la lista
         es el `orden` que se guarda. --}}
    @if ($seccion->ofreceOrdenar())
        <div class="recipe-orden" role="group" aria-label="Orden de {{ $fila['nombre'] }}">
            <button type="button" class="btn btn-sm btn-outline-secondary" data-recipe-subir
                    title="Subir «{{ $fila['nombre'] }}»" aria-label="Subir {{ $fila['nombre'] }}">
                <i class="bi bi-arrow-up" aria-hidden="true"></i>
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-recipe-bajar
                    title="Bajar «{{ $fila['nombre'] }}»" aria-label="Bajar {{ $fila['nombre'] }}">
                <i class="bi bi-arrow-down" aria-hidden="true"></i>
            </button>
        </div>
    @endif

    {{-- "Quitar" saca el medicamento del récipe **en edición**: no borra nada hasta que se guarda, y no toca
         el vademécum ni el tratamiento de donde vino. --}}
    <button type="button" class="btn btn-sm btn-outline-danger recipe-quitar" data-recipe-quitar
            title="Quitar «{{ $fila['nombre'] }}» del {{ $seccion->ofreceOrdenar() ? 'tratamiento' : 'récipe' }}"
            aria-label="Quitar {{ $fila['nombre'] }} del {{ $seccion->ofreceOrdenar() ? 'tratamiento' : 'récipe' }}">
        <i class="bi bi-trash" aria-hidden="true"></i>
    </button>
</li>

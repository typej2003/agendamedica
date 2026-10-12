{{-- Los datos del membrete (WEB-8.2): especialidad, RIF y las tres líneas de pie, que son lo que la hoja imprime de `evolucion`.
     El nombre del médico sale de su cuenta y no se edita acá. El aviso de "hay cambios sin guardar" y el del navegador al salir
     los pone `formato-impresion.js` a partir de los `data-*`. --}}
<x-ui.tarjeta titulo="Datos del membrete" icono="bi-card-heading" class="mb-3" id="formato-membrete">
    <form method="POST" action="{{ $vm->urlGuardarMembrete() }}" data-formato-form data-aviso="{{ $vm->avisoSinGuardar() }}">
        @csrf

        <p class="texto-tenue small mb-3">
            Tu nombre sale de tu cuenta: <strong>{{ $vm->nombreDelMedico() }}</strong>. Aquí escribes lo demás.
        </p>

        <div class="row g-3">
            @foreach ($vm->camposDelMembrete() as $campo)
                <x-ui.campo :etiqueta="$campo['etiqueta']" :for="'membrete-' . $campo['campo']" :error="$campo['campo']"
                            :ayuda="$campo['ayuda']" :class="$campo['columnas']">
                    <input type="text" name="{{ $campo['campo'] }}" id="membrete-{{ $campo['campo'] }}" class="form-control"
                           maxlength="{{ $campo['maximo'] }}" value="{{ $campo['valor'] }}">
                </x-ui.campo>
            @endforeach
        </div>

        @include('clinica.formato._guardar', ['texto' => 'Guardar los datos'])
    </form>
</x-ui.tarjeta>

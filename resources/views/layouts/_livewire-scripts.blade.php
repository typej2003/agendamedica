{{--
    Tag de Livewire 3 armado a mano.

    `@livewireScripts` genera `src="/livewire/livewire.js"` y `data-update-uri="/livewire/update"`:
    rutas relativas a la raíz del dominio. En QA la app vive bajo `/medic-report`, así que el
    navegador pegaba en `https://ddrsistemas.com/livewire/...` y todo el AJAX de Livewire daba 404.
    Con `url()` las dos URLs respetan la subcarpeta (y en local quedan en la raíz, como siempre).

    El archivo también cambia con `APP_DEBUG`: Livewire 3 registra `/livewire/livewire.js` con debug
    encendido y **`/livewire/livewire.min.js`** con debug apagado (producción). Hay que pedir el mismo
    que registró Livewire o el script da 404.

    El `?id=` es el hash del manifest que usa Livewire para invalidar la caché del script.
--}}
@php
    $manifiestoLivewire = json_decode(@file_get_contents(base_path('vendor/livewire/livewire/dist/manifest.json')), true) ?: [];
    $versionLivewire = $manifiestoLivewire['/livewire.js'] ?? 'dev';
    $archivoLivewire = config('app.debug') ? 'livewire.js' : 'livewire.min.js';
    $sinBarraDeProgreso = ! config('livewire.navigate.show_progress_bar', true);
@endphp
<script src="{{ url('/livewire/'.$archivoLivewire) }}?id={{ $versionLivewire }}"
        data-csrf="{{ csrf_token() }}"
        data-update-uri="{{ url('/livewire/update') }}"
        @if ($sinBarraDeProgreso) data-no-progress-bar @endif
        data-navigate-once="true"></script>

@props(['izquierda', 'derecha', 'tituloIzquierda', 'tituloDerecha', 'resumen' => null, 'accionesIzquierda' => null])

{{-- «Dos listas con Pasar / Eliminar / Agregar» (WEB-11.3): a la izquierda lo de **esta consulta**, a la derecha **el catálogo
     completo a la vista** (paginado, con buscador). El slot opcional `accionesIzquierda` va en la esquina derecha de la cabecera de la izquierda (p. ej. «Anterior»). Es sólo el armazón de las dos columnas; cada lista la arma la sección.

     Convención de las filas del catálogo: una fila con `data-pasar` que contiene el botón de «Pasar» (`data-pasar-boton`);
     el doble clic en la fila lo acciona, como el doble clic del escritorio (`dos-listas.js`). En celular las listas se apilan. --}}
<div {{ $attributes->merge(['class' => 'dos-listas']) }}>
    <section class="dos-listas-columna" aria-label="{{ $tituloIzquierda }}">
        <header class="dos-listas-cabecera">
            <h3 class="dos-listas-titulo">{{ $tituloIzquierda }}</h3>
            @if ($resumen)
                <span class="texto-tenue small">{{ $resumen }}</span>
            @endif
            @if ($accionesIzquierda)
                <span class="dos-listas-acciones ms-auto">{{ $accionesIzquierda }}</span>
            @endif
        </header>
        {{ $izquierda }}
    </section>

    <section class="dos-listas-columna" aria-label="{{ $tituloDerecha }}">
        <header class="dos-listas-cabecera">
            <h3 class="dos-listas-titulo">{{ $tituloDerecha }}</h3>
        </header>
        {{ $derecha }}
    </section>
</div>

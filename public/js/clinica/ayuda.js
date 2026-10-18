/**
 * **Las capturas de "Ayúdanos a mejorar"** (O-25): pegarlas del portapapeles o elegirlas, verlas como
 * miniaturas y quitarlas antes de mandar el comentario.
 *
 * El camino principal es **pegar**: un `paste` en el campo del comentario trae `clipboardData.items`, se
 * toman las imágenes y se agregan a la lista. Elegir archivos es la alternativa. Nada de librerías.
 *
 * Cómo viaja lo pegado al servidor: el `<input type="file" name="imagenes[]">` es el único que el formulario
 * manda, así que la lista de adjuntos vive en un `DataTransfer` y se copia al campo cada vez que cambia. Una
 * captura pegada se envía igual que una elegida, sin un endpoint aparte ni base64.
 *
 * La validación de acá es sólo para no dejar armar algo que el servidor va a rechazar (cantidad, tamaño y
 * tipo); la de verdad la hace el controlador con la política de archivos. Si el JS no corre, el campo sigue
 * funcionando solo.
 */
(function () {
    'use strict';

    var formulario = document.querySelector('[data-ayuda]');

    if (!formulario) {
        return;
    }

    var campo = formulario.querySelector('[data-ayuda-archivo]');
    var lista = formulario.querySelector('[data-ayuda-lista]');
    var aviso = formulario.querySelector('[data-ayuda-aviso]');

    if (!campo || !lista) {
        return;
    }

    var MAXIMO = 3;
    var MAXIMO_BYTES = 5 * 1024 * 1024;
    var TIPOS = ['image/png', 'image/jpeg', 'image/webp'];

    /** Los archivos elegidos o pegados, en orden. Es la fuente de lo que se pinta y de lo que se envía. */
    var adjuntos = [];

    /** El texto del cartel de error; `null` lo esconde. */
    function avisar(texto) {
        if (!aviso) {
            return;
        }

        var cuerpo = aviso.querySelector('span');

        if (cuerpo) {
            cuerpo.textContent = texto || '';
        }

        aviso.hidden = !texto;
    }

    /** Copia la lista al campo de archivo: es lo que el formulario manda. */
    function sincronizarCampo() {
        try {
            var transferencia = new DataTransfer();

            adjuntos.forEach(function (archivo) {
                transferencia.items.add(archivo);
            });

            campo.files = transferencia.files;
        } catch (error) {
            // Un navegador viejo sin `DataTransfer` deja el campo como estaba: lo elegido por el selector sigue
            // viajando y lo pegado, no. El servidor valida igual.
        }
    }

    function quitar(indice) {
        var archivo = adjuntos[indice];

        if (archivo && archivo.__vista) {
            URL.revokeObjectURL(archivo.__vista);
        }

        adjuntos.splice(indice, 1);
        avisar(null);
        pintar();
    }

    /** Vuelve a pintar las miniaturas y sincroniza el campo. */
    function pintar() {
        lista.textContent = '';

        adjuntos.forEach(function (archivo, indice) {
            if (!archivo.__vista) {
                archivo.__vista = URL.createObjectURL(archivo);
            }

            var elemento = document.createElement('li');
            elemento.className = 'adjunto';

            var enlace = document.createElement('a');
            enlace.href = archivo.__vista;
            enlace.target = '_blank';
            enlace.rel = 'noopener';
            enlace.setAttribute('aria-label', 'Abrir la captura ' + (indice + 1) + ' de ' + adjuntos.length);

            var imagen = document.createElement('img');
            imagen.className = 'adjunto-miniatura';
            imagen.src = archivo.__vista;
            imagen.alt = 'Captura adjunta ' + (indice + 1) + ': ' + (archivo.name || 'imagen');

            enlace.appendChild(imagen);
            elemento.appendChild(enlace);

            var nombre = document.createElement('span');
            nombre.className = 'adjunto-nombre texto-una-linea';
            nombre.textContent = archivo.name || 'Captura pegada';
            elemento.appendChild(nombre);

            var boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'adjunto-quitar';
            boton.setAttribute('aria-label', 'Quitar la captura ' + (indice + 1) + ': ' + (archivo.name || 'captura pegada'));
            boton.innerHTML = '<i class="bi bi-x-lg" aria-hidden="true"></i>';
            boton.addEventListener('click', function () {
                quitar(indice);
            });

            elemento.appendChild(boton);
            lista.appendChild(elemento);
        });

        sincronizarCampo();
    }

    /** ¿Ya está ese mismo archivo? (pegar dos veces la misma captura no la duplica) */
    function yaEsta(archivo) {
        return adjuntos.some(function (otro) {
            return otro.name === archivo.name && otro.size === archivo.size && otro.lastModified === archivo.lastModified;
        });
    }

    function agregar(nuevos) {
        avisar(null);

        nuevos.forEach(function (archivo) {
            if (adjuntos.length >= MAXIMO) {
                avisar('Podés adjuntar hasta ' + MAXIMO + ' imágenes por comentario.');
                return;
            }

            if (TIPOS.indexOf(archivo.type) === -1) {
                avisar('Sólo se pueden adjuntar imágenes PNG, JPG o WEBP.');
                return;
            }

            if (archivo.size > MAXIMO_BYTES) {
                avisar('«' + (archivo.name || 'La imagen') + '» pesa más de 5 MB.');
                return;
            }

            if (yaEsta(archivo)) {
                return;
            }

            // El nombre de una captura pegada llega como "image.png" (o vacío): se le pone uno para que la
            // miniatura y el nombre en el servidor digan algo.
            if (!archivo.name) {
                try {
                    Object.defineProperty(archivo, 'name', {
                        value: 'captura-' + (adjuntos.length + 1) + '.png',
                    });
                } catch (error) {
                    // Un `File` que no se deja renombrar sigue viajando igual: el servidor no usa el nombre.
                }
            }

            adjuntos.push(archivo);
        });

        pintar();
    }

    /** Lo que trae el portapapeles: las imágenes, si hay. */
    function imagenesDelPortapapeles(evento) {
        var datos = evento.clipboardData || window.clipboardData;
        var encontradas = [];

        if (!datos) {
            return encontradas;
        }

        if (datos.items) {
            for (var i = 0; i < datos.items.length; i++) {
                var item = datos.items[i];

                if (item.kind === 'file' && TIPOS.indexOf(item.type) !== -1) {
                    var archivo = item.getAsFile();

                    if (archivo) {
                        encontradas.push(archivo);
                    }
                }
            }
        }

        return encontradas;
    }

    // El `paste` se escucha en el formulario: pegar en el campo del comentario (o en cualquier campo de acá)
    // burbujea hasta él. Si lo que se pega es texto, no se toca nada; si son imágenes, se adjuntan y se evita
    // que se pegue además el "image/png" como texto.
    formulario.addEventListener('paste', function (evento) {
        var imagenes = imagenesDelPortapapeles(evento);

        if (!imagenes.length) {
            return;
        }

        evento.preventDefault();
        agregar(imagenes);
    });

    // Elegir archivos **agrega** a lo que ya hay (y a lo pegado): la lista es una sola y tiene el tope de 3.
    campo.addEventListener('change', function () {
        agregar(Array.prototype.slice.call(campo.files || []));
    });

    // Antes de enviar se vuelve a copiar la lista al campo: es lo que viaja en el `POST`.
    formulario.addEventListener('submit', function () {
        sincronizarCampo();
    });
})();

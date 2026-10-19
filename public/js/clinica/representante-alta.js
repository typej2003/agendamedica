/*
 * El **representante del alta de pediatría** (2026-10-19): un representante puede tener varios hijos.
 *
 * La tarjeta vive en `clinica/pacientes/_representante-alta.blade.php` y sólo se incluye en el alta de pediatría. Al pulsar
 * «Buscar» —o Enter en la cédula— se le pide al servidor el representante de esa cédula (`clinica.pacientes.representante.buscar`):
 * si ya está cargado en el consultorio (porque es el padre o la madre de un hermano), se rellenan los campos y se listan los
 * otros pacientes de los que ya es representante. La búsqueda es **explícita** y lo que rellena se puede corregir: el servidor
 * vuelve a resolver la cédula al guardar.
 *
 * La vista no lleva JS inline (Docs/Web/guia-arquitectura-web.md): declara el botón con `data-representante-buscar` —que trae
 * la URL— y el contenedor `data-representante-hijos`.
 *
 * Todo lo que se pinta se arma con `textContent`: lo que devuelve el servidor son nombres de pacientes y nunca se interpreta
 * como HTML.
 */
(function () {
    'use strict';

    const boton = document.querySelector('[data-representante-buscar]');
    if (!boton) return;

    const url = boton.getAttribute('data-representante-buscar') || '';
    const cedula = document.getElementById('representante-cedula');
    const resultado = document.querySelector('[data-representante-hijos]');

    if (!url || !cedula || !resultado) return;

    const campos = {
        nombre: document.getElementById('representante-nombre'),
        parentesco: document.getElementById('representante-parentesco'),
        telefono: document.getElementById('representante-telefono'),
        email: document.getElementById('representante-email'),
        direccion: document.getElementById('representante-direccion'),
    };

    // Un aviso en el contenedor del resultado: `tono` es la clase del texto (`texto-tenue` o `campo-error`).
    function avisar(texto, tono) {
        resultado.textContent = '';

        const parrafo = document.createElement('p');
        parrafo.className = (tono || 'texto-tenue') + ' mb-0';
        parrafo.textContent = texto;
        resultado.appendChild(parrafo);
    }

    // «También es representante de:» con el enlace a la ficha de cada hijo y su número de historia.
    function pintarHijos(hijos) {
        if (!hijos.length) {
            avisar('No es representante de ningún otro paciente todavía.');
            return;
        }

        resultado.textContent = '';

        const titulo = document.createElement('p');
        titulo.className = 'mb-1';
        const fuerte = document.createElement('strong');
        fuerte.textContent = 'También es representante de:';
        titulo.appendChild(fuerte);

        const lista = document.createElement('ul');
        lista.className = 'list-unstyled mb-0';

        hijos.forEach(function (hijo) {
            const item = document.createElement('li');

            if (hijo.url) {
                const enlace = document.createElement('a');
                enlace.href = hijo.url;
                enlace.textContent = hijo.nombre;
                item.appendChild(enlace);
            } else {
                item.appendChild(document.createTextNode(hijo.nombre));
            }

            if (hijo.historia) {
                const numero = document.createElement('span');
                numero.className = 'texto-tenue small';
                numero.textContent = ' · H. ' + hijo.historia;
                item.appendChild(numero);
            }

            lista.appendChild(item);
        });

        resultado.appendChild(titulo);
        resultado.appendChild(lista);
    }

    // Rellena los campos con lo encontrado. Se escribe sólo lo que el servidor trae: lo que el usuario ya había escrito no se
    // borra por un dato que el representante cargado no tenga (el servidor vuelve a resolver lo que falte al guardar).
    function rellenar(datos) {
        Object.keys(campos).forEach(function (campo) {
            const control = campos[campo];
            const valor = datos[campo];

            if (!control || valor === null || valor === undefined || valor === '') return;

            if (campo === 'parentesco') {
                const opcion = Array.prototype.find.call(control.options, function (cada) {
                    return cada.value === String(valor);
                });
                if (opcion) control.value = opcion.value;
                return;
            }

            control.value = valor;
        });
    }

    function buscar() {
        const escrito = (cedula.value || '').trim();

        if (escrito === '') {
            avisar('Escribe la cédula del representante para buscarlo.', 'texto-tenue');
            return;
        }

        boton.disabled = true;
        avisar('Buscando…');

        fetch(url + '?cedula=' + encodeURIComponent(escrito), { headers: { Accept: 'application/json' } })
            .then(function (respuesta) {
                if (!respuesta.ok) throw new Error('respuesta ' + respuesta.status);
                return respuesta.json();
            })
            .then(function (datos) {
                if (!datos.encontrado) {
                    avisar('No se encontró: se puede cargar como nuevo.');
                    return;
                }

                rellenar(datos);
                pintarHijos(datos.hijos || []);
            })
            .catch(function () {
                avisar('No se pudo buscar el representante. Revisa la cédula y prueba otra vez.', 'campo-error');
            })
            .finally(function () {
                boton.disabled = false;
            });
    }

    boton.addEventListener('click', buscar);

    // Enter en la cédula busca, en vez de enviar el formulario del alta (el alta se guarda con su botón).
    cedula.addEventListener('keydown', function (evento) {
        if (evento.key === 'Enter') {
            evento.preventDefault();
            buscar();
        }
    });
})();

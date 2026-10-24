/*
 * La máscara de las fechas que se teclean (O-18 y O-19).
 *
 * La FUR del eco obstétrico es un **campo de texto** —como en el escritorio, donde se teclea— y su columna
 * es `date`. Sin máscara se podía escribir cualquier cosa y el servidor la rechazaba (o, en SQLite, la
 * guardaba tal cual): la máscara pone las barras sola y **no deja escribir** un mes 13 ni un día 32.
 *
 * Mismo criterio que la máscara de la talla y la tensión (O-08): **la máscara es de la pantalla**; el dato
 * que viaja y se guarda conserva su formato. Acá ese formato es `AAAA-MM-DD`, y la conversión
 * `dd/mm/aaaa` → `AAAA-MM-DD` la hace el servidor en un solo lugar (`App\Support\FechaDeFormulario`), no
 * este archivo: el campo manda lo que el médico ve.
 *
 * Se declara en la vista con `data-mascara="fecha"` y no necesita ninguna librería:
 *
 *  - Se escribe de adelante hacia atrás sobre los ocho dígitos de la fecha; los dígitos imposibles se
 *    descartan en el momento (`32` no entra, `13` de mes tampoco) en vez de "corregirse" a otra fecha —eso
 *    inventaría un dato clínico—.
 *  - Un día o un mes que no puede crecer más se completa con su cero y la escritura sigue en el siguiente
 *    grupo: teclear `9` deja `09/` y teclear `5` después deja `09/05/`.
 *  - Pegar una fecha entera (`09/05/2026`, `9/5/2026` o `2026-05-09`) la carga de una vez; pegar algo que
 *    no es una fecha no rompe el campo.
 *  - Retroceso borra el último dígito y, cuando no queda ninguno, el campo queda **vacío**.
 *  - Cada cambio dispara `input`, que es lo que escuchan el aviso de cambios sin guardar y el autoguardado
 *    (`consulta.js` y `consulta-barra.js`): para ellos esto es escribir, como cualquier otra tecla.
 */
(function () {
    'use strict';

    var SELECTOR = '[data-mascara="fecha"]';

    /** Los dígitos de una fecha, de adelante hacia atrás, descartando los que no pueden existir. */
    function agregar(digitos, caracter) {
        if (!/^\d$/.test(caracter) || digitos.length >= 8) {
            return false;
        }

        var cuantos = digitos.length;

        if (cuantos === 0) {
            // Decena del día: `4`… `9` sólo pueden ser 04… 09, así que se completan y se pasa al mes.
            if (caracter > '3') {
                digitos.push('0', caracter);
            } else {
                digitos.push(caracter);
            }
            return true;
        }

        if (cuantos === 1) {
            var dia = Number(digitos[0] + caracter);
            if (dia < 1 || dia > 31) {
                return false;
            }
            digitos.push(caracter);
            return true;
        }

        if (cuantos === 2) {
            // Decena del mes: `2`… `9` sólo pueden ser 02… 09.
            if (caracter > '1') {
                digitos.push('0', caracter);
            } else {
                digitos.push(caracter);
            }
            return true;
        }

        if (cuantos === 3) {
            var mes = Number(digitos[2] + caracter);
            if (mes < 1 || mes > 12) {
                return false;
            }
            digitos.push(caracter);
            return true;
        }

        // El año: cuatro dígitos y no empieza en cero.
        if (cuantos === 4 && caracter === '0') {
            return false;
        }

        digitos.push(caracter);
        return true;
    }

    /** Los dígitos de un valor, ya filtrados por las reglas de arriba. */
    function leer(valor) {
        var digitos = [];
        var texto = String(valor == null ? '' : valor).replace(/\D/g, '').slice(0, 8);

        for (var i = 0; i < texto.length; i++) {
            agregar(digitos, texto[i]);
        }

        return digitos;
    }

    /** `09/05/2026`, con las barras puestas a medida que se escribe. */
    function dibujar(digitos) {
        var texto = digitos.join('');
        var salida = texto.slice(0, 2);

        if (texto.length > 2) {
            salida += '/' + texto.slice(2, 4);
        }

        if (texto.length > 4) {
            salida += '/' + texto.slice(4, 8);
        }

        return salida;
    }

    /** Una fecha pegada entera, si lo es: `dd/mm/aaaa`, `d/m/aaaa` o `aaaa-mm-dd`. */
    function deUnaFecha(texto) {
        var limpio = String(texto == null ? '' : texto).trim();
        var partes = limpio.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);

        if (partes) {
            return leer(partes[1] + partes[2] + partes[3]);
        }

        partes = limpio.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);

        if (partes) {
            var dia = Number(partes[3]);
            var mes = Number(partes[2]);

            if (dia < 1 || dia > 31 || mes < 1 || mes > 12) {
                return null;
            }

            return leer(
                ('0' + dia).slice(-2) + ('0' + mes).slice(-2) + partes[1],
            );
        }

        return null;
    }

    document.querySelectorAll(SELECTOR).forEach(function (campo) {
        var digitos = leer(campo.value);
        var dibujado = dibujar(digitos);
        // El último valor que escribió el script: sirve para distinguir sus propios `input` de los de afuera.
        var pintado = null;

        // Un valor que no es una fecha (un dato viejo de la base) se respeta tal cual hasta que el médico
        // toque el campo: reescribirlo acá sería esconderle lo que hay guardado.
        if (campo.value === '' || campo.value === dibujado) {
            campo.value = dibujado;
            pintado = campo.value;
        }

        function aplicar() {
            campo.value = dibujar(digitos);
            pintado = campo.value;
            campo.setSelectionRange(campo.value.length, campo.value.length);
            // `consulta.js` marca el formulario sucio con `input`, y el autoguardado también lo escucha.
            campo.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Si el campo cambia sin pasar por el teclado (un autocompletar, una prueba automática, pegar desde
        // el menú del navegador), lo que el script tiene en memoria se resincroniza **sin** reformatear lo que
        // el campo ya muestra: puede ser una fecha en el formato de la base, y reformatearla a ciegas la
        // rompería.
        campo.addEventListener('input', function () {
            if (campo.value === pintado) {
                return;
            }

            digitos = leer(campo.value);
            pintado = campo.value;
        });

        campo.addEventListener('keydown', function (evento) {
            if (evento.ctrlKey || evento.metaKey || evento.altKey || evento.isComposing) {
                return;
            }

            if (evento.key === 'Backspace' || evento.key === 'Delete') {
                // El valor lo arma este script: el navegador no puede borrar un carácter por su cuenta (las
                // barras volverían a entrar en el siguiente dibujo).
                evento.preventDefault();

                // Con todo el campo seleccionado (Ctrl+A y borrar) se vacía entero; si no, se borra el último
                // dígito, que es como se corrige una fecha tecleada.
                var todoSeleccionado = campo.value !== ''
                    && campo.selectionStart === 0
                    && campo.selectionEnd === campo.value.length;

                if (todoSeleccionado) {
                    digitos = [];
                    aplicar();
                    return;
                }

                if (digitos.length > 0) {
                    digitos.pop();
                    aplicar();
                }

                return;
            }

            if (evento.key && evento.key.length === 1) {
                evento.preventDefault();

                if (agregar(digitos, evento.key)) {
                    aplicar();
                }
            }
        });

        // El calendario (2026-10-23): la máscara se conserva para teclear, y al lado va el botón del calendario del
        // navegador. `data-max` (AAAA-MM-DD) es el último día permitido —hoy, en el caso de la FUR—: el calendario no
        // deja elegir más allá, y lo tecleado que lo pase marca el campo como inválido (el servidor lo vuelve a comprobar).
        var tope = campo.getAttribute('data-max') || '';

        function validarTope() {
            var d = leer(campo.value);
            var iso = d.length === 8 ? d.slice(4, 8).join('') + '-' + d.slice(2, 4).join('') + '-' + d.slice(0, 2).join('') : '';
            campo.setCustomValidity(tope && iso && iso > tope ? 'La fecha no puede ser futura.' : '');
        }

        campo.addEventListener('input', validarTope);
        validarTope();

        if (typeof document.createElement('input').showPicker === 'function' && !campo.readOnly) {
            // El icono va **dentro** del campo, a la derecha, como el del calendario nativo (no gasta alto).
            var envoltura = document.createElement('div');
            envoltura.style.cssText = 'position:relative';
            campo.parentNode.insertBefore(envoltura, campo);
            envoltura.appendChild(campo);
            campo.style.paddingRight = '2.5rem';

            var boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'btn btn-link p-0 border-0 text-body';
            boton.style.cssText = 'position:absolute;top:50%;right:.75rem;transform:translateY(-50%);line-height:1';
            boton.setAttribute('aria-label', 'Elegir la fecha en el calendario');
            boton.title = 'Elegir la fecha en el calendario';
            boton.innerHTML = '<i class="bi bi-calendar3" aria-hidden="true"></i>';

            var selector = document.createElement('input');
            selector.type = 'date';
            selector.tabIndex = -1;
            selector.setAttribute('aria-hidden', 'true');
            selector.style.cssText = 'position:absolute;right:0;bottom:0;opacity:0;pointer-events:none;width:1px;height:1px';
            if (tope) { selector.max = tope; }

            boton.addEventListener('click', function () {
                var actual = leer(campo.value);
                selector.value = actual.length === 8
                    ? actual.slice(4, 8).join('') + '-' + actual.slice(2, 4).join('') + '-' + actual.slice(0, 2).join('')
                    : '';
                try { selector.showPicker(); } catch (e) { selector.focus(); }
            });

            selector.addEventListener('change', function () {
                var fecha = deUnaFecha(selector.value);
                if (fecha && fecha.length > 0) {
                    digitos = fecha;
                    aplicar();
                }
            });

            envoltura.appendChild(boton);
            envoltura.appendChild(selector);
        }

        campo.addEventListener('paste', function (evento) {
            evento.preventDefault();

            var pegote = (evento.clipboardData || window.clipboardData);
            var fecha = deUnaFecha(pegote ? pegote.getData('text') : '');

            if (fecha && fecha.length > 0) {
                digitos = fecha;
                aplicar();
            }
        });
    });
})();

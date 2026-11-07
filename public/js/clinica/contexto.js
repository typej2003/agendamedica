/*
 * La pantalla de elegir consultorio (`/clinica/contexto`), por pasos (2026-11-06).
 *
 * El HTML ya trae todos los pasos: el médico (sólo si hay varios), la especialidad (si hay más de una) y el
 * consultorio. Este script es el que **esconde lo que todavía no toca** y colapsa cada elección en una línea
 * con el dato y «Cambiar». Sin JavaScript se ven todos los pasos a la vez y el consultorio se envía con su
 * formulario: el script no es el que hace funcionar la pantalla, es el que la ordena.
 *
 * No envía nada: el consultorio es un `<button type="submit">` de su formulario y el servidor resuelve el
 * `reg_medico` del consultorio (el menú junta registros distintos).
 */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-contexto]');
    if (!raiz) {
        return;
    }

    document.documentElement.classList.add('js-contexto');

    var pasoMedico = raiz.querySelector('[data-paso-medico]');
    var paneles = Array.prototype.slice.call(raiz.querySelectorAll('[data-panel-medico]'));
    var botonesMedico = Array.prototype.slice.call(raiz.querySelectorAll('[data-elegir-medico]'));

    var buscar = function (lista, fn) {
        for (var i = 0; i < lista.length; i++) {
            if (fn(lista[i])) {
                return lista[i];
            }
        }
        return null;
    };

    /* --------------------------------------------------------------- El paso del médico */

    /**
     * Muestra el panel del médico elegido (o ninguno, si se está eligiendo) y deja el paso en una línea con
     * su nombre y «Cambiar».
     */
    var elegirMedico = function (id) {
        paneles.forEach(function (panel) {
            panel.hidden = id === null || panel.dataset.panelMedico !== id;
        });

        botonesMedico.forEach(function (boton) {
            boton.setAttribute('aria-pressed', String(boton.dataset.elegirMedico === id));
        });

        if (!pasoMedico) {
            return;
        }

        if (id === null) {
            pasoMedico.removeAttribute('data-colapsado');
            return;
        }

        var boton = buscar(botonesMedico, function (b) {
            return b.dataset.elegirMedico === id;
        });
        var texto = pasoMedico.querySelector('[data-texto-elegido]');
        if (texto && boton) {
            texto.textContent = boton.dataset.nombre;
        }
        pasoMedico.setAttribute('data-colapsado', '');
    };

    botonesMedico.forEach(function (boton) {
        boton.addEventListener('click', function () {
            elegirMedico(boton.dataset.elegirMedico);

            var panel = buscar(paneles, function (p) {
                return p.dataset.panelMedico === boton.dataset.elegirMedico;
            });
            var foco = panel && (panel.querySelector('[data-paso="especialidad"] input:checked')
                || panel.querySelector('[data-paso="especialidad"] input')
                || panel.querySelector('[data-paso="consultorio"] button'));

            if (foco) {
                foco.focus();
            }
        });
    });

    if (pasoMedico) {
        var cambiarMedico = pasoMedico.querySelector('[data-cambiar]');
        if (cambiarMedico) {
            cambiarMedico.addEventListener('click', function () {
                elegirMedico(null);
                if (botonesMedico[0]) {
                    botonesMedico[0].focus();
                }
            });
        }

        // Al cargar: si el contexto ya tenía médico, se muestra ese; si no, se pregunta primero.
        var actual = raiz.dataset.medicoActual || null;
        if (actual !== null && buscar(botonesMedico, function (b) { return b.dataset.elegirMedico === actual; })) {
            elegirMedico(actual);
        } else {
            elegirMedico(null);
        }
    }

    /* ------------------------------------------------- El paso de la especialidad y el consultorio */

    paneles.forEach(function (panel) {
        var pasoEspecialidad = panel.querySelector('[data-paso="especialidad"]');
        var pasoConsultorio = panel.querySelector('[data-paso="consultorio"]');
        var radios = Array.prototype.slice.call(panel.querySelectorAll('input[name="specialty_id"]'));

        /** La especialidad marcada, o `null`. */
        var marcada = function () {
            return buscar(radios, function (radio) {
                return radio.checked;
            });
        };

        /** ¿Hay que elegir la especialidad antes de ver los consultorios? */
        var hayQueElegir = function () {
            return pasoEspecialidad !== null && radios.length > 0;
        };

        var textoDe = function (radio) {
            var etiqueta = radio.closest('.contexto-opcion');
            var titulo = etiqueta && etiqueta.querySelector('.contexto-opcion-titulo');
            return titulo ? titulo.textContent.trim() : '';
        };

        /**
         * Esconde el paso del consultorio y **deshabilita sus botones**: mientras la especialidad no esté
         * elegida, el formulario no se puede enviar por accidente (un Enter en el radio enviaría el primer
         * consultorio, que el médico ni vio).
         */
        var ponerPendiente = function (pendiente) {
            if (!pasoConsultorio) {
                return;
            }

            if (pendiente) {
                pasoConsultorio.setAttribute('data-pendiente', '');
            } else {
                pasoConsultorio.removeAttribute('data-pendiente');
            }

            Array.prototype.forEach.call(pasoConsultorio.querySelectorAll('button'), function (boton) {
                boton.disabled = pendiente;
            });
        };

        /** Colapsa la especialidad elegida y muestra los consultorios recién cuando la hay. */
        var sincronizar = function () {
            var radio = marcada();

            if (pasoEspecialidad) {
                if (radio) {
                    var texto = pasoEspecialidad.querySelector('.contexto-elegido-texto');
                    if (texto) {
                        texto.textContent = textoDe(radio);
                    }
                    pasoEspecialidad.setAttribute('data-colapsado', '');
                } else {
                    pasoEspecialidad.removeAttribute('data-colapsado');
                }
            }

            ponerPendiente(hayQueElegir() && !radio);
        };

        radios.forEach(function (radio) {
            // `change` cubre el teclado y `click` el mouse (incluso el de la especialidad ya marcada, que no
            // dispara `change`): los dos vuelven a colapsar y a mostrar los consultorios.
            var alElegir = function () {
                window.setTimeout(function () {
                    if (!radio.checked) {
                        return;
                    }

                    sincronizar();

                    // El radio se acaba de esconder dentro de la línea colapsada: el foco pasa al «Cambiar»
                    // para no perderse.
                    var cambiar = pasoEspecialidad && pasoEspecialidad.querySelector('[data-cambiar]');
                    if (cambiar) {
                        cambiar.focus();
                    }
                }, 0);
            };

            radio.addEventListener('change', alElegir);
            radio.addEventListener('click', alElegir);
        });

        if (pasoEspecialidad) {
            var cambiarEspecialidad = pasoEspecialidad.querySelector('[data-cambiar]');
            if (cambiarEspecialidad) {
                cambiarEspecialidad.addEventListener('click', function () {
                    // Se reabre la elección y el consultorio vuelve a esperar: la especialidad todavía no está
                    // decidida. No se llama a `sincronizar()` porque el radio anterior sigue marcado y lo
                    // volvería a colapsar sin dejar elegir.
                    pasoEspecialidad.removeAttribute('data-colapsado');
                    ponerPendiente(true);

                    var radio = marcada() || radios[0];
                    if (radio) {
                        radio.focus();
                    }
                });
            }
        }

        sincronizar();
    });

    /* ------------------------------------------------------- El registro del consultorio que se envía */

    Array.prototype.slice.call(raiz.querySelectorAll('form.contexto-form')).forEach(function (form) {
        form.addEventListener('submit', function (evento) {
            var boton = evento.submitter || document.activeElement;
            var oculto = form.querySelector('input[name="reg_medico"]');

            if (boton && oculto && boton.dataset && boton.dataset.regMedico) {
                oculto.value = boton.dataset.regMedico;
            }
        });
    });
})();

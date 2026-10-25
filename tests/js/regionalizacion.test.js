// node tests/js/regionalizacion.test.js
const assert = require('assert');
const r = require('../../public/js/regionalizacion.js');

// El orden y el separador salen de `Intl` del locale; el reloj, de su `hourCycle`.
assert.strictEqual(r.describir('es-VE'), 'dmy|/|12');
assert.strictEqual(r.describir('es-PA'), 'mdy|/|12');
assert.strictEqual(r.describir('es-CL'), 'dmy|-|12');
assert.strictEqual(r.describir('es-ES'), 'dmy|/|24');

// Se toma el primer idioma en español o portugués: un Chrome en inglés no pone el mes adelante.
assert.strictEqual(r.detectar({ languages: ['en-US', 'es-VE'] }), 'dmy|/|12');
assert.strictEqual(r.detectar({ languages: ['en-US'] }), null);
assert.strictEqual(r.detectar({ language: 'es-PA' }), 'mdy|/|12');

// Pintar y leer con la regionalización de la página.
const mdy = { orden: 'mdy', separador: '/', ciclo: 24 };
assert.strictEqual(r.fecha(9, 5, 2026, mdy), '05/09/2026');
assert.strictEqual(r.hora(13, 5, mdy), '13:05');
assert.strictEqual(r.hora(13, 5, { ciclo: 12 }), '1:05 PM');
assert.deepStrictEqual(r.leerFecha('05/09/2026', mdy), { anio: 2026, mes: 5, dia: 9 });
assert.deepStrictEqual(r.leerFecha('09/05/2026', { orden: 'dmy' }), { anio: 2026, mes: 5, dia: 9 });
assert.deepStrictEqual(r.leerFecha('2026-05-09', mdy), { anio: 2026, mes: 5, dia: 9 });
assert.strictEqual(r.leerFecha('31/12/2026', mdy), null);
console.log('regionalizacion: ok');

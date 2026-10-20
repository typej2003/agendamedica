// node tests/js/hora-ampm.test.js
const assert = require('assert');
const { parsearHora, alternar } = require('../../public/js/clinica/hora-ampm.js');

const casos = {
    '1pm': ['13:00', '1:00 PM'], '1:00 pm': ['13:00', '1:00 PM'], '1:30p': ['13:30', '1:30 PM'],
    '13:00': ['13:00', '1:00 PM'], '0800': ['08:00', '8:00 AM'], '800': ['08:00', '8:00 AM'], '8': ['08:00', '8:00 AM'],
    '12': ['12:00', '12:00 PM'], '12am': ['00:00', '12:00 AM'], '12:30 AM': ['00:30', '12:30 AM'], '12:30 pm': ['12:30', '12:30 PM'],
    '7': ['07:00', '7:00 AM'], '6': ['18:00', '6:00 PM'], '1': ['13:00', '1:00 PM'], '0': ['00:00', '12:00 AM'],
    '1 P.M.': ['13:00', '1:00 PM'], '11:59 pm': ['23:59', '11:59 PM'], '1130': ['11:30', '11:30 AM'], '130': ['13:30', '1:30 PM'],
};
for (const [entrada, [hhmm, texto]] of Object.entries(casos)) {
    const r = parsearHora(entrada);
    assert.ok(r, 'debía entenderse: ' + entrada);
    assert.strictEqual(r.hhmm, hhmm, entrada);
    assert.strictEqual(r.texto, texto, entrada);
}
for (const mala of ['', 'abc', '24', '8:60', '13pm', '0pm', '1:2', '99']) {
    assert.strictEqual(parsearHora(mala), null, 'debía rechazarse: ' + mala);
}
assert.strictEqual(alternar('08:15').texto, '8:15 PM');
assert.strictEqual(alternar('23:00').texto, '11:00 AM');
console.log('hora-ampm: ok');

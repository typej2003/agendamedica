window._ = require('lodash');

try {
    window.Popper = require('@popperjs/core');
    window.bootstrap = require('bootstrap');
} catch (e) {}

window.axios = require('axios');
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
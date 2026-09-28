// Interface language for the scripts: CalageLang.t('Save') and CalageLang.n('{n} image', '{n} images', count).
// The English text is the key; translations come from the page (<script id="lang-messages">, see Calage\Lang).
(function () {
    'use strict';
    var node = document.getElementById('lang-messages');
    var data = node ? JSON.parse(node.textContent) : {};
    var locale = document.documentElement.lang || 'en';
    var messages = data || {};

    function fill(text, params) {
        return params ? text.replace(/\{(\w+)\}/g, function (match, key) {
            return Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : match;
        }) : text;
    }

    function t(text, params) {
        var translation = Object.prototype.hasOwnProperty.call(messages, text) ? messages[text] : text;
        if (Array.isArray(translation)) translation = translation[0];
        return fill(translation, params);
    }

    function n(one, many, count, params) {
        var all = Object.assign({ n: count }, params || {});
        var singular = locale === 'fr' ? count < 2 : count === 1;
        var translation = Object.prototype.hasOwnProperty.call(messages, one) ? messages[one] : null;
        if (Array.isArray(translation)) return fill(translation[singular ? 0 : 1], all);
        return fill(singular ? one : many, all);
    }

    window.CalageLang = { t: t, n: n, locale: locale };
})();

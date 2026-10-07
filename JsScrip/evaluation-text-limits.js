(function () {
    'use strict';

    function countWords(value) {
        const words = String(value == null ? '' : value).match(/\S+/gu);
        return words ? words.length : 0;
    }

    function getValidationMessage(field) {
        const limit = parseInt(field.getAttribute('data-word-limit'), 10);
        return limit > 0 && countWords(field.value) > limit
            ? `Please keep your response to ${limit} words or fewer.`
            : '';
    }

    function validateAll(root, report) {
        let firstInvalid = null;
        root.querySelectorAll('textarea[data-word-limit]').forEach(field => {
            if (field.disabled || field.getAttribute('data-exception-reporting') === '1') return;
            const message = getValidationMessage(field);
            field.setCustomValidity(message);
            if (message && !firstInvalid) firstInvalid = field;
        });
        if (report && firstInvalid) firstInvalid.reportValidity();
        return !firstInvalid;
    }

    function validateInput(event) {
        const field = event.target;
        if (field.matches('textarea[data-word-limit]') && field.getAttribute('data-exception-reporting') !== '1') {
            field.setCustomValidity(getValidationMessage(field));
        }
    }

    document.addEventListener('input', validateInput);
    document.addEventListener('change', validateInput);
    window.EvaluationTextLimits = { countWords, getValidationMessage, validateAll };
})();

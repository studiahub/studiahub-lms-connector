(function () {
    'use strict';

    var errorMessages = {
        400: 'Revisá los datos ingresados.',
        401: 'No pudimos validar la solicitud. Probá de nuevo más tarde.',
        409: 'La configuración cambió. Actualizá la página y volvé a intentar.',
        413: 'Los datos enviados son demasiado grandes.',
        429: 'Hiciste varios intentos. Esperá unos minutos y volvé a probar.'
    };

    function initModal(modal) {
        var root = modal.closest('.slc-coursepitch');
        var opener = root ? root.querySelector('[data-slc-waitlist-open]') : null;
        var closeButton = modal.querySelector('[data-slc-waitlist-close]');
        var form = modal.querySelector('[data-slc-waitlist-form]');
        var fields = modal.querySelector('[data-slc-waitlist-fields]');
        var submitButton = modal.querySelector('[data-slc-waitlist-submit]');
        var status = modal.querySelector('[data-slc-waitlist-status]');
        var turnstileContainer = modal.querySelector('[data-slc-waitlist-turnstile]');
        var turnstileSiteKey = modal.getAttribute('data-turnstile-site-key') || '';
        var firstInput = form ? form.elements.fullName : null;
        var returnFocus = null;
        var submissionId = 0;
        var turnstileWidgetId = null;
        var turnstileToken = '';

        if (!opener || !closeButton || !form || !fields || !submitButton || !status) return;

        function focusableElements() {
            return Array.prototype.filter.call(
                modal.querySelectorAll('button:not([disabled]), input:not([disabled]):not([tabindex="-1"])'),
                function (element) { return !element.hidden; }
            );
        }

        function resetTurnstile() {
            turnstileToken = '';
            if (turnstileWidgetId === null || !window.turnstile || typeof window.turnstile.reset !== 'function') return;
            window.turnstile.reset(turnstileWidgetId);
        }

        function renderTurnstile() {
            if (!turnstileSiteKey || !turnstileContainer || turnstileWidgetId !== null) return;
            if (!window.turnstile || typeof window.turnstile.render !== 'function') {
                showStatus('No pudimos cargar la verificación. Actualizá la página y volvé a intentar.', false);
                return;
            }

            turnstileWidgetId = window.turnstile.render(turnstileContainer, {
                sitekey: turnstileSiteKey,
                action: 'waitlist',
                appearance: 'interaction-only',
                'response-field': false,
                callback: function (token) {
                    turnstileToken = token;
                },
                'expired-callback': resetTurnstile,
                'error-callback': function () {
                    turnstileToken = '';
                    showStatus('No pudimos completar la verificación. Probá de nuevo.', false);
                }
            });
        }

        function openModal() {
            returnFocus = opener;
            modal.hidden = false;
            document.body.classList.add('slc-waitlist-open');
            renderTurnstile();
            window.setTimeout(function () {
                if (firstInput) firstInput.focus();
            }, 0);
        }

        function closeModal() {
            submissionId += 1;
            setLoading(false);
            resetTurnstile();
            modal.hidden = true;
            document.body.classList.remove('slc-waitlist-open');
            if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
        }

        function setLoading(loading) {
            form.setAttribute('aria-busy', loading ? 'true' : 'false');
            Array.prototype.forEach.call(fields.querySelectorAll('input, button'), function (control) {
                if (control.name !== 'website') control.disabled = loading;
            });
            submitButton.textContent = loading ? 'Enviando…' : 'Anotarme';
        }

        function showStatus(message, success) {
            status.textContent = message;
            status.classList.toggle('slc-cpitch__waitlist-status--success', success);
            status.classList.toggle('slc-cpitch__waitlist-status--error', !success);
            status.focus();
        }

        opener.addEventListener('click', openModal);
        closeButton.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        modal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeModal();
                return;
            }
            if (event.key !== 'Tab') return;

            var focusable = focusableElements();
            if (!focusable.length) return;
            var first = focusable[0];
            var last = focusable[focusable.length - 1];
            if (focusable.indexOf(document.activeElement) === -1) {
                event.preventDefault();
                (event.shiftKey ? last : first).focus();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (!form.checkValidity()) {
                form.reportValidity();
                return;
            }
            if (turnstileSiteKey && !turnstileToken) {
                showStatus('Completá la verificación para continuar.', false);
                return;
            }

            status.textContent = '';
            status.className = 'slc-cpitch__waitlist-status';
            setLoading(true);
            var currentSubmissionId = ++submissionId;

            var payload = {
                fullName: form.elements.fullName.value,
                email: form.elements.email.value,
                consent: form.elements.consent.checked,
                consentVersion: modal.getAttribute('data-consent-version'),
                website: form.elements.website.value
            };
            if (turnstileSiteKey) payload.turnstileToken = turnstileToken;

            window.fetch(modal.getAttribute('data-endpoint'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function (response) {
                return response.json().catch(function () { return {}; }).then(function (data) {
                    return { response: response, data: data };
                });
            }).then(function (result) {
                if (currentSubmissionId !== submissionId) return;
                if (!result.response.ok || result.data.ok !== true) {
                    var fallback = errorMessages[result.response.status]
                        || 'No pudimos registrar tu interés. Probá de nuevo en unos minutos.';
                    throw { publicMessage: result.data.message || fallback };
                }
                form.reset();
                showStatus(result.data.message || 'Tu interés quedó registrado en la lista de espera.', true);
            }).catch(function (error) {
                if (currentSubmissionId !== submissionId) return;
                showStatus(
                    error && error.publicMessage
                        ? error.publicMessage
                        : 'No pudimos registrar tu interés. Revisá tu conexión y volvé a intentar.',
                    false
                );
            }).then(function () {
                resetTurnstile();
                if (currentSubmissionId === submissionId) setLoading(false);
            });
        });
    }

    function init() {
        document.querySelectorAll('[data-slc-waitlist-modal]').forEach(initModal);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

/* Блок ЕН ROZETKA на сторінці замовлення. Поля мають data-field (не name): вони всередині форми
   замовлення й не повинні зберігатися разом із нею; кожна дія — окремий POST у ParcelAdmin.
   Відповіді вставляються лише через textContent: текст Rozetka потрапляє сюди як є. */
(function () {
    'use strict';

    var TOAST_KEY = 'sviat_rozetka_parcel_toast';
    /** Поля форми, які підсвічує помилка поля з ParcelDraft. */
    var INPUTS = {
        sides: ['length', 'width', 'height'],
        last_name: ['last_name', 'first_name']
    };

    function rootOf(node) {
        return node.closest('.fn_rz_parcel');
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text) {
            node.textContent = text;
        }
        return node;
    }

    /** toastr 2.1.4 за замовчуванням вставляє текст як HTML, а сюди може потрапити текст Rozetka. */
    function toast(kind, text, title) {
        if (text && window.toastr) {
            window.toastr[kind](text, title, { escapeHtml: true });
        }
    }

    try {
        var waiting = JSON.parse(window.sessionStorage.getItem(TOAST_KEY) || 'null');
        window.sessionStorage.removeItem(TOAST_KEY);
        if (waiting) {
            document.addEventListener('DOMContentLoaded', function () { toast('success', waiting[0], waiting[1]); });
        }
    } catch (e) {}

    function fields(root) {
        var data = {};
        root.querySelectorAll('[data-field]').forEach(function (input) {
            if (input.disabled || (input.type === 'checkbox' && !input.checked)) {
                return;
            }
            data[input.getAttribute('data-field')] = input.value;
        });
        return data;
    }

    function clearIssues(root) {
        root.querySelectorAll('.rz-parcel__error').forEach(function (span) { span.textContent = ''; });
        root.querySelectorAll('.rz-parcel__invalid').forEach(function (input) { input.classList.remove('rz-parcel__invalid'); });
        root.querySelectorAll('.rz-parcel__issues, .rz-parcel__notices').forEach(function (box) { box.textContent = ''; });
        var candidates = root.querySelector('.rz-parcel__candidates');
        if (candidates) {
            candidates.textContent = '';
            candidates.hidden = true;
        }
    }

    /** У тості — текст сервера: блок помилок буває поза екраном. Загальний текст лише запасний. */
    function toastText(root, issues) {
        var first = issues.filter(function (issue) { return issue && issue.text; })[0];
        return first ? first.text : root.getAttribute('data-failed');
    }

    function showIssues(root, issues, box) {
        var target = box || root.querySelector('.rz-parcel__issues:not(.rz-parcel__issues--attach)');
        issues.forEach(function (issue) {
            var span = issue.field ? root.querySelector('.rz-parcel__error[data-for="' + CSS.escape(issue.field) + '"]') : null;
            if (span) {
                span.textContent = [issue.text, issue.advice].filter(Boolean).join(' ');
                (INPUTS[issue.field] || [issue.field]).forEach(function (name) {
                    var input = root.querySelector('[data-field="' + CSS.escape(name) + '"]');
                    if (input) {
                        input.classList.add('rz-parcel__invalid');
                    }
                });
                return;
            }
            if (!target) {
                return;
            }
            var alert = el('div', 'alert alert--error rz-parcel__issue');
            var content = el('div', 'alert__content');
            content.appendChild(el('p', 'text_600', root.getAttribute('data-failed')));
            content.appendChild(el('p', '', issue.text));
            if (issue.orderUrl) {
                var link = el('a', '', root.getAttribute('data-order-text'));
                link.href = issue.orderUrl;
                link.target = '_blank';
                link.rel = 'noopener';
                var line = el('p', '');
                line.appendChild(link);
                content.appendChild(line);
            }
            if (issue.advice) {
                content.appendChild(el('p', 'text_grey', issue.advice));
            }
            alert.appendChild(content);
            target.appendChild(alert);
        });
        var first = root.querySelector('.rz-parcel__invalid, .rz-parcel__issue');
        if (first && first.scrollIntoView) {
            first.scrollIntoView({ block: 'center', behavior: 'smooth' });
        }
        toast('error', toastText(root, issues), root.getAttribute('data-toast-error'));
    }

    /** Пошук знайшов кілька ЕН: менеджер вибирає сам, а ParcelAdmin перевіряє вибір ще раз. */
    function showCandidates(root, answer) {
        var box = root.querySelector('.rz-parcel__candidates');
        var notices = root.querySelector('.rz-parcel__notices');
        if (notices && answer.issues && answer.issues[0]) {
            var note = el('div', 'alert alert--warning');
            var content = el('div', 'alert__content');
            content.appendChild(el('p', '', answer.issues[0].text));
            note.appendChild(content);
            notices.appendChild(note);
        }
        if (!box) {
            return;
        }
        answer.candidates.forEach(function (candidate) {
            var row = el('div', 'rz-parcel__candidate');
            row.appendChild(el('span', '', [candidate.track, candidate.date, candidate.receiver, candidate.cost].filter(Boolean).join(' · ')));
            var choose = el('button', 'btn btn_small btn_border_blue fn_rz_parcel_choose', root.getAttribute('data-choose'));
            choose.type = 'button';
            choose.setAttribute('data-track', candidate.track);
            row.appendChild(choose);
            box.appendChild(row);
        });
        box.hidden = false;
    }

    function networkIssue(root, box) {
        showIssues(root, [{ field: null, text: root.getAttribute('data-network') }], box);
    }

    /**
     * json | pdf | session | server. 400–405 і вхід адмінки (редирект, 200 з HTML) — найчастіше прострочена
     * сесія, яку лікує лише оновлення сторінки; 5xx — збій сервера магазину, а не сесія.
     */
    function answerKind(response) {
        var type = response.headers.get('Content-Type') || '';
        if (response.status >= 500) {
            return 'server';
        }
        if (!response.ok || response.redirected) {
            return 'session';
        }
        if (type.indexOf('application/json') === 0) {
            return 'json';
        }
        return type.indexOf('application/pdf') === 0 || type.indexOf('application/zip') === 0 || isFileAttachment(response) ? 'pdf' : 'session';
    }

    /**
     * Response стоку OkayCMS 4.5.3 перезаписує Content-Type етикетки на text/plain, тож файл упізнаємо й за
     * Content-Disposition. Сторінка входу адмінки вкладенням не приходить.
     */
    function isFileAttachment(response) {
        return /^attachment;.*\.(pdf|zip)"?$/i.test(response.headers.get('Content-Disposition') || '');
    }

    function answerIssue(root, kind, box) {
        var text = kind === 'server' ? root.getAttribute('data-unexpected') : root.getAttribute('data-expired');
        showIssues(root, [{ field: null, text: text }], box);
    }

    function post(root, action, extra) {
        var body = new URLSearchParams(Object.assign({ action: action, order_id: root.getAttribute('data-order'), session_id: root.getAttribute('data-token') }, extra));
        return fetch(root.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' });
    }

    function busy(button, on, text) {
        button.disabled = on;
        button.classList.toggle('is-busy', on);
        if (on && text) {
            button.setAttribute('data-label', button.textContent);
            button.textContent = text;
        } else if (!on && button.hasAttribute('data-label')) {
            button.textContent = button.getAttribute('data-label');
            button.removeAttribute('data-label');
        }
    }

    /** Будь-яка успішна дія міняє вигляд блоку (форма ↔ картка в іншій колонці): сторінка перезавантажується. */
    function send(root, button, action, extra, box) {
        if (button.disabled) {
            return;
        }
        clearIssues(root);
        busy(button, true, action === 'create' ? root.getAttribute('data-busy') : null);
        post(root, action, extra)
            .then(function (response) {
                var kind = answerKind(response);
                if (kind !== 'json') {
                    answerIssue(root, kind, box);
                    return null;
                }
                return response.json();
            })
            .then(function (answer) {
                if (!answer) {
                    return;
                }
                if (answer.ok) {
                    try {
                        window.sessionStorage.setItem(TOAST_KEY, JSON.stringify([
                            root.getAttribute('data-done-' + action),
                            root.getAttribute('data-toast-ok')
                        ]));
                    } catch (e) {}
                    window.location.reload();
                    return;
                }
                if (answer.candidates && answer.candidates.length) {
                    showCandidates(root, answer);
                    return;
                }
                showIssues(root, answer.issues || [], box);
            })
            .catch(function () { networkIssue(root, box); })
            .finally(function () { busy(button, false); });
    }

    function fileName(response, root) {
        var match = /filename="([A-Za-z0-9.\-]+)"/.exec(response.headers.get('Content-Disposition') || '');
        return match ? match[1] : 'rozetka-' + root.getAttribute('data-order') + '.pdf';
    }

    /**
     * Етикетка приходить байтами PDF і відкривається в новій вкладці, щоб одразу надрукувати; помилка — JSON,
     * і її показують на сторінці. Вкладку відкриваємо ще в обробнику кліку: після fetch браузер уже
     * вважає window.open спливним вікном і блокує його. Якщо вкладку все ж заблоковано — файл скачується.
     */
    function label(root, button) {
        if (button.disabled) {
            return;
        }
        clearIssues(root);
        busy(button, true);
        var tab = window.open('', '_blank');
        if (tab) {
            tab.opener = null;
        }
        var closeTab = function () {
            if (tab && !tab.closed) {
                tab.close();
            }
        };
        post(root, 'label', {})
            .then(function (response) {
                var kind = answerKind(response);
                if (kind === 'session' || kind === 'server') {
                    closeTab();
                    answerIssue(root, kind);
                    return;
                }
                if (kind === 'json') {
                    closeTab();
                    return response.json().then(function (answer) { showIssues(root, answer.issues || []); });
                }
                return response.arrayBuffer().then(function (bytes) {
                    var url = URL.createObjectURL(new Blob([bytes], { type: 'application/pdf' }));
                    if (tab && !tab.closed) {
                        tab.location.href = url;
                    } else {
                        var link = el('a');
                        link.href = url;
                        link.download = fileName(response, root);
                        document.body.appendChild(link);
                        link.click();
                        link.remove();
                    }
                    // Вкладка вантажить blob асинхронно: раннє звільнення лишило б її порожньою.
                    setTimeout(function () { URL.revokeObjectURL(url); }, 60000);
                });
            })
            .catch(function () { closeTab(); networkIssue(root); })
            .finally(function () { busy(button, false); });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.fn_rz_parcel_create, .fn_rz_parcel_attach, .fn_rz_parcel_attach_toggle, .fn_rz_parcel_lookup, .fn_rz_parcel_choose, .fn_rz_parcel_delete, .fn_rz_parcel_unlink, .fn_rz_parcel_refresh, .fn_rz_parcel_label');
        var root = button ? rootOf(button) : null;
        if (!root) {
            return;
        }
        event.preventDefault();
        var has = function (name) { return button.classList.contains(name); };
        if (has('fn_rz_parcel_create')) {
            send(root, button, 'create', fields(root));
        } else if (has('fn_rz_parcel_attach')) {
            var track = root.querySelector('[data-attach="track"]');
            send(root, button, 'attach', { track: track ? track.value : '' }, root.querySelector('.rz-parcel__issues--attach'));
        } else if (has('fn_rz_parcel_attach_toggle')) {
            var panel = root.querySelector('.rz-parcel__attach');
            panel.hidden = !panel.hidden;
            button.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
            if (!panel.hidden) {
                panel.querySelector('[data-attach="track"]').focus();
            }
        } else if (has('fn_rz_parcel_lookup')) {
            send(root, button, 'lookup', {});
        } else if (has('fn_rz_parcel_choose')) {
            send(root, button, 'choose', { track: button.getAttribute('data-track') });
        } else if (has('fn_rz_parcel_refresh')) {
            send(root, button, 'refresh', {});
        } else if (has('fn_rz_parcel_label')) {
            label(root, button);
        } else if (has('fn_rz_parcel_delete')) {
            if (window.confirm(root.getAttribute('data-confirm-delete'))) {
                send(root, button, 'delete', {});
            }
        } else if (window.confirm(root.getAttribute(button.hasAttribute('data-uncertain') ? 'data-confirm-unlink-check' : 'data-confirm-unlink'))) {
            send(root, button, 'unlink', {});
        }
    });

    // Без післяплати неоплачене замовлення видається безкоштовно: позначка «оплачено поза сайтом» з'являється,
    // щойно менеджер прибрав суму. Сервер усе одно перевіряє це сам.
    document.addEventListener('input', function (event) {
        var input = event.target;
        if (!input.matches || !input.matches('.fn_rz_parcel [data-field="cod_amount"]')) {
            return;
        }
        var release = rootOf(input).querySelector('.fn_rz_parcel_release');
        if (release) {
            var amount = parseFloat(String(input.value).replace(',', '.'));
            release.hidden = amount > 0;
        }
    });

    // Enter у полі блоку інакше надіслав би форму замовлення навколо нього.
    document.addEventListener('keydown', function (event) {
        var input = event.target;
        if (event.key !== 'Enter' || !input.matches || !input.matches('.fn_rz_parcel input')) {
            return;
        }
        event.preventDefault();
        var root = rootOf(input);
        var button = input.matches('[data-attach]') ? root.querySelector('.fn_rz_parcel_attach') : null;
        if (button) {
            button.click();
        }
    });

    // Ядро прив'язує .fn_clipboard ще в <head>, до появи рядків списку замовлень і картки; без власного обробника
    // клік по href="" перезавантажує сторінку. Один обробник на список і картку: копіюють однаково.
    document.addEventListener('click', function (event) {
        var link = event.target.closest('.fn_rz_copy');
        if (!link) {
            return;
        }
        event.preventDefault();
        var value = link.getAttribute('data-copy-string');
        var done = function () {
            link.classList.add('is-copied');
            setTimeout(function () { link.classList.remove('is-copied'); }, 1000);
        };
        var failed = function () { toast('error', link.getAttribute('data-copy-failed'), ''); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(done, failed);
            return;
        }
        var field = document.createElement('input');
        field.value = value;
        field.setAttribute('readonly', '');
        field.style.position = 'absolute';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        var copiedOk = false;
        try {
            copiedOk = document.execCommand('copy');
        } catch (e) {}
        document.body.removeChild(field);
        if (copiedOk) {
            done();
        } else {
            failed();
        }
    });

    // Видалення реєстру — звичайна форма сторінки реєстрів; підтвердження без інлайн-обробника.
    document.addEventListener('submit', function (event) {
        var form = event.target.closest ? event.target.closest('form.fn_rz_confirm') : null;
        if (form && !window.confirm(form.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    document.addEventListener('click', function (event) {
        var hide = event.target.closest('.fn_rz_mass_hide');
        if (hide) {
            hide.closest('.fn_rz_mass_result').hidden = true;
        }
    });

    document.addEventListener('click', function (event) {
        var toggle = event.target.closest('.fn_rz_parcel_more');
        var card = toggle ? toggle.closest('.rz-card') : null;
        var panel = card ? card.querySelector('.rz-card__more') : null;
        if (!panel) {
            return;
        }
        var opening = panel.hidden;
        panel.hidden = !opening;
        toggle.setAttribute('aria-expanded', opening ? 'true' : 'false');
        toggle.querySelector('span').textContent = toggle.getAttribute(opening ? 'data-less' : 'data-more');
    });
})();

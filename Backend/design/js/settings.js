(function () {
    'use strict';

    var MIN_CITY_QUERY = 2;
    var DEBOUNCE_MS = 250;

    function ready(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function refreshPicker(select) {
        if (window.jQuery && window.jQuery.fn.selectpicker) {
            window.jQuery(select).selectpicker('refresh');
        }
    }

    function getJson(url, signal) {
        return fetch(url, { credentials: 'same-origin', signal: signal }).then(function (response) {
            return response.json();
        });
    }

    function submitGuard(form) {
        var buttons = form.querySelectorAll('button[type=submit]');
        var labels = [];
        var initiallyDisabled = [];
        buttons.forEach(function (button) {
            labels.push(button.querySelector('span').textContent);
            initiallyDisabled.push(button.disabled);
        });

        form.addEventListener('submit', function (event) {
            var pressed = event.submitter;
            if (pressed && pressed.getAttribute('data-rz-confirm') && !window.confirm(pressed.getAttribute('data-rz-confirm'))) {
                event.preventDefault();
                return;
            }
            // Вимкнену кнопку браузер не надсилає: її name/value переносимо в приховане поле.
            if (pressed && pressed.name) {
                var flag = document.createElement('input');
                flag.type = 'hidden';
                flag.name = pressed.name;
                flag.value = pressed.value;
                flag.setAttribute('data-rz-flag', '');
                form.appendChild(flag);
            }
            if (pressed && pressed.getAttribute('data-rz-busy')) {
                pressed.querySelector('span').textContent = pressed.getAttribute('data-rz-busy');
            }
            buttons.forEach(function (button) {
                button.disabled = true;
            });
        });

        // Повернення кнопкою «назад» бере сторінку з bfcache разом із вимкненими кнопками, а submit вдруге не спрацює.
        window.addEventListener('pageshow', function (event) {
            if (!event.persisted) {
                return;
            }
            form.querySelectorAll('input[type=hidden][data-rz-flag]').forEach(function (flag) {
                flag.remove();
            });
            buttons.forEach(function (button, index) {
                button.disabled = initiallyDisabled[index];
                button.querySelector('span').textContent = labels[index];
            });
        });
    }

    function cityItem(city) {
        var item = document.createElement('li');
        var type = document.createElement('span');
        type.className = 'text_grey';
        type.textContent = city.type ? city.type + ' ' : '';
        var name = document.createElement('b');
        name.textContent = city.name;
        item.appendChild(type);
        item.appendChild(name);
        if (city.region) {
            item.appendChild(document.createTextNode(', ' + city.region));
        }

        return item;
    }

    function citySearch(base, input, list, onPick) {
        var timer = null;
        var request = null;

        function close() {
            list.hidden = true;
            list.textContent = '';
        }

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var query = input.value.trim();
            if (query.length < MIN_CITY_QUERY) {
                close();
                return;
            }
            timer = setTimeout(function () {
                if (request) {
                    request.abort();
                }
                request = new AbortController();
                getJson(base + 'senderCities&q=' + encodeURIComponent(query), request.signal)
                    .then(function (data) {
                        list.textContent = '';
                        (data.cities || []).forEach(function (city) {
                            var item = cityItem(city);
                            item.addEventListener('mousedown', function (event) {
                                event.preventDefault();
                                onPick(city);
                                close();
                            });
                            list.appendChild(item);
                        });
                        list.hidden = list.children.length === 0;
                    })
                    .catch(function () {});
            }, DEBOUNCE_MS);
        });
        input.addEventListener('blur', function () { setTimeout(close, 150); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                var first = list.querySelector('li');
                if (first) {
                    first.dispatchEvent(new Event('mousedown'));
                }
            } else if (event.key === 'Escape') {
                close();
            }
        });
    }

    function senderCity(base) {
        var box = document.getElementById('rozetka_sender_city');
        if (!box) {
            return;
        }
        var chosen = box.querySelector('.rz-set__city-chosen');
        var search = box.querySelector('.rz-set__search');
        var input = box.querySelector('.rz-set__city-input');
        var cityId = document.getElementById('rozetka_sender_city_id');
        var select = document.getElementById('rozetka_sender_point_id');

        function pick(city) {
            var name = chosen.querySelector('.rz-set__city-name');
            name.textContent = '';
            if (city.type) {
                var type = document.createElement('span');
                type.className = 'text_grey';
                type.textContent = city.type + ' ';
                name.appendChild(type);
            }
            var bold = document.createElement('b');
            bold.textContent = city.name;
            name.appendChild(bold);
            if (city.region) {
                name.appendChild(document.createTextNode(', ' + city.region));
            }
            cityId.value = city.id;
            chosen.hidden = false;
            search.hidden = true;
            getJson(base + 'senderPoints&city_id=' + encodeURIComponent(city.id))
                .then(function (data) {
                    while (select.options.length > 1) {
                        select.remove(1);
                    }
                    (data.points || []).forEach(function (point) {
                        var option = document.createElement('option');
                        option.value = point.id;
                        option.textContent = point.title;
                        select.appendChild(option);
                    });
                    select.value = '';
                    refreshPicker(select);
                })
                .catch(function () {});
        }

        box.querySelector('.rz-set__city-change').addEventListener('click', function () {
            chosen.hidden = true;
            search.hidden = false;
            input.value = '';
            input.focus();
        });

        citySearch(base, input, box.querySelector('.rz-set__suggest'), pick);
    }

    function popularForm(base) {
        var rows = document.getElementById('rozetka_popular_rows');
        if (!rows) {
            return;
        }
        var empty = document.querySelector('.rz-set__popular-empty');
        var box = document.getElementById('rozetka_popular_search');
        var input = box.querySelector('.rz-set__city-input');
        var template = document.getElementById('rozetka_popular_template');

        function refreshEmpty() {
            empty.hidden = rows.querySelector('.rz-set__popular') !== null;
        }

        rows.addEventListener('click', function (event) {
            var button = event.target.closest('.rz-set__remove');
            if (button) {
                button.closest('.rz-set__popular').remove();
                refreshEmpty();
            }
        });

        citySearch(base, input, box.querySelector('.rz-set__suggest'), function (city) {
            input.value = '';
            var exists = Array.prototype.some.call(rows.querySelectorAll('input[name="popular[]"]'), function (hidden) {
                return hidden.value === city.id;
            });
            if (exists) {
                return;
            }
            var row = template.content.firstElementChild.cloneNode(true);
            row.querySelector('input').value = city.id;
            row.querySelector('b').textContent = city.name;
            row.querySelector('.text_grey').textContent = city.region || '';
            rows.appendChild(row);
            refreshEmpty();
        });
    }

    function groupsForm() {
        var form = document.getElementById('rozetka_groups_form');
        if (!form) {
            return;
        }
        var rows = document.getElementById('rozetka_group_rows');
        var newRow = rows.querySelector('.rz-set__group--new');
        var newName = newRow.querySelector('[data-field="name"]');
        var addButton = document.getElementById('rozetka_group_add');
        var selects = form.querySelectorAll('select.rz-set__kind-group');

        function newOptions(callback) {
            selects.forEach(function (select) {
                callback(select, select.querySelector('option[value="new"]'));
            });
        }

        addButton.addEventListener('click', function () {
            newRow.hidden = false;
            addButton.hidden = true;
            newOptions(function (select, option) {
                if (!option) {
                    option = document.createElement('option');
                    option.textContent = newName.value.trim();
                    option.value = 'new';
                    select.appendChild(option);
                }
                refreshPicker(select);
            });
            newName.focus();
        });

        newName.addEventListener('input', function () {
            newOptions(function (select, option) {
                if (option) {
                    option.textContent = newName.value.trim();
                    refreshPicker(select);
                }
            });
        });

        // Видалення спрацьовує після «Зберегти»; до того сторінку можна перезавантажити, і група повернеться.
        rows.addEventListener('click', function (event) {
            var button = event.target.closest('.rz-set__group-remove');
            if (!button) {
                return;
            }
            var row = button.closest('.rz-set__group');
            if (row === newRow) {
                newRow.hidden = true;
                newName.value = '';
                newOptions(function (select, option) {
                    if (option) {
                        option.remove();
                        refreshPicker(select);
                    }
                });
                addButton.hidden = false;
                return;
            }
            row.classList.add('rz-set__group--deleted');
            row.querySelector('[data-field="delete"]').value = '1';
        });

        // Імена полів — у порядку на сторінці, тож перетягнута група зберігає нову позицію.
        form.addEventListener('submit', function () {
            var index = 0;
            rows.querySelectorAll('.rz-set__group').forEach(function (row) {
                if (row === newRow && row.hidden) {
                    return;
                }
                row.querySelectorAll('[data-field]').forEach(function (field) {
                    field.name = 'groups[' + index + '][' + field.getAttribute('data-field') + ']';
                });
                index++;
            });
        });
    }

    ready(function () {
        var root = document.getElementById('rozetka_settings');
        if (!root) {
            return;
        }
        root.querySelectorAll('form').forEach(submitGuard);
        var base = root.getAttribute('data-base');
        senderCity(base);
        popularForm(base);
        groupsForm();
    });
})();

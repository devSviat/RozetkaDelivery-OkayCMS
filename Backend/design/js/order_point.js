/* Редактор точки ROZETKA Delivery на сторінці замовлення. Його поля не мають name: вони всередині
   форми замовлення й надсилаються в OrderPointAdmin окремо. Усе, що приходить із сервера, — лише textContent. */
(function () {
    'use strict';

    var DEBOUNCE_MS = 300;
    var MIN_CITY_QUERY = 2;

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined && text !== null) {
            node.textContent = text;
        }
        return node;
    }

    function format(template, values) {
        return String(template || '').replace(/%(\w+)%/g, function (all, name) {
            return values[name] !== undefined ? values[name] : all;
        });
    }

    function texts(root) {
        try {
            return JSON.parse(root.getAttribute('data-texts') || '{}');
        } catch (e) {
            return {};
        }
    }

    function post(root, action, data) {
        var body = new URLSearchParams(Object.assign({ action: action, session_id: root.getAttribute('data-token') }, data));
        return fetch(root.getAttribute('data-url'), { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) { return response.json(); });
    }

    function input(placeholder, maxLength) {
        var node = el('input', 'form-control');
        node.type = 'text';
        node.autocomplete = 'off';
        node.placeholder = placeholder || '';
        node.maxLength = maxLength;
        return node;
    }

    function field(label, control) {
        var wrap = el('label', 'rz-op__field');
        wrap.appendChild(el('span', 'heading_label', label));
        wrap.appendChild(control);
        return wrap;
    }

    /** Y-m-d з сервера як дд.мм.рррр; інше не показується. */
    function dateText(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
    }

    function money(amount, currency) {
        return amount !== null && amount !== undefined ? Number(amount).toFixed(2) + ' ' + (currency || '') : '';
    }

    /** Назва з районом і областю нижче: тезки бувають навіть в одному районі. */
    function cityLabel(city) {
        var box = document.createDocumentFragment();
        box.appendChild(el('b', '', [city.type, city.name].filter(Boolean).join(' ')));
        var where = [city.district, city.region].filter(Boolean).join(', ');
        if (where) {
            box.appendChild(el('span', 'rz-op__where', where));
        }
        return box;
    }

    function showAlert(box, kind, text, button) {
        var alert = el('div', 'alert alert--' + kind + ' mb-h');
        var content = el('div', 'alert__content');
        content.appendChild(el('p', '', text));
        if (button) {
            content.appendChild(button);
        }
        alert.appendChild(content);
        box.appendChild(alert);
    }

    function Editor(root) {
        this.root = root;
        this.texts = texts(root);
        this.box = root.querySelector('.rz-op__editor');
        this.message = root.querySelector('.rz-op__message');
        this.city = root.getAttribute('data-city-id') ? {
            id: root.getAttribute('data-city-id'),
            label: root.getAttribute('data-city-name') || ''
        } : null;
        this.pointId = root.getAttribute('data-point') || null;
        this.group = null;
        this.timers = {};
        this.build();
    }

    Editor.prototype.debounce = function (name, fn) {
        clearTimeout(this.timers[name]);
        this.timers[name] = setTimeout(fn, DEBOUNCE_MS);
    };

    Editor.prototype.build = function () {
        var self = this;
        var t = this.texts;
        this.box.textContent = '';
        this.message.textContent = '';

        this.cityInput = input(t.op_city_placeholder, 64);
        this.citySuggest = el('ul', 'rz-op__suggest');
        this.citySuggest.hidden = true;
        var cityField = field(t.order_city, this.cityInput);
        cityField.appendChild(this.citySuggest);
        this.cityChosen = el('div', 'rz-op__chosen');
        this.cityName = el('span', '');
        var change = el('button', 'rz-op__link', t.op_change);
        change.type = 'button';
        change.addEventListener('click', function () {
            self.cityChosen.hidden = true;
            self.cityInput.hidden = false;
            self.cityInput.value = '';
            self.cityInput.focus();
        });
        this.cityChosen.appendChild(this.cityName);
        this.cityChosen.appendChild(change);
        cityField.insertBefore(this.cityChosen, this.cityInput);
        this.box.appendChild(cityField);
        this.cityInput.addEventListener('input', function () {
            self.debounce('city', function () { self.findCities(); });
        });
        this.cityInput.addEventListener('blur', function () {
            self.citySuggest.hidden = true;
        });

        this.chips = el('div', 'rz-op__chips');
        this.box.appendChild(this.chips);
        this.search = input(t.op_point_search_placeholder, 64);
        this.box.appendChild(field(t.op_point_search, this.search));
        this.search.addEventListener('input', function () {
            self.debounce('points', function () { self.loadPoints(); });
        });
        this.list = el('div', 'rz-op__list');
        this.box.appendChild(this.list);

        var actions = el('div', 'rz-op__actions');
        this.saveButton = el('button', 'btn btn_small btn_blue', t.op_save);
        this.saveButton.type = 'button';
        this.saveButton.addEventListener('click', function () { self.save(); });
        actions.appendChild(this.saveButton);
        if (!this.root.getAttribute('data-open')) {
            var cancel = el('button', 'btn btn_small btn_border_blue', t.op_cancel);
            cancel.type = 'button';
            cancel.addEventListener('click', function () { self.close(); });
            actions.appendChild(cancel);
        }
        this.box.appendChild(actions);

        this.showCity();
        if (this.city) {
            this.loadPoints();
        }
        this.box.hidden = false;
        this.root.classList.add('is-editing');
    };

    Editor.prototype.close = function () {
        this.box.hidden = true;
        this.box.textContent = '';
        this.root.classList.remove('is-editing');
    };

    Editor.prototype.showCity = function () {
        this.cityName.textContent = '';
        if (this.city) {
            this.cityName.appendChild(this.city.data ? cityLabel(this.city.data) : el('b', '', this.city.label));
        }
        this.cityChosen.hidden = !this.city;
        this.cityInput.hidden = !!this.city;
    };

    Editor.prototype.findCities = function () {
        var self = this;
        var query = this.cityInput.value.trim();
        if (query.length < MIN_CITY_QUERY) {
            this.citySuggest.hidden = true;
            return;
        }
        post(this.root, 'cities', { q: query }).then(function (answer) {
            self.citySuggest.textContent = '';
            (answer.cities || []).forEach(function (city) {
                var li = el('li', '');
                li.appendChild(cityLabel(city));
                li.addEventListener('mousedown', function (event) {
                    event.preventDefault();
                    self.citySuggest.hidden = true;
                    self.city = { id: String(city.id), data: city };
                    self.pointId = null;
                    self.group = null;
                    self.search.value = '';
                    self.showCity();
                    self.loadPoints();
                });
                self.citySuggest.appendChild(li);
            });
            self.citySuggest.hidden = self.citySuggest.childElementCount === 0;
        }).catch(function () {});
    };

    Editor.prototype.loadPoints = function () {
        var self = this;
        if (!this.city) {
            return;
        }
        post(this.root, 'points', {
            order_id: this.root.getAttribute('data-order'),
            city_id: this.city.id,
            group_id: this.group || '',
            q: this.search.value.trim()
        }).then(function (answer) { self.renderPoints(answer); }).catch(function () {});
    };

    Editor.prototype.renderPoints = function (answer) {
        var self = this;
        var t = this.texts;
        this.group = answer.group || null;
        this.chips.textContent = '';
        (answer.groups || []).forEach(function (group) {
            var chip = el('button', 'rz-op__chip' + (String(group.id) === String(self.group) ? ' rz-op__chip--on' : ''), group.name + ' (' + group.count + ')');
            chip.type = 'button';
            chip.addEventListener('click', function () {
                self.group = group.id;
                self.loadPoints();
            });
            self.chips.appendChild(chip);
        });
        this.list.textContent = '';
        var points = answer.points || [];
        if (points.length === 0) {
            this.list.appendChild(el('div', 'text_grey rz-op__empty', t.op_no_points));
        }
        points.forEach(function (point) {
            var row = el('button', 'rz-op__option' + (String(point.id) === String(self.pointId) ? ' rz-op__option--on' : ''));
            row.type = 'button';
            row.appendChild(el('b', '', point.title || point.number));
            var place = [t['op_kind_' + point.kind], point.address].filter(Boolean).join(' · ');
            if (place) {
                row.appendChild(el('span', 'rz-op__place', place));
            }
            var problems = point.problems || [];
            problems.forEach(function (problem) {
                row.appendChild(el('span', 'rz-op__problem', t['op_unfit_' + problem] || ''));
            });
            // Післяплату в точку «лише передоплата» чи понад її межу сервер однаково відхилить; вага й розміри — рішення менеджера.
            row.disabled = problems.indexOf('prepay_only') !== -1 || problems.indexOf('cod_over_limit') !== -1;
            row.addEventListener('click', function () {
                self.pointId = String(point.id);
                self.list.querySelectorAll('.rz-op__option--on').forEach(function (other) { other.classList.remove('rz-op__option--on'); });
                row.classList.add('rz-op__option--on');
            });
            self.list.appendChild(row);
        });
    };

    Editor.prototype.save = function () {
        var self = this;
        var t = this.texts;
        this.message.textContent = '';
        if (!this.city || !this.pointId) {
            showAlert(this.message, 'error', t.op_choose_point);
            return;
        }
        this.saveButton.disabled = true;
        this.saveButton.textContent = t.op_saving;
        post(this.root, 'save', {
            order_id: this.root.getAttribute('data-order'),
            city_id: this.city.id,
            point_id: this.pointId
        }).then(function (answer) {
            if (!answer.ok) {
                showAlert(self.message, 'error', answer.error || t.op_network);
                return;
            }
            self.root.setAttribute('data-city-id', self.city.id);
            self.root.setAttribute('data-point', self.pointId);
            self.root.removeAttribute('data-open');
            var needs = self.root.querySelector('.fn_rz_op_needs');
            if (needs) {
                needs.remove();
            }
            self.root.querySelector('.fn_rz_op_change').hidden = false;
            self.close();
            applyAnswer(self.root, answer);
        }).catch(function () {
            showAlert(self.message, 'error', t.op_network);
        }).finally(function () {
            self.saveButton.disabled = false;
            self.saveButton.textContent = t.op_save;
        });
    };

    /** Форма замовлення ядра надсилає delivery_price під час збереження: там має бути ціна, записана тут. */
    function syncOrderForm(price) {
        var priceInput = document.querySelector('form [name="delivery_price"]');
        if (priceInput && price !== null && price !== undefined) {
            priceInput.value = Number(price).toFixed(2);
        }
    }

    function renderSummary(root, lines) {
        var t = texts(root);
        var summary = root.querySelector('.rz-op__summary');
        if (!summary || !lines) {
            return;
        }
        summary.textContent = '';
        var row = function (label, value, hint) {
            var node = el('div', 'rz-op__row');
            node.appendChild(el('span', 'rz-op__label', label));
            node.appendChild(document.createTextNode(value));
            if (hint) {
                node.appendChild(el('span', 'rz-op__hint', hint));
            }
            summary.appendChild(node);
        };
        row(t.order_city, lines.city);
        row(t.order_point, lines.point);
        if (lines.estimatedDate) {
            row(t.order_estimated, dateText(lines.estimatedDate));
        }
        if (lines.cod) {
            row(t.order_cod, lines.codFee !== null && lines.codFee !== undefined ? format(t.order_cod_fee, { fee: Number(lines.codFee).toFixed(2) }) : t.order_cod_yes);
        }
        if (lines.receiverPays) {
            row(t.order_payer, t.order_payer_receiver);
        }
        root.setAttribute('data-city-name', lines.city || '');
    }

    function applyAnswer(root, answer) {
        var t = texts(root);
        var message = root.querySelector('.rz-op__message');
        var currency = root.getAttribute('data-currency');
        renderSummary(root, answer.delivery);
        if (answer.warning) {
            showAlert(message, 'warning', answer.warning);
        }
        var price = answer.price;
        if (!price) {
            return;
        }
        var amount = money(price.price, currency);
        if (price.action === 'offer') {
            var apply = el('button', 'btn btn_small btn_border_blue', format(t.op_price_apply, { price: amount }));
            apply.type = 'button';
            apply.addEventListener('click', function () {
                apply.disabled = true;
                post(root, 'apply_price', { order_id: root.getAttribute('data-order') }).then(function (result) {
                    if (result.ok) {
                        message.textContent = '';
                        syncOrderForm(result.price && result.price.price);
                        applyAnswer(root, result);
                    } else {
                        apply.disabled = false;
                        showAlert(message, 'error', result.error || t.op_network);
                    }
                }).catch(function () {
                    apply.disabled = false;
                    showAlert(message, 'error', t.op_network);
                });
            });
            showAlert(message, 'info', format(t.op_price_offer, { price: amount }), apply);
        } else if (price.action === 'applied') {
            showAlert(message, 'success', format(t.op_price_applied, { price: amount }));
        } else {
            var text = price.action === 'keep' && amount ? t.op_price_same : t['op_price_' + price.action];
            if (text) {
                showAlert(message, price.action === 'keep' && !amount ? 'warning' : 'success', format(text, { price: amount }));
            }
        }
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.fn_rz_op_change');
        var root = button ? button.closest('.fn_rz_order_point') : null;
        if (root) {
            event.preventDefault();
            new Editor(root);
        }
    });

    function openWaiting() {
        document.querySelectorAll('.fn_rz_order_point[data-open="1"]').forEach(function (root) {
            new Editor(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', openWaiting);
    } else {
        openWaiting();
    }
})();

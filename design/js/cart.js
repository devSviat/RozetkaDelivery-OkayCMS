/*
 * Вибір точки ROZETKA Delivery у кошику. Усе, що приходить із довідника, потрапляє на сторінку
 * лише через textContent: назви — сторонні дані. Сірі точки й заблоковані оплати — лише зручність:
 * оформлення перевіряє все на сервері.
 */
(function () {
    'use strict';

    var DEBOUNCE_MS = 200;
    var MIN_CITY_QUERY = 2;

    function parse(json) {
        try {
            return json ? JSON.parse(json) : null;
        } catch (e) {
            return null;
        }
    }

    function format(template, values) {
        return String(template || '').replace(/%(\w+)%/g, function (m, key) {
            return values[key] !== undefined ? values[key] : m;
        });
    }

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

    /** Теми на основі okay_shop піднімають label поля класом `filled`, який ставлять лише на blur. */
    function syncFilled(input) {
        if (input.parentNode) {
            input.parentNode.classList.toggle('filled', input.value.trim() !== '');
        }
    }

    /** Y-m-d з сервера як дд.мм.рррр; інше не показується. */
    function dateText(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value || ''));
        return m ? m[3] + '.' + m[2] + '.' + m[1] : '';
    }

    function cityArea(city) {
        return [city.district, city.region].filter(Boolean).join(', ');
    }

    function Block(root) {
        this.root = root;
        root.rzBlock = this;
        this.texts = parse(root.getAttribute('data-texts')) || {};
        this.urls = parse(root.getAttribute('data-urls')) || {};
        this.chip = root.getAttribute('data-chip');
        this.chipSelected = root.getAttribute('data-chip-selected');
        this.deliveryId = root.getAttribute('data-delivery-id');
        this.codPayments = (root.getAttribute('data-cod-payments') || '').split(',').filter(Boolean);
        this.codFee = root.getAttribute('data-cod-fee') || '';
        this.city = root.querySelector('.rz-cart__city-input');
        this.popular = root.querySelector('.rz-cart__popular');
        this.cityBox = root.querySelector('.rz-cart__city');
        this.cityLine = root.querySelector('.rz-cart__city-line');
        this.chosen = root.querySelector('.rz-cart__chosen');
        this.suggest = root.querySelector('.rz-cart__suggest');
        this.points = root.querySelector('.rz-cart__points');
        this.groups = root.querySelector('.rz-cart__groups');
        this.searchBox = root.querySelector('.rz-cart__search');
        this.search = root.querySelector('.rz-cart__search-input');
        this.pointCheck = root.querySelector('.rz-cart__point-check');
        this.list = root.querySelector('.rz-cart__list');
        this.more = root.querySelector('.rz-cart__more');
        this.message = root.querySelector('.rz-cart__message');
        this.dateBox = root.querySelector('.rz-cart__date');
        this.cityInput = root.querySelector('.rz-cart__city-id');
        this.pointInput = root.querySelector('.rz-cart__point-id');
        this.selectedCity = parse(root.getAttribute('data-city'));
        this.point = parse(root.getAttribute('data-point'));
        this.step = 'city';
        this.groupId = null;
        this.lastData = null;
        this.suggestions = [];
        this.active = -1;
        this.cityRequest = null;
        this.pointsRequest = null;
        this.dateRequest = null;
        this.cityTimer = null;
        this.searchTimer = null;
        this.bind();
        if (this.selectedCity) {
            this.markPopular();
            // Вибір, відновлений із форми, сесії чи останнього замовлення, показується як вибраний, а не списком.
            if (this.point && !this.pointRefusesCod()) {
                this.setStep('chosen');
                if (this.isSelected()) {
                    this.requestDate();
                }
            } else {
                this.point = null;
                this.pointInput.value = '';
                this.setStep('points');
                this.load();
            }
        } else {
            this.setStep('city');
        }
        this.syncPayments();
    }

    Block.prototype.paymentId = function () {
        var checked = document.querySelector('input[name="payment_method_id"]:checked');
        return checked ? checked.value : '';
    };

    Block.prototype.codChosen = function () {
        return this.codPayments.indexOf(this.paymentId()) !== -1;
    };

    /** Точка «лише передоплата» з оплатою-післяплатою: оформлення таке відхилить. */
    Block.prototype.pointRefusesCod = function () {
        return !!(this.point && this.point.prepay_only && this.codChosen());
    };

    /** Проблеми точки для поточної оплати: сервер дає обидва варіанти, щоб зміна оплати не чекала на запит. */
    Block.prototype.problemsOf = function (point) {
        return (this.codChosen() ? point.cod_problems : point.problems) || [];
    };

    /** city: популярні міста й поле міста; points: місто одним рядком, групи, пошук, список; chosen: лише вибраний пункт. */
    Block.prototype.setStep = function (step) {
        this.step = step;
        this.root.setAttribute('data-step', step);
        if (this.popular) {
            this.popular.hidden = step !== 'city';
        }
        this.cityBox.hidden = step !== 'city';
        this.cityLine.hidden = step !== 'points';
        if (step === 'points' && this.selectedCity) {
            this.cityLine.querySelector('.rz-cart__city-line-type').textContent = this.selectedCity.type || '';
            this.cityLine.querySelector('.rz-cart__city-line-name').textContent = this.selectedCity.name || '';
            this.cityLine.querySelector('.rz-cart__city-line-area').textContent = cityArea(this.selectedCity);
        }
        this.chosen.hidden = step !== 'chosen';
        if (step === 'chosen') {
            this.showChosen();
        }
        if (step !== 'points') {
            this.points.hidden = true;
        }
    };

    /** Прибирає повідомлення, яке показав валідатор теми, щойно поле стає правильним. */
    Block.prototype.revalidate = function (element) {
        var $ = window.jQuery;
        if (element && $ && $(element).hasClass('error') && $(element.form).data('validator')) {
            $(element).valid();
        }
    };

    Block.prototype.pointTitle = function (point) {
        return point.title || point.number || '';
    };

    Block.prototype.showChosen = function () {
        var cityBox = this.chosen.querySelector('.rz-cart__chosen-city');
        cityBox.textContent = '';
        if (this.selectedCity) {
            if (this.selectedCity.type) {
                cityBox.appendChild(el('span', 'rz-cart__city-line-type', this.selectedCity.type + ' '));
            }
            cityBox.appendChild(el('b', null, this.selectedCity.name));
            var area = cityArea(this.selectedCity);
            if (area) {
                cityBox.appendChild(document.createTextNode(', ' + area));
            }
        }
        this.chosen.querySelector('.rz-cart__chosen-title').textContent = this.point ? this.pointTitle(this.point) : '';
        var place = this.chosen.querySelector('.rz-cart__chosen-place');
        // Назва точки ROZETKA зазвичай уже містить адресу.
        var address = this.point && this.point.address ? this.point.address : '';
        place.textContent = address && this.pointTitle(this.point).indexOf(address) === -1 ? address : '';
        place.hidden = place.textContent === '';
        var note = this.chosen.querySelector('.rz-cart__chosen-note');
        note.textContent = this.point && this.point.prepay_only ? (this.texts.cart_prepay_only || '') : '';
        note.hidden = note.textContent === '';
        this.showSchedule(this.point ? this.point.schedule : null);
    };

    Block.prototype.showSchedule = function (schedule) {
        var box = this.chosen.querySelector('.rz-cart__chosen-schedule');
        box.textContent = '';
        var hours = this.scheduleNode(schedule ? schedule.week : null);
        if (hours) {
            var around = String(this.texts.cart_schedule || '%schedule%').split('%schedule%');
            box.appendChild(document.createTextNode(around[0]));
            box.appendChild(hours);
            box.appendChild(document.createTextNode(around[1] || ''));
        }
        var special = schedule && Array.isArray(schedule.special) ? schedule.special : [];
        if (special.length) {
            var self = this;
            var days = special.map(function (day) {
                return dateText(day.date).slice(0, 5) + ' ' + (day.hours || self.texts.cart_day_off || '');
            }).join(', ');
            box.appendChild(el('span', 'rz-cart__special', format(this.texts.cart_special, { days: days })));
        }
        box.hidden = !hours && !special.length;
    };

    /**
     * Тиждень як "Пн–Пт 08:30–18:00 (перерва 13:00–14:00), Сб 09:00–15:00": групи WorkSchedule::parse()['week']
     * {from, to, hours, break} (0 = понеділок, hours null — вихідний) або [{allDay: true}].
     */
    Block.prototype.scheduleNode = function (schedule) {
        if (!Array.isArray(schedule) || schedule.length === 0) {
            return null;
        }
        if (schedule[0] && schedule[0].allDay) {
            return el('span', 'rz-cart__schedule rz-cart__day', this.texts.cart_all_day || '');
        }
        var days = String(this.texts.cart_weekdays || '').split(',');
        var self = this;
        var line = el('span', 'rz-cart__schedule');
        schedule.forEach(function (group, index) {
            var span = (days[group.from] || '') + (group.to !== group.from ? '–' + (days[group.to] || '') : '');
            var text = span + ' ' + (group.hours || self.texts.cart_day_off || '');
            if (group.hours && group.break) {
                text += ' (' + format(self.texts.cart_break, { hours: group.break }) + ')';
            }
            if (index > 0) {
                line.appendChild(document.createTextNode(', '));
            }
            line.appendChild(el('span', 'rz-cart__day', text));
        });
        return line;
    };

    Block.prototype.bind = function () {
        var self = this;
        this.root.querySelectorAll('[data-rz-city]').forEach(function (button) {
            button.addEventListener('click', function () {
                self.chooseCity({ id: button.getAttribute('data-rz-city'), name: button.getAttribute('data-rz-city-name'), type: button.getAttribute('data-rz-city-type') || '' });
            });
        });
        this.city.addEventListener('input', function () {
            if (self.selectedCity && self.city.value.trim() !== self.selectedCity.name) {
                self.dropCity();
            }
            clearTimeout(self.cityTimer);
            self.cityTimer = setTimeout(function () { self.findCities(); }, DEBOUNCE_MS);
        });
        this.city.addEventListener('keydown', function (event) { self.cityKeys(event); });
        this.city.addEventListener('blur', function () { setTimeout(function () { self.closeSuggest(); }, 150); });
        this.root.querySelector('.rz-cart__change-city').addEventListener('click', function () {
            self.city.value = '';
            syncFilled(self.city);
            self.dropCity();
            self.city.focus();
        });
        this.root.querySelector('.rz-cart__change').addEventListener('click', function () {
            self.setStep('points');
            self.load();
            if (!self.searchBox.hidden) {
                self.search.focus();
            }
        });
        this.search.addEventListener('input', function () {
            clearTimeout(self.searchTimer);
            self.searchTimer = setTimeout(function () { self.load(); }, DEBOUNCE_MS);
        });
        this.search.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });
    };

    Block.prototype.findCities = function () {
        var self = this;
        var query = this.city.value.trim();
        var url = this.urls.cities;
        if (query.length < MIN_CITY_QUERY || !url) {
            this.closeSuggest();
            return;
        }
        if (this.cityRequest) {
            this.cityRequest.abort();
        }
        this.cityRequest = new AbortController();
        fetch(url + '?q=' + encodeURIComponent(query), { credentials: 'same-origin', signal: this.cityRequest.signal })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (data.problem === 'busy') {
                    self.closeSuggest();
                    self.say(self.texts.cart_busy);
                    return;
                }
                self.say('');
                self.showSuggest(data.cities || []);
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    self.say(self.texts.cart_search_failed);
                }
            });
    };

    Block.prototype.showSuggest = function (items) {
        var self = this;
        this.suggestions = items;
        this.active = -1;
        this.suggest.textContent = '';
        if (items.length === 0) {
            var none = el('li', 'rz-cart__suggest-empty', this.texts.cart_no_city);
            none.setAttribute('aria-disabled', 'true');
            this.suggest.appendChild(none);
        }
        items.forEach(function (item, index) {
            var option = el('li', 'rz-cart__suggest-item');
            option.id = self.suggest.id + '_' + index;
            option.setAttribute('role', 'option');
            if (item.type) {
                option.appendChild(el('span', 'rz-cart__suggest-type', item.type + ' '));
            }
            option.appendChild(el('b', null, item.name));
            // Тезок розрізняють район і область.
            var area = cityArea(item);
            if (area) {
                option.appendChild(el('span', 'rz-cart__suggest-region', area));
            }
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
                self.chooseCity(item);
            });
            self.suggest.appendChild(option);
        });
        this.suggest.hidden = false;
        this.city.setAttribute('aria-expanded', 'true');
    };

    Block.prototype.closeSuggest = function () {
        this.suggest.hidden = true;
        this.city.setAttribute('aria-expanded', 'false');
        this.city.removeAttribute('aria-activedescendant');
    };

    Block.prototype.cityKeys = function (event) {
        var options = this.suggest.querySelectorAll('.rz-cart__suggest-item');
        if (this.suggest.hidden || options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            this.active = (this.active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options.forEach(function (option, index) {
                option.setAttribute('aria-selected', index === this.active ? 'true' : 'false');
            }, this);
            this.city.setAttribute('aria-activedescendant', options[this.active].id);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            this.chooseCity(this.suggestions[this.active >= 0 ? this.active : 0]);
        } else if (event.key === 'Escape') {
            this.closeSuggest();
        }
    };

    Block.prototype.chooseCity = function (city) {
        if (!city) {
            return;
        }
        var changed = !this.selectedCity || this.selectedCity.id !== city.id;
        this.selectedCity = city;
        this.city.value = city.name;
        syncFilled(this.city);
        this.cityInput.value = city.id;
        this.closeSuggest();
        this.markPopular();
        if (changed) {
            this.point = null;
            this.pointInput.value = '';
            this.clearDate();
            this.groupId = null;
            this.search.value = '';
            syncFilled(this.search);
        }
        // До того, як поле сховано: jQuery Validate не ховає повідомлення прихованого поля.
        this.revalidate(this.city);
        this.setStep('points');
        this.load();
    };

    /** Змінений текст міста вже не називає вибране місто: замовлення не повинне тихо піти туди. */
    Block.prototype.dropCity = function () {
        if (this.pointsRequest) {
            this.pointsRequest.abort();
        }
        this.selectedCity = null;
        this.point = null;
        this.cityInput.value = '';
        this.pointInput.value = '';
        this.groupId = null;
        this.lastData = null;
        this.points.hidden = true;
        this.say('');
        this.markPopular();
        this.clearDate();
        this.syncPayments();
        this.setStep('city');
    };

    Block.prototype.markPopular = function () {
        var self = this;
        this.root.querySelectorAll('[data-rz-city]').forEach(function (button) {
            var on = self.selectedCity && button.getAttribute('data-rz-city') === String(self.selectedCity.id);
            button.classList.toggle(self.chipSelected, !!on);
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
    };

    Block.prototype.load = function () {
        var self = this;
        var url = this.urls.points;
        if (!this.selectedCity || !url) {
            return;
        }
        if (this.pointsRequest) {
            this.pointsRequest.abort();
        }
        this.pointsRequest = new AbortController();
        url += '?city_id=' + encodeURIComponent(this.selectedCity.id)
            + (this.groupId ? '&group_id=' + encodeURIComponent(this.groupId) : '')
            + '&q=' + encodeURIComponent(this.search.value.trim());
        this.root.classList.add('rz-cart--loading');
        fetch(url, { credentials: 'same-origin', signal: this.pointsRequest.signal })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                self.root.classList.remove('rz-cart--loading');
                self.render(data || {});
            })
            .catch(function (error) {
                if (error.name !== 'AbortError') {
                    self.root.classList.remove('rz-cart--loading');
                    self.say(self.texts.cart_search_failed);
                }
            });
    };

    Block.prototype.render = function (data) {
        var self = this;
        this.lastData = data;
        this.say('');
        if (data.problem === 'busy') {
            this.say(this.texts.cart_busy);
            return;
        }
        var groups = data.groups || [];
        var points = data.points || [];
        if (groups.length === 0) {
            this.points.hidden = true;
            this.say(this.texts.cart_no_points);
            return;
        }
        // Список, що приходить, поки показано вибраний пункт, лишається прихованим до "Змінити".
        this.points.hidden = this.step !== 'points';
        this.groupId = data.group;

        this.groups.textContent = '';
        groups.forEach(function (group) {
            var on = group.id === data.group;
            var button = el('button', self.chip + (on ? ' ' + self.chipSelected : ''));
            button.type = 'button';
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.appendChild(el('span', null, group.name));
            button.appendChild(el('span', 'rz-cart__count', String(group.count)));
            button.addEventListener('click', function () {
                if (self.groupId !== group.id) {
                    self.groupId = group.id;
                    self.search.value = '';
                    syncFilled(self.search);
                    self.load();
                }
            });
            self.groups.appendChild(button);
        });

        var query = this.search.value.trim();
        var groupTotal = (groups.filter(function (g) { return g.id === data.group; })[0] || {}).count || 0;
        this.searchBox.hidden = groupTotal <= 8 && query === '';

        this.list.textContent = '';
        if (points.length === 0) {
            this.list.appendChild(el('p', 'rz-cart__empty', this.texts.cart_no_results));
        } else if (points.every(function (point) { return self.problemsOf(point).length > 0; })) {
            this.list.appendChild(el('p', 'rz-cart__empty', this.texts.cart_none_fit));
        }
        points.forEach(function (point) { self.list.appendChild(self.option(point)); });

        this.more.hidden = query !== '' || groupTotal <= points.length;
        this.more.textContent = format(this.texts.cart_shown_of, { shown: points.length, total: groupTotal });
    };

    Block.prototype.option = function (point) {
        var self = this;
        var problems = this.problemsOf(point);
        var unfit = problems.length > 0;
        var selected = !!(this.point && this.point.id === point.id);
        var option = el('button', 'rz-cart__option' + (selected ? ' rz-cart__option--selected' : '') + (unfit ? ' rz-cart__option--unfit' : ''));
        option.type = 'button';
        option.setAttribute('role', 'option');
        option.setAttribute('aria-selected', selected ? 'true' : 'false');
        if (unfit) {
            option.setAttribute('aria-disabled', 'true');
        }

        var head = el('span', 'rz-cart__option-head');
        head.appendChild(el('b', 'rz-cart__option-title', this.pointTitle(point)));
        head.appendChild(el('span', 'rz-cart__option-kind', this.texts['cart_kind_' + point.kind] || ''));
        option.appendChild(head);
        if (point.address && this.pointTitle(point).indexOf(point.address) === -1) {
            option.appendChild(el('span', 'rz-cart__option-place', point.address));
        }
        problems.forEach(function (problem) {
            option.appendChild(el('span', 'rz-cart__option-note', self.texts['cart_' + problem] || ''));
        });
        if (!unfit && point.prepay_only) {
            option.appendChild(el('span', 'rz-cart__option-note', this.texts.cart_prepay_only || ''));
        }

        option.addEventListener('click', function () {
            if (unfit) {
                return;
            }
            self.point = { id: point.id, number: point.number, kind: point.kind, title: point.title, address: point.address || '', prepay_only: !!point.prepay_only, schedule: point.schedule || null };
            self.pointInput.value = point.id;
            self.list.querySelectorAll('.rz-cart__option').forEach(function (other) {
                other.classList.remove('rz-cart__option--selected');
                other.setAttribute('aria-selected', 'false');
            });
            option.classList.add('rz-cart__option--selected');
            option.setAttribute('aria-selected', 'true');
            self.revalidate(self.pointCheck);
            self.setStep('chosen');
            self.syncPayments();
            self.requestDate();
        });
        return option;
    };

    /** Власна радіокнопка теми для цього способу доставки. */
    Block.prototype.themeInput = function () {
        var $ = window.jQuery;
        return $ ? $('input[name="delivery_id"][value="' + String(this.deliveryId).replace(/[^0-9]/g, '') + '"]') : null;
    };

    Block.prototype.clearDate = function () {
        if (this.dateRequest) {
            this.dateRequest.abort();
            this.dateRequest = null;
        }
        this.dateBox.hidden = true;
        this.dateBox.textContent = '';
    };

    /** Дата лише показується: її відсутність (збій, ліміт) не заважає оформити замовлення. */
    Block.prototype.requestDate = function () {
        var self = this;
        var url = this.urls.date;
        if (!this.point || !url) {
            return;
        }
        if (this.dateRequest) {
            this.dateRequest.abort();
        }
        this.dateRequest = new AbortController();
        fetch(url + '?delivery_id=' + encodeURIComponent(this.deliveryId) + '&point_id=' + encodeURIComponent(this.point.id), { credentials: 'same-origin', signal: this.dateRequest.signal })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                var date = dateText(data && data.date);
                self.dateBox.textContent = date ? format(self.texts.cart_estimated, { date: date }) : '';
                self.dateBox.hidden = !date;
            })
            .catch(function () {
                self.dateBox.hidden = true;
            });
    };

    /**
     * Оплати-післяплати поруч зі способом доставки: рядок комісії й блокування для точки «лише передоплата».
     * Блок робить це, лише коли вибрано саме його спосіб доставки, і знімає лише те, що поставив сам.
     */
    Block.prototype.syncPayments = function () {
        var self = this;
        var mine = this.isSelected();
        var locked = mine && !!(this.point && this.point.prepay_only);
        document.querySelectorAll('.rz-cod-line[data-rz-cod-payment]').forEach(function (line) {
            var id = line.getAttribute('data-rz-cod-payment');
            var ours = self.codPayments.indexOf(id) !== -1;
            if (mine && ours && self.codFee) {
                line.textContent = format(self.texts.cart_cod_fee, { fee: self.codFee });
                line.hidden = false;
                line.setAttribute('data-rz-delivery', self.deliveryId);
            } else if (line.getAttribute('data-rz-delivery') === self.deliveryId) {
                line.hidden = true;
                line.removeAttribute('data-rz-delivery');
            }
        });
        this.codPayments.forEach(function (id) {
            var input = document.querySelector('input[name="payment_method_id"][value="' + id.replace(/[^0-9]/g, '') + '"]');
            if (!input) {
                return;
            }
            var item = input.closest('.fn_payment_method__item') || input.parentNode;
            var note = item.querySelector('.rz-cod-lock');
            if (locked && !input.checked) {
                input.disabled = true;
                input.setAttribute('data-rz-locked', self.deliveryId);
                if (!note) {
                    note = el('span', 'rz-cod-lock', self.texts.cart_cod_payment_locked || '');
                    (input.closest('label') || input).insertAdjacentElement('afterend', note);
                }
            } else if (input.getAttribute('data-rz-locked') === self.deliveryId) {
                input.disabled = false;
                input.removeAttribute('data-rz-locked');
                if (note) {
                    note.remove();
                }
            }
        });
    };

    /** Покупець змінив оплату: точки «лише передоплата» сіріють чи знову доступні без нового запиту. */
    Block.prototype.paymentChanged = function () {
        this.syncPayments();
        if (!this.isSelected()) {
            return;
        }
        if (this.pointRefusesCod()) {
            this.point = null;
            this.pointInput.value = '';
            this.clearDate();
            this.setStep('points');
            this.syncPayments();
            if (this.lastData) {
                this.render(this.lastData);
                this.say(this.texts.err_cod_prepay_only);
            } else {
                this.load();
                this.say(this.texts.err_cod_prepay_only);
            }
            return;
        }
        if (this.step === 'points' && this.lastData) {
            this.render(this.lastData);
        }
    };

    Block.prototype.isSelected = function () {
        var input = this.themeInput();
        return !!(input && input.length && input.is(':checked'));
    };

    /** Доставку вибрано знову або кошик змінився (вага й сума): лише вибраний спосіб доставки питає сервер. */
    Block.prototype.refresh = function () {
        this.syncPayments();
        if (!this.selectedCity || !this.isSelected()) {
            return;
        }
        if (this.step === 'points') {
            this.load();
        }
        this.requestDate();
    };

    Block.prototype.say = function (text) {
        this.message.textContent = text || '';
        this.message.hidden = !text;
    };

    function visibleColor(value) {
        return value && value !== 'transparent' && !/rgba\([^)]*,\s*0\)$/.test(value) ? value : null;
    }

    /** Колір, що означає "вибрано": не чорний, білий чи сірий — ними теми фарбують звичайний текст і лінії. */
    function accentColor(value) {
        var color = visibleColor(value);
        var rgb = color ? color.match(/\d+(\.\d+)?/g) : null;
        if (!rgb || rgb.length < 3) {
            return null;
        }
        var r = Number(rgb[0]), g = Number(rgb[1]), b = Number(rgb[2]);
        return Math.max(r, g, b) - Math.min(r, g, b) > 24 ? color : null;
    }

    function isDark(color) {
        var rgb = color.match(/\d+(\.\d+)?/g).map(Number);
        return 0.299 * rgb[0] + 0.587 * rgb[1] + 0.114 * rgb[2] < 160;
    }

    /**
     * Колір, яким тема позначає вибрану опцію: позначка вибраного способу доставки (svg-галочка
     * або стилізована радіокнопка), інакше кнопка оформлення замовлення. Не перший .form__button: це
     * кнопка купона, бліда в деяких темах.
     */
    function themeAccent() {
        var chosen = document.querySelector('input[name="delivery_id"]:checked');
        var label = chosen ? (chosen.closest('label') || document.querySelector('label[for="' + chosen.id + '"]')) : null;
        var found = null;
        if (label) {
            label.querySelectorAll('svg, svg *').forEach(function (node) {
                var style = getComputedStyle(node);
                found = found || accentColor(style.stroke) || accentColor(style.fill);
            });
            [chosen, label].forEach(function (node) {
                ['::before', '::after'].forEach(function (pseudo) {
                    var style = getComputedStyle(node, pseudo);
                    found = found || accentColor(style.backgroundColor) || accentColor(style.borderTopColor);
                });
            });
            found = found || accentColor(getComputedStyle(chosen).accentColor);
        }
        var button = found ? null : document.querySelector('[name="checkout"].form__button, button[name="checkout"]');
        if (button) {
            var style = getComputedStyle(button);
            // Градієнтна кнопка має фірмовий колір у градієнті або на рамці.
            var gradient = (style.backgroundImage.match(/rgba?\([^)]*\)/g) || []);
            found = accentColor(style.backgroundColor) || accentColor(gradient[gradient.length - 1]) || accentColor(gradient[0]) || accentColor(style.borderTopColor);
        }
        return found;
    }

    /**
     * Не всі теми на основі okay_shop виставляють свої кольори як CSS-змінні, тож колір і заокруглення
     * зчитуються зі сторінки. vibe_shop нічого не потребує: блок використовує його компонент чіпа.
     */
    function adoptThemeColours(root) {
        if (!root.classList.contains('rz-cart--okay')) {
            return;
        }
        var accent = themeAccent();
        if (accent) {
            root.style.setProperty('--rz-accent', accent);
            root.style.setProperty('--rz-accent-text', isDark(accent) ? '#fff' : '#222');
        }
        var input = root.querySelector('.rz-cart__city-input');
        if (input) {
            var style = getComputedStyle(input);
            var line = visibleColor(style.borderTopColor);
            if (line) {
                root.style.setProperty('--rz-border', line);
            }
            root.style.setProperty('--rz-radius', style.borderTopLeftRadius);
        }
    }

    var blocks = [];
    var rulesAdded = false;

    /**
     * Перевірки полів ROZETKA у власному jQuery Validate теми, щоб повідомлення стояло під своїм полем
     * ще до надсилання форми. Поля інших способів доставки тема вимикає, а приховані кроки невидимі,
     * тож валідатор пропускає і ті, й інші. Сервер перевіряє все ще раз.
     */
    function addRules() {
        var $ = window.jQuery;
        var form = $ ? $('.fn_validate_cart') : null;
        if (rulesAdded || !form || !form.length || !$.validator || !form.data('validator') || blocks.length === 0) {
            return;
        }
        rulesAdded = true;
        var blockOf = function (element) {
            var root = element.closest('.rz-cart');
            return root ? root.rzBlock : null;
        };
        $.validator.addMethod('rzCity', function (value, element) {
            var block = blockOf(element);
            return !block || !!block.selectedCity;
        });
        $.validator.addMethod('rzPoint', function (value, element) {
            var block = blockOf(element);
            return !block || !!block.point;
        });
        $.validator.addMethod('rzCod', function (value, element) {
            var block = blockOf(element);
            return !block || !block.pointRefusesCod();
        });
        blocks.forEach(function (block) {
            var texts = block.texts;
            $(block.city).rules('add', { rzCity: true, messages: { rzCity: texts.err_city } });
            $(block.pointCheck).rules('add', { rzPoint: true, rzCod: true, messages: { rzPoint: texts.err_point, rzCod: texts.err_cod_prepay_only } });
        });
    }

    window.SviatRozetkaCart = { addRules: addRules };

    function init() {
        document.querySelectorAll('.rz-cart').forEach(function (root) {
            adoptThemeColours(root);
            blocks.push(new Block(root));
        });
        addRules();
        if (!window.jQuery) {
            return;
        }
        // Після змін у кошику тема повторно запускає `change` на вибраній доставці (jQuery
        // trigger, якого звичайні слухачі не бачать): вага й сума могли змінитися.
        window.jQuery(document).on('change', 'input[name="delivery_id"]', function () {
            blocks.forEach(function (block) {
                // Тема позначає лише вибраний спосіб доставки: тепер його колір можна зчитати.
                adoptThemeColours(block.root);
                block.refresh();
            });
        });
        window.jQuery(document).on('change', 'input[name="payment_method_id"]', function () {
            blocks.forEach(function (block) { block.paymentChanged(); });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();

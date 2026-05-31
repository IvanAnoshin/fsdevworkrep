/*
    Kopilot Framework (JavaScript)
    version 1.0

    Посвящаю Сереге
*/

const kop = {

    // ЖИВАЯ ПЕРЕЗАГРУЗКА (только для разработки)
    liveReload(checkUrl = '/kopilot/php/kopilot_reload.php', interval = 1000) {
        let lastTimestamp = null;
        console.log('[LiveReload] Запущен, мониторинг через', checkUrl);

        const check = async () => {
            try {
                const url = checkUrl + '?_=' + Date.now();
                const response = await fetch(url);
                if (!response.ok) {
                    console.warn('[LiveReload] Сервер вернул', response.status);
                    return;
                }
                const data = await response.json();
                const currentTimestamp = data.timestamp;
                console.log('[LiveReload] Проверка timestamp:', currentTimestamp);

                if (lastTimestamp === null) {
                    lastTimestamp = currentTimestamp;
                    console.log('[LiveReload] Первый запуск, timestamp сохранён');
                } else if (currentTimestamp !== lastTimestamp) {
                    console.log('[LiveReload] Изменения обнаружены! Перезагружаю...');
                    location.reload();
                }
            } catch (e) {
                console.warn('[LiveReload] Ошибка запроса:', e.message);
            }
        };

        setInterval(check, interval);
    },

    // ВНУТРЕННИЙ ХЕЛПЕР ДЛЯ ЗАПРОСОВ
    async _fetch(url, options = {}) {
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        const csrfInput = document.querySelector('input[name="_csrf"]');
        const csrfToken = csrfMeta?.content || csrfInput?.value || '';

        const headers = {
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.headers || {}),
        };

        if (!(options.body instanceof FormData)) {
            headers['Content-Type'] = 'application/json';
        }

        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        const fetchOptions = {
            method: options.method || 'GET',
            headers,
        };

        if (options.body) {
            fetchOptions.body = options.body instanceof FormData
                ? options.body
                : JSON.stringify(options.body);
        }

        const response = await fetch(url, fetchOptions);

        if (!response.ok) {
            if (response.status === 422) {
                const data = await response.json();
                if (data.errors) {
                    Object.entries(data.errors).forEach(([field, msg]) => {
                        const input = document.querySelector(`[name="${field}"]`);
                        if (input) {
                            input.classList.add('input-error');
                            const span = document.createElement('span');
                            span.className = 'error-message';
                            span.textContent = msg;
                            input.after(span);
                        }
                    });
                }
            }
            throw new Error(`HTTP ${response.status}`);
        }

        return response.json();
    },

    // GET-ЗАПРОС
    get(url, options = {}) {
        return this._fetch(url, { ...options, method: 'GET' });
    },

    // POST-ЗАПРОС
    post(url, data = {}, options = {}) {
        return this._fetch(url, { ...options, method: 'POST', body: data });
    },

    // ДЕЛЕГИРОВАНИЕ СОБЫТИЙ
    on(selector, eventType, handler, container = document) {
        container.addEventListener(eventType, function(e) {
            const target = e.target.closest(selector);
            if (target && container.contains(target)) {
                handler.call(target, e, target);
            }
        });
    },

    // БЕСКОНЕЧНАЯ ПРОКРУТКА
    infiniteScroll(containerSelector, callback, threshold = 200) {
        const container = document.querySelector(containerSelector);
        if (!container) return;

        let isLoading = false;

        const onScroll = async () => {
            const { scrollTop, scrollHeight, clientHeight } = container === document.body
                ? { scrollTop: window.pageYOffset, scrollHeight: document.documentElement.scrollHeight, clientHeight: window.innerHeight }
                : container;

            if (scrollTop + clientHeight >= scrollHeight - threshold && !isLoading) {
                isLoading = true;
                try {
                    await callback();
                } finally {
                    isLoading = false;
                }
            }
        };

        window.addEventListener('scroll', onScroll, { passive: true });
    },

    // УВЕДОМЛЕНИЯ (централизованный контейнер)
    flash(message, duration = 3000) {
        const toast = document.getElementById('global-toast');
        if (!toast) {
            const temp = document.createElement('div');
            temp.textContent = message;
            temp.style.position = 'fixed';
            temp.style.bottom = '20px';
            temp.style.right = '20px';
            temp.style.background = '#1e1e2f';
            temp.style.color = '#fff';
            temp.style.padding = '12px 20px';
            temp.style.borderRadius = '12px';
            temp.style.zIndex = '11000';
            document.body.appendChild(temp);
            setTimeout(() => temp.remove(), duration);
            return temp;
        }
        toast.textContent = message;
        toast.classList.add('visible');
        if (toast._timeout) clearTimeout(toast._timeout);
        if (duration > 0) {
            toast._timeout = setTimeout(() => {
                toast.classList.remove('visible');
            }, duration);
        }
        return toast;
    },

    // УТИЛИТЫ ДЛЯ DOM-МАНИПУЛЯЦИЙ
    $(selector, context = document) {
        return context.querySelector(selector);
    },

    show(el) {
        if (typeof el === 'string') el = this.$(el);
        if (el) el.style.display = '';
    },

    hide(el) {
        if (typeof el === 'string') el = this.$(el);
        if (el) el.style.display = 'none';
    },

    toggleClass(el, className) {
        if (typeof el === 'string') el = this.$(el);
        if (el) el.classList.toggle(className);
    },

    // ЭКРАНИРОВАНИЕ HTML
    esc(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    },

    // ВАЛИДАЦИЯ ЗНАЧЕНИЯ ПО ПРАВИЛАМ
    validateValue(value, rules) {
        if (!rules) return null;
        const ruleList = rules.split('|');
        for (const rule of ruleList) {
            if (rule === 'required' && (!value || value.trim() === '')) {
                return 'Поле обязательно для заполнения';
            }
            if (rule.startsWith('min:')) {
                const min = parseInt(rule.slice(4));
                if (value.length < min) {
                    return `Минимум ${min} символов`;
                }
            }
            if (rule.startsWith('max:')) {
                const max = parseInt(rule.slice(4));
                if (value.length > max) {
                    return `Максимум ${max} символов`;
                }
            }
            if (rule === 'alpha' && !/^[\p{L}\s\-]+$/u.test(value)) {
                return 'Только буквы, пробелы и дефисы';
            }
            if (rule === 'alphanumeric' && !/^[a-zA-Z0-9_]+$/.test(value)) {
                return 'Только латиница, цифры и _';
            }
        }
        return null;
    },

    // ДЕКЛАРАТИВНЫЕ ФОРМЫ
    form(formSelector, rules, onSubmit) {
        const form = this.$(formSelector);
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            const formData = new FormData(form);
            const data = {};
            for (const [name, value] of formData.entries()) {
                data[name] = value;
            }

            form.querySelectorAll('.input-error').forEach(el => el.classList.remove('input-error'));
            form.querySelectorAll('.error-message').forEach(el => el.remove());

            let hasErrors = false;
            for (const [field, ruleString] of Object.entries(rules)) {
                const value = data[field] ?? '';
                const error = this.validateValue(value, ruleString);
                if (error) {
                    hasErrors = true;
                    const input = form.querySelector(`[name="${field}"]`);
                    if (input) {
                        input.classList.add('input-error');
                        const span = document.createElement('span');
                        span.className = 'error-message';
                        span.textContent = error;
                        input.after(span);
                    }
                }
            }

            if (hasErrors) return;

            try {
                await onSubmit(data, form);
            } catch (err) {
                this.flash(err.message || 'Произошла ошибка');
            }
        });
    },

    // МОДАЛЬНЫЕ ОКНА
    modal(title, content, actions = [{ text: 'OK' }]) {
        return new Promise((resolve) => {
            const existing = document.querySelector('.kop-modal-overlay');
            if (existing) existing.remove();

            const overlay = document.createElement('div');
            overlay.className = 'kop-modal-overlay';

            const modal = document.createElement('div');

            if (title) {
                const titleEl = document.createElement('h3');
                titleEl.textContent = title;
                modal.appendChild(titleEl);
            }

            if (content) {
                const contentEl = document.createElement('div');
                contentEl.innerHTML = content;
                modal.appendChild(contentEl);
            }

            const buttonsRow = document.createElement('div');
            actions.forEach(action => {
                const btn = document.createElement('button');
                btn.textContent = action.text;
                btn.addEventListener('click', () => {
                    overlay.remove();
                    if (action.handler) action.handler();
                    resolve(action.text);
                });
                buttonsRow.appendChild(btn);
            });
            modal.appendChild(buttonsRow);

            overlay.appendChild(modal);

            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) {
                    overlay.remove();
                    resolve(null);
                }
            });

            document.body.appendChild(overlay);
        });
    },
};
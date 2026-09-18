(function () {
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

    // Sub-path the app is mounted under (e.g. "/locia"); empty for root deploys.
    // Prefix server-bound absolute paths so they survive a reverse-proxy mount.
    const BASE = (window.APP_BASE || '');
    const U = (p) => BASE + p;

    async function copyTextToClipboard(value) {
        const text = String(value ?? '');
        if (text === '') {
            return false;
        }

        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (error) {
                // HTTP deployments in the isolated network cannot always use
                // the modern Clipboard API. Fall through to the local DOM copy.
            }
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.setAttribute('aria-hidden', 'true');
        textarea.style.position = 'fixed';
        textarea.style.left = '-10000px';
        textarea.style.top = '0';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        textarea.setSelectionRange(0, textarea.value.length);
        try {
            return document.execCommand('copy');
        } catch (error) {
            return false;
        } finally {
            textarea.remove();
        }
    }

    function setSelectValue(select, value) {
        if (!select || select.value === value) {
            return;
        }
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function localIsoDate(date = new Date()) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function filterDictionaryOptions() {
        const project = document.querySelector('#task-project-select');
        if (!project) {
            return;
        }
        const projectId = project.value || '0';
        document.querySelectorAll('select[data-dictionary-select]').forEach((select) => {
            const previous = select.value;
            select.querySelectorAll('option[data-project]').forEach((option) => {
                const optionProject = option.dataset.project || '0';
                option.hidden = optionProject !== '0' && optionProject !== projectId;
            });
            const selected = select.options[select.selectedIndex];
            if (selected && selected.hidden) {
                setSelectValue(select, '');
            } else {
                setSelectValue(select, previous);
            }
        });
    }

    function syncTaskAssigneeVacation(form = document) {
        const assignee = form?.querySelector?.('[data-task-assignee]');
        const notice = form?.querySelector?.('[data-task-assignee-vacation]');
        const text = notice?.querySelector('[data-task-assignee-vacation-text]');
        const button = notice?.querySelector('[data-task-use-vacation-substitute]');
        if (!assignee || !notice || !text || !button) {
            return;
        }

        const option = assignee.options[assignee.selectedIndex];
        const dateFrom = option?.dataset.vacationFrom || '';
        const dateTo = option?.dataset.vacationTo || '';
        const substituteId = option?.dataset.vacationSubstituteId || '';
        const substituteName = option?.dataset.vacationSubstituteName || '';
        const due = form.querySelector('input[name="when_due"]')?.value || '';
        const today = localIsoDate();
        const relevantDate = due || today;
        const overlaps = dateFrom !== '' && dateTo !== '' && relevantDate >= dateFrom && relevantDate <= dateTo;

        notice.hidden = !overlaps;
        if (!overlaps) {
            button.dataset.substituteId = '';
            return;
        }
        text.textContent = `${option.textContent.split(' · отпуск ')[0]} в отпуске на выбранную дату. Замена: ${substituteName}.`;
        button.dataset.substituteId = substituteId;
        button.hidden = substituteId === '' || substituteId === '0';
    }

    function filterTaskDependencyOptions() {
        const project = document.querySelector('#task-project-select');
        const dependency = document.querySelector('[data-task-dependency-select]');
        if (!project || !dependency) {
            return;
        }
        const projectId = project.value || '';
        const selected = dependency.value;
        dependency.querySelectorAll('option[data-project]').forEach((option) => {
            option.hidden = projectId !== '' && option.dataset.project !== projectId;
        });
        const selectedOption = dependency.options[dependency.selectedIndex];
        setSelectValue(dependency, selectedOption && selectedOption.hidden ? '' : selected);
    }

    function filterProjectAccountingOptions() {
        const project = document.querySelector('#task-project-select');
        const pp = document.querySelector('[data-project-accounting-pp]');
        const btp = document.querySelector('[data-project-accounting-btp]');
        const projectId = project ? (project.value || '') : '';

        if (pp) {
            const selected = pp.value;
            pp.querySelectorAll('option[data-project]').forEach((option) => {
                option.hidden = projectId !== '' && option.dataset.project !== projectId;
            });
            const selectedOption = pp.options[pp.selectedIndex];
            setSelectValue(pp, selectedOption && selectedOption.hidden ? '' : selected);
        }

        if (btp) {
            const selected = btp.value;
            const ppId = pp ? (pp.value || '') : '';
            btp.querySelectorAll('option[data-project][data-pp]').forEach((option) => {
                const projectMatches = projectId === '' || option.dataset.project === projectId;
                const ppMatches = ppId === '' || option.dataset.pp === ppId;
                option.hidden = !projectMatches || !ppMatches;
            });
            const selectedOption = btp.options[btp.selectedIndex];
            setSelectValue(btp, selectedOption && selectedOption.hidden ? '' : selected);
        }
    }

    function syncProjectAccountingFromBtp() {
        const pp = document.querySelector('[data-project-accounting-pp]');
        const btp = document.querySelector('[data-project-accounting-btp]');
        if (!pp || !btp || !btp.value) {
            return;
        }

        const selectedOption = btp.options[btp.selectedIndex];
        const ppId = selectedOption ? (selectedOption.dataset.pp || '') : '';
        if (ppId !== '') {
            setSelectValue(pp, ppId);
        }
    }

    function syncCustomFieldsWithProject() {
        const project = document.querySelector('#task-project-select');
        const projectId = project ? project.value : '';
        document.querySelectorAll('[data-custom-project]').forEach((field) => {
            const fieldProject = field.dataset.customProject || '0';
            const active = fieldProject === '0' || (projectId !== '' && fieldProject === projectId);
            field.hidden = !active;
            field.querySelectorAll('input, select, textarea, button').forEach((control) => {
                control.disabled = !active;
            });
        });
        document.querySelectorAll('[data-custom-group]').forEach((group) => {
            const activeFields = group.querySelectorAll('[data-custom-project]:not([hidden])');
            group.hidden = activeFields.length === 0;
        });
        const shell = document.querySelector('[data-custom-fields-shell]');
        if (shell) {
            const hasVisibleRequired = Array.from(document.querySelectorAll('[data-custom-project]:not([hidden])'))
                .some((field) => field.querySelector('[required]'));
            if (hasVisibleRequired) {
                shell.open = true;
            }
        }
    }

    function syncTaskKind() {
        const form = document.querySelector('[data-task-intent-current]');
        if (!form) {
            return;
        }
        const checked = form.querySelector('[data-task-kind-control]:checked');
        const intent = checked ? checked.value : form.dataset.taskIntentCurrent || 'work';
        const taskType = checked?.dataset.taskType || 'work';
        form.dataset.taskIntentCurrent = intent;
        const taskTypeField = form.querySelector('[data-task-type-field]');
        if (taskTypeField) {
            taskTypeField.value = taskType;
        }
        const meta = form.querySelector('[data-task-kind-meta]');
        const selectedMeta = checked?.dataset.meta || '';
        if (meta && selectedMeta) {
            meta.textContent = selectedMeta;
        }
        const labelTargets = {
            due: checked?.dataset.dueLabel,
            assignee: checked?.dataset.assigneeLabel,
            what: checked?.dataset.whatLabel,
            why: checked?.dataset.whyLabel,
            composition: checked?.dataset.compositionMeta,
            source: checked?.dataset.sourceLabel
        };
        Object.entries(labelTargets).forEach(([name, value]) => {
            const target = form.querySelector(`[data-intent-label="${name}"]`);
            if (target && value) {
                target.textContent = value;
            }
        });
        const placeholderTargets = {
            title: checked?.dataset.titlePlaceholder,
            what: checked?.dataset.whatPlaceholder,
            why: checked?.dataset.whyPlaceholder
        };
        Object.entries(placeholderTargets).forEach(([name, value]) => {
            const target = form.querySelector(`[data-intent-placeholder="${name}"]`);
            if (target && value) {
                target.setAttribute('placeholder', value);
            }
        });
        const assigneeEmpty = form.querySelector('[data-intent-empty="assignee"]');
        if (assigneeEmpty && checked?.dataset.assigneeEmpty) {
            assigneeEmpty.textContent = checked.dataset.assigneeEmpty;
        }
        const assignee = form.querySelector('[name="assignee_id"]');
        if (assignee) {
            assignee.required = true;
        }
        form.querySelectorAll('[data-intent-panel]').forEach((panel) => {
            const intents = (panel.dataset.intentPanel || '').split(/\s+/);
            const strictEdit = panel.dataset.intentEditStrict === '1';
            const visible = (!strictEdit && form.dataset.editMode === '1') || intents.includes(intent);
            panel.hidden = !visible;
            panel.querySelectorAll('input, select, textarea, button').forEach((control) => {
                control.disabled = !visible;
            });
        });
        form.querySelectorAll('[data-task-kind-panel]').forEach((panel) => {
            const visible = (panel.dataset.taskKindPanel || '').split(/\s+/).includes(intent);
            panel.hidden = !visible;
        });
        const customShell = form.querySelector('[data-custom-fields-shell]');
        if (customShell && intent === 'bim_family_request') {
            customShell.open = true;
        }
    }

    function syncTaskCostGroup(form) {
        const assignee = form.querySelector('[data-task-assignee]');
        const output = form.querySelector('[data-task-cost-group-code]');
        const hint = form.querySelector('[data-task-cost-group-hint]');
        if (!assignee || !output) {
            return;
        }
        const option = assignee.options[assignee.selectedIndex];
        const code = option?.dataset.costGroupCode || '';
        output.value = code;
        if (hint) {
            hint.textContent = code
                ? `Автоматически: стоимостная группа исполнителя ${code}.`
                : 'У выбранного сотрудника не назначена стоимостная группа.';
            hint.classList.toggle('is-warning', code === '' && assignee.value !== '');
        }
    }

    function syncParticipantPicker(picker) {
        const summary = picker.querySelector('[data-participant-summary]');
        if (!summary) {
            return;
        }
        const checked = picker.querySelectorAll('input[type="checkbox"]:checked');
        const emptyText = summary.dataset.emptyText || 'Выберите из списка';
        summary.textContent = checked.length > 0 ? `Выбрано: ${checked.length}` : emptyText;
    }

    function initParticipantPickers() {
        document.querySelectorAll('[data-participant-picker]').forEach((picker) => {
            syncParticipantPicker(picker);
            picker.addEventListener('change', () => syncParticipantPicker(picker));
        });
    }

    function setTaskFiltersOpen(panel, toggle, open) {
        panel.classList.toggle('is-open', open);
        panel.setAttribute('aria-hidden', open ? 'false' : 'true');
        if ('inert' in panel) {
            panel.inert = !open;
        }
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function setReleaseNotesOpen(open) {
        const shell = document.querySelector('[data-release-notes]');
        if (!shell) {
            return;
        }
        shell.classList.toggle('is-open', open);
        shell.setAttribute('aria-hidden', open ? 'false' : 'true');
    }

    function initTaskFilters() {
        const panel = document.querySelector('[data-task-filters]');
        const toggle = document.querySelector('[data-task-filters-toggle]');
        if (!panel || !toggle) {
            return;
        }

        setTaskFiltersOpen(panel, toggle, panel.classList.contains('is-open'));
        toggle.addEventListener('click', function () {
            setTaskFiltersOpen(panel, toggle, !panel.classList.contains('is-open'));
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && panel.classList.contains('is-open')) {
                setReleaseNotesOpen(false);
                setTaskFiltersOpen(panel, toggle, false);
                toggle.focus();
            }
        });
    }

    function initTaskViewMemory() {
        const switcher = document.querySelector('[data-task-view-memory]');
        if (!switcher) {
            return;
        }
        const scope = switcher.dataset.taskViewScope || window.location.pathname;
        const storageKey = `task-view:${scope}`;
        const params = new URLSearchParams(window.location.search);
        const currentView = params.get('view');

        if (!currentView) {
            const savedView = storageGet(storageKey);
            if (savedView === 'table' || savedView === 'board') {
                params.set('view', savedView);
                const query = params.toString();
                window.location.replace(`${window.location.pathname}${query ? '?' + query : ''}${window.location.hash}`);
                return;
            }
        } else if (currentView === 'table' || currentView === 'board') {
            storageSet(storageKey, currentView);
        }

        switcher.querySelectorAll('[data-task-view-choice]').forEach((link) => {
            link.addEventListener('click', function () {
                const view = link.dataset.taskViewChoice;
                if (view === 'table' || view === 'board') {
                    storageSet(storageKey, view);
                }
            });
        });
    }

    function taskDrawerUrl(href, editByDefault = false) {
        const target = new URL(href, window.location.origin);
        target.searchParams.set('drawer', '1');
        if (editByDefault && !target.searchParams.has('edit')) {
            target.searchParams.set('edit', '1');
        }
        return `${target.pathname}${target.search}${target.hash}`;
    }

    function openTaskDrawer(href, title = '') {
        if (document.body.classList.contains('is-drawer-page')) {
            return false;
        }
        const drawer = document.querySelector('[data-task-drawer]');
        const frame = drawer?.querySelector('[data-task-drawer-frame]');
        const fullLink = drawer?.querySelector('[data-task-drawer-open]');
        const titleTarget = drawer?.querySelector('[data-task-drawer-title]');
        if (!drawer || !frame || !fullLink) {
            return false;
        }

        const target = taskDrawerUrl(href);
        frame.src = target;
        fullLink.href = href;
        if (titleTarget && title) {
            titleTarget.textContent = title.trim();
        }
        drawer.classList.add('is-open', 'is-loading');
        drawer.setAttribute('aria-hidden', 'false');
        drawer.removeAttribute('inert');
        document.body.classList.add('is-task-drawer-open');
        return true;
    }

    function closeTaskDrawer() {
        const drawer = document.querySelector('[data-task-drawer]');
        const frame = drawer?.querySelector('[data-task-drawer-frame]');
        if (!drawer) {
            return;
        }

        drawer.classList.remove('is-open', 'is-loading');
        drawer.setAttribute('aria-hidden', 'true');
        drawer.setAttribute('inert', '');
        document.body.classList.remove('is-task-drawer-open');
        window.setTimeout(() => {
            if (!drawer.classList.contains('is-open') && frame) {
                frame.removeAttribute('src');
            }
        }, 220);
    }

    function initTaskDrawer() {
        const drawer = document.querySelector('[data-task-drawer]');
        const frame = drawer?.querySelector('[data-task-drawer-frame]');
        if (!drawer || !frame) {
            return;
        }

        frame.addEventListener('load', () => {
            drawer.classList.remove('is-loading');
            try {
                const frameUrl = new URL(frame.contentWindow.location.href);
                const taskPage = /^\/tasks\/\d+$/.test(frameUrl.pathname);
                if (frameUrl.origin === window.location.origin && taskPage && frameUrl.searchParams.get('drawer') !== '1') {
                    frameUrl.searchParams.set('drawer', '1');
                    drawer.classList.add('is-loading');
                    frame.src = `${frameUrl.pathname}${frameUrl.search}${frameUrl.hash}`;
                }
            } catch (error) {
                // Cross-origin or transient iframe state; leave the loaded page as-is.
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && drawer.classList.contains('is-open')) {
                setReleaseNotesOpen(false);
                closeTaskDrawer();
            }
        });
    }

    // Поиск в выпадающих списках: прогрессивно превращаем <select> в комбобокс
    // с фильтром. Нативный <select> остаётся в DOM (скрыт) — он источник значения
    // и отправляется формой, поэтому при любой ошибке поведение откатывается к
    // обычному списку. На select можно повесить data-no-search чтобы не трогать.
    function enhanceSearchableSelects(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var selects = scope.querySelectorAll('select:not([multiple]):not([data-no-search])');
        Array.prototype.forEach.call(selects, function (select) {
            try {
                if (select.dataset.comboReady === '1') return;
                if (isFilterSelect(select)) return;
                if (select.options.length < 2) return;
                select.dataset.comboReady = '1';
                buildCombobox(select);
            } catch (e) { /* остаётся нативный select */ }
        });
    }

    function isFilterSelect(select) {
        if (select.closest('.filterbar, .filters, .admin-user-filterbar, .reports-filter-panel, .resource-filter-panel, .dpr-filter, .task-tag-filter, .notes-filter')) {
            return true;
        }
        var form = select.closest('form');
        if (!form) {
            return false;
        }
        return (form.getAttribute('method') || '').toLowerCase() === 'get';
    }

    function buildCombobox(select) {
        var wrap = document.createElement('div');
        wrap.className = 'combo';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'combo__input';
        input.setAttribute('autocomplete', 'off');
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-expanded', 'false');
        var label = select.getAttribute('aria-label') || '';
        if (!label && select.id) {
            var explicitLabel = document.querySelector('label[for="' + CSS.escape(select.id) + '"]');
            label = explicitLabel ? explicitLabel.textContent.trim() : '';
        }
        if (!label && select.closest('label')) {
            label = select.closest('label').textContent.trim();
        }
        input.setAttribute('aria-label', label || select.name || 'Выбор из списка');
        if (select.disabled) input.disabled = true;
        var list = document.createElement('div');
        list.className = 'combo__list';
        list.hidden = true;
        var error = document.createElement('small');
        error.className = 'field-error combo__error';
        error.dataset.comboError = '';
        error.setAttribute('role', 'alert');
        error.textContent = select.dataset.validationMessage || 'Выберите значение из списка.';
        error.hidden = true;

        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);
        wrap.appendChild(input);
        wrap.appendChild(error);
        wrap.appendChild(list);
        select.style.display = 'none';

        var activeIndex = -1;
        var visible = [];

        function currentLabel() {
            var o = select.options[select.selectedIndex];
            return o ? o.text : '';
        }
        function setFromSelect() { input.value = currentLabel(); }
        function syncValidity(showError) {
            var invalid = !select.disabled && !select.validity.valid;
            var visibleError = Boolean(showError && invalid);
            input.setAttribute('aria-required', select.required ? 'true' : 'false');
            input.setAttribute('aria-invalid', visibleError ? 'true' : 'false');
            error.hidden = !visibleError;
            select.closest('label')?.classList.toggle('form-field--error', visibleError);
            return invalid;
        }
        setFromSelect();
        syncValidity(false);

        var lastOptionCommitAt = 0;

        function chooseFromOptionEvent(value, ev) {
            if (ev) {
                ev.preventDefault();
                ev.stopPropagation();
            }
            var now = Date.now();
            if (now - lastOptionCommitAt < 80) {
                return;
            }
            lastOptionCommitAt = now;
            choose(value);
        }

        function renderList(filter) {
            list.innerHTML = '';
            visible = [];
            var f = (filter || '').toLowerCase().trim();
            Array.prototype.forEach.call(select.options, function (opt) {
                if (opt.hidden || opt.disabled) return;
                var text = opt.text || '';
                if (f && text.toLowerCase().indexOf(f) === -1) return;
                var row = document.createElement('div');
                row.className = 'combo__opt';
                if (opt.selected) row.classList.add('is-selected');
                row.textContent = text || '—';
                row.dataset.value = opt.value;
                row.addEventListener('pointerdown', function (ev) {
                    chooseFromOptionEvent(opt.value, ev);
                });
                row.addEventListener('mousedown', function (ev) {
                    chooseFromOptionEvent(opt.value, ev);
                });
                row.addEventListener('click', function (ev) {
                    chooseFromOptionEvent(opt.value, ev);
                });
                list.appendChild(row);
                visible.push(row);
            });
            if (!visible.length) {
                var empty = document.createElement('div');
                empty.className = 'combo__empty';
                empty.textContent = 'Ничего не найдено';
                list.appendChild(empty);
            }
            activeIndex = -1;
        }
        function optionText(option) {
            return (option && option.text ? option.text : '').replace(/\s+/g, ' ').trim();
        }
        function commitTypedValue() {
            var typed = (input.value || '').replace(/\s+/g, ' ').trim().toLowerCase();
            if (!typed) {
                setFromSelect();
                return;
            }

            var exact = null;
            var matches = [];
            Array.prototype.forEach.call(select.options, function (opt) {
                if (opt.hidden || opt.disabled) return;
                var text = optionText(opt);
                if (!text) return;
                var normalized = text.toLowerCase();
                if (normalized === typed) {
                    exact = opt;
                }
                if (normalized.indexOf(typed) !== -1) {
                    matches.push(opt);
                }
            });

            var chosen = exact || (matches.length === 1 ? matches[0] : null);
            if (chosen && select.value !== chosen.value) {
                select.value = chosen.value;
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            setFromSelect();
        }
        function open() {
            renderList('');
            list.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            input.select();
        }
        function close(shouldCommit) {
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            if (shouldCommit !== false) {
                commitTypedValue();
            }
        }
        function choose(value) {
            var changed = select.value !== value;
            if (changed) {
                select.value = value;
            }
            setFromSelect();
            close(false);
            if (changed) {
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            window.setTimeout(function () {
                list.hidden = true;
                input.setAttribute('aria-expanded', 'false');
                input.blur();
            }, 0);
        }
        function highlight(i) {
            visible.forEach(function (r, idx) { r.classList.toggle('is-active', idx === i); });
            if (visible[i]) visible[i].scrollIntoView({ block: 'nearest' });
            activeIndex = i;
        }

        input.addEventListener('focus', open);
        input.addEventListener('click', function () { if (list.hidden) open(); });
        input.addEventListener('input', function () { renderList(input.value); list.hidden = false; });
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'ArrowDown') { ev.preventDefault(); if (list.hidden) open(); highlight(Math.min(activeIndex + 1, visible.length - 1)); }
            else if (ev.key === 'ArrowUp') { ev.preventDefault(); highlight(Math.max(activeIndex - 1, 0)); }
            else if (ev.key === 'Enter') { if (!list.hidden && visible[activeIndex]) { ev.preventDefault(); choose(visible[activeIndex].dataset.value); } }
            else if (ev.key === 'Escape') { close(); }
        });
        input.addEventListener('blur', function () { window.setTimeout(close, 120); });
        input.comboCommitValue = commitTypedValue;
        input.comboSyncValidity = syncValidity;
        // Если значение select меняет другой код — отражаем в инпуте.
        select.addEventListener('change', function () {
            if (list.hidden) setFromSelect();
            syncValidity(input.getAttribute('aria-invalid') === 'true');
        });
        select.addEventListener('invalid', function (event) {
            if (select.style.display !== 'none') return;
            event.preventDefault();
            syncValidity(true);
            var active = document.activeElement;
            var form = select.closest('form');
            if (!active?.classList?.contains('combo__input') || active.closest('form') !== form) {
                input.focus();
                input.select();
            }
        });
        var form = select.closest('form');
        if (form && form.dataset.comboSubmitReady !== '1') {
            form.dataset.comboSubmitReady = '1';
            form.addEventListener('submit', function () {
                commitFormComboboxes(form);
            }, true);
        }
    }

    function commitFormComboboxes(form) {
        Array.prototype.forEach.call(form.querySelectorAll('.combo__input'), function (comboInput) {
            if (typeof comboInput.comboCommitValue === 'function') {
                comboInput.comboCommitValue();
            }
        });
    }

    function initExternalSubmitButtons(root) {
        var scope = root && root.querySelectorAll ? root : document;
        Array.prototype.forEach.call(scope.querySelectorAll('[data-submit-form]'), function (button) {
            if (button.dataset.submitReady === '1') return;
            button.dataset.submitReady = '1';
            button.addEventListener('click', function (event) {
                if (button.dataset.externalSubmitLocked === '1') {
                    event.preventDefault();
                    return;
                }
                var formId = button.getAttribute('data-submit-form') || button.getAttribute('form') || '';
                var form = formId ? document.getElementById(formId) : null;
                if (!form) return;
                event.preventDefault();
                commitFormComboboxes(form);
                button.dataset.externalSubmitLocked = '1';
                button.disabled = true;
                button.classList.add('is-disabled');
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    HTMLFormElement.prototype.submit.call(form);
                }
                window.setTimeout(function () {
                    if (form.dataset.submitLocked !== '1') {
                        button.dataset.externalSubmitLocked = '';
                        button.disabled = false;
                        button.classList.remove('is-disabled');
                    }
                }, 150);
            });
        });
    }

    // Единый поиск и для чекбоксных мультивыборов (наблюдатели/соавторы и пр.):
    // добавляем строку фильтра в меню — печатаешь, чекбоксы сужаются по имени.
    // Тот же принцип, что и комбобокс над <select>, просто другой рендер.
    function enhanceCheckboxPickers(root) {
        var scope = root && root.querySelectorAll ? root : document;
        var menus = scope.querySelectorAll('[data-participant-picker] .participant-picker__menu, [data-checkbox-search] .participant-picker__menu, .checkbox-menu[data-checkbox-search]');
        Array.prototype.forEach.call(menus, function (menu) {
            try {
                if (menu.dataset.searchReady === '1') return;
                var labels = menu.querySelectorAll('label');
                if (labels.length < 6) return; // короткий список — фильтр не нужен
                menu.dataset.searchReady = '1';
                var filter = document.createElement('input');
                filter.type = 'text';
                filter.className = 'picker-filter';
                filter.placeholder = 'Поиск…';
                filter.setAttribute('autocomplete', 'off');
                menu.insertBefore(filter, menu.firstChild);
                var rows = Array.prototype.slice.call(labels);
                filter.addEventListener('input', function () {
                    var f = filter.value.toLowerCase().trim();
                    rows.forEach(function (lab) {
                        var t = (lab.textContent || '').toLowerCase();
                        lab.style.display = (!f || t.indexOf(f) !== -1) ? '' : 'none';
                    });
                });
                // клик/ввод в поле не должен сворачивать <details>
                filter.addEventListener('click', function (e) { e.stopPropagation(); });
            } catch (e) { /* остаётся обычный список чекбоксов */ }
        });
    }

    function initBulkChecklists(root) {
        var scope = root && root.querySelectorAll ? root : document;
        Array.prototype.forEach.call(scope.querySelectorAll('[data-bulk-checklist]'), function (list) {
            if (list.dataset.bulkChecklistReady === '1') return;
            list.dataset.bulkChecklistReady = '1';
            var rows = Array.prototype.slice.call(list.querySelectorAll('[data-bulk-checklist-row]'));
            var checkboxes = rows.map(function (row) {
                return row.querySelector('[data-bulk-checklist-checkbox]');
            }).filter(Boolean);
            var search = list.querySelector('[data-bulk-checklist-search]');
            var count = list.querySelector('[data-bulk-checklist-count]');
            var summary = list.querySelector('[data-bulk-checklist-summary]');
            var empty = list.querySelector('[data-bulk-checklist-empty]');

            function updateState() {
                var selectedCount = 0;
                checkboxes.forEach(function (checkbox) {
                    if (checkbox.checked) selectedCount += 1;
                    var row = checkbox.closest('[data-bulk-checklist-row]');
                    if (row) row.classList.toggle('is-selected', checkbox.checked);
                });
                if (count) count.textContent = String(selectedCount);
                if (summary) {
                    var selectedNames = rows.filter(function (row) {
                        var checkbox = row.querySelector('[data-bulk-checklist-checkbox]');
                        return checkbox && checkbox.checked;
                    }).map(function (row) {
                        return (row.querySelector('strong') || row).textContent.trim();
                    });
                    summary.textContent = selectedNames.length === 0
                        ? 'Выбрать сотрудников'
                        : selectedNames.slice(0, 2).join(', ') + (selectedNames.length > 2 ? ' +' + (selectedNames.length - 2) : '');
                }
            }

            function applySearch() {
                var query = normalizeFilterText(search ? search.value : '');
                var visibleCount = 0;
                rows.forEach(function (row) {
                    var haystack = normalizeFilterText(row.dataset.bulkChecklistText || row.textContent || '');
                    var visible = !query || haystack.indexOf(query) !== -1;
                    row.hidden = !visible;
                    if (visible) visibleCount += 1;
                });
                if (empty) empty.hidden = visibleCount !== 0;
            }

            list.addEventListener('click', function (event) {
                var button = event.target.closest('[data-bulk-checklist-select]');
                if (!button || !list.contains(button)) return;
                var shouldSelect = button.dataset.bulkChecklistSelect === 'all';
                checkboxes.forEach(function (checkbox) {
                    if (!checkbox.disabled) checkbox.checked = shouldSelect;
                });
                updateState();
            });
            list.addEventListener('change', function (event) {
                if (event.target.matches('[data-bulk-checklist-checkbox]')) updateState();
            });
            if (search) search.addEventListener('input', applySearch);
            updateState();
            applySearch();
        });
    }

    function normalizeFilterText(value) {
        return (value || '').toString().replace(/\s+/g, ' ').trim().toLocaleLowerCase('ru-RU');
    }

    function cellFilterText(cell) {
        if (!cell) {
            return '';
        }
        return normalizeFilterText(cell.textContent || '');
    }

    function initDataTableFilters(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('table.data-table:not([data-no-column-filters])').forEach((table) => {
            if (table.dataset.columnFiltersReady === '1') {
                return;
            }
            const thead = table.tHead;
            const tbody = table.tBodies && table.tBodies[0];
            const headerRow = thead ? thead.rows[thead.rows.length - 1] : null;
            if (!thead || !tbody || !headerRow || headerRow.classList.contains('data-table-filter-row')) {
                return;
            }

            const rows = Array.from(tbody.rows).filter((row) => !row.classList.contains('data-table-filter-empty'));
            const dataRows = rows.filter((row) => !row.querySelector('.empty-state'));
            if (dataRows.length < 2) {
                return;
            }

            const headers = Array.from(headerRow.cells);
            const filterable = headers.map((cell, index) => {
                const title = normalizeFilterText(cell.textContent || '');
                const serviceColumn = cell.hasAttribute('data-no-column-filter') || title === '' || cell.querySelector('input, button, form') || index >= headers.length - 1 && title.length <= 2;
                return !serviceColumn;
            });
            if (!filterable.some(Boolean)) {
                return;
            }

            table.dataset.columnFiltersReady = '1';
            const filterRow = document.createElement('tr');
            filterRow.className = 'data-table-filter-row';
            const controls = [];

            headers.forEach((cell, index) => {
                const filterCell = document.createElement('th');
                if (!filterable[index]) {
                    filterCell.className = 'data-table-filter-row__empty';
                    filterRow.appendChild(filterCell);
                    return;
                }

                const title = (cell.textContent || '').replace(/\s+/g, ' ').trim();
                const values = Array.from(new Set(dataRows.map((row) => cellFilterText(row.cells[index])).filter(Boolean))).sort((a, b) => a.localeCompare(b, 'ru'));
                const useSelect = values.length > 0 && values.length <= 14 && values.every((value) => value.length <= 42);
                let control;
                if (useSelect) {
                    control = document.createElement('select');
                    control.dataset.noSearch = '1';
                    const all = document.createElement('option');
                    all.value = '';
                    all.textContent = 'Все';
                    control.appendChild(all);
                    values.forEach((value) => {
                        const option = document.createElement('option');
                        option.value = value;
                        option.textContent = value;
                        control.appendChild(option);
                    });
                } else {
                    control = document.createElement('input');
                    control.type = 'search';
                    control.placeholder = title || 'Фильтр';
                    control.autocomplete = 'off';
                }
                control.dataset.tableFilterColumn = String(index);
                control.setAttribute('aria-label', `Фильтр: ${title || 'колонка ' + (index + 1)}`);
                control.addEventListener('input', () => applyDataTableFilters(table, controls, dataRows));
                control.addEventListener('change', () => applyDataTableFilters(table, controls, dataRows));
                controls.push(control);
                filterCell.appendChild(control);
                filterRow.appendChild(filterCell);
            });

            thead.appendChild(filterRow);
            const emptyRow = document.createElement('tr');
            emptyRow.className = 'data-table-filter-empty';
            emptyRow.hidden = true;
            const emptyCell = document.createElement('td');
            emptyCell.colSpan = Math.max(1, headers.length);
            emptyCell.textContent = 'По фильтрам ничего не найдено';
            emptyRow.appendChild(emptyCell);
            tbody.appendChild(emptyRow);
        });
    }

    function applyDataTableFilters(table, controls, rows) {
        const active = controls
            .map((control) => ({
                column: Number(control.dataset.tableFilterColumn || '-1'),
                value: normalizeFilterText(control.value || ''),
                tag: control.tagName.toLowerCase()
            }))
            .filter((filter) => filter.column >= 0 && filter.value !== '');

        let visible = 0;
        rows.forEach((row) => {
            const matched = active.every((filter) => {
                const value = cellFilterText(row.cells[filter.column]);
                return filter.tag === 'select' ? value === filter.value : value.includes(filter.value);
            });
            row.classList.toggle('is-table-filter-hidden', !matched);
            if (matched && !row.hidden) {
                visible += 1;
            }
        });

        const empty = table.tBodies[0]?.querySelector('.data-table-filter-empty');
        if (empty) {
            empty.hidden = visible !== 0;
        }
    }

    function initProjectTaskFilters() {
        const panel = document.querySelector('[data-project-task-filters]');
        const board = document.querySelector('[data-project-task-board]');
        if (!panel || !board) {
            return;
        }

        const controls = {
            search: panel.querySelector('[data-project-task-filter="search"]'),
            status: panel.querySelector('[data-project-task-filter="status"]'),
            assignee: panel.querySelector('[data-project-task-filter="assignee"]'),
            deadline: panel.querySelector('[data-project-task-filter="deadline"]')
        };
        const count = panel.querySelector('[data-project-task-filter-count]');
        const cards = Array.from(document.querySelectorAll('[data-project-task-item]'));
        const calendarItems = Array.from(document.querySelectorAll('[data-project-task-calendar-item]'));
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        function dateValue(value) {
            if (!value || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
                return null;
            }
            const parsed = new Date(`${value}T00:00:00`);
            return Number.isNaN(parsed.getTime()) ? null : parsed;
        }

        function matchesDeadline(value, mode) {
            if (!mode) {
                return true;
            }
            const date = dateValue(value);
            if (mode === 'no_deadline') {
                return !date;
            }
            if (!date) {
                return false;
            }
            if (mode === 'overdue') {
                return date < today;
            }
            if (mode === 'today') {
                return date.getTime() === today.getTime();
            }
            if (mode === 'week') {
                const weekEnd = new Date(today);
                weekEnd.setDate(weekEnd.getDate() + 7);
                return date >= today && date <= weekEnd;
            }
            return true;
        }

        function itemMatches(item) {
            const search = normalizeFilterText(controls.search?.value || '');
            const status = controls.status?.value || '';
            const assignee = controls.assignee?.value || '';
            const deadline = controls.deadline?.value || '';
            if (search && !(item.dataset.projectTaskSearch || '').includes(search)) {
                return false;
            }
            if (status && item.dataset.projectTaskStatus !== status) {
                return false;
            }
            if (assignee !== '' && (item.dataset.projectTaskAssignee || '0') !== assignee) {
                return false;
            }
            return matchesDeadline(item.dataset.projectTaskDeadline || '', deadline);
        }

        function apply() {
            const visibleById = new Set();
            cards.forEach((card) => {
                const matched = itemMatches(card);
                card.classList.toggle('is-project-task-hidden', !matched);
                if (matched) {
                    visibleById.add(card.dataset.projectTaskId || '');
                }
            });
            calendarItems.forEach((item) => {
                item.classList.toggle('is-project-task-hidden', !visibleById.has(item.dataset.projectTaskId || '') && !itemMatches(item));
            });
            board.querySelectorAll('[data-project-task-column]').forEach((column) => {
                const visibleCards = column.querySelectorAll('[data-project-task-item]:not(.is-project-task-hidden)').length;
                const counter = column.querySelector('[data-project-task-column-count]');
                if (counter) {
                    counter.textContent = String(visibleCards);
                }
            });
            if (count) {
                count.textContent = String(visibleById.size);
            }
        }

        Object.values(controls).forEach((control) => {
            control?.addEventListener('input', apply);
            control?.addEventListener('change', apply);
        });
        panel.querySelector('[data-project-task-filter-reset]')?.addEventListener('click', () => {
            Object.values(controls).forEach((control) => {
                if (control) {
                    control.value = '';
                }
            });
            apply();
            controls.search?.focus();
        });
        apply();
    }

    function initTaskRegisterFilters() {
        const panel = document.querySelector('[data-task-register-filters]');
        if (!panel) {
            return;
        }

        const controls = {
            search: panel.querySelector('[data-task-register-filter="search"]'),
            status: panel.querySelector('[data-task-register-filter="status"]'),
            type: panel.querySelector('[data-task-register-filter="type"]'),
            section: panel.querySelector('[data-task-register-filter="section"]'),
            assignee: panel.querySelector('[data-task-register-filter="assignee"]'),
            deadline: panel.querySelector('[data-task-register-filter="deadline"]')
        };
        const rows = Array.from(document.querySelectorAll('[data-task-register-row]'));
        const groups = Array.from(document.querySelectorAll('[data-task-register-group]'));
        const empty = document.querySelector('[data-task-register-empty]');
        const count = panel.querySelector('[data-task-register-filter-count]');

        function rowMatches(row) {
            const search = normalizeFilterText(controls.search?.value || '');
            const status = controls.status?.value || '';
            const type = controls.type?.value || '';
            const section = normalizeFilterText(controls.section?.value || '');
            const assignee = normalizeFilterText(controls.assignee?.value || '');
            const deadline = normalizeFilterText(controls.deadline?.value || '');

            if (search && !(row.dataset.taskRegisterSearch || '').includes(search)) {
                return false;
            }
            if (status && row.dataset.taskRegisterStatus !== status) {
                return false;
            }
            if (type && row.dataset.taskRegisterType !== type) {
                return false;
            }
            if (section && !(row.dataset.taskRegisterSection || '').includes(section)) {
                return false;
            }
            if (assignee && !(row.dataset.taskRegisterAssignee || '').includes(assignee)) {
                return false;
            }
            return !deadline || (row.dataset.taskRegisterDeadline || '').includes(deadline);
        }

        function apply() {
            let visibleCount = 0;
            rows.forEach((row) => {
                const matched = rowMatches(row);
                row.classList.toggle('is-task-register-hidden', !matched);
                if (matched) {
                    visibleCount += 1;
                }
            });

            groups.forEach((group) => {
                const hasVisibleRows = Boolean(group.querySelector('[data-task-register-row]:not(.is-task-register-hidden)'));
                group.hidden = !hasVisibleRows;
            });

            if (empty) {
                empty.hidden = visibleCount !== 0;
            }
            if (count) {
                count.textContent = String(visibleCount);
            }
        }

        Object.values(controls).forEach((control) => {
            control?.addEventListener('input', apply);
            control?.addEventListener('change', apply);
        });
        panel.querySelector('[data-task-register-filter-reset]')?.addEventListener('click', () => {
            Object.values(controls).forEach((control) => {
                if (control) {
                    control.value = '';
                }
            });
            apply();
            controls.search?.focus();
        });
        apply();
    }

    function initProjectTeamFilter() {
        const input = document.querySelector('[data-project-team-filter]');
        if (!input) {
            return;
        }

        const rows = Array.from(document.querySelectorAll('[data-project-team-row]'));
        const count = document.querySelector('[data-project-team-count]');
        const empty = document.querySelector('[data-project-team-empty]');
        rows.forEach((row) => {
            const checkbox = row.querySelector('[data-team-member-toggle]');
            const controls = Array.from(row.querySelectorAll('[data-team-assignment-control]'));
            if (!checkbox) {
                return;
            }
            const syncState = () => row.classList.toggle('is-team-member-selected', checkbox.checked);
            checkbox.addEventListener('change', syncState);
            controls.forEach((control) => {
                control.addEventListener('change', () => {
                    if (control.value !== '' && !checkbox.checked && !checkbox.disabled) {
                        checkbox.checked = true;
                    }
                    syncState();
                });
            });
            syncState();
        });
        const apply = () => {
            const query = normalizeFilterText(input.value || '');
            let visible = 0;
            rows.forEach((row) => {
                const matched = !query || (row.dataset.projectTeamSearch || '').includes(query);
                row.hidden = !matched;
                if (matched) {
                    visible++;
                }
            });
            if (count) {
                count.textContent = visible === rows.length ? String(rows.length) : `${visible} / ${rows.length}`;
            }
            if (empty) {
                empty.hidden = visible > 0 || !query;
            }
        };

        input.addEventListener('input', apply);
        apply();
    }

    function initProjectStructureFilter() {
        const input = document.querySelector('[data-project-structure-filter]');
        if (!input) {
            return;
        }

        const rows = Array.from(document.querySelectorAll('[data-project-structure-row]'));
        const stages = Array.from(document.querySelectorAll('[data-project-structure-stage]'));
        const count = document.querySelector('[data-project-structure-count]');
        const empty = document.querySelector('[data-project-structure-empty]');
        const apply = () => {
            const query = normalizeFilterText(input.value || '');
            let visible = 0;
            rows.forEach((row) => {
                const matched = !query || (row.dataset.projectStructureSearch || '').includes(query);
                row.hidden = !matched;
                if (matched) {
                    visible++;
                }
            });
            stages.forEach((stage) => {
                const hasVisibleRows = Array.from(stage.querySelectorAll('[data-project-structure-row]')).some((row) => !row.hidden);
                stage.hidden = Boolean(query) && !hasVisibleRows;
                if (query && hasVisibleRows) {
                    stage.open = true;
                }
            });
            if (count) {
                count.textContent = visible === rows.length ? String(rows.length) : `${visible} / ${rows.length}`;
            }
            if (empty) {
                empty.hidden = rows.length > 0 ? (visible > 0 || !query) : false;
            }
        };

        input.addEventListener('input', apply);
        apply();
    }

    function initProjectPeopleFields(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('[data-project-people-field]').forEach((field) => {
            if (field.dataset.projectPeopleReady === '1') return;
            field.dataset.projectPeopleReady = '1';
            const select = field.querySelector('[data-project-people-add]');
            const selected = field.querySelector('[data-project-people-selected]');
            const empty = field.querySelector('[data-project-people-empty]');
            const inputName = field.dataset.projectPeopleName || '';
            if (!(select instanceof HTMLSelectElement) || !selected || !inputName) return;

            const syncEmpty = () => {
                if (empty) empty.hidden = selected.querySelector('[data-project-person-chip]') !== null;
            };
            const removeChip = (chip) => {
                const userId = chip.dataset.userId || '';
                const option = Array.from(select.options).find((item) => item.value === userId);
                if (option) option.hidden = false;
                chip.remove();
                syncEmpty();
            };
            selected.addEventListener('click', (event) => {
                const button = event.target.closest('[data-project-person-remove]');
                const chip = button?.closest('[data-project-person-chip]');
                if (chip && selected.contains(chip)) removeChip(chip);
            });
            select.addEventListener('change', () => {
                const option = select.selectedOptions[0];
                const userId = option?.value || '';
                if (!userId || selected.querySelector(`[data-user-id="${CSS.escape(userId)}"]`)) return;

                const chip = document.createElement('span');
                chip.className = 'project-team-person-chip';
                chip.dataset.projectPersonChip = '';
                chip.dataset.userId = userId;
                const name = document.createElement('span');
                name.textContent = option.dataset.personName || option.textContent.split(' · ')[0].trim();
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.dataset.projectPersonRemove = '';
                remove.setAttribute('aria-label', `Убрать сотрудника ${name.textContent}`);
                remove.textContent = '×';
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = inputName;
                hidden.value = userId;
                chip.append(name, remove, hidden);
                selected.appendChild(chip);
                select.value = '';
                option.hidden = true;
                select.dispatchEvent(new Event('change', { bubbles: true }));
                const combo = select.closest('.combo');
                const comboList = combo?.querySelector('.combo__list');
                const comboInput = combo?.querySelector('.combo__input');
                if (comboList) comboList.hidden = true;
                if (comboInput instanceof HTMLInputElement) {
                    comboInput.value = select.options[select.selectedIndex]?.text || 'Найти сотрудника';
                    comboInput.setAttribute('aria-expanded', 'false');
                }
                window.setTimeout(() => {
                    if (comboList) comboList.hidden = true;
                    if (comboInput instanceof HTMLInputElement) {
                        comboInput.value = select.options[select.selectedIndex]?.text || 'Найти сотрудника';
                        comboInput.setAttribute('aria-expanded', 'false');
                        comboInput.blur();
                    }
                }, 0);
                syncEmpty();
            });
            syncEmpty();
        });
    }

    function initProjectSectionAddForm() {
        const form = document.querySelector('[data-project-section-add-form]');
        if (!form) {
            return;
        }
        const catalog = form.querySelector('[data-project-section-catalog]');
        const draft = form.querySelector('[data-project-section-draft]');
        const code = form.querySelector('[data-project-section-code]');
        const title = form.querySelector('[data-project-section-title]');
        const titleField = form.querySelector('[data-project-section-title-field]');
        const start = form.querySelector('[data-project-section-add-start]');
        const cancel = form.querySelector('[data-project-section-add-cancel]');
        if (!catalog) {
            return;
        }
        const sync = () => {
            const usesCatalog = catalog.value !== '';
            const option = catalog.selectedOptions[0];
            if (code instanceof HTMLInputElement) {
                if (usesCatalog) code.value = option?.dataset.projectSectionOptionCode || catalog.value;
                code.readOnly = usesCatalog;
            }
            if (title instanceof HTMLInputElement) {
                if (usesCatalog) title.value = option?.dataset.projectSectionOptionTitle || option?.textContent?.trim() || '';
                title.disabled = usesCatalog;
            }
            if (titleField) titleField.hidden = usesCatalog;
        };
        const showDraft = () => {
            if (!draft) return;
            draft.hidden = false;
            start?.setAttribute('hidden', '');
            window.setTimeout(() => catalog.closest('.combo')?.querySelector('.combo__input')?.focus(), 0);
        };
        const clearDraft = () => {
            if (!draft) return;
            draft.querySelectorAll('[data-project-person-chip]').forEach((chip) => {
                const userId = chip.dataset.userId || '';
                const field = chip.closest('[data-project-people-field]');
                const select = field?.querySelector('[data-project-people-add]');
                const option = select ? Array.from(select.options).find((item) => item.value === userId) : null;
                if (option) option.hidden = false;
                chip.remove();
                const empty = field?.querySelector('[data-project-people-empty]');
                if (empty) empty.hidden = false;
            });
            catalog.value = '';
            catalog.dispatchEvent(new Event('change', { bubbles: true }));
            const catalogCombo = catalog.closest('.combo');
            const catalogInput = catalogCombo?.querySelector('.combo__input');
            const catalogList = catalogCombo?.querySelector('.combo__list');
            if (catalogList) catalogList.hidden = true;
            if (catalogInput instanceof HTMLInputElement) {
                catalogInput.value = catalog.options[catalog.selectedIndex]?.text || 'Свой раздел';
                catalogInput.setAttribute('aria-expanded', 'false');
                catalogInput.blur();
            }
            if (code instanceof HTMLInputElement) code.value = '';
            if (title instanceof HTMLInputElement) title.value = '';
            draft.hidden = true;
            start?.removeAttribute('hidden');
        };
        catalog.addEventListener('change', sync);
        start?.addEventListener('click', showDraft);
        cancel?.addEventListener('click', clearDraft);
        sync();
    }

    function initTaskAttachmentPickers(root = document) {
        root.querySelectorAll('[data-task-attachment-picker]').forEach((picker) => {
            if (picker.dataset.attachmentPickerReady === '1') return;
            picker.dataset.attachmentPickerReady = '1';
            const input = picker.querySelector('[data-task-attachment-input]');
            const selection = picker.querySelector('[data-task-attachment-selection]');
            const preview = picker.querySelector('[data-task-attachment-preview]');
            if (!(input instanceof HTMLInputElement)) return;

            let previewUrls = [];
            const clearPreviewUrls = () => {
                previewUrls.forEach((url) => URL.revokeObjectURL(url));
                previewUrls = [];
            };
            input.addEventListener('change', () => {
                clearPreviewUrls();
                const files = Array.from(input.files || []);
                const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
                if (selection) {
                    const total = totalBytes >= 1048576
                        ? `${(totalBytes / 1048576).toFixed(1).replace('.0', '')} МБ`
                        : `${Math.max(1, Math.round(totalBytes / 1024))} КБ`;
                    selection.textContent = files.length
                        ? `${files.length === 1 ? files[0].name : `Выбрано файлов: ${files.length}`} · ${total}`
                        : 'Файлы не выбраны';
                }
                if (!preview) return;
                preview.replaceChildren();
                files.filter((file) => file.type.startsWith('image/')).slice(0, 5).forEach((file) => {
                    const url = URL.createObjectURL(file);
                    previewUrls.push(url);
                    const image = document.createElement('img');
                    image.src = url;
                    image.alt = file.name;
                    preview.appendChild(image);
                });
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        enhanceSearchableSelects(document);
        initProjectPeopleFields(document);
        initExternalSubmitButtons(document);
        enhanceCheckboxPickers(document);
        initBulkChecklists(document);
        initDataTableFilters(document);
        initProjectTaskFilters();
        initTaskRegisterFilters();
        initProjectTeamFilter();
        initProjectStructureFilter();
        initProjectSectionAddForm();
        initParticipantPickers();
        initTaskAttachmentPickers();
        filterDictionaryOptions();
        filterTaskDependencyOptions();
        syncProjectAccountingFromBtp();
        filterProjectAccountingOptions();
        syncCustomFieldsWithProject();
        syncTaskKind();
        const taskForm = document.querySelector('[data-task-intent-current]');
        if (taskForm) {
            syncTaskCostGroup(taskForm);
            syncTaskAssigneeVacation(taskForm);
        }
        initTaskFilters();
        initTaskViewMemory();
        initTaskDrawer();
        initAdminUserFilters();
        initPeopleMatrixFilters();
    });
    document.addEventListener('change', function (event) {
        if (event.target && event.target.id === 'task-project-select') {
            filterDictionaryOptions();
            filterTaskDependencyOptions();
            filterProjectAccountingOptions();
            syncCustomFieldsWithProject();
            syncTaskKind();
        }
        if (event.target && event.target.matches('[data-project-accounting-pp]')) {
            filterProjectAccountingOptions();
        }
        if (event.target && event.target.matches('[data-project-accounting-btp]')) {
            syncProjectAccountingFromBtp();
            filterProjectAccountingOptions();
        }
        if (event.target && event.target.matches('[data-task-kind-control]')) {
            syncTaskKind();
        }
        if (event.target && event.target.matches('[data-task-assignee]')) {
            syncTaskCostGroup(event.target.form);
            syncTaskAssigneeVacation(event.target.form);
        }
        if (event.target && event.target.matches('input[name="when_due"]')) {
            syncTaskAssigneeVacation(event.target.form);
        }
        if (event.target && event.target.matches('[data-people-filter]')) {
            applyPeopleMatrixFilters();
        }
    });

    function initStaffingSearch() {
        const input = document.querySelector('[data-staffing-search]');
        if (!input) {
            return;
        }
        input.addEventListener('input', function () {
            const query = input.value.trim().toLocaleLowerCase('ru');
            document.querySelectorAll('[data-staffing-row]').forEach((row) => {
                row.hidden = query !== '' && !String(row.dataset.search || '').includes(query);
            });
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setReleaseNotesOpen(false);
        }
    });

    document.addEventListener('click', async function (event) {
        if (event.target.closest('[data-release-notes-open]')) {
            event.preventDefault();
            setReleaseNotesOpen(true);
            return;
        }

        if (event.target.closest('[data-release-notes-close]')) {
            event.preventDefault();
            setReleaseNotesOpen(false);
            return;
        }

        const dashboardToggle = event.target.closest('[data-dashboard-toggle]');
        if (dashboardToggle) {
            event.preventDefault();
            toggleDashboardReorder();
            return;
        }

        const dashboardReset = event.target.closest('[data-dashboard-reset]');
        if (dashboardReset) {
            event.preventDefault();
            resetDashboardOrder();
            return;
        }

        const dashboardSpanToggle = event.target.closest('[data-dashboard-span-toggle]');
        if (dashboardSpanToggle) {
            event.preventDefault();
            toggleDashboardWidgetSpan(dashboardSpanToggle);
            return;
        }

        const tourAction = event.target.closest('[data-tour-action]');
        if (tourAction) {
            event.preventDefault();
            handleTaskTourAction(tourAction.dataset.tourAction);
            return;
        }

        const tourStart = event.target.closest('[data-tour-start]');
        if (tourStart) {
            event.preventDefault();
            startTaskTourFromButton();
            return;
        }

        const addLinkButton = event.target.closest('[data-add-link-row]');
        if (addLinkButton) {
            const list = addLinkButton.closest('[data-link-list]');
            if (list) {
                const name = list.dataset.fieldName;
                const row = document.createElement('div');
                row.className = 'link-row';
                row.innerHTML = `
                    <input name="${name}[label][]" placeholder="Подпись">
                    <input name="${name}[url][]" placeholder="\\\\fileserver\\share\\folder или file://fileserver/share/folder">
                    <button class="btn btn-outline" type="button" data-remove-link-row>Убрать</button>
                `;
                list.insertBefore(row, addLinkButton);
                row.querySelector('input')?.focus();
            }
            return;
        }

        const removeLinkButton = event.target.closest('[data-remove-link-row]');
        if (removeLinkButton) {
            const list = removeLinkButton.closest('[data-link-list]');
            const row = removeLinkButton.closest('.link-row');
            if (list && row && list.querySelectorAll('.link-row').length > 1) {
                row.remove();
            } else if (row) {
                row.querySelectorAll('input').forEach((input) => input.value = '');
            }
            return;
        }

        const copyButton = event.target.closest('[data-copy]');
        if (copyButton) {
            const target = document.querySelector(copyButton.dataset.copy);
            if (target) {
                const originalText = copyButton.dataset.copyLabel || copyButton.textContent;
                if (await copyTextToClipboard(target.textContent.trim())) {
                    copyButton.textContent = 'Скопировано';
                } else {
                    window.prompt('Скопируйте текст', target.textContent.trim());
                }
                setTimeout(() => copyButton.textContent = originalText, 1400);
            }
            return;
        }

        const substituteButton = event.target.closest('[data-task-use-vacation-substitute]');
        if (substituteButton) {
            const form = substituteButton.closest('form');
            const assignee = form?.querySelector('[data-task-assignee]');
            const substituteId = substituteButton.dataset.substituteId || '';
            if (assignee && substituteId) {
                setSelectValue(assignee, substituteId);
            }
            return;
        }

        const taskDrawerClose = event.target.closest('[data-task-drawer-close]');
        if (taskDrawerClose) {
            event.preventDefault();
            closeTaskDrawer();
            return;
        }

        const taskDrawerLink = event.target.closest('[data-task-drawer-link]');
        if (taskDrawerLink && !event.metaKey && !event.ctrlKey && !event.shiftKey && event.button === 0) {
            const opened = openTaskDrawer(taskDrawerLink.href, taskDrawerLink.textContent || '');
            if (opened) {
                event.preventDefault();
                return;
            }
        }

        const taskDrawerRow = event.target.closest('[data-task-drawer-href]');
        if (taskDrawerRow && !event.target.closest('a, button, input, select, textarea, form')) {
            const opened = openTaskDrawer(taskDrawerRow.dataset.taskDrawerHref, taskDrawerRow.textContent || '');
            if (opened) {
                event.preventDefault();
                return;
            }
        }

        const row = event.target.closest('.clickable[data-href]');
        if (row && !event.target.closest('a, button, input, select, textarea, form')) {
            window.location.href = row.dataset.href;
        }
    });

    document.addEventListener('change', (event) => {
        const checkAll = event.target.closest('[data-check-all]');
        if (!checkAll) {
            return;
        }

        document.querySelectorAll(checkAll.dataset.checkAll).forEach((checkbox) => {
            const row = checkbox.closest('tr');
            if (row && row.hidden) {
                checkbox.checked = false;
                return;
            }
            checkbox.checked = checkAll.checked;
        });
    });

    const taskStatusLabels = {
        new: 'Новая',
        in_progress: 'В работе',
        review: 'На проверке',
        correction: 'Корректировка',
        pending_close: 'Ожидает принятия',
        done: 'Закрыта',
        blocked: 'Заблокирована',
        overdue: 'Просрочена'
    };
    let draggedCard = null;
    let kanbanDrag = null;
    let kanbanPointerDrag = null;
    let kanbanSuppressClick = false;

    document.addEventListener('dragstart', function (event) {
        const card = event.target.closest('.task-card[draggable="true"]');
        if (!card) {
            return;
        }

        const sourceColumn = card.closest('.kanban__column');
        const sourceBody = card.closest('[data-kanban-body]');
        if (!sourceColumn || !sourceBody || !event.dataTransfer) {
            event.preventDefault();
            return;
        }

        draggedCard = card;
        kanbanDrag = {
            card,
            sourceBody,
            sourceColumn,
            sourceNext: card.nextElementSibling,
            sourceStatus: card.dataset.taskStatus || sourceColumn.dataset.status || '',
            dropped: false
        };
        card.classList.add('is-kanban-dragging');
        card.setAttribute('aria-grabbed', 'true');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', card.dataset.taskId || '');
    });

    document.addEventListener('dragover', function (event) {
        const column = event.target.closest('.kanban__column');
        if (!column || !draggedCard) {
            return;
        }

        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'move';
        }
        clearKanbanDragOvers(column);
        column.classList.add('is-drag-over');
        const body = kanbanBody(column);
        if (body) {
            body.insertBefore(draggedCard, kanbanCardAfter(body, event.clientY));
        }
    });

    document.addEventListener('dragleave', function (event) {
        const column = event.target.closest('.kanban__column');
        if (column && !column.contains(event.relatedTarget)) {
            column.classList.remove('is-drag-over');
        }
    });

    document.addEventListener('dragend', function () {
        if (kanbanDrag && !kanbanDrag.dropped) {
            restoreKanbanCard(kanbanDrag);
            updateKanbanCounts();
        }
        finishKanbanDrag();
    });

    document.addEventListener('drop', async function (event) {
        const column = event.target.closest('.kanban__column');
        if (!column || !kanbanDrag || !draggedCard) {
            return;
        }

        event.preventDefault();
        const drag = kanbanDrag;
        const card = draggedCard;
        const body = kanbanBody(column);
        if (!body) {
            restoreKanbanCard(drag);
            finishKanbanDrag();
            return;
        }

        drag.dropped = true;
        body.insertBefore(card, kanbanCardAfter(body, event.clientY));
        clearKanbanDragOvers();
        await commitKanbanCardMove(card, column, drag);
    });

    document.addEventListener('pointerdown', function (event) {
        const card = event.target.closest('.task-card');
        if (!card || !card.closest('[data-kanban-board]') || event.button !== 0) {
            return;
        }
        if (event.target.closest('button, input, select, textarea')) {
            return;
        }

        const sourceColumn = card.closest('.kanban__column');
        const sourceBody = card.closest('[data-kanban-body]');
        if (!sourceColumn || !sourceBody) {
            return;
        }

        const rect = card.getBoundingClientRect();
        kanbanPointerDrag = {
            card,
            sourceBody,
            sourceColumn,
            sourceNext: card.nextElementSibling,
            sourceStatus: card.dataset.taskStatus || sourceColumn.dataset.status || '',
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
            width: rect.width,
            height: rect.height,
            dragging: false,
            dropped: false,
            placeholder: null
        };
    });

    document.addEventListener('pointermove', function (event) {
        if (!kanbanPointerDrag || event.pointerId !== kanbanPointerDrag.pointerId) {
            return;
        }

        const drag = kanbanPointerDrag;
        const dx = event.clientX - drag.startX;
        const dy = event.clientY - drag.startY;
        if (!drag.dragging && Math.hypot(dx, dy) < 6) {
            return;
        }

        event.preventDefault();
        if (!drag.dragging) {
            startKanbanPointerDrag(drag);
        }

        drag.card.style.left = `${Math.round(event.clientX - drag.offsetX)}px`;
        drag.card.style.top = `${Math.round(event.clientY - drag.offsetY)}px`;

        const column = kanbanColumnFromPoint(event.clientX, event.clientY);
        if (!column) {
            clearKanbanDragOvers();
            return;
        }

        clearKanbanDragOvers(column);
        column.classList.add('is-drag-over');
        const body = kanbanBody(column);
        if (body && drag.placeholder) {
            body.insertBefore(drag.placeholder, kanbanCardAfter(body, event.clientY));
        }
    });

    document.addEventListener('pointerup', function (event) {
        if (!kanbanPointerDrag || event.pointerId !== kanbanPointerDrag.pointerId) {
            return;
        }

        const drag = kanbanPointerDrag;
        kanbanPointerDrag = null;
        if (!drag.dragging) {
            return;
        }

        event.preventDefault();
        kanbanSuppressClick = true;
        window.setTimeout(() => {
            kanbanSuppressClick = false;
        }, 120);

        const placeholder = drag.placeholder;
        const column = placeholder?.closest('.kanban__column') || drag.sourceColumn;
        const body = kanbanBody(column);
        clearKanbanPointerStyles(drag);
        if (placeholder && body) {
            body.insertBefore(drag.card, placeholder);
            placeholder.remove();
        } else {
            restoreKanbanCard(drag);
        }
        clearKanbanDragOvers();
        updateKanbanCounts();
        void commitKanbanCardMove(drag.card, column, drag);
    });

    document.addEventListener('pointercancel', function (event) {
        if (!kanbanPointerDrag || event.pointerId !== kanbanPointerDrag.pointerId) {
            return;
        }

        const drag = kanbanPointerDrag;
        kanbanPointerDrag = null;
        if (drag.placeholder) {
            drag.placeholder.remove();
        }
        clearKanbanPointerStyles(drag);
        restoreKanbanCard(drag);
        clearKanbanDragOvers();
        updateKanbanCounts();
    });

    document.addEventListener('click', function (event) {
        if (!kanbanSuppressClick) {
            return;
        }

        kanbanSuppressClick = false;
        event.preventDefault();
        event.stopPropagation();
    }, true);

    async function commitKanbanCardMove(card, column, drag) {
        const previousStatus = drag.sourceStatus;
        const nextStatus = column === drag.sourceColumn ? previousStatus : (column.dataset.status || previousStatus);
        if (nextStatus === previousStatus) {
            updateKanbanCounts();
            finishKanbanDrag();
            return;
        }

        setTaskCardStatus(card, nextStatus);
        card.classList.add('is-kanban-saving');
        card.setAttribute('aria-busy', 'true');
        updateKanbanCounts();

        try {
            const response = await fetch(U(`/tasks/${card.dataset.taskId}/status`), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ status: nextStatus })
            });
            const payload = await kanbanResponsePayload(response);
            if (!response.ok || !payload.ok) {
                throw new Error(payload.message || 'Не удалось сменить статус задачи.');
            }

            setTaskCardStatus(card, nextStatus, payload.label);
            card.classList.add('is-kanban-saved');
            window.setTimeout(() => card.classList.remove('is-kanban-saved'), 900);
        } catch (error) {
            restoreKanbanCard(drag);
            setTaskCardStatus(card, previousStatus);
            updateKanbanCounts();
            showKanbanMessage(card.closest('[data-kanban-board]'), error.message || 'Не удалось сменить статус задачи.', 'error');
        } finally {
            card.classList.remove('is-kanban-saving');
            card.removeAttribute('aria-busy');
            finishKanbanDrag();
        }
    }

    function startKanbanPointerDrag(drag) {
        draggedCard = drag.card;
        kanbanDrag = drag;
        drag.dragging = true;
        drag.placeholder = document.createElement('div');
        drag.placeholder.className = 'kanban-placeholder';
        drag.placeholder.style.height = `${Math.round(drag.height)}px`;
        drag.sourceBody.insertBefore(drag.placeholder, drag.card.nextSibling);
        drag.card.classList.add('is-kanban-dragging');
        drag.card.setAttribute('aria-grabbed', 'true');
        drag.card.style.position = 'fixed';
        drag.card.style.left = `${Math.round(drag.startX - drag.offsetX)}px`;
        drag.card.style.top = `${Math.round(drag.startY - drag.offsetY)}px`;
        drag.card.style.width = `${Math.round(drag.width)}px`;
        drag.card.style.zIndex = '80';
        drag.card.style.pointerEvents = 'none';
    }

    function clearKanbanPointerStyles(drag) {
        drag.card.classList.remove('is-kanban-dragging');
        drag.card.setAttribute('aria-grabbed', 'false');
        drag.card.style.position = '';
        drag.card.style.left = '';
        drag.card.style.top = '';
        drag.card.style.width = '';
        drag.card.style.zIndex = '';
        drag.card.style.pointerEvents = '';
    }

    function kanbanColumnFromPoint(x, y) {
        const element = document.elementFromPoint(x, y);
        return element ? element.closest('.kanban__column') : null;
    }

    function kanbanBody(column) {
        return column.querySelector('[data-kanban-body]') || column;
    }

    function kanbanCardAfter(body, pointerY) {
        const cards = Array.from(body.querySelectorAll('.task-card:not(.is-kanban-dragging), .kanban-placeholder'));
        return cards.reduce((closest, card) => {
            const rect = card.getBoundingClientRect();
            const offset = pointerY - rect.top - rect.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset, card };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY, card: null }).card;
    }

    function clearKanbanDragOvers(exceptColumn = null) {
        document.querySelectorAll('.kanban__column.is-drag-over').forEach((column) => {
            if (column !== exceptColumn) {
                column.classList.remove('is-drag-over');
            }
        });
    }

    function updateKanbanCounts() {
        document.querySelectorAll('.kanban__column').forEach((column) => {
            const count = column.querySelector('[data-kanban-count]');
            const body = kanbanBody(column);
            if (count && body) {
                count.textContent = String(body.querySelectorAll('.task-card').length);
            }
        });
    }

    function finishKanbanDrag() {
        if (draggedCard) {
            draggedCard.classList.remove('is-kanban-dragging');
            draggedCard.setAttribute('aria-grabbed', 'false');
        }
        clearKanbanDragOvers();
        draggedCard = null;
        kanbanDrag = null;
    }

    function restoreKanbanCard(drag) {
        if (!drag || !drag.card || !drag.sourceBody) {
            return;
        }

        if (drag.sourceNext && drag.sourceNext.parentElement === drag.sourceBody) {
            drag.sourceBody.insertBefore(drag.card, drag.sourceNext);
        } else {
            drag.sourceBody.appendChild(drag.card);
        }
    }

    function setTaskCardStatus(card, status, label = null) {
        card.dataset.taskStatus = status;
        const badge = card.querySelector('[data-task-status-label]');
        if (!badge) {
            return;
        }

        Array.from(badge.classList).forEach((className) => {
            if (className.startsWith('status--')) {
                badge.classList.remove(className);
            }
        });
        badge.classList.add(`status--${status}`);
        badge.textContent = label || taskStatusLabels[status] || status;
    }

    async function kanbanResponsePayload(response) {
        const contentType = response.headers.get('content-type') || '';
        if (contentType.includes('application/json')) {
            return response.json();
        }

        const text = await response.text();
        return {
            ok: false,
            message: text || 'Не удалось сменить статус задачи.'
        };
    }

    function showKanbanMessage(board, message, type) {
        if (!board) {
            return;
        }

        let element = board.querySelector('[data-kanban-message]');
        if (!element) {
            element = document.createElement('div');
            element.dataset.kanbanMessage = '1';
            board.prepend(element);
        }

        element.className = `kanban-message kanban-message--${type || 'info'}`;
        element.textContent = message;
        window.clearTimeout(element._kanbanTimer);
        element._kanbanTimer = window.setTimeout(() => element.remove(), 3600);
    }

    let dashboardDrag = null;
    let dashboardResize = null;
    let dashboardEditMode = false;
    let dashboardZIndex = 20;

    function dashboardGrid() {
        return document.querySelector('[data-dashboard-grid]');
    }

    function dashboardStorageKey(grid) {
        return `dpr-dashboard-grid:${grid.dataset.dashboardVersion || 'v1'}`;
    }

    function dashboardLegacyOrderKey(grid) {
        return `dpr-dashboard-order:${grid.dataset.dashboardVersion || 'v1'}`;
    }

    function dashboardLegacyLayoutKey(grid) {
        return `dpr-dashboard-layout:${grid.dataset.dashboardVersion || 'v1'}`;
    }

    function dashboardWidgets(grid) {
        return Array.from(grid.querySelectorAll('[data-dashboard-widget]'));
    }

    function initDirectorDashboard() {
        const grid = dashboardGrid();
        if (!grid) {
            return;
        }

        dashboardWidgets(grid).forEach((widget, index) => {
            widget.dataset.dashboardDefaultIndex = String(index);
        });
        storageRemove(dashboardLegacyOrderKey(grid));
        storageRemove(dashboardLegacyLayoutKey(grid));
        applyDashboardLayout(grid);
        updateDashboardResetButton(grid);

        grid.addEventListener('pointerdown', function (event) {
            const resize = event.target.closest('[data-dashboard-resize]');
            const resizeWidget = resize ? resize.closest('[data-dashboard-widget]') : null;
            if (dashboardEditMode && resize && resizeWidget) {
                startDashboardResize(event, grid, resizeWidget, resize);
                return;
            }

            const handle = event.target.closest('[data-dashboard-handle]');
            const widget = handle ? handle.closest('[data-dashboard-widget]') : null;
            if (!dashboardEditMode || !handle || !widget) {
                return;
            }

            startDashboardDrag(event, grid, widget, handle);
        });

        document.addEventListener('pointermove', moveDashboardDrag);
        document.addEventListener('pointermove', moveDashboardResize);
        document.addEventListener('pointerup', endDashboardDrag);
        document.addEventListener('pointerup', endDashboardResize);
        document.addEventListener('pointercancel', endDashboardDrag);
        document.addEventListener('pointercancel', endDashboardResize);
        window.addEventListener('resize', function () {
            if (grid.classList.contains('is-positioned')) {
                layoutDashboardSlots(grid);
            }
        });
    }

    function applyDashboardLayout(grid) {
        const saved = storageGet(dashboardStorageKey(grid));
        if (!saved) {
            return false;
        }

        let layout;
        try {
            layout = JSON.parse(saved);
        } catch (error) {
            storageRemove(dashboardStorageKey(grid));
            return false;
        }

        if (!layout || !Array.isArray(layout.order)) {
            storageRemove(dashboardStorageKey(grid));
            return false;
        }

        assignDashboardSlots(grid, layout.order, layout.spans || {}, layout.heights || {});
        grid.classList.add('is-positioned');
        layoutDashboardSlots(grid);

        return true;
    }

    function ensureDashboardPositioning(grid) {
        if (grid.classList.contains('is-positioned')) {
            return;
        }

        assignDashboardSlots(grid);
        grid.classList.add('is-positioned');
        layoutDashboardSlots(grid);
    }

    function assignDashboardSlots(grid, order = null, spans = {}, heights = {}) {
        const widgets = dashboardWidgets(grid);
        const byKey = new Map(widgets.map((widget) => [widget.dataset.dashboardWidget, widget]));
        const assigned = new Set();
        let slot = 0;

        (order || widgets.map((widget) => widget.dataset.dashboardWidget)).forEach((key) => {
            const widget = byKey.get(key);
            if (!widget || assigned.has(widget)) {
                return;
            }

            widget.dataset.dashboardSlot = String(slot);
            widget.dataset.dashboardDesiredSpan = String(savedDashboardWidgetSpan(widget, spans));
            setSavedDashboardWidgetHeight(widget, heights);
            assigned.add(widget);
            slot += 1;
        });

        widgets
            .filter((widget) => !assigned.has(widget))
            .sort((a, b) => Number(a.dataset.dashboardDefaultIndex || 0) - Number(b.dataset.dashboardDefaultIndex || 0))
            .forEach((widget) => {
                widget.dataset.dashboardSlot = String(slot);
                widget.dataset.dashboardDesiredSpan = String(savedDashboardWidgetSpan(widget, spans));
                setSavedDashboardWidgetHeight(widget, heights);
                slot += 1;
            });
    }

    function savedDashboardWidgetSpan(widget, spans) {
        const key = widget.dataset.dashboardWidget || '';
        const saved = Number(spans[key]);
        if (Number.isFinite(saved) && saved > 0) {
            return clamp(Math.round(saved), 1, 2);
        }

        return dashboardDefaultWidgetSpan(widget);
    }

    function setSavedDashboardWidgetHeight(widget, heights) {
        const key = widget.dataset.dashboardWidget || '';
        const saved = Number(heights[key]);
        if (Number.isFinite(saved) && saved > 0) {
            widget.dataset.dashboardDesiredHeight = String(Math.round(saved));
            return;
        }

        delete widget.dataset.dashboardDesiredHeight;
    }

    function orderedDashboardWidgets(grid) {
        return dashboardWidgets(grid)
            .slice()
            .sort((a, b) => Number(a.dataset.dashboardSlot || 0) - Number(b.dataset.dashboardSlot || 0));
    }

    function dashboardColumns(grid) {
        if (grid.clientWidth < 680) {
            return 1;
        }

        return 2;
    }

    function dashboardGap(grid) {
        const gap = parseFloat(window.getComputedStyle(grid).gap);
        return Number.isFinite(gap) ? gap : 10;
    }

    function dashboardCellHeight(grid) {
        const rect = grid.getBoundingClientRect();
        const available = Math.max(320, window.innerHeight - rect.top - 80);
        if (grid.clientWidth < 680) {
            return clamp(Math.round(available * 0.62), 300, 520);
        }

        return clamp(Math.round(available / 2), 280, 430);
    }

    function dashboardDefaultWidgetSpan(widget) {
        return widget.classList.contains('dpr-span-12') ? 2 : 1;
    }

    function dashboardWidgetSpan(widget) {
        const desired = Number(widget.dataset.dashboardDesiredSpan || dashboardDefaultWidgetSpan(widget));
        return clamp(Math.round(Number.isFinite(desired) ? desired : 1), 1, 2);
    }

    function dashboardMinimumWidgetHeight(grid) {
        return grid.clientWidth < 680 ? 260 : 220;
    }

    function dashboardMaximumWidgetHeight(grid) {
        return Math.max(dashboardMinimumWidgetHeight(grid), Math.min(1100, Math.round(window.innerHeight * 1.35)));
    }

    function dashboardWidgetHeight(grid, widget, defaultHeight) {
        const desired = Number(widget.dataset.dashboardDesiredHeight || 0);
        if (!Number.isFinite(desired) || desired <= 0) {
            return defaultHeight;
        }

        return clamp(Math.round(desired), dashboardMinimumWidgetHeight(grid), dashboardMaximumWidgetHeight(grid));
    }

    function layoutDashboardSlots(grid, excludedWidget = null) {
        const columns = dashboardColumns(grid);
        const gap = dashboardGap(grid);
        const cellHeight = dashboardCellHeight(grid);
        const minTop = dashboardMinimumWidgetTop(grid);
        const cellWidth = Math.floor((grid.clientWidth - gap * (columns - 1)) / columns);
        let column = 0;
        let row = 0;
        let y = minTop;
        let rowHeight = 0;
        let maxBottom = minTop;

        grid.style.setProperty('--dashboard-grid-top', `${minTop}px`);
        grid.style.setProperty('--dashboard-grid-step-x', `${cellWidth + gap}px`);
        grid.style.setProperty('--dashboard-grid-step-y', `${cellHeight + gap}px`);
        grid.style.setProperty('--dashboard-cell-height', `${cellHeight}px`);

        orderedDashboardWidgets(grid).forEach((widget) => {
            const span = Math.min(columns, dashboardWidgetSpan(widget));
            if (column + span > columns) {
                y += rowHeight + gap;
                row += 1;
                column = 0;
                rowHeight = 0;
            }

            const x = column * (cellWidth + gap);
            const width = span * cellWidth + (span - 1) * gap;
            const height = dashboardWidgetHeight(grid, widget, cellHeight);
            widget.dataset.dashboardColumn = String(column);
            widget.dataset.dashboardRow = String(row);
            widget.dataset.dashboardSpan = String(span);
            widget.classList.toggle('is-dashboard-merged', span > 1);

            if (widget !== excludedWidget) {
                setDashboardWidgetPosition(widget, x, y, width, height, Number(widget.style.zIndex) || 1);
            }

            rowHeight = Math.max(rowHeight, height);
            maxBottom = Math.max(maxBottom, y + height + gap);
            column += span;
            if (column >= columns) {
                y += rowHeight + gap;
                row += 1;
                column = 0;
                rowHeight = 0;
            }
        });

        grid.style.minHeight = `${Math.ceil(maxBottom)}px`;
        updateDashboardSpanButtons(grid);
    }

    function setDashboardWidgetPosition(widget, x, y, width, height, zIndex) {
        widget.style.left = `${Math.round(x)}px`;
        widget.style.top = `${Math.round(y)}px`;
        widget.style.width = `${Math.round(width)}px`;
        widget.style.height = `${Math.round(height)}px`;
        widget.style.zIndex = String(zIndex || 1);
    }

    function dashboardMinimumWidgetTop(grid) {
        const metrics = grid.querySelector('.dpr-metrics');
        if (!metrics) {
            return 0;
        }

        const gridRect = grid.getBoundingClientRect();
        const metricsRect = metrics.getBoundingClientRect();
        return Math.max(0, Math.round(metricsRect.bottom - gridRect.top + 10));
    }

    function saveDashboardLayout(grid) {
        const layout = {
            order: orderedDashboardWidgets(grid).map((widget) => widget.dataset.dashboardWidget).filter(Boolean),
            spans: {},
            heights: {}
        };
        orderedDashboardWidgets(grid).forEach((widget) => {
            const key = widget.dataset.dashboardWidget;
            if (key) {
                layout.spans[key] = dashboardWidgetSpan(widget);
                const desiredHeight = Number(widget.dataset.dashboardDesiredHeight || 0);
                if (Number.isFinite(desiredHeight) && desiredHeight > 0) {
                    layout.heights[key] = Math.round(desiredHeight);
                }
            }
        });
        storageSet(dashboardStorageKey(grid), JSON.stringify(layout));
    }

    function resetDashboardOrder() {
        const grid = dashboardGrid();
        if (!grid) {
            return;
        }

        storageRemove(dashboardStorageKey(grid));
        storageRemove(dashboardLegacyOrderKey(grid));
        storageRemove(dashboardLegacyLayoutKey(grid));
        clearDashboardLayout(grid);
        setDashboardEditMode(false);
        updateDashboardResetButton(grid);
    }

    function toggleDashboardReorder() {
        setDashboardEditMode(!dashboardEditMode);
    }

    function toggleDashboardWidgetSpan(button) {
        const grid = dashboardGrid();
        const widget = button.closest('[data-dashboard-widget]');
        if (!grid || !widget || !dashboardEditMode) {
            return;
        }
        if (dashboardColumns(grid) < 2) {
            return;
        }

        const current = dashboardWidgetSpan(widget);
        widget.dataset.dashboardDesiredSpan = String(current > 1 ? 1 : 2);
        layoutDashboardSlots(grid);
        saveDashboardLayout(grid);
        updateDashboardResetButton(grid);
    }

    function setDashboardEditMode(enabled) {
        const grid = dashboardGrid();
        if (!grid) {
            return;
        }

        if (enabled) {
            ensureDashboardPositioning(grid);
        }

        dashboardEditMode = enabled;
        grid.classList.toggle('is-reordering', enabled);

        const toggle = document.querySelector('[data-dashboard-toggle]');
        if (toggle) {
            toggle.textContent = enabled ? 'Готово' : 'Переставить';
            toggle.classList.toggle('is-active', enabled);
        }

        if (!enabled && !storageGet(dashboardStorageKey(grid))) {
            clearDashboardLayout(grid);
        }

        updateDashboardResetButton(grid);
        updateDashboardSpanButtons(grid);
    }

    function updateDashboardResetButton(grid) {
        const reset = document.querySelector('[data-dashboard-reset]');
        if (!reset) {
            return;
        }

        reset.hidden = !storageGet(dashboardStorageKey(grid));
    }

    function updateDashboardSpanButtons(grid) {
        const columns = dashboardColumns(grid);
        dashboardWidgets(grid).forEach((widget) => {
            const button = widget.querySelector('[data-dashboard-span-toggle]');
            if (!button) {
                return;
            }

            const merged = dashboardWidgetSpan(widget) > 1;
            button.disabled = !dashboardEditMode || columns < 2;
            button.setAttribute('aria-label', merged ? 'Разделить ячейки' : 'Объединить ячейки');
            button.setAttribute('title', merged ? 'Разделить ячейки' : 'Объединить ячейки');
        });
    }

    function startDashboardDrag(event, grid, widget, handle) {
        event.preventDefault();
        event.stopPropagation();
        ensureDashboardPositioning(grid);
        dashboardZIndex += 1;
        widget.style.zIndex = String(dashboardZIndex);

        dashboardDrag = {
            grid,
            handle,
            widget,
            pointerId: event.pointerId,
            startX: event.clientX,
            startY: event.clientY,
            left: parseFloat(widget.style.left) || 0,
            top: parseFloat(widget.style.top) || 0,
            width: widget.offsetWidth,
            height: widget.offsetHeight,
            moved: false
        };
        widget.classList.add('is-dashboard-dragging');
        grid.classList.add('is-dragging-widget');

        if (handle.setPointerCapture) {
            try {
                handle.setPointerCapture(event.pointerId);
            } catch (error) {
                // Pointer capture is optional; document listeners still keep the drag alive.
            }
        }
    }

    function moveDashboardDrag(event) {
        if (!dashboardDrag || event.pointerId !== dashboardDrag.pointerId) {
            return;
        }

        event.preventDefault();
        const dx = event.clientX - dashboardDrag.startX;
        const dy = event.clientY - dashboardDrag.startY;
        const x = clamp(dashboardDrag.left + dx, 0, Math.max(0, dashboardDrag.grid.clientWidth - dashboardDrag.width));
        const y = Math.max(dashboardMinimumWidgetTop(dashboardDrag.grid), dashboardDrag.top + dy);
        setDashboardWidgetPosition(
            dashboardDrag.widget,
            x,
            y,
            dashboardDrag.width,
            dashboardDrag.height,
            dashboardZIndex
        );
        dashboardDrag.moved = dashboardDrag.moved || Math.abs(dx) > 2 || Math.abs(dy) > 2;

        const target = dashboardSwapTarget(dashboardDrag.grid, dashboardDrag.widget);
        if (target) {
            swapDashboardSlots(dashboardDrag.grid, dashboardDrag.widget, target);
            layoutDashboardSlots(dashboardDrag.grid, dashboardDrag.widget);
        }
    }

    function endDashboardDrag(event) {
        if (!dashboardDrag || event.pointerId !== dashboardDrag.pointerId) {
            return;
        }

        const drag = dashboardDrag;
        drag.widget.classList.remove('is-dashboard-dragging');
        drag.grid.classList.remove('is-dragging-widget');
        if (drag.handle.releasePointerCapture) {
            try {
                drag.handle.releasePointerCapture(event.pointerId);
            } catch (error) {
                // Some browsers release capture automatically.
            }
        }
        dashboardDrag = null;

        if (drag.moved) {
            const target = dashboardSwapTarget(drag.grid, drag.widget);
            if (target) {
                swapDashboardSlots(drag.grid, drag.widget, target);
            }
            layoutDashboardSlots(drag.grid);
            saveDashboardLayout(drag.grid);
            updateDashboardResetButton(drag.grid);
        } else {
            layoutDashboardSlots(drag.grid);
        }
    }

    function startDashboardResize(event, grid, widget, handle) {
        event.preventDefault();
        event.stopPropagation();
        ensureDashboardPositioning(grid);
        dashboardZIndex += 1;
        widget.style.zIndex = String(dashboardZIndex);

        dashboardResize = {
            grid,
            handle,
            widget,
            pointerId: event.pointerId,
            startY: event.clientY,
            startHeight: widget.offsetHeight,
            moved: false
        };
        widget.classList.add('is-dashboard-dragging');
        grid.classList.add('is-resizing-widget');

        if (handle.setPointerCapture) {
            try {
                handle.setPointerCapture(event.pointerId);
            } catch (error) {
                // Pointer capture is optional; document listeners still keep the resize alive.
            }
        }
    }

    function moveDashboardResize(event) {
        if (!dashboardResize || event.pointerId !== dashboardResize.pointerId) {
            return;
        }

        event.preventDefault();
        const dy = event.clientY - dashboardResize.startY;
        const height = clamp(
            dashboardResize.startHeight + dy,
            dashboardMinimumWidgetHeight(dashboardResize.grid),
            dashboardMaximumWidgetHeight(dashboardResize.grid)
        );
        dashboardResize.widget.dataset.dashboardDesiredHeight = String(Math.round(height));
        dashboardResize.widget.style.height = `${Math.round(height)}px`;
        dashboardResize.moved = dashboardResize.moved || Math.abs(dy) > 2;
    }

    function endDashboardResize(event) {
        if (!dashboardResize || event.pointerId !== dashboardResize.pointerId) {
            return;
        }

        const resize = dashboardResize;
        resize.widget.classList.remove('is-dashboard-dragging');
        resize.grid.classList.remove('is-resizing-widget');
        if (resize.handle.releasePointerCapture) {
            try {
                resize.handle.releasePointerCapture(event.pointerId);
            } catch (error) {
                // Some browsers release capture automatically.
            }
        }
        dashboardResize = null;

        layoutDashboardSlots(resize.grid);
        if (resize.moved) {
            saveDashboardLayout(resize.grid);
            updateDashboardResetButton(resize.grid);
        }
    }

    function dashboardSwapTarget(grid, draggedWidget) {
        const draggedRect = draggedWidget.getBoundingClientRect();
        const points = [
            {
                x: draggedRect.left + draggedRect.width / 2,
                y: draggedRect.top + draggedRect.height / 2
            },
            {
                x: draggedRect.right - 18,
                y: draggedRect.bottom - 18
            }
        ];
        let bestWidget = null;
        let bestArea = 0;
        const draggedArea = Math.max(1, draggedRect.width * draggedRect.height);

        const pointTarget = dashboardWidgets(grid).find((widget) => {
            if (widget === draggedWidget) {
                return false;
            }

            const rect = widget.getBoundingClientRect();
            const overlapX = Math.max(0, Math.min(draggedRect.right, rect.right) - Math.max(draggedRect.left, rect.left));
            const overlapY = Math.max(0, Math.min(draggedRect.bottom, rect.bottom) - Math.max(draggedRect.top, rect.top));
            const area = overlapX * overlapY;
            if (area > bestArea) {
                bestArea = area;
                bestWidget = widget;
            }

            return points.some((point) => (
                point.x >= rect.left &&
                point.x <= rect.right &&
                point.y >= rect.top &&
                point.y <= rect.bottom
            ));
        });

        if (pointTarget) {
            return pointTarget;
        }

        return bestArea / draggedArea > 0.18 ? bestWidget : null;
    }

    function swapDashboardSlots(grid, first, second) {
        const firstSlot = first.dataset.dashboardSlot;
        first.dataset.dashboardSlot = second.dataset.dashboardSlot;
        second.dataset.dashboardSlot = firstSlot;
    }

    function clearDashboardLayout(grid) {
        grid.classList.remove('is-positioned', 'is-reordering', 'is-dragging-widget');
        grid.style.minHeight = '';
        grid.style.removeProperty('--dashboard-grid-top');
        grid.style.removeProperty('--dashboard-grid-step-x');
        grid.style.removeProperty('--dashboard-grid-step-y');
        grid.style.removeProperty('--dashboard-cell-height');
        dashboardWidgets(grid).forEach((widget) => {
            widget.classList.remove('is-dashboard-dragging');
            widget.classList.remove('is-dashboard-merged');
            delete widget.dataset.dashboardSlot;
            delete widget.dataset.dashboardColumn;
            delete widget.dataset.dashboardRow;
            delete widget.dataset.dashboardSpan;
            delete widget.dataset.dashboardDesiredSpan;
            delete widget.dataset.dashboardDesiredHeight;
            widget.style.left = '';
            widget.style.top = '';
            widget.style.width = '';
            widget.style.height = '';
            widget.style.zIndex = '';
        });
    }

    function clamp(value, min, max) {
        return Math.min(Math.max(value, min), max);
    }

    const taskTourPendingKey = 'dpr-task-tour-pending';
    let taskTour = null;

    function storageSet(key, value) {
        try {
            window.localStorage.setItem(key, value);
        } catch (error) {
            // Local storage can be unavailable in restricted browser modes.
        }
    }

    function storageGet(key) {
        try {
            return window.localStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function storageRemove(key) {
        try {
            window.localStorage.removeItem(key);
        } catch (error) {
            // Ignore storage errors in restricted browser modes.
        }
    }

    function sessionStorageGet(key) {
        try {
            return window.sessionStorage.getItem(key);
        } catch (error) {
            return null;
        }
    }

    function sessionStorageSet(key, value) {
        try {
            window.sessionStorage.setItem(key, value);
        } catch (error) {
            // Session storage can be unavailable in restricted browser modes.
        }
    }

    function initPerformanceQuestionnaireRecovery() {
        document.querySelectorAll('form[data-performance-questionnaire]').forEach((form) => {
            const reviewId = form.dataset.reviewId || '';
            if (!reviewId) {
                return;
            }
            const storageKey = `locia:performance-questionnaire:${reviewId}`;
            const fields = Array.from(form.querySelectorAll('textarea[name^="answers["]'));
            const persist = () => {
                const answers = {};
                fields.forEach((field) => {
                    if (field instanceof HTMLTextAreaElement) {
                        answers[field.name] = field.value;
                    }
                });
                sessionStorageSet(storageKey, JSON.stringify(answers));
            };
            const recovery = new URLSearchParams(window.location.search).get('csrf') === 'expired';
            if (recovery) {
                try {
                    const answers = JSON.parse(sessionStorageGet(storageKey) || '{}');
                    fields.forEach((field) => {
                        if (field instanceof HTMLTextAreaElement && typeof answers[field.name] === 'string') {
                            field.value = answers[field.name];
                        }
                    });
                } catch (error) {
                    // A malformed browser draft must not block the questionnaire.
                }
            }
            fields.forEach((field) => field.addEventListener('input', persist));
            form.addEventListener('submit', persist);
        });
    }

    function taskTourContext() {
        if (document.querySelector('[data-tour-surface="task-form"]')) {
            return 'form';
        }
        if (document.querySelector('[data-tour-surface="task-detail"]')) {
            return 'detail';
        }
        if (document.querySelector('[data-tour-surface="task-list"]')) {
            return 'list';
        }
        return '';
    }

    function startTaskTourFromButton() {
        if (!taskTourContext()) {
            storageSet(taskTourPendingKey, '1');
            window.location.href = U(document.body.classList.contains('is-demo-theme') ? '/work' : '/locia');
            return;
        }

        startTaskTour();
    }

    function taskTourSteps() {
        const commonIntro = {
            title: 'Учебник по задачам',
            body: 'Я проведу по рабочему сценарию: где смотреть свои задачи, как создать новую, что читать в карточке и как проходит цикл проверки.'
        };
        const context = taskTourContext();
        const hubTitle = window.APP_TASK_HUB_TITLE || 'Рабочий стол';

        if (context === 'form') {
            return [
                commonIntro,
                { selector: '[data-tour="task-form"]', title: 'Форма задачи', body: 'Здесь задача превращается из мысли в поручение. Заполняйте сверху вниз: проект, ответственные, сроки, участники, затем постановка.' },
                { selector: '[data-tour="task-form-title"]', title: 'Название', body: 'Название должно быть коротким и проверяемым: что именно должно появиться в результате.' },
                { selector: '[data-tour="task-form-project"]', title: 'Проект', body: 'Проект связывает задачу с томами, разделами, ДПР и проектными таблицами.' },
                { selector: '[data-tour="task-form-assignee"]', title: 'Участники', body: 'Автор отвечает за выполнение задачи, соавтор может списывать время вместе с ним, наблюдатели только следят за ходом.' },
                { selector: '[data-tour="task-form-reviewer"]', title: 'Проверяющий', body: 'Проверяющий принимает результат или возвращает задачу на корректировку. Если поле пустое, система подберет проверяющего автоматически.' },
                { selector: '[data-tour="task-form-smart"]', title: 'SMART-постановка', body: 'Главный блок: что сделать, когда нужно и зачем это важно. Чем точнее здесь, тем меньше уточнений в чате.' },
                { selector: '.task-form-actions .btn, button[form="task-form"]', title: 'Добавить задачу', body: 'Кнопка находится под полями и продублирована в верхней панели. После сохранения задача появится в рабочем контуре и карточке проекта.' }
            ];
        }

        if (context === 'detail') {
            return [
                commonIntro,
                { selector: '[data-tour="task-passport"]', title: 'Паспорт задачи', body: 'Здесь собрана идентичность задачи: проект, номер, статус, дисциплина, том, раздел и важность.' },
                { selector: '[data-tour="task-smart"]', title: 'SMART', body: 'Это рабочее ядро задачи: что сделать, срок, контекст и зависимость. Если человек потерялся, начинать надо отсюда.' },
                { selector: '[data-tour="task-primary-flow"]', title: 'Главный маршрут', body: 'Сразу под постановкой находится рабочий цикл: цепочка согласования, отправка результата, приёмка или возврат на корректировку.' },
                { selector: '[data-tour="task-approval"]', title: 'Согласование', body: 'Цепочка показывает текущий этап: исполнитель, промежуточные согласующие, ГИП или выдача. Решения и возвраты остаются в истории.' },
                { selector: '[data-tour="task-exchange-actions"]', title: 'Обмен заданиями', body: 'Отсюда создается связанная задача типа «Задание»: ее можно выдать смежнику или запросить у него.' },
                { selector: '[data-tour="task-comments"]', title: 'Чат', body: 'В чате фиксируются уточнения. Можно упоминать коллег через @ID, чтобы не держать договоренности в воздухе.' },
                { selector: '[data-tour="task-issuances"]', title: 'Выдачи', body: 'Выдача фиксирует передачу результата. Закрытие задачи возможно только когда последняя выдача принята.' }
            ];
        }

        return [
            commonIntro,
            { selector: '[data-tour="nav-tasks"]', title: hubTitle, body: 'Это вход в личную работу: ваши задачи, поручения от вас, проверки и блокеры.' },
            { selector: '[data-tour="day-picture"]', title: 'Картина дня', body: 'Это короткий срез ваших задач: что горит, что сегодня, что на проверке и какие задания пришли или ушли от вас.' },
            { selector: '[data-tour="task-filters-toggle"], [data-tour="task-filters"]', title: 'Фильтры', body: 'Откройте фильтры, когда нужен точный срез по статусу, проекту, дисциплине, важности и срокам. Если список пустой, сначала сбросьте условия.' },
            { selector: '[data-tour="task-create"]', title: 'Новая задача', body: 'Отсюда создается задача. Если нужно поставить работу по проекту, начинайте с этой кнопки.' },
            { selector: '[data-tour="task-view"]', title: 'Вид списка', body: 'Таблица удобна для точной проверки сроков, доска - для движения статусов drag-and-drop.' },
            { selector: '[data-tour="task-list-table"], [data-tour="task-board"], [data-tour="task-empty"]', title: 'Рабочая область', body: 'Здесь живет очередь задач. Просрочка и проверка подняты выше, чтобы важное не пряталось в длинном списке.' },
            { selector: '[data-tour="task-row"], [data-tour="task-card"]', title: 'Открытие задачи', body: 'Клик по строке или карточке открывает подробности: SMART, чат, согласование, выдачи и историю.' }
        ];
    }

    function availableTaskTourSteps() {
        return taskTourSteps().filter((step) => !step.selector || document.querySelector(step.selector));
    }

    function ensureTaskTourElements() {
        let root = document.querySelector('[data-task-tour-root]');
        if (root) {
            return root;
        }

        root = document.createElement('div');
        root.className = 'task-tour';
        root.dataset.taskTourRoot = '1';
        root.innerHTML = `
            <div class="task-tour__shade"></div>
            <div class="task-tour__focus"></div>
            <section class="task-tour__card" role="dialog" aria-live="polite" aria-label="Учебник по задачам">
                <div class="task-tour__eyebrow"></div>
                <h2></h2>
                <p></p>
                <div class="task-tour__actions">
                    <button class="btn btn-outline" type="button" data-tour-action="prev">Назад</button>
                    <button class="btn btn-outline" type="button" data-tour-action="close">Закрыть</button>
                    <button class="btn btn-red" type="button" data-tour-action="next">Далее</button>
                </div>
            </section>
        `;
        document.body.appendChild(root);
        return root;
    }

    function startTaskTour() {
        const steps = availableTaskTourSteps();
        if (!steps.length) {
            return;
        }

        taskTour = {
            index: 0,
            root: ensureTaskTourElements(),
            steps
        };
        document.body.classList.add('task-tour-active');
        window.addEventListener('resize', updateTaskTourPosition);
        window.addEventListener('scroll', updateTaskTourPosition, true);
        showTaskTourStep();
    }

    function closeTaskTour() {
        if (!taskTour) {
            return;
        }

        taskTour.root.remove();
        taskTour = null;
        document.body.classList.remove('task-tour-active');
        window.removeEventListener('resize', updateTaskTourPosition);
        window.removeEventListener('scroll', updateTaskTourPosition, true);
    }

    function handleTaskTourAction(action) {
        if (!taskTour) {
            return;
        }

        if (action === 'close') {
            closeTaskTour();
            return;
        }

        if (action === 'prev') {
            taskTour.index = Math.max(0, taskTour.index - 1);
            showTaskTourStep();
            return;
        }

        if (action === 'next') {
            if (taskTour.index >= taskTour.steps.length - 1) {
                closeTaskTour();
                return;
            }
            taskTour.index++;
            showTaskTourStep();
        }
    }

    function showTaskTourStep() {
        if (!taskTour) {
            return;
        }

        const step = taskTour.steps[taskTour.index];
        const root = taskTour.root;
        root.querySelector('.task-tour__eyebrow').textContent = `Шаг ${taskTour.index + 1} из ${taskTour.steps.length}`;
        root.querySelector('h2').textContent = step.title;
        root.querySelector('p').textContent = step.body;
        root.querySelector('[data-tour-action="prev"]').disabled = taskTour.index === 0;
        root.querySelector('[data-tour-action="next"]').textContent = taskTour.index === taskTour.steps.length - 1 ? 'Готово' : 'Далее';

        const target = step.selector ? document.querySelector(step.selector) : null;
        if (target) {
            target.scrollIntoView({ block: 'center', inline: 'center', behavior: 'smooth' });
        }
        window.setTimeout(updateTaskTourPosition, target ? 180 : 0);
    }

    function updateTaskTourPosition() {
        if (!taskTour) {
            return;
        }

        const step = taskTour.steps[taskTour.index];
        const root = taskTour.root;
        const focus = root.querySelector('.task-tour__focus');
        const card = root.querySelector('.task-tour__card');
        const target = step.selector ? document.querySelector(step.selector) : null;

        if (!target) {
            focus.hidden = true;
            card.classList.add('task-tour__card--center');
            card.style.left = '';
            card.style.top = '';
            return;
        }

        const rect = target.getBoundingClientRect();
        const pad = 8;
        focus.hidden = false;
        focus.style.left = `${Math.max(8, rect.left - pad)}px`;
        focus.style.top = `${Math.max(8, rect.top - pad)}px`;
        focus.style.width = `${Math.min(window.innerWidth - 16, rect.width + pad * 2)}px`;
        focus.style.height = `${Math.min(window.innerHeight - 16, rect.height + pad * 2)}px`;

        card.classList.remove('task-tour__card--center');
        const cardWidth = Math.min(360, window.innerWidth - 32);
        card.style.width = `${cardWidth}px`;
        const cardHeight = card.offsetHeight || 220;
        const left = Math.min(Math.max(16, rect.left), window.innerWidth - cardWidth - 16);
        let top = rect.bottom + 14;
        if (top + cardHeight > window.innerHeight - 16) {
            top = rect.top - cardHeight - 14;
        }
        if (top < 16) {
            top = 16;
        }
        card.style.left = `${left}px`;
        card.style.top = `${top}px`;
    }

    function initCopyPathButtons() {
        document.querySelectorAll('[data-copy-path]').forEach((button) => {
            button.addEventListener('click', async () => {
                const value = button.dataset.copyPath || '';
                if (!value) {
                    return;
                }

                if (await copyTextToClipboard(value)) {
                    const previousText = button.textContent;
                    button.textContent = 'Скопировано';
                    window.setTimeout(() => {
                        button.textContent = previousText;
                    }, 1400);
                } else {
                    window.prompt('Скопируйте путь', value);
                }
            });
        });

        // Кнопки «Скопировать ссылку на модель»: копируют АБСОЛЮТНУЮ ссылку на
        // Атлас, чтобы её можно было переслать внутри сети и модель открылась сразу.
        document.querySelectorAll('[data-copy-link]').forEach((button) => {
            button.addEventListener('click', async () => {
                const raw = button.dataset.copyLink || '';
                if (!raw) {
                    return;
                }
                let value = raw;
                try {
                    value = new URL(raw, window.location.origin).href;
                } catch (error) {
                    value = raw;
                }
                if (await copyTextToClipboard(value)) {
                    const previousText = button.textContent;
                    button.textContent = 'Ссылка скопирована';
                    window.setTimeout(() => {
                        button.textContent = previousText;
                    }, 1400);
                } else {
                    window.prompt('Скопируйте ссылку на модель', value);
                }
            });
        });

        document.querySelectorAll('[data-copy-text]').forEach((button) => {
            button.addEventListener('click', async () => {
                const value = button.dataset.copyText || '';
                if (!value) {
                    return;
                }
                if (await copyTextToClipboard(value)) {
                    const previousText = button.textContent;
                    button.textContent = 'Скопировано';
                    window.setTimeout(() => {
                        button.textContent = previousText;
                    }, 1200);
                } else {
                    window.prompt('Скопируйте текст', value);
                }
            });
        });
    }

    function initModelFragmentQueue() {
        const queue = document.querySelector('[data-model-frag-queue]');
        if (!queue || queue.dataset.modelFragQueueBound === '1') {
            return;
        }
        queue.dataset.modelFragQueueBound = '1';
        const jobs = Array.from(queue.querySelectorAll('[data-model-frag-job]')).filter((job) => job.dataset.prepareUrl && job.dataset.statusUrl);
        if (!jobs.length) {
            return;
        }

        const frame = document.createElement('iframe');
        frame.hidden = true;
        frame.setAttribute('aria-hidden', 'true');
        frame.style.position = 'fixed';
        frame.style.width = '1px';
        frame.style.height = '1px';
        frame.style.left = '-10px';
        frame.style.bottom = '-10px';
        frame.style.opacity = '0';
        frame.style.pointerEvents = 'none';
        document.body.appendChild(frame);

        const statusFor = (job) => document.querySelector(`[data-frag-status="${CSS.escape(job.dataset.fragKey || '')}"]`);
        const setStatus = (job, text, ready) => {
            const chip = statusFor(job);
            if (!chip) {
                return;
            }
            chip.textContent = text;
            chip.classList.toggle('status-chip--done', ready);
            chip.classList.toggle('status-chip--pending', !ready);
        };
        const isReady = async (job) => {
            try {
                const response = await fetch(job.dataset.statusUrl, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                const payload = await response.json().catch(() => ({}));
                return response.ok && payload && payload.ready === true;
            } catch (error) {
                return false;
            }
        };
        const waitUntilReady = async (job) => {
            const started = Date.now();
            while (Date.now() - started < 120000) {
                if (await isReady(job)) {
                    return true;
                }
                await new Promise((resolve) => window.setTimeout(resolve, 10000));
            }
            return false;
        };

        void (async () => {
            for (const job of jobs) {
                if (await isReady(job)) {
                    setStatus(job, 'FRAG готов', true);
                    continue;
                }
                setStatus(job, 'готовится', false);
                frame.src = new URL(job.dataset.prepareUrl, window.location.origin).href;
                const ready = await waitUntilReady(job);
                setStatus(job, ready ? 'FRAG готов' : 'запущено', ready);
            }
        })();
    }

    function initFolderOpenForms() {
        document.querySelectorAll('[data-folder-open-form]').forEach((form) => {
            if (form.dataset.folderOpenBound === '1') {
                return;
            }
            form.dataset.folderOpenBound = '1';

            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const button = form.querySelector('[data-folder-open-button]');
                const previousHtml = button ? button.innerHTML : '';
                const pathHint = button?.dataset.folderPath || button?.getAttribute('title') || '';
                const openUrl = button?.dataset.folderOpenUrl || '';

                if (button) {
                    button.disabled = true;
                    button.innerHTML = 'Открываю...';
                }

                if (openUrl.startsWith('dpr-open:')) {
                    window.location.href = openUrl;
                    if (button) {
                        button.innerHTML = 'Открыто';
                        window.setTimeout(() => {
                            button.disabled = false;
                            button.innerHTML = previousHtml;
                        }, 1400);
                    }
                    return;
                }

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        body: new FormData(form),
                        credentials: 'same-origin',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
                        },
                    });
                    const contentType = response.headers.get('content-type') || '';
                    const payload = contentType.includes('application/json') ? await response.json() : {};
                    if (!response.ok || payload.ok === false) {
                        throw new Error(payload.message || 'Не удалось открыть папку.');
                    }

                    if (button) {
                        button.innerHTML = 'Открыто';
                    }
                } catch (error) {
                    if (button) {
                        button.innerHTML = 'Не открылось';
                    }
                    if (pathHint) {
                        window.prompt(error.message || 'Скопируйте путь и откройте его в проводнике', pathHint);
                    } else {
                        window.alert(error.message || 'Не удалось открыть папку.');
                    }
                } finally {
                    if (button) {
                        window.setTimeout(() => {
                            button.disabled = false;
                            button.innerHTML = previousHtml;
                        }, 1400);
                    }
                }
            });
        });
    }

    function initDprOpenLinks() {
        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href^="dpr-open:"]');
            if (!link) {
                return;
            }

            event.preventDefault();
            window.location.href = link.href;
        });
    }

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function adminNotice(message, type = 'success') {
        const main = document.querySelector('.main');
        const content = document.querySelector('.content');
        if (!main || !content) {
            return;
        }

        const notice = document.createElement('div');
        notice.className = `alert alert--${type} admin-ajax-notice`;
        notice.textContent = message;
        main.insertBefore(notice, content);
        window.setTimeout(() => notice.remove(), 2600);
    }

    async function postAdminUserForm(form) {
        const row = form.closest('[data-admin-user-row]');
        const formData = new FormData(form);
        const controls = Array.from(form.querySelectorAll('button, input, select, textarea'));
        controls.forEach((control) => {
            control.disabled = true;
        });
        row?.classList.add('is-saving');

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
                },
            });
            const contentType = response.headers.get('content-type') || '';
            const payload = contentType.includes('application/json') ? await response.json() : {};
            if (!response.ok || payload.ok === false) {
                throw new Error(payload.message || 'Изменение не сохранено.');
            }

            return payload;
        } finally {
            controls.forEach((control) => {
                control.disabled = false;
            });
            row?.classList.remove('is-saving');
        }
    }

    function updateAdminUserRow(row, user) {
        if (!row || !user) {
            return;
        }

        row.classList.toggle('is-inactive', user.is_active === false);
        row.dataset.userActive = user.is_active === false ? '0' : '1';
        setAdminUserFieldValue(row, 'tab_number', user.tab_number || '');
        setAdminUserFieldValue(row, 'name', user.name || '');
        setAdminUserFieldValue(row, 'email', user.email || '');

        const roleSelect = row.querySelector('select[name="role"]');
        if (roleSelect && user.role) {
            roleSelect.value = user.role;
        }
        row.dataset.userRole = roleSelect?.value || user.role || '';
        const departmentSelect = row.querySelector('select[name="department"]');
        if (departmentSelect) {
            departmentSelect.value = user.department || '';
        }
        row.dataset.userDepartment = departmentSelect?.value || user.department || '';
        const positionSelect = row.querySelector('select[name="position_id"]');
        if (positionSelect && user.position_id !== undefined) {
            positionSelect.value = String(user.position_id || '');
        }
        const managerSelect = row.querySelector('select[name="manager_id"]');
        if (managerSelect && user.manager_id !== undefined) {
            managerSelect.value = String(user.manager_id || '');
        }

        const statusKey = user.is_active === false ? 'inactive' : (user.must_change_password ? 'password' : 'active');
        row.dataset.userStatusKey = statusKey;
        row.dataset.userMailKey = user.credentials_mail_marked_sent_at ? 'mail_sent' : 'mail_pending';

        const status = row.querySelector('[data-user-status]');
        if (status) {
            status.textContent = user.status_label || (user.is_active === false ? 'Уволен' : 'Активен');
            status.classList.toggle('status-pill--muted', user.is_active === false);
        }
        const credentialStatus = row.querySelector('[data-user-credential-status]');
        if (credentialStatus) {
            credentialStatus.innerHTML = adminCredentialStatusHtml(user);
        }

        const activeForm = row.querySelector('[data-admin-user-active]');
        const activeInput = activeForm?.querySelector('input[name="is_active"]');
        const activeButton = activeForm?.querySelector('[data-active-button]');
        if (activeInput && activeButton) {
            activeInput.value = user.is_active === false ? '1' : '0';
            activeButton.textContent = user.is_active === false ? 'Вернуть' : 'Уволить';
            activeButton.classList.toggle('btn--red', user.is_active === false);
            activeButton.classList.toggle('btn-outline', user.is_active !== false);
        }

        const checkbox = row.querySelector('.user-bulk-check');
        if (checkbox) {
            checkbox.disabled = false;
        }

        refreshAdminUserRowFilterData(row);
        applyAdminUserFilters();
    }

    function adminUserFieldValue(row, field) {
        const cell = row?.querySelector(`[data-user-field="${field}"]`);
        const input = cell?.querySelector('input, textarea');
        return input ? input.value : (cell?.textContent || '');
    }

    function setAdminUserFieldValue(row, field, value) {
        const cell = row?.querySelector(`[data-user-field="${field}"]`);
        const input = cell?.querySelector('input, textarea');
        if (input) {
            input.value = value;
            return;
        }
        cell?.replaceChildren(document.createTextNode(value));
    }

    function credentialCard(credential) {
        const id = `generated-password-${Date.now()}-${Math.random().toString(16).slice(2)}`;
        const bodyId = `${id}-body`;
        const body = credential.mail_body || '';
        const role = credential.role || '';
        const article = document.createElement('article');
        article.className = 'credential-card';
        article.innerHTML = `
            <div class="credential-card__main">
                <strong>${escapeHtml(credential.name || '')}</strong>
                <span>${escapeHtml(credential.email || '')} · таб. ${escapeHtml(credential.tab_number || '')} · ${escapeHtml(credential.role_label || role)}</span>
            </div>
            <code id="${id}">${escapeHtml(credential.password || '')}</code>
            <div class="credential-card__actions">
                <button class="btn" type="button" data-copy="#${id}">Копировать пароль</button>
                <button class="btn" type="button" data-copy="#${bodyId}" data-copy-label="Копировать текст письма">Копировать текст письма</button>
            </div>
            <pre class="credential-card__body" id="${bodyId}">${escapeHtml(body)}</pre>
        `;
        return article;
    }

    function adminCredentialStatusHtml(user) {
        const resetAt = user.password_reset_at || '';
        const resetBy = user.password_reset_by_name || '';
        const sentAt = user.credentials_mail_marked_sent_at || '';
        const sentBy = user.credentials_mail_marked_sent_by_name || '';
        return `
            <small>
                Пароль: ${escapeHtml(resetAt || '—')}
                ${resetBy ? `<br><span class="muted">${escapeHtml(resetBy)}</span>` : ''}
                <br>Письмо:
                ${sentAt
                    ? ` ${escapeHtml(sentAt)}${sentBy ? `<br><span class="muted">${escapeHtml(sentBy)}</span>` : ''}`
                    : ' <span class="muted">не отмечено</span>'}
            </small>
        `;
    }

    function addAdminCredential(credential) {
        if (!credential) {
            return;
        }
        const panel = document.querySelector('[data-admin-credentials]');
        const list = document.querySelector('[data-admin-credential-list]');
        const count = document.querySelector('[data-admin-credential-count]');
        if (!panel || !list) {
            return;
        }

        panel.hidden = false;
        list.prepend(credentialCard(credential));
        if (count) {
            count.textContent = String(list.querySelectorAll('.credential-card').length);
        }
    }

    function optionsHtml(select, selectedValue) {
        if (!select) {
            return '';
        }

        return Array.from(select.options).map((option) => {
            const selected = option.value === String(selectedValue || '') ? ' selected' : '';
            return `<option value="${escapeHtml(option.value)}"${selected}>${escapeHtml(option.textContent || '')}</option>`;
        }).join('');
    }

    function adminUserRowHtml(user, createForm) {
        const roleOptions = optionsHtml(createForm.querySelector('select[name="role"]'), user.role);
        const departmentOptions = optionsHtml(createForm.querySelector('select[name="department"]'), user.department || '');
        const positionOptions = optionsHtml(createForm.querySelector('select[name="position_id"]'), user.position_id || '');
        const managerOptions = optionsHtml(createForm.querySelector('select[name="manager_id"]'), user.manager_id || '');
        const token = escapeHtml(csrf);
        const id = Number(user.id || 0);
        const hasRateColumn = Boolean(document.querySelector('[data-admin-user-rate-column]'));
        const rateValue = Number(user.hourly_rate || 0);
        const rateCell = hasRateColumn ? `
                <td>
                    <form class="admin-rate-form" method="post" action="${U(`/admin/users/${id}/rate`)}" data-admin-user-form>
                        <input type="hidden" name="_csrf" value="${token}">
                        <input type="number" min="0" step="0.01" name="hourly_rate" value="${Number.isFinite(rateValue) ? rateValue : 0}" aria-label="Ставка ${escapeHtml(user.name || '')}, рублей в час">
                        <button class="btn btn-sm" type="submit">OK</button>
                    </form>
                    <small class="muted">—</small>
                </td>` : '';
        const mailKey = user.credentials_mail_marked_sent_at ? 'mail_sent' : 'mail_pending';
        return `
            <tr data-admin-user-row="${id}" data-user-active="1" data-user-role="${escapeHtml(user.role || '')}" data-user-department="${escapeHtml(user.department || '')}" data-user-status-key="${user.must_change_password ? 'password' : 'active'}" data-user-mail-key="${mailKey}">
                <td><input class="user-bulk-check" type="checkbox" name="user_ids[]" value="${id}" form="bulk-credential-form" aria-label="Выбрать ${escapeHtml(user.name || '')}"></td>
                <td data-user-field="tab_number">
                    <form id="identity-${id}" method="post" action="${U(`/admin/users/${id}/identity`)}" data-admin-user-form></form>
                    <input type="hidden" form="identity-${id}" name="_csrf" value="${token}">
                    <input class="admin-user-inline-input" form="identity-${id}" name="tab_number" value="${escapeHtml(user.tab_number || '')}" required aria-label="Табельный номер ${escapeHtml(user.name || '')}">
                </td>
                <td data-user-field="name">
                    <input class="admin-user-inline-input admin-user-inline-input--wide" form="identity-${id}" name="name" value="${escapeHtml(user.name || '')}" required aria-label="ФИО">
                </td>
                <td data-user-field="email">
                    <div class="admin-user-email-edit">
                        <input class="admin-user-inline-input admin-user-inline-input--wide" form="identity-${id}" type="email" name="email" value="${escapeHtml(user.email || '')}" required aria-label="Email ${escapeHtml(user.name || '')}">
                        <button class="btn btn-sm" form="identity-${id}" type="submit">OK</button>
                    </div>
                </td>
                <td>
                    <form method="post" action="${U(`/admin/users/${id}/role`)}" data-admin-user-form>
                        <input type="hidden" name="_csrf" value="${token}">
                        <select name="role" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">${roleOptions}</select>
                    </form>
                </td>
                <td>
                    <form method="post" action="${U(`/admin/users/${id}/department`)}" data-admin-user-form>
                        <input type="hidden" name="_csrf" value="${token}">
                        <select name="department" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">${departmentOptions}</select>
                    </form>
                </td>
                <td>
                    <form method="post" action="${U(`/admin/users/${id}/org`)}" data-admin-user-form>
                        <input type="hidden" name="_csrf" value="${token}">
                        <select name="position_id" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">${positionOptions}</select>
                        <select name="manager_id" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">${managerOptions}</select>
                    </form>
                </td>
                ${rateCell}
                <td data-user-credential-status>${adminCredentialStatusHtml(user)}</td>
                <td>${escapeHtml(user.last_login || '')}</td>
                <td><span class="status-pill" data-user-status>${escapeHtml(user.status_label || 'Смена пароля')}</span></td>
                <td class="user-actions">
                    <form method="post" action="${U(`/admin/users/${id}/reset-password`)}" data-admin-user-form data-admin-user-reset>
                        <input type="hidden" name="_csrf" value="${token}">
                        <button class="btn" type="submit">Сбросить пароль</button>
                    </form>
                    <form method="post" action="${U(`/admin/users/${id}/credentials-mail-sent`)}" data-admin-user-form data-admin-user-mail-sent>
                        <input type="hidden" name="_csrf" value="${token}">
                        <button class="btn btn-outline" type="submit">Письмо отправлено</button>
                    </form>
                    <form method="post" action="${U(`/admin/users/${id}/active`)}" data-admin-user-form data-admin-user-active>
                        <input type="hidden" name="_csrf" value="${token}">
                        <input type="hidden" name="is_active" value="0">
                        <button class="btn btn-outline" type="submit" data-active-button>Уволить</button>
                    </form>
                </td>
            </tr>
        `;
    }

    function refreshAdminUserRowFilterData(row) {
        if (!row) {
            return;
        }

        const roleSelect = row.querySelector('select[name="role"]');
        const departmentSelect = row.querySelector('select[name="department"]');
        const positionSelect = row.querySelector('select[name="position_id"]');
        const managerSelect = row.querySelector('select[name="manager_id"]');
        const status = row.querySelector('[data-user-status]');
        const checkbox = row.querySelector('.user-bulk-check');
        row.dataset.userActive = row.classList.contains('is-inactive') ? '0' : '1';
        row.dataset.userRole = roleSelect?.value || row.dataset.userRole || '';
        row.dataset.userDepartment = departmentSelect?.value || row.dataset.userDepartment || '';
        if (!row.dataset.userStatusKey) {
            const statusText = (status?.textContent || '').toLocaleLowerCase('ru-RU');
            row.dataset.userStatusKey = statusText.includes('уволен') ? 'inactive' : (statusText.includes('смена') ? 'password' : 'active');
        }
        if (row.dataset.userActive === '0') {
            row.dataset.userStatusKey = 'inactive';
        }
        if (!row.dataset.userMailKey) {
            const credentialText = (row.querySelector('[data-user-credential-status]')?.textContent || '').toLocaleLowerCase('ru-RU');
            row.dataset.userMailKey = credentialText.includes('не отмечено') ? 'mail_pending' : 'mail_sent';
        }

        const searchParts = [
            adminUserFieldValue(row, 'tab_number'),
            adminUserFieldValue(row, 'name'),
            adminUserFieldValue(row, 'email'),
            roleSelect?.selectedOptions?.[0]?.textContent || '',
            departmentSelect?.value || '',
            positionSelect?.selectedOptions?.[0]?.textContent || '',
            managerSelect?.selectedOptions?.[0]?.textContent || '',
            status?.textContent || '',
            row.querySelector('[data-user-credential-status]')?.textContent || '',
        ];
        row.dataset.userSearch = searchParts.join(' ').trim().toLocaleLowerCase('ru-RU');
    }

    function initOrgStructure() {
        const root = document.querySelector('[data-org-structure]');
        if (!root) {
            return;
        }
        const input = root.querySelector('[data-org-search-input]');
        const nodes = Array.from(document.querySelectorAll('[data-org-node]'));
        const rows = Array.from(document.querySelectorAll('[data-org-person-row]'));
        const treeEmpty = document.querySelector('[data-org-empty]');
        const peopleEmpty = document.querySelector('[data-org-people-empty]');
        const apply = () => {
            const query = (input?.value || '').trim().toLocaleLowerCase('ru-RU');
            nodes.forEach((node) => { node.hidden = Boolean(query); });
            rows.forEach((row) => { row.hidden = Boolean(query); });
            if (!query) {
                nodes.forEach((node) => { node.hidden = false; });
                rows.forEach((row) => { row.hidden = false; });
                if (treeEmpty) {
                    treeEmpty.hidden = true;
                }
                if (peopleEmpty) {
                    peopleEmpty.hidden = true;
                }
                return;
            }
            let visibleNodes = 0;
            let visibleRows = 0;
            nodes.forEach((node) => {
                if (!(node.dataset.orgSearch || '').includes(query)) {
                    return;
                }
                node.hidden = false;
                visibleNodes++;
                let parent = node.parentElement?.closest('[data-org-node]');
                while (parent) {
                    parent.hidden = false;
                    parent.querySelector(':scope > details')?.setAttribute('open', '');
                    parent = parent.parentElement?.closest('[data-org-node]');
                }
                node.querySelector(':scope > details')?.setAttribute('open', '');
            });
            rows.forEach((row) => {
                if ((row.dataset.orgSearch || '').includes(query)) {
                    row.hidden = false;
                    visibleRows++;
                }
            });
            if (treeEmpty) {
                treeEmpty.hidden = visibleNodes > 0;
            }
            if (peopleEmpty) {
                peopleEmpty.hidden = visibleRows > 0;
            }
        };
        input?.addEventListener('input', apply);
        root.querySelector('[data-org-expand]')?.addEventListener('click', () => {
            document.querySelectorAll('.org-tree details').forEach((details) => details.setAttribute('open', ''));
        });
        root.querySelector('[data-org-collapse]')?.addEventListener('click', () => {
            document.querySelectorAll('.org-tree details').forEach((details) => details.removeAttribute('open'));
        });
    }

    function adminUserFilterValue(panel, key) {
        return panel.querySelector(`[data-admin-user-filter="${key}"]`)?.value || '';
    }

    function peopleMatrixFilterValue(key) {
        return (document.querySelector(`[data-people-filter="${key}"]`)?.value || '').trim().toLocaleLowerCase('ru-RU');
    }

    function applyPeopleMatrixFilters() {
        const table = document.querySelector('.dpr-people-matrix');
        if (!table) {
            return;
        }

        const person = peopleMatrixFilterValue('person');
        const department = peopleMatrixFilterValue('department');
        const object = peopleMatrixFilterValue('object');
        const state = peopleMatrixFilterValue('state');
        const objectHeaders = Array.from(table.querySelectorAll('[data-matrix-object-text]'));
        const visibleObjects = new Set();

        objectHeaders.forEach((header) => {
            const matches = !object || (header.dataset.matrixObjectText || '').includes(object);
            header.hidden = !matches;
            if (matches) {
                visibleObjects.add(header.dataset.matrixObjectId || '');
            }
        });

        table.querySelectorAll('[data-matrix-person-row]').forEach((row) => {
            const matchesPerson = !person || (row.dataset.matrixPersonText || '').includes(person);
            const matchesDepartment = !department || (row.dataset.matrixDepartment || '') === department;
            let hasVisibleCell = false;

            row.querySelectorAll('[data-matrix-state]').forEach((cell) => {
                const matchesObject = visibleObjects.has(cell.dataset.matrixObjectId || '');
                const matchesState = !state || (cell.dataset.matrixState || '') === state;
                const visible = matchesObject && matchesState;
                cell.hidden = !visible;
                hasVisibleCell = hasVisibleCell || visible;
            });

            row.hidden = !(matchesPerson && matchesDepartment && hasVisibleCell);
        });
    }

    function applyPeoplePickerResults(kind) {
        const input = document.querySelector(`[data-people-picker-input="${kind}"]`);
        const list = document.querySelector(`[data-people-picker-results="${kind}"]`);
        if (!input || !list) {
            return;
        }

        const query = input.value.trim().toLocaleLowerCase('ru-RU');
        const options = Array.from(list.querySelectorAll(`[data-people-picker-option="${kind}"]`));
        const count = document.querySelector(`[data-people-picker-count="${kind}"]`);
        const empty = document.querySelector(`[data-people-picker-empty="${kind}"]`);
        let visible = 0;

        options.forEach((option) => {
            const value = (option.dataset.filterValue || option.textContent || '').toLocaleLowerCase('ru-RU');
            const isVisible = !query || value.includes(query);
            option.hidden = !isVisible;
            option.classList.toggle('is-current', query !== '' && value === query);
            if (isVisible) {
                visible++;
            }
        });

        if (count) {
            count.textContent = String(visible);
        }
        if (empty) {
            empty.hidden = visible > 0;
        }
    }

    function initPeopleMatrixFilters() {
        const panel = document.querySelector('[data-people-matrix-filters]');
        if (!panel) {
            return;
        }

        panel.querySelectorAll('[data-people-filter]').forEach((control) => {
            control.addEventListener('input', applyPeopleMatrixFilters);
            control.addEventListener('change', applyPeopleMatrixFilters);
        });
        panel.querySelectorAll('[data-people-picker-input]').forEach((input) => {
            const kind = input.dataset.peoplePickerInput || '';
            const refresh = () => applyPeoplePickerResults(kind);
            input.addEventListener('input', refresh);
            input.addEventListener('change', refresh);
            refresh();
        });
        panel.querySelectorAll('[data-people-picker-option]').forEach((option) => {
            option.addEventListener('click', () => {
                const kind = option.dataset.peoplePickerOption || '';
                const input = panel.querySelector(`[data-people-picker-input="${kind}"]`);
                if (!input) {
                    return;
                }
                input.value = option.dataset.displayValue || option.textContent.trim();
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        });
        applyPeopleMatrixFilters();
    }

    function applyAdminUserFilters() {
        const panel = document.querySelector('[data-admin-users-panel]');
        if (!panel) {
            return;
        }

        const hideInactive = panel.querySelector('[data-admin-user-hide-inactive]')?.checked ?? false;
        const text = adminUserFilterValue(panel, 'text').trim().toLocaleLowerCase('ru-RU');
        const role = adminUserFilterValue(panel, 'role');
        const department = adminUserFilterValue(panel, 'department');
        const status = adminUserFilterValue(panel, 'status');
        const rows = Array.from(panel.querySelectorAll('[data-admin-user-row]'));
        let visible = 0;

        rows.forEach((row) => {
            refreshAdminUserRowFilterData(row);
            const rowDepartment = row.dataset.userDepartment || '';
            const matches = (!hideInactive || row.dataset.userActive !== '0')
                && (!text || (row.dataset.userSearch || row.textContent.toLocaleLowerCase('ru-RU')).includes(text))
                && (!role || row.dataset.userRole === role)
                && (!department || (department === '__empty' ? rowDepartment === '' : rowDepartment === department))
                && (!status || row.dataset.userStatusKey === status || row.dataset.userMailKey === status);

            row.hidden = !matches;
            if (!matches) {
                row.querySelector('.user-bulk-check')?.removeAttribute('checked');
                const checkbox = row.querySelector('.user-bulk-check');
                if (checkbox) {
                    checkbox.checked = false;
                }
            } else {
                visible++;
            }
        });

        const count = panel.querySelector('[data-admin-user-count]');
        if (count) {
            count.textContent = visible === rows.length ? String(rows.length) : `${visible} / ${rows.length}`;
        }
        const empty = panel.querySelector('[data-admin-user-empty]');
        if (empty) {
            empty.hidden = visible > 0;
        }

        const checkAll = panel.querySelector('[data-check-all]');
        if (checkAll) {
            checkAll.checked = false;
        }
    }

    function initAdminUserFilters() {
        const panel = document.querySelector('[data-admin-users-panel]');
        if (!panel) {
            return;
        }

        panel.querySelectorAll('[data-admin-user-filter], [data-admin-user-hide-inactive]').forEach((control) => {
            control.addEventListener('input', applyAdminUserFilters);
            control.addEventListener('change', applyAdminUserFilters);
        });
        panel.querySelector('[data-admin-user-filter-reset]')?.addEventListener('click', () => {
            panel.querySelectorAll('[data-admin-user-filter]').forEach((control) => {
                control.value = '';
            });
            const hideInactive = panel.querySelector('[data-admin-user-hide-inactive]');
            if (hideInactive) {
                hideInactive.checked = true;
            }
            applyAdminUserFilters();
        });
        applyAdminUserFilters();
    }

    function initAdminUsersAjax() {
        const createForm = document.querySelector('[data-admin-user-create]');
        const usersList = document.querySelector('[data-admin-users-list]');
        if (!createForm || !usersList) {
            return;
        }

        createForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            try {
                const payload = await postAdminUserForm(createForm);
                if (payload.user) {
                    usersList.insertAdjacentHTML('beforeend', adminUserRowHtml(payload.user, createForm));
                    updateAdminUserRow(usersList.querySelector(`[data-admin-user-row="${payload.user.id}"]`), payload.user);
                    const count = document.querySelector('[data-admin-user-count]');
                    if (count) {
                        count.textContent = String(usersList.querySelectorAll('[data-admin-user-row]').length);
                    }
                    applyAdminUserFilters();
                }
                addAdminCredential(payload.credential);
                createForm.reset();
                adminNotice(payload.message || 'Пользователь создан.');
            } catch (error) {
                adminNotice(error.message || 'Не удалось создать пользователя.', 'error');
            }
        });

        document.addEventListener('submit', async (event) => {
            const form = event.target.closest('[data-admin-user-form]');
            if (!form) {
                return;
            }

            event.preventDefault();
            try {
                const payload = await postAdminUserForm(form);
                const row = form.closest('[data-admin-user-row]');
                if (payload.user) {
                    updateAdminUserRow(row, payload.user);
                } else {
                    refreshAdminUserRowFilterData(row);
                    applyAdminUserFilters();
                }
                if (payload.credential) {
                    addAdminCredential(payload.credential);
                }
                adminNotice(payload.message || 'Сохранено.');
            } catch (error) {
                adminNotice(error.message || 'Изменение не сохранено.', 'error');
            }
        });
    }

    function initLaborEstimateUnits() {
        document.addEventListener('input', (event) => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement)) {
                return;
            }
            const isHours = target.matches('[data-labor-hours]');
            const isDays = target.matches('[data-labor-days]');
            if (!isHours && !isDays) {
                return;
            }
            const scope = target.closest('.form-grid, form');
            if (!scope) {
                return;
            }
            const pair = scope.querySelector(isHours ? '[data-labor-days]' : '[data-labor-hours]');
            if (!(pair instanceof HTMLInputElement)) {
                return;
            }
            const value = Number(String(target.value).replace(',', '.'));
            if (!Number.isFinite(value) || value <= 0) {
                pair.value = '';
                return;
            }
            const converted = isHours ? value / 8 : value * 8;
            pair.value = String(Math.round(converted * 100) / 100);
        });
    }

    function initDateInputs(root) {
        const scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('input[type="date"]').forEach((input) => {
            input.lang = 'ru-RU';
            if (!input.placeholder) {
                input.placeholder = 'дд.мм.гггг';
            }
            if (!input.getAttribute('aria-describedby')) {
                input.title = input.title || 'Дата в формате дд.мм.гггг';
            }
        });
    }

    function initTimeCalendarForms() {
        document.querySelectorAll('[data-time-calendar-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.bound === '1') {
                return;
            }
            const input = form.querySelector('[data-time-calendar-input]');
            if (!(input instanceof HTMLInputElement)) {
                return;
            }
            form.dataset.bound = '1';
            input.addEventListener('change', () => {
                if (!input.value) {
                    return;
                }
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        });
    }

    function parseGroupedNumber(value) {
        const normalized = String(value || '').replace(/[\s\u00a0]/g, '').replace(',', '.');
        if (normalized === '' || !/^-?\d*(?:\.\d*)?$/.test(normalized)) {
            return null;
        }
        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function formatGroupedNumber(value) {
        const parsed = parseGroupedNumber(value);
        if (parsed === null) {
            return String(value || '');
        }
        return new Intl.NumberFormat('ru-RU', {
            useGrouping: true,
            maximumFractionDigits: 2,
        }).format(parsed).replace(/\u00a0/g, ' ');
    }

    function initProjectBudgetInputs() {
        document.querySelectorAll('[data-grouped-number]').forEach((input) => {
            if (!(input instanceof HTMLInputElement) || input.dataset.groupedNumberReady === '1') {
                return;
            }
            input.dataset.groupedNumberReady = '1';
            input.addEventListener('blur', () => {
                input.value = formatGroupedNumber(input.value);
            });
        });

        const budgetForms = new Set();
        document.querySelectorAll('[data-project-budget-part]').forEach((input) => {
            const form = input.closest('form');
            if (form instanceof HTMLFormElement) {
                budgetForms.add(form);
            }
        });
        budgetForms.forEach((form) => {
            if (!(form instanceof HTMLFormElement) || form.dataset.projectBudgetReady === '1') {
                return;
            }
            const parts = Array.from(form.querySelectorAll('[data-project-budget-part]'));
            const total = form.querySelector('[data-project-budget-total]');
            const remainder = form.querySelector('[data-project-budget-remainder]');
            form.dataset.projectBudgetReady = '1';
            if (total instanceof HTMLInputElement) {
                total.dataset.budgetAuto = total.value.trim() === '' ? '1' : '0';
            }

            const updateTotal = () => {
                if (!(total instanceof HTMLInputElement)) return;
                let sum = 0;
                parts.forEach((input) => {
                    if (!(input instanceof HTMLInputElement)) return;
                    sum += parseGroupedNumber(input.value) || 0;
                });
                if (total.dataset.budgetAuto === '1') {
                    total.value = sum > 0 ? formatGroupedNumber(sum) : '';
                }
                const totalValue = parseGroupedNumber(total.value) || 0;
                const balance = totalValue - sum;
                total.setCustomValidity(balance < -0.00001 ? 'Сумма частей не может быть больше общего бюджета.' : '');
                if (remainder instanceof HTMLElement) {
                    remainder.classList.toggle('is-error', balance < -0.00001);
                    if (totalValue <= 0 && sum <= 0) {
                        remainder.textContent = 'Укажите общую сумму или начните заполнять части.';
                    } else if (balance < -0.00001) {
                        remainder.textContent = `Части превышают общий бюджет на ${formatGroupedNumber(Math.abs(balance))} тыс. ₽`;
                    } else if (balance > 0.00001) {
                        remainder.textContent = `Пока не распределено: ${formatGroupedNumber(balance)} тыс. ₽`;
                    } else {
                        remainder.textContent = 'Бюджет распределён полностью.';
                    }
                }
            };

            parts.forEach((input) => {
                if (!(input instanceof HTMLInputElement)) return;
                input.addEventListener('input', updateTotal);
                input.addEventListener('blur', () => {
                    updateTotal();
                });
            });
            if (total instanceof HTMLInputElement) {
                total.addEventListener('input', () => {
                    total.dataset.budgetAuto = total.value.trim() === '' ? '1' : '0';
                    updateTotal();
                });
                total.addEventListener('blur', updateTotal);
            }
            updateTotal();
        });
    }

    function initSubmitLock() {
        document.addEventListener('submit', (event) => {
            const form = event.target;
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            if (
                form.matches('[data-admin-user-form], [data-folder-open-form], [data-no-submit-lock]') ||
                form.dataset.noSubmitLock === '1'
            ) {
                return;
            }
            if (event.defaultPrevented) {
                return;
            }
            if (form.dataset.submitLocked === '1') {
                event.preventDefault();
                return;
            }

            form.dataset.submitLocked = '1';
            form.setAttribute('aria-busy', 'true');

            const submitter = event.submitter;
            if (submitter instanceof HTMLElement && submitter.getAttribute('name')) {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.getAttribute('name') || '';
                hidden.value = submitter.getAttribute('value') || '';
                hidden.dataset.submitLockValue = '1';
                form.appendChild(hidden);
            }

            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
                button.disabled = true;
                button.classList.add('is-disabled');
            });

            if (submitter instanceof HTMLButtonElement) {
                submitter.disabled = true;
                submitter.classList.add('is-disabled');
                submitter.dataset.submitLabel = submitter.dataset.submitLabel || submitter.textContent.trim();
                submitter.textContent = submitter.dataset.submittingLabel || 'Отправка...';
            } else if (submitter instanceof HTMLInputElement) {
                submitter.disabled = true;
                submitter.classList.add('is-disabled');
            }
        });
    }

    function initKnowledgeBase() {
        document.querySelectorAll('[data-knowledge-toc]').forEach((toc) => {
            const reader = toc.closest('[data-knowledge-reader]');
            const body = reader?.querySelector('[data-knowledge-body]');
            const links = toc.querySelector('[data-knowledge-toc-links]');
            if (!(body instanceof HTMLElement) || !(links instanceof HTMLElement)) {
                return;
            }
            const headings = Array.from(body.querySelectorAll('h2, h3'));
            if (headings.length === 0) {
                toc.hidden = true;
                return;
            }
            const used = new Set();
            headings.forEach((heading, index) => {
                const source = (heading.textContent || '').trim().toLocaleLowerCase('ru-RU');
                let id = source
                    .replace(/[^\p{L}\p{N}]+/gu, '-')
                    .replace(/^-+|-+$/g, '')
                    .slice(0, 72) || `section-${index + 1}`;
                const baseId = id;
                let suffix = 2;
                while (used.has(id) || document.getElementById(id)) {
                    id = `${baseId}-${suffix}`;
                    suffix += 1;
                }
                used.add(id);
                heading.id = id;
                const link = document.createElement('a');
                link.href = `#${id}`;
                link.dataset.level = heading.tagName === 'H3' ? '3' : '2';
                link.textContent = heading.textContent || `Раздел ${index + 1}`;
                links.appendChild(link);
            });
        });

        document.querySelectorAll('[data-knowledge-tree]').forEach((tree) => {
            if (tree instanceof HTMLDetailsElement && window.matchMedia('(max-width: 900px)').matches) {
                tree.open = false;
            }
        });

        document.querySelectorAll('[data-knowledge-editor-form]').forEach((form) => {
            if (!(form instanceof HTMLFormElement)) {
                return;
            }
            const editor = form.querySelector('[data-knowledge-editor]');
            const surface = form.querySelector('[data-editor-surface]');
            const input = form.querySelector('[data-editor-input]');
            const status = form.querySelector('[data-editor-status]');
            if (!(editor instanceof HTMLElement) || !(surface instanceof HTMLElement) || !(input instanceof HTMLTextAreaElement)) {
                return;
            }

            let dirtyVersion = 0;
            let savedVersion = 0;
            let saving = false;
            const autosaveUrl = form.dataset.autosaveUrl || '';

            const setStatus = (message, state = '') => {
                if (!(status instanceof HTMLElement)) {
                    return;
                }
                status.textContent = message;
                status.classList.remove('is-saving', 'is-saved', 'is-error');
                if (state) {
                    status.classList.add(`is-${state}`);
                }
            };
            const sync = () => {
                input.value = surface.innerHTML;
            };
            const markDirty = () => {
                dirtyVersion += 1;
                sync();
                setStatus(autosaveUrl ? 'Есть несохранённые изменения.' : 'Сохраните документ, чтобы включить автосохранение.');
            };
            const execute = (command, value = null) => {
                surface.focus();
                document.execCommand(command, false, value);
                markDirty();
            };

            editor.querySelectorAll('button').forEach((button) => {
                button.addEventListener('mousedown', (event) => event.preventDefault());
            });
            editor.querySelectorAll('[data-editor-command]').forEach((button) => {
                button.addEventListener('click', () => execute(button.dataset.editorCommand || ''));
            });
            editor.querySelector('[data-editor-block]')?.addEventListener('change', (event) => {
                const select = event.currentTarget;
                if (select instanceof HTMLSelectElement) {
                    execute('formatBlock', `<${select.value}>`);
                }
            });
            editor.querySelector('[data-editor-link]')?.addEventListener('click', () => {
                const href = window.prompt('Адрес ссылки:');
                if (href) {
                    execute('createLink', href.trim());
                }
            });
            editor.querySelector('[data-editor-callout]')?.addEventListener('click', () => {
                execute('formatBlock', '<blockquote>');
            });
            editor.querySelector('[data-editor-table]')?.addEventListener('click', () => {
                execute('insertHTML', '<table><thead><tr><th>Заголовок</th><th>Значение</th></tr></thead><tbody><tr><td>Строка</td><td>Данные</td></tr></tbody></table><p><br></p>');
            });

            surface.addEventListener('input', markDirty);
            form.querySelectorAll('input, select').forEach((control) => {
                control.addEventListener('input', markDirty);
                control.addEventListener('change', markDirty);
            });
            form.addEventListener('submit', sync);

            const autosave = async () => {
                if (!autosaveUrl || saving || dirtyVersion === savedVersion) {
                    return;
                }
                const targetVersion = dirtyVersion;
                const title = form.querySelector('input[name="title"]');
                if (title instanceof HTMLInputElement && title.value.trim() === '') {
                    setStatus('Добавьте название, чтобы сохранить черновик.', 'error');
                    return;
                }
                saving = true;
                sync();
                setStatus('Сохраняю черновик…', 'saving');
                try {
                    const response = await fetch(autosaveUrl, {
                        method: 'POST',
                        body: new FormData(form),
                        headers: { 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) {
                        throw new Error(result.message || 'Не удалось сохранить черновик.');
                    }
                    savedVersion = targetVersion;
                    setStatus(`Черновик сохранён в ${result.saved_at || 'только что'}.`, 'saved');
                } catch (error) {
                    setStatus(error instanceof Error ? error.message : 'Не удалось сохранить черновик.', 'error');
                } finally {
                    saving = false;
                }
            };

            if (autosaveUrl) {
                window.setInterval(autosave, 15000);
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'hidden') {
                        autosave();
                    }
                });
            }
        });
    }

    function initTeamManagement() {
        const rows = Array.from(document.querySelectorAll('[data-team-row]'));
        if (!rows.length) {
            return;
        }
        const search = document.querySelector('[data-team-search]');
        const department = document.querySelector('[data-team-department]');
        const status = document.querySelector('[data-team-status]');
        const empty = document.querySelector('[data-team-empty]');
        const apply = () => {
            const query = search instanceof HTMLInputElement ? search.value.trim().toLocaleLowerCase('ru-RU') : '';
            const departmentValue = department instanceof HTMLSelectElement ? department.value : '';
            const statusValue = status instanceof HTMLSelectElement ? status.value : '';
            let visible = 0;
            rows.forEach((row) => {
                const matches = (!query || (row.dataset.search || '').includes(query))
                    && (!departmentValue || row.dataset.department === departmentValue)
                    && (!statusValue || row.dataset.status === statusValue);
                row.classList.toggle('is-hidden', !matches);
                if (matches) {
                    visible += 1;
                }
            });
            if (empty) {
                empty.classList.toggle('is-hidden', visible !== 0);
            }
        };
        [search, department, status].forEach((control) => {
            if (control) {
                control.addEventListener('input', apply);
                control.addEventListener('change', apply);
            }
        });
        apply();

        document.querySelectorAll('[data-team-inline-form]').forEach((form) => {
            const formId = form.id;
            const controls = Array.from(document.querySelectorAll(`[form="${formId}"][data-team-inline-control]`));
            const save = document.querySelector(`[form="${formId}"][data-team-inline-save]`);
            const departmentControl = controls.find((control) => control.hasAttribute('data-team-inline-department'));
            const groupControl = controls.find((control) => control.hasAttribute('data-team-inline-group'));
            const filterGroups = () => {
                if (!(departmentControl instanceof HTMLSelectElement) || !(groupControl instanceof HTMLSelectElement)) return;
                const departmentCode = departmentControl.value;
                Array.from(groupControl.options).forEach((option) => {
                    if (!option.value) return;
                    option.hidden = option.dataset.department !== departmentCode;
                });
                const selected = groupControl.selectedOptions[0];
                if (selected && selected.value && selected.dataset.department !== departmentCode) groupControl.value = '';
            };
            filterGroups();
            controls.forEach((control) => control.addEventListener('change', () => {
                if (control === departmentControl) filterGroups();
                form.closest('[data-team-row]')?.classList.add('is-dirty');
                if (save instanceof HTMLButtonElement) save.disabled = false;
            }));
            form.addEventListener('submit', (event) => {
                const activeControl = controls.find((control) => control.hasAttribute('data-team-inline-active'));
                if (activeControl instanceof HTMLSelectElement
                    && form.dataset.originalActive === '1'
                    && activeControl.value === '0'
                    && !window.confirm('Сотрудник потеряет доступ к Лемме. Продолжить?')) {
                    event.preventDefault();
                }
            });
        });
    }

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeTaskTour();
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        initDirectorDashboard();
        initCopyPathButtons();
        initModelFragmentQueue();
        initDprOpenLinks();
        initFolderOpenForms();
        initAdminUsersAjax();
        initLaborEstimateUnits();
        initDateInputs();
        initTimeCalendarForms();
        initProjectBudgetInputs();
        initOrgStructure();
        initKnowledgeBase();
        initTeamManagement();
        initStaffingSearch();
        initPerformanceQuestionnaireRecovery();
        initSubmitLock();

        if (storageGet(taskTourPendingKey) !== '1') {
            return;
        }

        storageRemove(taskTourPendingKey);
        if (taskTourContext()) {
            startTaskTour();
        }
    });
})();

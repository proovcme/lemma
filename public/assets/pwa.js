(function () {
    'use strict';

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const base = window.APP_BASE || '';
    const url = (path) => base + path;
    const settings = document.querySelector('[data-push-settings]');
    let registration = null;

    async function post(path, payload) {
        const response = await fetch(url(path), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf, 'Accept': 'application/json'},
            body: JSON.stringify(payload || {}),
        });
        const data = await response.json().catch(() => ({ok: false, message: 'Сервер вернул некорректный ответ.'}));
        if (!response.ok || data.ok === false) throw new Error(data.message || 'Операция не выполнена.');
        return data;
    }

    function base64Key(value) {
        const padding = '='.repeat((4 - value.length % 4) % 4);
        const binary = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(binary, (char) => char.charCodeAt(0));
    }

    function installed() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    async function syncAppBadge() {
        if (!('Notification' in window) || !('setAppBadge' in navigator) || Notification.permission !== 'granted') return;
        const count = Math.max(0, Number.parseInt(document.body.dataset.unreadNotifications || '0', 10) || 0);
        if (count > 0) await navigator.setAppBadge(count);
        else if ('clearAppBadge' in navigator) await navigator.clearAppBadge();
    }

    function render(state, message) {
        if (!settings) return;
        settings.dataset.pushState = state;
        const text = settings.querySelector('[data-push-message]');
        if (text) text.textContent = message;
        settings.querySelector('[data-push-enable]')?.toggleAttribute('hidden', state !== 'available');
        settings.querySelector('[data-push-test]')?.toggleAttribute('hidden', state !== 'active');
        settings.querySelector('[data-push-disable]')?.toggleAttribute('hidden', state !== 'active');
    }

    async function refresh() {
        if (!settings) return;
        if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
            render('unsupported', 'Этот браузер не поддерживает системные push-уведомления.');
            return;
        }
        if (/iPhone|iPad|iPod/.test(navigator.userAgent) && !installed()) {
            render('install', 'На iPhone сначала добавьте Лемму на экран «Домой», затем откройте её с иконки.');
            return;
        }
        registration = await navigator.serviceWorker.ready;
        const response = await fetch(url('/push/config'), {credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        if (!response.ok) return;
        const config = await response.json();
        if (!config.enabled || !config.publicKey) {
            render('disabled', 'Сервер push пока не настроен.');
            return;
        }
        const subscription = await registration.pushManager.getSubscription();
        if (subscription && Notification.permission === 'granted') {
            render('active', 'Уведомления включены. Лемма сможет сообщать о задачах и согласованиях, даже когда закрыта.');
        } else if (Notification.permission === 'denied') {
            render('blocked', 'Уведомления запрещены в настройках устройства. Разрешите их для Лемме и вернитесь сюда.');
        } else {
            settings.dataset.vapidKey = config.publicKey;
            render('available', 'Получайте новые задачи, проверки, согласования и важные сроки на этом устройстве.');
        }
    }

    async function enable() {
        const button = settings?.querySelector('[data-push-enable]');
        if (button) button.disabled = true;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') throw new Error('Разрешение на уведомления не выдано.');
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: base64Key(settings.dataset.vapidKey || ''),
            });
            const payload = subscription.toJSON();
            payload.contentEncoding = (PushManager.supportedContentEncodings || ['aes128gcm'])[0];
            const result = await post('/push/subscribe', payload);
            render('active', result.message);
        } catch (error) {
            render(Notification.permission === 'denied' ? 'blocked' : 'available', error.message || 'Не удалось включить уведомления.');
        } finally {
            if (button) button.disabled = false;
        }
    }

    async function disable() {
        const subscription = registration ? await registration.pushManager.getSubscription() : null;
        if (subscription) {
            await post('/push/unsubscribe', {endpoint: subscription.endpoint});
            await subscription.unsubscribe();
        }
        render('available', 'Push-уведомления на этом устройстве выключены.');
    }

    async function test() {
        const result = await post('/push/test', {});
        render('active', result.message + ' Обычно доставка занимает несколько секунд.');
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register(url('/sw.js'), {scope: url('/')}).catch(() => {});
    }
    settings?.querySelector('[data-push-enable]')?.addEventListener('click', enable);
    settings?.querySelector('[data-push-disable]')?.addEventListener('click', () => disable().catch((error) => render('active', error.message)));
    settings?.querySelector('[data-push-test]')?.addEventListener('click', () => test().catch((error) => render('active', error.message)));
    window.addEventListener('load', () => {
        refresh().catch(() => render('unsupported', 'Не удалось проверить push-уведомления.'));
        syncAppBadge().catch(() => {});
    });
})();

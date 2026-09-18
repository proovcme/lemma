'use strict';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
    let message = {};
    try { message = event.data ? event.data.json() : {}; } catch (_) { message = {}; }
    const title = String(message.title || 'Лемма').slice(0, 180);
    const path = typeof message.url === 'string' && message.url.startsWith('/') && !message.url.startsWith('//')
        ? message.url : '/notifications';
    const operations = [self.registration.showNotification(title, {
        body: String(message.body || 'Новое уведомление').slice(0, 500),
        icon: message.icon || '/pwa/icon-192.png',
        badge: message.badge || '/pwa/badge-96.png',
        tag: message.tag || 'locia-notification',
        renotify: true,
        data: {url: path},
    })];
    const badgeCount = Math.max(0, Number.parseInt(message.badgeCount, 10) || 0);
    if ('setAppBadge' in self.navigator) {
        operations.push(badgeCount > 0 ? self.navigator.setAppBadge(badgeCount) : self.navigator.clearAppBadge());
    }
    event.waitUntil(Promise.all(operations));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/notifications', self.location.origin).href;
    event.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then((windows) => {
        for (const client of windows) {
            if (client.url.startsWith(self.location.origin) && 'focus' in client) {
                client.navigate(target);
                return client.focus();
            }
        }
        return self.clients.openWindow ? self.clients.openWindow(target) : undefined;
    }));
});

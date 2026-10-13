import './bootstrap';
import { createApp } from 'vue';
import router from './router';
import App from './App.vue';
import { ui } from './ui';

const app = createApp(App);

app.use(router);

app.mount('#app');

// Installable app (PWA). The service worker only caches the interface;
// downloads, the API and audio always come from the server.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Not available (e.g. plain http on a LAN address): the app still works normally
        });
    });
}

// Chrome/Edge/Android offer installing through this event; the app shows its own button
window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    ui.installPrompt = event;
});

window.addEventListener('appinstalled', () => {
    ui.installPrompt = null;
});

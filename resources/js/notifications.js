import { reactive } from 'vue';
import { downloadAPI } from './services/api';
import { readStorage, writeStorage } from './ui';

/**
 * System notifications (outside the browser) when downloads finish or fail.
 *
 * The app already polls the queue every few seconds; while something is queued,
 * this remembers the active downloads and notifies once each one leaves the queue.
 * Works while the app is open in any tab or as an installed app, even minimised.
 */

const KEY = 'notifications';

export const notifications = reactive({
    supported: typeof window !== 'undefined' && 'Notification' in window,
    // Browsers only allow notifications on https or localhost
    secure: typeof window !== 'undefined' && window.isSecureContext,
    permission: typeof Notification !== 'undefined' ? Notification.permission : 'denied',
    wanted: readStorage(KEY) === '1',
});

export function notificationsOn() {
    return notifications.supported && notifications.wanted && notifications.permission === 'granted';
}

/** Ask for permission (needs a click) and switch notifications on. Returns true when on. */
export async function enableNotifications() {
    if (!notifications.supported) return false;
    notifications.permission = await Notification.requestPermission();
    notifications.wanted = notifications.permission === 'granted';
    writeStorage(KEY, notifications.wanted ? '1' : '0');
    return notifications.wanted;
}

export function disableNotifications() {
    notifications.wanted = false;
    writeStorage(KEY, '0');
    watching.clear();
}

// Active downloads seen so far: id → title
const watching = new Map();

/**
 * Called on every queue poll with the number of waiting/running jobs
 */
export async function checkFinishedDownloads(pending) {
    if (!notificationsOn()) {
        watching.clear();
        return;
    }
    if (!pending && !watching.size) return;

    const { data } = await downloadAPI.getAll({ status: 'pending,processing', per_page: 100 });
    const active = new Set();
    for (const download of data.data) {
        active.add(download.id);
        watching.set(download.id, download.title);
    }

    const left = [...watching.keys()].filter((id) => !active.has(id));
    if (!left.length) return;
    left.forEach((id) => watching.delete(id));

    const results = await Promise.all(
        left.map((id) => downloadAPI.get(id).then(({ data }) => data.download).catch(() => null)),
    );
    // Cancelled or deleted downloads don't need a notification
    const completed = results.filter((d) => d?.status === 'completed');
    const failed = results.filter((d) => d?.status === 'failed');

    if (completed.length === 1) {
        show('Download finished', completed[0].title, { icon: completed[0].thumbnail_url, tag: `done-${completed[0].id}` });
    } else if (completed.length > 1) {
        show(`${completed.length} downloads finished`, listTitles(completed), { tag: 'done-many' });
    }

    if (failed.length === 1) {
        show('Download failed', `${failed[0].title}\n${failed[0].error_message || ''}`.trim(), { tag: `failed-${failed[0].id}`, url: '/queue' });
    } else if (failed.length > 1) {
        show(`${failed.length} downloads failed`, listTitles(failed), { tag: 'failed-many', url: '/queue' });
    }
}

export function sendTestNotification() {
    show('Notifications are on', 'You will see a message like this when a download finishes.', { tag: 'test' });
}

function listTitles(downloads) {
    const titles = downloads.slice(0, 3).map((d) => `• ${d.title}`);
    if (downloads.length > 3) titles.push(`and ${downloads.length - 3} more`);
    return titles.join('\n');
}

async function show(title, body, { icon, tag, url = '/history' } = {}) {
    const options = {
        body,
        icon: icon || '/icon-192.png',
        badge: '/icon-192.png',
        tag: `audiofetch-${tag}`,
        data: { url },
    };

    // Through the service worker where possible: required on Android, and clicks
    // can open the app even when its tab was closed meanwhile
    try {
        const registration = await navigator.serviceWorker?.getRegistration();
        if (registration) {
            await registration.showNotification(title, options);
            return;
        }
    } catch {
        // Fall back to a page notification
    }

    try {
        const notification = new Notification(title, options);
        notification.onclick = () => {
            window.focus();
            window.dispatchEvent(new CustomEvent('audiofetch:open', { detail: url }));
            notification.close();
        };
    } catch {
        // Not allowed here (e.g. Android without a service worker)
    }
}

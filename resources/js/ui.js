import { reactive } from 'vue';

/**
 * App-wide UI state: snackbar messages, the confirm dialog and the colour theme.
 */
export const ui = reactive({
    snackbar: null, // { id, message, type }
    dialog: null, // { title, message, confirmLabel, danger, checkbox, checked, resolve }
    theme: document.documentElement.dataset.theme || 'light',
    accent: document.documentElement.style.getPropertyValue('--accent-raw').trim() || null, // custom colour, null = default blue
    installPrompt: null, // set when the browser allows installing the app
    lockEnabled: false, // APP_PASSWORD is set on the server
    locked: false, // ...and this browser has not entered it yet
});

export async function installApp() {
    const prompt = ui.installPrompt;
    if (!prompt) return;
    prompt.prompt();
    const { outcome } = await prompt.userChoice;
    if (outcome === 'accepted') ui.installPrompt = null;
}

let snackbarTimer = null;

export function notify(message, type = 'info') {
    clearTimeout(snackbarTimer);
    ui.snackbar = { id: Date.now(), message, type };
    snackbarTimer = setTimeout(() => (ui.snackbar = null), type === 'error' ? 6000 : 3500);
}

export function dismissSnackbar() {
    clearTimeout(snackbarTimer);
    ui.snackbar = null;
}

/**
 * Promise-based confirm dialog. Resolves to false when cancelled, true when
 * confirmed, or { checked } when a checkbox option was offered.
 */
export function confirmDialog({ title, message = '', confirmLabel = 'Confirm', danger = false, checkbox = null }) {
    return new Promise((resolve) => {
        ui.dialog = { title, message, confirmLabel, danger, checkbox, checked: false, resolve };
    });
}

export function closeDialog(confirmed) {
    const dialog = ui.dialog;
    if (!dialog) return;
    ui.dialog = null;
    dialog.resolve(confirmed ? (dialog.checkbox ? { checked: dialog.checked } : true) : false);
}

export function toggleTheme() {
    ui.theme = ui.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = ui.theme;
    try {
        localStorage.setItem('theme', ui.theme);
    } catch (e) {
        // Storage may be unavailable (private mode); the theme still applies for this visit
    }
}

/** Colour presets for Settings → Appearance (null is the built-in blue) */
export const ACCENTS = [
    { name: 'Blue', value: null, swatch: '#2f5dd3' },
    { name: 'Indigo', value: '#4f46e5' },
    { name: 'Violet', value: '#7c3aed' },
    { name: 'Pink', value: '#db2777' },
    { name: 'Red', value: '#dc2626' },
    { name: 'Orange', value: '#ea580c' },
    { name: 'Amber', value: '#d97706' },
    { name: 'Green', value: '#16a34a' },
    { name: 'Teal', value: '#0d9488' },
    { name: 'Cyan', value: '#0891b2' },
    { name: 'Slate', value: '#475569' },
];

const HEX = /^#[0-9a-f]{6}$/i;

/**
 * Recolour the whole app. The colour is saved on this device and applied before
 * the first paint by app.blade.php. Pass null for the default blue.
 */
export function setAccent(color) {
    const root = document.documentElement;
    const raw = HEX.test(color || '') ? color.toLowerCase() : null;
    ui.accent = raw;

    if (!raw) {
        delete root.dataset.accent;
        root.style.removeProperty('--accent');
        root.style.removeProperty('--accent-raw');
        writeStorage('accent', '');
        return;
    }

    const light = readableOnWhite(raw);
    root.dataset.accent = 'custom';
    root.style.setProperty('--accent', light);
    root.style.setProperty('--accent-raw', raw);
    writeStorage('accent', JSON.stringify({ raw, light }));
}

/** Darken a colour until white text on it (and it as text on white) is readable */
function readableOnWhite(hex) {
    const rgb = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const luminance = (c) =>
        c
            .map((v) => v / 255)
            .map((v) => (v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4))
            .reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);

    let color = rgb;
    for (let step = 0; step < 20 && 1.05 / (luminance(color) + 0.05) < 4; step++) {
        color = color.map((v) => Math.round(v * 0.92));
    }
    return '#' + color.map((v) => v.toString(16).padStart(2, '0')).join('');
}

/** localStorage that never throws (private mode, blocked storage) */
export function readStorage(key) {
    try {
        return localStorage.getItem(key);
    } catch (e) {
        return null;
    }
}

export function writeStorage(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        // Not critical: the preference lasts for this visit only
    }
}

/** Best error message from an axios error */
export function errorMessage(error, fallback = 'Something went wrong') {
    const data = error?.response?.data;
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (first?.length) return first[0];
    }
    return data?.message || data?.error || error?.message || fallback;
}

export function formatBytes(bytes) {
    if (!bytes) return '—';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${parseFloat((bytes / 1024 ** i).toFixed(i > 1 ? 1 : 0))} ${units[i]}`;
}

export function formatDuration(seconds) {
    if (!seconds && seconds !== 0) return '';
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60).toString().padStart(2, '0');
    return h ? `${h}:${m.toString().padStart(2, '0')}:${s}` : `${m}:${s}`;
}

/**
 * "1:30" / "1:02:03" / "90" → seconds. Empty → null, invalid → NaN.
 */
export function parseTime(value) {
    const text = String(value ?? '').trim();
    if (!text) return null;
    if (!/^\d+(:\d{1,2}){0,2}$/.test(text)) return NaN;
    return text.split(':').reduce((total, part) => total * 60 + Number(part), 0);
}

const relative = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

export function formatRelative(value) {
    if (!value) return '';
    const date = typeof value === 'number' ? new Date(value * 1000) : new Date(value);
    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
    const abs = Math.abs(seconds);
    if (abs < 45) return 'just now';
    if (abs < 3600) return relative.format(Math.round(seconds / 60), 'minute');
    if (abs < 86400) return relative.format(Math.round(seconds / 3600), 'hour');
    if (abs < 86400 * 7) return relative.format(Math.round(seconds / 86400), 'day');
    return date.toLocaleDateString();
}

/** All YouTube links in a block of text (one per line, spaces, commas...), without duplicates */
export function extractYoutubeLinks(text) {
    const found = String(text ?? '').match(/(?:https?:\/\/)?(?:(?:www|m|music)\.)?(?:youtube\.com|youtu\.be)\/[^\s,;"'<>]+/gi) || [];
    return [...new Set(found.map((link) => (/^https?:\/\//i.test(link) ? link : `https://${link}`)))];
}

/** True for anything that is meant as a YouTube link (vs. search words) */
export function looksLikeYoutubeUrl(text) {
    return /^(https?:\/\/)?((www|m|music)\.)?(youtube\.com|youtu\.be)\//i.test(String(text ?? '').trim());
}

export const YOUTUBE_URL =/^(https?:\/\/)?(www\.|m\.)?(youtube\.com|youtu\.be|music\.youtube\.com)\/.+$/;

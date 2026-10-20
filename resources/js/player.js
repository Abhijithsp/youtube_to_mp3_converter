import { reactive } from 'vue';
import { notify } from './ui';

/**
 * One shared audio element for the whole app, so playback continues while
 * moving between pages. The PlayerBar component renders this state.
 */
const audio = new Audio();
audio.preload = 'metadata';

export const player = reactive({
    track: null, // the download being played
    queue: [], // downloads in play order
    index: -1, // position of track in queue
    shuffle: false,
    repeat: 'off', // off | all | one
    playing: false,
    loading: false,
    currentTime: 0,
    duration: 0,
    volume: 1,
    muted: false,
});

// Original order, restored when shuffle is switched off
let orderedQueue = [];

// Whether the current track has already been reloaded once after a playback error
let retried = false;

function readStored(key, fallback) {
    try {
        return localStorage.getItem(key) ?? fallback;
    } catch (e) {
        return fallback;
    }
}

function store(key, value) {
    try {
        localStorage.setItem(key, String(value));
    } catch (e) {
        // Storage unavailable (private mode): settings last for this visit only
    }
}

const savedVolume = Number(readStored('player-volume', 1));
audio.volume = player.volume = Number.isFinite(savedVolume) ? Math.min(1, Math.max(0, savedVolume)) : 1;
player.shuffle = readStored('player-shuffle', 'false') === 'true';
player.repeat = ['off', 'all', 'one'].includes(readStored('player-repeat', 'off')) ? readStored('player-repeat', 'off') : 'off';

audio.addEventListener('play', () => (player.playing = true));
audio.addEventListener('pause', () => (player.playing = false));
audio.addEventListener('waiting', () => (player.loading = true));
audio.addEventListener('playing', () => (player.loading = false));
audio.addEventListener('canplay', () => (player.loading = false));
audio.addEventListener('timeupdate', () => (player.currentTime = audio.currentTime));
audio.addEventListener('durationchange', () => {
    player.duration = Number.isFinite(audio.duration) ? audio.duration : player.track?.duration || 0;
});
audio.addEventListener('ended', () => {
    if (player.repeat === 'one') {
        audio.currentTime = 0;
        audio.play().catch(() => {});
        return;
    }
    if (!next({ auto: true })) {
        // End of the queue: rewind so pressing play starts over
        player.playing = false;
        player.currentTime = 0;
        audio.currentTime = 0;
    }
});
audio.addEventListener('error', () => {
    if (!player.track || !audio.getAttribute('src')) return;

    // A replayed song can get an empty response from a busy single-threaded server
    // (php artisan serve on Windows). Ask once more with a fresh request before giving up.
    if (!retried) {
        retried = true;
        const resumeAt = player.currentTime;
        const url = player.track.download_url;
        audio.src = `${url}${url.includes('?') ? '&' : '?'}retry=${Date.now()}`;
        if (resumeAt > 0) audio.addEventListener('loadedmetadata', () => (audio.currentTime = resumeAt), { once: true });
        audio.play().catch(() => (player.loading = false));
        return;
    }

    player.loading = false;
    player.playing = false;
    notify(`"${player.track.title}" could not be played. It may have been moved or deleted.`, 'error');
    // Skip broken files when playing a list
    if (player.queue.length > 1) next({ auto: true });
});

export function isCurrent(download) {
    return !!download && player.track?.id === download.id;
}

function load(download) {
    retried = false;
    player.track = download;
    player.currentTime = 0;
    player.duration = download.duration || 0;
    player.loading = true;
    audio.src = download.download_url;
    audio.play().catch(() => (player.loading = false));
    updateMediaSession(download);
}

function shuffled(list, first) {
    const rest = list.filter((d) => d.id !== first?.id);
    for (let i = rest.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1));
        [rest[i], rest[j]] = [rest[j], rest[i]];
    }
    return first ? [first, ...rest] : rest;
}

/**
 * Play a list of downloads, starting at the given one (or the first).
 * Items without a file (not completed) are skipped.
 */
export function playAll(downloads, start = null) {
    const playable = downloads.filter((d) => d?.download_url);
    if (!playable.length) {
        notify('Nothing to play yet.');
        return;
    }

    const first = start && playable.find((d) => d.id === start.id) ? playable.find((d) => d.id === start.id) : player.shuffle ? null : playable[0];
    orderedQueue = playable;
    player.queue = player.shuffle ? shuffled(playable, first) : playable;
    player.index = first ? player.queue.findIndex((d) => d.id === first.id) : 0;
    load(player.queue[player.index]);
}

/** Play one download; when it is part of `list`, the rest of the list follows */
export function play(download, list = null) {
    if (!download?.download_url) return;

    if (isCurrent(download)) {
        audio.play().catch(() => {});
        return;
    }

    if (list && list.length > 1) {
        playAll(list, download);
        return;
    }

    orderedQueue = [download];
    player.queue = [download];
    player.index = 0;
    load(download);
}

/** Play/pause the given track, or the current one when called without arguments */
export function toggle(download = player.track, list = null) {
    if (!download) return;
    if (isCurrent(download) && player.playing) {
        audio.pause();
    } else {
        play(download, list);
    }
}

export function hasNext() {
    return player.index < player.queue.length - 1 || (player.repeat === 'all' && player.queue.length > 1);
}

export function hasPrevious() {
    return player.index > 0 || (player.repeat === 'all' && player.queue.length > 1);
}

/** Returns false when the end of the queue was reached */
export function next({ auto = false } = {}) {
    if (!player.queue.length) return false;

    let index = player.index + 1;
    if (index >= player.queue.length) {
        if (player.repeat !== 'all') {
            if (!auto) notify('This was the last song.');
            return false;
        }
        index = 0;
    }

    player.index = index;
    load(player.queue[index]);
    return true;
}

export function previous() {
    // Like most players: restart the song unless it only just started
    if (audio.currentTime > 3 || !hasPrevious()) {
        seek(0);
        return;
    }
    player.index = player.index > 0 ? player.index - 1 : player.queue.length - 1;
    load(player.queue[player.index]);
}

export function toggleShuffle() {
    player.shuffle = !player.shuffle;
    store('player-shuffle', player.shuffle);

    if (player.queue.length > 1 && player.track) {
        player.queue = player.shuffle ? shuffled(orderedQueue, player.track) : [...orderedQueue];
        player.index = player.queue.findIndex((d) => d.id === player.track.id);
    }
}

export function cycleRepeat() {
    player.repeat = { off: 'all', all: 'one', one: 'off' }[player.repeat];
    store('player-repeat', player.repeat);
}

export function pause() {
    audio.pause();
}

/** Stop and close the player */
export function stop() {
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
    player.track = null;
    player.queue = [];
    player.index = -1;
    orderedQueue = [];
    player.playing = false;
    player.loading = false;
    player.currentTime = 0;
    player.duration = 0;
    if ('mediaSession' in navigator) navigator.mediaSession.metadata = null;
}

export function seek(seconds) {
    if (!player.track) return;
    const max = player.duration || audio.duration || 0;
    audio.currentTime = Math.min(Math.max(0, seconds), max || seconds);
    player.currentTime = audio.currentTime;
}

export function skip(seconds) {
    seek(audio.currentTime + seconds);
}

export function setVolume(value) {
    audio.volume = player.volume = Math.min(1, Math.max(0, value));
    audio.muted = player.muted = player.volume === 0;
    store('player-volume', player.volume);
}

export function toggleMute() {
    audio.muted = player.muted = !player.muted;
}

/** Remove a deleted download from the queue (and stop it if it is playing) */
export function forget(download) {
    if (isCurrent(download)) {
        if (player.queue.length > 1 && hasNext()) {
            const id = download.id;
            next({ auto: true });
            removeFromQueue(id);
        } else {
            stop();
        }
        return;
    }
    removeFromQueue(download.id);
}

function removeFromQueue(id) {
    orderedQueue = orderedQueue.filter((d) => d.id !== id);
    player.queue = player.queue.filter((d) => d.id !== id);
    player.index = player.track ? player.queue.findIndex((d) => d.id === player.track.id) : -1;
}

function updateMediaSession(download) {
    if (!('mediaSession' in navigator)) return;
    navigator.mediaSession.metadata = new MediaMetadata({
        title: download.title,
        artist: download.artist || '',
        album: download.album || '',
        artwork: download.thumbnail_url ? [{ src: download.thumbnail_url, sizes: '480x360' }] : [],
    });
}

// Keyboard media keys / OS media controls
if ('mediaSession' in navigator) {
    const handlers = {
        play: () => audio.play(),
        pause: () => audio.pause(),
        stop: () => stop(),
        previoustrack: () => previous(),
        nexttrack: () => next(),
        seekbackward: () => skip(-10),
        seekforward: () => skip(10),
        seekto: (details) => seek(details.seekTime),
    };
    for (const [action, handler] of Object.entries(handlers)) {
        try {
            navigator.mediaSession.setActionHandler(action, handler);
        } catch (e) {
            // Action not supported by this browser
        }
    }
}

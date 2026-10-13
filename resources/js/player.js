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
    playing: false,
    loading: false,
    currentTime: 0,
    duration: 0,
    volume: 1,
    muted: false,
});

let savedVolume = 1;
try {
    savedVolume = Number(localStorage.getItem('player-volume') ?? 1);
} catch (e) {
    // Storage unavailable: keep full volume
}
audio.volume = player.volume = Number.isFinite(savedVolume) ? Math.min(1, Math.max(0, savedVolume)) : 1;

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
    player.playing = false;
    player.currentTime = 0;
    audio.currentTime = 0;
});
audio.addEventListener('error', () => {
    if (!player.track) return;
    player.loading = false;
    player.playing = false;
    notify('This file could not be played. It may have been moved or deleted.', 'error');
});

export function isCurrent(download) {
    return player.track?.id === download?.id;
}

export function play(download) {
    if (!download?.download_url) return;

    if (isCurrent(download)) {
        audio.play().catch(() => {});
        return;
    }

    player.track = download;
    player.currentTime = 0;
    player.duration = download.duration || 0;
    player.loading = true;
    audio.src = download.download_url;
    audio.play().catch(() => (player.loading = false));
    updateMediaSession(download);
}

/** Play/pause the given track, or the current one when called without arguments */
export function toggle(download = player.track) {
    if (!download) return;
    if (isCurrent(download) && player.playing) {
        audio.pause();
    } else {
        play(download);
    }
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
    try {
        localStorage.setItem('player-volume', String(player.volume));
    } catch (e) {
        // Not critical
    }
}

export function toggleMute() {
    audio.muted = player.muted = !player.muted;
}

/** Forget the track if it was deleted elsewhere in the app */
export function forget(download) {
    if (isCurrent(download)) stop();
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

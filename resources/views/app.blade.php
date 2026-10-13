<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>YouTube to MP3 Converter</title>
    <meta name="description" content="Convert YouTube videos to MP3, FLAC or WAV with tags and cover art.">
    <link rel="icon" href="/icon.svg" type="image/svg+xml">
    <link rel="icon" href="/icon-192.png" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#e9edf5" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#101217" media="(prefers-color-scheme: dark)">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="YT to MP3">
    <script>
        // Apply the saved/system theme before first paint to avoid a flash
        (function () {
            var saved = null;
            try { saved = localStorage.getItem('theme'); } catch (e) {}
            var dark = saved ? saved === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.dataset.theme = dark ? 'dark' : 'light';
        })();
    </script>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <div id="app"></div>
</body>
</html>

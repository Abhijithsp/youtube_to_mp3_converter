# 🎵 AudioFetch (Self-Hosted)
A self-hosted application to download and convert YouTube videos to high-quality MP3, FLAC or WAV audio files. Built with **Laravel 13**, **Vue.js 3**, and **Tailwind CSS**.

<a id="walkthrough"></a>
## 🎬 Walkthrough
https://github.com/user-attachments/assets/73c4f846-7593-4ab1-b06e-7fd8bf3b1400

A 9-minute tour: search and download, live progress, queue, player, lyrics, ringtone maker, chapters, even volume, ZIP downloads, folder picker, colour themes, notifications and the password lock. More screenshots, including phone, are in [docs/walkthrough](docs/walkthrough/README.md).


## 📚 Table of Contents
- [Walkthrough](#walkthrough)
- [About](#about)
- [Important Disclaimer & Terms of Use](#disclaimer)
- [Run with Docker](#docker)
- [Run on a Server](#server)
- [Full Setup](#full-setup)
- [Run the App](#run-the-app)
- [Scheduler Setup](#scheduler-setup)
- [Running Tests](#testing)
- [Health Checks & Troubleshooting](#health-checks)

<a id="about"></a>
## ℹ️ About
This project is a self-hosted YouTube audio converter built as a learning-driven passion project. It focuses on practical Laravel queue processing, media conversion workflows (`yt-dlp` + `ffmpeg`), and a modern Vue-based UI for personal experimentation.
<a id="disclaimer"></a>

## ⚠️ Important Disclaimer & Terms of Use
This project is a hobbyist experiment and is provided strictly for educational and demonstration purposes. Before exploring or implementing this codebase, please note the following:


- **Use at Your Own Risk:** This software is free to use, but it is provided "as-is" without any warranties of any kind. By using this code, you acknowledge that you do so at your own discretion.
- **Compliance Warning:** Use of this codebase may not align with Google’s (or other third-party) Service Policies. It is your responsibility to ensure that your specific implementation complies with the Terms of Service of any platforms or APIs you integrate with.
- **No Developer Liability:** The creator of this project shall not be held responsible or liable for any damages, data loss, account suspensions, or financial losses incurred by individuals or businesses who choose to adapt this code into a live product.
- **Not a Commercial Product:** This is a passion project, not a commercial offering. If you decide to transform this hobbyist codebase into a commercial product, you assume 100% of the legal and technical responsibility.

In short: Use it, learn from it, and break it—but do so with caution!

## ✨ Features
- **Search or paste**: Search YouTube by song name right in the app, or paste a video/YouTube Music link.
- **Playlist Support**: Bulk import entire playlists with smart selection.
- **High Quality**: MP3 (up to 320 kbps), FLAC (lossless) and WAV (24-bit / 48 kHz).
- **Trim & silence removal**: Keep only part of a video, and cut silent intros/outros.
- **Clean titles & tags**: Removes "(Official Music Video)", "[HD]" and similar; tags title, artist, album, year, genre and cover art. Files are named after the title.
- **Built-in player**: Play, seek on the song's waveform, play all, shuffle, repeat, next/previous, media keys.
- **Download as ZIP**: Select several songs in History and save them as one ZIP file.
- **Notifications**: A system notification when downloads finish or fail.
- **Colour themes**: Light or dark, with any accent colour.
- **Any download folder**: Browse and pick a folder (or create one) in Settings.
- **Optional password**: Lock the app with `APP_PASSWORD` when it runs on a server.
- **Queue System**: Background processing with retries and rate limiting.
- **Setup checklist**: First-run check of yt-dlp, FFmpeg, Deno, the queue worker and storage, with fix commands.
- **One-click yt-dlp update** from the Settings page.
- **Installable app (PWA)**: Install it on desktop or phone like a native app.
- **Docker**: One command to run everything, tools included.
- **Responsive Design**: Material-style light and dark themes for phone, tablet and desktop.

## 🛠️ Requirements
- **OS**: Windows, Linux, or macOS
- **PHP**: `>= 8.2`
- **Composer**: `>= 2.x`
- **Node.js**: `>= 18` and npm
- **Database**: SQLite (default) or MySQL/MariaDB
- **System tools**:
  - `yt-dlp` (downloader)
  - `ffmpeg` (audio conversion)

<a id="docker"></a>
## 🐳 Run with Docker
The easiest way: no PHP, Node, yt-dlp or FFmpeg to install. Requires [Docker](https://docs.docker.com/get-docker/).

```bash
git clone https://github.com/Abhijithsp/youtube_to_mp3_converter.git
cd youtube_to_mp3_converter
docker compose up -d
```

Open **http://localhost:8000**. Converted files appear in the `downloads/` folder next to `docker-compose.yml`; the database and settings live in `docker-data/`.

- Use another port: `APP_PORT=9000 docker compose up -d`
- Update to a newer version of the app: `git pull && docker compose up -d --build`
- If YouTube asks to *confirm you're not a bot*: export a `cookies.txt` from your browser (e.g. with a "Get cookies.txt" extension), put it in `docker-data/`, and uncomment `YTDLP_COOKIES_FILE` in `docker-compose.yml`. Reading browser cookies directly does not work inside Docker.

<a id="server"></a>
## 🌐 Run on a Server
The app is made for your own use. On your computer it needs no login. To run it on a server (a VPS, a home server, a NAS) for yourself:

1. **Set a password** in `.env` (or `docker-compose.yml`):
   ```env
   APP_PASSWORD=choose-a-long-password
   ```
   Every browser then asks for it once and remembers it for 30 days. Without it, anyone who finds the address can use your server to download and can browse its folders. To keep the password out of `.env` in plain text, run `php artisan app:hash-password` and paste the line it prints. In `docker-compose.yml`, write each `$` of a hash as `$$`.
2. **Choose where files go**: *Settings → Storage → Browse* lists the folders on the server (drives, home, Music, the Docker `/downloads` volume) and can create a new one. Get files onto your phone or PC with the download button in History.
3. **Use HTTPS** (e.g. Caddy, nginx + Let's Encrypt, or a Cloudflare/Tailscale tunnel) so the password is not sent in plain text. The app also works as an installable app (PWA) only over HTTPS.
4. Run the queue worker and scheduler as services (see [Scheduler Setup](#scheduler-setup)); `php artisan dev` is meant for your own computer.

To lock a device again, use *Settings → Access → Lock this device*. Changing `APP_PASSWORD` locks every device.

<a id="full-setup"></a>
## 🚀 Full Setup
### 1) Clone and install dependencies
```bash
git clone <your-repo-url>
cd youtube_converter
composer install
npm install
```

### 2) Environment setup
```bash
cp .env.example .env
php artisan key:generate
```

PowerShell equivalent:
```powershell
Copy-Item .env.example .env
php artisan key:generate
```

### 3) Database setup
By default, `.env.example` uses:
```env
DB_CONNECTION=sqlite
```

#### Option A: SQLite
```bash
touch database/database.sqlite
php artisan migrate
php artisan db:seed --class=SettingsSeeder
```

PowerShell equivalent:
```powershell
New-Item -ItemType File -Path .\database\database.sqlite -Force
php artisan migrate
php artisan db:seed --class=SettingsSeeder
```

#### Option B: MySQL/MariaDB
Update `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_db_name
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

Then run:
```bash
php artisan migrate
php artisan db:seed --class=SettingsSeeder
```

### 4) Install and verify system dependencies
```bash
yt-dlp --version
ffmpeg -version
```

If not recognized, add both binaries to your system `PATH` and restart your terminal.

### 5) Optional app env overrides
```env
APP_PASSWORD=                                 # set one when running on a server (see Run on a Server)
YOUTUBE_DOWNLOAD_PATH=storage/app/downloads   # absolute, or relative to the project folder
MAX_PLAYLIST_SIZE=50
RATE_LIMIT_SECONDS=2
MIN_DISK_SPACE_MB=500
YOUTUBE_JOB_TIMEOUT=600
YOUTUBE_JOB_TRIES=3

# If YouTube asks you to "Sign in to confirm you're not a bot" (also on the Settings page)
YTDLP_COOKIES_FROM_BROWSER=firefox   # firefox, chrome, edge, brave, ... (Chromium browsers must be closed)
YTDLP_COOKIES_FILE=                  # or an exported Netscape cookies.txt file
```

<a id="run-the-app"></a>
## ▶️ Run the App
### Recommended (single command)
```bash
composer run dev
```

This starts:
- Laravel dev server
- Queue listener
- Log stream (`php artisan pail`)
- Vite dev server

App URL:
- `http://127.0.0.1:8000`

### Alternative (separate terminals)
Terminal 1:
```bash
php artisan serve
```

Terminal 2:
```bash
php artisan queue:work
```

Terminal 3:
```bash
npm run dev
```

Optional Terminal 4:
```bash
php artisan pail --timeout=0
```

<a id="scheduler-setup"></a>
## ⏱️ Scheduler Setup (Required for automated cleanup)
This app schedules a daily cleanup job (`CleanupOldDownloadsJob`) in `routes/console.php`.

### Local development
Run the scheduler worker in a separate terminal:
```bash
php artisan schedule:work
```

### Production (Linux cron)
Add this cron entry so Laravel checks scheduled tasks every minute:
```cron
* * * * * cd /path/to/youtube_converter && php artisan schedule:run >> /dev/null 2>&1
```

### Production (Windows Task Scheduler)
Create a task that runs every 1 minute with:
```powershell
php artisan schedule:run
```

### Verify scheduler is working
```bash
php artisan schedule:list
php artisan schedule:run
```

You should see the scheduled cleanup task in the list, and `schedule:run` should execute due tasks.

## 🏗️ Build for Production
```bash
npm run build
```

## 📖 Usage Guide
1. Open the app in browser.
2. Paste a YouTube URL.
3. Select format (`mp3` or `flac`) and quality.
4. Start conversion and monitor queue/progress.
5. Download completed file from the app.

### 📲 Install as an app
Open the app in Chrome, Edge or Brave and use the **Install app** button in the menu (or the install icon in the address bar). On Android use *Add to Home screen*, on iPhone *Share → Add to Home Screen*. Browsers only offer installing on `localhost` or HTTPS.

<a id="testing"></a>
## 🧪 Running Tests
The test suite fakes `yt-dlp` and `ffmpeg`, so it runs offline in a few seconds (requires the `pdo_sqlite` PHP extension):
```bash
php artisan test
```

Real end-to-end downloads (needs network, `yt-dlp` and `ffmpeg`; YouTube may rate-limit repeated runs):
```bash
php artisan test --group=integration
```

<a id="health-checks"></a>
## 🩺 Health Checks & Troubleshooting
### Useful checks
```bash
php artisan about
php artisan route:list
php artisan queue:failed
php artisan optimize:clear
```

### Jobs stuck / no progress
- Ensure worker is running: `php artisan queue:work`
- Retry failed jobs:
  ```bash
  php artisan queue:failed
  php artisan queue:retry all
  ```

### "Sign in to confirm you're not a bot" / HTTP Error 429
YouTube rate-limits computers that make many requests. Wait a few minutes, or let yt-dlp use your YouTube login:
- **Settings → YouTube access**: pick the browser you are signed in with (Firefox works best; Chrome/Edge/Brave must be closed while downloading), or point to a `cookies.txt` file exported with a "Get cookies.txt" browser extension.
- Use **Test connection** to check it. If cookies can't be read, downloads continue without them.

### Downloads fail with "HTTP Error 403"
YouTube changes often; update yt-dlp: `yt-dlp -U` (or `winget upgrade yt-dlp` / `pip install -U yt-dlp`, depending on how you installed it).

### `yt-dlp` / `ffmpeg` not found
- Verify commands:
  ```bash
  yt-dlp --version
  ffmpeg -version
  ```
- Ensure both are available in the same terminal environment as Laravel/queue.

### Frontend not updating
- Run `npm run dev` in development.
- Rebuild assets with `npm run build` if needed.

### Database issues
- Confirm DB values in `.env`.
- Reset and reseed:
  ```bash
  php artisan migrate:fresh --seed
  ```

## ⚡ One-Time Setup Shortcut
```bash
composer run setup
```

This runs:
- `composer install`
- creates `.env` if missing
- `php artisan key:generate`
- `php artisan migrate --force`
- `npm install`
- `npm run build`

Then start:
```bash
composer run dev
```

## 🤝 Contributing
Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

## 📝 License
[MIT](https://choosealicense.com/licenses/mit/)

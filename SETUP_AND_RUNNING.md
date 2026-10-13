# Full Setup and Run Guide
This guide covers everything needed to install, configure, and run the YouTube Converter app locally.

## 1) Prerequisites
- PHP `>= 8.2`
- Composer `>= 2.x`
- Node.js `>= 18` and npm
- A database:
  - SQLite (default in `.env.example`), or
  - MySQL/MariaDB
- `yt-dlp` installed and available in `PATH`
- `ffmpeg` installed and available in `PATH`

## 2) Clone and Install
```bash
git clone <your-repo-url>
cd youtube_converter
composer install
npm install
```

## 3) Environment Setup
Copy env file and generate key:

```bash
cp .env.example .env
php artisan key:generate
```

On Windows PowerShell, use:

```powershell
Copy-Item .env.example .env
php artisan key:generate
```

## 4) Database Setup
By default, `.env.example` uses SQLite:

```env
DB_CONNECTION=sqlite
```

### Option A: SQLite (quickest)
Create the sqlite file if needed:

```bash
touch database/database.sqlite
```

Windows PowerShell:

```powershell
New-Item -ItemType File -Path .\database\database.sqlite -Force
```

Then run:

```bash
php artisan migrate
php artisan db:seed --class=SettingsSeeder
```

### Option B: MySQL/MariaDB
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

## 5) Install System Dependencies
The app requires external binaries for downloading and converting media:

### yt-dlp
- Install from official release or package manager.
- Verify:

```bash
yt-dlp --version
```

### ffmpeg
- Install from official release or package manager.
- Verify:

```bash
ffmpeg -version
```

If these are not recognized, add them to your system `PATH` and restart terminal.

## 6) Optional App Configuration
You can override converter behavior using env variables:

```env
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

## 7) Run the Application (Recommended Dev Flow)
Use the built-in composer dev script:

```bash
composer run dev
```

This starts:
- Laravel dev server
- Queue listener
- Log stream (`pail`)
- Vite dev server

Open the app at:
- `http://127.0.0.1:8000`

## 8) Run Services Manually (Alternative)
If you prefer separate terminals:

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

Optional Terminal 4 (live logs):
```bash
php artisan pail --timeout=0
```

## 9) Build Frontend for Production
```bash
npm run build
```

## 10) Quick Health Checks
Run these if something is not working:

```bash
php artisan about
php artisan route:list
php artisan queue:failed
php artisan optimize:clear
```

## 11) Basic Usage
1. Open the app in browser.
2. Paste YouTube URL.
3. Choose format (`mp3` or `flac`) and quality.
4. Submit and monitor progress.
5. Download completed file from the app.

## 12) Troubleshooting
### Jobs stuck / no progress
- Make sure queue worker is running (`php artisan queue:work`).
- Check failed jobs:
  ```bash
  php artisan queue:failed
  php artisan queue:retry all
  ```

### Dependency errors (yt-dlp/ffmpeg not found)
- Confirm versions with:
  ```bash
  yt-dlp --version
  ffmpeg -version
  ```
- Ensure both commands work in the same terminal running Laravel/queue worker.

### Frontend assets not updating
- Ensure Vite is running (`npm run dev`) for development.
- For production/local build mode, run `npm run build`.

### DB errors
- Recheck `.env` DB values.
- Re-run migrations:
  ```bash
  php artisan migrate:fresh --seed
  ```

## 13) Useful One-Time Setup Shortcut
Project includes a composer setup script:

```bash
composer run setup
```

It performs:
- `composer install`
- creates `.env` if missing
- `php artisan key:generate`
- `php artisan migrate --force`
- `npm install`
- `npm run build`

After this, run:

```bash
composer run dev
```

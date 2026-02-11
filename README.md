# Track AI PWA

A Progressive Web Application (PWA) for **DPWH (Department of Public Works and Highways) field engineers** to track construction project progress with offline-first capabilities and AI-powered analysis via the **Saras AI platform**.

## Overview

Track AI PWA enables field engineers working on DPWH infrastructure projects to:
- Log daily attendance with GPS verification
- Upload project documentation and photos
- Submit progress updates for AI-powered analysis
- Work seamlessly offline in areas with poor connectivity

All data is synchronized with the **Saras AI** backend system, which serves as the central data repository and provides AI-powered construction progress analysis.

## Features

### Core Functionality
- **PWA Support**: Installable on mobile devices, works like a native app
- **Offline-First Architecture**: All actions are queued when offline and automatically synced when connectivity is restored
- **GPS-Based Attendance**: Check-in/check-out with geolocation verification
- **Document Uploads**: Capture photos and upload documents (Purchase Orders, Equipment Pictures, Delivery Receipts, Meals, etc.)
- **Progress Updates**: Photo checklist with required camera angles for comprehensive documentation
- **AI Analysis**: Integration with Saras AI workflow for automated progress assessment

### Attendance Policy
The system enforces DTR (Daily Time Record) best practices:
- **Punch Pairing**: Check-in must precede check-out
- **Duplicate Prevention**: Cannot check-in twice without checking out first
- **Auto-Checkout**: Forgotten checkouts are automatically closed at end of day (10 PM default) or when detected the next day
- **Session Tracking**: Real-time display of check-in time and on-site duration

### Active Project Selection
- Set a default "active" project from the Projects page
- Active project auto-populates across Attendance, Uploads, and Progress pages
- Persisted in browser storage for convenience

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   Track AI PWA  │────▶│  Laravel API    │────▶│   Saras AI      │
│   (Vue.js/TS)   │◀────│  (Backend)      │◀────│   (External)    │
└─────────────────┘     └─────────────────┘     └─────────────────┘
        │                       │
        │                       ▼
        │               ┌─────────────────┐
        │               │   SQLite/MySQL  │
        │               │   (Local DB)    │
        │               └─────────────────┘
        ▼
┌─────────────────┐
│   IndexedDB     │
│ (Offline Queue) │
└─────────────────┘
```

### Data Flow
1. **Online Mode**: Requests go directly to Laravel API → Saras AI
2. **Offline Mode**: Requests are stored in IndexedDB, then synced when connectivity returns
3. **Local Database**: Stores user sessions, attendance sessions, audit logs, and cached project data

## Tech Stack

| Layer | Technology |
|-------|------------|
| Backend | Laravel 12, PHP 8.4 |
| Frontend | Vue 3 + TypeScript, Inertia.js v2 |
| UI Components | TailwindCSS, shadcn-vue |
| PWA | Service Worker, Web Manifest |
| Offline Storage | IndexedDB (via `idb` library) |
| Database | SQLite (default), MySQL/PostgreSQL supported |
| Authentication | Laravel Fortify + Sanctum (session-based) |

## Requirements

### System Requirements
- PHP 8.4+
- Node.js 18+
- Composer 2.x
- SQLite (for development) or MySQL 8.0+ / PostgreSQL 14+ (for production)

### External Dependencies
- **Saras AI API Access**: Required for production use. Contact your Saras administrator for:
  - API Base URL
  - API credentials/keys
  - User account provisioning

## Installation

### 1. Clone and Install Dependencies

```bash
git clone <repository-url>
cd track-ai-pwa

# Install PHP dependencies
composer install

# Install Node dependencies
npm install
```

### 2. Environment Configuration

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your configuration (see [Configuration](#configuration) section below).

### 3. Database Setup

```bash
# Create SQLite database (default)
touch database/database.sqlite

# Run migrations
php artisan migrate
```

### 4. Build Assets

```bash
# Development
npm run dev

# Production
npm run build
```

### 5. (Optional) Seed Demo Data

```bash
# Fresh database with demo users, projects, and audit logs
php artisan trackai:demo --fresh
```

Demo users created:
- `admin` (Administrator)
- `engineer01` - `engineer05` (Field Engineers)
- `inspector01` - `inspector02` (Inspectors)

All demo users have password: `password`

## Configuration

### Required Environment Variables

```env
# Application
APP_NAME="Track AI"
APP_URL=https://your-domain.test

# Sanctum (for SPA authentication)
SANCTUM_STATEFUL_DOMAINS=your-domain.test,localhost,127.0.0.1

# Saras AI Integration (REQUIRED for production)
SARAS_BASE_URL=https://api.saras.ph        # Saras API endpoint
SARAS_MODE=stub                             # 'stub' for dev, 'live' for production
SARAS_TIMEOUT=30                            # API timeout in seconds
```

### Saras API Modes

| Mode | Description | Use Case |
|------|-------------|----------|
| `stub` | Returns mock data, no external API calls | Development, testing, demos |
| `live` | Makes real API calls to Saras backend | Production deployment |

> **Note**: In `stub` mode, any username/password combination will authenticate successfully, and mock project data will be returned.

### Database Options

```env
# SQLite (default - recommended for development)
DB_CONNECTION=sqlite

# MySQL
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=track_ai
DB_USERNAME=root
DB_PASSWORD=secret

# PostgreSQL
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=track_ai
DB_USERNAME=postgres
DB_PASSWORD=secret
```

## Development

### Start Development Server

```bash
# Using Laravel's built-in server (recommended)
composer run dev

# Or run separately
php artisan serve
npm run dev
```

### Using Laravel Herd (macOS)

If using Laravel Herd, the app is automatically available at:
```
https://track-ai-pwa.test
```

### Code Formatting

```bash
# Format PHP code
vendor/bin/pint

# Format only changed files
vendor/bin/pint --dirty
```

## Testing

```bash
# Run all tests
php artisan test

# Run specific test files
php artisan test --filter=AttendanceTest
php artisan test --filter=LoginTest
php artisan test --filter=ProjectsTest

# Run with coverage
php artisan test --coverage
```

Current test suite: **60 tests, 221 assertions**

## Scheduled Commands

### Auto-Checkout Command

Automatically closes forgotten attendance sessions:

```bash
# Run at 10 PM daily (default cutoff)
php artisan trackai:auto-checkout

# Custom cutoff time
php artisan trackai:auto-checkout --cutoff=22:30

# Preview without making changes
php artisan trackai:auto-checkout --dry-run
```

Add to your server's crontab:
```cron
0 22 * * * cd /path/to/track-ai-pwa && php artisan trackai:auto-checkout >> /dev/null 2>&1
```

## PWA Installation

### Mobile (Android/iOS)
1. Open the app URL in Chrome (Android) or Safari (iOS)
2. Tap the browser menu
3. Select "Add to Home Screen" or "Install App"
4. The app will appear on your home screen

### Desktop (Chrome/Edge)
1. Visit the app URL
2. Click the install icon in the address bar
3. Confirm installation

### Offline Capabilities
- **Offline Page**: Displayed when completely offline with no cached content
- **Request Queue**: API requests made offline are stored in IndexedDB
- **Auto-Sync**: When connectivity returns, queued requests are automatically processed
- **Sync Page**: Manual control over pending sync items

## API Reference

### Authentication
All API routes require authentication via Laravel Sanctum (session cookies).

### Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| **Attendance** ||
| GET | `/api/attendance/status` | Get current attendance status for a project |
| POST | `/api/attendance/check-in` | Record check-in with GPS coordinates |
| POST | `/api/attendance/check-out` | Record check-out with GPS coordinates |
| **Projects** ||
| POST | `/api/projects/sync` | Sync projects from Saras API |
| **Uploads** ||
| POST | `/api/uploads/init` | Initialize upload entry |
| POST | `/api/uploads/file` | Upload file attachment |
| **Progress** ||
| POST | `/api/progress/submit` | Submit progress update |
| POST | `/api/progress/photo` | Upload progress photo |
| POST | `/api/progress/ai` | Trigger AI analysis workflow |
| GET | `/api/progress/ai/{id}` | Get AI workflow status |
| **Sync** ||
| POST | `/api/sync/batch` | Batch sync offline requests |

## Application Pages

| Route | Page | Description |
|-------|------|-------------|
| `/login` | Login | Username/password authentication |
| `/app/projects` | Projects | View/sync assigned projects, set active project |
| `/app/attendance` | Attendance | GPS-based check-in/check-out |
| `/app/uploads` | Uploads | Upload project documents and photos |
| `/app/progress` | Progress | Submit progress updates with photo checklist |
| `/app/sync` | Sync | View and manage offline queue |

## Limitations

### Current Limitations
1. **Saras Integration**: Full functionality requires active Saras API connection; stub mode provides limited mock data
2. **File Uploads**: Cannot queue large file uploads for offline sync; requires connectivity
3. **GPS Requirement**: Attendance features require device GPS/location services
4. **Browser Support**: Requires modern browsers with Service Worker and IndexedDB support
5. **Single Project per Session**: Attendance is tracked per-project; cannot be checked into multiple projects simultaneously

### Known Issues
- Service Worker caching may require hard refresh after updates
- iOS Safari has limited PWA capabilities compared to Android Chrome

## Troubleshooting

### "Unable to get location" error
- Ensure location services are enabled on your device
- Grant location permission to the browser/app
- Check that you're using HTTPS (required for geolocation API)

### Offline sync not working
- Check IndexedDB storage in browser dev tools
- Ensure Service Worker is registered (`navigator.serviceWorker.ready`)
- Try clearing cache and re-installing the PWA

### 401 Unauthorized errors
- Ensure `SANCTUM_STATEFUL_DOMAINS` includes your domain
- Check that session cookies are being sent (credentials: 'include')
- Verify the session hasn't expired

## Contributing

This is a proprietary project. Please contact the project maintainers for contribution guidelines.

## License

Proprietary - All rights reserved.

---

**Track AI PWA** - Empowering DPWH field engineers with modern, offline-capable project tracking.

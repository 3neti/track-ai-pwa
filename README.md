# Track AI PWA

A Progressive Web Application for DPWH field engineers to track construction project progress with offline-first capabilities and AI-powered analysis.

## Features

- **PWA Support**: Installable on mobile devices with offline functionality
- **Offline-First Queue**: All requests are queued when offline and synced automatically when back online
- **Username-based Login**: Engineers log in with their Saras username
- **Project Management**: View and sync assigned construction projects
- **Attendance Tracking**: GPS-based check-in/check-out with geolocation
- **Document Uploads**: Capture photos and upload documents (Purchase Orders, Equipment Pictures, Delivery Receipts, Meals, Documents)
- **Progress Updates**: Photo checklist with required angles for AI analysis
- **AI Analysis**: Integration with Saras AI workflow for progress assessment

## Tech Stack

- **Backend**: Laravel 12, PHP 8.4
- **Frontend**: Vue 3 + TypeScript, Inertia.js v2
- **UI**: TailwindCSS, shadcn-vue
- **PWA**: Service Worker, Web Manifest, IndexedDB (via idb)
- **Database**: SQLite (default), MySQL/PostgreSQL supported

## Installation

1. Clone the repository
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Install Node dependencies:
   ```bash
   npm install
   ```
4. Copy environment file:
   ```bash
   cp .env.example .env
   ```
5. Generate application key:
   ```bash
   php artisan key:generate
   ```
6. Run migrations:
   ```bash
   php artisan migrate
   ```
7. Build frontend assets:
   ```bash
   npm run build
   ```

## Development

Start the development server:
```bash
composer run dev
```

Or run separately:
```bash
php artisan serve
npm run dev
```

## Configuration

### Saras API Integration

The application integrates with the Saras API. Configure in `.env`:

```env
SARAS_BASE_URL=https://api.saras.example.com
SARAS_API_KEY=your-api-key
SARAS_MODE=stub  # Use 'live' for production
```

When `SARAS_MODE=stub`, the application uses mock data for development.

## Testing

Run the test suite:
```bash
php artisan test
```

Run specific tests:
```bash
php artisan test --filter=LoginTest
php artisan test --filter=ProjectsTest
php artisan test --filter=AttendanceTest
```

## PWA Features

### Installing the App

1. Visit the application in a mobile browser
2. Tap "Add to Home Screen" (or equivalent)
3. The app will be installed as a standalone application

### Offline Support

- All API requests made while offline are stored in IndexedDB
- When connectivity is restored, requests are automatically synced
- The Sync page shows pending items and allows manual sync

## API Routes

All API routes require authentication (session-based).

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/projects/sync` | Sync projects from Saras |
| POST | `/api/attendance/check-in` | Record check-in |
| POST | `/api/attendance/check-out` | Record check-out |
| POST | `/api/uploads/init` | Initialize upload entry |
| POST | `/api/uploads/file` | Upload file |
| POST | `/api/progress/submit` | Submit progress update |
| POST | `/api/progress/photo` | Upload progress photo |
| POST | `/api/progress/ai` | Trigger AI analysis |
| GET | `/api/progress/ai/{id}` | Get AI workflow status |
| POST | `/api/sync/batch` | Batch sync offline requests |

## App Routes

| Route | Page | Description |
|-------|------|-------------|
| `/app/projects` | Projects | View assigned projects |
| `/app/attendance` | Attendance | Check-in/Check-out |
| `/app/uploads` | Uploads | Upload documents |
| `/app/progress` | Progress | Submit progress updates |
| `/app/sync` | Sync | Manage offline queue |

## License

Proprietary - All rights reserved.

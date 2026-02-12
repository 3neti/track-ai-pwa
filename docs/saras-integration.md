# Track AI ↔ Saras AI Integration

## Overview

Track AI integrates with Saras AI for:
- **Attendance** - Check-in/check-out entries
- **Uploads** - File storage and tagging (TrackData)
- **Projects** - Fetching assigned projects
- **Progress** - Progress updates (feature-flagged, pending Saras API)

All Saras API calls are server-side only. Tokens are never exposed to the browser.

---

## Configuration

### Environment Variables

```env
# Saras API Configuration
SARAS_BASE_URL=https://ind-prod.sarasfinance.com/v1
SARAS_MODE=live                    # stub | live (default: live)

# All other settings have sensible defaults - no credentials needed!
# User tokens are obtained during login and stored per-user.
```

**Optional overrides** (all have defaults):
```env
SARAS_SUBPROJECT_ATTENDANCE=78053120-7685-42a2-b802-ca144b6ed010
SARAS_SUBPROJECT_TRACKDATA=efb3b7c8-f6af-479f-95e3-bd623add7c56
SARAS_PLUGIN_NAME=knowledgeRepo
SARAS_ENABLED=true
SARAS_PROGRESS_ENABLED=false
```

### Switching Modes

| Mode   | Behavior |
|--------|----------|
| `stub` | Returns deterministic mock responses. No network calls. |
| `live` | Makes actual API calls to Saras. Requires valid credentials. |

---

## Architecture

### Component Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│                         Track AI Backend                            │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────┐    ┌──────────────────┐    ┌───────────────┐  │
│  │   Controllers   │───▶│     Services     │───▶│ SarasClient   │  │
│  │                 │    │                  │    │  Interface    │  │
│  │ • Attendance    │    │ • Attendance     │    └───────┬───────┘  │
│  │ • Upload        │    │ • Upload         │            │          │
│  │ • Progress      │    │ • Progress       │            ▼          │
│  │ • Project       │    │                  │    ┌───────────────┐  │
│  └─────────────────┘    └──────────────────┘    │  StubClient   │  │
│                                                 │      OR       │  │
│                                                 │  LiveClient   │  │
│                                                 └───────┬───────┘  │
│                                                         │          │
│                                                         ▼          │
│                                                 ┌───────────────┐  │
│                                                 │ TokenManager  │  │
│                                                 │ (OAuth2 +     │  │
│                                                 │  Cache)       │  │
│                                                 └───────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────┐
                    │         Saras AI API          │
                    │  ind-prod.sarasfinance.com    │
                    └───────────────────────────────┘
```

---

## Authentication Flow

### User-Based Token Management

Track AI uses **per-user OAuth2 tokens**. When a user logs in with their Saras credentials:
1. The app authenticates against Saras API
2. The access token is stored in the user's database record
3. Subsequent API calls use the user's stored token

**No service account required!**

```
┌──────────────┐         ┌──────────────┐         ┌──────────────┐
│    User      │         │   Track AI   │         │  Saras API   │
└──────┬───────┘         └──────┬───────┘         └──────┬───────┘
       │                        │                        │
       │  Login (email/pass)    │                        │
       │───────────────────────▶│                        │
       │                        │                        │
       │                        │  POST /users/userLogin │
       │                        │  {client_id: email,    │
       │                        │   client_secret: pass} │
       │                        │───────────────────────▶│
       │                        │                        │
       │                        │  {access_token,        │
       │                        │   expires_in}          │
       │                        │◀───────────────────────│
       │                        │                        │
       │                        │  Store token in        │
       │                        │  users.saras_access_   │
       │                        │  token                 │
       │                        │                        │
       │  Login success         │                        │
       │◀───────────────────────│                        │
       │                        │                        │
       │  (Later) API calls     │                        │
       │───────────────────────▶│                        │
       │                        │  Use user's stored     │
       │                        │  token for request     │
       │                        │───────────────────────▶│
       │                        │                        │
```

### Token Storage

- **Location**: `users.saras_access_token` (encrypted column)
- **Expiry**: `users.saras_token_expires_at`
- **TTL**: `expires_in - 60 seconds` (buffer to avoid using expired tokens)
- **Invalidation**: On logout or 401/403 responses

---

## Data Flows

### 1. Upload Flow (TrackData)

The new Saras flow requires uploading the file first, then creating a process entry.

```
┌──────────┐    ┌─────────────┐    ┌──────────────┐    ┌───────────┐
│  Client  │    │ UploadCtrl  │    │UploadService │    │   Saras   │
└────┬─────┘    └──────┬──────┘    └──────┬───────┘    └─────┬─────┘
     │                 │                  │                  │
     │ POST /uploads   │                  │                  │
     │ {file, meta}    │                  │                  │
     │────────────────▶│                  │                  │
     │                 │                  │                  │
     │                 │ uploadFileToRemote()                │
     │                 │─────────────────▶│                  │
     │                 │                  │                  │
     │                 │                  │ ┌──────────────┐ │
     │                 │                  │ │ Step 1:      │ │
     │                 │                  │ │ Upload File  │ │
     │                 │                  │ └──────────────┘ │
     │                 │                  │                  │
     │                 │                  │ POST /process/   │
     │                 │                  │ knowledges/      │
     │                 │                  │ createStorage    │
     │                 │                  │ {files[]}        │
     │                 │                  │─────────────────▶│
     │                 │                  │                  │
     │                 │                  │ {files: [{id}]}  │
     │                 │                  │◀─────────────────│
     │                 │                  │                  │
     │                 │                  │ ┌──────────────┐ │
     │                 │                  │ │ Step 2:      │ │
     │                 │                  │ │CreateProcess │ │
     │                 │                  │ └──────────────┘ │
     │                 │                  │                  │
     │                 │                  │ POST /process/   │
     │                 │                  │ createProcess    │
     │                 │                  │ {subProjectId,   │
     │                 │                  │  fields: {       │
     │                 │                  │    file: <uuid>, │
     │                 │                  │    contractId,   │
     │                 │                  │    tags, ...}}   │
     │                 │                  │─────────────────▶│
     │                 │                  │                  │
     │                 │                  │ {entryId, ...}   │
     │                 │                  │◀─────────────────│
     │                 │                  │                  │
     │                 │ Update Upload    │                  │
     │                 │ record with      │                  │
     │                 │ entry_id,        │                  │
     │                 │ remote_file_id   │                  │
     │                 │◀─────────────────│                  │
     │                 │                  │                  │
     │ {success,       │                  │                  │
     │  upload_id,     │                  │                  │
     │  entry_id,      │                  │                  │
     │  file_id}       │                  │                  │
     │◀────────────────│                  │                  │
     │                 │                  │                  │
```

**Upload Record States:**
- `pending` → Created locally, not yet synced
- `uploading` → File upload in progress
- `uploaded` → Successfully synced to Saras
- `failed` → Sync failed (retryable)

---

### 2. Attendance Flow

```
┌──────────┐    ┌───────────────┐    ┌──────────────────┐    ┌───────────┐
│  Client  │    │ AttendanceCtrl│    │ AttendanceService│    │   Saras   │
└────┬─────┘    └───────┬───────┘    └────────┬─────────┘    └─────┬─────┘
     │                  │                     │                    │
     │ POST /attendance │                     │                    │
     │ /check-in        │                     │                    │
     │ {contract_id,    │                     │                    │
     │  lat, lng}       │                     │                    │
     │─────────────────▶│                     │                    │
     │                  │                     │                    │
     │                  │ checkIn()           │                    │
     │                  │────────────────────▶│                    │
     │                  │                     │                    │
     │                  │                     │ POST /process/     │
     │                  │                     │ createProcess      │
     │                  │                     │ {subProjectId:     │
     │                  │                     │  ATTENDANCE,       │
     │                  │                     │  fields: {         │
     │                  │                     │    userId,         │
     │                  │                     │    contractId,     │
     │                  │                     │    checkInTime,    │
     │                  │                     │    geoLocation,    │
     │                  │                     │    ...}}           │
     │                  │                     │───────────────────▶│
     │                  │                     │                    │
     │                  │                     │ {entryId, ...}     │
     │                  │                     │◀───────────────────│
     │                  │                     │                    │
     │                  │                     │ Create local       │
     │                  │                     │ AttendanceSession  │
     │                  │                     │                    │
     │                  │ {session, status}   │                    │
     │                  │◀────────────────────│                    │
     │                  │                     │                    │
     │ {success,        │                     │                    │
     │  entry_id,       │                     │                    │
     │  session}        │                     │                    │
     │◀─────────────────│                     │                    │
     │                  │                     │                    │
```

---

### 3. Project Sync Flow

```
┌──────────┐    ┌───────────────┐    ┌───────────────────────────┐
│  Client  │    │ ProjectCtrl   │    │         Saras API         │
└────┬─────┘    └───────┬───────┘    └─────────────┬─────────────┘
     │                  │                          │
     │ POST /projects   │                          │
     │ /sync            │                          │
     │─────────────────▶│                          │
     │                  │                          │
     │                  │ GET /process/projects/   │
     │                  │ getProjectsForUser       │
     │                  │ ?page=1&perPageCount=50  │
     │                  │─────────────────────────▶│
     │                  │                          │
     │                  │ {data: [...],            │
     │                  │  totalPages: N}          │
     │                  │◀─────────────────────────│
     │                  │                          │
     │                  │    ┌─────────────────┐   │
     │                  │    │ Loop until      │   │
     │                  │    │ page > totalPages│   │
     │                  │    └─────────────────┘   │
     │                  │                          │
     │                  │ Upsert to local          │
     │                  │ projects table           │
     │                  │                          │
     │ {success,        │                          │
     │  projects: [...]}│                          │
     │◀─────────────────│                          │
     │                  │                          │
```

---

## API Endpoints Mapping

| Track AI Service Method | Saras API Endpoint | HTTP Method |
|------------------------|-------------------|-------------|
| `TokenManager.getAccessToken()` | `/users/userLogin` | POST |
| `SarasClient.getUserDetails()` | `/users/getUserDetails` | GET |
| `SarasClient.getProjectsForUser()` | `/process/projects/getProjectsForUser` | GET |
| `SarasClient.createProcess()` | `/process/createProcess` | POST |
| `SarasClient.uploadFiles()` | `/process/knowledges/createStorage` | POST (multipart) |
| `SarasClient.executeWorkflow()` | `/process/workflows/executeWorkflow` | POST |

---

## Error Handling

### Exception Types

| Type | When Thrown | Recovery |
|------|-------------|----------|
| `saras_unavailable` | Connection failed, 5xx errors | Retry with backoff |
| `saras_auth_failed` | 401/403 responses | Invalidate token, retry once |
| `saras_validation_error` | 422 responses | Do not retry, fix payload |
| `saras_timeout` | Request timeout | Retry with backoff |
| `upload_failed` | File upload failed | Check file, retry |

### Retry Policy

- **Max Retries**: 2 (configurable via `SARAS_RETRY_ATTEMPTS`)
- **Delay**: 500ms exponential backoff (configurable via `SARAS_RETRY_DELAY_MS`)
- **Retryable**: Connection errors, 5xx responses
- **Not Retryable**: 4xx validation errors

---

## Feature Flags

### Progress Sync (Disabled)

Progress updates are feature-flagged because Saras API for progress/workflow is pending.

```php
// config/saras.php
'feature_flags' => [
    'enabled' => env('SARAS_ENABLED', true),
    'progress_enabled' => env('SARAS_PROGRESS_ENABLED', false),
],
```

When `progress_enabled = false`:
- Progress submissions save locally only
- Returns stub response: `"Progress saved locally (Saras sync pending)"`
- UI remains functional

---

## Local Development

### Using Stub Mode

Set `SARAS_MODE=stub` in `.env`. The stub client returns:

- **getUserDetails()** → Static user details
- **getProjectsForUser()** → 3 sample DPWH projects
- **createProcess()** → Random entry_id, always succeeds
- **uploadFiles()** → Random UUID for each file

### Testing

```bash
# Run all tests (uses stub by default)
php artisan test

# Run specific Saras-related tests
php artisan test --filter=Upload
php artisan test --filter=Attendance
```

---

## Deployment Checklist

1. **Environment Variables**
   - [ ] Set `SARAS_MODE=live`
   - [ ] Set `SARAS_USERNAME` and `SARAS_PASSWORD`
   - [ ] Set `SARAS_CONTRACT_ID_DEFAULT` (temporary)

2. **Cache Backend**
   - [ ] Configure Redis for token caching (recommended)
   - [ ] Verify cache is working: `php artisan tinker` → `Cache::get('saras:token')`

3. **Migration**
   - [ ] Run `php artisan migrate` (adds `saras_user_id` column)

4. **Verification**
   ```bash
   # Test token acquisition
   php artisan tinker
   >>> app(SarasTokenManagerInterface::class)->getAccessToken()
   ```

---

## Troubleshooting

### Token Issues

**Symptom**: `saras_auth_failed` errors

**Solutions**:
1. Verify credentials in `.env`
2. Check Saras API is accessible: `curl https://ind-prod.sarasfinance.com/v1/health`
3. Invalidate cached token: `Cache::forget('saras:token')`

### Upload Failures

**Symptom**: Uploads stuck in `failed` status

**Solutions**:
1. Check `uploads.last_error` column for error message
2. Verify `SARAS_SUBPROJECT_TRACKDATA` is correct
3. Check file size limits on Saras side

### Connection Timeouts

**Symptom**: `saras_timeout` or `saras_unavailable` errors

**Solutions**:
1. Increase timeout: `SARAS_TIMEOUT=60`
2. Check network connectivity to Saras
3. Verify base URL is correct

---

## Module Field Schemas

### Attendance System (`subProjectId: 78053120-7685-42a2-b802-ca144b6ed010`)

| Field | Type | Description |
|-------|------|-------------|
| `userId` | UUID | From user details |
| `contractId` | UUID | Contract reference |
| `ipAddressCheckIn` | string | IP at check-in |
| `ipAddressCheckOut` | string | IP at check-out |
| `geoLocationCheckIn` | string | Coordinates at check-in |
| `geoLocationCheckOut` | string | Coordinates at check-out |
| `date` | ISO date | Attendance date |
| `checkInTime` | ISO datetime | Check-in timestamp |
| `checkOutTime` | ISO datetime | Check-out timestamp |
| `remarks` | string | Optional notes |

### Upload & Tagging / TrackData (`subProjectId: efb3b7c8-f6af-479f-95e3-bd623add7c56`)

| Field | Type | Description |
|-------|------|-------------|
| `contractId` | UUID | Contract reference |
| `file` | UUID | File UUID from uploadFiles() |
| `tags` | array | List of tags, e.g. `["equipment", "site"]` |
| `name` | string | Display name |
| `documentType` | string | Type: Purchase Order, Equipment pictures, Delivery Receipts, Meals, etc |
| `ipAddress` | string | Client IP |
| `geoLocation` | string | Coordinates |
| `date` | ISO date | Upload date |
| `time` | ISO datetime | Upload timestamp |
| `remarks` | string | Optional notes |
| `documentId` | string | External document ID |

### Progress Updates / ProjectUpdates (`subProjectId: pending`)

| Field | Type | Description |
|-------|------|-------------|
| `contractId` | UUID | Contract reference |
| `checklist` | array | List of file UUIDs (sent as stage files) |
| `ipAddress` | string | Client IP |
| `geoLocation` | string | Coordinates |
| `date` | ISO date | Submission date |
| `time` | ISO datetime | Submission timestamp |
| `remarks` | string | Engineer comments |

---

## AI Workflow

The `executeWorkflow` method runs AI analysis on uploaded images.

```json
POST /process/workflows/executeWorkflow
{
    "workflowId": "df4b1009-8ee3-4b10-a5df-3a78b8b29739",
    "otherDetails": {},
    "payload": {}
}
```

**Default Workflow ID**: `df4b1009-8ee3-4b10-a5df-3a78b8b29739`

---

## Future Enhancements (Pending Saras)

1. **Progress Updates** - Waiting for subProjectId
2. **Stage Files** - API for attaching checklist files to workflow stages
3. **Real Contract IDs** - Replace hardcoded default with actual DPWH contracts

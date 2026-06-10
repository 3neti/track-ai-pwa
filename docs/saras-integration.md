# Track AI ↔ Saras AI Integration

## Overview

Track AI integrates with Saras AI for:
- **Attendance** - Check-in/check-out entries
- **Uploads** - File storage and tagging (TrackData)
- **Projects** - Fetching assigned modules and contracts
- **Progress** - ProjectProgress creation, stage file attachment, AI workflow execution
- **Contracts** - Listing DPWH contracts with milestones from Contract AI

All Saras API calls are server-side only. Tokens are never exposed to the browser.

**Official Saras API Documentation**: https://docs.sarasfinance.com/v1.0.0/api-reference/introduction

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
SARAS_PROJECT_ID=d3999d8f-c367-4213-a630-a528cfdd7eb6
SARAS_SUBPROJECT_ATTENDANCE=78053120-7685-42a2-b802-ca144b6ed010
SARAS_SUBPROJECT_TRACKDATA=efb3b7c8-f6af-479f-95e3-bd623add7c56
SARAS_SUBPROJECT_PROJECT_PROGRESS=794a98cf-afea-49f9-aa02-c3a430ba714f
SARAS_SUBPROJECT_CONTRACT_AI=acfdb45a-f4fd-4e25-8e52-de8ae6ff5b99
SARAS_WORKFLOW_COMPLETION_ID=d702fb25-51ae-4d7f-88fc-132d555b2f00
SARAS_WORKFLOW_COMPLETION_STAGE_KEY=stage_1779863565116_eqt6
SARAS_PLUGIN_NAME=knowledgeRepo
SARAS_ENABLED=true
SARAS_PROGRESS_ENABLED=true
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

### 2. Attendance Flow (Unified Check-in/Check-out)

Check-in creates a Saras process. Check-out **updates the same process** via `updateProcessField` instead of creating a second record. The `saras_process_id` is stored on the local `AttendanceSession`.

Check-in and check-out remarks are combined into one field:
```
check in remarks: <check-in text>
check out remarks: <check-out text>
```

```
┌──────────┐    ┌───────────────┐    ┌──────────────────┐    ┌───────────┐
│  Client  │    │ AttendanceCtrl│    │ AttendanceService│    │   Saras   │
└────┬─────┘    └───────┬───────┘    └────────┬─────────┘    └─────┬─────┘
     │                  │                     │                    │
     │ POST /check-in   │                     │                    │
     │─────────────────▶│ checkIn()           │                    │
     │                  │────────────────────▶│ createProcess      │
     │                  │                     │───────────────────▶│
     │                  │                     │ {processId}        │
     │                  │                     │◀───────────────────│
     │                  │                     │ Store processId    │
     │                  │                     │ on session         │
     │ {success}        │                     │                    │
     │◀─────────────────│                     │                    │
     │                  │                     │                    │
     │ POST /check-out  │                     │                    │
     │─────────────────▶│ checkOut()          │                    │
     │                  │────────────────────▶│ updateProcessField │
     │                  │                     │ (same processId)   │
     │                  │                     │───────────────────▶│
     │                  │                     │ {success}          │
     │                  │                     │◀───────────────────│
     │ {success}        │                     │                    │
     │◀─────────────────│                     │                    │
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

| Track AI Method | Saras API Endpoint | Method | Docs |
|----------------|-------------------|--------|------|
| `TokenManager.getAccessToken()` | `/users/userLogin` | POST | [Introduction](https://docs.sarasfinance.com/v1.0.0/api-reference/introduction) |
| `SarasClient.getUserDetails()` | `/users/getUserDetails` | GET | |
| `SarasClient.getProjectsForUser()` | `/process/projects/getProjectsForUser` | GET | |
| `SarasClient.createProcess()` | `/process/createProcess` | POST | [Create Process](https://docs.sarasfinance.com/v1.0.0/api-reference/process/create) |
| `SarasClient.getProcesses()` | `/process/getProcess` | GET | [Get Process](https://docs.sarasfinance.com/v1.0.0/api-reference/process/get) |
| `SarasClient.uploadFiles()` | `/process/knowledges/createStorage` | POST | |
| `SarasClient.updateFiles()` | `/process/updateFiles` | POST | Stage file attachment |
| `SarasClient.executeWorkflow()` | `/process/workflows/executeWorkflow` | POST | |
| `SarasClient.getWorkflowRuns()` | `/process/workflows/getWorkflowRuns` | GET | |
| `SarasClient.updateProcessField()` | `/process/updateProcessField` | POST | [Update Process](https://docs.sarasfinance.com/v1.0.0/api-reference/process/update) |

**Naming note**: Our method `getProcesses()` calls the Saras endpoint `/process/getProcess` (singular). The `getProcesses` (plural) endpoint returns 417 and is not used.

**Standard patterns** (per [Saras docs](https://docs.sarasfinance.com/v1.0.0/api-reference/introduction)):
- **Pagination**: `page` + `perPageCount` query params on all GET endpoints
- **Filters**: `filters` query param as stringified JSON, e.g. `{"subProjectId_id": "..."}`
- **Tracing**: Each response includes `traceId` in body and `x-saras-traceid` in headers

---

## Error Handling

### Exception Types

| Type | When Thrown | Recovery |
|------|-------------|----------|
| `saras_unavailable` | Connection failed, 5xx errors | Retry with backoff |
| `saras_auth_failed` | 401/403 responses | Invalidate token, retry once |
| `saras_validation_error` | 400/422 responses | Do not retry, fix payload |
| `saras_timeout` | Request timeout | Retry with backoff |
| `upload_failed` | File upload failed | Check file, retry |

**Saras error codes** (from [official docs](https://docs.sarasfinance.com/v1.0.0/api-reference/process/create)):

| Code | ID | Description |
|------|-----|-------------|
| `ERROR_PROCESS_INVALID_FIELD_VALUE` | 1232 | Invalid value for field (e.g. null instead of string) |
| `ERROR_PROCESS_UNKNOWN_FIELD_IN_REQUEST` | 1231 | Unknown field name in request |
| `ERROR_NOT_ALLOWED_TO_CREATE` | 1206 | User unauthorized |
| `ERROR_SUBPROCESS_META_NOT_FOUND` | 1202 | SubProject not found |

### Retry Policy

- **Max Retries**: 2 (configurable via `SARAS_RETRY_ATTEMPTS`)
- **Delay**: 500ms exponential backoff (configurable via `SARAS_RETRY_DELAY_MS`)
- **Retryable**: Connection errors, 5xx responses
- **Not Retryable**: 4xx validation errors

---

## Feature Flags

### Progress Sync

ProjectProgress sync is controlled by a feature flag.

```php
// config/saras.php
'feature_flags' => [
    'enabled' => env('SARAS_ENABLED', true),
    'progress_enabled' => env('SARAS_PROGRESS_ENABLED', true),
],
```

When `progress_enabled = false`:
- Progress submissions save locally only
- Returns stub response: `"Progress saved locally (Saras sync pending)"`

When `progress_enabled = true`:
- Progress reports sync to Saras via `createProcess` with `subProjectId: project_progress`
- Completion workflow can be triggered and polled

---

## Local Development

### Using Stub Mode

Set `SARAS_MODE=stub` in `.env`. The stub client returns:

- **getUserDetails()** → Static user details
- **getProjectsForUser()** → 3 sample DPWH projects
- **createProcess()** → Random entry_id, always succeeds
- **uploadFiles()** → Random UUID for each file
- **updateProcessField()** → Success response
- **getProcesses()** → Empty process list
- **updateFiles()** → Success response
- **getWorkflowRuns()** → 3 sample runs (SUCCESS, FAILED, INITIALISED)

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
| `remarks` | string | Combined check-in/check-out notes |

**Note**: All date/time fields use `Asia/Manila` timezone. Check-out updates the same record via `updateProcessField`.

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

### ProjectProgress (`subProjectId: 794a98cf-afea-49f9-aa02-c3a430ba714f`)

| Field | Type | Description |
|-------|------|-------------|
| `contractId` | process | Links to Contract AI subproject |
| `name` | string | Report display name |
| `currentMilestone` | string | Current construction milestone |
| `remarks` | string | Engineer's remarks (maps to workflow slot `engineersRemarks`) |
| `previousProgressFiles` | list of files | Baseline/old image file UUIDs |
| `currentProgressFiles` | list of files | Current progress image file UUIDs |
| `certificateOfCompletion` | file | Certificate file UUID (output) |
| `milestoneList` | list of strings | System-managed milestone list (internal) |
| `tags` | list of strings | Searchable tags |
| `ipAddress` | string | Client IP |
| `geoLocation` | string | Coordinates |
| `date` | ISO date | Submission date |
| `time` | ISO datetime | Submission timestamp |

---

## AI Workflows

### Default AI Analysis

**Workflow ID**: `df4b1009-8ee3-4b10-a5df-3a78b8b29739`

### Construction Progress Comparison

**Workflow ID**: `d702fb25-51ae-4d7f-88fc-132d555b2f00`
**Stage Key**: `stage_1779863565116_eqt6`

```json
POST /process/workflows/executeWorkflow
{
    "workflowId": "d702fb25-51ae-4d7f-88fc-132d555b2f00",
    "otherDetails": {
        "initiator": "INITIATOR_PROCESS",
        "processId": "<ProjectProgress processId>",
        "initiatorMeta": { "stageKey": "stage_1779863565116_eqt6" }
    },
    "payload": { "engineersRemarks": "<observations>" }
}
```

Workflow run states: `INITIALISED` → `WAITING` → `SUCCESS` / `FAILED`

### Polling Workflow Runs

```
GET /process/workflows/getWorkflowRuns?page=1&perPageCount=5&filters={"otherDetails__processId":"<id>","workflowId_id":"<id>"}
```

Server-side filtering via `filters` JSON query param. Returns only matching runs (`totalCount: 1`).

---

## Lifecycle Scenario Runtime

| Scenario | Mode | Description |
|----------|------|-------------|
| `basic_progress` | `default` | Submit a progress report to Saras |
| `full_lifecycle` | `full_lifecycle` | Submit → trigger workflow → poll |
| `dpwh_field_day` | `field_day` | Fetch contracts → check-in → upload → progress → stage files → workflow → poll → check-out |

```bash
php artisan trackai:lifecycle:run --list
php artisan trackai:lifecycle:run dpwh_field_day
php artisan trackai:lifecycle:run dpwh_field_day --trace
php artisan trackai:lifecycle:run dpwh_field_day --report
```

| Flag | Purpose |
|------|--------|
| `--trace` | Inline API call details (endpoint, payload, timing, Saras IDs) |
| `--report` | Full diagnostic report (flow diagram, artifacts, payloads, responses, scorecard, action items) |
| `--json` | Machine-readable JSON output |
| `--bucket=` | Custom path for progress photo uploads |

---

## Recent Changes (2026-06-10)

1. **Contracts page** — New PWA tab: contract listing, milestone display, certificate status, manual refresh from Saras
2. **Certificate detection** — Cross-references ProjectProgress records from Saras to find `certificateOfCompletion` per contract
3. **Auto-populate previous progress** — `createProgress()` auto-resolves previous files from last report's current files
4. **Unified attendance** — Check-out updates same Saras process via `updateProcessField`; remarks are combined
5. **Timezone fix** — All date/time fields use `Asia/Manila` timezone
6. **Default module** — All pages use Track AI module from config; project selector removed
7. **Smart milestone selection** — Queries Saras to pre-select next incomplete milestone
8. **Logout button** — Added to bottom navigation

## Future Enhancements

1. **Certificate download** — Saras file download API not available for tenant; file UUID exists but cannot be downloaded
2. **Certificate workflow** — `3406f390-ce85-4b32-8531-8b90c837dcb4` returns 404; pending deployment
3. **Workflow diagnostics** — `getWorkflowRuns` returns only id/state/flowState; need failure reason exposure
4. **Lifecycle report Phase 2** — Timeline view, debug package export, demo mode

# Instruction to AI Coding Agent
## Track AI: Add ProjectProgress-Based Progress Reports per Project per Milestone

## 1. Context and Rationale

Track AI is being revived for a proof of concept involving DPWH project monitoring, field progress capture, Saras AI processing, and eventual Certificate of Completion generation.

The current Track AI implementation already has working integration concepts for:

- Saras authentication
- project synchronization
- TrackData uploads
- Attendance records
- file upload to Saras storage
- Saras process creation
- workflow execution
- workflow polling

Historically, Track AI already integrated with Saras resources such as `TrackData` and `Attendance`. These resources are accessed through Saras process APIs, where each resource appears to be represented by a schema/table-like structure and addressed through the same general process endpoints, differentiated mainly by `subProjectId`, resource schema, and payload fields.

A new Saras resource called `ProjectProgress` has now been introduced. In the Saras Agentic Platform dashboard, the visual flow appears to be:

```text
ProjectProgress
    ↓
TrackData
    ↓
Attendance
```

This suggests that `ProjectProgress` is the higher-level progress/milestone resource, while `TrackData` contains supporting uploaded files/documents, and `Attendance` contains field presence records.

The purpose of this task is to add Track AI support for **progress reports per project per milestone**, using the new `ProjectProgress` resource.

---

## 2. Current Understanding

Based on the dashboard and meeting notes:

1. `ProjectProgress` is a Saras resource/schema.
2. It likely uses the same API pattern as `TrackData`.
3. It contains details about actual project progress on a per-project and per-milestone basis.
4. It may reference uploaded file UUIDs from Saras storage.
5. It appears to expose or contain cards/sections such as:
    - Metadata details
    - Progress
    - Certificate
6. It may be the resource that Track AI should use before triggering the Saras completion/certification workflow.
7. It may be the authoritative resource for determining:
    - project milestone progress
    - stage files
    - completion status
    - certificate-related data

---

## 3. Problem

Track AI currently knows how to work with `TrackData` and `Attendance`, but it does not yet know how to create, update, fetch, or display `ProjectProgress`.

Without `ProjectProgress`, Track AI cannot properly model:

- project progress per milestone
- progress evidence grouped by stage
- old vs new image sets
- manual engineer progress notes
- milestone completion state
- certificate readiness state
- Saras workflow linkage for completion/certificate generation

The immediate POC needs this bridge.

---

## 4. Goal

Implement a new `ProjectProgress` integration slice in Track AI.

The feature should allow Track AI to:

1. Fetch project progress records from Saras.
2. Create or update a `ProjectProgress` record for a project/milestone.
3. Attach or reference uploaded Saras file UUIDs.
4. Store engineer manual progress input.
5. Prepare the payload needed for Saras workflow execution.
6. Poll or inspect related workflow results.
7. Surface progress/certificate readiness in the Track AI UI.

---

## 5. Important Constraint

Do not assume a brand-new API.

Research first whether `ProjectProgress` is accessed using the same endpoint family as `TrackData`, most likely:

```text
POST /process/createProcess
GET  /process/projects/getProjectsForUser
POST /process/knowledges/createStorage
POST /process/workflows/executeWorkflow
GET  /process/workflows/getWorkflowRuns
```

The likely difference is not the endpoint, but the payload and `subProjectId`/resource identifier.

---

## 6. Research Plan: Lowest-Cost, Highest-Signal Approach

Because API experimentation is costly, do not brute-force endpoint combinations. Use this sequence instead.

### Step 1: Inspect Existing Track AI Saras Code

Search the codebase for:

```text
SARAS_SUBPROJECT_TRACKDATA
SARAS_SUBPROJECT_ATTENDANCE
createProcess
createStorage
executeWorkflow
getWorkflowRuns
TrackData
Attendance
Progress
SarasClient
LiveSarasClient
StubSarasClient
UploadService
ProgressService
AttendanceService
```

Understand the current implementation pattern before adding anything new.

Expected existing pattern:

```text
Controller
  → Service
    → SarasClientInterface
      → LiveSarasClient / StubSarasClient
```

Follow the same style. Do not introduce a parallel architecture.

---

### Step 2: Identify Existing Resource Configuration

Inspect config and `.env` usage for Saras resource IDs:

```text
SARAS_SUBPROJECT_ATTENDANCE
SARAS_SUBPROJECT_TRACKDATA
SARAS_PROGRESS_ENABLED
SARAS_WORKFLOW_ID
```

Add a new configurable value for ProjectProgress, for example:

```env
SARAS_SUBPROJECT_PROJECT_PROGRESS=<to-be-confirmed>
```

and in config:

```php
'resources' => [
    'attendance' => env('SARAS_SUBPROJECT_ATTENDANCE'),
    'trackdata' => env('SARAS_SUBPROJECT_TRACKDATA'),
    'project_progress' => env('SARAS_SUBPROJECT_PROJECT_PROGRESS'),
],
```

Do not hardcode the ProjectProgress resource ID once discovered.

---

### Step 3: Use Dashboard Schema Before API Guessing

Since access to the Saras dashboard is available, use the dashboard to inspect the `ProjectProgress` schema.

Capture the following fields:

```text
resource name
resource ID / subProjectId
field names
field types
required fields
relationship fields
file fields
stage/milestone fields
certificate fields
status fields
created/updated metadata
```

Pay special attention to fields related to:

```text
contractId
projectId
milestoneId
stageKey
oldImage
newImage
files
progress
remarks
completionStatus
certificate
certificateUrl
certificateId
```

The dashboard schema should drive the payload. Do not infer field names if the schema provides them.

---

### Step 4: Compare ProjectProgress Against TrackData

Use the existing TrackData integration as the template.

If TrackData currently does:

```php
createProcess([
    'subProjectId' => config('saras.subprojects.trackdata'),
    'fields' => [
        'contractId' => ...,
        'file' => ...,
        'tags' => ...,
        'documentType' => ...,
        'geoLocation' => ...,
        'date' => ...,
        'time' => ...,
        'remarks' => ...,
    ],
]);
```

then ProjectProgress will likely do something like:

```php
createProcess([
    'subProjectId' => config('saras.subprojects.project_progress'),
    'fields' => [
        'contractId' => ...,
        'projectId' => ...,
        'milestoneId' => ...,
        'stageKey' => ...,
        'oldImage' => ...,
        'newImage' => ...,
        'progressRemarks' => ...,
        'manualProgressInput' => ...,
        'geoLocation' => ...,
        'date' => ...,
        'time' => ...,
    ],
]);
```

But the actual field names must come from the Saras dashboard schema or a confirmed successful response.

---

## 7. API Research Checklist

Before coding the final implementation, produce a short research note answering:

### ProjectProgress Resource

```text
What is the ProjectProgress subProjectId/resource ID?
What are the required fields?
Which field links it to the project/contract?
Which field identifies milestone/stage?
Which fields store file UUIDs?
Which field stores engineer manual input?
Which field stores completion status?
Which field stores certificate data?
```

### Endpoint Confirmation

Confirm whether ProjectProgress uses:

```text
POST /process/createProcess
```

for create/update.

If update uses a different endpoint, document it.

### Fetching Records

Find the correct way to fetch ProjectProgress records:

Possible patterns:

```text
GET /process/getProcesses
GET /process/projects/getProjectsForUser
GET /process/{id}
GET /process/subproject/{subProjectId}
GET /process/createProcess-derived listing endpoint
```

Use existing TrackData fetch/list code if available.

### Workflow Linkage

Confirm how ProjectProgress process ID links to:

```text
POST /process/workflows/executeWorkflow
```

Known workflow sample:

```json
{
  "workflowId": "3406f390-ce85-4b32-8531-8b90c837dcb4",
  "otherDetails": {
    "initiator": "INITIATOR_PROCESS",
    "processId": "<ProjectProgress processId>",
    "initiatorMeta": {
      "stageKey": "1"
    }
  },
  "payload": {
    "abc": 100000,
    "implementationOffice": ["Ranchi"],
    "totalTenderWorkflows": 100000,
    "oldImage": "uuid1,uuid2",
    "newImage": "uuid3,uuid4"
  }
}
```

Confirm whether `processId` should be the `ProjectProgress` process ID or another existing project/progress process ID.

---

## 8. Proposed Implementation

### Backend Classes

Add or extend the following:

```text
app/Services/Saras/ProjectProgressService.php
app/Data/ProjectProgressData.php
app/Data/ProjectProgressPayloadData.php
app/Http/Controllers/ProjectProgressController.php
```

If the project already has equivalent naming conventions, follow the existing style.

---

### Saras Client Methods

Extend the Saras client interface with methods like:

```php
public function createProjectProgress(array $fields): array;

public function getProjectProgress(array $filters = []): array;

public function triggerCompletionWorkflow(array $payload): array;

public function getCompletionWorkflowRuns(array $filters): array;
```

If the client already has generic methods like `createProcess()` and `executeWorkflow()`, prefer wrapping them in the service instead of adding too many low-level methods.

---

### ProjectProgressService Responsibilities

The service should handle:

```text
- mapping Track AI project/milestone data to Saras ProjectProgress fields
- attaching Saras file UUIDs
- creating or updating ProjectProgress records
- preparing workflow payloads
- triggering completion workflow
- polling workflow runs
- extracting status/certificate/task data from workflow results
```

Example method outline:

```php
final class ProjectProgressService
{
    public function createOrUpdateProgress(Project $project, array $input): ProjectProgressResultData
    {
        // Upload files first if needed.
        // Resolve oldImage/newImage UUIDs.
        // Build fields from ProjectProgress schema.
        // Call Saras createProcess/update endpoint.
        // Store returned processId/entryId locally.
    }

    public function triggerCompletion(ProjectProgress $progress): WorkflowRunData
    {
        // Build executeWorkflow payload.
        // Use workflowId 3406f390-ce85-4b32-8531-8b90c837dcb4.
        // Use processId from ProjectProgress.
        // Use stageKey from milestone/stage.
    }

    public function pollCompletion(ProjectProgress $progress): CompletionStatusData
    {
        // Call getWorkflowRuns with filters.
        // Parse workflow result.
        // Return normalized completion state.
    }
}
```

---

## 9. Suggested Local Data Model

If the app does not yet persist ProjectProgress locally, add a lightweight local model/table.

Possible table:

```text
project_progress_reports
```

Suggested columns:

```text
id
project_id
contract_id
milestone_id
stage_key
saras_process_id
saras_workflow_id
saras_workflow_run_id
old_image_file_ids JSON
new_image_file_ids JSON
manual_progress_input TEXT
progress_status
completion_status
certificate_status
certificate_url nullable
certificate_file_id nullable
raw_saras_payload JSON
raw_saras_response JSON
last_synced_at
created_at
updated_at
```

Rationale:

Track AI should not depend entirely on live Saras responses during demo. It should keep enough local state to resume, display, and debug.

---

## 10. Frontend Feature

Add a progress report UI per project/milestone.

Minimum POC UI:

```text
Project
  → Milestone
    → Existing/Baseline Images
    → New Progress Images
    → Engineer Notes / Manual Progress Input
    → Submit Progress
    → Run AI Evaluation
    → View Status
    → View Certificate / Certificate Task Status
```

The frontend should not know Saras payload details. It should call Track AI backend endpoints only.

Suggested Track AI endpoints:

```text
GET  /api/projects/{project}/progress
POST /api/projects/{project}/progress
POST /api/progress/{progress}/workflow
GET  /api/progress/{progress}/workflow
GET  /api/progress/{progress}/certificate
```

---

## 11. Workflow Payload Mapper

Create a dedicated mapper. Do not scatter payload construction across controller/service code.

Example:

```php
final class ProjectProgressWorkflowPayloadMapper
{
    public function map(ProjectProgressReport $report): array
    {
        return [
            'workflowId' => config('saras.workflows.completion'),
            'otherDetails' => [
                'initiator' => 'INITIATOR_PROCESS',
                'processId' => $report->saras_process_id,
                'initiatorMeta' => [
                    'stageKey' => (string) $report->stage_key,
                ],
            ],
            'payload' => [
                'oldImage' => implode(',', $report->old_image_file_ids ?? []),
                'newImage' => implode(',', $report->new_image_file_ids ?? []),
                'manualProgressInput' => $report->manual_progress_input,
                // Add schema-confirmed fields only.
            ],
        ];
    }
}
```

Keep `abc`, `implementationOffice`, and `totalTenderWorkflows` configurable or mapped from project metadata if required. Do not leave unexplained magic constants in production code.

---

## 12. Testing Requirements

Add tests for:

```text
- ProjectProgress payload mapping
- createProcess call uses ProjectProgress subProjectId
- oldImage/newImage file UUIDs are serialized correctly
- manual engineer input is included
- executeWorkflow receives correct workflowId/processId/stageKey
- getWorkflowRuns filters are correct
- workflow result is normalized into Track AI status
- missing certificate output does not break UI
```

Use stub mode for tests.

The stub should simulate:

```text
pending workflow
completed workflow
failed workflow
completed workflow with certificate
completed workflow with DPWH task but no certificate yet
```

---

## 13. Efficient Research Strategy

Avoid repeated live API calls.

Use this order:

1. Inspect existing source code.
2. Inspect `.env` and config.
3. Inspect Saras dashboard schema for `ProjectProgress`.
4. Compare field names with `TrackData`.
5. Make one controlled create/fetch call using a test project.
6. Save the exact successful request/response as fixture.
7. Make one controlled workflow execution call.
8. Save the `getWorkflowRuns` completed response as fixture.
9. Build stubs/tests from those fixtures.
10. Only then wire the UI.

Do not repeatedly experiment against live Saras.

---

## 14. Immediate Questions to Resolve During Research

Only ask Saras or the dashboard owner if these cannot be determined from dashboard/source/API responses:

```text
1. What is the ProjectProgress subProjectId/resource ID?
2. Which ProjectProgress field links to the contract/project?
3. Which field represents milestone or stage?
4. Should oldImage/newImage be stored in ProjectProgress or only passed to executeWorkflow?
5. Should processId in executeWorkflow be the ProjectProgress process ID?
6. Where does the completed workflow expose certificate/task output?
7. What exact status means certificate-ready or DPWH-confirmation-created?
```

---

## 15. Success Criteria for POC

The feature is POC-ready when:

```text
- Track AI can create/fetch a ProjectProgress record.
- Track AI can attach old/new image UUIDs.
- Track AI can include engineer manual progress input.
- Track AI can trigger the Saras workflow using ProjectProgress processId.
- Track AI can poll workflow runs using processId + stageKey + workflowId.
- Track AI can display normalized status.
- Track AI can display certificate URL/file or DPWH certificate task status if returned.
```

---

## 16. Important Framing

Do not frame this feature as “Track AI automatically issues payment certificates.”

Frame it as:

```text
Track AI captures field progress evidence and submits it to Saras AI for progress evaluation. Saras then produces or initiates the completion certification workflow for DPWH confirmation and payment processing.
```

This is more accurate, safer, and aligned with government approval/payment realities.

---

## Implementation Status (2026-06-01)

### Research Phase — COMPLETED

All questions from Section 14 were resolved via live API probing (`saras:probe-progress` command):

| Question | Answer |
|----------|--------|
| ProjectProgress subProjectId | `794a98cf-afea-49f9-aa02-c3a430ba714f` |
| Contract/project link field | `contractId` (process type → Contract AI) |
| Milestone/stage field | `currentMilestone` (string), `milestoneList` (internal) |
| File fields | `previousProgressFiles` (list of files), `currentProgressFiles` (list of files) |
| processId in executeWorkflow | Yes, the ProjectProgress process ID |
| Workflow ID | `d702fb25-51ae-4d7f-88fc-132d555b2f00` ("Construction Progress Comparison") |
| Stage key | `stage_1779863565116_eqt6` |
| Certificate field | `certificateOfCompletion` (file type) |
| Workflow slot | `engineersRemarks` (STRING) → maps to field `remarks` via `slotsToFieldsMapper` |

Key findings:
- `GET /process/getProcess` (singular) with `filters` JSON query param is the correct endpoint for listing processes. `getProcesses` (plural) returns 417.
- `getWorkflowRuns` supports server-side filtering via `filters` JSON query param with `__` notation for nested fields (e.g., `otherDetails__processId`).
- `oldImage`/`newImage` in `executeWorkflow` payload must be comma-separated file UUIDs.
- Certificate workflow `3406f390-ce85-4b32-8531-8b90c837dcb4` returns 404 — not yet deployed to our tenant.
- Contract AI subproject (`acfdb45a-f4fd-4e25-8e52-de8ae6ff5b99`) contains 5 contracts with milestone definitions.

API probe fixtures saved in `tests/fixtures/saras/`.

### ProjectProgress Integration — COMPLETED

| Component | Status |
|-----------|--------|
| Config (`SARAS_SUBPROJECT_PROJECT_PROGRESS`, workflow IDs, stage key) | ✅ |
| `getWorkflowRuns()` in SarasClientInterface + Live/Stub | ✅ |
| `WorkflowRunDTO`, `WorkflowRunsResponse` DTOs | ✅ |
| `project_progress_reports` migration + `ProjectProgressReport` model + factory | ✅ |
| `ProjectProgressService` (create, trigger workflow, poll, list) | ✅ |
| `ProjectProgressWorkflowPayloadMapper` (with `oldImage`/`newImage` comma-separated UUIDs) | ✅ |
| `updateFiles()` in SarasClientInterface + Live/Stub (`POST /process/updateFiles`) | ✅ |
| `ProjectProgressService::attachStageFiles()` — attaches files to stage checklist | ✅ |
| `ProjectProgressController` + API routes + Inertia page route | ✅ |
| `ProjectProgress.vue` frontend page | ✅ |
| `WorkflowResponse` DTO fix for live `runId.id` extraction | ✅ |
| ISO 8601 datetime fix for `checkInTime`/`checkOutTime`/`time` fields | ✅ |
| Feature flag `SARAS_PROGRESS_ENABLED=true` | ✅ |
| 9 feature tests (26 assertions) | ✅ |

### Lifecycle Scenario Runtime — COMPLETED

Adapted from x-change's lifecycle runtime pattern:

| Component | Status |
|-----------|--------|
| Engine → Bootstrapper → Runner architecture | ✅ |
| Repository with config-driven scenario definitions | ✅ |
| `DefaultProgressScenarioRunner` (submit progress) | ✅ |
| `FullLifecycleScenarioRunner` (submit → workflow → poll) | ✅ |
| `FieldDayScenarioRunner` (fetch contracts → check-in → upload → progress → stage files → workflow → poll → check-out) | ✅ |
| Phase 0: fetch modules via `getProjectsForUser` + contracts via `getProcess` with filters | ✅ |
| `getProcesses()` in SarasClientInterface + Live/Stub (`GET /process/getProcess?filters=...`) | ✅ |
| `send_image_payload` config toggle for `oldImage`/`newImage` in workflow payload | ✅ |
| CLI auth fix (`Auth::login` + Saras token refresh for artisan context) | ✅ |
| `--trace` flag for verbose API debug output | ✅ |
| `SarasApiTracer` singleton + `TracingLifecycleOutput` decorator | ✅ |
| API Call Summary in result renderer | ✅ |
| File bucket (`storage/app/lifecycle/progress/{previous,current}/`) + `--bucket=` CLI option | ✅ |
| Server-side `getWorkflowRuns` filtering via `filters` JSON query param | ✅ |
| `--report` flag: full diagnostic report (9 sections) | ✅ |
| 13 lifecycle tests (28 assertions) | ✅ |

### Lifecycle Report (`--report`) — COMPLETED

| Section | Description |
|---------|-------------|
| Lifecycle Flow | Vertical diagram with ✓/✗/⏳ markers and Saras IDs per phase |
| Run Artifacts | All process IDs, file counts, workflow run ID at a glance |
| Modules & Contracts | Available Saras modules + contracts with milestones from Contract AI |
| Workflow Trigger | Dedicated block: workflowId, processId, stageKey, runId, state, payload keys |
| Saras Action Items | Numbered list of open items for Saras team |
| Full Payloads | Complete JSON for every POST call |
| Full Responses | Compact Saras responses (stripped of verbose user/tenant data) |
| Developer Interpretation | Track AI ✓ vs Saras ✗/? status, dynamic conclusion + next steps |
| Integration Scorecard | 7-line scored checklist with overall health % |
| Saras Trace IDs | Labeled trace IDs for Saras log search |
| Executive Summary | Slide-worthy: readiness %, primary blocker, next action |

### Live Saras Verification — COMPLETED

The `dpwh_field_day` scenario has been run successfully against live Saras (`ind-prod.sarasfinance.com`). All 6 phases produced entries visible on the Saras dashboard:

- **Attendance**: Check-in and check-out entries created
- **TrackData**: 5 real construction photos uploaded (2 previous at ~90KB, 3 current at ~50-103KB)
- **ProjectProgress**: Process created with file UUIDs, milestone, and engineer remarks
- **Stage Files**: `previousProgressImages` and `currentProgressImages` attached via `POST /process/updateFiles`
- **Workflow**: "Construction Progress Comparison" triggered with `oldImage`/`newImage` UUIDs, polled via filtered query (`totalCount: 1`)
- **Dashboard verification**: Metadetails, Progress, and Certificate sections populated

### POC Success Criteria (Section 15) — Status

| Criterion | Status |
|-----------|--------|
| Create/fetch a ProjectProgress record | ✅ Created + listed via `getProcess` with filters |
| Attach old/new image UUIDs | ✅ `previousProgressFiles` / `currentProgressFiles` |
| Include engineer manual progress input | ✅ `remarks` field → `engineersRemarks` workflow slot |
| Trigger Saras workflow using processId | ✅ `executeWorkflow` with correct workflowId + stageKey |
| Poll workflow runs | ✅ `getWorkflowRuns` with server-side `filters` param (returns `totalCount: 1` directly) |
| Display normalized status | ✅ INITIALISED → WAITING → SUCCESS/FAILED |
| Display certificate | ⏳ Pending (field exists in schema but AI workflow returns FAILED with test images) |

### Pending / Next Steps

1. **Milestone concept** — ✅ Milestones now available per contract via `getProcess`. 5 contracts found with milestone arrays (e.g., Foundation Work, Floor1–Floor4, Terrace, Interior, Painting). Next: integrate milestone selection into lifecycle scenario and frontend.
2. **Certificate workflow deployment** — Workflow `3406f390-ce85-4b32-8531-8b90c837dcb4` returns 404. Confirm with Saras devs it's deployed for tenant `681e0d5e-fcd9-46e6-b2e8-405b0d177558` (DPWH Philippines).
3. **Certificate display** — Show `certificateOfCompletion` when workflow produces it.
4. **Stage file attachment** — ✅ Implemented via `POST /process/updateFiles`. Files now attached to stage checklist with correct `processId`, `stageKey`, and `subProjectId`. Verify on Saras dashboard that `previousProgressImages`/`currentProgressImages` appear populated.
5. **Webhook/notification** — For long-running AI evaluation, implement webhook or polling notification instead of blocking poll loop.
6. **Real site photos** — Current bucket has Unsplash stock construction photos. Replace with actual DPWH site images for meaningful AI evaluation.
7. **Workflow diagnostics** — `getWorkflowRuns` returns only id/state/flowState. Need Saras to expose failure reason, node errors, workflow output, and certificate artifacts.
8. **Lifecycle report Phase 2** — Timeline view, debug package export, Saras escalation pack, demo mode (per original enhancement spec).
9. **Contract listing** — ✅ Resolved. Correct endpoint is `GET /process/getProcess` (singular) with `filters={"subProjectId_id": "acfdb45a-..."}`. Returns 5 contracts with milestone arrays. Phase 0 now displays contracts and milestones in lifecycle report.

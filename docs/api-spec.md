# Workforce Compliance & Inspection Platform – API Specification

All endpoints are JSON over HTTP. Authentication uses Laravel Sanctum bearer tokens unless otherwise noted.

Base URL examples:
- `http://localhost:{PORT}/api/...` (PORT is documented in the project README).

---

## Authentication

### POST /api/auth/login

Authenticate a user with username and password.

- Request body:
  - `username` (string, required)
  - `password` (string, required)

- Responses:
  - `200 OK`: Returns user info and access token.
  - `401 Unauthorized`: Invalid credentials or account locked.

---

## Health

### GET /api/health

Returns basic health information.

- Responses:
  - `200 OK`:
    - `status`: `"ok"`
    - `db_connected`: boolean

---

## Employers

### POST /api/employers

Create a new employer and optional qualification records.

- Auth: Employer Manager or higher.
- Body (example):
  - `name` (string, required)
  - `qualifications` (array of objects)
  - `documents` (optional, handled via multipart where applicable)

- Behavior:
  - New employer created with `status = "pending"`.
  - Decision fields are unset until reviewed.

- Responses:
  - `201 Created`: Returns employer resource.
  - `422 Unprocessable Entity`: Validation errors.

### GET /api/employers

List employers with filters.

- Auth: Compliance Reviewer or higher by default; employer managers may see their own.
- Query parameters:
  - `status` (optional)
  - `created_from`, `created_to` (optional)

- Responses:
  - `200 OK`: Paginated list.

### GET /api/employers/{id}

Fetch employer details.

- Auth: Authorized roles only.
- Responses:
  - `200 OK`
  - `404 Not Found`

### PATCH /api/employers/{id}/decision

Approve or reject an employer.

- Auth: System Administrator or Compliance Reviewer.
- Body:
  - `status` (`"approved"` or `"rejected"`, required)
  - `reason_code` (enum, required when `status = "rejected"`)

- Behavior:
  - Records decision, timestamps, and updates audit log.
  - Enforces 3-business-day rule as defined in implementation.

- Responses:
  - `200 OK`
  - `422 Unprocessable Entity` (invalid reason code)
  - `403 Forbidden` (insufficient role)

---

## Jobs

### POST /api/employers/{employerId}/jobs

Create a job posting for an employer.

- Auth: Employer Manager for that employer or higher.
- Body:
  - `title` (string, required)
  - `salary_min` (integer, required)
  - `salary_max` (integer, required, `>= salary_min`)
  - `education_level` (enum, required)
  - `street`, `city`, `state`, `zip` (required)
  - `category` (string or ID, required)
  - `tags` (array, optional)

- Behavior:
  - Validates salary and education level.
  - Enforces duplicate detection and per-24-hour rate limit.
  - Initial job status may be `"draft"` or `"published"` per rules.

- Responses:
  - `201 Created`
  - `422 Unprocessable Entity` (validation or duplicate)
  - `429 Too Many Requests` (rate limit exceeded)

### GET /api/jobs

List jobs.

- Auth: Depends on role; public results may be restricted to certain statuses.
- Query parameters: filters by employer, status, category, location.

- Responses:
  - `200 OK`

### GET /api/jobs/{id}

Get job details.

- Auth: As per role.
- Responses:
  - `200 OK`
  - `404 Not Found`

### PATCH /api/jobs/{id}

Update a job.

- Auth: Employer Manager for that employer or higher.
- Behavior:
  - Applies validation rules.
  - Supports toggling online/offline controls.
- Responses:
  - `200 OK`
  - `422 Unprocessable Entity`

---

## Result Publication

### POST /api/jobs/{id}/result-versions

Create a draft result version for a job.

- Auth: Inspector or Compliance Reviewer.
- Responses:
  - `201 Created`

### PATCH /api/result-versions/{id}/status

Transition a result version through phases.

- Auth: Configured roles only.
- Body:
  - `status` (enum: `"draft"`, `"internal"`, `"public"`)

- Behavior:
  - Each transition is audited.
  - Transition to `"public"` creates an immutable snapshot.

- Responses:
  - `200 OK`
  - `422 Unprocessable Entity` (invalid transition)

### GET /api/result-versions/{id}

Get the current representation of a result version.

- Behavior:
  - Applies field masking according to caller role and scopes.

- Responses:
  - `200 OK`
  - `404 Not Found`

### GET /api/result-versions/{id}/history

Fetch version history for a result.

- Auth: Restricted to authorized roles.
- Responses:
  - `200 OK`

---

## Objections & Tickets

### POST /api/result-versions/{id}/objections

File an objection to a published result.

- Auth: Authenticated user.
- Body:
  - `reason` (string, required)
  - Attachments (multipart; PDF/JPG/PNG, <= 10 MB each, up to 5 files)

- Behavior:
  - Only allowed within 7 calendar days of public release.
  - Creates an associated ticket in `intake` state.

- Responses:
  - `201 Created`
  - `403 Forbidden` (window expired)
  - `422 Unprocessable Entity` (validation or file constraints)

### GET /api/objections/{id}

Retrieve objection details.

- Auth: Subject party and authorized staff.
- Responses:
  - `200 OK`
  - `404 Not Found`

### PATCH /api/objections/{id}

Update objection status or add adjudication data.

- Auth: Compliance Reviewer or designated roles.
- Body:
  - `status` (enum: `"intake"`, `"review"`, `"adjudication"`)
  - `decision_reason` (optional)
  - Attachments (optional)

- Behavior:
  - Writes to objection decision audit table.
  - When reaching adjudication, syncs decision into final result version.

- Responses:
  - `200 OK`
  - `422 Unprocessable Entity`

### GET /api/tickets/{id}

Get ticket details related to an objection.

- Responses:
  - `200 OK`
  - `404 Not Found`

---

## Messaging

### GET /api/messages

List messages for the current user.

- Auth: Authenticated user.
- Query:
  - `unread_only` (optional)

- Responses:
  - `200 OK`

### POST /api/messages

Create a system-generated message.

- Auth: Internal system or privileged roles.
- Body:
  - `user_id`
  - `event_type`
  - `subject`
  - `body`

- Responses:
  - `201 Created`

### PATCH /api/messages/{id}/read

Mark a message as read.

- Responses:
  - `200 OK`

### GET /api/messages/stats

Get message statistics for the current user.

- Responses:
  - `200 OK` (includes unread count)

---

## Workflow Engine

### POST /api/workflows/definitions

Create or update a workflow definition.

- Auth: System Administrator.
- Body includes:
  - `name`
  - `version`
  - `definition` (JSON with nodes, branches, timeouts, escalation configuration)

- Responses:
  - `201 Created`

### POST /api/workflows/instances

Start a workflow instance for a subject.

- Body:
  - `workflow_definition_id`
  - `subject_type`
  - `subject_id`

- Responses:
  - `201 Created`

### PATCH /api/workflows/instances/{id}

Advance workflow, reassign, or complete tasks.

- Behavior:
  - Writes workflow action audits for every state change.

- Responses:
  - `200 OK`

---

## Offline Inspection Sync

### POST /api/inspections/assigned

Get inspections assigned to the current inspector for offline caching.

- Auth: Inspector.
- Responses:
  - `200 OK` (list of inspections and related data)

### POST /api/inspections/sync

Upload inspection updates from an offline device.

- Auth: Inspector.
- Headers:
  - `X-Device-Id` (required)
- Body:
  - `batch_id`
  - `idempotency_key`
  - `chunks` (array of chunks with size 2–5 MB each)
  - Inspection payloads with version and timestamps

- Behavior:
  - Uses idempotency key to deduplicate.
  - Applies conflict detection with version + `updated_at`.
  - Applies merge rules favoring latest confirmed adjudication fields.
  - Retries using exponential backoff up to 8 attempts; quarantines failing batches.

- Responses:
  - `202 Accepted` (batch queued or processed)
  - `409 Conflict` (unresolvable conflict)
  - `422 Unprocessable Entity`

---

## Health & Feature Flags

### GET /api/health/capacity

Return resource and capacity indicators.

- Data:
  - Disk utilization
  - DB connectivity status
  - Queue backlog metrics (if available)

- Responses:
  - `200 OK`

### GET /api/feature-flags

List feature flags and their current status.

- Auth: System Administrator or similar.

- Responses:
  - `200 OK`
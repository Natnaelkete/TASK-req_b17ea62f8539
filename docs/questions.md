# questions.md

## 1. Employer approval deadline and enforcement

Question: The Prompt states that employers must be approved or rejected within 3 business days but does not define what happens if no decision is made in that window.

Assumption: If no explicit decision is made within 3 business days, the employer remains in "pending" status and cannot post jobs; the system should surface this as an overdue item but not auto-approve or auto-reject.

Solution: Implemented a scheduled check (command/cron) that flags employers whose `status = pending` and `created_at` is older than 3 business days, exposing them via an internal API/filter for Compliance Reviewers; no automatic status change is applied.

## 2. “Business day” definition

Question: The Prompt mentions “3 business days” but does not define weekends, holidays, or time zone.

Assumption: Business days exclude Saturday and Sunday and are calculated in the system’s configured timezone (e.g., UTC), with no special handling for regional holidays.

Solution: Implemented a date utility that counts business days as Monday–Friday only in UTC when evaluating the 3-day review window; holiday handling is not included and can be added later if required.

## 3. Education level enum values

Question: The Prompt requires an educationLevel enum but does not list the allowed values explicitly.

Assumption: The intended values reflect common US education levels: `high_school`, `associate`, `bachelor`, `master`, `doctorate`, and `other`.

Solution: Implemented a DB-backed enum constraint and request validation restricting `education_level` to that set; attempts to submit other values result in a 422 response.

## 4. Category/tag constraints

Question: The Prompt states that category/tag constraints must be enforced but does not define the source of allowed categories or tags.

Assumption: Allowed categories and tags are centrally managed reference data stored in database tables, and only these may be attached to jobs.

Solution: Implemented `job_categories` and `job_tags` reference tables and many-to-many relationships; job creation and update validate category/tag IDs against these tables and reject unknown entries.

## 5. Duplicate job title normalization

Question: The Prompt requires duplicate detection using a “normalized title” but does not specify the normalization rules.

Assumption: Normalization should be case-insensitive, whitespace-insensitive, and punctuation-insensitive while preserving word order.

Solution: Implemented a normalization helper that lowercases the title, trims leading/trailing spaces, collapses internal whitespace to a single space, and strips basic punctuation; duplicates are detected using this normalized value plus ZIP and employer within a 30-day window.

## 6. Rate limiting time window

Question: The Prompt caps job publication to 20 new jobs per employer per 24 hours but does not specify whether the window is rolling or calendar-based.

Assumption: The limit is enforced using a rolling 24-hour window based on job creation timestamps.

Solution: Implemented rate checks that count jobs created for a given employer where `created_at >= now() - 24 hours`; the 21st attempt within this rolling window returns HTTP 429.

## 7. Handling objections filed after 7 days

Question: The Prompt allows objections within 7 calendar days of public release but does not specify what to do after this period.

Assumption: After the 7-day window, objections must be strictly rejected at the API layer.

Solution: Implemented a check that compares `now()` with the result version’s public `published_at`; if more than 7 days have passed, `POST /objections` responds with HTTP 403 and an explanation.

## 8. File storage path strategy for objection attachments

Question: The Prompt allows uploading files (PDF/JPG/PNG) up to 10 MB but does not specify the storage layout for these files.

Assumption: Files should be stored in the local filesystem under a structured path rooted in Laravel’s storage directory, without exposing raw filesystem paths to clients.

Solution: Implemented storage under `storage/app/objections/{objection_id}/` using Laravel’s storage API and persisted only logical storage paths in the database; download endpoints stream files via the storage driver.

## 9. Message delivery semantics (in-app only vs. external channels)

Question: The Prompt mentions “in-app messages” and subscription preferences but does not specify whether email/SMS channels are required.

Assumption: Only in-app messaging is in scope; external channels like email or SMS are out of scope for this implementation.

Solution: Implemented a message model and APIs that only deliver and track in-app notifications; subscription preferences control which in-app event types a user receives.

## 10. Workflow escalation supervisor role resolution

Question: The Prompt requires auto-escalation to a “configured supervisor role” but does not define how that supervisor is determined.

Assumption: Each workflow definition can specify a target supervisor role (e.g., `system_admin` or `compliance_reviewer`) for escalation.

Solution: Extended the workflow definition JSON to include a `supervisor_role` field; on timeout, workflow instances find users with that role and assign escalation tasks accordingly, recording the required escalation note in the audit log.

## 11. Offline inspection device identity

Question: The Prompt references deviceId and offline sync batches but does not define how device identities are established.

Assumption: Each inspector client registers a device identifier once and reuses it for all subsequent sync operations.

Solution: Implemented a `device_id` field in `sessions` and `offline_sync_batches`; APIs require a valid authenticated user and a provided `device_id` header, which is validated and stored when creating sessions and batches.

## 12. Conflict resolution rule granularity

Question: The Prompt states that server-side merge rules must favor the latest confirmed adjudication fields but does not define field-by-field vs. record-level precedence.

Assumption: When conflicts occur, adjudication-related fields are resolved field-by-field using the latest confirmed adjudication timestamp, while non-adjudication fields use the newest `updated_at`.

Solution: Implemented merge logic that, on conflict, compares timestamps for adjudication fields separately from other fields and applies the appropriate latest values; all merges are logged via audit tables.

## 13. 99th percentile latency measurement scope

Question: The Prompt targets 99th percentile API latency under 300 ms at 50 concurrent users but does not specify the measurement method or test mix.

Assumption: The latency target applies to typical read/write API operations under synthetic load in a single-node environment.

Solution: Implemented lightweight performance tests and logging of request durations; documentation explains how to run a basic load test scenario hitting key endpoints and how to verify approximate 99th percentile latency from the structured logs.

## 14. Feature/gray-release toggles structure

Question: The Prompt requires configuration/gray-release toggles implemented as local DB-backed flags but does not define the schema or keys.

Assumption: A generic feature flag table with a key, description, enabled flag, and optional JSON metadata is sufficient.

Solution: Implemented a `feature_flags` table (`key`, `enabled`, `metadata`) and a small caching service that reads these flags from DB; critical behaviors (e.g., new masking logic or offline sync behaviors) check these flags at runtime.
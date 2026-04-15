# Workforce Compliance & Inspection Platform – Design

## Overview

The system is a Laravel-based, API-only Workforce Compliance & Inspection Operations Platform running entirely within a single-node Docker environment backed by MySQL. It manages employers, jobs, inspections, result publication, objections, messaging, and an approval workflow engine with strict audit trails and offline sync support.

## High-Level Architecture

- **API Layer (Laravel)**: Exposes resource-scoped JSON APIs for Authentication, Employers, Jobs, Inspections, Result Publication, Objections/Tickets, Messaging, Content Items, and Workflow Engine.
- **Domain Layer**: Encapsulates business logic around employer onboarding, job validation, workflow transitions, objection handling, offline sync, and masking/encryption policies.
- **Persistence Layer (MySQL)**: Stores all primary entities and immutable audit tables, plus configuration and feature flags.
- **Background Processing (Laravel Queues)**: Handles asynchronous tasks such as offline sync batch processing, workflow timeouts and escalations, message dispatching, and retention/cleanup.
- **Docker Environment**: Single-node deployment with containers for the Laravel app and MySQL, orchestrated via `docker compose up`.

## Key Data Structures

- **Core Entities**: `users`, `sessions`, `employers`, `employer_qualifications`, `jobs`, `inspections`, `offline_sync_batches`, `result_versions`, `objections`, `tickets`, `messages`, `content_items`, `workflow_definitions`, `workflow_instances`.
- **Audit Tables**: Separate append-only tables for workflow actions and result/objection decisions, storing `actor_id`, `role`, `timestamp`, `prior_value_hash`, `new_value_hash`, and a human-readable `reason`.
- **Configuration Tables**: Feature flags and reference data (e.g., education levels, job categories, tags) used for validation and gray-release behavior.

## Security and Access Control

- **Authentication**: Username/password with strict complexity rules, minimum length of 12 characters, and lockout after 10 failed attempts for 15 minutes. Laravel Sanctum provides token-based API authentication.
- **Authorization**: Role-based access control with roles such as System Administrator, Compliance Reviewer, Employer Manager, Inspector, and General User. Middleware and policies enforce access at the API layer.
- **PII Handling**: Sensitive fields (e.g., last name, phone) are encrypted at rest using Laravel’s encryption facilities. Response serializers apply masking rules based on the requesting user’s role and configured visibility scopes.

## Key Workflows

- **Employer Onboarding**: Employers are created as “pending” and must be approved or rejected by authorized roles within three business days. Decisions require reason codes and are audited.
- **Job Posting**: Employers create jobs with validated salary ranges, education levels, and structured US addresses. Duplicate detection and per-employer rate limiting guard against abuse.
- **Result Publication and Objections**: Inspections lead to result versions that move through draft, internal, and public states, with masking applied for public views. Objections can be filed within a fixed time window and progress through intake, review, and adjudication, with final decisions synced into result versions.
- **Messaging & Reminders**: System-generated messages and reminders (e.g., check-in reminders, change notices) are persisted with per-user read status and retention windows.
- **Approval Workflow Engine**: Workflows are defined as versioned definitions with conditional branches, parallel approvals, reassignments, and timeouts including auto-escalation to supervisor roles. All transitions are audited.
- **Offline Inspection Sync**: Inspectors operate offline with local caches and later sync changes using resumable, chunked uploads with idempotency keys. Conflict resolution rules favor the latest confirmed adjudication fields and log decisions in audit tables.

## Observability and Non-Functional Concerns

- **Logging**: Structured JSON logs capture correlation IDs, request metadata, and performance metrics. Audit logs capture business-level decisions.
- **Health and Capacity Checks**: Health endpoints and background checks monitor DB connectivity and disk utilization, triggering alerts when disk free space drops below the configured threshold.
- **Performance**: The system is designed to keep typical API requests under the specified latency targets at the expected concurrency level, aided by appropriate indexing and efficient query patterns.

## Deployment and Operations

- **Docker**: `docker-compose.yml` defines the Laravel application and MySQL services. The application container runs migrations on startup and exposes the API on a documented port.
- **Configuration**: `.env` variables control environment-specific settings, all of which are documented in `.env.example` and the project README. No external services or host-specific dependencies are used.
- **Testing**: `run_tests.sh` executes the PHPUnit test suite with coverage. Each major module (models, controllers, workflows, sync) is covered to at least 90% for meaningful lines of code.
# Workforce Compliance & Inspection Operations Platform

A Laravel-based API platform for workforce compliance management, field inspections, result publication, objection workflows, and governed communications.

## Quick Start

### Prerequisites
- Docker and Docker Compose installed

### Run the Application

```bash
docker compose up
```

This starts:
- **App** (PHP-FPM): Laravel API backend
- **Nginx**: Web server on port **8000**
- **MySQL 8.0**: Database on port **3308** (external)
- **Queue Worker**: Processes background jobs via database driver

### Verify It's Running

```bash
curl http://localhost:8000/api/health
```

Expected response:
```json
{
  "status": "healthy",
  "timestamp": "2026-04-15T...",
  "checks": {
    "database": { "status": "ok", "error": null },
    "disk": { "free_percent": 93.6, "alert": false }
  }
}
```

### Run Tests

From the project root (host machine) just run:

```bash
bash run_tests.sh
```

The script auto-detects its environment:
- If run on the host, it starts the `app` container (if not already up) and executes the suite inside it via `docker compose exec`.
- If run inside the container, it runs the tests directly against the in-memory SQLite DB.

The single command executes all three suites (`unit_tests/`, `API_tests/`, and `tests/` with coverage) and prints a consolidated PASS/FAIL summary.

### Stop the Application

```bash
docker compose down
```

To also remove volumes (database data):
```bash
docker compose down -v
```

## Test Credentials

After first run, a default admin is seeded:
- **Email**: `admin@workforce.local`
- **Password**: `Admin@12345678`

## API Endpoints

### Health & Auth
| Method | Path            | Auth | Description              |
|--------|-----------------|------|--------------------------|
| GET    | `/api/health`   | No   | Health & DB check        |
| POST   | `/api/register` | No   | Register new user        |
| POST   | `/api/login`    | No   | Login, get token         |
| POST   | `/api/logout`   | Yes  | Revoke current token     |
| GET    | `/api/me`       | Yes  | Get current user profile |

### Employers
| Method     | Path                          | Auth | Description                     |
|------------|-------------------------------|------|---------------------------------|
| POST       | `/api/employers`              | Yes  | Create employer (pending)       |
| GET        | `/api/employers`              | Yes  | List employers (filterable)     |
| GET        | `/api/employers/{id}`         | Yes  | Get employer detail             |
| PUT/PATCH  | `/api/employers/{id}`         | Yes  | Update employer                 |
| POST       | `/api/employers/{id}/review`  | Yes* | Approve/reject employer         |

*Requires `system_admin` or `compliance_reviewer` role

### Jobs
| Method     | Path                               | Auth | Description                    |
|------------|-------------------------------------|------|-------------------------------|
| POST       | `/api/employers/{employerId}/jobs` | Yes  | Create job (rate limited)      |
| GET        | `/api/jobs`                        | Yes  | List jobs (filterable)         |
| GET        | `/api/jobs/{id}`                   | Yes  | Get job detail                 |
| PUT/PATCH  | `/api/jobs/{id}`                   | Yes  | Update job                     |

### Result Versions
| Method | Path                              | Auth | Description                     |
|--------|-----------------------------------|------|---------------------------------|
| POST   | `/api/jobs/{id}/result-versions`  | Yes  | Create draft result             |
| PUT    | `/api/result-versions/{id}/status`| Yes* | Transition (draft->internal->public) |
| GET    | `/api/result-versions/{id}`       | Yes  | Get result version              |
| GET    | `/api/result-versions/{id}/history`| Yes | Version history + audit trail   |

### Objections & Tickets
| Method     | Path                                    | Auth | Description                |
|------------|------------------------------------------|------|---------------------------|
| POST       | `/api/result-versions/{id}/objections`  | Yes  | File objection (7-day window) |
| PUT/PATCH  | `/api/objections/{id}`                  | Yes* | Update objection status    |
| GET        | `/api/objections/{id}`                  | Yes  | Get objection detail       |
| GET        | `/api/tickets/{id}`                     | Yes  | Get ticket detail          |

### Messages
| Method | Path                      | Auth | Description              |
|--------|---------------------------|------|--------------------------|
| POST   | `/api/messages`           | Yes* | Create system message    |
| GET    | `/api/messages`           | Yes  | List user messages       |
| PUT    | `/api/messages/{id}/read` | Yes  | Mark message as read     |
| GET    | `/api/messages/stats`     | Yes  | Unread/total stats       |

### Inspections
| Method     | Path                         | Auth | Description              |
|------------|------------------------------|------|--------------------------|
| POST       | `/api/inspections`           | Yes  | Schedule inspection      |
| GET        | `/api/inspections`           | Yes  | List inspections         |
| GET        | `/api/inspections/{id}`      | Yes  | Get inspection detail    |
| PUT/PATCH  | `/api/inspections/{id}`      | Yes  | Update inspection        |
| GET        | `/api/inspections/assigned/me`| Yes | Get assigned inspections |

### Offline Sync
| Method | Path                                      | Auth | Description                |
|--------|-------------------------------------------|------|----------------------------|
| POST   | `/api/offline-sync/upload`                | Yes  | Upload sync batch          |
| GET    | `/api/offline-sync/status/{idempotencyKey}` | Yes | Check sync batch status |

### Workflows
| Method | Path                                       | Auth | Description                |
|--------|--------------------------------------------|------|----------------------------|
| POST   | `/api/workflow-definitions`                | Yes* | Create workflow definition |
| GET    | `/api/workflow-definitions`                | Yes* | List workflow definitions  |
| POST   | `/api/workflow-instances`                  | Yes* | Create workflow instance   |
| PUT    | `/api/workflow-instances/{id}/advance`     | Yes* | Advance workflow           |
| GET    | `/api/workflow-instances/{id}`             | Yes* | Get instance + audit trail |

*Requires `system_admin` or `compliance_reviewer` role

## Roles

| Role                  | Description                                    |
|-----------------------|------------------------------------------------|
| `system_admin`        | Full access to all features                    |
| `compliance_reviewer` | Review employers, publish results, manage workflows |
| `employer_manager`    | Manage employer records and jobs               |
| `inspector`           | Conduct inspections, create result drafts      |
| `general_user`        | Basic access, can file objections              |

## Environment Variables

See `.env.example` for all configuration. Key variables:

| Variable           | Default                | Description            |
|--------------------|------------------------|------------------------|
| `APP_PORT`         | `8000`                 | External HTTP port     |
| `DB_DATABASE`      | `workforce_compliance` | MySQL database name    |
| `DB_USERNAME`      | `wc_user`              | MySQL username         |
| `DB_PASSWORD`      | `wc_password`          | MySQL password         |
| `DB_EXTERNAL_PORT` | `3308`                 | External MySQL port    |

## Architecture

- **Backend**: Laravel 11 (API-only, no frontend assets)
- **Auth**: Laravel Sanctum (token-based)
- **Queue**: Database driver (no Redis/external broker)
- **Storage**: Local filesystem only (no cloud)
- **Testing**: PHPUnit with SQLite in-memory for tests
- **PII**: Encrypted at rest via `Crypt`, masked in responses based on role
- **Audit**: Immutable append-only audit tables for all workflow/result/objection decisions
- **Offline Sync**: Idempotent batch uploads with conflict resolution and retry/quarantine

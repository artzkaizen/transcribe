# BBB Recording Ingest & Transcription Service

## Overview

A Hono (Bun) application that automatically ingests recordings from BigBlueButton, transcribes them using the Whisper CLI, and stores transcripts in SQLite. Inngest handles cron scheduling and workflow orchestration.

## Stack

- **Runtime:** Bun
- **Framework:** Hono
- **Workflow/Cron:** Inngest (every 4 hours)
- **Transcription:** Whisper CLI via `Bun.spawn()`
- **Database:** SQLite via `bun:sqlite` + Drizzle ORM
- **XML Parsing:** `fast-xml-parser` (BBB API returns XML)
- **Validation:** Zod + `@hono/zod-validator` middleware
- **CORS:** `https://*.b-trend.digital`
- **Env Validation:** Varlock
- **Deployment:** Docker Compose (app + Inngest dev server)

## Project Structure

```
hono/
├── src/
│   ├── index.ts              # Hono app + Inngest serve endpoint
│   ├── inngest/
│   │   ├── client.ts          # Inngest client instance
│   │   └── functions/
│   │       └── ingest.ts      # Cron + event-triggered functions
│   ├── lib/
│   │   ├── bbb.ts             # BBB API client (getRecordings, checksum auth)
│   │   ├── whisper.ts          # Whisper CLI wrapper (Bun.spawn)
│   │   └── db.ts              # SQLite setup + Drizzle schema + queries
│   └── env.ts                 # Varlock env schema + typed ENV
├── .env.schema                # Varlock schema
├── .env                       # Actual secrets (gitignored)
├── docker-compose.yaml        # App + Inngest dev server
├── Dockerfile
├── package.json
└── tsconfig.json
```

## Database Schema (Drizzle ORM)

### recordings

| Column       | Type    | Notes                                                    |
|-------------|---------|----------------------------------------------------------|
| id          | TEXT PK | BBB recordID                                             |
| meeting_id  | TEXT    | NOT NULL                                                 |
| meeting_name| TEXT    |                                                          |
| start_time  | INTEGER | Unix timestamp                                           |
| end_time    | INTEGER | Unix timestamp                                           |
| video_url   | TEXT    | NOT NULL — skip recordings without a playback URL        |
| status      | TEXT    | enum: pending, downloading, transcribing, completed, failed |
| error       | TEXT    |                                                          |
| created_at  | INTEGER | DEFAULT unixepoch()                                      |
| updated_at  | INTEGER | DEFAULT unixepoch()                                      |

### transcripts

| Column           | Type    | Notes                          |
|-----------------|---------|--------------------------------|
| id              | INTEGER | PK AUTOINCREMENT               |
| recording_id    | TEXT    | FK → recordings.id, UNIQUE     |
| text            | TEXT    | Full transcript text           |
| vtt             | TEXT    | WebVTT formatted subtitles     |
| language        | TEXT    |                                |
| duration_seconds| REAL    | Audio duration                 |
| model           | TEXT    | DEFAULT 'base'                 |
| created_at      | INTEGER | DEFAULT unixepoch()            |

## Inngest Workflow

Two functions:

### 1. `bbb/ingest.sweep` (cron: every 4 hours)

1. **step.run("fetch-recordings")** — Call BBB `getRecordings` API with `state=published`, diff against `recordings` table, insert new ones as `status: "pending"`, return list of new recording IDs.
2. **step.sendEvent()** — Dispatch one `bbb/ingest.process` event per new recording for parallel processing.

### 2. `bbb/ingest.process` (event: `bbb/ingest.process`)

Per-recording pipeline, each step independently retriable:

1. **step.run("download-{id}")** — Download video from BBB playback URL to `/tmp/bbb-ingest/`. Update status → `downloading`.
2. **step.run("transcribe-{id}")** — Run `whisper` CLI via `Bun.spawn()` on downloaded file. Parse VTT output + extract plain text. Update status → `transcribing`.
3. **step.run("store-{id}")** — Upsert transcript into `transcripts` table (ON CONFLICT recording_id). Update recording status → `completed`. Delete temp video file.

**Configuration:**

```typescript
{
  id: "bbb/ingest.process",
  retries: 2,
  concurrency: { limit: 2 },  // Limit parallel transcriptions (CPU-intensive)
}
```

**Failure handling:** Declare an `onFailure` handler that updates the recording status to `failed` with the error message. All update queries must explicitly set `updated_at`.

**Temp file cleanup:** On each sweep, delete files in `/tmp/bbb-ingest/` older than 24 hours to handle orphaned files from crashed runs.

## BBB API Client

### Authentication

Every BBB API call requires a SHA-1 checksum: `SHA1(apiCallName + queryString + BBB_SHARED_SECRET)`.

### getRecordings

- **Endpoint:** `GET {BBB_BASE_URL}/api/getRecordings`
- **Parameters:** `state=published` (only get published recordings)
- **Response:** XML with `<recording>` elements containing `recordID`, `meetingID`, `name`, `startTime`, `endTime`, and `playback.format.url`
- **Parsing:** `fast-xml-parser` to convert XML → JS objects

## Whisper CLI Wrapper

```typescript
Bun.spawn([
  "whisper", audioPath,
  "--model", model ?? "base",
  "--language", "auto",
  "--output_format", "vtt",
  "--output_dir", tmpDir
]);
```

- Outputs VTT format
- Plain text extracted from VTT for the `text` column
- Temp files cleaned up after storing to SQLite

## Environment Variables (.env.schema)

```
# @sensitive @required @type=string
BBB_SHARED_SECRET=

# @required @type=url
BBB_BASE_URL=

# @sensitive
INNGEST_EVENT_KEY=

# @sensitive
INNGEST_SIGNING_KEY=

# @type=string @default=data/app.db
DATABASE_PATH=data/app.db

# @type=enum(tiny,base,small,medium,large) @default=base
WHISPER_MODEL=base
```

## Docker Compose

```yaml
services:
  app:
    build: .
    ports:
      - "3000:3000"
    volumes:
      - ./data:/app/data
      - ./.env:/app/.env
    environment:
      - INNGEST_DEV=1
      - INNGEST_DEV_SERVER_URL=http://inngest:8288

  inngest:
    image: inngest/inngest:latest
    ports:
      - "8288:8288"
    environment:
      - INNGEST_DEV=1
```

No Redis required. SQLite persists via mounted volume.

## Dockerfile

The app image must include Whisper CLI dependencies:

```dockerfile
FROM oven/bun:latest

# Install Python, ffmpeg, and Whisper CLI
RUN apt-get update && apt-get install -y python3 python3-pip ffmpeg \
    && pip3 install --break-system-packages openai-whisper \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Pre-download Whisper model to avoid first-run delay
RUN whisper --model base /dev/null 2>/dev/null || true

WORKDIR /app
COPY package.json bun.lock ./
RUN bun install --frozen-lockfile
COPY . .

EXPOSE 3000
CMD ["bun", "run", "src/index.ts"]
```

Note: Image will be larger (~2GB+) due to Whisper + Python + model weights.

## Middleware

- **CORS:** Allow `https://*.b-trend.digital` via Hono's `cors()` middleware with origin pattern matching
- **JSON:** All responses default to `application/json`
- **Zod Validator:** `@hono/zod-validator` on all endpoints for input validation

## API Endpoints (Hono)

All endpoints return JSON and are validated with Zod schemas.

- `GET /` — Health check
- `ALL /api/inngest` — Inngest serve handler (GET, PUT, POST)
- `GET /transcripts` — List all transcripts. Query params validated: `{ limit?: number, offset?: number, status?: enum }`
- `GET /transcripts/:id` — Get transcript by recording ID. Param validated: `{ id: string }`

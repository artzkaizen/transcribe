# BBB Ingest & Transcription Service Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Hono (Bun) service that sweeps BigBlueButton for new recordings every 4 hours via Inngest, transcribes them with Whisper CLI, and stores transcripts in SQLite.

**Architecture:** Single Hono app serves API endpoints and an Inngest handler. Inngest orchestrates a cron sweep function that fans out per-recording process functions (download → transcribe → store). SQLite via Drizzle ORM for persistence. Varlock for env validation.

**Tech Stack:** Bun, Hono, Inngest, Drizzle ORM (bun:sqlite), Zod, @hono/zod-validator, fast-xml-parser, Varlock, Whisper CLI, Docker

**Spec:** `docs/superpowers/specs/2026-03-18-bbb-ingest-transcription-design.md`

---

## File Structure

| File | Responsibility |
|------|----------------|
| `src/index.ts` | Hono app: middleware (CORS, JSON), API routes, Inngest serve endpoint |
| `src/env.ts` | Varlock env loading + typed ENV export |
| `src/lib/db.ts` | Drizzle schema (recordings, transcripts tables) + db instance |
| `src/lib/bbb.ts` | BBB API client: checksum auth, getRecordings, XML parsing |
| `src/lib/whisper.ts` | Whisper CLI wrapper: Bun.spawn, VTT parsing |
| `src/inngest/client.ts` | Inngest client instance |
| `src/inngest/functions/ingest.ts` | Two Inngest functions: sweep (cron) + process (event) |
| `.env.schema` | Varlock schema definition |
| `docker-compose.yaml` | App + Inngest dev server |
| `Dockerfile` | Bun + Python + ffmpeg + Whisper CLI |
| `drizzle.config.ts` | Drizzle Kit config for migrations |
| `src/__tests__/bbb.test.ts` | BBB client tests |
| `src/__tests__/whisper.test.ts` | Whisper wrapper tests |
| `src/__tests__/api.test.ts` | API endpoint tests |

---

### Task 1: Project Setup — Dependencies & Config

**Files:**
- Modify: `package.json`
- Modify: `tsconfig.json`
- Create: `.env.schema`
- Create: `src/env.ts`
- Create: `.gitignore`

- [ ] **Step 1: Install dependencies**

```bash
cd /Users/jeffersonchukwuka/Developer/b-trend/FastApi/hono
bun add inngest @hono/zod-validator zod drizzle-orm fast-xml-parser varlock
bun add -d drizzle-kit
```

- [ ] **Step 2: Update tsconfig.json**

Add module resolution and path config needed for Drizzle and Bun:

```json
{
  "compilerOptions": {
    "strict": true,
    "jsx": "react-jsx",
    "jsxImportSource": "hono/jsx",
    "target": "ESNext",
    "module": "ESNext",
    "moduleResolution": "bundler",
    "types": ["bun-types"],
    "esModuleInterop": true,
    "skipLibCheck": true
  }
}
```

- [ ] **Step 3: Create .gitignore**

```
node_modules/
data/
.env
*.db
/tmp/
```

- [ ] **Step 4: Create .env.schema**

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

- [ ] **Step 5: Create src/env.ts**

```typescript
import "varlock/auto-load";
import { ENV } from "varlock/env";

export { ENV };
```

- [ ] **Step 6: Create a sample .env for local dev**

```
BBB_SHARED_SECRET=your-secret-here
BBB_BASE_URL=https://vroom.b-trend.digital/bigbluebutton
DATABASE_PATH=data/app.db
WHISPER_MODEL=base
```

- [ ] **Step 7: Verify setup compiles**

Run: `bun build --no-bundle src/env.ts --outdir /tmp/test-build`
Expected: No TypeScript errors

- [ ] **Step 8: Commit**

```bash
git add package.json bun.lock tsconfig.json .gitignore .env.schema src/env.ts
git commit -m "feat: project setup with dependencies and varlock env config"
```

---

### Task 2: Database Schema with Drizzle ORM

**Files:**
- Create: `src/lib/db.ts`
- Create: `drizzle.config.ts`

- [ ] **Step 1: Create src/lib/db.ts with Drizzle schema and db instance**

```typescript
import { sqliteTable, text, integer, real } from "drizzle-orm/sqlite-core";
import { drizzle } from "drizzle-orm/bun-sqlite";
import { Database } from "bun:sqlite";
import { sql } from "drizzle-orm";

// --- Schema ---

const statusEnum = [
  "pending",
  "downloading",
  "transcribing",
  "completed",
  "failed",
] as const;

export const recordings = sqliteTable("recordings", {
  id: text("id").primaryKey(), // BBB recordID
  meetingId: text("meeting_id").notNull(),
  meetingName: text("meeting_name"),
  startTime: integer("start_time"),
  endTime: integer("end_time"),
  videoUrl: text("video_url").notNull(),
  status: text("status", { enum: statusEnum }).default("pending"),
  error: text("error"),
  createdAt: integer("created_at").default(sql`(unixepoch())`),
  updatedAt: integer("updated_at").default(sql`(unixepoch())`),
});

export const transcripts = sqliteTable("transcripts", {
  id: integer("id").primaryKey({ autoIncrement: true }),
  recordingId: text("recording_id")
    .notNull()
    .unique()
    .references(() => recordings.id),
  text: text("text").notNull(),
  vtt: text("vtt"),
  language: text("language"),
  durationSeconds: real("duration_seconds"),
  model: text("model").default("base"),
  createdAt: integer("created_at").default(sql`(unixepoch())`),
});

// --- DB Instance ---

export type Recording = typeof recordings.$inferSelect;
export type Transcript = typeof transcripts.$inferSelect;

export function createDb(path?: string) {
  const dbPath = path ?? process.env.DATABASE_PATH ?? "data/app.db";
  const { mkdirSync } = require("fs");
  const { dirname } = require("path");
  mkdirSync(dirname(dbPath), { recursive: true });
  const sqlite = new Database(dbPath, { create: true });
  sqlite.exec("PRAGMA journal_mode = WAL;");
  return drizzle(sqlite, { schema: { recordings, transcripts } });
}

export const db = createDb();
```

- [ ] **Step 2: Create drizzle.config.ts**

```typescript
import { defineConfig } from "drizzle-kit";

export default defineConfig({
  schema: "./src/lib/db.ts",
  out: "./drizzle",
  dialect: "sqlite",
  dbCredentials: {
    url: `file:${process.env.DATABASE_PATH ?? "data/app.db"}`,
  },
});
```

- [ ] **Step 3: Generate initial migration**

Run: `mkdir -p data && bun drizzle-kit generate`
Expected: Migration files created in `drizzle/` directory

- [ ] **Step 4: Push schema to local dev DB**

Run: `bun drizzle-kit push`
Expected: Tables created successfully

- [ ] **Step 5: Verify schema**

Run: `bun -e "import { db, recordings } from './src/lib/db'; import { sql } from 'drizzle-orm'; console.log(db.select().from(recordings).all());"`
Expected: Empty array `[]`

- [ ] **Step 6: Commit**

```bash
git add src/lib/db.ts drizzle.config.ts drizzle/
git commit -m "feat: add Drizzle ORM schema for recordings and transcripts"
```

---

### Task 3: BBB API Client

**Files:**
- Create: `src/lib/bbb.ts`
- Create: `src/__tests__/bbb.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// src/__tests__/bbb.test.ts
import { describe, it, expect } from "bun:test";
import { buildChecksum, buildApiUrl, parseRecordingsXml } from "../lib/bbb";

describe("buildChecksum", () => {
  it("creates SHA-1 checksum from call name, query string, and secret", () => {
    const result = buildChecksum("getRecordings", "state=published", "my-secret");
    // SHA1("getRecordingsstate=publishedmy-secret")
    expect(result).toBeString();
    expect(result).toHaveLength(40); // SHA-1 hex length
  });
});

describe("buildApiUrl", () => {
  it("builds full API URL with checksum", () => {
    const url = buildApiUrl(
      "https://vroom.b-trend.digital/bigbluebutton",
      "getRecordings",
      "state=published",
      "my-secret"
    );
    expect(url).toStartWith(
      "https://vroom.b-trend.digital/bigbluebutton/api/getRecordings?state=published&checksum="
    );
  });
});

describe("parseRecordingsXml", () => {
  it("parses BBB getRecordings XML response into recording objects", () => {
    const xml = `
      <response>
        <returncode>SUCCESS</returncode>
        <recordings>
          <recording>
            <recordID>rec-abc123</recordID>
            <meetingID>meet-1</meetingID>
            <name>Test Meeting</name>
            <startTime>1700000000000</startTime>
            <endTime>1700003600000</endTime>
            <playback>
              <format>
                <type>presentation</type>
                <url>https://vroom.b-trend.digital/playback/presentation/2.3/rec-abc123</url>
              </format>
            </playback>
          </recording>
        </recordings>
      </response>
    `;
    const result = parseRecordingsXml(xml);
    expect(result).toHaveLength(1);
    expect(result[0].recordId).toBe("rec-abc123");
    expect(result[0].meetingId).toBe("meet-1");
    expect(result[0].meetingName).toBe("Test Meeting");
    expect(result[0].videoUrl).toContain("rec-abc123");
  });

  it("returns empty array when no recordings", () => {
    const xml = `
      <response>
        <returncode>SUCCESS</returncode>
        <recordings></recordings>
      </response>
    `;
    const result = parseRecordingsXml(xml);
    expect(result).toEqual([]);
  });

  it("filters out recordings without a playback URL", () => {
    const xml = `
      <response>
        <returncode>SUCCESS</returncode>
        <recordings>
          <recording>
            <recordID>rec-no-video</recordID>
            <meetingID>meet-2</meetingID>
            <name>No Video</name>
            <startTime>1700000000000</startTime>
            <endTime>1700003600000</endTime>
            <playback></playback>
          </recording>
        </recordings>
      </response>
    `;
    const result = parseRecordingsXml(xml);
    expect(result).toEqual([]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bun test src/__tests__/bbb.test.ts`
Expected: FAIL — modules not found

- [ ] **Step 3: Write the BBB client implementation**

```typescript
// src/lib/bbb.ts
import { XMLParser } from "fast-xml-parser";

export interface BbbRecording {
  recordId: string;
  meetingId: string;
  meetingName: string;
  startTime: number;
  endTime: number;
  videoUrl: string;
}

export function buildChecksum(
  apiCall: string,
  queryString: string,
  secret: string
): string {
  const hasher = new Bun.CryptoHasher("sha1");
  hasher.update(`${apiCall}${queryString}${secret}`);
  return hasher.digest("hex");
}

export function buildApiUrl(
  baseUrl: string,
  apiCall: string,
  queryString: string,
  secret: string
): string {
  const checksum = buildChecksum(apiCall, queryString, secret);
  return `${baseUrl}/api/${apiCall}?${queryString}&checksum=${checksum}`;
}

export function parseRecordingsXml(xml: string): BbbRecording[] {
  const parser = new XMLParser({
    ignoreAttributes: false,
    isArray: (name) => name === "recording" || name === "format",
  });
  const parsed = parser.parse(xml);
  const response = parsed.response;

  if (response.returncode !== "SUCCESS") {
    throw new Error(`BBB API error: ${response.message ?? "unknown"}`);
  }

  const recordingsNode = response.recordings?.recording;
  if (!recordingsNode || !Array.isArray(recordingsNode)) {
    return [];
  }

  return recordingsNode
    .map((rec: any) => {
      const formats = rec.playback?.format;
      const format = Array.isArray(formats) ? formats[0] : formats;
      const videoUrl = format?.url;

      if (!videoUrl) return null;

      return {
        recordId: String(rec.recordID),
        meetingId: String(rec.meetingID),
        meetingName: String(rec.name ?? ""),
        startTime: Math.floor(Number(rec.startTime) / 1000), // BBB uses ms, we store seconds
        endTime: Math.floor(Number(rec.endTime) / 1000),
        videoUrl: String(videoUrl),
      };
    })
    .filter((r: BbbRecording | null): r is BbbRecording => r !== null);
}

export async function fetchRecordings(
  baseUrl: string,
  secret: string
): Promise<BbbRecording[]> {
  const queryString = "state=published";
  const url = buildApiUrl(baseUrl, "getRecordings", queryString, secret);
  const response = await fetch(url);

  if (!response.ok) {
    throw new Error(`BBB API request failed: ${response.status}`);
  }

  const xml = await response.text();
  return parseRecordingsXml(xml);
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bun test src/__tests__/bbb.test.ts`
Expected: All 3 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/lib/bbb.ts src/__tests__/bbb.test.ts
git commit -m "feat: add BBB API client with checksum auth and XML parsing"
```

---

### Task 4: Whisper CLI Wrapper

**Files:**
- Create: `src/lib/whisper.ts`
- Create: `src/__tests__/whisper.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
// src/__tests__/whisper.test.ts
import { describe, it, expect } from "bun:test";
import { parseVtt, extractTextFromVtt } from "../lib/whisper";

const sampleVtt = `WEBVTT

1
00:00:00.000 --> 00:00:05.000
Hello, this is a test.

2
00:00:05.000 --> 00:00:10.000
This is the second segment.
`;

describe("parseVtt", () => {
  it("parses VTT content into segments", () => {
    const segments = parseVtt(sampleVtt);
    expect(segments).toHaveLength(2);
    expect(segments[0].text).toBe("Hello, this is a test.");
    expect(segments[0].start).toBe("00:00:00.000");
    expect(segments[0].end).toBe("00:00:05.000");
  });
});

describe("extractTextFromVtt", () => {
  it("extracts plain text from VTT content", () => {
    const text = extractTextFromVtt(sampleVtt);
    expect(text).toBe("Hello, this is a test. This is the second segment.");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `bun test src/__tests__/whisper.test.ts`
Expected: FAIL — modules not found

- [ ] **Step 3: Write the Whisper wrapper implementation**

```typescript
// src/lib/whisper.ts
import { mkdirSync, existsSync, readFileSync, unlinkSync, readdirSync, statSync } from "fs";
import { join, basename } from "path";

export const TEMP_DIR = "/tmp/bbb-ingest";

export interface VttSegment {
  start: string;
  end: string;
  text: string;
}

export interface TranscriptionResult {
  text: string;
  vtt: string;
  language: string;
}

export function parseVtt(vttContent: string): VttSegment[] {
  const segments: VttSegment[] = [];
  const lines = vttContent.split("\n");
  let i = 0;

  while (i < lines.length) {
    const line = lines[i].trim();
    // Look for timestamp lines: "00:00:00.000 --> 00:00:05.000"
    if (line.includes("-->")) {
      const [start, end] = line.split("-->").map((s) => s.trim());
      const textLines: string[] = [];
      i++;
      while (i < lines.length && lines[i].trim() !== "" && !lines[i].trim().match(/^\d+$/)) {
        textLines.push(lines[i].trim());
        i++;
      }
      if (textLines.length > 0) {
        segments.push({ start, end, text: textLines.join(" ") });
      }
    } else {
      i++;
    }
  }

  return segments;
}

export function extractTextFromVtt(vttContent: string): string {
  const segments = parseVtt(vttContent);
  return segments.map((s) => s.text).join(" ");
}

export async function transcribe(
  audioPath: string,
  model: string = "base"
): Promise<TranscriptionResult> {
  mkdirSync(TEMP_DIR, { recursive: true });

  const proc = Bun.spawn(
    [
      "whisper",
      audioPath,
      "--model", model,
      "--language", "auto",
      "--output_format", "vtt",
      "--output_dir", TEMP_DIR,
    ],
    {
      stdout: "pipe",
      stderr: "pipe",
    }
  );

  // Consume stdout/stderr BEFORE awaiting exit to avoid stream draining issues
  const stdoutPromise = new Response(proc.stdout).text();
  const stderrPromise = new Response(proc.stderr).text();
  const exitCode = await proc.exited;
  const stdout = await stdoutPromise;
  const stderr = await stderrPromise;

  if (exitCode !== 0) {
    throw new Error(`Whisper CLI failed (exit ${exitCode}): ${stderr}`);
  }

  // Whisper outputs <filename>.vtt in the output dir
  const inputName = basename(audioPath).replace(/\.[^.]+$/, "");
  const vttPath = join(TEMP_DIR, `${inputName}.vtt`);

  if (!existsSync(vttPath)) {
    throw new Error(`Whisper did not produce VTT output at ${vttPath}`);
  }

  const vtt = readFileSync(vttPath, "utf-8");
  const text = extractTextFromVtt(vtt);

  // Parse detected language from whisper stdout
  const langMatch = stdout.match(/Detected language: (\w+)/);
  const language = langMatch?.[1] ?? "unknown";

  // Clean up VTT temp file
  unlinkSync(vttPath);

  return { text, vtt, language };
}

export function cleanupOldTempFiles(maxAgeHours: number = 24): void {
  if (!existsSync(TEMP_DIR)) return;

  const now = Date.now();
  const maxAgeMs = maxAgeHours * 60 * 60 * 1000;

  for (const file of readdirSync(TEMP_DIR)) {
    const filePath = join(TEMP_DIR, file);
    const stat = statSync(filePath);
    if (now - stat.mtimeMs > maxAgeMs) {
      unlinkSync(filePath);
    }
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bun test src/__tests__/whisper.test.ts`
Expected: All 2 tests PASS

- [ ] **Step 5: Commit**

```bash
git add src/lib/whisper.ts src/__tests__/whisper.test.ts
git commit -m "feat: add Whisper CLI wrapper with VTT parsing"
```

---

### Task 5: Inngest Client & Functions

**Files:**
- Create: `src/inngest/client.ts`
- Create: `src/inngest/functions/ingest.ts`

- [ ] **Step 1: Create the Inngest client**

```typescript
// src/inngest/client.ts
import { Inngest } from "inngest";

export const inngest = new Inngest({ id: "bbb-ingest" });
```

- [ ] **Step 2: Create the Inngest functions**

```typescript
// src/inngest/functions/ingest.ts
import { inngest } from "../client";
import { db, recordings, transcripts } from "../../lib/db";
import { fetchRecordings } from "../../lib/bbb";
import { transcribe, cleanupOldTempFiles, TEMP_DIR } from "../../lib/whisper";
import { eq } from "drizzle-orm";
import { mkdirSync } from "fs";
import { join } from "path";
import { ENV } from "../../env";

const BBB_BASE_URL = ENV.BBB_BASE_URL;
const BBB_SHARED_SECRET = ENV.BBB_SHARED_SECRET;
const WHISPER_MODEL = ENV.WHISPER_MODEL ?? "base";

// --- Function 1: Sweep BBB for new recordings (cron) ---

export const sweep = inngest.createFunction(
  { id: "bbb/ingest.sweep" },
  { cron: "0 */4 * * *" }, // Every 4 hours
  async ({ step }) => {
    // Clean up orphaned temp files
    await step.run("cleanup-temp-files", async () => {
      cleanupOldTempFiles(24);
    });

    // Fetch recordings from BBB and diff against DB
    const newRecordings = await step.run("fetch-recordings", async () => {
      const bbbRecordings = await fetchRecordings(BBB_BASE_URL, BBB_SHARED_SECRET);

      // Get existing recording IDs
      const existing = db
        .select({ id: recordings.id })
        .from(recordings)
        .all();
      const existingIds = new Set(existing.map((r) => r.id));

      // Filter to new recordings only
      const newOnes = bbbRecordings.filter((r) => !existingIds.has(r.recordId));

      // Insert new recordings as pending
      for (const rec of newOnes) {
        db.insert(recordings)
          .values({
            id: rec.recordId,
            meetingId: rec.meetingId,
            meetingName: rec.meetingName,
            startTime: rec.startTime,
            endTime: rec.endTime,
            videoUrl: rec.videoUrl,
            status: "pending",
          })
          .run();
      }

      return newOnes.map((r) => ({
        recordingId: r.recordId,
        videoUrl: r.videoUrl,
      }));
    });

    // Fan out: dispatch one process event per new recording
    if (newRecordings.length > 0) {
      await step.sendEvent(
        "dispatch-process-events",
        newRecordings.map((r) => ({
          name: "bbb/ingest.process" as const,
          data: { recordingId: r.recordingId, videoUrl: r.videoUrl },
        }))
      );
    }

    return { newRecordings: newRecordings.length };
  }
);

// --- Function 2: Process a single recording (event-triggered) ---

export const processRecording = inngest.createFunction(
  {
    id: "bbb/ingest.process",
    retries: 2,
    concurrency: { limit: 2 },
    onFailure: async ({ event, error }) => {
      const recordingId = event.data.recordingId;
      const errorMessage = error.message;
      db.update(recordings)
        .set({
          status: "failed",
          error: errorMessage,
          updatedAt: Math.floor(Date.now() / 1000),
        })
        .where(eq(recordings.id, recordingId))
        .run();
    },
  },
  { event: "bbb/ingest.process" },
  async ({ event, step }) => {
    const { recordingId, videoUrl } = event.data;

    // Step 1: Download video
    const audioPath = await step.run(`download-${recordingId}`, async () => {
      db.update(recordings)
        .set({ status: "downloading", updatedAt: Math.floor(Date.now() / 1000) })
        .where(eq(recordings.id, recordingId))
        .run();

      mkdirSync(TEMP_DIR, { recursive: true });
      const outputPath = join(TEMP_DIR, `${recordingId}.mp4`);

      const response = await fetch(videoUrl);
      if (!response.ok) {
        throw new Error(`Download failed: ${response.status}`);
      }

      await Bun.write(outputPath, response);
      return outputPath;
    });

    // Step 2: Transcribe
    const result = await step.run(`transcribe-${recordingId}`, async () => {
      db.update(recordings)
        .set({ status: "transcribing", updatedAt: Math.floor(Date.now() / 1000) })
        .where(eq(recordings.id, recordingId))
        .run();

      return await transcribe(audioPath, WHISPER_MODEL);
    });

    // Step 3: Store transcript and clean up
    await step.run(`store-${recordingId}`, async () => {
      // Upsert transcript
      db.insert(transcripts)
        .values({
          recordingId,
          text: result.text,
          vtt: result.vtt,
          language: result.language,
          model: WHISPER_MODEL,
        })
        .onConflictDoUpdate({
          target: transcripts.recordingId,
          set: {
            text: result.text,
            vtt: result.vtt,
            language: result.language,
            model: WHISPER_MODEL,
            createdAt: Math.floor(Date.now() / 1000),
          },
        })
        .run();

      // Mark recording as completed
      db.update(recordings)
        .set({ status: "completed", updatedAt: Math.floor(Date.now() / 1000) })
        .where(eq(recordings.id, recordingId))
        .run();

      // Delete temp video file
      try {
        const { unlinkSync } = await import("fs");
        unlinkSync(audioPath);
      } catch {
        // Ignore cleanup errors
      }
    });

    return { recordingId, status: "completed" };
  }
);
```

- [ ] **Step 3: Verify it compiles**

Run: `bun build --no-bundle src/inngest/functions/ingest.ts --outdir /tmp/test-build`
Expected: No TypeScript errors

- [ ] **Step 4: Commit**

```bash
git add src/inngest/
git commit -m "feat: add Inngest sweep (cron) and process functions"
```

---

### Task 6: Hono App — Routes, Middleware, Inngest Serve

**Files:**
- Modify: `src/index.ts`
- Create: `src/__tests__/api.test.ts`

- [ ] **Step 1: Write failing API tests**

```typescript
// src/__tests__/api.test.ts
import { describe, it, expect, beforeAll } from "bun:test";
import app from "../index";

describe("GET /", () => {
  it("returns health check JSON", async () => {
    const res = await app.request("/");
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(body).toHaveProperty("status", "ok");
  });
});

describe("GET /transcripts", () => {
  it("returns JSON array", async () => {
    const res = await app.request("/transcripts");
    expect(res.status).toBe(200);
    const body = await res.json();
    expect(Array.isArray(body.data)).toBe(true);
  });

  it("validates query params", async () => {
    const res = await app.request("/transcripts?limit=notanumber");
    expect(res.status).toBe(400);
  });
});

describe("GET /transcripts/:id", () => {
  it("returns 404 for unknown id", async () => {
    const res = await app.request("/transcripts/nonexistent");
    expect(res.status).toBe(404);
  });
});

describe("CORS", () => {
  it("allows requests from *.b-trend.digital", async () => {
    const res = await app.request("/", {
      headers: { Origin: "https://app.b-trend.digital" },
    });
    expect(res.headers.get("Access-Control-Allow-Origin")).toBe(
      "https://app.b-trend.digital"
    );
  });

  it("rejects requests from other origins", async () => {
    const res = await app.request("/", {
      headers: { Origin: "https://evil.com" },
    });
    expect(res.headers.get("Access-Control-Allow-Origin")).toBeNull();
  });
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `bun test src/__tests__/api.test.ts`
Expected: FAIL

- [ ] **Step 3: Implement src/index.ts**

```typescript
// src/index.ts
import { Hono } from "hono";
import { cors } from "hono/cors";
import { z } from "zod";
import { zValidator } from "@hono/zod-validator";
import { serve } from "inngest/hono";
import { inngest } from "./inngest/client";
import { sweep, processRecording } from "./inngest/functions/ingest";
import { db, recordings, transcripts } from "./lib/db";
import { eq } from "drizzle-orm";

const app = new Hono();

// --- Middleware ---

app.use(
  "*",
  cors({
    origin: (origin) => {
      if (origin && /^https:\/\/.*\.b-trend\.digital$/.test(origin)) {
        return origin;
      }
      return null;
    },
  })
);

// --- Routes ---

app.get("/", (c) => {
  return c.json({ status: "ok" });
});

const transcriptsQuerySchema = z.object({
  limit: z.coerce.number().int().min(1).max(100).optional().default(20),
  offset: z.coerce.number().int().min(0).optional().default(0),
  status: z
    .enum(["pending", "downloading", "transcribing", "completed", "failed"])
    .optional(),
});

app.get(
  "/transcripts",
  zValidator("query", transcriptsQuerySchema),
  (c) => {
    const { limit, offset, status } = c.req.valid("query");

    let query = db.select().from(recordings);

    if (status) {
      query = query.where(eq(recordings.status, status)) as typeof query;
    }

    const data = query.limit(limit).offset(offset).all();

    return c.json({ data });
  }
);

const transcriptParamSchema = z.object({
  id: z.string().min(1),
});

app.get(
  "/transcripts/:id",
  zValidator("param", transcriptParamSchema),
  (c) => {
    const { id } = c.req.valid("param");

    const recording = db
      .select()
      .from(recordings)
      .where(eq(recordings.id, id))
      .get();

    if (!recording) {
      return c.json({ error: "Not found" }, 404);
    }

    const transcript = db
      .select()
      .from(transcripts)
      .where(eq(transcripts.recordingId, id))
      .get();

    return c.json({ recording, transcript: transcript ?? null });
  }
);

// --- Inngest ---

app.on(
  ["GET", "PUT", "POST"],
  "/api/inngest",
  serve({
    client: inngest,
    functions: [sweep, processRecording],
  })
);

export default app;
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `bun test src/__tests__/api.test.ts`
Expected: All tests PASS

- [ ] **Step 5: Verify dev server starts**

Run: `bun run dev` (then Ctrl+C after confirming it starts)
Expected: Server starts on port 3000

- [ ] **Step 6: Commit**

```bash
git add src/index.ts src/__tests__/api.test.ts
git commit -m "feat: add Hono routes with CORS, Zod validation, and Inngest serve"
```

---

### Task 7: Docker Compose & Dockerfile

**Files:**
- Create: `docker-compose.yaml`
- Create: `Dockerfile`

- [ ] **Step 1: Create Dockerfile**

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

# Create data directory for SQLite
RUN mkdir -p data

EXPOSE 3000
CMD ["bun", "run", "src/index.ts"]
```

- [ ] **Step 2: Create docker-compose.yaml**

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

- [ ] **Step 3: Create .dockerignore**

```
node_modules/
data/
.env
*.db
.git/
```

- [ ] **Step 4: Verify Docker build**

Run: `docker compose build app`
Expected: Build completes successfully

- [ ] **Step 5: Commit**

```bash
git add Dockerfile docker-compose.yaml .dockerignore
git commit -m "feat: add Docker Compose with Inngest dev server and Whisper CLI"
```

---

### Task 8: Integration Smoke Test

**Files:**
- No new files — manual verification

- [ ] **Step 1: Run all unit tests**

Run: `bun test`
Expected: All tests PASS

- [ ] **Step 2: Start the stack locally**

Run: `docker compose up -d`
Expected: Both `app` and `inngest` containers start

- [ ] **Step 3: Verify health check**

Run: `curl http://localhost:3000/`
Expected: `{"status":"ok"}`

- [ ] **Step 4: Verify Inngest dev UI**

Open: `http://localhost:8288`
Expected: Inngest dev UI shows `bbb/ingest.sweep` and `bbb/ingest.process` functions registered

- [ ] **Step 5: Verify transcripts endpoint**

Run: `curl http://localhost:3000/transcripts`
Expected: `{"data":[]}`

- [ ] **Step 6: Tear down**

Run: `docker compose down`

- [ ] **Step 7: Final commit**

```bash
git status
git add Dockerfile docker-compose.yaml .dockerignore
git commit -m "chore: verify integration smoke test passes"
```

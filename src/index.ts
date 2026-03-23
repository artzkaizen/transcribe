import { Hono } from "hono";
import { cors } from "hono/cors";
import * as z  from "zod";
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
      .get() ?? null;

    return c.json({ recording, transcript });
  }
);

// --- Inngest ---

const inngestHandler = serve({
  client: inngest,
  functions: [sweep, processRecording],
});

app.use("/api/inngest", async (c) => {
  return inngestHandler(c);
});

export default app;

import { sqliteTable, text, integer, real } from "drizzle-orm/sqlite-core";
import { drizzle } from "drizzle-orm/bun-sqlite";
import { Database } from "bun:sqlite";
import { sql } from "drizzle-orm";
import { mkdirSync } from "fs";
import { dirname } from "path";

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
  mkdirSync(dirname(dbPath), { recursive: true });
  const sqlite = new Database(dbPath, { create: true });
  sqlite.exec("PRAGMA journal_mode = WAL;");
  return drizzle(sqlite, { schema: { recordings, transcripts } });
}

export const db = createDb();

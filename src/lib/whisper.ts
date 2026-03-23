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

  const inputName = basename(audioPath).replace(/\.[^.]+$/, "");
  const vttPath = join(TEMP_DIR, `${inputName}.vtt`);

  if (!existsSync(vttPath)) {
    throw new Error(`Whisper did not produce VTT output at ${vttPath}`);
  }

  const vtt = readFileSync(vttPath, "utf-8");
  const text = extractTextFromVtt(vtt);

  const langMatch = stdout.match(/Detected language: (\w+)/);
  const language = langMatch?.[1] ?? "unknown";

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

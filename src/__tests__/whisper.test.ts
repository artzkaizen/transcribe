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

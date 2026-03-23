import { describe, it, expect } from "bun:test";
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

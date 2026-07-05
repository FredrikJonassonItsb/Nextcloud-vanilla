/**
 * OpenRouter client: embeddings + metadata extraction.
 *
 * Vendored behavior from OB1 (server/index.ts + kubernetes-deployment/index.ts):
 *  - POST {base}/embeddings   {model, input}                -> data[0].embedding
 *  - POST {base}/chat/completions  json_object mode with the verbatim OB1
 *    metadata-extraction system prompt.
 *
 * When no API key is configured the store runs in PENDING mode (CONTRACTS
 * section 5): thoughts are saved with embedding=NULL + metadata.embed_pending
 * and the backfill worker retries every 5 minutes.
 */

// Verbatim OB1 system prompt — do not edit (protocol-compatible metadata shape).
const EXTRACTION_PROMPT = `Extract metadata from the user's captured thought. Return JSON with:
- "people": array of people mentioned (empty if none)
- "action_items": array of implied to-dos (empty if none)
- "dates_mentioned": array of dates YYYY-MM-DD (empty if none)
- "topics": array of 1-3 short topic tags (always at least one)
- "type": one of "observation", "task", "idea", "reference", "person_note"
Only extract what's explicitly there.`;

export const FALLBACK_METADATA = Object.freeze({ topics: ["uncategorized"], type: "observation" });

export function createEmbedder({
  apiKey,
  base = "https://openrouter.ai/api/v1",
  embedModel = "openai/text-embedding-3-small",
  chatModel = "openai/gpt-4o-mini",
  fetchImpl = fetch,
  timeoutMs = 30000,
}) {
  const hasKey = () => Boolean(apiKey && apiKey.trim());

  async function post(path, payload) {
    const r = await fetchImpl(`${base}${path}`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${apiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(timeoutMs),
    });
    if (!r.ok) {
      const msg = await r.text().catch(() => "");
      throw new Error(`OpenRouter ${path} failed: ${r.status} ${msg.slice(0, 300)}`);
    }
    return r.json();
  }

  /** @returns {Promise<number[]>} 1536-dim embedding. Throws when unavailable. */
  async function embed(text) {
    if (!hasKey()) throw new Error("OPENROUTER_API_KEY is not configured");
    const d = await post("/embeddings", { model: embedModel, input: text });
    const emb = d?.data?.[0]?.embedding;
    if (!Array.isArray(emb) || emb.length === 0) {
      throw new Error("OpenRouter /embeddings returned no embedding");
    }
    return emb;
  }

  /**
   * LLM metadata extraction (OB1-verbatim prompt). Returns the parsed object,
   * or the OB1 fallback {topics:["uncategorized"], type:"observation"} on
   * parse failure. Throws on transport failure (caller decides pending mode).
   */
  async function extractMetadata(text) {
    if (!hasKey()) throw new Error("OPENROUTER_API_KEY is not configured");
    const d = await post("/chat/completions", {
      model: chatModel,
      response_format: { type: "json_object" },
      messages: [
        { role: "system", content: EXTRACTION_PROMPT },
        { role: "user", content: text },
      ],
    });
    try {
      const parsed = JSON.parse(d.choices[0].message.content);
      if (parsed && typeof parsed === "object" && !Array.isArray(parsed)) return parsed;
      return { ...FALLBACK_METADATA };
    } catch {
      return { ...FALLBACK_METADATA };
    }
  }

  return { hasKey, embed, extractMetadata };
}

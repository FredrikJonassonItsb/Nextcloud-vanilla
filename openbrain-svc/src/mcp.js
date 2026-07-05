/**
 * MCP server: the six OB1 tools, vendored from OB1's self-hosted server
 * (integrations/kubernetes-deployment/index.ts). Tool NAMES, input schemas,
 * annotations and text output FORMATS are preserved verbatim so that
 * Nate-compatible clients (Claude Desktop connectors, ChatGPT search/fetch,
 * the SvelteKit dashboard's text parsers) keep working:
 *
 *   search, fetch                       (ChatGPT read-only compatibility pair)
 *   search_thoughts, list_thoughts, thought_stats, capture_thought
 *
 * ITSL deltas (all additive, per CONTRACTS §5 / BYGGPLAN §2.2):
 *   - capture_thought writes through the fingerprint-dedupe upsert path.
 *   - capture_thought is guarded by the write firewall (422-semantics as a
 *     tool error with the Swedish refusal text).
 *   - Searches degrade to ILIKE with an explicit warning when embeddings are
 *     unavailable (pending mode).
 */

import { McpServer } from "@modelcontextprotocol/sdk/server/mcp.js";
import { z } from "zod";
import { FirewallError } from "./firewall.js";

function thoughtTitle(content, createdAt) {
  const firstLine = content.replace(/\s+/g, " ").trim().slice(0, 80);
  const datePrefix = createdAt ? new Date(createdAt).toLocaleDateString() : "Open Brain";
  return firstLine ? `${datePrefix} - ${firstLine}` : `${datePrefix} thought`;
}

function errorResult(err) {
  return {
    content: [{ type: "text", text: `Error: ${err instanceof Error ? err.message : String(err)}` }],
    isError: true,
  };
}

export function buildMcpServer({ store, citationBaseUrl }) {
  const thoughtUrl = (id) => `${citationBaseUrl.replace(/\/$/, "")}/${id}`;

  const server = new McpServer({ name: "open-brain", version: "1.0.0" });

  // ChatGPT compatibility: restricted connector surfaces, company knowledge,
  // and deep research look for exact read-only `search` and `fetch` shapes.
  server.registerTool(
    "search",
    {
      title: "Search Open Brain",
      description:
        "Search Open Brain memories by meaning. Use this read-only compatibility tool when ChatGPT needs search/fetch-style access to stored thoughts.",
      annotations: { readOnlyHint: true },
      inputSchema: {
        query: z.string().describe("The search query to run against Open Brain thoughts"),
      },
    },
    async ({ query }) => {
      try {
        const { rows, fallback, warning } = await store.searchThoughts({
          query,
          limit: 10,
          // 0.25, not OB1's English-centric 0.5/0.7: Swedish content with
          // text-embedding-3-small lands relevant matches at cosine ~0.2–0.4
          // (verified on dev15). A higher gate returns nothing for real queries.
          threshold: 0.25,
        });
        const results = rows.map((t) => ({
          id: String(t.id),
          title: thoughtTitle(t.content, t.created_at),
          url: thoughtUrl(t.id),
        }));
        const payload = fallback ? { results, warning } : { results };
        return { content: [{ type: "text", text: JSON.stringify(payload) }] };
      } catch (err) {
        return errorResult(err);
      }
    }
  );

  server.registerTool(
    "fetch",
    {
      title: "Fetch Open Brain Thought",
      description:
        "Fetch one Open Brain thought by ID after using search. Use this read-only compatibility tool to retrieve the full text and metadata for citation.",
      annotations: { readOnlyHint: true },
      inputSchema: {
        id: z.string().describe("The Open Brain thought ID returned by the search tool"),
      },
    },
    async ({ id }) => {
      try {
        const thought = await store.fetchById(id);
        if (!thought) {
          return {
            content: [{ type: "text", text: `No thought found for ID ${id}.` }],
            isError: true,
          };
        }
        const document = {
          id: String(thought.id),
          title: thoughtTitle(thought.content, thought.created_at),
          text: thought.content,
          url: thoughtUrl(thought.id),
          metadata: {
            ...thought.metadata,
            created_at: thought.created_at,
            updated_at: thought.updated_at,
          },
        };
        return { content: [{ type: "text", text: JSON.stringify(document) }] };
      } catch (err) {
        return errorResult(err);
      }
    }
  );

  // Tool 1: Semantic Search
  server.registerTool(
    "search_thoughts",
    {
      title: "Search Thoughts",
      description:
        "Search captured thoughts by meaning. Use this when the user asks about a topic, person, or idea they've previously captured.",
      annotations: { readOnlyHint: true },
      inputSchema: {
        query: z.string().describe("What to search for"),
        limit: z.number().optional().default(10),
        threshold: z.number().optional().default(0.25),
      },
    },
    async ({ query, limit, threshold }) => {
      try {
        const { rows, fallback, warning } = await store.searchThoughts({ query, limit, threshold });
        if (!rows.length) {
          return {
            content: [{ type: "text", text: `No thoughts found matching "${query}".` }],
          };
        }
        const results = rows.map((t, i) => {
          const m = t.metadata || {};
          const match =
            t.similarity == null ? "text match" : `${(t.similarity * 100).toFixed(1)}% match`;
          const parts = [
            `--- Result ${i + 1} (${match}) ---`,
            `Captured: ${new Date(t.created_at).toLocaleDateString()}`,
            `Type: ${m.type || "unknown"}`,
          ];
          if (Array.isArray(m.topics) && m.topics.length) parts.push(`Topics: ${m.topics.join(", ")}`);
          if (Array.isArray(m.people) && m.people.length) parts.push(`People: ${m.people.join(", ")}`);
          if (Array.isArray(m.action_items) && m.action_items.length)
            parts.push(`Actions: ${m.action_items.join("; ")}`);
          parts.push(`\n${t.content}`);
          return parts.join("\n");
        });
        const header = `Found ${rows.length} thought(s):`;
        const warnBlock = fallback ? `\n\nWarning: ${warning}` : "";
        return {
          content: [{ type: "text", text: `${header}${warnBlock}\n\n${results.join("\n\n")}` }],
        };
      } catch (err) {
        return errorResult(err);
      }
    }
  );

  // Tool 2: List Recent
  server.registerTool(
    "list_thoughts",
    {
      title: "List Recent Thoughts",
      description:
        "List recently captured thoughts with optional filters by type, topic, person, or time range.",
      annotations: { readOnlyHint: true },
      inputSchema: {
        limit: z.number().optional().default(10),
        type: z
          .string()
          .optional()
          .describe("Filter by type: observation, task, idea, reference, person_note"),
        topic: z.string().optional().describe("Filter by topic tag"),
        person: z.string().optional().describe("Filter by person mentioned"),
        days: z.number().optional().describe("Only thoughts from the last N days"),
      },
    },
    async ({ limit, type, topic, person, days }) => {
      try {
        const rows = await store.listThoughts({ limit, type, topic, person, days });
        if (!rows.length) {
          return { content: [{ type: "text", text: "No thoughts found." }] };
        }
        const results = rows.map((t, i) => {
          const m = t.metadata || {};
          const tags = Array.isArray(m.topics) ? m.topics.join(", ") : "";
          return `${i + 1}. [${new Date(t.created_at).toLocaleDateString()}] (${m.type || "??"}${tags ? " - " + tags : ""})\n   ${t.content}`;
        });
        return {
          content: [
            { type: "text", text: `${rows.length} recent thought(s):\n\n${results.join("\n\n")}` },
          ],
        };
      } catch (err) {
        return errorResult(err);
      }
    }
  );

  // Tool 3: Stats
  server.registerTool(
    "thought_stats",
    {
      title: "Thought Statistics",
      description:
        "Get a summary of all captured thoughts: totals, types, top topics, and people.",
      annotations: { readOnlyHint: true },
      inputSchema: {},
    },
    async () => {
      try {
        const { count, rows } = await store.stats();
        const types = {};
        const topics = {};
        const people = {};
        for (const r of rows) {
          const m = r.metadata || {};
          if (m.type) types[m.type] = (types[m.type] || 0) + 1;
          if (Array.isArray(m.topics)) for (const t of m.topics) topics[t] = (topics[t] || 0) + 1;
          if (Array.isArray(m.people)) for (const p of m.people) people[p] = (people[p] || 0) + 1;
        }
        const sort = (o) =>
          Object.entries(o)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 10);
        const lines = [
          `Total thoughts: ${count}`,
          `Date range: ${
            rows.length
              ? new Date(rows[rows.length - 1].created_at).toLocaleDateString() +
                " -> " +
                new Date(rows[0].created_at).toLocaleDateString()
              : "N/A"
          }`,
          "",
          "Types:",
          ...sort(types).map(([k, v]) => `  ${k}: ${v}`),
        ];
        if (Object.keys(topics).length) {
          lines.push("", "Top topics:");
          for (const [k, v] of sort(topics)) lines.push(`  ${k}: ${v}`);
        }
        if (Object.keys(people).length) {
          lines.push("", "People mentioned:");
          for (const [k, v] of sort(people)) lines.push(`  ${k}: ${v}`);
        }
        return { content: [{ type: "text", text: lines.join("\n") }] };
      } catch (err) {
        return errorResult(err);
      }
    }
  );

  // Tool 4: Capture Thought — firewalled, dedupe-upsert, pending-mode aware.
  server.registerTool(
    "capture_thought",
    {
      title: "Capture Thought",
      description:
        "Save a new thought to the Open Brain. Generates an embedding and extracts metadata automatically. Use this when the user wants to save something to their brain directly from any AI client — notes, insights, decisions, or migrated content from other systems.",
      annotations: {
        readOnlyHint: false,
        openWorldHint: false,
        destructiveHint: false,
        idempotentHint: false,
      },
      inputSchema: {
        content: z
          .string()
          .describe(
            "The thought to capture — a clear, standalone statement that will make sense when retrieved later by any AI"
          ),
      },
    },
    async ({ content }) => {
      try {
        const r = await store.captureThought({ content, source: "mcp" });
        const meta = r.metadata;
        let confirmation = `Captured as ${meta.type || "thought"}`;
        if (Array.isArray(meta.topics) && meta.topics.length)
          confirmation += ` -- ${meta.topics.join(", ")}`;
        if (Array.isArray(meta.people) && meta.people.length)
          confirmation += ` | People: ${meta.people.join(", ")}`;
        if (Array.isArray(meta.action_items) && meta.action_items.length)
          confirmation += ` | Actions: ${meta.action_items.join("; ")}`;
        if (!r.inserted) confirmation += " | Duplicate: metadata merged";
        if (r.embedPending) confirmation += " | Embedding: pending (backfill queued)";
        return { content: [{ type: "text", text: confirmation }] };
      } catch (err) {
        if (err instanceof FirewallError) {
          // 422-semantics as an MCP tool error with the Swedish refusal.
          return { content: [{ type: "text", text: err.message }], isError: true };
        }
        return errorResult(err);
      }
    }
  );

  return server;
}

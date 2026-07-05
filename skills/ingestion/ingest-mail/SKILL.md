---
name: ingest-mail
description: Hämta e-post (M365/Outlook Graph eller NC Mail) inkrementellt in i en persons brain — personens skickade förklaringar utåt (SentItems), klipp citathistorik, läs bara delegerade brevlådor, draft-only. Använd för brain-filling av mailkunskap. Följer source-connector-contract.
---
# Ingest Mail

Personens utåtriktade förklaringar (SentItems) = hög signal. Följer [[source-connector-contract]].

## Auth (M365 Graph)
Client-credentials: `POST https://login.microsoftonline.com/<tenant>/oauth2/v2.0/token` (`scope=https://graph.microsoft.com/.default`, `grant_type=client_credentials`) → Bearer. Kräver **application-behörighet `Mail.Read` + admin-consent**; begränsa appen till just de brevlådor vi läser (Exchange application access policy). NC Mail-app = alternativ Hubs-nativ väg (IMAP/REST).

## Steg
1. Per brevlåda (egen cursor `mailbox-<addr>`): `GET /users/<mailbox>/mailFolders/SentItems/messages?$filter=sentDateTime ge <Z-datum>&$select=subject,sentDateTime,from,toRecipients,body,webLink,conversationId&$orderby=sentDateTime desc&$top=50`, följ `@odata.nextLink`.
2. **Klipp citathistorik** (`strip_quoted`): behåll bara den nyskrivna delen ovanför första citatmarkören (`Från:`/`From:`/`--- Ursprungligt meddelande ---`/`Den … skrev:`/`On … wrote:`). Blir det tomt (bottenpostat/vidarebefordrat) → behåll fulltexten hellre än att tappa allt.
3. Normalisera → brain-post kind `discussion`; `author_is_expert` från FAKTISK avsändare (inte vilken brevlåda vi läser — annars felmärks andras mail); `source_url = webLink`; metadata: mailbox, conversation_id, to[]. Cursor = högsta `sentDateTime`.

## Standarder
Draft-only: connectorn har INGEN send-verb (strukturell Human Gate). Högst PII av alla källor → brain-brandväggens 422 gäller; personens egna mail i egna brain per companion-principen.

## Bevis
Nya mail per brevlåda, citat bortklippt, cursor framflyttad; ingen send-kapacitet i verktyget.

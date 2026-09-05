---
name: public-client-access-design
description: Agreed design for the unauthenticated /request/{token} client portal access on DocumentRequest (token hashing, status gate, throttle).
metadata:
  type: project
---

Public client access to a DocumentRequest uses a SHA-256 hash of a 40-char `Str::random()` token stored in `document_requests.access_token_hash` (unique, nullable); raw token is shown once. Public visibility is gated on `status`, NOT on token existence — a draft with a token must stay private.

**Why:** the user's spec is explicit that draft state must never leak just because a link was generated, and that no raw secret may sit in the DB or logs. Hashing costs nothing here because SHA-256 is deterministic, so the unique index still gives an O(1) lookup (bcrypt/Hash::make would force a table scan and was rejected for that reason).

**How to apply:** when the "send" flow is later implemented, it should be the thing that flips status out of `draft`; do not add a second visibility mechanism. Any new public route joins the same `throttle:public-request` limiter and must never log the raw token.

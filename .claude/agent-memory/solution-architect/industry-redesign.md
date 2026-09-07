---
name: industry-redesign
description: The "Industry" visual redesign initiative (steel-blue/Barlow design system) applied to the existing Document Chaser UI, and the two scope traps it exposes.
metadata:
  type: project
---

A visual redesign ("Industry" design system: `--color-accent:#5980a6`, Barlow / Barlow Condensed, square/flat hairline-bordered components) is being applied to the existing app as a pure UI layer — no new endpoints, routes, DB fields, or business logic.

**Why:** The design came from a Claude Design mockup (static HTML/JS prototype, not real code). The requester wants high visual fidelity without touching backend behavior.

**How to apply:** Two parts of the mockup are NOT pure-visual and must be surfaced as explicit scope questions rather than silently implemented:
1. The mockup dashboard has 4 stat cards + a "Recent requests" list. The real `/dashboard` route is a bare closure with no controller and no data. Building it requires new backend queries.
2. The mockup shows a derived display status (Draft / Awaiting client / Completed / Expired / Archived), but `DocumentRequestController@index` does not send `sent_at`, so Draft-vs-Awaiting cannot be derived client-side without adding that field to the Inertia payload.

Any frontend status derivation must mirror `DocumentRequest::isPubliclyAccessible()` / `isComplete()` exactly, or it will drift from backend truth. See [[public-client-access-design]].

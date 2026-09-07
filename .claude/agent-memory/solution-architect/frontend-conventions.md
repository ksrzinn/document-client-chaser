---
name: frontend-conventions
description: Frontend facts that are easy to get wrong — the app is Composition API (script setup), not Options API, and Tailwind is v3 despite a v4 package being listed.
metadata:
  type: project
---

Two frontend facts that have already been mis-stated once in briefs and are worth re-verifying before acting:

- **Every Vue file uses `<script setup>` (Composition API).** 27 of 28 `.vue` files. A prior brief asserted "Options API only, no Composition API in use" — that was wrong. Composables are the idiomatic extraction mechanism here, not mixins.
- **Tailwind is v3.4.19** (`postcss.config.js` + `tailwind.config.js` + `@tailwind` directives). `package.json` also lists `@tailwindcss/vite ^4`, which is unused and misleading. Do not write v4-only syntax (`@theme`, CSS-first config).

Shared helpers live flat at `resources/js/*.js` (e.g. `format.js`), not in a `Utils/` directory.

**Why:** Getting either wrong produces a plan that doesn't compile or that fights the codebase.

**How to apply:** Check these before proposing frontend architecture; don't trust a brief's characterization of them. Relevant to [[industry-redesign]].

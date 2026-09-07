# Industry Design Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the "Industry" visual design (steel-blue/grey palette, Barlow/Barlow Condensed typography, blueprint-inspired corner details, geometric structure executed as a modern premium SaaS) to the existing Document Chaser Laravel + Vue3 + Inertia app, across every screen, without changing any existing route, authorization rule, validation rule, or business behavior — except two explicitly user-approved additions: a `display_status` computed field on `DocumentRequest`, and a real stats/recent-requests dashboard backed by a new `DashboardController`.

**Architecture:** Design tokens (CSS custom properties) live in `resources/css/app.css` under `:root` and are mapped by reference into `tailwind.config.js theme.extend`; all new/restyled UI is built as Vue 3 `<script setup>` SFCs using Tailwind utility classes bound to those tokens (not a parallel hand-written `.btn`/`.card` global CSS system). A small set of new shared components (StatusTag, Card, PageHeader, EmptyState, Pagination, ConfirmDialog, Toast/ToastStack, SelectInput, TextareaInput, AppSidebar, MobileDrawer, ClientPreviewPanel) replace duplicated inline markup. Status derivation for document requests moves server-side (an Eloquent accessor) since the state machine already lives in `DocumentRequest`; client archived/active status stays a trivial client-side ternary since it's a single boolean with no drift risk.

**Tech Stack:** Laravel 13, Inertia.js v2, Vue 3 (`<script setup>`, no Composition-API mixins), Tailwind CSS v3.4 (NOT v4 — `@tailwindcss/vite` in `package.json` is unused/stale, do not use v4 `@theme`/CSS-first syntax), Pest 5 for backend tests. No new JS dependencies are introduced (no component test runner exists — see Global Constraints).

**Spec:** This plan's decisions were derived from (a) a Claude Design mockup ("Industry" design system + a screen-by-screen static prototype for Document Chaser, read via the design MCP, not committed to this repo) and (b) a full architecture research pass over the existing codebase. There is no separate spec file — the "Design tokens" and "Confirmed decisions" sections below are the spec.

## Design tokens (source of truth — copy verbatim, do not reinterpret)

```
--color-bg: #f2f2f3;            /* app canvas background */
--color-surface: #e9e9ea;
--color-text: #1d1f20;
--color-accent: #5980a6;
--color-accent-2: #728fab;
--color-divider: color-mix(in srgb, #1d1f20 16%, transparent);

--color-neutral-100..900: #f5f5f8, #e7e7ea, #d4d4d7, #b7b7ba, #98989b, #7a7a7d, #5d5d60, #424244, #2b2b2d;
--color-accent-100..900:  #eef6ff, #d6ebff, #b5d9fd, #94bce3, #749dc4, #597ea3, #416180, #2c455d, #1d2d3d;
--color-accent-2-100..900: #eef6ff, #d6ebff, #bdd8f2, #9ebbd8, #7e9cb8, #627d98, #486077, #314457, #1f2d3a;

--font-heading: "Barlow Condensed", system-ui, sans-serif;  /* weight 600 */
--font-body: "Barlow", system-ui, sans-serif;                /* weights 400/500 */
```

Status tag colors (from the mockup's own `tags` map — used only inside `StatusTag.vue`, not exposed as Tailwind theme colors):

| Status | Background | Text | Border |
|---|---|---|---|
| Draft | `#f5f5f8` (neutral-100) | `#424244` (neutral-800) | `#d4d4d7` (neutral-300) |
| Awaiting client | `#eef6ff` (accent-100) | `#2c455d` (accent-800) | `#b5d9fd` (accent-300) |
| Completed | `#e7f0ea` | `#1c4a34` | `#b9d3c3` |
| Expired | `#f7efe1` | `#7a5312` | `#e3cfa8` |
| Archived | `transparent` | `#5d5d60` (neutral-700) | `#b7b7ba` (neutral-400) |
| Active (client) | `#e7f0ea` | `#1c4a34` | `#b9d3c3` |
| Received (item) | `#e7f0ea` | `#1c4a34` | `#b9d3c3` |
| Missing (item) | `#f5f5f8` | `#424244` | `#d4d4d7` |

Danger/error semantic colors (buttons/text, not a full ramp): text `#9a3324`, banner background `#fbecea`, banner border `#edc9c2`, banner text `#7d2a1d`.

Radii used by the actual product mockup (NOT the design-system showcase's flattened override, which applies only to that showcase page): cards/dialogs `8px`, buttons/inputs/tags/segmented-controls `6px`.

Fonts: Barlow (400/500/600) + Barlow Condensed (600), loaded via Bunny Fonts (matches the existing Figtree pattern), replacing Figtree entirely.

## Confirmed decisions (asked and approved by the user)

1. **Dashboard is in scope as a real feature.** Build `DashboardController` with stat queries (clients count, active requests count, pending documents count, completed count) and a "recent requests" list (5 most recent, any status). This is a small, contained backend addition, not scope creep.
2. **Status derivation lives on the backend.** Add `display_status` as an Eloquent accessor on `DocumentRequest`, computed from `status`/`sent_at`/`expires_at` exactly mirroring `isPubliclyAccessible()`/`isComplete()`. Send it in the index, show, and dashboard payloads. No frontend re-implementation of this state machine.
3. **No skeleton-loading rows, no JS-driven table→card breakpoint.** Table→card conversion is pure CSS (`hidden md:table` / `md:hidden`), preserving the existing horizontal-scroll-safety fix (commit `89d0a44`) on the desktop table and avoiding a fake loading state Inertia doesn't actually have.
4. **Fonts via Bunny Fonts**, replacing the current Figtree `<link>` in `app.blade.php`.

## Global Constraints

- Tailwind is v3.4.19 (postcss pipeline). Do not write v4 `@theme` / CSS-first config syntax anywhere.
- No new npm or composer dependencies. In particular: **no JS component test runner exists (no vitest/jest) and none will be added** — Vue component changes are verified via (a) the existing Pest feature test suite, which asserts on Inertia props/component names/redirects, never on CSS classes or DOM structure, and (b) manual browser verification at 390px/430px/768px/1280px/1440px per task. This is a deliberate, approved scope boundary, not a skipped step — if the user wants automated component tests later, that's a separate, explicit decision.
- No new database columns, tables, or migrations. `display_status` is a computed accessor, not a stored column.
- No new routes beyond the one new `GET /dashboard` controller-backed route (replacing the existing closure route of the same name/URI — no URL changes).
- Preserve exactly, at every step:
  - The 3 `window.confirm()` call sites' guard semantics: `Clients/Show.vue` (archive), `DocumentRequests/Show.vue` (archive, resend). Each stays gated on its form's `processing` flag and keeps `preserveScroll: true` on the resulting Inertia post. The resend site's existing behavior — skip any confirmation entirely when `sent_at` is null — is preserved.
  - The exact 404 page copy `"This link isn't available"` (asserted verbatim in `tests/Feature/Http/ClientRequestControllerTest.php:67,76`), including the typographic apostrophe.
  - The public portal's Inertia payload never gains `storage_path` or `user_id` fields (asserted absent in `tests/Feature/Http/PublicUploadTest.php:439-440` and `tests/Feature/Http/DocumentRequestControllerTest.php:461`).
  - Laravel's pagination `link.label` values are rendered with `v-html` (they contain HTML entities like `&laquo;`) and a `null` `link.url` renders as a disabled, non-clickable element.
  - Backend authorization/ownership scoping (`$request->user()->clients()`, `->documentRequests()`, `findOrFail`) is untouched — this is a visual-layer and additive-feature task only.
- Run `docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test` after every backend task and after Phase 2 (layout), Phase 4 tasks touching the public portal, and the error page — it must stay green throughout (212 passing tests today, see Task 21 for the final count with new tests added).
- Commit after each task individually (small, meaningful commits per project convention) — do not batch tasks into one commit.

---

## Phase 0 — Design tokens and base primitives

### Task 1: Design tokens, Tailwind mapping, fonts

**Files:**
- Modify: `resources/css/app.css`
- Modify: `tailwind.config.js`
- Modify: `resources/views/app.blade.php`

**Interfaces:**
- Produces: Tailwind color tokens `accent.{100..900,DEFAULT}`, `accent2.{100..900,DEFAULT}`, `steel.{100..900}` (renamed from "neutral" to avoid silently blending with Tailwind's built-in `neutral` scale), `ink` (text color), `canvas` (page background), `surface`; `fontFamily.heading` = Barlow Condensed, `fontFamily.sans` = Barlow; `borderRadius.card` = 8px, `borderRadius.control` = 6px. Every later task's Tailwind classes reference these exact token names.

- [ ] **Step 1: Write the token CSS**

Replace the full contents of `resources/css/app.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
    :root {
        --color-bg: #f2f2f3;
        --color-surface: #e9e9ea;
        --color-text: #1d1f20;
        --color-accent: #5980a6;
        --color-accent-2: #728fab;
        --color-divider: color-mix(in srgb, #1d1f20 16%, transparent);

        --color-neutral-100: #f5f5f8;
        --color-neutral-200: #e7e7ea;
        --color-neutral-300: #d4d4d7;
        --color-neutral-400: #b7b7ba;
        --color-neutral-500: #98989b;
        --color-neutral-600: #7a7a7d;
        --color-neutral-700: #5d5d60;
        --color-neutral-800: #424244;
        --color-neutral-900: #2b2b2d;

        --color-accent-100: #eef6ff;
        --color-accent-200: #d6ebff;
        --color-accent-300: #b5d9fd;
        --color-accent-400: #94bce3;
        --color-accent-500: #749dc4;
        --color-accent-600: #597ea3;
        --color-accent-700: #416180;
        --color-accent-800: #2c455d;
        --color-accent-900: #1d2d3d;

        --color-accent-2-100: #eef6ff;
        --color-accent-2-200: #d6ebff;
        --color-accent-2-300: #bdd8f2;
        --color-accent-2-400: #9ebbd8;
        --color-accent-2-500: #7e9cb8;
        --color-accent-2-600: #627d98;
        --color-accent-2-700: #486077;
        --color-accent-2-800: #314457;
        --color-accent-2-900: #1f2d3a;
    }

    body {
        background-color: var(--color-bg);
        color: var(--color-text);
    }
}

/* Blueprint corner-mark decoration — used only by Dashboard stat cards.
   Kept as real CSS (not Tailwind utilities): pseudo-element positioning
   this fiddly reads worse as arbitrary-value utility soup. */
@layer components {
    .blueprint {
        position: relative;
    }
    .blueprint > .corner {
        position: absolute;
        width: 11px;
        height: 11px;
        color: color-mix(in srgb, var(--color-text) 55%, transparent);
    }
    .blueprint > .corner::before,
    .blueprint > .corner::after {
        content: '';
        position: absolute;
        background: currentColor;
    }
    .blueprint > .corner::before { left: 5px; top: 0; width: 1px; height: 100%; }
    .blueprint > .corner::after { top: 5px; left: 0; width: 100%; height: 1px; }
    .blueprint > .corner.tl { top: -6px; left: -6px; }
    .blueprint > .corner.tr { top: -6px; right: -6px; }
    .blueprint > .corner.bl { bottom: -6px; left: -6px; }
    .blueprint > .corner.br { bottom: -6px; right: -6px; }
}
```

- [ ] **Step 2: Map tokens into Tailwind**

Replace the full contents of `tailwind.config.js`:

```js
import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Barlow', ...defaultTheme.fontFamily.sans],
                heading: ['"Barlow Condensed"', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                canvas: 'var(--color-bg)',
                surface: 'var(--color-surface)',
                ink: 'var(--color-text)',
                divider: 'var(--color-divider)',
                accent: {
                    DEFAULT: 'var(--color-accent)',
                    100: 'var(--color-accent-100)',
                    200: 'var(--color-accent-200)',
                    300: 'var(--color-accent-300)',
                    400: 'var(--color-accent-400)',
                    500: 'var(--color-accent-500)',
                    600: 'var(--color-accent-600)',
                    700: 'var(--color-accent-700)',
                    800: 'var(--color-accent-800)',
                    900: 'var(--color-accent-900)',
                },
                accent2: {
                    DEFAULT: 'var(--color-accent-2)',
                    100: 'var(--color-accent-2-100)',
                    200: 'var(--color-accent-2-200)',
                    300: 'var(--color-accent-2-300)',
                    400: 'var(--color-accent-2-400)',
                    500: 'var(--color-accent-2-500)',
                    600: 'var(--color-accent-2-600)',
                    700: 'var(--color-accent-2-700)',
                    800: 'var(--color-accent-2-800)',
                    900: 'var(--color-accent-2-900)',
                },
                steel: {
                    100: 'var(--color-neutral-100)',
                    200: 'var(--color-neutral-200)',
                    300: 'var(--color-neutral-300)',
                    400: 'var(--color-neutral-400)',
                    500: 'var(--color-neutral-500)',
                    600: 'var(--color-neutral-600)',
                    700: 'var(--color-neutral-700)',
                    800: 'var(--color-neutral-800)',
                    900: 'var(--color-neutral-900)',
                },
            },
            borderRadius: {
                card: '8px',
                control: '6px',
            },
        },
    },

    plugins: [forms],
};
```

- [ ] **Step 3: Swap the font `<link>`**

In `resources/views/app.blade.php`, replace:

```html
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
```

with:

```html
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=barlow:400,500,600|barlow-condensed:600&display=swap" rel="stylesheet" />
```

- [ ] **Step 4: Verify the build and visually smoke-test**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: still 212 passed (no backend touched). Then open the app in a browser and confirm the page background is a light steel-grey and body text renders in Barlow (headings will still look like body text until Task 2+ apply `font-heading` — that's expected at this step).

- [ ] **Step 5: Commit**

```bash
cd /var/www/html/projects/client-document-chaser/.claude/worktrees/redesign-industry-ui
git add resources/css/app.css tailwind.config.js resources/views/app.blade.php
git commit -m "$(cat <<'EOF'
feat: add Industry design tokens and Barlow fonts

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 2: Restyle base form/button components

**Files:**
- Modify: `resources/js/Components/PrimaryButton.vue`
- Modify: `resources/js/Components/SecondaryButton.vue`
- Modify: `resources/js/Components/TextInput.vue`
- Modify: `resources/js/Components/InputLabel.vue`
- Modify: `resources/js/Components/InputError.vue`
- Modify: `resources/js/Components/Checkbox.vue`
- Create: `resources/js/Components/SelectInput.vue`
- Create: `resources/js/Components/TextareaInput.vue`

**Interfaces:**
- Consumes: Tailwind tokens from Task 1 (`accent`, `steel`, `ink`, `divider`, `control` radius, `font-heading`).
- Produces: `SelectInput` — props `{ modelValue }` (via `defineModel`), default slot for `<option>` children, same visual language as `TextInput`. `TextareaInput` — props `{ modelValue }` (via `defineModel`), attrs pass-through (`rows`, `placeholder`). Both used by Task 18 (Document Request Create/Edit).

- [ ] **Step 1: Restyle `PrimaryButton.vue`**

```vue
<script setup>
defineProps({
    type: {
        type: String,
        default: 'submit',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-control border border-transparent bg-accent px-4 py-2 font-heading text-[15px] font-semibold text-white shadow-sm transition duration-150 ease-in-out hover:bg-accent-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 active:bg-accent-700 disabled:cursor-not-allowed disabled:opacity-45"
    >
        <slot />
    </button>
</template>
```

- [ ] **Step 2: Restyle `SecondaryButton.vue`**

```vue
<script setup>
defineProps({
    type: {
        type: String,
        default: 'button',
    },
});
</script>

<template>
    <button
        :type="type"
        class="inline-flex min-h-[44px] items-center justify-center gap-2 rounded-control border border-divider bg-white px-4 py-2 font-heading text-[15px] font-semibold text-ink transition duration-150 ease-in-out hover:bg-steel-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-45"
    >
        <slot />
    </button>
</template>
```

- [ ] **Step 3: Restyle `TextInput.vue`** (keep the existing `defineModel`/autofocus/`defineExpose` behavior untouched — visual classes only)

```vue
<script setup>
import { onMounted, ref } from 'vue';

const model = defineModel({
    type: String,
    required: true,
});

const input = ref(null);

onMounted(() => {
    if (input.value.hasAttribute('autofocus')) {
        input.value.focus();
    }
});

defineExpose({ focus: () => input.value.focus() });
</script>

<template>
    <input
        class="min-h-[44px] w-full rounded-control border-divider bg-white text-[15px] text-ink shadow-sm transition duration-150 ease-in-out focus:border-accent focus:ring-accent"
        v-model="model"
        ref="input"
    />
</template>
```

- [ ] **Step 4: Restyle `InputLabel.vue`**

```vue
<script setup>
defineProps({
    value: {
        type: String,
    },
});
</script>

<template>
    <label class="block text-sm font-medium text-steel-700">
        <span v-if="value">{{ value }}</span>
        <span v-else><slot /></span>
    </label>
</template>
```

- [ ] **Step 5: Restyle `InputError.vue`**

```vue
<script setup>
defineProps({
    message: {
        type: String,
    },
});
</script>

<template>
    <div v-show="message">
        <p role="alert" class="flex items-center gap-1.5 text-sm text-[#9a3324]">
            {{ message }}
        </p>
    </div>
</template>
```

- [ ] **Step 6: Restyle `Checkbox.vue`** (keep the existing `v-model:checked` proxy behavior untouched)

```vue
<script setup>
import { computed } from 'vue';

const emit = defineEmits(['update:checked']);

const props = defineProps({
    checked: {
        type: [Array, Boolean],
        required: true,
    },
    value: {
        default: null,
    },
});

const proxyChecked = computed({
    get() {
        return props.checked;
    },
    set(val) {
        emit('update:checked', val);
    },
});
</script>

<template>
    <input
        type="checkbox"
        :value="value"
        v-model="proxyChecked"
        class="h-4 w-4 rounded border-divider text-accent shadow-sm focus:ring-accent"
    />
</template>
```

- [ ] **Step 7: Create `SelectInput.vue`**

```vue
<script setup>
const model = defineModel({
    required: true,
});
</script>

<template>
    <select
        v-model="model"
        class="min-h-[44px] w-full rounded-control border-divider bg-white text-[15px] text-ink shadow-sm transition duration-150 ease-in-out focus:border-accent focus:ring-accent"
    >
        <slot />
    </select>
</template>
```

- [ ] **Step 8: Create `TextareaInput.vue`**

```vue
<script setup>
const model = defineModel({
    type: String,
    required: true,
});
</script>

<template>
    <textarea
        v-model="model"
        class="w-full rounded-control border-divider bg-white text-[15px] text-ink shadow-sm transition duration-150 ease-in-out focus:border-accent focus:ring-accent"
    ></textarea>
</template>
```

- [ ] **Step 9: Run backend tests (no backend touched, but confirms nothing broke via Vite manifest resolution) and manually verify all 4 auth forms + both client/request forms still submit**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 212 passed.

- [ ] **Step 10: Commit**

```bash
git add resources/js/Components/PrimaryButton.vue resources/js/Components/SecondaryButton.vue resources/js/Components/TextInput.vue resources/js/Components/InputLabel.vue resources/js/Components/InputError.vue resources/js/Components/Checkbox.vue resources/js/Components/SelectInput.vue resources/js/Components/TextareaInput.vue
git commit -m "$(cat <<'EOF'
refactor: restyle shared form and button components

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 3: StatusTag component

**Files:**
- Create: `resources/js/Components/StatusTag.vue`

**Interfaces:**
- Produces: `StatusTag` — prop `label` (`String`, required) whose value must be one of `'Draft' | 'Awaiting client' | 'Completed' | 'Expired' | 'Archived' | 'Active' | 'Received' | 'Missing'`. Renders a colored pill matching the table above. Consumed by Tasks 14, 16, 17, 19 (client/request status), and Task 19's item checklist rows (`Received`/`Missing`).

- [ ] **Step 1: Write the component**

```vue
<script setup>
import { computed } from 'vue';

const props = defineProps({
    label: {
        type: String,
        required: true,
    },
});

const VARIANTS = {
    Draft: 'bg-steel-100 text-steel-800 border-steel-300',
    'Awaiting client': 'bg-accent-100 text-accent-800 border-accent-300',
    Completed: 'bg-[#e7f0ea] text-[#1c4a34] border-[#b9d3c3]',
    Expired: 'bg-[#f7efe1] text-[#7a5312] border-[#e3cfa8]',
    Archived: 'bg-transparent text-steel-700 border-steel-400',
    Active: 'bg-[#e7f0ea] text-[#1c4a34] border-[#b9d3c3]',
    Received: 'bg-[#e7f0ea] text-[#1c4a34] border-[#b9d3c3]',
    Missing: 'bg-steel-100 text-steel-800 border-steel-300',
};

const classes = computed(() => VARIANTS[props.label] ?? VARIANTS.Draft);
</script>

<template>
    <span
        class="inline-flex items-center whitespace-nowrap rounded border px-2.5 py-0.5 text-xs font-medium"
        :class="classes"
    >
        {{ label }}
    </span>
</template>
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/StatusTag.vue
git commit -m "$(cat <<'EOF'
feat: add StatusTag component

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 4: Card, PageHeader, EmptyState components

**Files:**
- Create: `resources/js/Components/Card.vue`
- Create: `resources/js/Components/PageHeader.vue`
- Create: `resources/js/Components/EmptyState.vue`

**Interfaces:**
- Produces: `Card` — default slot only, white rounded-card container with a divider border. `PageHeader` — prop `title` (`String`, required), default slot for the subtitle paragraph, `#actions` slot for right-aligned buttons. `EmptyState` — props `title` (`String`, required), `description` (`String`, required), `#icon` slot, `#action` slot.

- [ ] **Step 1: Write `Card.vue`**

```vue
<template>
    <div class="overflow-hidden rounded-card border border-divider bg-white">
        <slot />
    </div>
</template>
```

- [ ] **Step 2: Write `PageHeader.vue`**

```vue
<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div class="mb-5 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="font-heading text-3xl leading-tight text-ink">{{ title }}</h1>
            <p v-if="$slots.default" class="mt-1 text-sm text-steel-700">
                <slot />
            </p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap gap-2">
            <slot name="actions" />
        </div>
    </div>
</template>
```

- [ ] **Step 3: Write `EmptyState.vue`**

```vue
<script setup>
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        required: true,
    },
});
</script>

<template>
    <div class="flex flex-col items-center gap-2.5 rounded-card border border-divider bg-white px-6 py-14 text-center">
        <span
            v-if="$slots.icon"
            class="grid h-13 w-13 place-items-center rounded-card border border-accent-300 bg-accent-100 text-accent-700"
        >
            <slot name="icon" />
        </span>
        <h2 class="mt-1.5 font-heading text-xl text-ink">{{ title }}</h2>
        <p class="max-w-sm text-sm leading-relaxed text-steel-700">{{ description }}</p>
        <div v-if="$slots.action" class="mt-2">
            <slot name="action" />
        </div>
    </div>
</template>
```

- [ ] **Step 4: Commit**

```bash
git add resources/js/Components/Card.vue resources/js/Components/PageHeader.vue resources/js/Components/EmptyState.vue
git commit -m "$(cat <<'EOF'
feat: add Card, PageHeader, and EmptyState components

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 5: Pagination component

**Files:**
- Create: `resources/js/Components/Pagination.vue`
- Modify: `resources/js/Pages/Clients/Index.vue:65-80` (pagination block only — full page restyle happens in Task 14, this step only dedupes pagination so it's not restyled twice)
- Modify: `resources/js/Pages/DocumentRequests/Index.vue:67-82` (same)

**Interfaces:**
- Consumes: nothing new.
- Produces: `Pagination` — prop `links` (`Array`, required; Laravel's default paginator link shape `{ url: string|null, label: string, active: boolean }`).

- [ ] **Step 1: Write `Pagination.vue`**, replicating the exact current behavior (only render when more than the prev/current/next 3 links, `v-html` for entity-encoded labels, disabled span when `url` is null)

```vue
<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: {
        type: Array,
        required: true,
    },
});
</script>

<template>
    <div v-if="links.length > 3" class="mt-4 flex justify-center gap-1 px-6 pb-4">
        <template v-for="(link, index) in links" :key="index">
            <Link
                v-if="link.url"
                :href="link.url"
                v-html="link.label"
                class="rounded px-3 py-1 text-sm"
                :class="link.active ? 'bg-accent text-white' : 'text-steel-700 hover:bg-steel-100'"
            />
            <span
                v-else
                v-html="link.label"
                class="rounded px-3 py-1 text-sm text-steel-400"
            />
        </template>
    </div>
</template>
```

- [ ] **Step 2: Wire into `Clients/Index.vue`** — replace lines 65-80 (the `<div v-if="clients.links.length > 3">...</div>` block) with:

```vue
                    <Pagination :links="clients.links" />
```

Add the import to the `<script setup>` block: `import Pagination from '@/Components/Pagination.vue';`

- [ ] **Step 3: Wire into `DocumentRequests/Index.vue`** — same replacement using `documentRequests.links`, same import.

- [ ] **Step 4: Manually verify pagination still renders/navigates** by seeding >15 clients (`php artisan tinker` → `\App\Models\Client::factory(20)->for($user)->create();` against a logged-in user) and clicking through pages 1→2→1.

- [ ] **Step 5: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 212 passed.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Pagination.vue resources/js/Pages/Clients/Index.vue resources/js/Pages/DocumentRequests/Index.vue
git commit -m "$(cat <<'EOF'
refactor: extract shared Pagination component

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 6: ConfirmDialog component and the 3 confirm() call sites

**Files:**
- Create: `resources/js/Components/ConfirmDialog.vue`
- Modify: `resources/js/Pages/Clients/Show.vue`
- Modify: `resources/js/Pages/DocumentRequests/Show.vue`

**Interfaces:**
- Produces: `ConfirmDialog` — props `show` (`Boolean`), `title` (`String`, required), `body` (`String`, required), `confirmLabel` (`String`, default `'Confirm'`), `processing` (`Boolean`, default `false`), `danger` (`Boolean`, default `false` — controls whether the confirm button uses the danger red or the accent color); emits `confirm`, `cancel`.

- [ ] **Step 1: Write `ConfirmDialog.vue`** (teleported, `Escape` to close, backdrop click cancels, matches Breeze's existing `Modal.vue` pattern conventions even though this project has no `Modal.vue` yet)

```vue
<script setup>
import { onMounted, onUnmounted } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    title: {
        type: String,
        required: true,
    },
    body: {
        type: String,
        required: true,
    },
    confirmLabel: {
        type: String,
        default: 'Confirm',
    },
    processing: {
        type: Boolean,
        default: false,
    },
    danger: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['confirm', 'cancel']);

function onKeydown(event) {
    if (event.key === 'Escape' && props.show) {
        emit('cancel');
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="show"
            class="fixed inset-0 z-50 flex items-center justify-center bg-[#1d2d3d]/55 p-4"
            @click.self="emit('cancel')"
        >
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="confirm-dialog-title"
                class="w-full max-w-[440px] rounded-card border border-divider bg-white p-5 shadow-lg"
            >
                <h2 id="confirm-dialog-title" class="font-heading text-xl text-ink">{{ title }}</h2>
                <p class="mt-2 text-sm leading-relaxed text-steel-700">{{ body }}</p>
                <div class="mt-5 flex justify-end gap-2">
                    <button
                        type="button"
                        class="inline-flex min-h-[44px] items-center rounded-control border border-divider bg-white px-4 py-2 text-sm font-semibold text-ink hover:bg-steel-100"
                        @click="emit('cancel')"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        class="inline-flex min-h-[44px] items-center rounded-control px-4 py-2 text-sm font-semibold text-white disabled:cursor-not-allowed disabled:opacity-45"
                        :class="danger ? 'bg-[#9a3324] hover:bg-[#7d2a1d]' : 'bg-accent hover:bg-accent-600'"
                        :disabled="processing"
                        @click="emit('confirm')"
                    >
                        {{ confirmLabel }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
```

- [ ] **Step 2: Wire into `Clients/Show.vue`** — replace the `<script setup>` archive logic and template archive button:

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});

const archiveForm = useForm({});
const confirmingArchive = ref(false);

const openArchiveConfirm = () => {
    confirmingArchive.value = true;
};

const archive = () => {
    archiveForm.post(route('clients.archive', props.client.id), {
        preserveScroll: true,
        onFinish: () => {
            confirmingArchive.value = false;
        },
    });
};
</script>
```

Replace the archive `<PrimaryButton>` in the template with:

```vue
                        <PrimaryButton
                            v-if="!client.archived_at"
                            type="button"
                            :disabled="archiveForm.processing"
                            @click="openArchiveConfirm"
                        >
                            Archive
                        </PrimaryButton>
```

Add just before `</AuthenticatedLayout>`'s closing (as a sibling of the page content, inside the root):

```vue
    <ConfirmDialog
        :show="confirmingArchive"
        title="Archive this client?"
        body="Their document requests will remain but the client can no longer be edited."
        confirm-label="Archive"
        danger
        :processing="archiveForm.processing"
        @confirm="archive"
        @cancel="confirmingArchive = false"
    />
```

- [ ] **Step 3: Wire into `DocumentRequests/Show.vue`** — same pattern for both archive and resend, preserving the resend site's "skip the dialog when never sent" behavior:

```vue
<script setup>
// ...existing imports...
import ConfirmDialog from '@/Components/ConfirmDialog.vue';
import { ref } from 'vue';

// ...existing props...

const archiveForm = useForm({});
const confirmingArchive = ref(false);
const openArchiveConfirm = () => { confirmingArchive.value = true; };
const archive = () => {
    archiveForm.post(route('document-requests.archive', props.documentRequest.id), {
        preserveScroll: true,
        onFinish: () => { confirmingArchive.value = false; },
    });
};

const sendForm = useForm({});
const confirmingSend = ref(false);
const openSendConfirm = () => {
    if (!props.documentRequest.sent_at) {
        send();
        return;
    }
    confirmingSend.value = true;
};
const send = () => {
    sendForm.post(route('document-requests.send', props.documentRequest.id), {
        preserveScroll: true,
        onFinish: () => { confirmingSend.value = false; },
    });
};

// ...existing copyLink logic unchanged...
</script>
```

Replace the archive button's `@click="archive"` with `@click="openArchiveConfirm"`, and the send button's `@click="send"` with `@click="openSendConfirm"`. Add both dialogs as siblings near the end of the template:

```vue
    <ConfirmDialog
        :show="confirmingArchive"
        title="Archive this request?"
        body="The client will no longer be able to access the upload link."
        confirm-label="Archive"
        danger
        :processing="archiveForm.processing"
        @confirm="archive"
        @cancel="confirmingArchive = false"
    />
    <ConfirmDialog
        :show="confirmingSend"
        title="Resend this request?"
        body="The previous link will stop working."
        confirm-label="Resend"
        :processing="sendForm.processing"
        @confirm="send"
        @cancel="confirmingSend = false"
    />
```

- [ ] **Step 4: Manually verify** all 3 flows in a browser: archive a client, archive a request, resend an already-sent request (dialog appears), send a never-sent request (no dialog, posts immediately) — each disables its confirm button while `processing`.

- [ ] **Step 5: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 212 passed (these are backend-asserted behaviors, e.g. `it is idempotent when archiving an already-archived request`, and none of them depend on `window.confirm`, which was always frontend-only).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/ConfirmDialog.vue resources/js/Pages/Clients/Show.vue resources/js/Pages/DocumentRequests/Show.vue
git commit -m "$(cat <<'EOF'
feat: replace window.confirm with ConfirmDialog

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 7: Toast/ToastStack and flash message upgrade

**Files:**
- Create: `resources/js/Components/Toast.vue`
- Create: `resources/js/Components/ToastStack.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (remove the two static flash `<div>` blocks, add `<ToastStack>`)
- Modify: `resources/js/Layouts/GuestLayout.vue` (add `<ToastStack>` — the public portal uses this layout and currently gets no flash rendering at all)

**Interfaces:**
- Produces: `Toast` — props `message` (`String`, required), `variant` (`'success'|'error'`, default `'success'`); emits `dismiss`. `ToastStack` — no props; reads `$page.props.flash.success`/`flash.error` itself via `usePage()`, auto-dismisses each after 4200ms (matching the mockup's `_tt` timeout), de-duplicates so a new flash on the same key restarts its own timer without stacking duplicates.

- [ ] **Step 1: Write `Toast.vue`**

```vue
<script setup>
defineProps({
    message: {
        type: String,
        required: true,
    },
    variant: {
        type: String,
        default: 'success',
        validator: (v) => ['success', 'error'].includes(v),
    },
});

defineEmits(['dismiss']);
</script>

<template>
    <div
        role="status"
        aria-live="polite"
        class="flex items-center gap-3 rounded-card border px-4 py-3 shadow-lg"
        :class="variant === 'error'
            ? 'border-[#edc9c2] bg-[#fbecea] text-[#7d2a1d]'
            : 'border-[#b9d3c3] bg-[#e7f0ea] text-[#1c4a34]'"
    >
        <span class="min-w-0 flex-1 text-sm leading-snug">{{ message }}</span>
        <button
            type="button"
            aria-label="Dismiss notification"
            class="grid h-7 w-7 flex-none place-items-center rounded text-inherit opacity-60 hover:opacity-100"
            @click="$emit('dismiss')"
        >
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
    </div>
</template>
```

- [ ] **Step 2: Write `ToastStack.vue`**

```vue
<script setup>
import { usePage } from '@inertiajs/vue3';
import { reactive, watch } from 'vue';
import Toast from '@/Components/Toast.vue';

const page = usePage();
const toasts = reactive([]);
let nextId = 0;
const timers = {};

function push(message, variant) {
    const id = nextId++;
    toasts.push({ id, message, variant });
    timers[id] = setTimeout(() => dismiss(id), 4200);
}

function dismiss(id) {
    clearTimeout(timers[id]);
    delete timers[id];
    const index = toasts.findIndex((t) => t.id === id);
    if (index !== -1) toasts.splice(index, 1);
}

watch(
    () => page.props.flash.success,
    (message) => {
        if (message) push(message, 'success');
    }
);

watch(
    () => page.props.flash.error,
    (message) => {
        if (message) push(message, 'error');
    }
);
</script>

<template>
    <div class="fixed inset-x-0 bottom-4 z-[60] flex flex-col items-center gap-2 px-4 sm:items-end sm:px-6">
        <div v-for="toast in toasts" :key="toast.id" class="w-full max-w-sm">
            <Toast :message="toast.message" :variant="toast.variant" @dismiss="dismiss(toast.id)" />
        </div>
    </div>
</template>
```

- [ ] **Step 3: Wire into `AuthenticatedLayout.vue`** — remove this block entirely from the `<main>` section:

```vue
                <div
                    v-if="$page.props.flash.success"
                    role="status"
                    class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8"
                >
                    <div class="rounded-md bg-green-50 p-4 text-sm text-green-800">
                        {{ $page.props.flash.success }}
                    </div>
                </div>
                <div
                    v-if="$page.props.flash.error"
                    role="alert"
                    class="mx-auto max-w-7xl px-4 pt-4 sm:px-6 lg:px-8"
                >
                    <div class="rounded-md bg-red-50 p-4 text-sm text-red-800">
                        {{ $page.props.flash.error }}
                    </div>
                </div>
```

Add `import ToastStack from '@/Components/ToastStack.vue';` to the script, and add `<ToastStack />` as a direct child of the outermost `<div>` in the template (sibling to the `min-h-screen` wrapper, so it's fixed-positioned relative to the viewport regardless of scroll).

- [ ] **Step 4: Wire into `GuestLayout.vue`** — add the same import and `<ToastStack />` placement (this layout has no flash rendering today; the public portal will now surface `flash.success`/`flash.error` if any are ever set for that path — currently none are, so this is inert until Task 20, which doesn't add any, but keeps the layout consistent for future auth-flow flashes like `status` messages).

- [ ] **Step 5: Manually verify** — trigger a client/request archive, confirm a toast appears bottom-right and auto-dismisses after ~4.2s, and can be dismissed early by clicking the X.

- [ ] **Step 6: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 212 passed (flash message keys/session behavior are backend-only and untouched).

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/Toast.vue resources/js/Components/ToastStack.vue resources/js/Layouts/AuthenticatedLayout.vue resources/js/Layouts/GuestLayout.vue
git commit -m "$(cat <<'EOF'
feat: replace static flash banners with auto-dismissing toasts

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

## Phase 1 — Backend additions (display_status + Dashboard)

### Task 8: `DocumentRequest::display_status` accessor

**Files:**
- Modify: `app/Models/DocumentRequest.php`
- Test: `tests/Unit/Models/DocumentRequestDisplayStatusTest.php` (new)

**Interfaces:**
- Produces: `DocumentRequest::getDisplayStatusAttribute(): string`, accessible as `$documentRequest->display_status`, returning one of `'archived' | 'completed' | 'expired' | 'awaiting_client' | 'draft'`. Consumed by Task 9 (controller payloads) and Task 10 (dashboard queries).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\User;

it('is draft when never sent', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => null, 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('draft');
});

it('is awaiting_client when sent with no expiry', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now(), 'expires_at' => null, 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('awaiting_client');
});

it('is expired when sent and expiry is in the past', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now()->subDays(10), 'expires_at' => now()->subDay(), 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('expired');
});

it('is awaiting_client when sent and expiry is in the future', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now(), 'expires_at' => now()->addDay(), 'status' => 'draft']);

    expect($documentRequest->display_status)->toBe('awaiting_client');
});

it('is completed regardless of expiry once status is completed', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => now()->subDays(10), 'expires_at' => now()->subDay(), 'status' => 'completed']);

    expect($documentRequest->display_status)->toBe('completed');
});

it('is archived regardless of every other field once status is archived', function () {
    $documentRequest = DocumentRequest::factory()
        ->for(User::factory())
        ->for(Client::factory())
        ->create(['sent_at' => null, 'status' => 'archived']);

    expect($documentRequest->display_status)->toBe('archived');
});
```

- [ ] **Step 2: Run test to verify it fails**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test tests/Unit/Models/DocumentRequestDisplayStatusTest.php
```
Expected: FAIL — `display_status` is not a defined accessor, tests error with an undefined-property warning coerced to `null`, so `expect(null)->toBe('draft')` etc. fail.

- [ ] **Step 3: Implement the accessor** — add to `app/Models/DocumentRequest.php`, directly below `isComplete()`:

```php
    public function getDisplayStatusAttribute(): string
    {
        if ($this->status === 'archived') {
            return 'archived';
        }

        if ($this->status === 'completed') {
            return 'completed';
        }

        if ($this->sent_at === null) {
            return 'draft';
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return 'expired';
        }

        return 'awaiting_client';
    }
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test tests/Unit/Models/DocumentRequestDisplayStatusTest.php
```
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Models/DocumentRequest.php tests/Unit/Models/DocumentRequestDisplayStatusTest.php
git commit -m "$(cat <<'EOF'
feat: add DocumentRequest::display_status accessor

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 9: Send `display_status` in index/show payloads

**Files:**
- Modify: `app/Http/Controllers/DocumentRequestController.php:19-31` (`index`)
- Modify: `app/Http/Controllers/DocumentRequestController.php:70-96` (`show`)
- Test: `tests/Feature/Http/DocumentRequestControllerTest.php` (extend existing tests, don't create a new file)

**Interfaces:**
- Consumes: `DocumentRequest::display_status` from Task 8.
- Produces: `documentRequests.data[].display_status` and `documentRequest.display_status` in the two Inertia payloads. Consumed by Task 17 (`Index.vue`) and Task 19 (`Show.vue`) via `StatusTag`.

- [ ] **Step 1: Write the failing test additions** — append to `tests/Feature/Http/DocumentRequestControllerTest.php` (find the existing `it('shows...')` / index test block and add two new `it()` blocks near it):

```php
it('includes display_status for each row in the index payload', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)
        ->create(['sent_at' => now(), 'expires_at' => null, 'status' => 'draft']);

    $response = $this->actingAs($user)->get(route('document-requests.index'));

    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Index')
        ->where('documentRequests.data.0.display_status', 'awaiting_client'));
});

it('includes display_status in the show payload', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();
    $documentRequest = DocumentRequest::factory()->for($user)->for($client)
        ->create(['sent_at' => null, 'status' => 'draft']);

    $response = $this->actingAs($user)->get(route('document-requests.show', $documentRequest));

    $response->assertInertia(fn ($page) => $page
        ->component('DocumentRequests/Show')
        ->where('documentRequest.display_status', 'draft'));
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test --filter="includes display_status"
```
Expected: FAIL — `display_status` key absent from the payload (Inertia's `where()` fails a missing-key path with a clear error).

- [ ] **Step 3: Add the field to both payloads** — in `index()`, change the `->through()` callback:

```php
            ->through(fn (DocumentRequest $documentRequest) => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'items_count']),
                'display_status' => $documentRequest->display_status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'client' => $documentRequest->client->only(['id', 'name']),
            ]);
```

In `show()`, change the `documentRequest` array:

```php
            'documentRequest' => [
                ...$documentRequest->only(['id', 'status', 'message', 'due_at', 'expires_at', 'created_at', 'updated_at']),
                'display_status' => $documentRequest->display_status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'expires_at' => $documentRequest->expires_at?->toDateString(),
                'sent_at' => $documentRequest->sent_at?->toIso8601String(),
                'completed_at' => $documentRequest->completed_at?->toIso8601String(),
                'client' => $documentRequest->client->only(['id', 'name', 'email']),
                'items' => $documentRequest->items->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'status' => $item->status,
                    'documents' => $item->uploadedDocuments->map(fn ($document) => [
                        'id' => $document->id,
                        'original_filename' => $document->original_filename,
                        'mime_type' => $document->mime_type,
                        'size' => $document->size,
                        'uploaded_at' => $document->uploaded_at->toIso8601String(),
                    ])->values(),
                ]),
            ],
```

- [ ] **Step 4: Run to verify it passes, then run the full suite**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 214 passed (212 + 2 new).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DocumentRequestController.php tests/Feature/Http/DocumentRequestControllerTest.php
git commit -m "$(cat <<'EOF'
feat: expose display_status in document request payloads

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 10: DashboardController with stats and recent requests

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/web.php:26-28` (replace the closure route)
- Test: `tests/Feature/Http/DashboardControllerTest.php` (new)

**Interfaces:**
- Produces: `GET /dashboard` → `Inertia::render('Dashboard', [...])` with props `stats: { clients: int, activeRequests: int, pendingDocuments: int, completed: int }` and `recentRequests: array` (id, client name, display_status, due_at as date string, items received/total counts — max 5, newest first, any status). Consumed by Task 13 (`Dashboard.vue`).

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\User;

it('shows zeroed stats and no recent requests for a brand new user', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('stats.clients', 0)
        ->where('stats.activeRequests', 0)
        ->where('stats.pendingDocuments', 0)
        ->where('stats.completed', 0)
        ->where('recentRequests', []));
});

it('counts clients, active/completed requests, and pending documents scoped to the current user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $client = Client::factory()->for($user)->create();
    Client::factory()->for($user)->create(['archived_at' => now()]);
    Client::factory()->for($otherUser)->create();

    $active = DocumentRequest::factory()->for($user)->for($client)
        ->create(['status' => 'draft', 'sent_at' => now()]);
    DocumentRequestItem::factory()->for($active)->create(['status' => 'requested']);
    DocumentRequestItem::factory()->for($active)->create(['status' => 'requested']);

    $completed = DocumentRequest::factory()->for($user)->for($client)
        ->create(['status' => 'completed']);
    DocumentRequestItem::factory()->for($completed)->create(['status' => 'received']);

    DocumentRequest::factory()->for($otherUser)->for(Client::factory()->for($otherUser))
        ->create(['status' => 'draft', 'sent_at' => now()]);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->where('stats.clients', 2)
        ->where('stats.activeRequests', 1)
        ->where('stats.pendingDocuments', 2)
        ->where('stats.completed', 1));
});

it('lists at most 5 recent requests ordered newest first, regardless of status', function () {
    $user = User::factory()->create();
    $client = Client::factory()->for($user)->create();

    for ($i = 0; $i < 7; $i++) {
        DocumentRequest::factory()->for($user)->for($client)->create();
    }

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertInertia(fn ($page) => $page
        ->component('Dashboard')
        ->has('recentRequests', 5));
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test tests/Feature/Http/DashboardControllerTest.php
```
Expected: FAIL — the closure route renders `Dashboard` with no props at all, so `stats.clients` etc. are missing keys.

- [ ] **Step 3: Write `DashboardController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\DocumentRequest;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $activeRequests = $user->documentRequests()
            ->whereNotIn('status', ['archived', 'completed']);

        $pendingDocuments = (clone $activeRequests)
            ->withCount(['items' => fn ($query) => $query->where('status', '!=', 'received')])
            ->get()
            ->sum('items_count');

        $recentRequests = $user->documentRequests()
            ->with('client:id,name')
            ->withCount('items')
            ->withCount(['items as received_items_count' => fn ($query) => $query->where('status', 'received')])
            ->orderByDesc('created_at')
            ->limit(5)
            ->get()
            ->map(fn (DocumentRequest $documentRequest) => [
                'id' => $documentRequest->id,
                'client_name' => $documentRequest->client->name,
                'display_status' => $documentRequest->display_status,
                'due_at' => $documentRequest->due_at?->toDateString(),
                'items_received' => $documentRequest->received_items_count,
                'items_total' => $documentRequest->items_count,
            ]);

        return Inertia::render('Dashboard', [
            'stats' => [
                'clients' => $user->clients()->whereNull('archived_at')->count(),
                'activeRequests' => (clone $activeRequests)->count(),
                'pendingDocuments' => $pendingDocuments,
                'completed' => $user->documentRequests()->where('status', 'completed')->count(),
            ],
            'recentRequests' => $recentRequests,
        ]);
    }
}
```

- [ ] **Step 4: Replace the route** — in `routes/web.php`, replace:

```php
Route::get('/dashboard', function () {
    return inertia('Dashboard');
})->middleware('auth')->name('dashboard');
```

with:

```php
Route::get('/dashboard', DashboardController::class)->middleware('auth')->name('dashboard');
```

Add the import: `use App\Http\Controllers\DashboardController;`

- [ ] **Step 5: Run to verify it passes, then the full suite**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed (214 + 3 new).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/DashboardController.php routes/web.php tests/Feature/Http/DashboardControllerTest.php
git commit -m "$(cat <<'EOF'
feat: add DashboardController with stats and recent requests

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

## Phase 2 — Layout (sidebar + mobile drawer)

### Task 11: AppSidebar, MobileDrawer, and AuthenticatedLayout rewrite

**Files:**
- Create: `resources/js/Components/AppSidebar.vue`
- Create: `resources/js/Components/MobileDrawer.vue`
- Modify: `resources/js/Layouts/AuthenticatedLayout.vue` (full rewrite)

**Interfaces:**
- Consumes: `route()` (Ziggy, already globally available), `$page.props.auth.user` (`{ id, name, email }`).
- Produces: `AppSidebar` — no props, self-contained desktop nav (renders only ≥`md` via a `hidden md:flex` wrapper in the parent, not internally, so it stays a dumb presentational component). `MobileDrawer` — prop `show` (`Boolean`), emits `close`. Both consumed only by `AuthenticatedLayout.vue`.
- This is the highest-risk single task (per architecture doc) — do it in isolation, verify all three breakpoints before moving on.

- [ ] **Step 1: Write `AppSidebar.vue`** — desktop-only left nav (250px), logo, 3 nav links with active-state styling via `route().current()`, user menu dropdown pinned to the bottom (name/email from `auth.user`, Log Out posts to `route('logout')`)

```vue
<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const page = usePage();
const userMenuOpen = ref(false);

function initials(name) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}

const navLinkClass = (active) => [
    'flex items-center gap-2.5 rounded-control px-3 py-2.5 text-sm font-medium transition',
    active ? 'bg-accent-100 text-accent-800' : 'text-steel-700 hover:bg-steel-100',
];
</script>

<template>
    <aside class="flex w-[250px] flex-none flex-col gap-1.5 border-r border-divider bg-white px-3.5 py-5">
        <Link :href="route('dashboard')" class="mb-4 flex items-center gap-2.5 px-2">
            <span class="grid h-7 w-7 place-items-center rounded-control border border-accent text-accent">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="M8.5 14l2 2 4-4.5" /></svg>
            </span>
            <span class="font-heading text-base uppercase tracking-wide text-ink">Document Chaser</span>
        </Link>

        <Link :href="route('dashboard')" :class="navLinkClass(route().current('dashboard'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7" /><rect x="14" y="3" width="7" height="7" /><rect x="14" y="14" width="7" height="7" /><rect x="3" y="14" width="7" height="7" /></svg>
            Dashboard
        </Link>
        <Link :href="route('clients.index')" :class="navLinkClass(route().current('clients.*'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3" /><path d="M3 20c0-3.2 2.9-5 6-5s6 1.8 6 5" /><path d="M17 5a3 3 0 0 1 0 6" /><path d="M19.5 19.6c-.2-1.9-1.2-3.3-2.7-4.1" /></svg>
            Clients
        </Link>
        <Link :href="route('document-requests.index')" :class="navLinkClass(route().current('document-requests.*'))">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="M9 13h6M9 17h4" /></svg>
            Document Requests
        </Link>

        <div class="relative mt-auto border-t border-divider pt-3">
            <button
                type="button"
                :aria-expanded="userMenuOpen"
                class="flex w-full items-center gap-2.5 rounded-control p-2 text-left hover:bg-steel-100"
                @click="userMenuOpen = !userMenuOpen"
            >
                <span class="grid h-8.5 w-8.5 flex-none place-items-center rounded-control bg-accent-100 font-heading text-sm text-accent-800">
                    {{ initials(page.props.auth.user.name) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ page.props.auth.user.name }}</span>
                    <span class="block truncate text-xs text-steel-600">{{ page.props.auth.user.email }}</span>
                </span>
            </button>
            <div
                v-if="userMenuOpen"
                class="absolute inset-x-2 bottom-[58px] z-10 rounded-card border border-divider bg-white p-1.5 shadow-md"
            >
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="block w-full rounded px-2.5 py-2 text-left text-sm text-[#9a3324] hover:bg-steel-100"
                >
                    Log out
                </Link>
            </div>
        </div>
    </aside>
</template>
```

- [ ] **Step 2: Write `MobileDrawer.vue`** — same 3 links + logout, slide-in from the right, backdrop click / Escape closes

```vue
<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { onMounted, onUnmounted } from 'vue';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(['close']);
const page = usePage();

function onKeydown(event) {
    if (event.key === 'Escape' && props.show) emit('close');
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

const navLinkClass = (active) => [
    'flex items-center gap-2.5 rounded-control px-3 py-3 text-[15px] font-medium',
    active ? 'bg-accent-100 text-accent-800' : 'text-steel-700 hover:bg-steel-100',
];
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 bg-[#1d2d3d]/55" @click="emit('close')" />
        <nav
            v-if="show"
            class="fixed inset-y-0 right-0 z-50 flex w-[82%] max-w-[320px] flex-col bg-white p-4 shadow-lg"
        >
            <div class="mb-4 flex items-center justify-between">
                <span class="font-heading text-sm uppercase tracking-wide text-ink">Menu</span>
                <button
                    type="button"
                    aria-label="Close menu"
                    class="grid h-11 w-11 place-items-center rounded-control border border-divider text-ink"
                    @click="emit('close')"
                >
                    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18" /></svg>
                </button>
            </div>

            <Link :href="route('dashboard')" :class="navLinkClass(route().current('dashboard'))" @click="emit('close')">Dashboard</Link>
            <Link :href="route('clients.index')" :class="navLinkClass(route().current('clients.*'))" @click="emit('close')">Clients</Link>
            <Link :href="route('document-requests.index')" :class="navLinkClass(route().current('document-requests.*'))" @click="emit('close')">Document Requests</Link>

            <div class="mt-auto flex items-center gap-2.5 border-t border-divider pt-4">
                <span class="text-sm text-steel-700">{{ page.props.auth.user.name }}</span>
            </div>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="mt-2 rounded-control px-3 py-3 text-left text-[15px] font-medium text-[#9a3324] hover:bg-steel-100"
            >
                Log out
            </Link>
        </nav>
    </Teleport>
</template>
```

- [ ] **Step 3: Rewrite `AuthenticatedLayout.vue`** in full

```vue
<script setup>
import { ref } from 'vue';
import AppSidebar from '@/Components/AppSidebar.vue';
import MobileDrawer from '@/Components/MobileDrawer.vue';
import ToastStack from '@/Components/ToastStack.vue';

const drawerOpen = ref(false);
</script>

<template>
    <div>
        <div class="flex min-h-screen bg-canvas">
            <AppSidebar class="hidden md:flex" />

            <div class="flex min-w-0 flex-1 flex-col">
                <header class="sticky top-0 z-20 flex items-center gap-3 border-b border-divider bg-white/90 px-4 py-3 backdrop-blur md:hidden">
                    <span class="grid h-6.5 w-6.5 place-items-center rounded-control border border-accent text-accent">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /><path d="M8.5 14l2 2 4-4.5" /></svg>
                    </span>
                    <span class="mr-auto font-heading text-base uppercase tracking-wide text-ink">Document Chaser</span>
                    <button
                        type="button"
                        aria-label="Open menu"
                        :aria-expanded="drawerOpen"
                        class="grid h-11 w-11 place-items-center rounded-control border border-divider text-ink"
                        @click="drawerOpen = true"
                    >
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16" /></svg>
                    </button>
                </header>

                <header v-if="$slots.header" class="border-b border-divider bg-white px-4 py-5 md:px-8">
                    <slot name="header" />
                </header>

                <main class="flex-1 px-4 py-6 md:px-8 md:py-8">
                    <slot />
                </main>
            </div>
        </div>

        <MobileDrawer :show="drawerOpen" @close="drawerOpen = false" />
        <ToastStack />
    </div>
</template>
```

- [ ] **Step 4: Manually verify at 375px, 768px, 1280px** — desktop sidebar shows ≥768px with working nav-active states and the user-menu dropdown toggling; mobile shows the sticky header + hamburger, drawer slides in from the right with the same 3 links + logout, Escape and backdrop-click both close it; page-header slot (used by every authenticated page currently) still renders above `<main>`.

- [ ] **Step 5: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed (this is a pure layout change; no test in this suite asserts on the nav DOM).

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/AppSidebar.vue resources/js/Components/MobileDrawer.vue resources/js/Layouts/AuthenticatedLayout.vue
git commit -m "$(cat <<'EOF'
feat: replace top nav with sidebar and mobile drawer

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

## Phase 3 — Page restyles

### Task 12: GuestLayout and Auth pages

**Files:**
- Modify: `resources/js/Layouts/GuestLayout.vue`
- Modify: `resources/js/Pages/Auth/Login.vue`
- Modify: `resources/js/Pages/Auth/Register.vue`
- Modify: `resources/js/Pages/Auth/ForgotPassword.vue`
- Modify: `resources/js/Pages/Auth/ResetPassword.vue`

**Interfaces:**
- Consumes: `Card`, restyled `TextInput`/`InputLabel`/`InputError`/`Checkbox`/`PrimaryButton` from Tasks 2-4.
- No prop/behavior changes to any auth page — every `useForm`, route name, and field name stays identical; this task is template/class-only.

- [ ] **Step 1: Restyle `GuestLayout.vue`** — centered card on `canvas` background, logo, keep the `ToastStack` added in Task 7

```vue
<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import ToastStack from '@/Components/ToastStack.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="flex min-h-screen flex-col items-center justify-center bg-canvas px-4 py-10">
        <Link href="/" class="mb-6 flex items-center gap-2.5">
            <span class="grid h-7 w-7 place-items-center rounded-control border border-accent text-accent">
                <ApplicationLogo class="h-4 w-4 fill-current" />
            </span>
            <span class="font-heading text-base uppercase tracking-wide text-ink">Document Chaser</span>
        </Link>

        <div class="w-full max-w-[380px] rounded-card border border-divider bg-white p-6 shadow-sm">
            <slot />
        </div>

        <ToastStack />
    </div>
</template>
```

- [ ] **Step 2: Restyle `Login.vue`** — same fields/handlers, new classes:

```vue
<script setup>
import Checkbox from '@/Components/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <h1 class="font-heading text-2xl text-ink">Welcome back</h1>
        <p class="mb-6 mt-1 text-sm text-steel-700">Sign in to manage your document requests.</p>

        <div v-if="status" class="mb-4 text-sm font-medium text-[#1c4a34]">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1" v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <div class="flex items-baseline justify-between gap-2">
                    <InputLabel for="password" value="Password" />
                    <Link v-if="canResetPassword" :href="route('password.request')" class="text-xs text-accent-700 hover:text-accent-900">
                        Forgot password?
                    </Link>
                </div>
                <TextInput id="password" type="password" class="mt-1" v-model="form.password" required autocomplete="current-password" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <label class="flex min-h-[44px] cursor-pointer items-center gap-2.5 text-sm text-ink">
                <Checkbox name="remember" v-model:checked="form.remember" />
                Remember me on this device
            </label>

            <PrimaryButton class="w-full" :disabled="form.processing">Log in</PrimaryButton>
        </form>
    </GuestLayout>
</template>
```

- [ ] **Step 3: Restyle `Register.vue`** — same fields/handlers:

```vue
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Register" />

        <h1 class="font-heading text-2xl text-ink">Create your account</h1>
        <p class="mb-6 mt-1 text-sm text-steel-700">Start sending secure document requests.</p>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <div>
                <InputLabel for="name" value="Name" />
                <TextInput id="name" type="text" class="mt-1" v-model="form.name" required autofocus autocomplete="name" />
                <InputError class="mt-2" :message="form.errors.name" />
            </div>

            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1" v-model="form.email" required autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Password" />
                <TextInput id="password" type="password" class="mt-1" v-model="form.password" required autocomplete="new-password" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm password" />
                <TextInput id="password_confirmation" type="password" class="mt-1" v-model="form.password_confirmation" required autocomplete="new-password" />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <PrimaryButton class="w-full" :disabled="form.processing">Create account</PrimaryButton>

            <p class="text-center text-sm text-steel-700">
                Already have an account? <Link :href="route('login')" class="text-accent-700 hover:text-accent-900">Sign in</Link>
            </p>
        </form>
    </GuestLayout>
</template>
```

- [ ] **Step 4: Restyle `ForgotPassword.vue`**:

```vue
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps({
    status: {
        type: String,
    },
});

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Forgot Password" />

        <h1 class="font-heading text-2xl text-ink">Reset your password</h1>
        <p class="mb-6 mt-1 text-sm leading-relaxed text-steel-700">
            Tell us your email address and we'll send you a link to choose a new password.
        </p>

        <div v-if="status" class="mb-4 text-sm font-medium text-[#1c4a34]">
            {{ status }}
        </div>

        <form @submit.prevent="submit" class="flex flex-col gap-4">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1" v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <PrimaryButton class="w-full" :disabled="form.processing">Email reset link</PrimaryButton>
        </form>
    </GuestLayout>
</template>
```

- [ ] **Step 5: Restyle `ResetPassword.vue`**:

```vue
<script setup>
import GuestLayout from '@/Layouts/GuestLayout.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({
    email: {
        type: String,
        required: true,
    },
    token: {
        type: String,
        required: true,
    },
});

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Reset Password" />

        <h1 class="font-heading text-2xl text-ink">Choose a new password</h1>

        <form @submit.prevent="submit" class="mt-6 flex flex-col gap-4">
            <div>
                <InputLabel for="email" value="Email" />
                <TextInput id="email" type="email" class="mt-1" v-model="form.email" required autofocus autocomplete="username" />
                <InputError class="mt-2" :message="form.errors.email" />
            </div>

            <div>
                <InputLabel for="password" value="Password" />
                <TextInput id="password" type="password" class="mt-1" v-model="form.password" required autocomplete="new-password" />
                <InputError class="mt-2" :message="form.errors.password" />
            </div>

            <div>
                <InputLabel for="password_confirmation" value="Confirm password" />
                <TextInput id="password_confirmation" type="password" class="mt-1" v-model="form.password_confirmation" required autocomplete="new-password" />
                <InputError class="mt-2" :message="form.errors.password_confirmation" />
            </div>

            <PrimaryButton class="w-full" :disabled="form.processing">Reset password</PrimaryButton>
        </form>
    </GuestLayout>
</template>
```

- [ ] **Step 6: Manually verify** all 4 auth flows end-to-end (register → redirected to dashboard; logout; login; forgot password → check queued mail in `storage/logs` or Mailpit if configured; reset password with the emailed token).

- [ ] **Step 7: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 8: Commit**

```bash
git add resources/js/Layouts/GuestLayout.vue resources/js/Pages/Auth/
git commit -m "$(cat <<'EOF'
feat: restyle guest layout and auth pages

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 13: Restyle Dashboard

**Files:**
- Modify: `resources/js/Pages/Dashboard.vue`

**Interfaces:**
- Consumes: `stats` and `recentRequests` props from Task 10, `Card`/`PageHeader`/`StatusTag` from Tasks 3-4, the `.blueprint`/`.corner` CSS from Task 1.

- [ ] **Step 1: Rewrite `Dashboard.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusTag from '@/Components/StatusTag.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    stats: {
        type: Object,
        required: true,
    },
    recentRequests: {
        type: Array,
        required: true,
    },
});

const STATUS_LABELS = {
    draft: 'Draft',
    awaiting_client: 'Awaiting client',
    completed: 'Completed',
    expired: 'Expired',
    archived: 'Archived',
};

function initials(name) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">Dashboard</h2>
        </template>

        <PageHeader title="Dashboard">
            Here's what's happening with your document requests.
            <template #actions>
                <Link :href="route('clients.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control border border-divider bg-white px-4 text-sm font-semibold text-ink hover:bg-steel-100">
                    Add client
                </Link>
                <Link :href="route('document-requests.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    New request
                </Link>
            </template>
        </PageHeader>

        <div class="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Clients</span>
                <span class="font-heading text-3xl leading-none text-ink">{{ stats.clients }}</span>
            </div>
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Active requests</span>
                <span class="font-heading text-3xl leading-none text-ink">{{ stats.activeRequests }}</span>
            </div>
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Pending documents</span>
                <span class="font-heading text-3xl leading-none text-[#8a5a12]">{{ stats.pendingDocuments }}</span>
            </div>
            <div class="blueprint flex flex-col gap-1 rounded-card border border-divider bg-white p-4">
                <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
                <span class="text-[11px] uppercase tracking-wide text-steel-600">Completed</span>
                <span class="font-heading text-3xl leading-none text-ink">{{ stats.completed }}</span>
            </div>
        </div>

        <section class="overflow-hidden rounded-card border border-divider bg-white">
            <div class="flex items-center justify-between border-b border-divider px-4 py-3.5">
                <h2 class="font-heading text-lg text-ink">Recent requests</h2>
                <Link :href="route('document-requests.index')" class="text-sm text-accent-700 hover:text-accent-900">View all</Link>
            </div>
            <p v-if="recentRequests.length === 0" class="px-4 py-8 text-center text-sm text-steel-600">
                No document requests yet.
            </p>
            <Link
                v-for="r in recentRequests"
                :key="r.id"
                :href="route('document-requests.show', r.id)"
                class="flex items-center gap-3 border-b border-divider px-4 py-3.5 last:border-b-0 hover:bg-steel-100/50"
            >
                <span class="grid h-8.5 w-8.5 flex-none place-items-center rounded-control bg-steel-100 font-heading text-sm text-steel-800">
                    {{ initials(r.client_name) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-medium text-ink">{{ r.client_name }}</span>
                    <span class="block text-xs text-steel-600">{{ r.items_received }} of {{ r.items_total }} documents in{{ r.due_at ? ` · Due ${r.due_at}` : '' }}</span>
                </span>
                <StatusTag :label="STATUS_LABELS[r.display_status]" />
            </Link>
        </section>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Manually verify** — visit `/dashboard` with a mix of draft/sent/completed requests and confirm stat counts match, recent list shows ≤5 with correct badges.

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Dashboard.vue
git commit -m "$(cat <<'EOF'
feat: restyle dashboard with stat cards and recent requests

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 14: Restyle Clients/Index

**Files:**
- Modify: `resources/js/Pages/Clients/Index.vue`

**Interfaces:**
- Consumes: `PageHeader`, `EmptyState`, `StatusTag`, `Pagination` (wired in Task 5).

- [ ] **Step 1: Rewrite `Clients/Index.vue`** — CSS-only table→card breakpoint (`hidden md:block` table wrapper, `md:hidden` card list), `StatusTag` for Active/Archived (trivial client-side ternary, no backend change needed — see Global Constraints)

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusTag from '@/Components/StatusTag.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    clients: {
        type: Object,
        required: true,
    },
});

function initials(name) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}
</script>

<template>
    <Head title="Clients" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">Clients</h2>
        </template>

        <PageHeader title="Clients">
            {{ clients.data.length }} client{{ clients.data.length === 1 ? '' : 's' }}
            <template #actions>
                <Link :href="route('clients.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    New client
                </Link>
            </template>
        </PageHeader>

        <EmptyState
            v-if="clients.data.length === 0"
            title="No clients yet"
            description="Add your first client to start sending document requests."
        >
            <template #action>
                <Link :href="route('clients.create')" class="inline-flex min-h-[44px] items-center rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    Add client
                </Link>
            </template>
        </EmptyState>

        <div v-else class="rounded-card border border-divider bg-white">
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[520px] text-sm">
                    <thead>
                        <tr class="border-b border-divider text-left text-xs uppercase tracking-wide text-steel-600">
                            <th class="px-4 py-3 font-medium">Client</th>
                            <th class="px-4 py-3 font-medium">Email</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="client in clients.data" :key="client.id" class="border-b border-divider last:border-b-0 hover:bg-steel-100/50">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 flex-none place-items-center rounded-control bg-accent-100 font-heading text-sm text-accent-800">{{ initials(client.name) }}</span>
                                    <Link :href="route('clients.show', client.id)" class="font-medium text-ink hover:text-accent-700">{{ client.name }}</Link>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-steel-700">{{ client.email }}</td>
                            <td class="px-4 py-3.5"><StatusTag :label="client.archived_at ? 'Archived' : 'Active'" /></td>
                            <td class="px-4 py-3.5 text-right">
                                <Link :href="route('clients.show', client.id)" class="text-sm text-accent-700 hover:text-accent-900">View</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2.5 p-3 md:hidden">
                <Link
                    v-for="client in clients.data"
                    :key="client.id"
                    :href="route('clients.show', client.id)"
                    class="flex min-h-[44px] items-center gap-3 rounded-card border border-divider p-3.5"
                >
                    <span class="grid h-10 w-10 flex-none place-items-center rounded-control bg-accent-100 font-heading text-base text-accent-800">{{ initials(client.name) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[15px] font-medium text-ink">{{ client.name }}</span>
                        <span class="block truncate text-xs text-steel-600">{{ client.email }}</span>
                        <StatusTag class="mt-1" :label="client.archived_at ? 'Archived' : 'Active'" />
                    </span>
                </Link>
            </div>

            <Pagination :links="clients.links" />
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Manually verify** at 375px (cards, no horizontal scroll) and 1024px (table). Verify pagination still works with >15 clients.

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Clients/Index.vue
git commit -m "$(cat <<'EOF'
feat: restyle clients index with responsive table/card layout

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 15: Restyle Clients/Create and Clients/Edit

**Files:**
- Modify: `resources/js/Pages/Clients/Create.vue`
- Modify: `resources/js/Pages/Clients/Edit.vue`

- [ ] **Step 1: Rewrite `Clients/Create.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({
    name: '',
    email: '',
});

const submit = () => {
    form.post(route('clients.store'));
};
</script>

<template>
    <Head title="New Client" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">New client</h2>
        </template>

        <div class="mx-auto max-w-[520px]">
            <Link :href="route('clients.index')" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                Clients
            </Link>
            <Card class="p-6">
                <h1 class="font-heading text-2xl text-ink">New client</h1>
                <form @submit.prevent="submit" class="mt-5 flex flex-col gap-4">
                    <div>
                        <InputLabel for="name" value="Client name" />
                        <TextInput id="name" type="text" class="mt-1" v-model="form.name" required autofocus />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" type="email" class="mt-1" v-model="form.email" required />
                        <InputError class="mt-2" :message="form.errors.email" />
                        <p v-if="!form.errors.email" class="mt-1.5 text-xs text-steel-600">Secure links and reminders are sent here.</p>
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-3">
                        <Link :href="route('clients.index')">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                        <PrimaryButton :disabled="form.processing">Create client</PrimaryButton>
                    </div>
                </form>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Rewrite `Clients/Edit.vue`** (same shape, pre-filled, `Save changes` label, `put` to `clients.update`)

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    client: {
        type: Object,
        required: true,
    },
});

const form = useForm({
    name: props.client.name,
    email: props.client.email,
});

const submit = () => {
    form.put(route('clients.update', props.client.id));
};
</script>

<template>
    <Head title="Edit Client" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">Edit client</h2>
        </template>

        <div class="mx-auto max-w-[520px]">
            <Link :href="route('clients.show', props.client.id)" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                {{ client.name }}
            </Link>
            <Card class="p-6">
                <h1 class="font-heading text-2xl text-ink">Edit client</h1>
                <form @submit.prevent="submit" class="mt-5 flex flex-col gap-4">
                    <div>
                        <InputLabel for="name" value="Client name" />
                        <TextInput id="name" type="text" class="mt-1" v-model="form.name" required autofocus />
                        <InputError class="mt-2" :message="form.errors.name" />
                    </div>

                    <div>
                        <InputLabel for="email" value="Email" />
                        <TextInput id="email" type="email" class="mt-1" v-model="form.email" required />
                        <InputError class="mt-2" :message="form.errors.email" />
                    </div>

                    <div class="mt-2 flex items-center justify-end gap-3">
                        <Link :href="route('clients.show', props.client.id)">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                        <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                    </div>
                </form>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 3: Manually verify** create and edit flows submit correctly and validation errors render.

- [ ] **Step 4: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Pages/Clients/Create.vue resources/js/Pages/Clients/Edit.vue
git commit -m "$(cat <<'EOF'
feat: restyle client create and edit forms

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 16: Restyle Clients/Show

**Files:**
- Modify: `resources/js/Pages/Clients/Show.vue` (build on Task 6's `ConfirmDialog` wiring — do not re-add the old `confirm()` call)

- [ ] **Step 1: Rewrite the template portion of `Clients/Show.vue`**, keeping the `<script setup>` block exactly as landed in Task 6

```vue
<template>
    <Head :title="client.name" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">{{ client.name }}</h2>
        </template>

        <div class="mx-auto max-w-[640px]">
            <Link :href="route('clients.index')" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                Clients
            </Link>

            <div class="rounded-card border border-divider bg-white p-5">
                <div class="flex flex-wrap items-start gap-4">
                    <span class="grid h-14 w-14 flex-none place-items-center rounded-card bg-accent-100 font-heading text-xl text-accent-800">
                        {{ client.name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase() }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <h1 class="font-heading text-2xl text-ink">{{ client.name }}</h1>
                        <p class="mt-1 break-all text-sm text-steel-700">{{ client.email }}</p>
                        <StatusTag class="mt-2" :label="client.archived_at ? 'Archived' : 'Active'" />
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Link :href="route('clients.edit', client.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <PrimaryButton
                            v-if="!client.archived_at"
                            type="button"
                            :disabled="archiveForm.processing"
                            @click="openArchiveConfirm"
                        >
                            Archive
                        </PrimaryButton>
                    </div>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :show="confirmingArchive"
            title="Archive this client?"
            body="Their document requests will remain but the client can no longer be edited."
            confirm-label="Archive"
            danger
            :processing="archiveForm.processing"
            @confirm="archive"
            @cancel="confirmingArchive = false"
        />
    </AuthenticatedLayout>
</template>
```

Add `import StatusTag from '@/Components/StatusTag.vue';` to the `<script setup>` block (everything else in the script stays as Task 6 left it).

- [ ] **Step 2: Manually verify** the archive confirm flow and that the page reads correctly for both an active and an already-archived client (Archive button hidden once archived).

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Clients/Show.vue
git commit -m "$(cat <<'EOF'
feat: restyle client detail page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 17: Restyle DocumentRequests/Index

**Files:**
- Modify: `resources/js/Pages/DocumentRequests/Index.vue`

**Interfaces:**
- Consumes: `display_status` from Task 9, `StatusTag`, `EmptyState`, `PageHeader`, `Pagination`.

- [ ] **Step 1: Rewrite `DocumentRequests/Index.vue`**

```vue
<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import EmptyState from '@/Components/EmptyState.vue';
import StatusTag from '@/Components/StatusTag.vue';
import Pagination from '@/Components/Pagination.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps({
    documentRequests: {
        type: Object,
        required: true,
    },
});

const STATUS_LABELS = {
    draft: 'Draft',
    awaiting_client: 'Awaiting client',
    completed: 'Completed',
    expired: 'Expired',
    archived: 'Archived',
};

function initials(name) {
    return name.split(' ').map((p) => p[0]).slice(0, 2).join('').toUpperCase();
}
</script>

<template>
    <Head title="Document Requests" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">Document Requests</h2>
        </template>

        <PageHeader title="Document Requests">
            {{ documentRequests.data.length }} request{{ documentRequests.data.length === 1 ? '' : 's' }}
            <template #actions>
                <Link :href="route('document-requests.create')" class="inline-flex min-h-[44px] items-center gap-2 rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                    New request
                </Link>
            </template>
        </PageHeader>

        <EmptyState
            v-if="documentRequests.data.length === 0"
            title="No document requests yet"
            description="Create your first request and send it to a client. They upload, you stop chasing."
        >
            <template #action>
                <Link :href="route('document-requests.create')" class="inline-flex min-h-[44px] items-center rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    Create request
                </Link>
            </template>
        </EmptyState>

        <div v-else class="rounded-card border border-divider bg-white">
            <div class="hidden overflow-x-auto md:block">
                <table class="w-full min-w-[560px] text-sm">
                    <thead>
                        <tr class="border-b border-divider text-left text-xs uppercase tracking-wide text-steel-600">
                            <th class="px-4 py-3 font-medium">Client</th>
                            <th class="px-4 py-3 font-medium">Status</th>
                            <th class="px-4 py-3 font-medium">Documents</th>
                            <th class="px-4 py-3 font-medium">Due</th>
                            <th class="px-4 py-3 font-medium">Expires</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="r in documentRequests.data" :key="r.id" class="border-b border-divider last:border-b-0 hover:bg-steel-100/50">
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2.5">
                                    <span class="grid h-8 w-8 flex-none place-items-center rounded-control bg-steel-100 font-heading text-sm text-steel-800">{{ initials(r.client.name) }}</span>
                                    <Link :href="route('document-requests.show', r.id)" class="font-medium text-ink hover:text-accent-700">{{ r.client.name }}</Link>
                                </div>
                            </td>
                            <td class="px-4 py-3.5"><StatusTag :label="STATUS_LABELS[r.display_status]" /></td>
                            <td class="px-4 py-3.5 text-steel-700">{{ r.items_count }} item{{ r.items_count === 1 ? '' : 's' }}</td>
                            <td class="px-4 py-3.5 text-steel-700">{{ r.due_at ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-steel-700">{{ r.expires_at ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <Link :href="route('document-requests.show', r.id)" class="text-sm text-accent-700 hover:text-accent-900">View</Link>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex flex-col gap-2.5 p-3 md:hidden">
                <Link
                    v-for="r in documentRequests.data"
                    :key="r.id"
                    :href="route('document-requests.show', r.id)"
                    class="block rounded-card border border-divider p-3.5"
                >
                    <div class="flex items-start gap-2.5">
                        <span class="grid h-9 w-9 flex-none place-items-center rounded-control bg-steel-100 font-heading text-sm text-steel-800">{{ initials(r.client.name) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[15px] font-medium text-ink">{{ r.client.name }}</span>
                            <span class="block text-xs text-steel-600">{{ r.items_count }} item{{ r.items_count === 1 ? '' : 's' }}</span>
                        </span>
                        <StatusTag :label="STATUS_LABELS[r.display_status]" />
                    </div>
                    <div class="mt-2.5 flex flex-wrap gap-3 text-xs text-steel-700">
                        <span>Due {{ r.due_at ?? '—' }}</span>
                        <span>Expires {{ r.expires_at ?? '—' }}</span>
                    </div>
                </Link>
            </div>

            <Pagination :links="documentRequests.links" />
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 2: Manually verify** at 375px and 1024px, including that draft/awaiting-client/completed/expired/archived requests each show the correct colored badge.

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/DocumentRequests/Index.vue
git commit -m "$(cat <<'EOF'
feat: restyle document requests index with status tags

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 18: Restyle DocumentRequests/Create and Edit (+ ClientPreviewPanel)

**Files:**
- Create: `resources/js/Components/ClientPreviewPanel.vue`
- Modify: `resources/js/Pages/DocumentRequests/Create.vue`
- Modify: `resources/js/Pages/DocumentRequests/Edit.vue`

**Interfaces:**
- Produces: `ClientPreviewPanel` — props `clientName` (`String`, nullable), `clientEmail` (`String`, nullable), `message` (`String`), `items` (`Array<{ name: String }>`), `dueAt` (`String`, nullable), `expiresAt` (`String`, nullable). Pure presentational, no form mutation — consumed by both Create and Edit.

- [ ] **Step 1: Write `ClientPreviewPanel.vue`**

```vue
<script setup>
defineProps({
    clientName: {
        type: String,
        default: null,
    },
    clientEmail: {
        type: String,
        default: null,
    },
    message: {
        type: String,
        default: '',
    },
    items: {
        type: Array,
        default: () => [],
    },
    dueAt: {
        type: String,
        default: null,
    },
    expiresAt: {
        type: String,
        default: null,
    },
});
</script>

<template>
    <aside class="rounded-card border border-dashed border-accent-400 bg-accent-100 p-5">
        <span class="text-[11px] uppercase tracking-wide text-accent-800">What your client receives</span>
        <div class="mt-3.5 rounded-card border border-divider bg-white p-4.5">
            <div class="flex items-center gap-2 border-b border-divider pb-3.5">
                <span class="grid h-5.5 w-5.5 place-items-center rounded border border-accent text-accent">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                </span>
                <span class="font-heading text-xs uppercase tracking-wide text-steel-700">Secure upload portal</span>
            </div>
            <p class="mb-0.5 mt-3.5 text-[11px] uppercase tracking-wide text-steel-600">Request for</p>
            <p class="font-heading text-lg text-ink">{{ clientName || 'Select a client' }}</p>
            <p v-if="clientEmail" class="mt-0.5 break-all text-xs text-steel-600">{{ clientEmail }}</p>
            <p v-if="message" class="mt-3.5 rounded-r border-l-2 border-accent-400 bg-canvas p-2.5 text-sm text-steel-800">{{ message }}</p>
            <p class="mb-2 mt-3.5 text-[11px] uppercase tracking-wide text-steel-600">Requested documents</p>
            <div class="flex flex-col gap-1.5">
                <span v-for="(item, index) in items" :key="index" class="flex items-center gap-2 text-sm text-steel-800">
                    <span class="h-3.5 w-3.5 flex-none rounded-full border-[1.5px] border-steel-400"></span>
                    {{ item.name || `Document ${index + 1}` }}
                </span>
            </div>
            <div class="mt-4 flex gap-5 border-t border-divider pt-3.5">
                <span><span class="block text-[11px] uppercase tracking-wide text-steel-600">Due</span><span class="text-sm">{{ dueAt || '—' }}</span></span>
                <span><span class="block text-[11px] uppercase tracking-wide text-steel-600">Expires</span><span class="text-sm">{{ expiresAt || '—' }}</span></span>
            </div>
        </div>
        <p class="mt-3.5 text-xs leading-relaxed text-accent-800">Nothing is sent yet. You can review and send the request from its detail page.</p>
    </aside>
</template>
```

- [ ] **Step 2: Rewrite `DocumentRequests/Create.vue`**

```vue
<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';
import EmptyState from '@/Components/EmptyState.vue';
import ClientPreviewPanel from '@/Components/ClientPreviewPanel.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    clients: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    client_id: '',
    message: '',
    due_at: '',
    expires_at: '',
    items: [{ name: '' }],
});

const addItem = () => form.items.push({ name: '' });
const removeItem = (index) => form.items.splice(index, 1);

const submit = () => {
    form.post(route('document-requests.store'));
};

const selectedClient = computed(() => props.clients.find((c) => String(c.id) === String(form.client_id)) ?? null);
</script>

<template>
    <Head title="New Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">New Document Request</h2>
        </template>

        <EmptyState
            v-if="clients.length === 0"
            title="You need a client first"
            description="Add a client before creating a document request — the secure link is sent to their email."
        >
            <template #action>
                <Link :href="route('clients.create')" class="inline-flex min-h-[44px] items-center rounded-control bg-accent px-4 text-sm font-semibold text-white hover:bg-accent-600">
                    Add client
                </Link>
            </template>
        </EmptyState>

        <div v-else>
            <Link :href="route('document-requests.index')" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                Document requests
            </Link>

            <div class="flex flex-wrap items-start gap-5">
                <Card class="min-w-0 flex-1 basis-[380px] p-5">
                    <form @submit.prevent="submit" class="flex flex-col gap-4.5">
                        <div>
                            <InputLabel for="client_id" value="Client" />
                            <SelectInput id="client_id" class="mt-1" v-model="form.client_id" required>
                                <option value="" disabled>Select a client</option>
                                <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                            </SelectInput>
                            <InputError class="mt-2" :message="form.errors.client_id" />
                        </div>

                        <div>
                            <InputLabel for="message">Message to the client <span class="text-steel-500">— optional</span></InputLabel>
                            <TextareaInput id="message" class="mt-1" v-model="form.message" rows="3" placeholder="Anything they should know before uploading." />
                            <InputError class="mt-2" :message="form.errors.message" />
                        </div>

                        <div class="flex flex-wrap gap-3.5">
                            <div class="min-w-0 flex-1 basis-[150px]">
                                <InputLabel for="due_at">Due date <span class="text-steel-500">— optional</span></InputLabel>
                                <TextInput id="due_at" type="date" class="mt-1" v-model="form.due_at" />
                                <InputError class="mt-2" :message="form.errors.due_at" />
                            </div>
                            <div class="min-w-0 flex-1 basis-[150px]">
                                <InputLabel for="expires_at">Link expires <span class="text-steel-500">— optional</span></InputLabel>
                                <TextInput id="expires_at" type="date" class="mt-1" v-model="form.expires_at" />
                                <InputError class="mt-2" :message="form.errors.expires_at" />
                            </div>
                        </div>

                        <fieldset class="border-0 p-0">
                            <legend class="mb-2.5 font-heading text-lg text-ink">Requested documents</legend>
                            <div class="flex flex-col gap-2.5">
                                <div v-for="(item, index) in form.items" :key="`new-${index}`" class="flex items-start gap-2.5 rounded-control border border-divider bg-canvas p-2.5">
                                    <span class="mt-2 grid h-6.5 w-6.5 flex-none place-items-center rounded border border-divider bg-white font-heading text-xs text-steel-700">{{ index + 1 }}</span>
                                    <div class="min-w-0 flex-1">
                                        <label :for="`items-${index}-name`" class="mb-1 block text-[11px] uppercase tracking-wide text-steel-600">Document {{ index + 1 }}</label>
                                        <TextInput :id="`items-${index}-name`" type="text" v-model="item.name" placeholder="e.g. Bank statement" required />
                                        <InputError class="mt-1.5" :message="form.errors[`items.${index}.name`]" />
                                    </div>
                                    <button
                                        type="button"
                                        aria-label="Remove document"
                                        class="mt-2 grid h-9.5 w-9.5 flex-none place-items-center rounded-control border border-divider text-steel-700 hover:border-[#9a3324] hover:text-[#9a3324] disabled:cursor-not-allowed disabled:opacity-40"
                                        :disabled="form.items.length === 1"
                                        @click="removeItem(index)"
                                    >
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13" /></svg>
                                    </button>
                                </div>
                            </div>
                            <InputError class="mt-2" :message="form.errors.items" />
                            <button type="button" class="mt-3 flex min-h-[44px] w-full items-center justify-center gap-2 rounded-control border border-divider bg-white text-sm font-semibold text-ink hover:bg-steel-100" @click="addItem">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                                Add document
                            </button>
                        </fieldset>

                        <div class="mt-1 flex items-center justify-end gap-3">
                            <Link :href="route('document-requests.index')">
                                <SecondaryButton type="button">Cancel</SecondaryButton>
                            </Link>
                            <PrimaryButton :disabled="form.processing">Create request</PrimaryButton>
                        </div>
                    </form>
                </Card>

                <ClientPreviewPanel
                    class="min-w-0 flex-1 basis-[300px]"
                    :client-name="selectedClient?.name ?? null"
                    :client-email="selectedClient?.email ?? null"
                    :message="form.message"
                    :items="form.items"
                    :due-at="form.due_at"
                    :expires-at="form.expires_at"
                />
            </div>
        </div>
    </AuthenticatedLayout>
</template>
```

Note: `selectedClient?.email` requires the `clients` prop to include `email`. Check `DocumentRequestController::create()` — it currently sends `->map->only(['id', 'name'])`. Add `'email'` to that projection as part of this task (a payload addition, not a behavior change — no test asserts the absence of `email` here; confirm via `grep -n "clients" tests/Feature/Http/DocumentRequestControllerTest.php` before editing, then add `'email'` to both the `create()` and `edit()` `->only([...])` calls in `app/Http/Controllers/DocumentRequestController.php`).

- [ ] **Step 3: Add `email` to the clients payload** — in `app/Http/Controllers/DocumentRequestController.php`, change both occurrences of:

```php
            ->get()
            ->map->only(['id', 'name']);
```

to:

```php
            ->get()
            ->map->only(['id', 'name', 'email']);
```

(one in `create()`, one in `edit()`).

- [ ] **Step 4: Rewrite `DocumentRequests/Edit.vue`** — identical structure to Create, pre-filled, `Save changes` label, includes `item.id` in the items array so the update diff logic in the controller keeps working unchanged:

```vue
<script setup>
import { computed } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';
import ClientPreviewPanel from '@/Components/ClientPreviewPanel.vue';
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import SecondaryButton from '@/Components/SecondaryButton.vue';
import SelectInput from '@/Components/SelectInput.vue';
import TextareaInput from '@/Components/TextareaInput.vue';
import TextInput from '@/Components/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps({
    documentRequest: {
        type: Object,
        required: true,
    },
    clients: {
        type: Array,
        required: true,
    },
});

const form = useForm({
    client_id: props.documentRequest.client_id,
    message: props.documentRequest.message ?? '',
    due_at: props.documentRequest.due_at ?? '',
    expires_at: props.documentRequest.expires_at ?? '',
    items: props.documentRequest.items.map((item) => ({ id: item.id, name: item.name })),
});

const addItem = () => form.items.push({ name: '' });
const removeItem = (index) => form.items.splice(index, 1);

const submit = () => {
    form.put(route('document-requests.update', props.documentRequest.id));
};

const selectedClient = computed(() => props.clients.find((c) => String(c.id) === String(form.client_id)) ?? null);
</script>

<template>
    <Head title="Edit Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">Edit Document Request</h2>
        </template>

        <Link :href="route('document-requests.show', props.documentRequest.id)" class="mb-4 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
            Document request
        </Link>

        <div class="flex flex-wrap items-start gap-5">
            <Card class="min-w-0 flex-1 basis-[380px] p-5">
                <form @submit.prevent="submit" class="flex flex-col gap-4.5">
                    <div>
                        <InputLabel for="client_id" value="Client" />
                        <SelectInput id="client_id" class="mt-1" v-model="form.client_id" required>
                            <option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option>
                        </SelectInput>
                        <InputError class="mt-2" :message="form.errors.client_id" />
                    </div>

                    <div>
                        <InputLabel for="message">Message to the client <span class="text-steel-500">— optional</span></InputLabel>
                        <TextareaInput id="message" class="mt-1" v-model="form.message" rows="3" />
                        <InputError class="mt-2" :message="form.errors.message" />
                    </div>

                    <div class="flex flex-wrap gap-3.5">
                        <div class="min-w-0 flex-1 basis-[150px]">
                            <InputLabel for="due_at">Due date <span class="text-steel-500">— optional</span></InputLabel>
                            <TextInput id="due_at" type="date" class="mt-1" v-model="form.due_at" />
                            <InputError class="mt-2" :message="form.errors.due_at" />
                        </div>
                        <div class="min-w-0 flex-1 basis-[150px]">
                            <InputLabel for="expires_at">Link expires <span class="text-steel-500">— optional</span></InputLabel>
                            <TextInput id="expires_at" type="date" class="mt-1" v-model="form.expires_at" />
                            <InputError class="mt-2" :message="form.errors.expires_at" />
                        </div>
                    </div>

                    <fieldset class="border-0 p-0">
                        <legend class="mb-2.5 font-heading text-lg text-ink">Requested documents</legend>
                        <div class="flex flex-col gap-2.5">
                            <div v-for="(item, index) in form.items" :key="item.id ?? `new-${index}`" class="flex items-start gap-2.5 rounded-control border border-divider bg-canvas p-2.5">
                                <span class="mt-2 grid h-6.5 w-6.5 flex-none place-items-center rounded border border-divider bg-white font-heading text-xs text-steel-700">{{ index + 1 }}</span>
                                <div class="min-w-0 flex-1">
                                    <label :for="`items-${index}-name`" class="mb-1 block text-[11px] uppercase tracking-wide text-steel-600">Document {{ index + 1 }}</label>
                                    <TextInput :id="`items-${index}-name`" type="text" v-model="item.name" placeholder="e.g. Bank statement" required />
                                    <InputError class="mt-1.5" :message="form.errors[`items.${index}.name`]" />
                                </div>
                                <button
                                    type="button"
                                    aria-label="Remove document"
                                    class="mt-2 grid h-9.5 w-9.5 flex-none place-items-center rounded-control border border-divider text-steel-700 hover:border-[#9a3324] hover:text-[#9a3324] disabled:cursor-not-allowed disabled:opacity-40"
                                    :disabled="form.items.length === 1"
                                    @click="removeItem(index)"
                                >
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M4 7h16M9 7V5h6v2M6 7l1 13h10l1-13" /></svg>
                                </button>
                            </div>
                        </div>
                        <InputError class="mt-2" :message="form.errors.items" />
                        <button type="button" class="mt-3 flex min-h-[44px] w-full items-center justify-center gap-2 rounded-control border border-divider bg-white text-sm font-semibold text-ink hover:bg-steel-100" @click="addItem">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                            Add document
                        </button>
                    </fieldset>

                    <div class="mt-1 flex items-center justify-end gap-3">
                        <Link :href="route('document-requests.show', props.documentRequest.id)">
                            <SecondaryButton type="button">Cancel</SecondaryButton>
                        </Link>
                        <PrimaryButton :disabled="form.processing">Save changes</PrimaryButton>
                    </div>
                </form>
            </Card>

            <ClientPreviewPanel
                class="min-w-0 flex-1 basis-[300px]"
                :client-name="selectedClient?.name ?? null"
                :client-email="selectedClient?.email ?? null"
                :message="form.message"
                :items="form.items"
                :due-at="form.due_at"
                :expires-at="form.expires_at"
            />
        </div>
    </AuthenticatedLayout>
</template>
```

- [ ] **Step 5: Manually verify** create and edit flows, including adding/removing document items and confirming the live preview panel updates as you type.

- [ ] **Step 6: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed (the `email` payload addition is additive; no existing test asserts the `clients` array's exact key set).

- [ ] **Step 7: Commit**

```bash
git add resources/js/Components/ClientPreviewPanel.vue resources/js/Pages/DocumentRequests/Create.vue resources/js/Pages/DocumentRequests/Edit.vue app/Http/Controllers/DocumentRequestController.php
git commit -m "$(cat <<'EOF'
feat: restyle document request forms with live client preview

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 19: Restyle DocumentRequests/Show

**Files:**
- Modify: `resources/js/Pages/DocumentRequests/Show.vue` (build on Task 6's `ConfirmDialog` wiring)

- [ ] **Step 1: Rewrite the template portion**, keeping the `<script setup>` block exactly as Task 6 left it (archive/send/copyLink logic unchanged)

```vue
<template>
    <Head title="Document Request" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-heading text-xl text-ink">{{ documentRequest.client.name }}</h2>
        </template>

        <div class="mx-auto max-w-[900px]">
            <Link :href="route('document-requests.index')" class="mb-3.5 inline-flex items-center gap-1.5 text-sm text-accent-700 hover:text-accent-900">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M15 6l-6 6 6 6" /></svg>
                Document requests
            </Link>

            <div class="mb-4 rounded-card border border-divider bg-white p-5">
                <div class="flex flex-wrap items-start gap-3.5">
                    <div class="min-w-0 flex-1 basis-[220px]">
                        <div class="mb-2 flex items-center gap-2.5">
                            <StatusTag :label="STATUS_LABELS[documentRequest.display_status]" />
                            <span class="text-xs text-steel-600">Request #{{ documentRequest.id }}</span>
                        </div>
                        <h1 class="font-heading text-2xl text-ink">{{ documentRequest.client.name }}</h1>
                        <p class="break-all text-sm text-steel-700">{{ documentRequest.client.email }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2.5">
                        <Link :href="route('document-requests.edit', documentRequest.id)">
                            <SecondaryButton type="button">Edit</SecondaryButton>
                        </Link>
                        <template v-if="documentRequest.display_status !== 'archived'">
                            <SecondaryButton type="button" class="!text-[#9a3324]" :disabled="archiveForm.processing" @click="openArchiveConfirm">
                                Archive
                            </SecondaryButton>
                            <PrimaryButton type="button" :disabled="sendForm.processing" @click="openSendConfirm">
                                {{ documentRequest.sent_at ? 'Resend' : 'Send request' }}
                            </PrimaryButton>
                        </template>
                    </div>
                </div>
                <p v-if="documentRequest.display_status === 'archived'" class="mt-4 rounded-control border border-steel-300 bg-steel-100 p-3 text-sm leading-relaxed text-steel-800">
                    This request is archived. The secure link no longer works and the client can't submit documents. You can still edit it.
                </p>
            </div>

            <div v-if="documentRequest.display_status !== 'archived'" class="mb-4 rounded-card border border-divider bg-white p-4.5">
                <span class="mb-2.5 block text-[11px] uppercase tracking-wide text-steel-600">Secure link</span>
                <div class="flex flex-wrap items-center gap-2.5">
                    <input
                        type="text"
                        readonly
                        :value="copiedLink ?? ''"
                        placeholder="Copy the link to see it here"
                        aria-label="Secure upload link"
                        class="min-h-[44px] flex-1 basis-[240px] rounded-control border-divider bg-canvas text-sm text-steel-800"
                        @focus="$event.target.select()"
                    />
                    <PrimaryButton type="button" :disabled="copyLinkForm.processing" @click="copyLink">
                        Copy link
                    </PrimaryButton>
                </div>
                <p v-if="$page.props.flash.accessLinkExists && !copiedLink" class="mt-2 text-xs text-steel-600">
                    A secure link already exists for this request.
                </p>
            </div>

            <div class="flex flex-wrap items-start gap-4">
                <section class="min-w-0 flex-1 basis-[340px] overflow-hidden rounded-card border border-divider bg-white">
                    <div class="flex items-center justify-between gap-2.5 border-b border-divider px-4.5 py-3.5">
                        <h2 class="font-heading text-lg text-ink">Requested documents</h2>
                        <span class="text-xs text-steel-700">{{ receivedCount }} of {{ documentRequest.items.length }} received</span>
                    </div>
                    <div v-for="item in documentRequest.items" :key="item.id" class="border-b border-divider px-4.5 py-3.5 last:border-b-0">
                        <div class="flex items-center gap-2.5">
                            <span
                                class="grid h-6.5 w-6.5 flex-none place-items-center rounded-control"
                                :class="item.status === 'received' ? 'bg-[#e7f0ea] text-[#1c4a34]' : 'bg-steel-100 text-steel-500'"
                            >
                                <svg v-if="item.status === 'received'" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                                <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><circle cx="12" cy="12" r="8" /></svg>
                            </span>
                            <span class="min-w-0 flex-1 text-[15px] font-medium text-ink">{{ item.name }}</span>
                            <StatusTag :label="item.status === 'received' ? 'Received' : 'Missing'" />
                        </div>
                        <div v-for="document in item.documents" :key="document.id" class="ml-9 mt-2.5 flex items-center gap-2.5 rounded-control border border-divider bg-canvas p-2.5">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--color-accent-700)" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" class="flex-none"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                            <span class="min-w-0 flex-1 truncate text-sm">{{ document.original_filename }}</span>
                            <span class="flex-none text-xs text-steel-600">{{ Math.round(document.size / 1024) }} KB</span>
                        </div>
                        <p v-if="item.status !== 'received'" class="ml-9 mt-2 text-xs text-steel-600">Nothing uploaded yet.</p>
                    </div>
                </section>

                <aside class="min-w-0 flex-1 basis-[240px] rounded-card border border-divider bg-white p-4.5">
                    <h2 class="mb-3.5 font-heading text-lg text-ink">Overview</h2>
                    <div class="flex flex-col gap-3">
                        <div class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Sent</span>
                            <span class="text-sm">{{ documentRequest.sent_at ? formatDateTime(documentRequest.sent_at) : 'Not sent yet' }}</span>
                        </div>
                        <div v-if="documentRequest.completed_at" class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Completed</span>
                            <span class="text-sm font-medium text-[#1c4a34]">{{ formatDateTime(documentRequest.completed_at) }}</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Due</span>
                            <span class="text-sm">{{ documentRequest.due_at ?? 'None' }}</span>
                        </div>
                        <div class="flex items-baseline justify-between gap-3.5">
                            <span class="text-xs text-steel-600">Expires</span>
                            <span class="text-sm">{{ documentRequest.expires_at ?? 'None' }}</span>
                        </div>
                    </div>
                    <div v-if="documentRequest.message" class="mt-4 border-t border-divider pt-3.5">
                        <span class="mb-1.5 block text-[11px] uppercase tracking-wide text-steel-600">Message</span>
                        <p class="text-sm leading-relaxed text-steel-800">{{ documentRequest.message }}</p>
                    </div>
                </aside>
            </div>
        </div>

        <ConfirmDialog
            :show="confirmingArchive"
            title="Archive this request?"
            body="The client will no longer be able to access the upload link."
            confirm-label="Archive"
            danger
            :processing="archiveForm.processing"
            @confirm="archive"
            @cancel="confirmingArchive = false"
        />
        <ConfirmDialog
            :show="confirmingSend"
            title="Resend this request?"
            body="The previous link will stop working."
            confirm-label="Resend"
            :processing="sendForm.processing"
            @confirm="send"
            @cancel="confirmingSend = false"
        />
    </AuthenticatedLayout>
</template>
```

Add to the `<script setup>` block (append, don't replace, the Task 6 logic): the `StatusTag` import, the `STATUS_LABELS` map, and a `receivedCount` computed:

```js
import StatusTag from '@/Components/StatusTag.vue';
import { computed } from 'vue';

const STATUS_LABELS = {
    draft: 'Draft',
    awaiting_client: 'Awaiting client',
    completed: 'Completed',
    expired: 'Expired',
    archived: 'Archived',
};

const receivedCount = computed(() => props.documentRequest.items.filter((i) => i.status === 'received').length);
```

- [ ] **Step 2: Manually verify** the full lifecycle: create → show (Draft badge, no link box hidden—wait, link box shows for any non-archived status including draft, matching current backend behavior where `accessLink` can be generated pre-send) → send (Awaiting client badge, resend confirm skipped first time) → upload all documents via the public portal → refresh show page (Completed badge, completed timestamp) → archive (Archived badge, link box disappears, banner shows).

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/DocumentRequests/Show.vue
git commit -m "$(cat <<'EOF'
feat: restyle document request detail page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 20: Restyle Public/DocumentRequest (client portal)

**Files:**
- Modify: `resources/js/Pages/Public/DocumentRequest.vue`

**Interfaces:**
- No prop or handler changes — `token`, `maxSizeMb`, `allowedExtensions`, `documentRequest` shape, `state` reactive object, `onFileChange`/`upload` functions all stay exactly as they are today. Template/class-only restyle, matching the mockup's upload-card states (picker → uploading progress bar → error) using the actual state machine this component already has (`uploading`/`error`/`received` — there is no separate "ready to submit, not yet uploading" state in the current implementation, since it uploads immediately via the button; do not invent one).

- [ ] **Step 1: Rewrite the template**, keeping the `<script setup>` block byte-for-byte identical to the current file

```vue
<template>
    <Head title="Document Request" />

    <GuestLayout>
        <div class="mx-auto max-w-xl">
            <h1 class="font-heading text-2xl text-ink">Hi, {{ documentRequest.client_name }}</h1>

            <div class="mt-3 flex items-center gap-2 rounded-control border border-divider bg-white p-3 text-sm text-steel-800">
                <span class="grid h-7.5 w-7.5 flex-none place-items-center rounded-control bg-steel-100 font-heading text-xs text-steel-800">DC</span>
                <span>Requested by Document Chaser</span>
            </div>

            <p v-if="documentRequest.message" class="mt-4 rounded-r-control border-l-[3px] border-accent bg-white p-3.5 text-sm leading-relaxed text-steel-800">
                {{ documentRequest.message }}
            </p>

            <div
                v-if="documentRequest.status === 'completed'"
                class="mt-5 flex items-start gap-3 rounded-card border border-[#b9d3c3] bg-[#e7f0ea] p-4"
            >
                <span class="grid h-9 w-9 flex-none place-items-center rounded-control bg-[#245c41] text-white">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                </span>
                <span>
                    <span class="block font-heading text-lg text-[#1c4a34]">All documents received</span>
                    <span class="mt-0.5 block text-sm leading-relaxed text-[#245c41]">Thank you. Your submission is complete — nothing else is needed.</span>
                </span>
            </div>

            <h2 class="mb-2.5 mt-6 text-xs font-medium uppercase tracking-wide text-steel-600">Documents requested</h2>
            <p v-if="documentRequest.status !== 'completed'" class="mb-3 text-sm text-steel-600">
                Accepted formats: {{ allowedExtensions }}. Maximum size: {{ maxSizeMb }} MB per file.
            </p>

            <div class="flex flex-col gap-3">
                <div
                    v-for="item in documentRequest.items"
                    :key="item.id"
                    class="rounded-card border p-4"
                    :class="state[item.id].received ? 'border-[#b9d3c3] bg-[#f4f9f6]' : 'border-divider bg-white'"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="grid h-6.5 w-6.5 flex-none place-items-center rounded-control"
                            :class="state[item.id].received ? 'bg-[#e7f0ea] text-[#1c4a34]' : 'bg-steel-100 text-steel-500'"
                        >
                            <svg v-if="state[item.id].received" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5" /></svg>
                            <svg v-else width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" /><path d="M14 3v5h5" /></svg>
                        </span>
                        <span class="min-w-0 flex-1 font-heading text-lg leading-tight text-ink">{{ item.name }}</span>
                        <StatusTag :label="state[item.id].received ? 'Received' : 'Missing'" />
                    </div>

                    <p v-if="state[item.id].received" class="ml-9.5 mt-2 text-sm font-medium text-[#245c41]">Uploaded — thank you.</p>

                    <div v-else class="mt-3.5 flex flex-col gap-2.5">
                        <label class="relative flex min-h-[48px] cursor-pointer items-center justify-center gap-2 rounded-control bg-accent px-4 font-heading text-[15px] text-white hover:bg-accent-600">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4" /><path d="M7 9l5-5 5 5" /><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" /></svg>
                            {{ state[item.id].file ? state[item.id].file.name : 'Choose a file' }}
                            <input
                                type="file"
                                class="absolute h-px w-px opacity-0"
                                :accept="allowedExtensionsAttr"
                                :aria-label="`Upload file for ${item.name}`"
                                @change="onFileChange(item.id, $event)"
                            />
                        </label>
                        <button
                            type="button"
                            class="flex min-h-[48px] w-full items-center justify-center rounded-control border border-divider bg-white text-[15px] font-semibold text-ink disabled:cursor-not-allowed disabled:opacity-50"
                            :disabled="state[item.id].uploading || !state[item.id].file"
                            @click="upload(item.id)"
                        >
                            {{ state[item.id].uploading ? 'Uploading…' : 'Upload this document' }}
                        </button>
                    </div>

                    <p v-if="state[item.id].error" class="mt-2.5 flex items-start gap-2 rounded-control border border-[#edc9c2] bg-[#fbecea] p-2.5 text-sm text-[#7d2a1d]">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" class="mt-0.5 flex-none"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16h.01" /></svg>
                        {{ state[item.id].error }}
                    </p>
                </div>
            </div>

            <div class="mt-6 flex flex-wrap gap-x-5 gap-y-1.5 border-t border-divider pt-4 text-xs text-steel-700">
                <span v-if="documentRequest.due_at">Due <strong class="font-medium">{{ formatDate(documentRequest.due_at) }}</strong></span>
                <span v-if="documentRequest.expires_at">Link expires <strong class="font-medium">{{ formatDate(documentRequest.expires_at) }}</strong></span>
                <span class="basis-full text-steel-600">Uploads are private. Questions? Reply to the email that sent you this link.</span>
            </div>
        </div>
    </GuestLayout>
</template>
```

Add `import StatusTag from '@/Components/StatusTag.vue';` to the existing `<script setup>` block (no other script changes).

- [ ] **Step 2: Manually verify** the full upload flow via a real generated link: pick a file, upload, see it flip to "Uploaded — thank you", upload the last item and see the completion banner appear on refresh; also verify the expired-link and generic-error paths still render the Blade `404` view (Task 21) rather than this Vue page — those are server-side `abort(404)` responses, unaffected by this task.

- [ ] **Step 3: Run tests**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed — in particular `tests/Feature/Http/PublicUploadTest.php` and `tests/Feature/Http/ClientRequestControllerTest.php`, since this is the highest-consequence surface for the `assertDontSee(storage_path/user_id)` security assertions (Global Constraints) — this task adds no new payload fields, so they must still pass.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Public/DocumentRequest.vue
git commit -m "$(cat <<'EOF'
feat: restyle public upload portal

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

### Task 21: Restyle the Blade "link unavailable" error page

**Files:**
- Modify: `resources/views/errors/404.blade.php`

**Interfaces:**
- Must preserve the exact string `"This link isn't available"` verbatim (asserted in `tests/Feature/Http/ClientRequestControllerTest.php:67,76`). This page is plain server-rendered HTML with an inline `<style>` block — no `@vite`, no Tailwind classes, since it must render correctly even if the asset pipeline is unavailable (this is an error path).

- [ ] **Step 1: Rewrite `404.blade.php`**, hardcoding the token values (a deliberate, documented duplication — see the comment)

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Link unavailable</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        /* Token values duplicated from resources/css/app.css :root — this page
           intentionally has no @vite/build dependency, since it is an error
           path that must render even if the asset pipeline is broken. */
        body {
            margin: 0;
            padding: 2rem;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f2f2f3;
            color: #1d1f20;
            font-family: Barlow, system-ui, sans-serif;
        }
        .card {
            max-width: 26rem;
            width: 100%;
            text-align: center;
            background: #fff;
            border: 1px solid color-mix(in srgb, #1d1f20 16%, transparent);
            border-radius: 8px;
            padding: 2rem 1.75rem;
        }
        .icon {
            display: grid;
            place-items: center;
            width: 52px;
            height: 52px;
            margin: 0 auto 0.75rem;
            border-radius: 8px;
            background: #f7efe1;
            color: #7a5312;
        }
        h1 {
            font-family: "Barlow Condensed", system-ui, sans-serif;
            font-weight: 600;
            font-size: 1.375rem;
            margin: 0 0 0.5rem;
        }
        p {
            margin: 0;
            color: #5d5d60;
            line-height: 1.55;
        }
    </style>
</head>
<body>
    <div class="card">
        <span class="icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7.5v5l3 2"></path></svg>
        </span>
        <h1>This link isn't available</h1>
        <p>It may have expired or been withdrawn. Please contact the person who sent it to you.</p>
    </div>
</body>
</html>
```

- [ ] **Step 2: Run the specific test that pins this copy**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test tests/Feature/Http/ClientRequestControllerTest.php
```
Expected: PASS, all tests in that file.

- [ ] **Step 3: Manually verify** by visiting an invalid `/request/{garbage-token}` URL in a browser and confirming the styled error card renders.

- [ ] **Step 4: Run the full suite**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed.

- [ ] **Step 5: Commit**

```bash
git add resources/views/errors/404.blade.php
git commit -m "$(cat <<'EOF'
feat: restyle link-unavailable error page

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_015N7Z5nGUivuHWbn2oHkupv
EOF
)"
```

---

## Phase 4 — Final checks

### Task 22: Full regression pass and branch finish-out

**Files:** none (verification-only task)

- [ ] **Step 1: Run the full backend test suite one more time**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php artisan test
```
Expected: 217 passed, 0 failed.

- [ ] **Step 2: Run Pint (the project's configured linter, if present) and fix any reported issues**

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 php vendor/bin/pint --test
```
If it reports fixable issues, run without `--test` to apply them, then re-run the full test suite.

- [ ] **Step 3: Full manual pass across every screen** at 390px, 430px, 834px, 1280px, and 1440px viewport widths (the mockup's own breakpoints): login, register, forgot/reset password, dashboard, clients index/create/edit/show (including archive confirm + toast), document requests index/create/edit/show (including archive/resend confirm, copy link, send, and viewing a completed request), the public upload portal for a draft/awaiting-client/completed/expired request, and the link-unavailable error page. Confirm no horizontal overflow at any width and no console errors.

- [ ] **Step 4: Rebuild the production Vite bundle to confirm no build-time errors from the redesign** (the dev-server `hot` file bypasses this during local testing, but a stale manifest would break production):

```bash
docker exec -w /var/www/html/.claude/worktrees/redesign-industry-ui client-document-chaser-app-1 sh -c "cd node_modules/.bin && ./vite build --config ../../vite.config.js" 2>&1 || echo "Build check needs node -- verify manually if this environment lacks a build-capable shell"
```
If no build-capable shell is available in this container, confirm this step with the user before considering the task done — do not skip verifying the production build silently.

- [ ] **Step 5: Report to the user** what changed (link to this plan file), the final test count, and explicitly flag: (a) the `display_status` and Dashboard stats additions as the two backend changes beyond pure styling, (b) that no automated Vue component tests exist or were added (Global Constraints), (c) any manual-verification findings from Step 3.

- [ ] **Step 6: Hand off to `superpowers:finishing-a-development-branch`** to decide how this branch gets integrated (PR vs. merge vs. further review) — do not merge or push unilaterally.

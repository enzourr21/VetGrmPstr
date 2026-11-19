---
name: ui-design
description: >-
  Design, build, refine, audit, or review PawCariño frontend interfaces, visual identity,
  layout hierarchy, typography, UX copy, responsive behavior, accessibility, materials/depth,
  and UI motion/animations. Activate this skill when creating or editing PHP views, CSS styles,
  design tokens, JavaScript UI interactions, transitions, or animations across customer-facing
  pages and staff workspaces, or when auditing/reviewing existing UI and motion code.
  Not for backend-only PHP services, database migrations, or pure server logic.
---

# PawCariño UI Design & Engineering

Use this skill for all frontend design, implementation, refinement, motion engineering, and UI/animation review work in PawCariño. Every interface decision should combine a clear point of view, uncompromising craft in unseen details, and strict fidelity to the project's established design system and surface mode.

## Core Principles

- **The brief and established truth win.** Honor explicit product requirements, surface constraints, and PawCariño's established design tokens even when they conflict with generic pattern warnings. Missing `DESIGN.md` does not make a surface greenfield; inspect the live CSS, views, and JS first.
- **Refinement preserves; redesign replaces.** When refining an existing page or component, preserve its identity, behavior, and verified copy outside the requested scope. Ask before replacing factual copy or adding claims. When doing a full redesign, keep product truth and constraints while replacing the discarded look cleanly rather than polishing half-measures.
- **Scale ambition to the surface mode.** Choose the mode per surface, not per product:
  - **Persuade** *(landing pages, unit showcases, CTAs)*: earn attention and action with strong hierarchy and domain-authentic visual identity.
  - **Operate** *(dashboards, admin/manager/receptionist/delivery workspaces, forms, carts, checkout, bookings)*: prioritize scanability, consistency, speed, and task completion; brand lives in precise details.
  - **Read** *(guides, clinical/grooming records, order details, help)*: optimize for comprehension, comfortable line length, and typographic rhythm.
  - **Experience** *(galleries, artifact-led showcases)*: let the primary media/artifact lead while chrome recedes.
- **Keep loaded symbols out of decoration.** Do not use emblems tied to militarism, supremacy, or hate movements as motifs, badges, or ornament.
- **Verify at shipped sizes.** Inspect rendered output across desktop and mobile viewports and batch fixes rather than tweaking blindly.

## PawCariño Frontend Context

- **Stack:** Server-rendered PHP 8.3 views ([app/Views/](../../../app/Views/)) wrapped by [app/Views/layout.php](../../../app/Views/layout.php), vanilla CSS ([public/assets/css/app.css](../../../public/assets/css/app.css)), and vanilla JavaScript ([public/assets/js/app.js](../../../public/assets/js/app.js)) using Web Animations API (`element.animate()`), Pointer Events, and CSS transitions/`@starting-style`. There is no React, Tailwind, or bundler pipeline.
- **Extend existing tokens — never fork them.** [public/assets/css/app.css](../../../public/assets/css/app.css) already defines the palette (`--bg`, `--surface`, `--brand: #0d6e57`, `--brand-emerald`, `--accent-warm`, `--accent-gold`, semantic `--warn`/`--err`/`--ok`), dark theme (`[data-theme="dark"]` persisted in `localStorage['vetgrpet_theme']`), optical type hierarchy (`system-ui`, inverse leading, size-specific tracking), and motion tokens (`--ease-out`, `--ease-in-out`, `--ease-drawer`, `--ease-apple`, and `--dur-press` 100ms through `--dur-scene` 260ms). Reuse and extend these variables rather than introducing parallel token scales.
- **Customer vs Staff Surfaces:**
  - Customer/public navigation (`/`, `/products`, `/services`, `/staffs`, `/appointments`, `/pets`, `/orders`, `/profile`) includes scenic rails ([app/Views/partials/scenic_rails.php](../../../app/Views/partials/scenic_rails.php)) outside auth sign-in/out and calendar views.
  - Staff workspaces (`/super-admin`, `/sysadmin`, `/manager`, `/petstore/receptionist`, `/grooming/receptionist`, `/vetclinic/receptionist`, `/delivery`, `/staff`) require dense operational clarity, unit-specific navigation, and the Switch Interface profile menu.
- **View & Security Hygiene:** Always escape dynamic output with `e()`, include `<?= \App\Core\CSRF::field() ?>` in POST forms, respect `prefers-reduced-motion` and touch/hover pointer boundaries, and keep Medical vs Grooming UI flows strictly separated.

## Mandatory Reference Consultation

Before writing, editing, auditing, or reviewing UI code, read the relevant reference(s) below:

1. **Visual Direction, Layout, Typography & UX Copy** — Consult [references/visual-design-and-ux.md](references/visual-design-and-ux.md) before designing new pages, restructuring layouts, writing UI copy, or reshaping visual hierarchy. Covers subject-matter grounding, surface modes, avoiding AI-default tells, two-pass design planning, CSS specificity hygiene, UX writing, Apple's eight design principles, and component-craft standards.
2. **Fluid Interactions, Springs, Materials & Optical Typography** — Consult [references/apple-fluid-interfaces.md](references/apple-fluid-interfaces.md) before building gesture-driven UI, drag/swipe sheets, pointer tracking, momentum projection, rubber-banding, translucent `backdrop-filter` chrome, multimodal feedback, reduced-transparency/contrast support, or optical typography tuning.
3. **Building Motion & Animations** — Consult [references/motion-and-animation.md](references/motion-and-animation.md) before adding or modifying any CSS transition, `@keyframes`, WAAPI call, or interactive animation. Covers the frequency gate, purpose check, cheapest-tool ladder, GPU-only properties, canonical cubic-beziers and sub-300ms durations, interruptibility, `clip-path`, blur masking, staggers, reduced motion, and hover gating.
4. **Codebase Motion Audits & Diff Reviews** — Consult [references/animation-audit-and-review.md](references/animation-audit-and-review.md) whenever asked to review UI/motion code, critique a diff, or audit animations across the codebase. Enforces the mandatory `Before | After | Why` table format, the 10 non-negotiable standards, escalation triggers, remedial hierarchy, Block/Approve verdict criteria, and the 4-phase read-only audit/plan workflow.

## Workflow Summary

1. **Identify Mode & Scope:** Determine whether the surface is Persuade, Operate, Read, or Experience, and whether the task is refinement, new surface, redesign, audit, or code review.
2. **Consult References:** Read the applicable reference file(s) in `references/` plus the target view/CSS/JS files before editing.
3. **Plan Before Coding (for new surfaces or redesigns):** Draft a brief design plan (palette tokens, type roles, layout ASCII wireframe, alignment, motion gate) and check it against the brief to strip generic defaults.
4. **Implement Using Project Tokens:** Edit views/CSS/JS cleanly, extending `--brand`/`--ease-*`/`--dur-*` tokens, shipping `prefers-reduced-motion` and `@media (hover: hover) and (pointer: fine)` with every motion rule.
5. **Self-Critique & Feel-Check:** Verify responsive behavior, keyboard focus, contrast in both light and `[data-theme="dark"]`, and inspect non-trivial motion in slow motion (2–5×) or frame-by-frame in browser DevTools.

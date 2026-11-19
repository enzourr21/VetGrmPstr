# UI Motion & Animation Engineering

Authoritative construction guide for building UI transitions, WAAPI sequences, CSS state animations, and micro-interactions in PawCariño (rooted in Emil Kowalski's animation philosophy).

---

## 1. Operating Posture & Hard Rules

Two failure modes exist, and the first is worse:
1. **Animating something that shouldn't animate.** Producing zero animation code for a high-frequency or keyboard-triggered control is a success, not an omission.
2. **Animating the right thing with the wrong ingredients** — `ease-in` on an entrance, `scale(0)`, `@keyframes` on a toast, or >300ms sluggish dropdowns.

**Hard Rules:**
1. **Run the 7-step build sequence in order.** Steps 1 and 2 gate everything; never pick a curve before deciding whether it should animate.
2. **Never approximate easing or timing values.** Use the project CSS tokens (`--ease-out`, `--ease-in-out`, `--ease-drawer`, `--ease-apple`, `--dur-*`) or exact curves below. Never invent `cubic-bezier(0.4, 0, 0.2, 1)` by habit.
3. **Extend existing tokens — never fork them.** Reuse `public/assets/css/app.css` custom properties rather than inventing parallel duration/easing systems.
4. **Ship accessibility with the animation.** `prefers-reduced-motion` and `@media (hover: hover) and (pointer: fine)` ship in the same edit, never as a follow-up.
5. **Cheapest tool that works.** Never add a JS animation library for a fade, state toggle, or simple transition.

---

## 2. The 7-Step Build Sequence

### Step 1: Should This Animate at All?

| Usage Frequency | Decision |
| --- | --- |
| **100+ times/day** (keyboard shortcuts, command palette toggle, rapid staff hotkeys) | **No animation. Ever.** Stop here and use an instant state change. |
| **Tens of times/day** (hover effects, list navigation, table row focus) | Near-imperceptible only — fast and subtle, or nothing. |
| **Occasional** (modals, drawers, toasts, auth stage reveals) | Standard purposeful animation. |
| **Rare / first-time** (onboarding, order/registration completion, celebrations) | The delight budget lives here. |

**Keyboard-initiated actions are disqualified automatically.** If a request fails this frequency gate, decline the animation and ship an instant state change or crisp static affordance instead.

### Step 2: What Is the Purpose?

Name the exact purpose before writing a line of CSS or JS:
- **Feedback:** Confirming the interface heard the user (e.g., button `:active` press scale).
- **Spatial consistency:** Showing where an overlay/toast/drawer came from or returns to.
- **State indication:** Making a state change legible (step chip transition, password visibility blur swap).
- **Preventing a jarring change:** Bridging content that would otherwise teleport (auth progressive stages, inline error reveal).
- **Explanation:** Demonstrating how something works (marketing/onboarding only).
- **Delight:** Allowed *only* in the rare/first-time tier.

*Functional readability check:* Data the user is actively reading or acting on (charts, clinical vitals, POS totals, table cells) must never move decoratively.

### Step 3: Pick the Tool — Cheapest That Works

Walk down the ladder and stop at the first tool that fits:

| Need | Tool |
| --- | --- |
| Hover, press, color, or state toggle controlled via class/attribute | **CSS `transition`** |
| Entry animation on initial mount/unhide with no complex JS choreography | **CSS `@starting-style`** |
| Predetermined motion that must stay smooth while main thread is busy loading | **CSS `@keyframes` animation** (runs off main thread) |
| Programmatic control with CSS/compositor performance, zero dependencies (PawCariño standard for staged reveals/errors) | **WAAPI (`element.animate()`)** |
| Springs, drag momentum, layout transitions, or gesture-interruptible motion | **Pointer Events + rAF / spring math** (or Motion library if ever added) |

*Why CSS/WAAPI beats rAF under load:* Predetermined CSS animations run off the main thread, whereas `requestAnimationFrame`-driven JS drops frames during page loads, script execution, or heavy paints.

### Step 4: Pick the Properties

- **Animate `transform` and `opacity` only.** They skip layout and paint and run on the compositor/GPU. Animating `width`, `height`, `margin`, `padding`, `top`, or `left` triggers layout + paint on every frame.
  - *Sanctioned exceptions:* `clip-path` (compositor-friendly inset reveals/tabs), subtle `filter: blur()` (<20px, short duration for crossfade masking), and `height` *only* for accordions where no transform equivalent exists.
- **Never animate from `scale(0)`.** Nothing in the physical world appears from nothing. Start entrances from `scale(0.9)`–`scale(0.97)` + `opacity: 0`.
- **Origin awareness:** Popovers, dropdowns, menus, and tooltips must scale from their trigger (`transform-origin` pointing toward the trigger, or `var(--transform-origin)`), **never** from `center`. **Modals are exempt** — unanchored viewport modals stay centered.
- **Percentages in `translate()`:** `translateY(100%)` / `translateY(-100%)` move relative to the element's *own* dimensions, adapting automatically to dynamic content height.
- **Never drive child transforms via a parent CSS variable:** Updating `--swipe-amount` on a container forces style recalculation across every child. Set `transform` directly on the moving element.
- **Motion library caveat (if used):** In `motion` >= 14.2, shorthand `x`/`y`/`scale`/`rotate` are hardware-accelerated on HTML elements; before 14.2 use explicit `transform: "translateX(...)"` strings. Layout animations (`layout`, `layoutId`), `onUpdate`, `transformTemplate`, and `useTransform` still run on the main thread.

### Step 5: Easing, Duration & Springs

#### Easing Decision Tree
1. **Entering or exiting?** → `ease-out` (fast start gives instant perceived responsiveness).
2. **Moving or morphing while already on screen?** → `ease-in-out`.
3. **Hover or color change?** → `ease`.
4. **Constant motion (marquee, progress bar)?** → `linear`.
5. **Default?** → `ease-out`.

**Never use `ease-in` on UI interactions.** It delays initial movement at the exact moment the user is watching, making a 200ms–300ms transition feel broken and sluggish.

**Canonical Curves (defined in `public/assets/css/app.css`):**
```css
--ease-out: cubic-bezier(0.23, 1, 0.32, 1);        /* strong ease-out for UI entrances/exits */
--ease-in-out: cubic-bezier(0.77, 0, 0.175, 1);    /* strong ease-in-out for on-screen movement */
--ease-drawer: cubic-bezier(0.32, 0.72, 0, 1);     /* iOS-like drawer curve (Ionic) */
--ease-apple: cubic-bezier(0.16, 1, 0.3, 1);       /* Apple fluid settle */
```
For additional custom curves, source proven values from [easing.dev](https://easing.dev/) or [easings.co](https://easings.co/) instead of guessing.

#### Duration Budgets (UI Rule: Stay Under 300ms)

| Element | Duration | PawCariño Token |
| --- | --- | --- |
| Button `:active` press | `100–160ms` | `--dur-press` (`100ms`) / `--dur-fast` (`160ms`) |
| Tooltips, small popovers | `125–200ms` | `--dur-fast` / `--dur-ui` (`200ms`) |
| Dropdowns, selects, inline errors | `150–250ms` | `--dur-ui` / `--dur-stage` (`220ms`) |
| Modals, drawers, stage scenes | `200–260ms` (max `500ms` large sheets) | `--dur-scene` (`260ms`) |
| Marketing / explanatory | Can be longer when justified | — |

*Perceived performance:*
- A `180ms` dropdown feels noticeably faster than a `400ms` one; `ease-out` at `200ms` feels faster than `ease-in` at `200ms`.
- A faster-spinning loader makes wait times feel shorter.
- Subsequent tooltips in an already-active toolbar should skip both delay and animation (`[data-instant] { transition-duration: 0ms; }`).

#### When to Use Springs
Use spring physics for drag with momentum, interruptible gestures, "alive" UI elements, or decorative pointer interpolation (never decorative motion on functional charts/tables):
- **Apple-style config:** `{ type: "spring", duration: 0.4–0.5, bounce: 0.0–0.2 }` (keep bounce `0.1–0.3` and reserve it for drag/flicks).
- **Physics config:** `{ type: "spring", mass: 1, stiffness: 100, damping: 10 }`.

### Step 6: Interruption & Exit

- **Transitions over `@keyframes` for anything triggered rapidly** (toasts, toggles, dropdowns, accordion states): transitions smoothly retarget from the current value when interrupted; `@keyframes` snap back to zero.
- **Exit the way it entered:** A toast sliding in from the bottom exits through the bottom; a drawer sliding from the right dismisses to the right.
- **Asymmetric timing where the user is deciding:** Slow during deliberate user effort (e.g., hold-to-delete `2s linear`), snappy on system response/release (`200ms ease-out`).

### Step 7: Reduced Motion & Pointer Gating (Mandatory)

```css
@media (prefers-reduced-motion: reduce) {
  .animated-surface {
    transition: opacity 200ms ease, color 200ms ease, background-color 200ms ease;
    transform: none !important;
    animation: none;
  }
}

@media (hover: hover) and (pointer: fine) {
  .card-interactive:hover {
    transform: translateY(-2px);
  }
}
```
```js
const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
```

**Reduced motion means fewer and gentler, not zero:** keep subtle opacity and color transitions that clarify state changes; remove movement, scale, and positional shifts. Touch devices trigger false `:hover` states on tap, so gate hover motion behind `@media (hover: hover) and (pointer: fine)`.

---

## 3. Component & CSS Recipes

### Responsive Pressable Controls
```css
.btn {
  transition: transform var(--dur-fast) var(--ease-out), background-color var(--dur-ui) var(--ease-apple);
}
.btn:active {
  transform: scale(0.97);
  transition-duration: var(--dur-press);
}
```

### Blur-Masked Crossfades & Text Swaps
When two states overlap awkwardly during a crossfade, bridge them with subtle blur (`<= 20px`, ideally `2px`–`4px`; heavy blur is expensive in Safari):
```css
.swap-target {
  transition: filter 140ms ease, opacity 140ms ease;
}
.swap-target.is-blur-swapping {
  filter: blur(2px);
  opacity: 0.72;
}
```

### Entry Animation Without JS State (`@starting-style` + fallback)
```css
.toast {
  opacity: 1;
  transform: translateY(0);
  transition: opacity 220ms var(--ease-out), transform 220ms var(--ease-out);
}
@starting-style {
  .toast {
    opacity: 0;
    transform: translateY(100%);
  }
}
```
When targeting browsers or `hidden`-attribute toggles in PawCariño, pair with WAAPI `el.animate(...)` gated by `!reducedMotion` (see `animateStageIn` in `public/assets/js/app.js`).

### `clip-path: inset()` Patterns
Hardware-accelerated rectangular clipping without extra DOM nodes:
- **Hold-to-confirm / hold-to-delete:** Overlay starts at `clip-path: inset(0 100% 0 0)` (`200ms ease-out` release), transitions to `inset(0 0 0 0)` over `2s linear` on `:active`.
- **Seamless active tab indicator:** Duplicate tab list styled as active, clipped to the active tab bounds with `clip-path: inset(...)`, transitioning `clip-path` on tab switch.
- **Scroll image reveal:** Start `clip-path: inset(0 0 100% 0)` and animate to `inset(0 0 0 0)` once via `IntersectionObserver` (`rootMargin: '-100px'`).
- **Comparison slider:** Clip top image with `inset(0 <pct>% 0 0)` driven directly by pointer drag position.

### 3D Depth & Stagger
- **3D CSS transforms:** Use `rotateX()` / `rotateY()` inside `transform-style: preserve-3d` wrappers for depth cards or orbital showcases.
- **Stagger:** Keep stagger delays to `30–80ms` per item and **never block interaction** while stagger plays. Avoid long cascading delays that make lists feel sluggish.

### Gesture Dismissal Check
Combine distance threshold and flick velocity (`Math.abs(swipeAmount) / elapsedTime > 0.11`) so quick flicks dismiss immediately without requiring full drag distance. Guard against multi-touch finger switching once a drag starts.

---

## 4. Feel-Check & Debugging Protocol

Code alone cannot verify motion feel. Before finishing non-trivial motion:
1. **Slow-motion playback (2–5×):** Temporarily slow durations or use Chrome DevTools **Animations** panel to inspect curves, color blending, `transform-origin`, and property sync.
2. **Frame-by-frame stepping:** Check coordinated opacity + height/transform changes for overlap artifacts or jumps.
3. **Real device testing:** Test touch drawers, popovers, and swipe gestures on physical mobile hardware when possible (simulators miss thumb physics).
4. **Fresh-eyes check:** Revisit transitions after stepping away; imperfections invisible during implementation stand out immediately later.

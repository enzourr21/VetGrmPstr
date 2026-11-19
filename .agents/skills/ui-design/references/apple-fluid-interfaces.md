# Apple Fluid Interfaces, Materials, Gestures & Optical Typography

Physical interaction mechanics, spring math, translucent layering, multimodal harmony, reduced-transparency/contrast accessibility, and size-adaptive typography translated to the web platform (CSS, Pointer Events, WAAPI, `requestAnimationFrame`, and spring physics).

---

## 1. Response — Kill Latency

The moment input lag appears, the feeling of direct manipulation collapses.

- **Respond on pointer-down, not release:** Show press feedback immediately on `:active` / `pointerdown` rather than waiting for `click` / `pointerup`.
  ```css
  .button:active {
    transform: scale(0.97);
    transition: transform 100ms var(--ease-out, cubic-bezier(0.23, 1, 0.32, 1));
  }
  ```
- **Eliminate artificial delays:** Audit debounces, unnecessary `setTimeout` waits, and ~300ms tap delays (`touch-action: manipulation` where appropriate).
- **Continuous feedback during interaction:** For drags, sliders, or drawers, update the UI 1:1 with the pointer throughout the gesture — never animate only after release.

---

## 2. Direct Manipulation — 1:1 Tracking

"Touch and content should move together."

- **Preserve the grab offset:** Never snap an element's center to the pointer on grab; record the initial offset (`e.clientY - rect.top`) and apply deltas from it.
- **Capture the pointer:** Call `el.setPointerCapture(e.pointerId)` on `pointerdown` so tracking continues smoothly if the pointer leaves the element bounds.
- **Record short velocity history:** Keep timestamps and positions of the last few `pointermove` events so release velocity is accurate rather than zeroed by a single micro-jitter frame.

---

## 3. Interruptibility — The Single Most Important Principle

Every animation must be interruptible and redirectable at any instant; never lock out input during a transition.

- **Animate from the presentation (live) value, never the target value:** On interruption, read the element's live on-screen transform (`getComputedStyle(el).transform` or tracked state) and start the new motion from there to prevent jumps.
- **Avoid `@keyframes` (and fixed CSS transitions when gesture-driven) for interactive drag/sheet states:** Keyframes restart from zero and cannot be grabbed mid-flight. Use interruptible transitions for discrete toggles or springs for continuous gestures.
- **Blend velocity on reversal:** Hard-cutting velocity when reversing direction creates a "brick wall" discontinuity. Use spring physics that carry existing velocity through re-targeting.
- **Decompose 2D motion into independent X and Y springs:** A single scalar spring on 2D distance desynchronizes when horizontal and vertical velocities differ.

---

## 4. Behavior Over Animation — Spring Mechanics

A spring is a conversation between user input and object physics: new input changes the target while motion stays continuous.

Think in Apple's two designer-facing parameters:
- **Damping ratio (`1.0` = critically damped):** Controls overshoot. `1.0` settles smoothly with zero bounce; `< 1.0` overshoots and oscillates.
- **Response (seconds):** How quickly the value converges toward the target (lower = snappier). Note that springs have no fixed duration; settle time emerges from physics.

| Interaction | Damping | Response | Web Spring Mapping (`bounce` / `duration`) |
| --- | --- | --- | --- |
| Default UI move / reposition | `1.0` | `0.4` | `bounce: 0, duration: 0.4` |
| Rotation / playful snap | `0.8` | `0.4` | `bounce: 0.2, duration: 0.4` |
| Drawer / bottom sheet | `0.8` | `0.3` | `bounce: 0.2, duration: 0.3` |

**Default rule:** Start most UI at `damping: 1.0` (no bounce). Add subtle bounce (`damping ~0.8` / `bounce: 0.1–0.3`) **only** when the user's gesture itself carried momentum (flick, throw, drag release).

---

## 5. Velocity Handoff & Momentum Projection

### Seamless Velocity Handoff
When a drag ends, initialize the settling animation with the pointer's release velocity so there is no visible seam. For spring APIs that expect normalized relative velocity:

```text
relativeVelocity = gestureVelocity / (targetValue - currentValue)
```

*(Example: element at `y = 50`, target `y = 150` [100px remaining], pointer moving `50px/s` → relative initial velocity = `50 / 100 = 0.5`.)*

### Momentum Projection (Animate to Where the Gesture Is Going)
Rather than snapping to the boundary closest to the *release point*, project the resting position from release velocity using exponential decay (the physics behind good scroll deceleration, bottom sheets, and carousels), then snap to the target nearest that projected endpoint:

```js
// decelerationRate ≈ 0.998 for normal scroll feel; 0.99 for snappier stops
function project(initialVelocity /* px/s */, decelerationRate = 0.998) {
  return (initialVelocity / 1000) * decelerationRate / (1 - decelerationRate);
}

const projectedEndpoint = currentPosition + project(releaseVelocity);
const target = nearestSnapPoint(projectedEndpoint);
// Hand off releaseVelocity into the spring animating to target
```

Use **velocity sign**, not just positional halfway thresholds, when deciding commit vs. cancel on a flick.

---

## 6. Spatial Consistency, Hinting & Rubber-Banding

- **Symmetric Paths:** Enter and exit along the same path (in-from-right exits right; up-from-bottom exits bottom). Mirror easing on reversible transitions where appropriate.
- **Hint in the Direction of the Gesture:** Intermediate frames should telegraph where the action leads rather than interpolating blindly.
- **Rubber-Banding at Boundaries:** Resist progressively past an edge instead of stopping dead (a hard stop reads as frozen):
  ```js
  function rubberband(overshoot, dimension, constant = 0.55) {
    return (overshoot * dimension * constant) / (dimension + constant * Math.abs(overshoot));
  }
  ```

---

## 7. Gesture Design & Frame-Level Smoothness

- **Tap:** Highlight immediately on `pointerdown`, commit on `pointerup`, allow ~10px hit/cancel hysteresis so dragging off cancels safely.
- **Drag / Swipe:** Require a small movement hysteresis (~10px) before locking into a directional drag, then track 1:1.
- **Parallel Detection:** Detect plausible gestures from the first move and cancel losers once intent is clear; avoid final-state-only recognizers that starve continuous tracking.
- **Avoid Double-Tap Delays:** Double-tap disambiguation delays every single tap; omit it unless double-tap genuinely exists.
- **Multi-Touch Protection:** Ignore secondary pointers once a primary drag has started so finger switches do not cause positional jumps.
- **Per-Frame Smoothness:** Keep per-frame jumps below strobing thresholds, animate compositor-only properties (`transform`, `opacity`), and add `will-change` only immediately before/during imminent motion (not permanently across dozens of elements).

---

## 8. Materials, Depth & Layering

Translucent materials establish floating functional hierarchy without stealing focus from underlying content.

```css
.floating-bar {
  background: var(--chrome-bg, rgba(255, 255, 255, 0.82));
  backdrop-filter: blur(20px) saturate(180%);
  -webkit-backdrop-filter: blur(20px) saturate(180%);
  border-bottom: 1px solid rgba(255, 255, 255, 0.4);
}
```

- **Content Scrolls Under Chrome:** Build sticky navbars, toolbars, and sheets as translucent layers rather than opaque strips where appropriate.
- **Material Weight Encodes Hierarchy:** Heavier/darker materials anchor structural sidebars; lighter materials elevate floating interactive controls. **Never stack a light translucent surface directly over another light translucent surface** — legibility collapses.
- **Thickness & Context-Aware Shadows:** Larger floating surfaces need stronger blur and deeper shadows than small pills/chips; deepen shadow slightly when floating over busy text or imagery.
- **Dim to Focus; Separate to Keep Flow:** Blocking modal tasks pair a dimming scrim with background scale/recession. Parallel non-blocking inspectors/panels use translucency and offset *without* a scrim. Progressively dim/push back parent sheets when stacking.
- **Vibrancy:** Over blurred backgrounds, avoid low-contrast flat gray text; use higher contrast, slightly heavier font weight, and a tiny positive tracking bump. Place semantic color fills on solid layers rather than washed-out translucent foregrounds.
- **Scroll Edge Transitions:** Where floating chrome overlaps scrolled content, prefer subtle backdrop blur + soft edge shadow/fade over harsh 1px dividers.
- **Materialize on Entry:** For glass/blur surfaces, coordinate subtle scale (`0.96 → 1`) + opacity (and blur settle where affordable) rather than a bare fade.

---

## 9. Multimodal Feedback & Accessibility Signals

### Multimodal Harmony
When combining visuals with Web Audio or the Vibration API:
1. **Causality:** Trigger feedback on the exact causal moment (snap, toggle, commit) and match its physical character.
2. **Harmony:** Fire visual, audio, and haptic cues on the **same frame**; lag between senses breaks physical believability.
3. **Utility:** Reserve audio/haptics for high-signal moments (success, error, snap, destructive confirm) so users do not tune them out.

### Independent Accessibility Media Queries
Bake all three signals into floating, translucent, and animated components:

```css
@media (prefers-reduced-motion: reduce) {
  .sheet,
  .popover {
    transition: opacity 200ms ease;
    transform: none !important;
  }
}

@media (prefers-reduced-transparency: reduce) {
  .topbar,
  .floating-bar {
    background: var(--surface, #ffffff);
    backdrop-filter: none;
    -webkit-backdrop-filter: none;
  }
}

@media (prefers-contrast: more) {
  .floating-bar,
  .card {
    background: var(--surface, #ffffff);
    border: 1px solid var(--ink, #111827);
  }
}
```

- Avoid full-viewport moving backgrounds, slow looping oscillations (~0.2 Hz / 5s cycles), and abrupt dark↔light brightness flashes. Fade oversized surfaces gently during large repositions.

---

## 10. Optical Typography Discipline

Type changes proportions as size changes (matching Apple's *The Details of UI Typography* and PawCariño's `public/assets/css/app.css` heading rules):

- **Size-Specific Tracking (`letter-spacing`):** Never apply one fixed tracking value across all sizes. Tighten large display headings (`-0.02em` to `-0.03em`), keep body near `0`, and add slight positive tracking (`+0.01em` to `+0.06em`) on tiny captions or uppercase kickers.
- **Inverse Leading (`line-height`):** Tight on large headings (`1.05`–`1.18`), comfortable on body copy (`1.5`–`1.6`). Tighten slightly for dense tabular/operational UI; loosen for tall ascenders or long-form reading.
- **Hierarchy as a Set:** Combine weight, size, color contrast, and leading together — weight creates hierarchy without consuming extra space.
- **Respect Dynamic Text Scaling:** Use `rem` / `em` for font sizes, padding, and gaps so enlarged browser font preferences scale layouts cleanly without clipping.
- **System Font Optical Sizing:** System UI stacks (`system-ui`, `-apple-system`, `Segoe UI`, `Roboto`) already include optical sizing (`font-optical-sizing: auto`) and tuned metrics; override with a custom typeface only for clear brand/editorial reason.

---

## Quick Reference

| Need | Technique | Target Value |
| --- | --- | --- |
| Instant press feedback | `:active` scale on `pointerdown` | `scale(0.97)`, `100ms ease-out` |
| Default UI spring | Critically damped, zero overshoot | `damping 1.0`, `response 0.3–0.4` |
| Flick / momentum spring | Under-damped, slight bounce | `damping ~0.8`, `response 0.3–0.4` |
| Flick landing target | Exponential momentum projection | `current + (v/1000) * d / (1 - d)`, `d ≈ 0.998` |
| Boundary resistance | Progressive rubber-banding | `(overshoot * dim * 0.55) / (dim + 0.55 * |overshoot|)` |
| Interrupt cleanly | Start from live presentation transform | Read current DOM transform |
| Translucent bar | `backdrop-filter` + semi-opaque fill | `blur(20px) saturate(180%)` |
| Reduced transparency | Solid surface fallback | `@media (prefers-reduced-transparency: reduce)` |
| Display type | Negative tracking + tight leading | `letter-spacing: -0.02em`, `line-height: 1.05–1.18` |

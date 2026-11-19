# Visual Design, Layout, Typography & UX Craft

Consolidated guidance on visual identity, intentional design decisions, two-pass planning, restraint, UX copywriting, and product-level design principles.

---

## 1. Ground Designs in Subject Matter & Surface Mode

Before choosing colors, layout, or visual treatments, establish three concrete facts from the brief or codebase:

1. **Subject & Domain:** What the surface actually is (e.g., PawCariño Pet Store product catalog vs Veterinary Clinic clinical SOAP record vs Grooming appointment booking).
2. **Audience & Context:** Who uses it and under what conditions (pet owners browsing on mobile, clinic receptionists triaging appointments under time pressure, store managers auditing inventory/remittances).
3. **Primary Job & Mode:** What success looks like on this specific surface:
   - **Persuade:** Visitor decides and acts; design is the product (landing pages, unit overviews, service showcases). Ship authentic imagery and deliberate visual framing.
   - **Operate:** Visitor completes a task (POS, orders, cart/checkout, appointments, inventory, admin tables, forms). Scanability, predictability, native/established conventions, and low friction outrank decorative expression.
   - **Read:** Visitor understands something (clinical notes, order summaries, policies, documentation). Structure for comprehension and comfortable reading rhythm.
   - **Experience:** Visitor is inside the artifact itself (galleries, media showcases). Let the artifact lead from the first viewport; interface chrome recedes.

Build with real domain content and subject-matter vernacular throughout rather than generic placeholder filler.

---

## 2. Design Principles & Avoiding AI-Generated Defaults

### Deliberate Visual Hierarchy
- **Hero Treatment:** Open with the most characteristic element for the surface's world (headline, product/care photography, interactive moment, or operational summary). Treat "big number + small label + supporting stats + gradient accent" as one deliberate option among many, never an automatic reflex.
- **Typography Carries Personality:** Use one family or two clearly distinct families. In PawCariño, `public/assets/css/app.css` establishes a `system-ui` optical stack with tight display tracking and comfortable body leading — respect and extend that established system unless a brief explicitly pins custom typefaces.
- **Line Length & Leading:** Default body copy to under 80 characters per line (`max-width: 65ch`–`75ch`). Give serif body text slightly more line-height than sans-serif.
- **Visual Structure Is Information:** Outlines, borders, dividers, numbering, eyebrows, and labels must encode meaningful relationships or sequence rather than decorate empty space. Only use numbered markers (`01 / 02 / 03`) when the content is genuinely a sequential process or timeline.
- **Sparing Non-User-Triggered Motion:** Use at most one orchestrated page-load/reveal moment when warranted. Scattered fade-and-slide-up entrances on every section and hover transitions on every card feel templated. Motion responding to user actions (opening, expanding, confirming) is welcome when it clarifies state change.

### Common Tells to Avoid (Unless Pinned by the Brief or Project Tokens)
Audit your plan against these common automated-design ruts:
1. Defaulting to warm cream (`#F4F1EA`) + high-contrast serif + terracotta (`#D97757`) regardless of brand.
2. Near-black background with a single acid-green or vermilion accent without brief justification.
3. Unprompted broadsheet newspaper layouts with hairline rules, zero border-radius, and dense columns.
4. The uniform "SaaS-card kit": chopping every section into identical rounded cards with identical shadow (`rgba(0,0,0,.1)`) and decorative gradient washes regardless of hierarchy.
5. Repetitive template chrome:
   - Accenting a single word in every headline with italic/bold/color.
   - Tracked-out ALL-CAPS eyebrows above every heading or unnecessary labels above content.
   - Meta strings joined with middle dots (`A · B · C`), `WORD — fragment` labels, or `→` appended to every link/button.
   - Arbitrary tinted near-black (`#0B0B0B`, `#111`) or monospace small labels where plain text belongs.

*Rule:* Where the brief or PawCariño's existing design system pins a visual direction (such as PawCariño's `#0d6e57` deep teal brand, `#f6f7f9` canvas, or existing `·` footer separators), follow the brief and project conventions. Where an axis is free, make a purposeful choice rather than spending that freedom on generic defaults.

---

## 3. Two-Pass Process: Plan, Review, Build, Critique

Work in two passes for any new surface or major redesign:

### Pass 1 — Design Plan & Brief Review
Before writing CSS or PHP view markup, draft a concise design plan:
- **Color:** 4–6 named hex tokens (extending PawCariño's `:root` tokens and `[data-theme="dark"]` overrides).
- **Type:** Typefaces/roles, scale, weights, and tracking/leading behavior.
- **Layout:** One-sentence layout concept plus compact ASCII wireframe(s) and explicit alignment rules (left-aligned, centered, tabular, etc.).
- **Uniqueness & Motion:** What makes this surface fit its exact task, and which (if any) transitions are justified.

**Self-Review Gate:** Compare the plan against the brief and project context. If any part reads like a generic template you would emit for an unrelated domain, revise that part and note why before writing code.

### Pass 2 — Implementation & Restraint
- **CSS Specificity Hygiene:** Structure CSS classes cleanly so generic utility/section selectors (e.g., `.section`, `.card`) and component-specific selectors (e.g., `.cta`, `.panel`) do not accidentally override each other's padding, margin, or display properties.
- **Spend Boldness in One Place:** Let one focal element carry the memorable weight; keep surrounding chrome quiet and disciplined. Remove every ornament that does not serve the brief ("before leaving the house, take a look in the mirror and remove one accessory").
- **Non-Negotiable Quality Floor:** Responsive down to mobile (`320px–375px`+), visible keyboard `:focus-visible` rings, `prefers-reduced-motion` honored, WCAG AA contrast across light and dark themes, and harmonious semantic colors.
- **Visual Critique:** Review rendered screenshots at shipped desktop and mobile sizes whenever possible; fix all issues observed in one pass before re-checking.

---

## 4. UX Writing & Interface Copy

Words in an interface exist to make the product easier to understand and operate — never as filler or decoration.

- **Write from the User's Perspective:** Name concepts by what the user understands, not internal implementation details (e.g., "Email notifications", not "SMTP webhook config"; "Pending order", not "Reservation hold row").
- **Active Voice & Specific CTAs:** State exactly what happens when clicked — `"Save changes"`, `"Confirm appointment"`, `"Place order"`, not vague `"Submit"`.
- **Flow Vocabulary Consistency:** Keep the same verb/noun across the entire interaction: a button labeled `"Publish"` produces a confirmation saying `"Published"`; `"Archive"` produces `"Archived"`.
- **Errors & Empty States Give Direction, Not Mood:**
  - Never apologize ("Sorry, something went wrong") or stay vague ("Invalid input").
  - Explain what happened and the concrete next action in plain, calm language (e.g., `"Enter a valid email address (for example name@example.com) or username."`).
  - Treat empty screens as clear invitations to act (e.g., `"No pets registered yet"` + primary action `"Add your first pet"`).
- **Tone:** Conversational, plain verbs, sentence case, zero filler. Let each text element do exactly one job.

---

## 5. Eight Foundational Design Principles & Component Craft

Drawn from Apple's *Principles of Great Design* and Emil Kowalski's design engineering philosophy:

1. **Purpose:** Make with intention; decide what *not* to build. Every control spends the user's attention budget.
2. **Agency & Forgiveness:** Keep users in control with easy undo/edit paths. Reserve confirmation dialogs strictly for genuinely destructive, irreversible actions (overusing confirmations trains users to click through blindly).
3. **Responsibility:** Ask for permissions/data transparently at the moment needed. Anticipate misuse or harm (e.g., clear clinical/billing safeguards, accurate allergen/medical cues).
4. **Familiarity:** Build on established mental models and physical metaphors. Elements that look the same must behave the same and stay in predictable locations. Break a familiar pattern only when demonstrably better.
5. **Flexibility:** Adapt smoothly across touch/mobile and pointer/desktop workflows, text-size scaling (`rem`/`em`), and varying user expertise.
6. **Simplicity (Not Minimalism):** Strip unnecessary clutter so the primary path shines — hiding essential context makes UI obscure, not simple. Show the common path first and tuck advanced/edge controls one level deeper.
7. **Craft:** Every spacing, alignment, contrast, icon, and timing value is a deliberate, defensible choice. Unseen details compound into trust ("a thousand barely audible voices all singing in tune").
8. **Delight:** The natural byproduct of getting the first seven principles right — not confetti tacked onto broken flows.

### Tactical UX Rules
- **Four Feedback Types:** Expose ongoing **status**, confirm **completion**, **warn** before problems, and validate **errors** inline (not only after full-form submit).
- **Wayfinding:** Every screen must answer: *Where am I? Where can I go? What's here? How do I get out?* Never trap the user in a dead-end flow.
- **Proximity & Mapping:** Place controls directly adjacent to what they affect; mirror physical/spatial layout where possible. If a control needs a paragraph to explain its mapping, redesign the mapping.
- **Specific Labels Beat Vague Umbrellas:** Name navigation items for their contents (`"Appointments"`, `"Medical Records"`, `"Remittances"`), not generic catch-alls (`"Home"`, `"Stuff"`).
- **Great Component Defaults (Sonner Principles):**
  - Low-friction adoption and clean APIs/DOM attributes.
  - Ship refined visual and timing defaults out of the box (most surfaces never customize options).
  - Handle edge cases invisibly (e.g., pause auto-dismiss timers while hovered/hidden, fill gaps between stacked toasts so pointer hover doesn't drop, manage focus/escape cleanly).
  - Match component personality to product mood (crisp and fast for staff workspaces; warm, reassuring, and responsive for pet owners).

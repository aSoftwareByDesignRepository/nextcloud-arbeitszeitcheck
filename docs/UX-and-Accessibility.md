# ArbeitszeitCheck — UX & Accessibility (Holistic Plan)

This document describes how the app is designed to be **simple, clear, secure, and accessible** for everyone (e.g. from less technical users to power users, and WCAG 2.1 AA compliant).

---

## 1. Design principles

- **One primary action per section** — Each block (card/section) has one main action so users are not overwhelmed.
- **Clear hierarchy** — Page title → section titles → body text. Sections are visually separated (spacing, borders, optional left accent).
- **No jargon** — Labels use plain language (e.g. "Clock in" / "Clock out", "Request time off").
- **Generous spacing** — Consistent spacing scale (`--space-*`) so sections and controls are easy to scan and tap.
- **Security-first** — All templates receive CSP nonce; no inline scripts without nonce; output is escaped (`p()`, `print_unescaped` only where safe).

---

## 2. How the app is wired

| Layer | Purpose |
|-------|--------|
| **Routes** | `appinfo/routes.php` — All URLs and verbs; no logic. |
| **Controllers** | Return `TemplateResponse` or `JSONResponse`; pass `urlGenerator`, `l` (IL10N), and page-specific params; use `CSPTrait::configureCSP()` for template responses. |
| **Templates** | One main template per page (e.g. `dashboard`, `time-entries`); include `common/navigation.php`; use `$_` for params and `$l` for translations. |
| **CSS** | Load order: colors → typography → base → components → layout → utilities → navigation → app-layout → page-specific. Variables: `--arbeitszeitcheck-*`, `--space-*`, `--radius-*`, `--shadow-*`. |
| **JS** | Nonce injected via `$_['cspNonce']`; no inline scripts without nonce; app scripts loaded per page. |

---

## 3. Section and layout pattern

- **Breadcrumb** — Optional, minimal (e.g. "Dashboard" or "Time Entries").
- **Page header** — One clear `h2` + short description.
- **Sections** — Each logical block is a `.section`: padding, border, subtle shadow, optional left accent bar. Section heading: `.section-header` with `h2`/`h3` and optional description.
- **Cards** — Used inside sections for status, stats, or actions; `.card` with `.card-header`, `.card-body`, `.card-actions`.
- **Empty states** — `.empty-state` with title, description, and one clear CTA.

---

## 4. WCAG 2.1 AA alignment

| Criterion | How we meet it |
|-----------|----------------|
| **1.4.3 Contrast (Minimum)** | Text uses `--arbeitszeitcheck-color-text` on background; secondary text `--arbeitszeitcheck-color-text-secondary`. Colors chosen for ≥4.5:1 (normal) and ≥3:1 (large). |
| **2.1.1 Keyboard** | All actions available via keyboard; no keyboard traps. |
| **2.4.7 Focus Visible** | `:focus-visible` outline 2px solid primary, 2px offset; scoped to app content where needed. |
| **2.5.5 Target Size** | Buttons and nav links min 44×44px; spacing between controls so adjacent taps are not ambiguous. |
| **3.3.1 Error Identification** | Form errors use `role="alert"`, `aria-invalid`, and visible text; not only color. |
| **4.1.2 Name, Role, Value** | Buttons/links have accessible names (text or `aria-label`); icons that are decorative use `aria-hidden="true"`. |

Reduced motion and high contrast are supported via `prefers-reduced-motion` and `prefers-contrast` in CSS.

---

## 5. Responsive behaviour

- **Breakpoints** — Aligned with `--breakpoint-*` (e.g. 640, 768, 1024px). Content stacks on small screens; grids use `auto-fit` / `minmax()` where appropriate.
- **Touch** — Same 44px minimum target on mobile; nav collapses to a hamburger with overlay.
- **Readable width** — Main content max-width (e.g. 1200px) and padding so lines stay readable.

---

## 6. Security (no regressions)

- **CSP** — `CSPService` applies policy and injects nonce into template params; templates use `nonce="<?php p($_['cspNonce'] ?? ''); ?>"` on inline scripts.
- **Escaping** — User-facing output: `p()` for attributes/text; `print_unescaped()` only for trusted URLs or markup (e.g. `image_path`, `linkToRoute`).
- **No raw user input in JS** — Data passed from PHP to JS is escaped (e.g. `json_encode` with `JSON_HEX_*` flags where needed).

---

## 7. File roles (quick reference)

- **CSS:** `colors.css` (theme), `typography.css` (scale), `base.css` (reset, focus, skip link), `components.css` (buttons, forms, cards), `layout.css` (grid/flex), `utilities.css` (spacing vars, margin/padding classes), `app-layout.css` (content wrapper, sections, breadcrumb, page header), `navigation.css` (sidebar, nav links), `accessibility.css` (focus, sr-only, touch targets, high contrast).
- **Templates:** Each page template includes `common/navigation.php` and uses a consistent structure: breadcrumb (optional), page header, then one or more `.section` blocks.

This plan keeps the app predictable, maintainable, and safe while staying simple and accessible.

---

## 8. Layout and navigation

- **App wrapper** — All pages that include the sidebar use `<div id="arbeitszeitcheck-app">` to wrap navigation + content. This provides a flex container for desktop (sidebar + content side-by-side) and mobile (stacked layout with hamburger menu).
- **Navigation script** — `common/navigation.js` is loaded automatically when `common/navigation.php` is included, so mobile menu toggle and keyboard navigation work on every page.
- **Mobile overlay** — When the hamburger menu is open, an overlay appears and closes the menu on click; it uses `pointer-events: none` when closed to avoid blocking interaction.

---

## 9. Weaknesses and mitigations

| Risk | Mitigation |
|------|------------|
| **Inline scripts** | All inline scripts use `nonce="<?php p($_['cspNonce'] ?? ''); ?>"`; CSP is applied via `CSPService` and `CSPTrait`. |
| **Link/button contrast** | Links in content use `--arbeitszeitcheck-color-primary`; buttons use semantic variants; focus ring uses primary. |
| **Touch target crowding** | Min 44×44px for controls; spacing between nav items and between buttons (e.g. `gap`, `margin-top`) so adjacent taps are distinct. |
| **Section identification** | Sections use border + left accent bar (not only color); headings use bold and size; optional `.section__title` for extra clarity. |
| **Form errors** | Errors shown as text + `aria-invalid` and `aria-describedby` where applicable; not only red border. |
| **Global styles leaking** | App CSS is scoped (e.g. `#app-content-wrapper`, `#app-navigation`) so Nextcloud core and other apps are not overridden. |

---

## 10. Audit findings and implementation notes

### Clock / break API error handling

- **Risk** — If `clockIn`, `clockOut`, `startBreak`, or `endBreak` fail (network, server error), the user previously received no feedback.
- **Mitigation** — Each of these actions now chains `.catch()` on `callApi()` and calls `showError()` with the error message, so users see an `OC.Notification` or alert on failure.

### API URL handling

- **Risk** — Paths like `/apps/arbeitszeitcheck/api/clock/in` may not resolve correctly in setups with custom webroot or `index.php` in the path.
- **Mitigation** — `callApi()` uses `OC.generateUrl()` for non-absolute URLs so Nextcloud generates the correct base path.

### Absence approval audit trail

- **Note** — `approvedBy` is not stored on the absence record; approver identity is recorded in the audit log. Schema and business logic remain consistent with this choice.

### Production recommendations

- Remove or guard `console.warn` / `console.error` in production if they log sensitive or noisy data.
- Ensure all API endpoints validate CSRF via `requesttoken`; the app uses headers and body for this.

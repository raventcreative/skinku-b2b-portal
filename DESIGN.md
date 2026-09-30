# SKINKU B2B Portal — Design System

## 1. Visual theme and atmosphere
- **Direction:** Editorial operations UI: calm, legible, precise, and built for frequent use. Use the compact grouped navigation and clear active-page state found in the local System AI Preneur (`pipeline`) app as a structural reference.
- **Mood:** Trusted distribution workspace with a clear SKINKU red identity.
- **Signature:** Red navigation rail frames a warm, open work surface; active navigation uses a brighter red marker. Preserve SKINKU red even where the pipeline app uses cyan.
- Keep the dashboard data-led. Avoid decorative gradients, emoji, and repeated containers that do not group related information.

## 2. Color palette and semantic tokens
- **Canvas:** `#f7f6f4`; **surface:** `#ffffff`; **soft surface:** `#f2f0ee`.
- **Primary text:** `#292524`; **secondary text:** `#6b625e`; **divider:** `#e7e2df`.
- **Brand red:** `#b4232f`; **navigation red:** `#991b1b` (`red-800`); **active red:** `#b91c1c` (`red-700`). Use the red scale for navigation and brand red for primary actions and focus.
- **Feedback:** retain the existing Tailwind emerald, amber, and rose meanings for success, warning, and error.
- Keep body copy at WCAG AA contrast. Never use color alone to convey a status.

## 3. Typography
- Use the existing local sans-serif stack for interface text; use the existing serif utility only for occasional brand/editorial statements.
- Page title: 1.5–2rem, semibold, tight tracking, balanced wrapping.
- Section title: 1–1.25rem, semibold. Body: 0.875–1rem with comfortable leading. Metadata: at least 0.75rem with clear contrast.
- Use tabular numerals or right alignment for currency and quantities where the view already presents numeric columns.

## 4. Components and states
- **Navigation:** fixed `red-800` rail on desktop; off-canvas drawer on mobile; current page uses `red-700` with a visible left marker. Keep group state and permission checks in Blade.
- **Header:** compact white sticky bar with page name and existing actions.
- **Buttons:** preserve existing Tailwind intent colors. Primary actions use brand red; secondary actions use a neutral outline. All controls need hover, focus-visible, and disabled states.
- **Inputs:** white surface, warm neutral border, rounded 10px corners, red focus ring. Keep native input types and labels.
- **Tables:** semantic table markup, readable headers, aligned numeric data, subtle row separators/hover, and horizontal overflow on narrow screens. Do not turn tables into cards unless labels remain accessible.
- **Containers:** use whitespace first; reserve bordered white surfaces for distinct tasks, forms, charts, and data groups.

## 5. Layout and spacing
- Main desktop content maxes naturally to available width beside the 16rem navigation rail.
- Use a 4px base spacing rhythm, favoring 16, 24, and 32px section gaps.
- Keep page content left aligned. Use a 12-column grid only when comparing related data groups.
- On narrow screens, reduce outer padding and allow data regions to scroll without shrinking text to unreadable sizes.

## 6. Depth and elevation
- Canvas is flat. Surfaces use a 1px warm-gray border; sticky bars use a faint, crisp shadow.
- Reserve stronger elevation for overlays and dialogs. Avoid large diffuse shadows on ordinary cards.

## 7. Do and avoid
- **Do:** preserve Indonesian copy, permission-based visibility, keyboard access, and responsive behavior.
- **Do:** keep one focal action per section and show errors beside their relevant fields when the view supports it.
- **Avoid:** emoji, generic three-card rows without a data reason, red text on red surfaces, tiny low-contrast helper text, and color-only statuses.
- **Avoid:** adding a UI framework for pages already rendered by Blade and Tailwind.

## 8. Responsive breakpoints
- Follow Tailwind defaults: `sm` 640px, `md` 768px, `lg` 1024px, `xl` 1280px, `2xl` 1536px.
- The sidebar becomes a drawer below `lg`; preserve a visible menu button and dismissible backdrop.
- Check layouts at 375px, 768px, 1024px, and 1440px.

## 9. Agent prompt guide
Use [21st.dev dashboard patterns](https://docs.21st.dev/blog/dashboard-component-libraries) as visual reference for the shell, metric hierarchy, and data tables; adapt them to Blade and installed Tailwind instead of adding React components. Keep existing routes, permissions, and form behavior. Use red as the brand accent, keep keyboard focus visible, and do not add emoji or UI dependencies without a concrete need.

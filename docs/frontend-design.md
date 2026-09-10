# Frontend design review

The application uses a shared Blade shell and small Vue islands. Blade retains ownership of native forms, links and page scripts; Vue mounts only the registered custom elements. This avoids recompiling server-rendered page content and removing inline page scripts.

## Design system

- Teal primary actions, neutral backgrounds and raised white surfaces; corresponding dark tokens in `resources/css/app.css`.
- System sans typography, monospace for timers/code, tabular figures for data.
- One page title and context description per route, consistent spacing, tables and form controls.
- Keyboard skip link, visible focus, accessible mobile drawer, focus containment and Escape dismissal for dialogs, labelled inputs and overflow regions.
- Explicit empty/filter/no-contest/error states. No invented sample input, active-competition badge or fake wizard data.
- Reduced motion respects the OS setting. No external fonts, new production dependencies or external assets.

## Page coverage

| Surface | Review |
| --- | --- |
| Home | Action-oriented dashboard, statistics from existing controller, competition timer and recent submissions |
| Problems / statement / submission | Current `Problem` contract preserved, readable statement and real samples, searchable list, labelled upload/code controls |
| Scoreboard / submissions | Search and result counts, consistent table and verdict treatment, dynamic problem columns preserved |
| Clarifications | Shared layout and feedback, retained question text, guest sign-in guidance |
| Help / first steps | Task-oriented guide, expandable FAQ, verdict reference, working navigation |
| Login / register / password views | Shared split auth layout, clear fields, theme support and responsive form |
| Admin problems / users / teams | Search, responsive tables, accessible create dialogs, contest-scoped real problem management and working submission detail links |
| Contest configuration / create / edit | Shared shell, labelled controls, responsive actions and step focus |
| Problem bank / import | Composable search + difficulty filter, result feedback and consistent surfaces |
| Judge / staff | Shared visual system, searchable queues and labelled verdict controls; backend permissions/actions preserved |
| 403 / 404 / 419 / 500 | New recovery views with status codes and navigation |

Unrouted historical `products`, `users` and `welcome` templates remain archival; the backend removed the dead `my-team` template. No routes or backend contracts were added for them. No controllers, migrations or API endpoints are changed by this PR.

## Validation

- `npm run test:frontend` (14 behavior and contrast tests)
- `npm run build`
- `vendor/bin/phpunit --no-coverage` (489 tests / 2,964 assertions in the isolated Linux container on the integrated base)
- `git diff --check`
- Browser review: desktop light/dark dashboard, login, administrative table, search result counts, modal opening/Escape, 390px mobile table/drawer, correct focus and no document overflow.
- New feature tests cover public/admin/participant navigation, management page rendering, help/onboarding and the custom 404 status.

The base includes backend PRs #24–#29, #31 and #36–#38. Submission detail uses the integrated backend route with improved code-region accessibility. Server-rendered lists update on navigation/reload; search filters the loaded rows. Timers refresh contest metadata every minute.

The subsequent [accessibility and Nielsen review](frontend-accessibility.md) documents form recovery, dialog isolation, keyboard selections, URL filters, test evidence and remaining manual verification limits.

The [complete visual review](frontend-visual-audit.md) records the semantic palette, measured contrast, page coverage, functional corrections and outstanding browser/backend checks.

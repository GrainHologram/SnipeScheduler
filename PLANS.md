# Issue Plans

Plans for all remaining open issues. Review and let me know which to proceed with.

---

## Issue #3: Classify Equipment and Users (Certifications)

**Status:** Largely already implemented. The codebase has a working certification system.

### What Already Exists

- **Per-model cert requirements:** Assets with custom fields matching `Cert - {Name}` (value "Yes") create model-level requirements. Detected by `get_model_certification_requirements()` in `snipeit_client.php`.
- **User cert verification:** `check_user_certifications()` in `checkout_rules.php` compares required certs against the user's Snipe-IT group names (case-insensitive).
- **Enforcement points:** Catalogue (blocks "Add to Basket"), basket preview, basket checkout, staff checkout, and quick checkout all call `check_user_certifications()`.
- **Access group gate:** `check_user_has_access_group()` requires `Access - *` group membership to use the system at all.
- **UI badges:** Catalogue cards show "Certification: X" warning badges on models requiring certs.

### What's Missing / Could Be Improved

1. **Admin visibility page** — No way for admins to see at a glance which models require which certs, or which users have which certs. A simple "Certifications Overview" admin page could show:
   - Table of all models with their cert requirements
   - Table of all cert-related Snipe-IT groups and their member counts
2. **Cert assignment guidance** — No in-app documentation for staff explaining the `Cert - *` custom field convention. A help tooltip or settings page note would reduce confusion.
3. **Stale cert detection** — If someone removes the custom field from Snipe-IT assets, the requirement silently disappears. Could add a periodic audit log warning.

### Recommendation

This issue may already be closeable if the current `Cert - *` pattern meets the original need. I'd suggest:
- Add a brief "Certifications Overview" section to the admin page (read-only, summarizes which models need certs and which groups satisfy them)
- Close the issue with documentation of how the system works

### Questions for You
- Is the existing `Cert - *` custom field + Snipe-IT group approach sufficient, or do you want a dedicated certifications management UI?
- Should there be a way to define cert requirements at the **model** level in SnipeScheduler's DB (rather than relying on per-asset custom fields in Snipe-IT)?

---

## Don't Pre-fill Booking User for Staff

**Status:** Done. Merged PR #82.

- Staff must now explicitly select a user before adding items to the basket — catalogue no longer defaults to the staff member's own account
- Catalogue shows "No user selected — search to begin" with add-to-basket buttons disabled until a user is chosen
- Basket blocks checkout submission if staff has no user selected
- User search replaced: uses Snipe-IT users API (same as dashboard) instead of querying LDAP/Google/Microsoft directories directly
- Clicking a search suggestion auto-submits the form (no separate "Use" button)
- Non-staff users unaffected

---

## Flatpickr DateTime Pickers + Staff Date Override

**Status:** Done. Merged PR #77.

### Flatpickr
- Replaced native `datetime-local` inputs site-wide with Flatpickr (v4.6.13, CDN)
- Global auto-enhancement via `public/assets/datetime-picker.js` — no per-page setup
- Config: `altInput` for human-friendly display, `minuteIncrement: 15`, `allowInput: true`
- Catalogue/basket programmatic setters updated to use `setPickerValue()` helper with Flatpickr `setDate()` API

### Staff Date Override
- Staff can edit checkout start/end dates when processing a reservation on `staff_checkout.php`
- Dates pre-fill from reservation; "Reset dates" button reverts to originals
- Non-blocking conflict warnings shown when overridden dates overlap other reservations/checkouts
- Controlled by `checkout_limits.staff_date_override` config key (defaults to `true`)
- Override dates carry through single-active-checkout "Append" flow via hidden inputs

---

## Issue #5: Calendar View for Active Checkouts and Reservations

### Approach

Add a new "Calendar" page accessible to staff, showing reservations and checkouts as colored blocks on a monthly/weekly/daily calendar. Use [FullCalendar](https://fullcalendar.io/) v6 via CDN (no build step needed, aligns with the project's no-bundler approach).

### Files to Create/Modify

1. **`public/calendar.php`** — New page with FullCalendar rendering
2. **`public/calendar_events.php`** — JSON API endpoint returning events for a date range
3. **`src/layout.php`** — Add "Calendar" link to staff nav

### `calendar_events.php` — JSON Endpoint

- Accepts `?start=YYYY-MM-DD&end=YYYY-MM-DD&type=all|reservations|checkouts`
- Queries `reservations` table for non-cancelled reservations overlapping the range
- Queries `checkouts` table for checkouts overlapping the range
- Returns FullCalendar-compatible JSON array:
  ```json
  [
    {
      "id": "r-42",
      "title": "Jane Doe — Canon 5D (2), Tripod",
      "start": "2026-02-18T09:00:00",
      "end": "2026-02-19T17:00:00",
      "url": "reservation_detail.php?id=42",
      "color": "#0d6efd",
      "extendedProps": { "type": "reservation", "status": "confirmed" }
    },
    {
      "id": "c-15",
      "title": "John Smith — Checkout #15",
      "start": "2026-02-17T10:00:00",
      "end": "2026-02-20T10:00:00",
      "url": "reservation_detail.php?id=8",
      "color": "#198754",
      "extendedProps": { "type": "checkout", "status": "open" }
    }
  ]
  ```

### `calendar.php` — Calendar Page

- Standard page shell (nav, top bar, etc.)
- FullCalendar initialized with:
  - `dayGridMonth`, `timeGridWeek`, `timeGridDay`, `listWeek` views
  - Event source pointing to `calendar_events.php`
  - Color coding: blue = reservation (pending/confirmed), green = checkout (open), orange = partial return, gray = closed/fulfilled
  - Click event → navigate to `reservation_detail.php` or checkout detail
- Filter toggles: show/hide reservations, show/hide checkouts
- Status legend

### Conventions
- FullCalendar CSS+JS loaded from CDN (same pattern as Bootstrap)
- No new DB tables
- Datetimes converted to app timezone for display
- Staff/admin access only

### Estimated Scope
- ~200 lines for `calendar.php` (page shell + FullCalendar init)
- ~100 lines for `calendar_events.php` (queries + JSON output)
- ~5 lines for nav addition in `layout.php`

---

## Issue #17: More Flexible Reservation Conversion/Checkout Page

### Bullet 1: Barcode Scan-to-Assign — Done. PR #62.

Scan asset barcodes to assign assets to reservation models on the Today tab checkout. Supports adding extra quantity (bumps reservation_items), models not on the reservation (inserts new row), cert/access override warnings with persistent badge, asset autocomplete, and auto-focus for rapid scanning.

### Remaining Bullets (not yet implemented)

#### 2. Inline quantity adjustment

For each model line in the checkout view, allow staff to increase/decrease the requested quantity:
- Small +/- buttons next to the quantity
- Updates `reservation_items.quantity` via POST
- Rechecks availability before allowing increase

#### 3. Add "Add Model" button to checkout page

On `staff_checkout.php`, below the existing reservation items, add an "Add model to this reservation" section:
- Dropdown/search of requestable models (from Snipe-IT)
- Quantity selector
- "Add" button that inserts a new `reservation_items` row and refreshes the page
- Availability check before adding (same logic as basket)

---

## Issue #45: Account for Closed Hours in Maximum Checkout Duration

### Current State

- Duration limits exist in `checkout_rules.php`: `validate_checkout_duration()` computes `(end - start) / 3600` as raw elapsed hours.
- Opening hours system exists in `opening_hours.php` with a three-tier priority model (one-off overrides > recurring schedules > default weekly).
- `oh_is_open_at(DateTime $utcDt)` can check if the facility is open at any given moment.
- **These two systems are not connected.** A Friday 5pm to Monday 9am checkout counts as 64 hours even if the facility is closed all weekend.

### Approach

Add a `calculate_open_hours()` function that counts only the hours the facility is open between two UTC datetimes, then use it in the existing validation functions.

### Files to Modify

1. **`src/opening_hours.php`** — Add `oh_calculate_open_hours(DateTime $startUtc, DateTime $endUtc): float`
2. **`src/checkout_rules.php`** — Modify `validate_checkout_duration()`, `validate_renewal_duration()`, `get_max_checkout_end()`, `get_max_renewal_end()` to use open-hours calculation
3. **`config/config.example.php`** — Add `duration_excludes_closed_hours` boolean (default `false` for backward compatibility)

### `oh_calculate_open_hours()` Algorithm

```
Given start and end (both UTC):
1. Iterate day-by-day from start date to end date (in app timezone)
2. For each day, call oh_get_hours_for_date() to get open/close times
3. If day is closed, contribute 0 hours
4. If day is open, calculate the overlap between [day_open, day_close] and [start, end]
5. Sum all overlapping hours
Return total open hours as float
```

This handles:
- Weekends (closed days contribute 0)
- Holiday overrides (one-off closures)
- Recurring schedule changes (e.g., shorter winter hours)
- Partial first/last day (e.g., checkout starts at 3pm, facility closes at 5pm = 2 hours that day)

### `get_max_checkout_end()` Change

Currently: `start + max_hours` (simple addition).
New: Walk forward from start, accumulating open hours until `max_hours` is reached. Return the datetime at which the limit is hit.

```
Given start and max_hours:
1. Start at start datetime
2. For each day from start forward:
   a. Get open hours for that day
   b. Calculate overlap with remaining time
   c. Subtract from remaining hours
   d. If remaining <= 0, compute exact cutoff datetime
3. Return the cutoff datetime
```

### Config Toggle

```php
'checkout_limits' => [
    // ...existing...
    'duration_excludes_closed_hours' => false, // true = only count open hours toward limits
],
```

When `false`, behavior is unchanged (raw elapsed time). When `true`, uses `oh_calculate_open_hours()`.

### Edge Cases
- If no opening hours are configured (all days have no records), treat as 24/7 open (same as current behavior)
- If start or end falls outside opening hours, still count from the nearest open period
- Very long checkouts (weeks) — iterate efficiently, cache `oh_get_hours_for_date()` results per-date

---

## Issue #46: KITS!!!!

### API Discovery

Snipe-IT has a full (undocumented) kits API. Confirmed endpoints from the source:

| Method | Endpoint | Purpose |
|--------|----------|---------|
| `GET` | `/api/v1/kits` | List all predefined kits |
| `GET` | `/api/v1/kits/{id}` | Get single kit details |
| `GET` | `/api/v1/kits/{id}/models` | Get models in a kit (with quantities) |
| `GET` | `/api/v1/kits/{id}/licenses` | Get licenses in a kit |
| `GET` | `/api/v1/kits/{id}/accessories` | Get accessories in a kit |
| `GET` | `/api/v1/kits/{id}/consumables` | Get consumables in a kit |

### Approach

Fetch kit definitions from the Snipe-IT API and cache aggressively (once per day via cron, or on first request with long TTL). No local kit tables — Snipe-IT is the single source of truth.

### Caching Strategy

- **File-based cache** in `config/cache/` (same pattern as existing API caching)
- **TTL: 24 hours** — kits change infrequently
- Cache key: `kits_list` for the index, `kit_{id}_models` for per-kit models
- **Cron refresh** (`scripts/sync_kits_cache.php`) — optional script to pre-warm the cache daily, similar to `sync_checked_out_assets.php`
- Manual cache-bust via admin settings page (a "Refresh kits" button)

### Files to Create/Modify

1. **`src/snipeit_client.php`** — Add kit API functions:
   - `get_kits()` — fetch all kits (cached 24h)
   - `get_kit($kitId)` — fetch single kit
   - `get_kit_models($kitId)` — fetch models + quantities for a kit (cached 24h)
   - Uses existing `snipeit_request()` with extended TTL

2. **`public/catalogue.php`** — Add kits section:
   - Separate "Kits" tab or section above/below individual models
   - Each kit card shows: kit name, constituent models with quantities, combined availability (minimum across all models in the time window)
   - "Add kit to basket" button that expands into individual model quantities
   - Kit cards show certification requirements (union of all constituent model certs)

3. **`public/basket_add.php`** — Handle kit-to-basket expansion:
   - Accept `kit_id` parameter (in addition to existing `model_id`)
   - When adding a kit, iterate its models and add each to `$_SESSION['basket']` with the kit quantity
   - If a model is already in the basket, increment quantity
   - Store `$_SESSION['basket_kit_groups']` mapping to track which basket items came from which kit (for display grouping)

4. **`public/basket.php`** — Group kit items visually:
   - If basket items came from a kit, show them under a kit header (e.g., "Basic Camera Package" with indented model rows)
   - "Remove kit" removes all constituent items at once
   - Individual model quantities still adjustable within the kit

5. **`scripts/sync_kits_cache.php`** (optional) — Cron script to pre-warm kit cache daily

### Kit Availability Calculation

A kit is "available" for a time window if **every model in the kit** has enough free units:

```
kit_available_qty = min(
    floor(free_units_model_A / kit_qty_model_A),
    floor(free_units_model_B / kit_qty_model_B),
    ...
)
```

Example: Kit needs Canon 5D (1) + Tripod (2). If 3 Canon 5Ds are free and 5 Tripods are free:
`min(3/1, 5/2) = min(3, 2) = 2` kits available.

### Reservation & Checkout Flow

- **Reservation:** Kit items are stored as individual `reservation_items` rows (model_id + quantity), same as manually-added items. No schema changes needed.
- **Checkout:** Staff assigns individual assets per model — unchanged from current flow.
- **Keeping kit items together:** The issue mentions "bonus points for keeping all kit items together/contiguous." This can be handled by sorting reservation items so kit-sourced items appear grouped, and by prefilling the staff checkout asset picker to favor assets from the same location/shelf.

### Decisions (Confirmed)
- **Visible to all users** — kits are preferred over manually selecting many individual items
- **Adjustable quantities** — users can tweak individual model quantities when adding a kit
- **Catalogue layout:** Two tabs — "Kits" tab and "Equipment" tab. Clean, simple layout.
- **Visual grouping in basket/reservation is sufficient** — no need for location-aware asset picker logic

---

## Issue #49: Better Dashboard for Desk Agents

### Current State

`index.php` is a simple card-based landing page with links to Catalogue, My Reservations, Reservations hub, Quick Checkout, and Quick Checkin. No live data, no metrics, no quick actions.

### Proposed Dashboard

Replace the staff section of `index.php` with a data-rich dashboard showing today's actionable items at a glance.

### Layout

```
+-----------------------------------------------+
| TODAY'S SUMMARY (stat cards row)               |
| [Pending Pickups: 5] [Active Checkouts: 12]   |
| [Due Today: 3]       [Overdue: 1 (red)]       |
+-----------------------------------------------+
| LEFT COLUMN (60%)     | RIGHT COLUMN (40%)     |
|                        |                        |
| UPCOMING PICKUPS       | QUICK ACTIONS          |
| (next 5 pending/      | [Start Checkout ▸]     |
|  confirmed for today)  | [Quick Checkin ▸]      |
| - Doe, 10am, Canon 5D | [Browse Catalogue ▸]   |
|   [Process ▸]          |                        |
| - Smith, 11am, Tripod  | DUE SOON               |
|   [Process ▸]          | (checkouts ending      |
|                        |  within 2 hours)       |
| EQUIPMENT DUE TODAY    | - Asset #1234, Doe     |
| (checkouts with end    |   due 3:00pm           |
|  date = today)         | - Asset #5678, Smith   |
| - Doe, Canon 5D #1234 |   due 4:30pm           |
|   due 5pm              |                        |
|   [Checkin ▸]          | OVERDUE (red section)  |
|                        | - Asset #9012, Jones   |
+-----------------------------------------------+
```

### Files to Create/Modify

1. **`public/index.php`** — Major rework of the staff section. Keep the user section (Catalogue + My Reservations cards) for non-staff users.
2. Possibly **`public/dashboard_data.php`** — AJAX endpoint for refreshing stats without full page reload (optional, could be a follow-up).

### Data Queries

**Pending pickups today:**
```sql
SELECT * FROM reservations
WHERE status IN ('pending', 'confirmed')
  AND DATE(start_datetime) = CURDATE()
ORDER BY start_datetime ASC
LIMIT 10
```

**Active checkouts count:**
```sql
SELECT COUNT(*) FROM checkouts WHERE status IN ('open', 'partial')
```

**Due today:**
```sql
SELECT c.*, ci.* FROM checkouts c
JOIN checkout_items ci ON ci.checkout_id = c.id
WHERE c.status IN ('open', 'partial')
  AND DATE(c.end_datetime) = CURDATE()
  AND ci.checked_in_at IS NULL
```

**Overdue:**
```sql
SELECT c.*, ci.* FROM checkouts c
JOIN checkout_items ci ON ci.checkout_id = c.id
WHERE c.status IN ('open', 'partial')
  AND c.end_datetime < NOW()
  AND ci.checked_in_at IS NULL
```

### Features
- Stat cards at top with counts (color-coded: red for overdue)
- "Process" links go directly to `reservations.php?tab=today` with the reservation pre-selected
- "Checkin" links go to `quick_checkin.php`
- Auto-refresh option (meta refresh or JS timer, every 60s)
- Responsive: single column on mobile

### Questions for You
- Should the dashboard replace the current `index.php` entirely for staff, or be a separate page (e.g., `staff_dashboard.php`) linked from the nav?
- The issue mentions "quick user selector to start reservation/checkout/quick checkout" — should this be a unified user search bar at the top of the dashboard that routes to the appropriate page?
- Should the dashboard auto-refresh, or is manual refresh fine?

# Dream Trips — Travel Itinerary Planner

A full-stack travel planning web app that turns scattered trip-planning notes (Google Docs, group chats, browser tabs, half-finished spreadsheets) into a single, day-by-day itinerary with budgets, dates, and analytics.

* **Live demo:** 
* **Repo:** https://github.com/DaminiRastogi11/dream-trips

---

## Problem statement

People plan trips in fragments. Destination ideas live in browser bookmarks, hotel options in tabs, attractions in screenshots, costs in mental math, and the actual day-by-day plan in a notes app the day before departure. The result is duplicated work, blown budgets, missed reservations, and a vague feeling of "I'm sure I forgot something."

Existing tools force a tradeoff:

- **Google Sheets / Docs** — flexible but unstructured; no notion of a day, a destination, or a budget.
- **Booking.com / Airbnb** — solve one slice (lodging) and ignore the rest.
- **TripIt / Wanderlog** — handle the timeline but don't actually help you choose between options or reason about cost.

Dream Trips is the opinionated middle layer: a structured planner where one user, one trip, and one budget are first-class concepts; where every attraction, event, and accommodation has a price the system can sum; and where you can see in seconds how many days you've planned, how much it costs, and how it compares to your budget.

---

## Features

### Planning flow
- **Preferences** — pick climate, visa, and trip type to filter the destination grid to relevant options.
- **Destinations** — 8 seeded cities (Paris, Tokyo, Cairo, Bali, Barcelona, Reykjavik, Bangkok, New York) with images, climate, and visa info pulled from the database.
- **Search** — type a city or country in the dashboard search bar; results are filtered with `LIKE` queries against `city`/`country`.
- **Attractions & Events** — every destination has 3 attractions and 2 events with category, rating, entry fee, dates, and price.
- **Itinerary Builder** — day-by-day, with editable trip start date and budget. Day tabs show real calendar dates ("Day 2 (Tue, May 12)") once a start date is set.
- **Per-day accommodation** — pick a different hotel for each day if you're moving around the city.
- **Add / remove days** — adding appends; removing renumbers the remaining days back to 1..N (no gaps).
- **Add / remove activities** — every activity row has an inline POST-form remove button with a server-side ownership check.

### Trip management
- **My Trips** — sequential per-user trip numbering (`Trip #1`, `Trip #2`...) instead of global database IDs.
- **Edit / View / Delete** — every trip can be opened in the builder, summarized, or sent to Trash.
- **Soft delete + Trash tab** — deleting moves the trip to Trash; nothing is hard-deleted, so trips can be restored.
- **Budget tracking** — the summary page shows a green/yellow/red progress bar comparing planned spend to budget, plus an "over budget by $X" notice when you exceed it.
- **Public sharing** — generate a 32-hex-char share token and send anyone a read-only `view_shared.php?token=...` link. Revoke at any time.

### Analytics dashboard
- **6 KPI cards** — trips, days, activities, total spend, average per trip, combined budget.
- **Chart.js charts** — trips per destination (bar), spend by category (donut), trips planned per month (line), top 5 most-added attractions (horizontal bar).
- **Avg trip cost by destination** — analytical SQL using correlated subqueries.

### Account & security
- **Auth** — signup with `password_hash()`, login with `password_verify()`, `session_regenerate_id(true)` after successful login to defeat session-fixation.
- **Profile page** — gradient hero with avatar, stats sidebar, edit name/email, change password (each requires current password). Show/hide toggle on every password input.
- **CSRF tokens** — generated per session, validated on every destructive POST (preferences, builder, profile, delete, restore, share, password change).
- **Soft-delete trash** — real deletion only happens on explicit cleanup; mistakes are recoverable.

---

## Tech stack

| Layer | Tech |
|---|---|
| Server language | PHP 8.2 |
| Database | MySQL / MariaDB (XAMPP locally; InfinityFree in production) |
| DB driver | mysqli with **prepared statements throughout** — no raw `mysqli_query` on user input |
| CSS framework | Bootstrap 5.3 |
| Icons | Bootstrap Icons 1.11 |
| Typography | Plus Jakarta Sans (Google Fonts) |
| Charts | Chart.js 4.4 (CDN, no build step) |
| Frontend JS | Vanilla — no framework, no bundler |
| Auth | Native PHP sessions + `password_hash` (bcrypt) |

### Security posture
- 100% prepared statements (no string-concat SQL)
- Per-session CSRF tokens with `hash_equals` constant-time comparison
- POST-only destructive endpoints (deletion, restore, share, password change)
- Ownership checks on every read/write of an itinerary or activity
- Output escaped with `htmlspecialchars` everywhere user input is rendered
- Soft-delete by default — no accidental data loss

---

## Database schema

```
traveler                       -- account
traveler_preferences           -- climate / visa / trip type per user
destination                    -- city, country, climate, visa, image_url
attraction                     -- belongs to destination
event                          -- belongs to destination
accommodation                  -- belongs to destination
itinerary                      -- start_date, budget, deleted_at, share_token
itinerary_day                  -- day N of an itinerary, optional accommodation_id
itinerary_activity             -- attractions/events on a specific day
```

All foreign keys are explicit; cascading deletes wired up so removing a parent cleans up children.

---

## Setup — local development with XAMPP

**Prerequisites:** XAMPP with PHP 8.2+ and MySQL 5.7+ / MariaDB 10.4+.

1. **Clone into `htdocs`:**
   ```bash
   cd C:\xampp\htdocs
   git clone https://github.com/YOUR_USERNAME/dream-trips.git travelplanner
   ```

2. **Start XAMPP:** open the XAMPP Control Panel, start **Apache** and **MySQL**.

3. **Import the database:**
   - Open `http://localhost/phpmyadmin`
   - Click **New** in the left sidebar → name it `travel_itinerary_db` → Create
   - Select the new database → **Import** tab → choose `travel_itinerary_db.sql` → **Go**

4. **(Default credentials work — only change if your local MySQL isn't the XAMPP default.)** Open `config.php`:
   ```php
   $host = "localhost";
   $user = "root";
   $pass = "";
   $db   = "travel_itinerary_db";
   ```

5. **Open the app:** `http://localhost/travelplanner/`

6. **Sign up** for a new account, then plan your first trip.

---

## Project structure

```
travelplanner/
├── config.php                 # DB connection + CSRF helpers
├── header.php                 # navbar, fonts, icons
├── footer.php                 # closing tags + Bootstrap JS
├── style.css                  # theme, gradients, KPI cards, profile/analytics styles
│
├── index.php                  # router — sends to dashboard or login
├── login.php                  # auth: login form + session regeneration
├── signup.php                 # auth: account creation
├── logout.php                 # session destroy
├── profile.php                # avatar hero, stats, edit name/email, change password
│
├── dashboard.php              # KPI strip, next trip countdown, recent trips
├── preferences.php            # climate / visa / trip type
├── destinations.php           # filterable destination grid + search
├── attractions.php            # destination's attractions + events
├── itinerary_builder.php      # day-by-day builder
├── summary.php                # full itinerary, budget bar, share link
├── trips.php                  # active + trash tabs, View/Edit/Delete
├── delete_itinerary.php       # soft delete + restore handler
├── remove_activity.php        # POST-only activity removal with CSRF
├── view_shared.php            # public read-only viewer (no login required)
├── analytics.php              # Chart.js dashboard
│
└── travel_itinerary_db.sql    # full schema + seed data + idempotent migrations
```

---

## Screenshots

_Add after deploying:_
- Dashboard with KPI cards
- Itinerary builder with day tabs
- Summary with budget progress bar
- Analytics charts
- Public shared view

---

## Roadmap

Things deliberately deferred from this build:

- **Multi-destination trips** — let one itinerary span Paris → Rome → Athens by moving `destination_id` from itinerary down to `itinerary_day`.
- **Live data** — Open-Meteo for weather, Ticketmaster for events, exchange rates for currency conversion.
- **Map view** — Leaflet on the summary page once attractions get `latitude`/`longitude`.
- **Favorites** — star destinations and attractions.
- **Calendar export** — generate iCal files for the trip.
- **Centralized config** — environment-variable-driven DB credentials for production.

---

## License

MIT — do whatever you want, attribution appreciated.

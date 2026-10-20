# Home Network Device Monitoring and Alert System

A web-based monitor that scans a home network, tells known devices from unknown ones, and emails an alert when something unfamiliar joins. Built for the CSN project (TMA2 interim report): a three-tier design.

| Tier | Technology | Folder |
|------|-----------|--------|
| Dashboard | React (Vite) | `src/` |
| Backend + scanner | PHP | `backend/` |
| Storage | MySQL | `backend/schema.sql` |

## How it works

- **Discovery:** a ping sweep of the subnet makes every active device answer, which fills the host's ARP cache. Only then is `arp -a` (or `ip neigh`) read. Reading the cache alone misses idle devices.
- **Identity:** the MAC address is the primary key of `devices`, because DHCP changes IPs. A changed IP is logged, never alerted.
- **Alerts:** a MAC not in the database is **Unknown** and raises one alert (dashboard + email). Repeated scans do not re-alert. The first-ever scan is a baseline with a single summary alert.
- **MAC randomisation:** an unrecognised MAC whose hostname matches a trusted device that is not currently online is treated as that phone rotating its private MAC: the record is updated quietly, with no alert.
- **Spoofing check:** a trusted MAC that suddenly reports a different hostname or manufacturer is flagged **Suspicious** for review.
- **Scheduled scans:** `backend/cli_scan.php` runs from cron or Windows Task Scheduler, so alerts work with the dashboard closed. Target: a full /24 scan in under 120 s (shown in the dashboard header).
- **CORS:** the API sends CORS headers for the origins in `allowed_origins`.
- **Time:** all timestamps are stored in UTC and shown in the browser's local time.
- **Privacy:** only MAC, IP, hostname, manufacturer and timestamps are stored. The dashboard needs a password, and the API needs a signed token.

## Configuration

The backend needs no config file. Settings are read from environment variables, or from `backend/.env` for local use (copy `backend/.env.example`). Real environment variables win over `.env`. On a cloud host, set the same names in the host's environment/"variables" settings. `backend/config.local.php` is an optional local override.

| Variable | Purpose |
|----------|---------|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (or `DB_DSN`) | MySQL connection |
| `SUBNET` | Range to scan, e.g. `192.168.0.0/24` |
| `SCAN_MODE` | `live` (this server scans its own network), `agent` (a home agent scans; use on a cloud server) or `demo` (simulated devices) |
| `AGENT_KEY` | Secret shared between the cloud API and the home agent |
| `AGENT_API_URL`, `AGENT_INTERVAL_S` | Only for `agent.php` on the home computer |
| `DASHBOARD_PASSWORD` (or `DASHBOARD_PASSWORD_HASH`) | Login password (default `changeme`: change it) |
| `AUTH_SECRET` | Long random string used to sign login tokens |
| `ALLOWED_ORIGINS` | Your deployed dashboard URL (CORS), comma separated |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` | The account alert emails are sent *from* |

The dashboard itself only needs `VITE_API_BASE_URL` (the API address), set at build time. Never put passwords in it: it is public.

## Setup (XAMPP, local)

1. Start Apache and MySQL, then import `backend/schema.sql` (phpMyAdmin or `mysql -u root < backend/schema.sql`). It creates a new database, `home_network_monitor`, so it won't clash with tables from older projects.
2. Copy the `backend` folder to `htdocs/network-monitor-api` and copy `backend/.env.example` to `backend/.env`. Set at least `SUBNET` (your own network) and, for email, the `SMTP_*` values.
3. Run the dashboard:
   ```bash
   npm install
   npm run dev      # http://localhost:5173
   ```
   If the API is not at `http://localhost/network-monitor-api`, copy `.env.example` to `.env` and edit `VITE_API_BASE_URL`.
4. Sign in (`changeme` unless you set `DASHBOARD_PASSWORD`) and click **Scan now**.
5. Schedule scans, e.g. every 2 minutes (Task Scheduler / cron): `php /path/to/network-monitor-api/cli_scan.php`

### Email alerts

Whoever uses the dashboard types their own address in **Email alerts** and clicks Save, then **Send test email** to check it. Alerts are sent *from* the SMTP account in the server settings. For Gmail: create a project account, turn on 2-Step Verification, create an app password at https://myaccount.google.com/apppasswords, and set `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=465`, `SMTP_SECURE=ssl`, `SMTP_USER=<the gmail address>`, `SMTP_PASS=<the app password>`. Without `SMTP_HOST` the server falls back to PHP `mail()`, which needs mail software on the machine. Each alert in the dashboard shows "email sent" or "email not sent".

### Deploying to a cloud host

1. Create a MySQL database there and import `backend/schema.sql`; deploy the `backend` folder to a PHP host and set the variables above in the host's settings (not in git).
2. Build the dashboard with the API address: `VITE_API_BASE_URL=https://your-api.example.com npm run build`, and publish `dist/` as static files. Add the dashboard's URL to `ALLOWED_ORIGINS`.
3. **A cloud server cannot see your home network**, so real scanning uses the home agent (next section). Use `SCAN_MODE=agent` on the cloud API (or `demo` for simulated devices only).

### Scanning your home network from a cloud dashboard (home agent)

```
Home computer (agent.php)  --HTTPS, outgoing only-->  Cloud API + MySQL  <--  Dashboard
```

`backend/agent.php` runs on an always-on computer inside your home network. It scans the LAN and uploads the results to the cloud API, so no router ports need opening. The dashboard's **Scan now** button asks the agent to scan (the header shows whether the agent is online), and the agent also scans by itself every `AGENT_INTERVAL_S` seconds, so alerts do not depend on the dashboard being open.

1. On the cloud API set `SCAN_MODE=agent` and `AGENT_KEY=<a long random string>`.
2. On the home computer create `backend/.env` with the same `AGENT_KEY`, plus `AGENT_API_URL=https://your-api.example.com/index.php` and `SUBNET=<your home subnet>`.
3. Start it: `php backend/agent.php` (leave it running; `--once` scans once and exits).

## Try it without a network (demo mode)

`SCAN_MODE=demo` returns a simulated household with an intruder from the third scan on, and a phone that rotates its MAC.

## Tests

```bash
npm run test:backend   # parsing, alert-once, DHCP change, MAC rotation, spoof flag, auth tokens
npm run build
```

## Layout

```
backend/   index.php (JSON API) · cli_scan.php · schema.sql · lib/{scanner,monitor,mailer,vendor,http,db}.php · tests/
src/       App.jsx · api.js · components/{Login,DeviceList,SidePanels}.jsx
```

Not included: a full IEEE OUI database (a small built-in table is used; extend `backend/lib/vendor.php`).

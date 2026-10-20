# Home Network Device Monitoring and Alert System

A web-based monitor that scans a home network, tells known devices from unknown ones, and emails an alert when something unfamiliar joins. Built for the CSN project (TMA2 interim report): a three-tier design with a cloud-hosted dashboard.

| Tier | Technology | Folder |
|------|-----------|--------|
| Dashboard | React (Vite) | `src/` |
| Backend + scanner | PHP | `backend/` |
| Storage | SQLite (default) or MySQL | `backend/schema.sqlite.sql`, `backend/schema.sql` |

## Quick start (easiest: only PHP needed)

1. Install **PHP 8+**: XAMPP (https://www.apachefriends.org, then its PHP is used automatically), or PHP from https://www.php.net/downloads, or `brew install php` on a Mac. No database, Node.js or npm is needed: the dashboard is pre-built and a SQLite database file is created automatically.
2. Download this project (Code → Download ZIP on GitHub, or `git clone`).
3. **Double-click `start.bat`** (Windows) or **`start.command`** (Mac). On Linux, or from a terminal: `php start.php`. Your browser opens at http://127.0.0.1:8080.
4. **Create an account** (any email, password of 8+ characters).
5. In **Network & alerts**, enter your network range (your computer's IP with the last number replaced by 0, e.g. `192.168.0.124` becomes `192.168.0.0/24`) and click Save.
6. Click **Scan now**. The computer must be on the home Wi-Fi or cable. Mark the devices you recognise as **Trusted**.

A scan also runs every 2 minutes while the program is open, so alerts arrive even with the browser closed. Press Ctrl+C (or close the window) to stop. If port 8080 is busy: `PORT=8081 php start.php`. For all features without a real network, set `SCAN_MODE=demo` in `backend/.env` (copy `backend/.env.example`).

Developers: `npm install`, then `npm run dev:all` (hot reload) and `npm run build` to refresh `dist/` before committing.

## How it works

```
Home computer (agent.php) --HTTPS, outgoing only--> Cloud API + MySQL <-- Dashboard (any browser)
```

- **Accounts:** each person creates an account. Their devices, alerts and history are private to that account. Each account has its own **network range** and **agent key**.
- **Home agent:** a website cannot see inside a home network, so `backend/agent.php` runs on a computer connected to the user's router. It scans the LAN and uploads the results to the API. The dashboard's **Scan now** asks the agent to scan, and the agent also scans every `AGENT_INTERVAL_S` seconds on its own, so alerts do not depend on the dashboard being open.
- **Discovery:** a ping sweep of the subnet makes active devices answer, which fills the ARP cache; the cache is then read (`arp -a` / `ip neigh`). A device counts as *online* only if it answered the ping, because the ARP cache keeps entries for ~20 minutes after a device leaves.
- **Identity:** the MAC address identifies a device (per account), because DHCP changes IPs. A changed IP is logged, never alerted.
- **Alerts:** a MAC not yet known is **Unknown** and raises one alert (dashboard + email); repeat scans do not re-alert. The first scan is a baseline with a single summary alert.
- **MAC randomisation:** an unrecognised MAC whose hostname matches a trusted device that is not currently online is treated as that phone rotating its private MAC. The record is updated quietly, with no alert.
- **Spoofing check:** a trusted MAC that suddenly reports a different hostname or manufacturer is flagged **Suspicious**.
- **Privacy and access:** only MAC, IP, hostname, manufacturer and timestamps are stored. Passwords are hashed, the API needs a signed token, and the agent has its own secret key. Use HTTPS in production.
- **Time:** all timestamps are stored in UTC and shown in the browser's local time.

## Using it (as a user)

1. Open the dashboard and **Create an account**.
2. In the **Home agent** panel, follow the three steps on a computer at home: install PHP, download this project's `backend` folder, create `backend/.env` with the two lines shown (they contain your personal key), then run `php agent.php`. The panel turns **Online**.
3. In **Network & alerts**, enter your network range (your computer's IP with the last number replaced: `192.168.0.124` becomes `192.168.0.0/24`) and your email for alerts, then Save.
4. Click **Scan now**. Mark the devices you recognise as **Trusted**; anything else stays **Unknown**.

## Configuration (server)

The backend needs no config file. Settings come from environment variables, or from `backend/.env` for local use (copy `backend/.env.example`). Real environment variables win over `.env`; `backend/config.local.php` is an optional local override. On a cloud host, set the same names in its environment settings.

| Variable | Purpose |
|----------|---------|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (or `DB_DSN`) | MySQL connection. If none is set, a SQLite file (`backend/data/monitor.sqlite`) is used |
| `SCAN_MODE` | `agent` (users' home agents scan; use this on a cloud server), `live` (this server scans its own network; use this when everything runs at home) or `demo` (simulated devices) |
| `SUBNET` | Default network range (each user can set their own) |
| `AUTH_SECRET` | Long random string used to sign login tokens. **Change it.** |
| `ALLOWED_ORIGINS` | Your deployed dashboard URL (CORS), comma separated |
| `ALLOW_REGISTRATION` | `0` closes sign-ups |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` | The account alert emails are sent *from* (e.g. a project Gmail account with an app password) |
| `AGENT_API_URL`, `AGENT_KEY`, `AGENT_INTERVAL_S` | Only for `agent.php` on the home computer (copy from the dashboard) |

The dashboard needs `VITE_API_BASE_URL` only when it is hosted apart from the API (set at build time). Never put passwords in it: it is public.

### Email alerts

Each user types their own address under **Network & alerts** and can press **Send test email**. Alerts are sent from the SMTP account in the server settings. For Gmail: create a project account, turn on 2-Step Verification, create an app password at https://myaccount.google.com/apppasswords, and set `SMTP_HOST=smtp.gmail.com`, `SMTP_PORT=465`, `SMTP_SECURE=ssl`, `SMTP_USER=<gmail address>`, `SMTP_PASS=<app password>`. Without `SMTP_HOST` PHP `mail()` is used, which needs mail software on the server. Each alert shows "email sent" or "email not sent".

## Deploying

1. Create a MySQL database on the host and import `backend/schema.sql` (note: it drops and recreates the tables), or leave the database settings empty to use SQLite if the host allows writing to `backend/data`.
2. Deploy the `backend` folder to a PHP host and set the variables above (`SCAN_MODE=agent`, `AUTH_SECRET`, `ALLOWED_ORIGINS`, `DB_*`, `SMTP_*`).
3. If the dashboard is hosted separately from the API, build it against the API: `VITE_API_BASE_URL=https://your-api.example.com npm run build`, and publish `dist/` as static files. (When PHP serves both, as with `start.php`, no setting is needed.)

## Running with XAMPP / MySQL instead (optional)

1. Start Apache and MySQL, import `backend/schema.sql` in phpMyAdmin.
2. Copy `backend` to `htdocs/network-monitor-api`, copy `backend/.env.example` to `backend/.env`, and set `DB_HOST=127.0.0.1`, `DB_NAME=home_network_monitor`, `DB_USER`, `DB_PASS` and `SCAN_MODE=live`.
3. Build with `VITE_API_BASE_URL=http://localhost/network-monitor-api npm run build` and serve `dist/` (or run `npm run dev` with that variable set).
4. Schedule `php .../cli_scan.php` every 2 minutes (cron / Task Scheduler) for scans while the dashboard is closed.

## Tests

```bash
npm run test:backend   # parsing, accounts, isolation, alerts, MAC rotation, spoofing, scan lock, agent ingest, SMTP, env config
npm run build
```

## Layout

```
backend/   index.php (JSON API) · agent.php · cli_scan.php · schema.sql · lib/ · tests/
src/       App.jsx · api.js · components/{Login,DeviceList,SidePanels}.jsx
```

Limits: only IPv4 ranges from /22 to /30; the manufacturer lookup is a small built-in table (extend `backend/lib/vendor.php`); devices that ignore pings (some sleeping phones, strict firewalls) can appear offline while connected.

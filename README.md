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
- **Privacy:** only MAC, IP, hostname, manufacturer and timestamps are stored. The dashboard needs a password, and the API needs a signed token.

## Setup (XAMPP)

1. Start Apache and MySQL, then import `backend/schema.sql` (phpMyAdmin or `mysql -u root < backend/schema.sql`).
2. Copy the `backend` folder to `C:\xampp\htdocs\network-monitor-api`.
3. Create `config.local.php` next to `config.php` to override defaults:
   ```php
   <?php return [
     'subnet' => '192.168.1.0/24',
     'db_pass' => '',
     'auth_secret' => 'a-long-random-string',
     'dashboard_password_hash' => '<php -r "echo password_hash(\'your-password\', PASSWORD_DEFAULT);">',
   ];
   ```
   The default password is `changeme`: change it.
4. Run the dashboard:
   ```bash
   npm install
   npm run dev      # http://localhost:5173
   ```
   If the API is not at `http://localhost/network-monitor-api`, copy `.env.example` to `.env` and edit `VITE_API_BASE_URL`.
5. Schedule scans, e.g. every 2 minutes (Task Scheduler / cron): `php C:\xampp\htdocs\network-monitor-api\cli_scan.php`
6. For email, configure `sendmail`/SMTP in `php.ini` (PHP `mail()` is used), then enter your address under **Email alerts** in the dashboard.

The scanner must run on a host inside the home LAN (it reads that host's ARP cache), so the PHP backend belongs on an always-on machine on the network.

## Try it without a network (demo mode)

`'scan_mode' => 'demo'` in `config.local.php` returns a simulated household with an intruder from the third scan on, and a phone that rotates its MAC.

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

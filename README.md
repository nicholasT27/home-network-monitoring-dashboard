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

## Setup guide (step by step)

You run three things on **one computer that is connected to your home Wi-Fi or router** (the scanner reads that computer's network): the MySQL database, the PHP backend, and the dashboard.

### What to install first
- **XAMPP** (https://www.apachefriends.org): gives you Apache, MySQL and PHP.
- **Node.js** LTS (https://nodejs.org): runs the dashboard.
- The project files: on GitHub use **Code → Download ZIP** (or `git clone`), then unzip.

### Step 1: start the servers
Open the XAMPP Control Panel and click **Start** next to **Apache** and **MySQL**. Both turn green.

### Step 2: create the database
1. Open http://localhost/phpmyadmin in your browser.
2. Click **Import**, then **Choose file**, select `backend/schema.sql` from the project, and click **Import** at the bottom. A database called `home_network_monitor` appears.
   (Re-importing the file later erases the data and starts fresh.)

### Step 3: install the backend
1. Copy the project's `backend` folder into XAMPP's web folder and rename it **`network-monitor-api`**:
   - Windows: `C:\xampp\htdocs\network-monitor-api`
   - Mac: `/Applications/XAMPP/xamppfiles/htdocs/network-monitor-api`
2. Find your network range. Your computer's IP address tells you:
   - Windows: open Command Prompt, run `ipconfig`, read **IPv4 Address**.
   - Mac: open Terminal, run `ipconfig getifaddr en0` (or System Settings → Wi-Fi → Details → TCP/IP).
   
   Keep the first three numbers, end with `.0/24`. For example `192.168.0.124` becomes `192.168.0.0/24`.
3. Inside the `network-monitor-api` folder, create a file named **`config.local.php`** (make sure it does not end in `.txt`) with:
   ```php
   <?php return [
     'subnet' => '192.168.0.0/24',   // your range from the step above
   ];
   ```
4. Check the backend: open http://localhost/network-monitor-api/index.php?action=status . You should see `{"error":"Not signed in"}`. That means it works.

### Step 4: start the dashboard
Open a terminal in the project folder (not the backend folder) and run:
```bash
npm install
npm run dev
```
Open http://localhost:5173 and sign in with the password **`changeme`**.

### Step 5: use it
1. Click **Scan now**. The first scan lists everything as **Unknown** and adds one "Initial scan complete" alert.
2. Click **Trust** on the devices you recognise (the pencil icon gives a device a friendly name). Anything you do not recognise stays **Unknown**.
3. From now on a new device raises an alert. A device that leaves shows as offline, and comes back online when it returns. A device only counts as online if it answers the scan.
4. Click **Scan now** again whenever you want an update. To scan automatically, even with the dashboard closed, schedule `cli_scan.php` (next section).

### Optional: scan automatically every 2 minutes
- **Windows:** Task Scheduler → Create Basic Task → repeat every 2 minutes → run `C:\xampp\php\php.exe` with the argument `C:\xampp\htdocs\network-monitor-api\cli_scan.php`.
- **Mac / Linux:** run `crontab -e` and add `*/2 * * * * /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/network-monitor-api/cli_scan.php`.

To test it once by hand, run the same command in a terminal: it prints something like `8 devices, 0 new unknown, 9.1s`.

### Optional: email alerts
Type your address in the **Email alerts** box and click **Save**. Emails are sent with PHP's `mail()`, which only works if mail is set up on your computer (`sendmail`/SMTP in `php.ini`). A default XAMPP has none, so by default alerts appear in the dashboard only (marked "email not sent" if mail is not configured).

### Change the password
Run `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"` (PHP is at `C:\xampp\php\php.exe` on Windows) and add the result to `config.local.php` as `'dashboard_password_hash' => '...'`.

### If something goes wrong
| What you see | Cause and fix |
|---|---|
| "Cannot reach the monitoring API" | Apache is not running, or the folder is not named `network-monitor-api`. If you use another address, copy `.env.example` to `.env`, edit `VITE_API_BASE_URL`, and restart `npm run dev`. |
| "Database error" | MySQL is not running, `schema.sql` was not imported, or your MySQL has a password: add `'db_pass' => '...'` to `config.local.php`. |
| Scan finds 0 devices | Your range in `config.local.php` does not match your network (check the IP again), or the computer is not on the home Wi-Fi. |
| "A scan is already running" | Wait a minute and try again; if it keeps happening restart Apache. |
| Browser blocks the request (CORS) | The dashboard is on a port other than 5173. Add its address to `allowed_origins` in `config.local.php`. |
| Sign-in fails | The default password is `changeme`, unless you changed it. |

## Try it without a network (demo mode)

The scanner must run on a computer inside the home network (it reads that computer's ARP cache). A cloud server cannot scan a home network.


Add `'scan_mode' => 'demo',` to `config.local.php` to get a simulated household, with an intruder from the third scan on and a phone that rotates its MAC. It lets you try every feature without a real network.

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

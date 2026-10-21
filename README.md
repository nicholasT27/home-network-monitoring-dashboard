# Home Network Device Monitoring and Alert System

A web dashboard that shows which devices are connected to your home network, tells **Trusted** devices from **Unknown** ones, and emails you when something unfamiliar joins. Built for the CSN project (TMA2 interim report).

```
Home computer (agent.php) ──HTTPS, outgoing only──►  Web host: PHP API + MySQL  ◄── Dashboard in any browser
   scans your home network                            stores each account's devices      (log in from anywhere)
```

A website cannot see inside a home network, so a small **agent** runs on a computer at home. It scans the network and uploads the results to the web host. The dashboard shows them, and the host emails the alerts.

| Part | Technology | Folder |
|------|-----------|--------|
| Dashboard | React (Vite), pre-built in `dist/` | `src/`, `dist/` |
| API + scanner agent | PHP | `backend/` |
| Database | MySQL (SQLite for local testing) | `backend/schema*.sql` |

**Who does what**

| Role | Does | Needs |
|---|---|---|
| **Owner** (you, once) | Puts the dashboard + API on a web host | A PHP 8 + MySQL web host, a browser |
| **Each user** (you, your teacher, a friend) | Creates an account, runs the agent at home | An email address, one computer at home with PHP |

---

# Part 1: Owner setup (do this once)

### 1. Get a web host
Any hosting with **PHP 8+, MySQL, phpMyAdmin, HTTPS** and a file manager or FTP works (a free host such as InfinityFree, a paid shared host such as Hostinger, or a university server). Create a **MySQL database** in the host's control panel and note four things: *database host* (often `localhost`), *database name*, *user*, *password*.

### 2. Create the tables
1. Open the host's **phpMyAdmin** and click your database on the left.
2. Click **Import**, choose **`backend/schema.shared-hosting.sql`**, and click **Import**. Six tables appear.
(Importing again later erases all data.)

### 3. Upload the files
Download this project (GitHub → **Code → Download ZIP**, unzip it). In the host's File Manager (or FTP), open the public web folder (usually `public_html`) and upload:

| From the project | To the host |
|---|---|
| everything **inside** the `dist` folder (`index.html`, `config.js`, `assets/`) | `public_html/` |
| the whole `backend` folder, renamed **`api`** | `public_html/api/` |

Then open `public_html/config.js` and set the API folder:
```js
window.NETMON_API_BASE = '/api';
```

### 4. Configure the API
In `public_html/api/` create a file named **`.env`** (a plain text file, not `.env.txt`):
```
DB_HOST=localhost
DB_NAME=your_database_name
DB_USER=your_database_user
DB_PASS=your_database_password

SCAN_MODE=agent
AUTH_SECRET=type-a-long-random-sentence-here
```
`SCAN_MODE=agent` tells the host that users' home agents do the scanning.

### 5. Check it works
1. Open `https://your-site/api/index.php?action=status`. You should see `{"error":"Not signed in"}`.
2. Open `https://your-site/`. You should see the sign-in page.
3. Security check: open `https://your-site/api/.env`. It must show **403 / Forbidden / Not found**. If it shows the file contents, your host ignores `.htaccess`: delete the `.env` file and set the same names as environment variables in the host's panel instead.

### 6. Optional: email alerts
Alerts are emailed from one sender account that you set up once; each user types only their own address.
1. Create a Gmail account for the project, turn on **2-Step Verification**, then create an **app password** at https://myaccount.google.com/apppasswords (16 characters).
2. Add to `public_html/api/.env`:
   ```
   SMTP_HOST=smtp.gmail.com
   SMTP_PORT=465
   SMTP_SECURE=ssl
   SMTP_USER=your.project.account@gmail.com
   SMTP_PASS=your16characterapppassword
   ```
3. In the dashboard, enter an email address and press **Send test email**. If it fails, your host may block outgoing email (some free hosts do); alerts still appear in the dashboard.

To close sign-ups after your teacher has registered, add `ALLOW_REGISTRATION=0`.

**Send users this:** the dashboard link (`https://your-site/`) and the "Part 2" instructions below.

---

# Part 2: User setup (each person)

You need an email address, a web browser, and **one computer at home that stays on and is connected to your home Wi-Fi or router**.

### Step 1: Create your account (1 minute)
1. Open the dashboard link. Click **New here? Create an account**.
2. Enter your email and a password of at least 8 characters, then click **Create account**.
3. You see an empty dashboard and a **Home agent** box showing **Offline**. Keep this page open: it shows two lines you will copy.

### Step 2: Start the agent on the home computer (10 minutes the first time)
1. **Install PHP.** The easiest way is **XAMPP** (https://www.apachefriends.org). You only need the installer; you do not have to start any XAMPP service.
2. **Download the project:** GitHub → **Code → Download ZIP**, then unzip it. You only need its `backend` folder.
3. **Create the agent settings file.** Inside the `backend` folder, create a text file named **`.env`** and paste the two lines from the **Home agent** box (use its **Copy** button):
   ```
   AGENT_API_URL=https://your-site/api/index.php
   AGENT_KEY=your-personal-key
   ```
   (Windows tip: in File Explorer choose View → Show → File name extensions, so the file is `.env` and not `.env.txt`.)
4. **Run the agent.**
   - **Windows:** open the `backend` folder in File Explorer, click the address bar, type `cmd`, press Enter, then run:
     ```
     C:\xampp\php\php.exe agent.php
     ```
   - **Mac:** open Terminal, go to the folder (for example `cd ~/Downloads/home-network-monitoring-dashboard/backend`), then run:
     ```
     /Applications/XAMPP/xamppfiles/bin/php agent.php
     ```
     (If you installed PHP with Homebrew, `php agent.php` works.)
5. You should see `Home agent running…`. **Leave the window open.** Within a few seconds the dashboard's Home agent box turns **Online**.

### Step 3: Set your network (1 minute)
In the dashboard, open **Network & alerts**:
1. **Your network range:** find your computer's IP address (Windows: `ipconfig`, read *IPv4 Address*; Mac: `ipconfig getifaddr en0`). Keep the first three numbers and end with `.0/24`. For example `192.168.0.124` becomes **`192.168.0.0/24`**.
2. **Email for alerts:** your email address.
3. Click **Save**.

### Step 4: Scan
1. Click **Scan now**. The dashboard says *Waiting for the home agent…*, then lists every device on your network as **Unknown**, with one "Initial scan complete" alert.
2. Click **Trust** on the devices you recognise. The pencil icon gives a device a friendly name. Anything you do not recognise stays **Unknown**.

### Daily use
- The agent scans by itself every 2 minutes while it is running. You can also click **Scan now**.
- A **new device** raises an alert in the dashboard and an email. A device that leaves shows as offline. A trusted device that suddenly reports a different name is marked **Suspicious**.
- You can open the dashboard from any phone or computer. The **agent window must stay open** on the home computer; if it is closed, the Home agent box shows **Offline** and the device list stops updating.
- To start it again next time, repeat Step 2, point 4.

Keep your agent key private: it lets a program send data into your account.

---

# Part 3: For the teacher (marking)

Two ways to try the system:
- **Quick look (nothing to install):** open the dashboard link and sign in with the demo login the student gives you. The student's home agent shows their live home network. Click around: **Trust / Untrust**, rename, history, alerts, filters.
- **Your own network:** create your own account (Part 2). Install XAMPP, download the project ZIP, and run the agent on a computer at home. You will see only your own devices.

---

# Troubleshooting

| What you see | Cause and fix |
|---|---|
| Dashboard says "Cannot reach the monitoring server" | `config.js` is wrong (should be `'/api'` for the layout above), or the `api` folder was not uploaded. |
| `{"error":"Database error…"}` | Wrong `DB_*` values in `.env`, or `schema.shared-hosting.sql` was not imported into that database. |
| Home agent box stays **Offline** | The agent window is closed, the `.env` next to `agent.php` is missing or named `.env.txt`, or the key/URL was copied wrongly. Copy both lines again from the dashboard. |
| Agent prints `API error … (401)` | Wrong key. Copy the key again from the dashboard. |
| Agent prints `Set AGENT_API_URL and AGENT_KEY first` | `.env` is not in the same folder as `agent.php`. |
| "Waiting for the home agent…" then an error | The agent is not running, or it cannot reach the internet. |
| Scan finds 0 devices | Wrong network range (check your IP again), or the agent computer is not on your home network. |
| A phone shows offline but is connected | Some devices do not answer pings when asleep. Wake the phone and scan again. |
| Test email fails | Gmail app password wrong, or the host blocks outgoing email. Alerts still appear in the dashboard. |
| Windows: `php is not recognized` | Use the full path `C:\xampp\php\php.exe agent.php`. |
| Mac: "cannot be opened" | Use the full XAMPP path shown in Step 2. |

---

# Try everything on one computer (testing)

You do not need a web host to test. Install PHP, then in the project folder:
```bash
php start.php            # or double-click start.bat (Windows) / start.command (Mac)
```
It opens http://127.0.0.1:8080 with the dashboard, API and a SQLite database in one program, and scans the computer's own network (`SCAN_MODE=live`). Create an account, enter your range, click **Scan now**. For simulated devices without a real network, create `backend/.env` containing `SCAN_MODE=demo`.

---

# How it works

- **Discovery:** the agent pings every address in your range (which makes active devices answer and fills the ARP cache), then reads the ARP cache (`arp -a` / `ip neigh`). A device counts as **online** only if it answered the ping, because the cache keeps entries for ~20 minutes after a device leaves.
- **Identity:** the MAC address identifies a device (per account), because DHCP changes IP addresses. A changed IP is logged, never alerted.
- **Alerts:** a MAC not yet known is **Unknown** and raises one alert (dashboard + email); repeat scans do not re-alert. The first scan is a baseline with a single summary alert.
- **MAC randomisation:** an unrecognised MAC whose hostname matches a trusted device that is not currently online is treated as that phone rotating its private MAC; the record is updated quietly, with no alert.
- **Spoofing check:** a trusted MAC that suddenly reports a different hostname or manufacturer is flagged **Suspicious**.
- **Privacy and access:** only MAC, IP, hostname, manufacturer and timestamps are stored. Each account sees only its own data. Passwords are hashed, the API needs a signed token, and each agent has its own secret key. Use HTTPS.
- **Time:** timestamps are stored in UTC and shown in your browser's local time.

## Configuration (server)

Settings are read from `backend/.env` (or real environment variables, which win). `backend/.env.example` lists them.

| Variable | Purpose |
|----------|---------|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` (or `DB_DSN`) | MySQL. If none is set, a SQLite file is used |
| `SCAN_MODE` | `agent` (home agents scan; use on a web host), `live` (this server scans its own network), `demo` (simulated devices) |
| `AUTH_SECRET` | Long random string that signs login tokens. **Change it.** |
| `ALLOW_REGISTRATION` | `0` closes sign-ups |
| `ALLOWED_ORIGINS` | Only if the dashboard is on a different address than the API |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_SECURE`, `SMTP_USER`, `SMTP_PASS` | Sender account for alert emails |
| `AGENT_API_URL`, `AGENT_KEY`, `AGENT_INTERVAL_S` | Only for `agent.php` at home (copy from the dashboard) |

## Tests and development

```bash
php backend/tests/run.php   # 43 backend tests: parsing, accounts, isolation, alerts, MAC rotation, spoofing, scan lock, agent, SMTP, env config
npm install && npm run build   # rebuilds dist/ (commit it, so users need no Node.js)
npm run dev:all             # dashboard with hot reload + API
```

## Layout

```
dist/      the built dashboard (upload this) · public/config.js sets where the API is
backend/   index.php (JSON API) · agent.php (home agent) · cli_scan.php · schema*.sql · lib/ · tests/
src/       React source · start.php / start.bat / start.command (run everything locally)
docs/      REPORT_NOTES.md (architecture and "changes from the interim report" wording)
```

Limits: IPv4 ranges from /22 to /30; the manufacturer lookup is a small built-in table (`backend/lib/vendor.php`); devices that ignore pings can appear offline while connected; the agent must stay running at home.

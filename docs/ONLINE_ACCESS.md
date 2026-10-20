# Online access with a tunnel (dashboard on the web, scanner at home)

A website cannot scan a home network: the devices have private addresses behind the router, and only a computer **inside** the home can ping them or read its ARP table. So the scanner has to run at home. A **tunnel** lets the dashboard reach it from anywhere over HTTPS, without opening any router ports and without a database in the cloud.

```
Browser ──► Dashboard (Vercel, static React site)
                │  HTTPS (the backend address you type on the sign-in page)
                ▼
         Tunnel (Cloudflare or ngrok)
                │  outgoing connection made by your computer, nothing opened on the router
                ▼
   Your computer at home:  PHP backend (127.0.0.1:8081)  ──►  ping sweep + ARP scan of the home network
                           MySQL (stays on this computer)
```

- Device data (MAC addresses, hostnames) is stored only on the home computer.
- The tunnel exposes **only the JSON API** (`backend/router.php` returns 404 for everything else, so no SQL or config files, and no phpMyAdmin).
- The dashboard needs the password, and the backend only accepts browser requests from the dashboard address you allow.

## What each person needs

| Who | Needs |
|---|---|
| **Whoever publishes the dashboard** (once) | A free Vercel account (or Netlify). No installs. |
| **Whoever scans their home** (you, or your teacher) | **XAMPP** (PHP + MySQL), **cloudflared** (or ngrok), and the project folder (Code → Download ZIP). Node.js is **not** needed. |
| **Anyone who only wants to look** | A browser, the dashboard link, the password and the tunnel address. |

The computer at home must be on, connected to the home Wi-Fi/cable, and running the backend and the tunnel while you use the dashboard. If it is off, the dashboard shows "Cannot reach the backend".

---

## Part A: publish the dashboard on Vercel (once)

1. Make sure the project is on GitHub, on the branch you want to publish (Vercel's *production* branch is normally `main`; other branches get preview addresses).
2. Sign in at https://vercel.com with GitHub, choose **Add New → Project**, and import this repository.
3. Keep the detected settings: Framework **Vite**, Build command `npm run build`, Output directory `dist`. No environment variables are needed.
4. Click **Deploy**. You get an address like `https://home-network-monitoring-dashboard.vercel.app`. **Write it down**: the backend must allow it (Part B, step 3).

## Part B: set up the scanner at home

### 1. Install and prepare
1. Install **XAMPP** (https://www.apachefriends.org). Download the project ZIP and unzip it anywhere (no need to copy it into `htdocs`).
2. In the XAMPP Control Panel start **MySQL** and **Apache**.
3. Open http://localhost/phpmyadmin → **Import** → choose `backend/schema.sql` → **Import**. (Apache can be stopped afterwards: the backend only needs MySQL.)

### 2. Find your network range
- Windows: Command Prompt → `ipconfig` → **IPv4 Address**. Mac: Terminal → `ipconfig getifaddr en0`.
- Keep the first three numbers and end with `.0/24`: `192.168.0.124` becomes `192.168.0.0/24`.

### 3. Create `backend/config.local.php`
Inside the project's `backend` folder, create the file (the name must not end in `.txt`):
```php
<?php return [
  'subnet'        => '192.168.0.0/24',                          // your range from step 2
  'extra_origins' => ['https://your-dashboard.vercel.app'],     // your dashboard address from Part A
  'auth_secret'   => 'type-a-long-random-sentence-here',
  'dashboard_password_hash' => '<paste the hash from below>',
];
```
Make a password hash by running this in a terminal (Windows: use `C:\xampp\php\php.exe`; Mac: `/Applications/XAMPP/xamppfiles/bin/php`):
```
php -r "echo password_hash('your-strong-password', PASSWORD_DEFAULT);"
```
Paste the output between the quotes. **Do not keep the default password `changeme`**: the tunnel puts this login on the internet.

### 4. Start the backend
Double-click **`start-backend.bat`** (Windows) or **`start-backend.command`** (Mac; the first time right-click → Open). Leave the window open.
Check it: open http://127.0.0.1:8081/index.php?action=status in a browser. You should see `{"error":"Not signed in"}`.

### 5. Start the tunnel

**Option 1: Cloudflare quick tunnel (free, no account)**
1. Install cloudflared:
   - Mac: `brew install cloudflared`
   - Windows: download the Windows (64-bit) cloudflared from Cloudflare's downloads page (search "cloudflared downloads"), rename the file to `cloudflared.exe`, and put it in a folder you can open a Command Prompt in.
2. Run:
   ```
   cloudflared tunnel --url http://127.0.0.1:8081
   ```
3. After a few seconds it prints an address like `https://random-words-1234.trycloudflare.com`. **Copy it** and leave the window open. This address changes every time you start the tunnel.

**Option 2: ngrok (free account, can keep the same address)**
1. Create a free account at https://ngrok.com, install ngrok, and add your token: `ngrok config add-authtoken <your token>`.
2. Run `ngrok http 8081`. (With a free static domain from the ngrok dashboard: `ngrok http --url=your-name.ngrok-free.app 8081`.) Copy the `https://…` address it shows.

### 6. Sign in from the dashboard
1. Open your dashboard address (`https://….vercel.app`).
2. In **Backend address**, paste the tunnel address (just the address; no `/index.php`). Enter the password and click **Sign in**. The browser remembers the address; paste a new one only when the tunnel address changes.
3. Click **Scan now**, then **Trust** the devices you recognise.

For automatic scans while the dashboard is closed, schedule `php backend/cli_scan.php` every 2 minutes (see the README).

## Part C: what your teacher does to run it at *their* home
1. Install XAMPP and cloudflared; download the project ZIP.
2. Follow Part B with **their** network range and **their** password.
3. Open the same dashboard link (Part A) and paste **their** tunnel address. They see their own network; you see yours; nobody sees the other's data, because each home has its own backend and database.
(For `extra_origins` they use the same dashboard address.)

---

## Security checklist
- Use a long, unique dashboard password and a random `auth_secret`.
- List only your dashboard address in `extra_origins`.
- Stop the tunnel (close its window) when you are not using the dashboard.
- Quick tunnels are meant for testing and demos, with no uptime guarantee.
- MySQL is never exposed: only the API port is tunnelled. Do **not** tunnel XAMPP's port 80, which would expose phpMyAdmin.

## Troubleshooting
| Symptom | Fix |
|---|---|
| "Cannot reach the backend…" | The backend window or the tunnel is closed, or the address is wrong or old (quick tunnel addresses change). Paste the current one. |
| Browser console shows a CORS error | Your dashboard address is missing from `extra_origins` in `config.local.php` (it must match exactly, e.g. `https://name.vercel.app` with no trailing slash). Restart the backend after editing. |
| "…must also start with https://" | The dashboard is on https, so use the tunnel's `https://` address, not `http://127.0.0.1…`. |
| `{"error":"Database error…"}` | MySQL is not running, `schema.sql` was not imported, or MySQL has a password (add `'db_pass' => '…'`). |
| Scan finds 0 devices | Wrong `subnet`, or the computer is not on the home network. |
| "Incorrect password" | You are using the password from `config.local.php`, not `changeme`. Make sure the hash was pasted completely. |
| ngrok shows a warning page | The dashboard already sends the header that skips it; check you used the `https://` address. |

---

## Wording for the report

**Deployment architecture.** The system is a three-tier web application. The PHP back end, which performs the ping sweep and ARP discovery, and the MySQL database run on an always-on host inside the home network, because only a host on the local network can resolve MAC addresses through ARP. The React dashboard is a static web page published online. A reverse-proxy tunnel (Cloudflare Tunnel) creates an outbound, TLS-encrypted connection from the home host to the provider's edge, so the dashboard can reach the API at a public HTTPS address without port forwarding or exposing the router. Device data (MAC address, hostname, timestamps) stays on the home host. The API accepts requests only from the dashboard's origin (CORS), requires a signed token obtained with a password, and the tunnel exposes only the API endpoint.

**Why a tunnel, not a cloud scanner.** Testing showed a cloud-hosted server cannot discover home devices: they use private addresses behind NAT, and ICMP and ARP do not cross the router. The scanner therefore had to remain on the home network, and a tunnel provides remote access to it without moving the device data to a third party.

**Limitations.** The dashboard is available only while the home host and tunnel are running. Free quick tunnels give a changing address and no uptime guarantee. Each home needs its own back end, so the design suits one household per deployment rather than a hosted multi-user service.

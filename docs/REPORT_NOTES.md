# Notes for the final report

## Architecture (cloud dashboard + home agent)

The system is a three-tier web application. A React dashboard and a PHP API with a MySQL database are hosted on a web server. Because only a host inside the home network can discover devices through ICMP echo and ARP, a lightweight PHP agent runs on an always-on computer in the home. The agent performs the ping sweep and reads the ARP cache, then uploads the result to the API over HTTPS using a personal secret key. The API compares each detected MAC address with the account's stored devices, classifies it as trusted or unknown, records connection history, and sends an email alert for unknown or suspicious devices. The dashboard, protected by account authentication, can be opened from any browser, and each account can see only its own devices.

## How this relates to the Interim Report

| Interim Report | Final system |
|---|---|
| Web application with PHP back end, MySQL, React front end | Same |
| Scanning on a single always-on host in the home | The home agent is that host |
| Data sent between the home network and a cloud server over HTTPS/TLS | The agent uploads over HTTPS to the web host |
| Access protected by authentication so data is visible only to the account holder | User accounts; every record is scoped to its account; per-account agent keys |
| MAC address as the primary identifier | Same (per account) |
| Email alert for unknown devices | Same, sent through an SMTP account |
| Hostname check to avoid false alarms from MAC randomisation | Same |
| Trusted device with changed hostname/manufacturer flagged | Same (marked Suspicious) |
| Alert latency under 120 s, scan under 120 s | Scheduled scans every 2 minutes; scan duration shown in the dashboard |
| Dashboard reachable from any device "on the network" | Also reachable from outside the home, because the dashboard is online |

## What was learned in testing (changes and challenges)

- **A server outside the home cannot scan it.** Devices use private addresses behind NAT and ICMP/ARP do not cross the router, so the scanner had to stay in the home and send results out (the agent).
- **ARP cache entries outlive devices.** A phone that left Wi-Fi stayed in the cache for about 20 minutes. A device is now online only if it answers the ping.
- **Mac ping timeout units** (milliseconds on macOS, seconds on Linux) needed a platform-specific value.
- **Time zones.** Server and browser clocks differed; times are stored in UTC and converted in the browser.
- **Scan lock.** A temporary-file lock failed for the web server user; it moved into the database.
- **Reverse-DNS lookups could stall a scan,** so they now get a short time budget.
- **Old database tables** from an earlier project caused a missing-column error; the schema uses its own database name.

## Limitations
- The agent must keep running at home; when it stops, the dashboard shows the last known data and "agent offline".
- Devices that ignore pings (for example some sleeping phones) can appear offline while connected.
- MAC randomisation handling relies on the hostname staying the same.
- Each household has to run the agent on a computer at home.

## Future work
A double-click agent (no PHP install), push notifications, and a fuller manufacturer (OUI) database.

# Home Network Monitoring Dashboard

A local React dashboard for monitoring devices registered in the XAMPP `network-monitor-api` project.

## Open and run in VS Code

1. Start Apache and MySQL in the XAMPP Manager.
2. Open this `home-network-monitoring-dashboard` folder in VS Code.
3. Open **Terminal → New Terminal** and run:

   ```bash
   npm install
   npm run dev
   ```

4. Open the address shown in the terminal, normally `http://localhost:5173`.

The dashboard expects the PHP API at `http://localhost/network-monitor-api`. To use another address, copy `.env.example` to `.env`, edit `VITE_API_BASE_URL`, and restart the development server.

## Install the PHP API

The companion files are in the adjacent `network-monitor-api-updates` folder. Follow its README to import the database schema and copy the PHP files into XAMPP.

## How monitoring works

- Devices are registered manually using their name and IP address; MAC address is optional.
- **Run status check** pings every registered device.
- One open alert is created when a device is offline. Repeated checks do not create duplicate open alerts.
- The alert is resolved automatically when a later check finds the device online.
- The page shows registered, online, offline, and active-alert totals.

Automatic discovery of phones or other devices is not included in this prototype.

## Production build

Run `npm run build`. The optimized site is written to the `dist` folder.

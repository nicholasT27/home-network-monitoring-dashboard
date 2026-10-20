package main

import (
	"database/sql"
	"errors"
	"fmt"
	"strings"
	"sync"
	"time"
)

// App holds the shared state of the running program.
type App struct {
	db     *sql.DB
	secret string // signs login tokens
	demo   bool   // simulated devices instead of a real scan
	locks  sync.Map
}

type Summary struct {
	StartedAt    string `json:"started_at"`
	DurationMS   int64  `json:"duration_ms"`
	DevicesFound int    `json:"devices_found"`
	NewUnknown   int    `json:"new_unknown"`
}

type pendingMail struct {
	alertID         int64
	to, subj, body string
}

type devRow struct {
	Hostname, Vendor, Label, Status string
	Suspicious, Online              bool
	IP                              string
}

func (a *App) smtpConfig() SMTPConfig {
	return SMTPConfig{
		Host: orDefault(getSetting(a.db, "smtp_host"), "smtp.gmail.com"), Port: orDefault(getSetting(a.db, "smtp_port"), "465"),
		User: getSetting(a.db, "smtp_user"), Pass: getSetting(a.db, "smtp_pass"), Secure: orDefault(getSetting(a.db, "smtp_secure"), "ssl"),
	}
}

func orDefault(v, d string) string {
	if v == "" {
		return d
	}
	return v
}

func sameName(a, b string) bool { return a != "" && b != "" && strings.EqualFold(a, b) }

// processScan applies one scan result to the user's data: new devices, alerts, MAC rotation, spoofing
// checks and online/offline tracking. Everything is scoped by user_id so households never mix.
func (a *App) processScan(uid int64, found []Found, source string, started time.Time) (Summary, error) {
	tx, err := a.db.Begin()
	if err != nil {
		return Summary{}, err
	}
	defer tx.Rollback()
	at := now()

	var alertEmail string
	var ae sql.NullString
	_ = tx.QueryRow(`SELECT alert_email FROM users WHERE id = ?`, uid).Scan(&ae)
	alertEmail = ae.String

	var scanCount int
	_ = tx.QueryRow(`SELECT COUNT(*) FROM scans WHERE user_id = ?`, uid).Scan(&scanCount)
	baseline := scanCount == 0 // first scan: one summary alert instead of one per device

	var mails []pendingMail
	history := func(mac, ip, host, event string) {
		_, _ = tx.Exec(`INSERT INTO connection_history (user_id, mac, ip, hostname, event, event_time) VALUES (?,?,?,?,?,?)`, uid, mac, nullable(ip), nullable(host), event, at)
	}
	raise := func(typ, mac, ip, host, msg string) {
		res, _ := tx.Exec(`INSERT INTO alerts (user_id, mac, ip, hostname, type, message, email_sent, created_at) VALUES (?,?,?,?,?,?,0,?)`, uid, mac, nullable(ip), nullable(host), typ, msg, at)
		if alertEmail != "" && !a.demo {
			id, _ := res.LastInsertId()
			subject := "Network alert: suspicious device"
			if typ == "unknown_device" {
				subject = "Network alert: unknown device detected"
			}
			body := fmt.Sprintf("%s\n\nIP address:  %s\nMAC address: %s\nHostname:    %s\nDetected at: %s UTC\n\nOpen your dashboard to trust or investigate this device.", msg, orDash(ip), mac, orDash(host), at)
			mails = append(mails, pendingMail{id, alertEmail, subject, body})
		}
	}

	foundMacs := map[string]bool{}
	for _, f := range found {
		foundMacs[f.MAC] = true
	}
	seen := map[string]bool{}
	newUnknown := 0

	for _, f := range found {
		vendor := vendorFor(f.MAC)
		if f.Alive {
			seen[f.MAC] = true
		}
		var d devRow
		var host, ven, lab sql.NullString
		var susp, online int
		err := tx.QueryRow(`SELECT ip, hostname, vendor, label, status, suspicious, online FROM devices WHERE user_id = ? AND mac = ?`, uid, f.MAC).
			Scan(&d.IP, &host, &ven, &lab, &d.Status, &susp, &online)
		d.Hostname, d.Vendor, d.Label, d.Suspicious, d.Online = host.String, ven.String, lab.String, susp != 0, online != 0

		if err == nil { // known device
			if f.Alive && !d.Online {
				history(f.MAC, f.IP, f.Hostname, "joined")
			}
			if f.Alive && d.IP != f.IP {
				history(f.MAC, f.IP, f.Hostname, "ip_changed") // routine DHCP change: no alert
			}
			if f.Alive {
				_, _ = tx.Exec(`UPDATE devices SET ip = ?, online = 1, last_seen = ?, hostname = COALESCE(hostname, ?), vendor = COALESCE(vendor, ?) WHERE user_id = ? AND mac = ?`,
					f.IP, at, nullable(f.Hostname), nullable(vendor), uid, f.MAC)
			}
			// A stale ARP entry (no ping reply) leaves the device as it was; the offline sweep below handles it.

			// MAC-spoofing safety check: a trusted device must not change identity.
			if d.Status == "trusted" && !d.Suspicious {
				name := d.Label
				if name == "" {
					name = orDefault(d.Hostname, f.MAC)
				}
				flag := func(reason string) {
					_, _ = tx.Exec(`UPDATE devices SET suspicious = 1, suspicious_reason = ? WHERE user_id = ? AND mac = ?`, reason, uid, f.MAC)
					raise("suspicious_device", f.MAC, f.IP, f.Hostname, fmt.Sprintf("Trusted device \"%s\" looks different: %s", name, reason))
				}
				if f.Hostname != "" && d.Hostname != "" && !sameName(f.Hostname, d.Hostname) {
					flag(fmt.Sprintf("hostname changed from %s to %s (possible MAC spoofing)", d.Hostname, f.Hostname))
				} else if vendor != "" && d.Vendor != "" && vendor != d.Vendor {
					flag(fmt.Sprintf("manufacturer changed from %s to %s", d.Vendor, vendor))
				}
			}
			continue
		}

		// Unrecognised MAC. Is it a trusted phone that rotated its private MAC? Match on hostname, but only
		// if that trusted device is not also present right now under its old MAC.
		rotated := false
		if f.Hostname != "" {
			rows, _ := tx.Query(`SELECT mac FROM devices WHERE user_id = ? AND status = 'trusted' AND LOWER(hostname) = LOWER(?)`, uid, f.Hostname)
			var olds []string
			for rows.Next() {
				var m string
				_ = rows.Scan(&m)
				olds = append(olds, m)
			}
			rows.Close()
			for _, old := range olds {
				if foundMacs[old] {
					continue
				}
				on := 0
				if f.Alive {
					on = 1
				}
				_, _ = tx.Exec(`UPDATE devices SET mac = ?, ip = ?, online = ?, last_seen = ? WHERE user_id = ? AND mac = ?`, f.MAC, f.IP, on, at, uid, old)
				_, _ = tx.Exec(`UPDATE connection_history SET mac = ? WHERE user_id = ? AND mac = ?`, f.MAC, uid, old)
				history(f.MAC, f.IP, f.Hostname, "mac_rotated")
				rotated = true
				break
			}
		}
		if rotated {
			continue
		}

		on := 0
		if f.Alive {
			on = 1
		}
		_, _ = tx.Exec(`INSERT INTO devices (user_id, mac, ip, hostname, vendor, status, online, first_seen, last_seen) VALUES (?,?,?,?,?,'unknown',?,?,?)`,
			uid, f.MAC, f.IP, nullable(f.Hostname), nullable(vendor), on, at, at)
		history(f.MAC, f.IP, f.Hostname, "first_seen")
		if !baseline {
			raise("unknown_device", f.MAC, f.IP, f.Hostname, "An unknown device joined your network.")
		}
		newUnknown++
	}

	if baseline && newUnknown > 0 {
		_, _ = tx.Exec(`INSERT INTO alerts (user_id, mac, type, message, created_at) VALUES (?, '-', 'baseline', ?, ?)`, uid,
			fmt.Sprintf("First scan found %d devices. Mark the ones you recognise as Trusted; anything you don't recognise stays Unknown.", newUnknown), at)
	}

	// Anything previously online but not seen answering this time has left.
	type gone struct{ mac, ip, host string }
	var left []gone
	rows, _ := tx.Query(`SELECT mac, ip, hostname FROM devices WHERE user_id = ? AND online = 1`, uid)
	for rows.Next() {
		var g gone
		var h sql.NullString
		_ = rows.Scan(&g.mac, &g.ip, &h)
		g.host = h.String
		if !seen[g.mac] {
			left = append(left, g)
		}
	}
	rows.Close()
	for _, g := range left {
		_, _ = tx.Exec(`UPDATE devices SET online = 0 WHERE user_id = ? AND mac = ?`, uid, g.mac)
		history(g.mac, g.ip, g.host, "left")
	}

	dur := time.Since(started).Milliseconds()
	_, _ = tx.Exec(`INSERT INTO scans (user_id, started_at, duration_ms, devices_found, new_unknown, source) VALUES (?,?,?,?,?,?)`, uid, at, dur, len(found), newUnknown, source)
	if err := tx.Commit(); err != nil {
		return Summary{}, err
	}

	if len(mails) > 0 { // email after the data is safely stored, without holding up the scan
		go func(list []pendingMail) {
			for _, m := range list {
				if sendMail(a.smtpConfig(), m.to, m.subj, m.body) == nil {
					_, _ = a.db.Exec(`UPDATE alerts SET email_sent = 1 WHERE id = ?`, m.alertID)
				}
			}
		}(mails)
	}
	return Summary{StartedAt: at, DurationMS: dur, DevicesFound: len(found), NewUnknown: newUnknown}, nil
}

func orDash(s string) string { return orDefault(s, "-") }

var errScanRunning = errors.New("A scan is already running. Wait a moment and try again.")

// runScan scans on this computer for one user's network range.
func (a *App) runScan(u *User, source string) (Summary, error) {
	lk, _ := a.locks.LoadOrStore(u.ID, &sync.Mutex{})
	mu := lk.(*sync.Mutex)
	if !mu.TryLock() {
		return Summary{}, errScanRunning
	}
	defer mu.Unlock()

	started := time.Now()
	var found []Found
	if a.demo {
		var n int
		_ = a.db.QueryRow(`SELECT COUNT(*) FROM scans WHERE user_id = ?`, u.ID).Scan(&n)
		found = demoScan(n + 1)
	} else {
		var err error
		if found, err = scanNetwork(orDefault(u.Subnet, detectSubnet())); err != nil {
			return Summary{}, err
		}
	}
	return a.processScan(u.ID, found, source, started)
}

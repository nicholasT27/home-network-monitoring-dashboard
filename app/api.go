package main

import (
	"database/sql"
	"encoding/json"
	"io/fs"
	"net/http"
	"net/mail"
	"path"
	"strings"
	"time"
)

type deviceJSON struct {
	MAC              string  `json:"mac"`
	IP               string  `json:"ip"`
	Hostname         *string `json:"hostname"`
	Vendor           *string `json:"vendor"`
	Label            *string `json:"label"`
	Status           string  `json:"status"`
	Suspicious       int     `json:"suspicious"`
	SuspiciousReason *string `json:"suspicious_reason"`
	Online           int     `json:"online"`
	FirstSeen        string  `json:"first_seen"`
	LastSeen         string  `json:"last_seen"`
}

type alertJSON struct {
	ID             int64   `json:"id"`
	MAC            string  `json:"mac"`
	IP             *string `json:"ip"`
	Hostname       *string `json:"hostname"`
	Type           string  `json:"type"`
	Message        string  `json:"message"`
	EmailSent      int     `json:"email_sent"`
	CreatedAt      string  `json:"created_at"`
	AcknowledgedAt *string `json:"acknowledged_at"`
}

type historyJSON struct {
	ID        int64   `json:"id"`
	MAC       string  `json:"mac"`
	IP        *string `json:"ip"`
	Hostname  *string `json:"hostname"`
	Event     string  `json:"event"`
	EventTime string  `json:"event_time"`
}

type scanJSON struct {
	ID           int64  `json:"id"`
	StartedAt    string `json:"started_at"`
	DurationMS   int64  `json:"duration_ms"`
	DevicesFound int    `json:"devices_found"`
	NewUnknown   int    `json:"new_unknown"`
	Source       string `json:"source"`
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json; charset=utf-8")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func fail(w http.ResponseWriter, status int, msg string) { writeJSON(w, status, map[string]string{"error": msg}) }

func body(r *http.Request) map[string]any {
	var b map[string]any
	_ = json.NewDecoder(http.MaxBytesReader(nil, r.Body, 1<<20)).Decode(&b)
	if b == nil {
		b = map[string]any{}
	}
	return b
}

func str(b map[string]any, k string) string {
	v, _ := b[k].(string)
	return v
}

// handler serves the JSON API at /index.php (same address the dashboard already uses) and the built dashboard.
func (a *App) handler(web fs.FS) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/index.php", a.api)
	index, _ := fs.ReadFile(web, "index.html")
	files := http.FileServerFS(web)
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		name := strings.TrimPrefix(path.Clean(r.URL.Path), "/")
		if _, err := fs.Stat(web, name); name == "" || name == "index.html" || err != nil {
			w.Header().Set("Content-Type", "text/html; charset=utf-8")
			_, _ = w.Write(index)
			return
		}
		files.ServeHTTP(w, r)
	})
	return mux
}

func (a *App) isAdmin(u *User) bool {
	var first int64
	_ = a.db.QueryRow(`SELECT MIN(id) FROM users`).Scan(&first)
	return u.ID == first
}

func (a *App) api(w http.ResponseWriter, r *http.Request) {
	action := r.URL.Query().Get("action")
	post := r.Method == http.MethodPost

	switch {
	case action == "register" && post:
		b := body(r)
		u, err := createUser(a.db, str(b, "email"), str(b, "password"))
		if err != nil {
			fail(w, 400, err.Error())
			return
		}
		writeJSON(w, 200, map[string]string{"token": makeToken(a.secret, u.ID, 12*time.Hour)})
		return
	case action == "login" && post:
		b := body(r)
		u := loginUser(a.db, str(b, "email"), str(b, "password"))
		if u == nil {
			time.Sleep(time.Second) // slows down password guessing
			fail(w, 401, "Incorrect email or password")
			return
		}
		writeJSON(w, 200, map[string]string{"token": makeToken(a.secret, u.ID, 12*time.Hour)})
		return
	}

	uid := tokenUser(a.secret, r.Header.Get("X-Auth-Token"))
	u, err := userByID(a.db, uid)
	if uid == 0 || err != nil {
		fail(w, 401, "Not signed in")
		return
	}

	switch action {
	case "status":
		var last *scanJSON
		var s scanJSON
		if a.db.QueryRow(`SELECT id, started_at, duration_ms, devices_found, new_unknown, source FROM scans WHERE user_id = ? ORDER BY id DESC LIMIT 1`, uid).
			Scan(&s.ID, &s.StartedAt, &s.DurationMS, &s.DevicesFound, &s.NewUnknown, &s.Source) == nil {
			last = &s
		}
		mode := "live"
		if a.demo {
			mode = "demo"
		}
		smtp := a.smtpConfig()
		writeJSON(w, 200, map[string]any{
			"email": u.Email, "last_scan": last, "subnet": orDefault(u.Subnet, detectSubnet()), "mode": mode, "scan_target_s": 120,
			"alert_email": u.AlertEmail, "agent_key": "", "agent_online": false,
			"can_configure_email": a.isAdmin(u), "smtp_user": smtp.User, "smtp_configured": smtp.configured(),
		})

	case "devices":
		rows, err := a.db.Query(`SELECT mac, ip, hostname, vendor, label, status, suspicious, suspicious_reason, online, first_seen, last_seen
			FROM devices WHERE user_id = ? ORDER BY online DESC, last_seen DESC`, uid)
		if err != nil {
			fail(w, 500, "Database error")
			return
		}
		defer rows.Close()
		out := []deviceJSON{}
		for rows.Next() {
			var d deviceJSON
			_ = rows.Scan(&d.MAC, &d.IP, &d.Hostname, &d.Vendor, &d.Label, &d.Status, &d.Suspicious, &d.SuspiciousReason, &d.Online, &d.FirstSeen, &d.LastSeen)
			out = append(out, d)
		}
		writeJSON(w, 200, out)

	case "scan":
		if !post {
			fail(w, 405, "POST required")
			return
		}
		sum, err := a.runScan(u, "manual")
		if err != nil {
			code := 500
			if err == errScanRunning {
				code = 409
			}
			fail(w, code, err.Error())
			return
		}
		writeJSON(w, 200, sum)

	case "alerts":
		rows, _ := a.db.Query(`SELECT id, mac, ip, hostname, type, message, email_sent, created_at, acknowledged_at FROM alerts
			WHERE user_id = ? AND acknowledged_at IS NULL ORDER BY id DESC LIMIT 100`, uid)
		defer rows.Close()
		out := []alertJSON{}
		for rows.Next() {
			var x alertJSON
			_ = rows.Scan(&x.ID, &x.MAC, &x.IP, &x.Hostname, &x.Type, &x.Message, &x.EmailSent, &x.CreatedAt, &x.AcknowledgedAt)
			out = append(out, x)
		}
		writeJSON(w, 200, out)

	case "alert_ack":
		b := body(r)
		if str(b, "id") == "all" {
			_, _ = a.db.Exec(`UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND acknowledged_at IS NULL`, now(), uid)
		} else if id, ok := b["id"].(float64); ok {
			_, _ = a.db.Exec(`UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND id = ?`, now(), uid, int64(id))
		}
		writeJSON(w, 200, map[string]bool{"ok": true})

	case "device_update":
		b := body(r)
		mac := strings.ToUpper(str(b, "mac"))
		if len(mac) != 17 {
			fail(w, 400, "Invalid MAC address")
			return
		}
		if st := str(b, "status"); st != "" {
			if st != "trusted" && st != "unknown" {
				fail(w, 400, "Invalid status")
				return
			}
			// Trusting a device resets any suspicion; its current identity becomes the baseline.
			_, _ = a.db.Exec(`UPDATE devices SET status = ?, suspicious = 0, suspicious_reason = NULL WHERE user_id = ? AND mac = ?`, st, uid, mac)
			if st == "trusted" {
				_, _ = a.db.Exec(`UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND mac = ? AND acknowledged_at IS NULL`, now(), uid, mac)
			}
		}
		if _, ok := b["label"]; ok {
			label := strings.TrimSpace(str(b, "label"))
			if len([]rune(label)) > 100 {
				label = string([]rune(label)[:100])
			}
			_, _ = a.db.Exec(`UPDATE devices SET label = ? WHERE user_id = ? AND mac = ?`, nullable(label), uid, mac)
		}
		if c, _ := b["clear_suspicious"].(bool); c {
			_, _ = a.db.Exec(`UPDATE devices SET suspicious = 0, suspicious_reason = NULL WHERE user_id = ? AND mac = ?`, uid, mac)
			_, _ = a.db.Exec(`UPDATE alerts SET acknowledged_at = ? WHERE user_id = ? AND mac = ? AND acknowledged_at IS NULL`, now(), uid, mac)
		}
		writeJSON(w, 200, map[string]bool{"ok": true})

	case "device_delete":
		mac := strings.ToUpper(str(body(r), "mac"))
		for _, t := range []string{"devices", "connection_history", "alerts"} {
			_, _ = a.db.Exec(`DELETE FROM `+t+` WHERE user_id = ? AND mac = ?`, uid, mac)
		}
		writeJSON(w, 200, map[string]bool{"ok": true})

	case "history":
		var rows *sql.Rows
		if mac := strings.ToUpper(r.URL.Query().Get("mac")); mac != "" {
			rows, _ = a.db.Query(`SELECT id, mac, ip, hostname, event, event_time FROM connection_history WHERE user_id = ? AND mac = ? ORDER BY id DESC LIMIT 100`, uid, mac)
		} else {
			rows, _ = a.db.Query(`SELECT id, mac, ip, hostname, event, event_time FROM connection_history WHERE user_id = ? ORDER BY id DESC LIMIT 100`, uid)
		}
		defer rows.Close()
		out := []historyJSON{}
		for rows.Next() {
			var h historyJSON
			_ = rows.Scan(&h.ID, &h.MAC, &h.IP, &h.Hostname, &h.Event, &h.EventTime)
			out = append(out, h)
		}
		writeJSON(w, 200, out)

	case "settings": // the user's own network range and alert email; the first account also sets the sender
		b := body(r)
		if _, ok := b["subnet"]; ok {
			subnet := ""
			if s := strings.TrimSpace(str(b, "subnet")); s != "" {
				var err error
				if subnet, err = normaliseSubnet(s); err != nil {
					fail(w, 400, "Enter the network range like 192.168.0.0/24 (prefix between /22 and /30)")
					return
				}
			}
			_, _ = a.db.Exec(`UPDATE users SET subnet = ? WHERE id = ?`, nullable(subnet), uid)
		}
		if _, ok := b["alert_email"]; ok {
			e := strings.TrimSpace(str(b, "alert_email"))
			if _, err := mail.ParseAddress(e); e != "" && err != nil {
				fail(w, 400, "Invalid email address")
				return
			}
			_, _ = a.db.Exec(`UPDATE users SET alert_email = ? WHERE id = ?`, nullable(e), uid)
		}
		if a.isAdmin(u) {
			if v, ok := b["smtp_user"]; ok {
				_ = setSetting(a.db, "smtp_user", strings.TrimSpace(v.(string)))
			}
			if p := str(b, "smtp_pass"); p != "" { // blank keeps the saved password
				_ = setSetting(a.db, "smtp_pass", strings.ReplaceAll(p, " ", ""))
			}
		}
		writeJSON(w, 200, map[string]bool{"ok": true})

	case "test_email":
		if !post {
			fail(w, 405, "POST required")
			return
		}
		if u.AlertEmail == "" {
			fail(w, 400, "Save an email address first")
			return
		}
		err := sendMail(a.smtpConfig(), u.AlertEmail, "Network Monitor: test email", "This is a test email from your Home Network Monitor.\nIf you can read this, alert emails are working.")
		resp := map[string]any{"sent": err == nil, "to": u.AlertEmail, "via": "smtp"}
		if err != nil {
			resp["error"] = err.Error()
		}
		writeJSON(w, 200, resp)

	default:
		fail(w, 404, "Unknown action")
	}
}

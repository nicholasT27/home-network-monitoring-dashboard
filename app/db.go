package main

import (
	"crypto/rand"
	"database/sql"
	"encoding/hex"
	"os"
	"path/filepath"
	"time"

	_ "modernc.org/sqlite"
)

const schema = `
CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT NOT NULL UNIQUE, password_hash TEXT NOT NULL,
  agent_key TEXT NOT NULL DEFAULT '', subnet TEXT, alert_email TEXT, created_at TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS devices (user_id INTEGER NOT NULL, mac TEXT NOT NULL, ip TEXT NOT NULL, hostname TEXT, vendor TEXT, label TEXT,
  status TEXT NOT NULL DEFAULT 'unknown', suspicious INTEGER NOT NULL DEFAULT 0, suspicious_reason TEXT,
  online INTEGER NOT NULL DEFAULT 0, first_seen TEXT NOT NULL, last_seen TEXT NOT NULL, PRIMARY KEY (user_id, mac));
CREATE TABLE IF NOT EXISTS connection_history (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mac TEXT NOT NULL, ip TEXT,
  hostname TEXT, event TEXT NOT NULL, event_time TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS alerts (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, mac TEXT NOT NULL, ip TEXT, hostname TEXT,
  type TEXT NOT NULL, message TEXT NOT NULL, email_sent INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL, acknowledged_at TEXT);
CREATE TABLE IF NOT EXISTS scans (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, started_at TEXT NOT NULL,
  duration_ms INTEGER NOT NULL, devices_found INTEGER NOT NULL, new_unknown INTEGER NOT NULL DEFAULT 0, source TEXT NOT NULL DEFAULT 'manual');
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL);
`

// openDB opens (creating if needed) the SQLite file and its tables.
func openDB(path string) (*sql.DB, error) {
	if path != ":memory:" {
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			return nil, err
		}
		path = "file:" + filepath.ToSlash(path) + "?_pragma=busy_timeout(5000)&_pragma=journal_mode(WAL)"
	}
	db, err := sql.Open("sqlite", path)
	if err != nil {
		return nil, err
	}
	db.SetMaxOpenConns(1) // SQLite: one writer; also keeps ":memory:" databases on a single connection
	if _, err := db.Exec(schema); err != nil {
		return nil, err
	}
	return db, nil
}

// All stored times are UTC "YYYY-MM-DD HH:MM:SS"; the dashboard converts them to local time.
func now() string { return time.Now().UTC().Format("2006-01-02 15:04:05") }

// nullable turns "" into SQL NULL.
func nullable(s string) any {
	if s == "" {
		return nil
	}
	return s
}

func getSetting(db *sql.DB, key string) string {
	var v string
	_ = db.QueryRow(`SELECT v FROM settings WHERE k = ?`, key).Scan(&v)
	return v
}

func setSetting(db *sql.DB, key, value string) error {
	_, err := db.Exec(`INSERT INTO settings (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v`, key, value)
	return err
}

func randomHex(n int) string {
	b := make([]byte, n)
	_, _ = rand.Read(b)
	return hex.EncodeToString(b)
}

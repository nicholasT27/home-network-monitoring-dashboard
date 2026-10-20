package main

import (
	"bufio"
	"net"
	"strings"
	"sync"
	"testing"
	"time"
)

func newTestApp(t *testing.T) *App {
	t.Helper()
	db, err := openDB(":memory:")
	if err != nil {
		t.Fatal(err)
	}
	return &App{db: db, secret: "s3cret"}
}

func count(t *testing.T, a *App, q string, args ...any) int {
	t.Helper()
	var n int
	if err := a.db.QueryRow(q, args...).Scan(&n); err != nil {
		t.Fatal(err)
	}
	return n
}

func str1(t *testing.T, a *App, q string, args ...any) string {
	t.Helper()
	var s string
	_ = a.db.QueryRow(q, args...).Scan(&s)
	return s
}

func scan(t *testing.T, a *App, uid int64, f ...Found) Summary {
	t.Helper()
	s, err := a.processScan(uid, f, "manual", time.Now())
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func dev(mac, ip, host string) Found { return Found{MAC: mac, IP: ip, Hostname: host, Alive: true} }

func TestParseARP(t *testing.T) {
	win := "  192.168.1.1           3c-5a-b4-11-22-33     dynamic\n  192.168.1.255         ff-ff-ff-ff-ff-ff     static\n  224.0.0.22            01-00-5e-00-00-16     static\n  10.0.0.9              aa-bb-cc-dd-ee-ff     dynamic\n"
	if got := parseARP(win, "192.168.1.0/24"); len(got) != 1 || got["192.168.1.1"] != "3C:5A:B4:11:22:33" {
		t.Errorf("windows: %v", got)
	}
	lin := "? (192.168.1.20) at 8c:77:12:44:55:66 [ether] on eth0\n? (192.168.1.21) at <incomplete> on eth0\n192.168.1.30 dev eth0 lladdr a4:83:e7:aa:bb:01 REACHABLE\n"
	if got := parseARP(lin, "192.168.1.0/24"); len(got) != 2 || got["192.168.1.30"] != "A4:83:E7:AA:BB:01" {
		t.Errorf("linux: %v", got)
	}
	if got := parseARP("? (192.168.1.7) at 0:1a:2b:3c:4d:5e on en0 ifscope [ethernet]", "192.168.1.0/24"); got["192.168.1.7"] != "00:1A:2B:3C:4D:5E" {
		t.Errorf("macos short octets: %v", got)
	}
}

func TestSubnets(t *testing.T) {
	if h, _ := cidrHosts("192.168.1.0/24"); len(h) != 254 {
		t.Errorf("hosts %d", len(h))
	}
	if s, err := normaliseSubnet(" 192.168.0.124/24 "); err != nil || s != "192.168.0.0/24" {
		t.Errorf("normalise %q %v", s, err)
	}
	if _, err := normaliseSubnet("192.168.0.0/8"); err == nil {
		t.Error("/8 must be rejected")
	}
	if !isPrivateMAC("DA:A1:19:00:00:01") || isPrivateMAC("3C:5A:B4:11:22:33") {
		t.Error("private MAC detection")
	}
	if d := detectSubnet(); !strings.HasSuffix(d, "/24") {
		t.Errorf("detectSubnet %q", d)
	}
}

func TestAccountsAndTokens(t *testing.T) {
	a := newTestApp(t)
	alice, err := createUser(a.db, "Alice@Example.com", "password-alice")
	if err != nil {
		t.Fatal(err)
	}
	if loginUser(a.db, "alice@example.com", "password-alice") == nil || loginUser(a.db, "alice@example.com", "nope-nope") != nil {
		t.Error("login")
	}
	for _, c := range [][2]string{{"alice@example.com", "another-pass"}, {"not-an-email", "password1"}, {"c@example.com", "short"}} {
		if _, err := createUser(a.db, c[0], c[1]); err == nil {
			t.Errorf("sign-up should be rejected: %v", c)
		}
	}
	tok := makeToken("s3cret", alice.ID, time.Minute)
	if tokenUser("s3cret", tok) != alice.ID || tokenUser("s3cret", tok+"x") != 0 || tokenUser("other", tok) != 0 {
		t.Error("token validation")
	}
	if tokenUser("s3cret", makeToken("s3cret", alice.ID, -time.Minute)) != 0 {
		t.Error("expired token accepted")
	}
}

func TestScanLogic(t *testing.T) {
	a := newTestApp(t)
	alice, _ := createUser(a.db, "alice@example.com", "password-alice")
	bob, _ := createUser(a.db, "bob@example.com", "password-bob")
	uid := alice.ID
	router, mac := dev("3C:5A:B4:11:22:33", "192.168.1.1", "router.lan"), dev("A4:83:E7:AA:BB:01", "192.168.1.12", "kee-macbook")

	s := scan(t, a, uid, router, mac)
	if s.NewUnknown != 2 || count(t, a, `SELECT COUNT(*) FROM alerts WHERE type='baseline'`) != 1 || count(t, a, `SELECT COUNT(*) FROM alerts`) != 1 {
		t.Fatalf("baseline: %+v", s)
	}
	a.db.Exec(`UPDATE devices SET status='trusted' WHERE user_id=?`, uid)

	mac.IP = "192.168.1.99"
	if s = scan(t, a, uid, router, mac); s.NewUnknown != 0 || count(t, a, `SELECT COUNT(*) FROM alerts`) != 1 {
		t.Error("rescan must not alert")
	}
	if count(t, a, `SELECT COUNT(*) FROM connection_history WHERE event='ip_changed'`) != 1 {
		t.Error("ip change not recorded")
	}

	scan(t, a, uid, router)
	if count(t, a, `SELECT online FROM devices WHERE mac='A4:83:E7:AA:BB:01'`) != 0 {
		t.Error("missing device should be offline")
	}
	scan(t, a, uid, router, mac)
	if count(t, a, `SELECT COUNT(*) FROM connection_history WHERE event='joined'`) != 1 {
		t.Error("rejoin not logged")
	}

	// MAC randomisation: trusted phone returns with a new private MAC and the same hostname
	a.db.Exec(`INSERT INTO devices (user_id,mac,ip,hostname,vendor,status,online,first_seen,last_seen) VALUES (?,?,?,?,?,?,0,'2026-01-01 00:00:00','2026-01-01 00:00:00')`,
		uid, "DA:A1:19:00:00:00", "192.168.1.40", "keesiang-iphone", "Private (randomised) MAC", "trusted")
	before := count(t, a, `SELECT COUNT(*) FROM alerts`)
	scan(t, a, uid, router, mac, dev("DA:A1:19:00:00:01", "192.168.1.41", "KeeSiang-iPhone"))
	if count(t, a, `SELECT COUNT(*) FROM alerts`) != before || count(t, a, `SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:01' AND status='trusted'`) != 1 ||
		count(t, a, `SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:00'`) != 0 || count(t, a, `SELECT COUNT(*) FROM connection_history WHERE event='mac_rotated'`) != 1 {
		t.Error("MAC rotation not handled quietly")
	}

	// a genuinely new device after the baseline is alerted
	phone := dev("DA:A1:19:00:00:01", "192.168.1.41", "keesiang-iphone")
	scan(t, a, uid, router, mac, phone, dev("5E:CF:7F:0B:AD:01", "192.168.1.77", ""))
	if count(t, a, `SELECT COUNT(*) FROM alerts WHERE type='unknown_device'`) != 1 {
		t.Error("new device not alerted")
	}
	// a clone of the hostname while the trusted original is online stays unknown
	scan(t, a, uid, router, mac, phone, dev("DA:A1:19:00:00:77", "192.168.1.60", "keesiang-iphone"))
	if count(t, a, `SELECT COUNT(*) FROM devices WHERE mac='DA:A1:19:00:00:77' AND status='unknown'`) != 1 {
		t.Error("hostname clone must stay unknown")
	}

	// spoof check: trusted MAC reports a different hostname
	router.Hostname = "evil-laptop"
	scan(t, a, uid, router, mac)
	if count(t, a, `SELECT suspicious FROM devices WHERE mac='3C:5A:B4:11:22:33'`) != 1 || count(t, a, `SELECT COUNT(*) FROM alerts WHERE type='suspicious_device'`) != 1 {
		t.Error("spoof not flagged")
	}
	scan(t, a, uid, router)
	if count(t, a, `SELECT COUNT(*) FROM alerts WHERE type='suspicious_device'`) != 1 {
		t.Error("suspicious alert repeated")
	}

	// stale ARP entry (no ping reply): goes offline; unknown ones are still detected but not shown online
	a.db.Exec(`UPDATE devices SET online=1 WHERE mac='A4:83:E7:AA:BB:01'`)
	scan(t, a, uid, Found{MAC: "A4:83:E7:AA:BB:01", IP: "192.168.1.99", Hostname: "kee-macbook", Alive: false})
	if count(t, a, `SELECT online FROM devices WHERE mac='A4:83:E7:AA:BB:01'`) != 0 {
		t.Error("stale ARP device should be offline")
	}
	n := count(t, a, `SELECT COUNT(*) FROM alerts`)
	scan(t, a, uid, Found{MAC: "6A:11:22:33:44:55", IP: "192.168.1.90", Alive: false})
	if count(t, a, `SELECT online FROM devices WHERE mac='6A:11:22:33:44:55'`) != 0 || count(t, a, `SELECT COUNT(*) FROM alerts`) != n+1 {
		t.Error("ARP-only unknown device must alert but not show online")
	}

	// households are isolated
	if count(t, a, `SELECT COUNT(*) FROM devices WHERE user_id=?`, bob.ID) != 0 || count(t, a, `SELECT COUNT(*) FROM alerts WHERE user_id=?`, bob.ID) != 0 {
		t.Error("Bob sees Alice's data")
	}
	scan(t, a, bob.ID, dev("3C:5A:B4:11:22:33", "192.168.0.1", "router.lan"))
	if str1(t, a, `SELECT status FROM devices WHERE user_id=? AND mac='3C:5A:B4:11:22:33'`, bob.ID) != "unknown" ||
		str1(t, a, `SELECT status FROM devices WHERE user_id=? AND mac='3C:5A:B4:11:22:33'`, uid) != "trusted" {
		t.Error("same MAC in two households must stay separate")
	}
}

func TestDemoRunAndLock(t *testing.T) {
	a := newTestApp(t)
	a.demo = true
	u, _ := createUser(a.db, "d@example.com", "password-demo")
	for i := 0; i < 3; i++ {
		if _, err := a.runScan(u, "manual"); err != nil {
			t.Fatal(err)
		}
	}
	if count(t, a, `SELECT COUNT(*) FROM alerts WHERE type='unknown_device'`) != 1 { // the intruder appears on scan 3
		t.Error("demo intruder alert")
	}
	lk, _ := a.locks.LoadOrStore(u.ID, new(sync.Mutex))
	lk.(*sync.Mutex).Lock()
	if _, err := a.runScan(u, "manual"); err != errScanRunning {
		t.Errorf("concurrent scan should be rejected, got %v", err)
	}
	lk.(*sync.Mutex).Unlock()
}

// fake SMTP server to check an alert email is really sent
func TestSendMail(t *testing.T) {
	ln, _ := net.Listen("tcp", "127.0.0.1:0")
	got := make(chan string, 1)
	go func() {
		c, _ := ln.Accept()
		defer c.Close()
		r, w, log := bufio.NewReader(c), func(s string) { c.Write([]byte(s + "\r\n")) }, ""
		w("220 fake")
		data := false
		for {
			line, err := r.ReadString('\n')
			if err != nil {
				break
			}
			log += line
			if data {
				if strings.TrimSpace(line) == "." {
					data = false
					w("250 queued")
				}
				continue
			}
			switch strings.ToUpper(line[:4]) {
			case "EHLO":
				w("250-fake\r\n250 AUTH PLAIN")
			case "AUTH":
				w("235 ok")
			case "MAIL", "RCPT":
				w("250 ok")
			case "DATA":
				w("354 go")
				data = true
			case "QUIT":
				w("221 bye")
				got <- log
				return
			}
		}
	}()
	host, port, _ := net.SplitHostPort(ln.Addr().String())
	err := sendMail(SMTPConfig{Host: host, Port: port, User: "me@example.com", Pass: "pw", Secure: "none"}, "teacher@example.com", "Network alert: test", "Line one\nIP: 1.2.3.4")
	if err != nil {
		t.Fatal(err)
	}
	log := <-got
	if !strings.Contains(log, "RCPT TO:<teacher@example.com>") || !strings.Contains(log, "Subject: Network alert: test") || !strings.Contains(log, "IP: 1.2.3.4") {
		t.Errorf("unexpected SMTP log:\n%s", log)
	}
	if sendMail(SMTPConfig{}, "x@y.com", "s", "b") == nil {
		t.Error("unconfigured SMTP must fail")
	}
}

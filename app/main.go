package main

import (
	"embed"
	"flag"
	"fmt"
	"io/fs"
	"log"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"time"
)

//go:embed all:web
var webFiles embed.FS

func openBrowser(url string) {
	var cmd *exec.Cmd
	switch runtime.GOOS {
	case "windows":
		cmd = exec.Command("rundll32", "url.dll,FileProtocolHandler", url)
	case "darwin":
		cmd = exec.Command("open", url)
	default:
		cmd = exec.Command("xdg-open", url)
	}
	hideWindow(cmd)
	_ = cmd.Start()
}

func main() {
	port := flag.Int("port", 8080, "port to listen on")
	lan := flag.Bool("lan", false, "let other devices on the home network open the dashboard")
	demo := flag.Bool("demo", os.Getenv("SCAN_MODE") == "demo", "use simulated devices instead of scanning the network")
	dataDir := flag.String("data", "", "folder for the database (default: your user settings folder)")
	noBrowser := flag.Bool("no-browser", false, "do not open the browser")
	flag.Parse()

	if *dataDir == "" {
		base, err := os.UserConfigDir()
		if err != nil {
			base = "."
		}
		*dataDir = filepath.Join(base, "HomeNetworkMonitor")
	}
	db, err := openDB(filepath.Join(*dataDir, "monitor.sqlite"))
	if err != nil {
		log.Fatalf("Could not open the database in %s: %v", *dataDir, err)
	}
	secret := getSetting(db, "auth_secret")
	if secret == "" {
		secret = randomHex(32)
		_ = setSetting(db, "auth_secret", secret)
	}
	app := &App{db: db, secret: secret, demo: *demo}

	web, _ := fs.Sub(webFiles, "web")
	host := "127.0.0.1"
	if *lan {
		host = "0.0.0.0"
	}
	var ln net.Listener
	for p := *port; p < *port+10; p++ {
		if ln, err = net.Listen("tcp", fmt.Sprintf("%s:%d", host, p)); err == nil {
			*port = p
			break
		}
	}
	if ln == nil {
		log.Fatalf("Could not start the server: %v", err)
	}
	url := fmt.Sprintf("http://127.0.0.1:%d", *port)

	fmt.Printf("\nHome Network Monitor is running at %s\n", url)
	if *demo {
		fmt.Println("DEMO MODE: showing simulated devices.")
	}
	fmt.Println("Create an account, then press \"Scan now\". Your network is detected automatically.")
	fmt.Println("A scan also runs every 2 minutes. Close this window to stop the program.")
	fmt.Printf("Data is stored in %s\n\n", *dataDir)

	// Scheduled scan for every account, so alerts arrive even when the dashboard is closed.
	go func() {
		for range time.Tick(2 * time.Minute) {
			rows, err := db.Query(`SELECT id FROM users`)
			if err != nil {
				continue
			}
			var ids []int64
			for rows.Next() {
				var id int64
				_ = rows.Scan(&id)
				ids = append(ids, id)
			}
			rows.Close()
			for _, id := range ids {
				if u, err := userByID(db, id); err == nil {
					if s, err := app.runScan(u, "scheduled"); err == nil {
						fmt.Printf("[%s] %s: %d devices, %d new unknown\n", s.StartedAt, u.Email, s.DevicesFound, s.NewUnknown)
					}
				}
			}
		}
	}()

	if !*noBrowser {
		go func() { time.Sleep(500 * time.Millisecond); openBrowser(url) }()
	}
	log.Fatal(http.Serve(ln, app.handler(web)))
}

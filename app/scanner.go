package main

import (
	"context"
	"errors"
	"fmt"
	"net"
	"os/exec"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"
)

// Found is one device seen by a scan. Alive is true only if it answered a ping: the ARP cache keeps
// entries for ~20 minutes after a device leaves, so a cache entry alone does not prove it is online.
type Found struct {
	MAC      string
	IP       string
	Hostname string
	Alive    bool
}

func cidrHosts(cidr string) ([]string, error) {
	ip, n, err := net.ParseCIDR(strings.TrimSpace(cidr))
	if err != nil || ip.To4() == nil {
		return nil, fmt.Errorf("invalid subnet %q", cidr)
	}
	ones, _ := n.Mask.Size()
	if ones < 22 || ones > 30 {
		return nil, errors.New("subnet prefix must be between /22 and /30")
	}
	base := ipToUint(n.IP.To4())
	size := uint32(1) << (32 - ones)
	hosts := make([]string, 0, size)
	for i := uint32(1); i < size-1; i++ {
		hosts = append(hosts, uintToIP(base+i))
	}
	return hosts, nil
}

// normaliseSubnet returns the subnet in network-address form ("192.168.0.124/24" -> "192.168.0.0/24").
func normaliseSubnet(cidr string) (string, error) {
	if _, err := cidrHosts(cidr); err != nil {
		return "", err
	}
	_, n, _ := net.ParseCIDR(strings.TrimSpace(cidr))
	return n.String(), nil
}

func ipToUint(ip net.IP) uint32 { return uint32(ip[0])<<24 | uint32(ip[1])<<16 | uint32(ip[2])<<8 | uint32(ip[3]) }
func uintToIP(v uint32) string  { return fmt.Sprintf("%d.%d.%d.%d", v>>24, v>>16&255, v>>8&255, v&255) }

// detectSubnet finds this computer's home network (first private IPv4 interface), as a /24.
func detectSubnet() string {
	ifaces, _ := net.Interfaces()
	for _, ifc := range ifaces {
		if ifc.Flags&net.FlagUp == 0 || ifc.Flags&net.FlagLoopback != 0 {
			continue
		}
		addrs, _ := ifc.Addrs()
		for _, a := range addrs {
			if ipn, ok := a.(*net.IPNet); ok && ipn.IP.To4() != nil && ipn.IP.IsPrivate() {
				_, n, _ := net.ParseCIDR(ipn.IP.String() + "/24")
				return n.String()
			}
		}
	}
	return "192.168.1.0/24"
}

func pingArgs(ip string) []string {
	switch runtime.GOOS {
	case "windows":
		return []string{"-n", "1", "-w", "500", ip}
	case "darwin":
		return []string{"-c", "1", "-W", "1000", ip} // macOS: milliseconds
	default:
		return []string{"-c", "1", "-W", "1", ip} // Linux: seconds
	}
}

var ttlRe = regexp.MustCompile(`(?i)ttl=`)

// pingSweep pings every address (64 at a time) and returns the set that replied.
func pingSweep(ips []string) map[string]bool {
	alive := map[string]bool{}
	var mu sync.Mutex
	var wg sync.WaitGroup
	sem := make(chan struct{}, 64)
	for _, ip := range ips {
		wg.Add(1)
		sem <- struct{}{}
		go func(ip string) {
			defer wg.Done()
			defer func() { <-sem }()
			ctx, cancel := context.WithTimeout(context.Background(), 4*time.Second)
			defer cancel()
			cmd := exec.CommandContext(ctx, "ping", pingArgs(ip)...)
			hideWindow(cmd)
			out, _ := cmd.CombinedOutput()
			if ttlRe.Match(out) { // a real reply carries a TTL; "host unreachable" does not
				mu.Lock()
				alive[ip] = true
				mu.Unlock()
			}
		}(ip)
	}
	wg.Wait()
	return alive
}

var arpRe = regexp.MustCompile(`(\d{1,3}(?:\.\d{1,3}){3}).*?\b((?:[0-9a-fA-F]{1,2}[:-]){5}[0-9a-fA-F]{1,2})\b`)

// parseARP reads `arp -a` (Windows/macOS/Linux) or `ip neigh` output into ip -> MAC, for IPs inside cidr.
func parseARP(text, cidr string) map[string]string {
	hosts, _ := cidrHosts(cidr)
	inRange := make(map[string]bool, len(hosts))
	for _, h := range hosts {
		inRange[h] = true
	}
	found := map[string]string{}
	for _, line := range strings.Split(text, "\n") {
		m := arpRe.FindStringSubmatch(line)
		if m == nil {
			continue
		}
		parts := strings.FieldsFunc(m[2], func(r rune) bool { return r == ':' || r == '-' })
		for i, p := range parts {
			parts[i] = strings.ToUpper(strings.Repeat("0", 2-len(p)) + p)
		}
		mac := strings.Join(parts, ":")
		if !inRange[m[1]] || mac == "FF:FF:FF:FF:FF:FF" || mac == "00:00:00:00:00:00" || strings.HasPrefix(mac, "01:00:5E") {
			continue
		}
		found[m[1]] = mac
	}
	return found
}

func readARP(cidr string) map[string]string {
	cmds := [][]string{{"arp", "-a"}}
	if runtime.GOOS == "linux" {
		cmds = [][]string{{"ip", "neigh", "show"}, {"arp", "-an"}}
	}
	all := map[string]string{}
	for _, c := range cmds {
		cmd := exec.Command(c[0], c[1:]...)
		hideWindow(cmd)
		if out, err := cmd.Output(); err == nil {
			for ip, mac := range parseARP(string(out), cidr) {
				all[ip] = mac
			}
		}
	}
	return all
}

func resolveHostname(ip string) string {
	ctx, cancel := context.WithTimeout(context.Background(), 1500*time.Millisecond)
	defer cancel()
	names, err := net.DefaultResolver.LookupAddr(ctx, ip)
	if err != nil || len(names) == 0 {
		return ""
	}
	name := strings.TrimSuffix(names[0], ".")
	if name == ip {
		return ""
	}
	return name
}

// scanNetwork pings the subnet first (which makes every active device answer and fills the ARP cache),
// then reads the cache and looks up hostnames.
func scanNetwork(cidr string) ([]Found, error) {
	hosts, err := cidrHosts(cidr)
	if err != nil {
		return nil, err
	}
	alive := pingSweep(hosts)
	arp := readARP(cidr)
	devices := make([]Found, 0, len(arp))
	for ip, mac := range arp {
		devices = append(devices, Found{MAC: mac, IP: ip, Alive: alive[ip]})
	}
	var wg sync.WaitGroup
	sem := make(chan struct{}, 16)
	for i := range devices {
		wg.Add(1)
		sem <- struct{}{}
		go func(d *Found) {
			defer wg.Done()
			defer func() { <-sem }()
			d.Hostname = resolveHostname(d.IP)
		}(&devices[i])
	}
	wg.Wait()
	return devices, nil
}

// demoScan simulates a household: a fixed set of devices, an intruder from the 3rd scan on, and a phone
// whose private MAC rotates every 4 scans.
func demoScan(round int) []Found {
	d := []Found{
		{"3C:5A:B4:11:22:33", "192.168.1.1", "router.lan", true},
		{"A4:83:E7:AA:BB:01", "192.168.1.12", "kee-macbook", true},
		{"8C:77:12:44:55:66", "192.168.1.20", "living-room-tv", true},
		{"24:0A:C4:9A:12:34", "192.168.1.31", "smart-plug-kitchen", true},
		{"44:65:0D:77:88:99", "192.168.1.33", "echo-dot", true},
		{"DA:A1:19:00:00:" + strings.ToUpper(fmt.Sprintf("%02x", (round/4)%256)), "192.168.1.40", "keesiang-iphone", true},
	}
	if round >= 3 {
		d = append(d, Found{"5E:CF:7F:0B:AD:01", "192.168.1.77", "", true})
	}
	return d
}

// --- manufacturer lookup (built-in table of common home-network vendors)

var ouiTable = map[string]string{
	"B8:27:EB": "Raspberry Pi", "DC:A6:32": "Raspberry Pi", "E4:5F:01": "Raspberry Pi",
	"3C:5A:B4": "Google", "F4:F5:D8": "Google", "00:1A:11": "Google", "18:B4:30": "Google Nest",
	"44:65:0D": "Amazon", "FC:65:DE": "Amazon", "68:54:FD": "Amazon",
	"24:0A:C4": "Espressif (IoT)", "30:AE:A4": "Espressif (IoT)", "84:0D:8E": "Espressif (IoT)",
	"00:17:88": "Philips Hue", "00:03:93": "Apple", "00:1C:B3": "Apple", "3C:07:54": "Apple",
	"00:1B:63": "Apple", "F0:18:98": "Apple", "A4:83:E7": "Apple",
	"00:16:6C": "Samsung", "00:12:FB": "Samsung", "00:1D:25": "Samsung", "8C:77:12": "Samsung",
	"98:DA:C4": "TP-Link", "50:C7:BF": "TP-Link", "F4:F2:6D": "TP-Link",
	"28:6C:07": "Xiaomi", "64:09:80": "Xiaomi",
	"00:23:14": "Intel", "3C:97:0E": "Intel", "00:1F:3B": "Intel",
	"00:1E:C9": "Dell", "00:21:5A": "HP", "00:09:5B": "Netgear", "00:14:6C": "Netgear",
	"00:1D:60": "ASUS", "04:D4:C4": "ASUS", "00:04:4B": "NVIDIA",
	"00:0D:3A": "Microsoft", "00:15:5D": "Microsoft (Hyper-V)", "00:50:56": "VMware", "08:00:27": "VirtualBox",
}

// isPrivateMAC: IEEE 802 bit 1 of the first octet marks a locally administered (e.g. randomised) address.
func isPrivateMAC(mac string) bool {
	v, err := strconv.ParseUint(mac[:2], 16, 8)
	return err == nil && v&2 != 0
}

func vendorFor(mac string) string {
	if isPrivateMAC(mac) {
		return "Private (randomised) MAC"
	}
	return ouiTable[mac[:8]]
}

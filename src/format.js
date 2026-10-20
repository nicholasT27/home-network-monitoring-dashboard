// The API returns server-local "YYYY-MM-DD HH:MM:SS" timestamps.
export const parseTime = (s) => (s ? new Date(s.replace(' ', 'T')) : null);

export function timeAgo(s) {
  const d = parseTime(s);
  if (!d) return 'never';
  const sec = Math.max(0, Math.round((Date.now() - d.getTime()) / 1000));
  if (sec < 45) return 'just now';
  if (sec < 3600) return `${Math.round(sec / 60)} min ago`;
  if (sec < 86400) return `${Math.round(sec / 3600)} h ago`;
  return `${Math.round(sec / 86400)} d ago`;
}

export const fullTime = (s) => (parseTime(s) ? parseTime(s).toLocaleString() : '');

export const deviceName = (d) => d.label || d.hostname || 'Unnamed device';

export const isPrivateMac = (mac) => (parseInt(mac.slice(0, 2), 16) & 2) !== 0;

export const EVENT_TEXT = {
  first_seen: 'First seen on the network',
  joined: 'Came online',
  left: 'Went offline',
  ip_changed: 'IP address changed (DHCP)',
  mac_rotated: 'MAC address rotated, same hostname',
};

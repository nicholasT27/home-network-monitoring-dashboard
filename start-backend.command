#!/bin/bash
# Starts ONLY the monitoring API on http://127.0.0.1:8081 (for use with a tunnel). MySQL must be running.
cd "$(dirname "$0")"
for p in php /Applications/XAMPP/xamppfiles/bin/php /opt/homebrew/bin/php /usr/local/bin/php; do
  if command -v "$p" >/dev/null 2>&1; then
    echo "Backend running on http://127.0.0.1:8081 - leave this window open."
    exec "$p" -S 127.0.0.1:8081 -t backend backend/router.php
  fi
done
echo "PHP was not found. Install XAMPP (https://www.apachefriends.org)."
read -p "Press Enter to close"

#!/bin/bash
# Double-click to start the Home Network Monitor (needs PHP, e.g. XAMPP or Homebrew).
cd "$(dirname "$0")"
for p in php /Applications/XAMPP/xamppfiles/bin/php /opt/homebrew/bin/php /usr/local/bin/php; do
  if command -v "$p" >/dev/null 2>&1; then exec "$p" start.php; fi
done
echo "PHP was not found. Install XAMPP (https://www.apachefriends.org) or run: brew install php"
read -p "Press Enter to close"

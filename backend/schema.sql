-- Home Network Device Monitoring and Alert System: MySQL schema
-- Import in phpMyAdmin or:  mysql -u root < schema.sql

CREATE DATABASE IF NOT EXISTS home_network_monitor CHARACTER SET utf8mb4;
USE home_network_monitor;

-- The MAC address is the primary key: it survives DHCP lease changes, the IP does not.
CREATE TABLE IF NOT EXISTS devices (
  mac               VARCHAR(17)  NOT NULL PRIMARY KEY,   -- AA:BB:CC:DD:EE:FF
  ip                VARCHAR(45)  NOT NULL,
  hostname          VARCHAR(255) NULL,
  vendor            VARCHAR(100) NULL,                   -- from the MAC's OUI prefix
  label             VARCHAR(100) NULL,                   -- user-chosen friendly name
  status            VARCHAR(10)  NOT NULL DEFAULT 'unknown',  -- 'trusted' | 'unknown'
  suspicious        TINYINT(1)   NOT NULL DEFAULT 0,
  suspicious_reason VARCHAR(255) NULL,
  online            TINYINT(1)   NOT NULL DEFAULT 0,
  first_seen        DATETIME     NOT NULL,
  last_seen         DATETIME     NOT NULL
) ENGINE=InnoDB;

-- Connection history: only state changes are stored (joined, left, IP change...).
CREATE TABLE IF NOT EXISTS connection_history (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  mac        VARCHAR(17)  NOT NULL,
  ip         VARCHAR(45)  NULL,
  hostname   VARCHAR(255) NULL,
  event      VARCHAR(20)  NOT NULL,   -- first_seen | joined | left | ip_changed | mac_rotated
  event_time DATETIME     NOT NULL,
  INDEX idx_hist_mac (mac),
  INDEX idx_hist_time (event_time)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  mac             VARCHAR(17)  NOT NULL,
  ip              VARCHAR(45)  NULL,
  hostname        VARCHAR(255) NULL,
  type            VARCHAR(20)  NOT NULL,   -- unknown_device | suspicious_device
  message         VARCHAR(500) NOT NULL,
  email_sent      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  acknowledged_at DATETIME     NULL,
  INDEX idx_alert_ack (acknowledged_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS scans (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  started_at    DATETIME     NOT NULL,
  duration_ms   INT          NOT NULL,
  devices_found INT          NOT NULL,
  new_unknown   INT          NOT NULL DEFAULT 0,
  source        VARCHAR(10)  NOT NULL DEFAULT 'manual'   -- manual | scheduled
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS settings (
  k VARCHAR(50)  NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

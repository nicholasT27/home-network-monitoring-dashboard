-- Home Network Device Monitoring and Alert System: MySQL tables for SHARED HOSTING (multi-user)
-- In phpMyAdmin, first SELECT the database your host gave you, then Import this file.
-- (Same as schema.sql but without CREATE DATABASE / USE, which shared hosts do not allow.)
-- WARNING: this drops and recreates the app's tables, so re-importing erases all data.


DROP TABLE IF EXISTS alerts, connection_history, devices, scans, settings, users;

-- One account per household. The agent_key identifies that household's home agent.
CREATE TABLE users (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  email            VARCHAR(190) NOT NULL UNIQUE,
  password_hash    VARCHAR(255) NOT NULL,
  agent_key        CHAR(32)     NOT NULL UNIQUE,
  subnet           VARCHAR(18)  NULL,            -- the user's network range, e.g. 192.168.0.0/24
  alert_email      VARCHAR(190) NULL,
  agent_seen       INT          NOT NULL DEFAULT 0,   -- unix time of the agent's last contact
  scan_requested   INT          NOT NULL DEFAULT 0,   -- unix time the dashboard asked for a scan
  created_at       DATETIME     NOT NULL
) ENGINE=InnoDB;

-- The MAC address identifies a device (the IP changes with DHCP), per user.
CREATE TABLE devices (
  user_id           INT          NOT NULL,
  mac               VARCHAR(17)  NOT NULL,   -- AA:BB:CC:DD:EE:FF
  ip                VARCHAR(45)  NOT NULL,
  hostname          VARCHAR(255) NULL,
  vendor            VARCHAR(100) NULL,
  label             VARCHAR(100) NULL,
  status            VARCHAR(10)  NOT NULL DEFAULT 'unknown',  -- 'trusted' | 'unknown'
  suspicious        TINYINT(1)   NOT NULL DEFAULT 0,
  suspicious_reason VARCHAR(255) NULL,
  online            TINYINT(1)   NOT NULL DEFAULT 0,
  first_seen        DATETIME     NOT NULL,
  last_seen         DATETIME     NOT NULL,
  PRIMARY KEY (user_id, mac)
) ENGINE=InnoDB;

CREATE TABLE connection_history (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT          NOT NULL,
  mac        VARCHAR(17)  NOT NULL,
  ip         VARCHAR(45)  NULL,
  hostname   VARCHAR(255) NULL,
  event      VARCHAR(20)  NOT NULL,   -- first_seen | joined | left | ip_changed | mac_rotated
  event_time DATETIME     NOT NULL,
  INDEX idx_hist_user_mac (user_id, mac),
  INDEX idx_hist_time (event_time)
) ENGINE=InnoDB;

CREATE TABLE alerts (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  user_id         INT          NOT NULL,
  mac             VARCHAR(17)  NOT NULL,
  ip              VARCHAR(45)  NULL,
  hostname        VARCHAR(255) NULL,
  type            VARCHAR(20)  NOT NULL,   -- unknown_device | suspicious_device | baseline
  message         VARCHAR(500) NOT NULL,
  email_sent      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at      DATETIME     NOT NULL,
  acknowledged_at DATETIME     NULL,
  INDEX idx_alert_user (user_id, acknowledged_at)
) ENGINE=InnoDB;

CREATE TABLE scans (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  user_id       INT          NOT NULL,
  started_at    DATETIME     NOT NULL,
  duration_ms   INT          NOT NULL,
  devices_found INT          NOT NULL,
  new_unknown   INT          NOT NULL DEFAULT 0,
  source        VARCHAR(10)  NOT NULL DEFAULT 'manual',   -- manual | scheduled | agent
  INDEX idx_scan_user (user_id)
) ENGINE=InnoDB;

-- Internal key/value store (scan locks).
CREATE TABLE settings (
  k VARCHAR(50)  NOT NULL PRIMARY KEY,
  v VARCHAR(255) NOT NULL
) ENGINE=InnoDB;

package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"database/sql"
	"encoding/base64"
	"encoding/json"
	"errors"
	"net/mail"
	"strings"
	"time"

	"golang.org/x/crypto/bcrypt"
)

type User struct {
	ID         int64
	Email      string
	Hash       string
	Subnet     string
	AlertEmail string
}

func normaliseEmail(e string) string { return strings.ToLower(strings.TrimSpace(e)) }

func createUser(db *sql.DB, email, password string) (*User, error) {
	email = normaliseEmail(email)
	if _, err := mail.ParseAddress(email); err != nil || len(email) > 190 || !strings.Contains(email, ".") {
		return nil, errors.New("Enter a valid email address")
	}
	if len(password) < 8 {
		return nil, errors.New("Password must be at least 8 characters")
	}
	if u, _ := userByEmail(db, email); u != nil {
		return nil, errors.New("An account with this email already exists")
	}
	hash, err := bcrypt.GenerateFromPassword([]byte(password), bcrypt.DefaultCost)
	if err != nil {
		return nil, err
	}
	res, err := db.Exec(`INSERT INTO users (email, password_hash, agent_key, created_at) VALUES (?,?,?,?)`, email, string(hash), randomHex(16), now())
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return userByID(db, id)
}

func scanUser(row *sql.Row) (*User, error) {
	var u User
	var subnet, alert sql.NullString
	if err := row.Scan(&u.ID, &u.Email, &u.Hash, &subnet, &alert); err != nil {
		return nil, err
	}
	u.Subnet, u.AlertEmail = subnet.String, alert.String
	return &u, nil
}

const userCols = `SELECT id, email, password_hash, subnet, alert_email FROM users `

func userByEmail(db *sql.DB, email string) (*User, error) {
	return scanUser(db.QueryRow(userCols+`WHERE email = ?`, normaliseEmail(email)))
}
func userByID(db *sql.DB, id int64) (*User, error) { return scanUser(db.QueryRow(userCols+`WHERE id = ?`, id)) }

func loginUser(db *sql.DB, email, password string) *User {
	u, err := userByEmail(db, email)
	if err != nil || bcrypt.CompareHashAndPassword([]byte(u.Hash), []byte(password)) != nil {
		return nil
	}
	return u
}

// --- signed login tokens (secret generated once and kept in the database)

func b64(b []byte) string { return base64.RawURLEncoding.EncodeToString(b) }

func makeToken(secret string, uid int64, ttl time.Duration) string {
	payload, _ := json.Marshal(map[string]int64{"uid": uid, "exp": time.Now().Add(ttl).Unix()})
	p := b64(payload)
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(p))
	return p + "." + b64(mac.Sum(nil))
}

// tokenUser returns the user id a token was issued to, or 0 if it is invalid or expired.
func tokenUser(secret, token string) int64 {
	parts := strings.Split(token, ".")
	if len(parts) != 2 {
		return 0
	}
	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write([]byte(parts[0]))
	if !hmac.Equal([]byte(b64(mac.Sum(nil))), []byte(parts[1])) {
		return 0
	}
	raw, err := base64.RawURLEncoding.DecodeString(parts[0])
	if err != nil {
		return 0
	}
	var p struct{ UID, Exp int64 }
	if json.Unmarshal(raw, &p) != nil || p.Exp < time.Now().Unix() {
		return 0
	}
	return p.UID
}

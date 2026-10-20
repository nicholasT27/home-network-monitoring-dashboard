package main

import (
	"crypto/tls"
	"errors"
	"fmt"
	"net"
	"net/mail"
	"net/smtp"
	"strings"
	"time"
)

type SMTPConfig struct {
	Host, Port, User, Pass, Secure string // Secure: "ssl" (port 465), "starttls" (587) or "none"
}

func (c SMTPConfig) configured() bool { return c.Host != "" && c.User != "" && c.Pass != "" }

// sendMail sends a plain-text email through the configured SMTP account (e.g. Gmail with an app password).
func sendMail(c SMTPConfig, to, subject, body string) error {
	if !c.configured() {
		return errors.New("email sending is not set up")
	}
	if _, err := mail.ParseAddress(to); err != nil {
		return errors.New("invalid recipient")
	}
	addr := net.JoinHostPort(c.Host, c.Port)
	dialer := &net.Dialer{Timeout: 10 * time.Second}
	var conn net.Conn
	var err error
	if c.Secure == "ssl" {
		conn, err = tls.DialWithDialer(dialer, "tcp", addr, &tls.Config{ServerName: c.Host})
	} else {
		conn, err = dialer.Dial("tcp", addr)
	}
	if err != nil {
		return err
	}
	_ = conn.SetDeadline(time.Now().Add(30 * time.Second))
	cl, err := smtp.NewClient(conn, c.Host)
	if err != nil {
		return err
	}
	defer cl.Close()
	if c.Secure == "starttls" {
		if err := cl.StartTLS(&tls.Config{ServerName: c.Host}); err != nil {
			return err
		}
	}
	if err := cl.Auth(smtp.PlainAuth("", c.User, c.Pass, c.Host)); err != nil {
		return err
	}
	if err := cl.Mail(c.User); err != nil {
		return err
	}
	if err := cl.Rcpt(to); err != nil {
		return err
	}
	w, err := cl.Data()
	if err != nil {
		return err
	}
	msg := fmt.Sprintf("From: %s\r\nTo: %s\r\nSubject: %s\r\nDate: %s\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n%s",
		c.User, to, subject, time.Now().Format(time.RFC1123Z), strings.ReplaceAll(body, "\n", "\r\n"))
	if _, err := w.Write([]byte(msg)); err != nil {
		return err
	}
	if err := w.Close(); err != nil {
		return err
	}
	return cl.Quit()
}

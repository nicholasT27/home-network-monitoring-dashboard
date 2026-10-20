//go:build windows

package main

import (
	"os/exec"
	"syscall"
)

// hideWindow stops a console window flashing for every ping/arp command on Windows.
func hideWindow(cmd *exec.Cmd) { cmd.SysProcAttr = &syscall.SysProcAttr{HideWindow: true} }

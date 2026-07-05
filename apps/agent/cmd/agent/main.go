package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"syscall"
	"time"

	"controlpanel/agent/internal/controlplane"
	"controlpanel/agent/internal/metrics"
	"controlpanel/agent/internal/runtime"
	"controlpanel/agent/internal/security"
)

func main() {
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	cfg, err := security.LoadConfig()
	if err != nil {
		log.Fatalf("config: %v", err)
	}

	client, err := controlplane.NewClient(cfg)
	if err != nil {
		log.Fatalf("control plane client: %v", err)
	}

	executor, err := runtime.NewDockerExecutor()
	if err != nil {
		log.Fatalf("runtime executor: %v", err)
	}

	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()

	log.Printf("controlpanel-agent started for node %s", cfg.NodeID)

	for {
		select {
		case <-ctx.Done():
			log.Println("controlpanel-agent stopped")
			return
		case <-ticker.C:
			snapshot, err := metrics.Collect(ctx)
			if err != nil {
				log.Printf("metrics: %v", err)
				continue
			}
			if err := client.SendHeartbeat(ctx, snapshot); err != nil {
				log.Printf("heartbeat: %v", err)
			}
			commands, err := client.FetchCommands(ctx)
			if err != nil {
				log.Printf("commands: %v", err)
				continue
			}
			for _, command := range commands {
				_ = client.ReportCommand(ctx, command.ID, runtime.Result{Status: "acknowledged", Message: "command received"})
				_ = client.ReportCommand(ctx, command.ID, runtime.Result{Status: "running", Message: "command running"})
				result := executor.Execute(ctx, command)
				if err := client.ReportCommand(ctx, command.ID, result); err != nil {
					log.Printf("report command %s: %v", command.ID, err)
				}
			}
		}
	}
}

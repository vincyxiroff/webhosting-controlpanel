package controlplane

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"time"

	"controlpanel/agent/internal/metrics"
	"controlpanel/agent/internal/runtime"
	"controlpanel/agent/internal/security"
)

type Client struct {
	cfg    security.Config
	client *http.Client
}

func NewClient(cfg security.Config) (*Client, error) {
	tlsConfig := &tls.Config{MinVersion: tls.VersionTLS13}
	cert, err := tls.LoadX509KeyPair(cfg.ClientCertPath, cfg.ClientKeyPath)
	if err == nil {
		tlsConfig.Certificates = []tls.Certificate{cert}
	}

	ca, err := os.ReadFile(cfg.CACertPath)
	if err == nil {
		pool := x509.NewCertPool()
		pool.AppendCertsFromPEM(ca)
		tlsConfig.RootCAs = pool
	}

	return &Client{
		cfg: cfg,
		client: &http.Client{
			Timeout: 30 * time.Second,
			Transport: &http.Transport{TLSClientConfig: tlsConfig},
		},
	}, nil
}

func (c *Client) SendHeartbeat(ctx context.Context, snapshot metrics.Snapshot) error {
	return c.post(ctx, "/agent/v1/heartbeat", map[string]any{
		"reported_at":     snapshot.CollectedAt,
		"metrics":         snapshot.Metrics,
		"containers":      snapshot.Containers,
		"active_sites":    snapshot.ActiveSites,
		"health":          snapshot.Health,
		"capabilities":    snapshot.Capabilities,
		"runtime_support": snapshot.RuntimeSupport,
	}, nil)
}

func (c *Client) FetchCommands(ctx context.Context) ([]runtime.Command, error) {
	var response struct {
		Commands []runtime.Command `json:"commands"`
	}
	if err := c.post(ctx, "/agent/v1/command/pull", map[string]any{"limit": 10}, &response); err != nil {
		return nil, err
	}
	return response.Commands, nil
}

func (c *Client) ReportCommand(ctx context.Context, commandID string, result runtime.Result) error {
	return c.post(ctx, "/agent/v1/command/"+commandID+"/result", result, nil)
}

func (c *Client) post(ctx context.Context, path string, payload any, target any) error {
	body, err := json.Marshal(payload)
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, c.cfg.ControlPlaneURL+path, bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("content-type", "application/json")
	req.Header.Set("x-node-id", c.cfg.NodeID)
	if c.cfg.Fingerprint != "" {
		req.Header.Set("x-client-cert-fingerprint", c.cfg.Fingerprint)
	}
	if c.cfg.AgentToken != "" {
		req.Header.Set("authorization", "Bearer "+c.cfg.AgentToken)
	}
	resp, err := c.client.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode >= 300 {
		return fmt.Errorf("post %s: %s", path, resp.Status)
	}
	if target != nil {
		return json.NewDecoder(resp.Body).Decode(target)
	}
	return nil
}

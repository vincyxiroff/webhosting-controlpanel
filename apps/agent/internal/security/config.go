package security

import (
	"flag"
	"os"

	"gopkg.in/yaml.v3"
)

type Config struct {
	NodeID          string `yaml:"node_id"`
	ControlPlaneURL string `yaml:"control_plane_url"`
	AgentToken      string `yaml:"agent_token"`
	Fingerprint     string `yaml:"fingerprint"`
	CACertPath      string `yaml:"ca_cert_path"`
	ClientCertPath  string `yaml:"client_cert_path"`
	ClientKeyPath   string `yaml:"client_key_path"`
	NginxConfigDir  string `yaml:"nginx_config_dir"`
	SiteDataDir     string `yaml:"site_data_dir"`
}

func LoadConfig() (Config, error) {
	path := flag.String("config", "/etc/controlpanel/agent.yaml", "agent config path")
	flag.Parse()

	data, err := os.ReadFile(*path)
	if err != nil {
		return Config{}, err
	}

	var cfg Config
	if err := yaml.Unmarshal(data, &cfg); err != nil {
		return Config{}, err
	}

	return cfg, nil
}

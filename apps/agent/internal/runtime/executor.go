package runtime

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"time"
)

type Command struct {
	ID             string         `json:"id"`
	Name           string         `json:"command"`
	Payload        map[string]any `json:"payload"`
	IdempotencyKey string         `json:"idempotency_key"`
	Attempt        int            `json:"attempt"`
}

type Result struct {
	Status  string         `json:"status"`
	Message string         `json:"message"`
	Meta    map[string]any `json:"meta,omitempty"`
}

type DockerExecutor struct {
	siteDataDir      string
	nginxConfigDir   string
	nginxVersionsDir string
	nginxTemplateDir string
}

func NewDockerExecutor() (*DockerExecutor, error) {
	return &DockerExecutor{
		siteDataDir:      getenv("CONTROLPANEL_SITE_DATA_DIR", "/var/lib/controlpanel/sites"),
		nginxConfigDir:   getenv("CONTROLPANEL_NGINX_CONFIG_DIR", "/etc/nginx/conf.d/controlpanel"),
		nginxVersionsDir: getenv("CONTROLPANEL_NGINX_VERSION_DIR", "/var/lib/controlpanel/nginx-revisions"),
		nginxTemplateDir: getenv("CONTROLPANEL_NGINX_TEMPLATE_DIR", "/etc/controlpanel/vhost-templates"),
	}, nil
}

func (e *DockerExecutor) Execute(ctx context.Context, command Command) Result {
	switch command.Name {
	case "runtime.provision":
		return e.runtimeProvision(ctx, command)
	case "runtime.destroy":
		return e.runtimeDestroy(ctx, command)
	case "site.create":
		return e.createSite(ctx, command)
	case "volume.attach":
		return e.attachVolume(ctx, command)
	case "nginx.configure":
		return e.configureNginx(ctx, command)
	case "service.start":
		return e.startService(ctx, command)
	case "health.check":
		return e.healthCheck(ctx, command)
	case "site.suspend":
		return e.stopContainer(ctx, command, "suspended")
	case "site.throttle":
		return e.throttleSite(ctx, command)
	case "site.isolate":
		return e.isolateSite(ctx, command)
	case "site.delete":
		return e.deleteSite(ctx, command)
	case "site.restore":
		return e.restoreSite(ctx, command)
	case "logs.tail":
		return e.tailLogs(ctx, command)
	default:
		return Result{Status: "failed", Message: fmt.Sprintf("unsupported command %s", command.Name)}
	}
}

func (e *DockerExecutor) runtimeProvision(ctx context.Context, command Command) Result {
	if err := exec.CommandContext(ctx, "docker", "info").Run(); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	runtimeName, _ := command.Payload["runtime"].(string)
	image := imageFor(command.Payload)
	if out, err := exec.CommandContext(ctx, "docker", "image", "inspect", image).CombinedOutput(); err != nil {
		if pullOut, pullErr := exec.CommandContext(ctx, "docker", "pull", image).CombinedOutput(); pullErr != nil {
			return Result{Status: "failed", Message: string(out) + string(pullOut)}
		}
	}
	return Result{Status: "success", Message: "runtime image available", Meta: map[string]any{
		"runtime_type":    runtimeName,
		"runtime_version": command.Payload["version"],
	}}
}

func (e *DockerExecutor) runtimeDestroy(ctx context.Context, command Command) Result {
	return Result{Status: "success", Message: "runtime destroy is represented by site container removal"}
}

func (e *DockerExecutor) createSite(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	name := containerName(siteID)
	if containerExists(ctx, name) {
		return Result{Status: "success", Message: "container already exists", Meta: e.runtimeMeta(ctx, siteID, command.Payload)}
	}

	image := imageFor(command.Payload)
	siteDir := filepath.Join(e.siteDataDir, siteID)
	envFile := filepath.Join(siteDir, "runtime.env")
	if err := os.MkdirAll(siteDir, 0750); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	if err := writeEnvFile(envFile, command.Payload["environment"]); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	networkName, networkID, err := ensureSiteNetwork(ctx, siteID)
	if err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	volumeName, volumeID, err := ensureSiteVolume(ctx, siteID)
	if err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	limits := resourceLimits(command.Payload)

	args := []string{
		"run", "-d",
		"--name", name,
		"--restart", "unless-stopped",
		"--label", "controlpanel.site_id=" + siteID,
		"--label", "controlpanel.runtime=" + runtimeName(command.Payload),
		"--label", "controlpanel.container_config_hash=" + stringValue(command.Payload, "desired_container_config_hash"),
		"--label", "controlpanel.nginx_config_hash=" + stringValue(command.Payload, "desired_nginx_config_hash"),
		"--network", networkName,
		"--env-file", envFile,
		"--cpus", limits["cpus"],
		"--memory", limits["memory"],
		"--pids-limit", limits["pids_limit"],
		"--health-cmd", healthCommand(command.Payload),
		"--health-interval", "30s",
		"--health-timeout", "5s",
		"--health-retries", "3",
		"-v", volumeName + ":/app",
		"-w", "/app",
	}
	args = append(args, runtimeEnvArgs(command.Payload)...)
	args = append(args, runtimePublishArgs(command.Payload)...)
	args = append(args, image)
	args = append(args, runtimeCommandArgs(command.Payload)...)
	out, err := exec.CommandContext(ctx, "docker", args...).CombinedOutput()
	if err != nil {
		return Result{Status: "failed", Message: string(out)}
	}
	containerID := strings.TrimSpace(string(out))
	return Result{Status: "success", Message: "site container created", Meta: map[string]any{
		"site_id":         siteID,
		"container_id":    containerID,
		"container_name":  name,
		"network_id":      networkID,
		"network_name":    networkName,
		"volume_id":       volumeID,
		"volume_name":     volumeName,
		"runtime_type":    runtimeName(command.Payload),
		"runtime_version": command.Payload["runtime_version"],
		"app_port":        appPort(command.Payload),
		"resource_limits": limits,
	}}
}

func (e *DockerExecutor) attachVolume(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	if err := os.MkdirAll(filepath.Join(e.siteDataDir, siteID), 0750); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	volumeName, volumeID, err := ensureSiteVolume(ctx, siteID)
	if err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	return Result{Status: "success", Message: "volume attached", Meta: map[string]any{
		"site_id":     siteID,
		"volume_id":   volumeID,
		"volume_name": volumeName,
	}}
}

func (e *DockerExecutor) configureNginx(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	domain, _ := command.Payload["domain"].(string)
	if siteID == "" || domain == "" {
		return Result{Status: "failed", Message: "site_id and domain are required"}
	}
	if err := os.MkdirAll(e.nginxConfigDir, 0750); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	if err := os.MkdirAll(e.nginxVersionsDir, 0750); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	port := appPort(command.Payload)
	runtimeKind := runtimeName(command.Payload)
	if hostPort := hostPort(command.Payload); hostPort > 0 {
		port = hostPort
	}
	templateName := vhostTemplateName(command.Payload, runtimeKind)
	version := configVersion(siteID, domain+":"+templateName)
	config, err := e.renderNginxConfig(siteID, domain, runtimeKind, templateName, port, command.Payload)
	if err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	path := filepath.Join(e.nginxConfigDir, siteID+".conf")
	versionPath := filepath.Join(e.nginxVersionsDir, siteID+"-"+version+".conf")
	backupPath := path + ".rollback"
	if existing, err := os.ReadFile(path); err == nil {
		if err := os.WriteFile(backupPath, existing, 0640); err != nil {
			return Result{Status: "failed", Message: err.Error()}
		}
	}
	if err := os.WriteFile(versionPath, []byte(config), 0640); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	if err := os.WriteFile(path, []byte(config), 0640); err != nil {
		return Result{Status: "failed", Message: err.Error()}
	}
	if _, err := exec.LookPath("nginx"); err == nil {
		if out, err := exec.CommandContext(ctx, "nginx", "-t").CombinedOutput(); err != nil {
			rollbackFile(path, backupPath)
			return Result{Status: "failed", Message: string(out)}
		}
		if out, err := exec.CommandContext(ctx, "nginx", "-s", "reload").CombinedOutput(); err != nil {
			rollbackFile(path, backupPath)
			return Result{Status: "failed", Message: string(out)}
		}
	}
	return Result{Status: "success", Message: "nginx configured", Meta: map[string]any{
		"site_id":              siteID,
		"nginx_config_path":    path,
		"nginx_config_version": version,
		"nginx_template":       templateName,
	}}
}

func (e *DockerExecutor) renderNginxConfig(siteID string, domain string, runtimeKind string, templateName string, port int, payload map[string]any) (string, error) {
	upstreamName := "cp_" + safeNginxName(siteID)
	upstreamServer := containerName(siteID)
	if hostPort(payload) > 0 {
		upstreamServer = "127.0.0.1"
	}
	templateBody, err := e.loadNginxTemplate(templateName)
	if err != nil {
		return "", err
	}
	documentRoot := stringValueDefault(payload, "document_root", "/app")
	if runtimeKind == "static" {
		documentRoot = stringValueDefault(payload, "document_root", filepath.Join(e.siteDataDir, siteID))
	}
	replacements := map[string]string{
		"{{ site_id }}":       siteID,
		"{{ server_names }}":  sanitizeServerNames(domain),
		"{{ domain }}":        domain,
		"{{ upstream_name }}": upstreamName,
		"{{ upstream_url }}":  "http://" + upstreamName,
		"{{ document_root }}": documentRoot,
	}
	for token, value := range replacements {
		templateBody = strings.ReplaceAll(templateBody, token, value)
	}

	if runtimeKind == "static" && templateName == "static" {
		return templateBody, nil
	}

	return fmt.Sprintf("upstream %s {\n    server %s:%d;\n}\n\n%s", upstreamName, upstreamServer, port, templateBody), nil
}

func (e *DockerExecutor) loadNginxTemplate(templateName string) (string, error) {
	if safe := safeTemplateName(templateName); safe != "" {
		path := filepath.Join(e.nginxTemplateDir, safe+".conf")
		if content, err := os.ReadFile(path); err == nil {
			return string(content), nil
		}
	}

	if fallback, ok := embeddedNginxTemplates()[templateName]; ok {
		return fallback, nil
	}
	if fallback, ok := embeddedNginxTemplates()["reverse-proxy"]; ok {
		return fallback, nil
	}

	return "", fmt.Errorf("nginx template %s not found", templateName)
}

func embeddedNginxTemplates() map[string]string {
	return map[string]string{
		"generic-php": `server {
    listen 80;
    listen [::]:80;
    server_name {{ server_names }};
    root {{ document_root }};
    index index.php index.html;
    access_log /var/log/nginx/controlpanel-{{ site_id }}-access.log;
    error_log /var/log/nginx/controlpanel-{{ site_id }}-error.log warn;

    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/controlpanel/acme/{{ site_id }};
        auth_basic off;
        allow all;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_intercept_errors on;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTPS $https if_not_empty;
        fastcgi_read_timeout 3600;
        fastcgi_send_timeout 3600;
        try_files $uri =404;
        fastcgi_pass {{ upstream_name }};
    }
}`,
		"laravel": `server {
    listen 80;
    listen [::]:80;
    server_name {{ server_names }};
    root {{ document_root }}/public;
    index index.php index.html;
    access_log /var/log/nginx/controlpanel-{{ site_id }}-access.log;
    error_log /var/log/nginx/controlpanel-{{ site_id }}-error.log warn;

    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/controlpanel/acme/{{ site_id }};
        auth_basic off;
        allow all;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        try_files $uri =404;
        fastcgi_pass {{ upstream_name }};
    }
}`,
		"wordpress": `server {
    listen 80;
    listen [::]:80;
    server_name {{ server_names }};
    root {{ document_root }};
    index index.php index.html;
    access_log /var/log/nginx/controlpanel-{{ site_id }}-access.log;
    error_log /var/log/nginx/controlpanel-{{ site_id }}-error.log warn;

    location = /xmlrpc.php {
        deny all;
    }

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        try_files $uri =404;
        fastcgi_pass {{ upstream_name }};
    }
}`,
		"nodejs": `server {
    listen 80;
    listen [::]:80;
    server_name {{ server_names }};
    access_log /var/log/nginx/controlpanel-{{ site_id }}-access.log;
    error_log /var/log/nginx/controlpanel-{{ site_id }}-error.log warn;

    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/controlpanel/acme/{{ site_id }};
        auth_basic off;
        allow all;
    }

    location / {
        proxy_pass http://{{ upstream_name }};
        proxy_http_version 1.1;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Server $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_cache_bypass $http_upgrade;
        proxy_connect_timeout 900;
        proxy_send_timeout 900;
        proxy_read_timeout 900;
    }
}`,
		"reverse-proxy": `server {
    listen 80;
    listen [::]:80;
    server_name {{ server_names }};
    access_log /var/log/nginx/controlpanel-{{ site_id }}-access.log;
    error_log /var/log/nginx/controlpanel-{{ site_id }}-error.log warn;

    location ^~ /.well-known/acme-challenge/ {
        root /var/lib/controlpanel/acme/{{ site_id }};
        auth_basic off;
        allow all;
    }

    location / {
        proxy_pass http://{{ upstream_name }};
        proxy_http_version 1.1;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Forwarded-Server $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_connect_timeout 900;
        proxy_send_timeout 900;
        proxy_read_timeout 900;
    }
}`,
		"python": `server {
    listen 80;
    listen [::]:80;
    server_name {{ server_names }};
    access_log /var/log/nginx/controlpanel-{{ site_id }}-access.log;
    error_log /var/log/nginx/controlpanel-{{ site_id }}-error.log warn;

    location / {
        proxy_pass http://{{ upstream_name }};
        proxy_http_version 1.1;
        proxy_set_header X-Forwarded-Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Host $http_host;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 900;
    }
}`,
		"static": `server {
    listen 80;
    listen [::]:80;
    server_name {{ server_names }};
    root {{ document_root }};
    index index.html;
    access_log /var/log/nginx/controlpanel-{{ site_id }}-access.log;
    error_log /var/log/nginx/controlpanel-{{ site_id }}-error.log warn;

    location / {
        try_files $uri $uri/ =404;
    }
}`,
	}
}

func stringValue(payload map[string]any, key string) string {
	if value, ok := payload[key].(string); ok {
		return value
	}
	return ""
}

func stringValueDefault(payload map[string]any, key string, fallback string) string {
	if value := stringValue(payload, key); value != "" {
		return value
	}
	return fallback
}

func vhostTemplateName(payload map[string]any, runtimeKind string) string {
	if value := safeTemplateName(stringValue(payload, "vhost_template")); value != "" {
		return value
	}
	if value := safeTemplateName(stringValue(payload, "app_template")); value != "" {
		return value
	}
	switch runtimeKind {
	case "php":
		return "generic-php"
	case "python":
		return "python"
	case "node":
		return "nodejs"
	case "static":
		return "static"
	case "go", "rust", "bun", "deno", "docker", "reverse_proxy":
		return "reverse-proxy"
	default:
		return "reverse-proxy"
	}
}

func safeTemplateName(name string) string {
	name = strings.ToLower(strings.TrimSpace(name))
	name = strings.ReplaceAll(name, "_", "-")
	if name == "" {
		return ""
	}
	for _, char := range name {
		if (char < 'a' || char > 'z') && (char < '0' || char > '9') && char != '-' {
			return ""
		}
	}
	return name
}

func sanitizeServerNames(value string) string {
	parts := strings.Fields(strings.ReplaceAll(value, ",", " "))
	if len(parts) == 0 {
		return "_"
	}
	safe := make([]string, 0, len(parts))
	for _, part := range parts {
		part = strings.ToLower(strings.TrimSpace(part))
		part = strings.Trim(part, ".")
		if part == "" {
			continue
		}
		allowed := true
		for _, char := range part {
			if (char < 'a' || char > 'z') && (char < '0' || char > '9') && char != '-' && char != '.' && char != '*' {
				allowed = false
				break
			}
		}
		if allowed {
			safe = append(safe, part)
		}
	}
	if len(safe) == 0 {
		return "_"
	}
	return strings.Join(safe, " ")
}

func (e *DockerExecutor) writeBlockedNginx(ctx context.Context, siteID string, domain string, reason string) error {
	if err := os.MkdirAll(e.nginxConfigDir, 0750); err != nil {
		return err
	}
	path := filepath.Join(e.nginxConfigDir, siteID+".conf")
	backupPath := path + ".rollback"
	if existing, err := os.ReadFile(path); err == nil {
		_ = os.WriteFile(backupPath, existing, 0640)
	}
	config := fmt.Sprintf(`server {
    listen 80;
    server_name %s;
    return 451 "Site %s by billing policy";
}
`, domain, reason)
	if err := os.WriteFile(path, []byte(config), 0640); err != nil {
		return err
	}
	if _, err := exec.LookPath("nginx"); err == nil {
		if out, err := exec.CommandContext(ctx, "nginx", "-t").CombinedOutput(); err != nil {
			rollbackFile(path, backupPath)
			return errors.New(string(out))
		}
		if out, err := exec.CommandContext(ctx, "nginx", "-s", "reload").CombinedOutput(); err != nil {
			rollbackFile(path, backupPath)
			return errors.New(string(out))
		}
	}
	return nil
}

func (e *DockerExecutor) startService(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	out, err := exec.CommandContext(ctx, "docker", "start", containerName(siteID)).CombinedOutput()
	if err != nil {
		return Result{Status: "failed", Message: string(out)}
	}
	return Result{Status: "success", Message: "service started", Meta: e.runtimeMeta(ctx, siteID, command.Payload)}
}

func (e *DockerExecutor) healthCheck(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if !containerRunning(ctx, containerName(siteID)) {
		return Result{Status: "failed", Message: "container is not running"}
	}
	health := inspectHealth(ctx, containerName(siteID))
	return Result{Status: "success", Message: "health check passed", Meta: map[string]any{"site_id": siteID, "health": health}}
}

func (e *DockerExecutor) stopContainer(ctx context.Context, command Command, status string) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	_ = exec.CommandContext(ctx, "docker", "stop", containerName(siteID)).Run()
	if domain, ok := command.Payload["domain"].(string); ok && domain != "" {
		_ = e.writeBlockedNginx(ctx, siteID, domain, status)
	}
	return Result{Status: "success", Message: status, Meta: map[string]any{"site_id": siteID, "health": map[string]any{"status": status}}}
}

func (e *DockerExecutor) throttleSite(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	out, err := exec.CommandContext(ctx, "docker", "update", "--cpus", "0.100", "--memory", "128m", containerName(siteID)).CombinedOutput()
	if err != nil {
		return Result{Status: "failed", Message: string(out)}
	}
	return Result{Status: "success", Message: "site throttled", Meta: map[string]any{"site_id": siteID, "resource_limits": map[string]any{"cpus": "0.100", "memory": "128m"}}}
}

func (e *DockerExecutor) isolateSite(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	_ = exec.CommandContext(ctx, "docker", "network", "disconnect", "-f", networkName(siteID), containerName(siteID)).Run()
	return Result{Status: "success", Message: "site isolated", Meta: map[string]any{"site_id": siteID, "health": map[string]any{"status": "isolated"}}}
}

func (e *DockerExecutor) deleteSite(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	_ = exec.CommandContext(ctx, "docker", "rm", "-f", containerName(siteID)).Run()
	_ = os.Remove(filepath.Join(e.nginxConfigDir, siteID+".conf"))
	_ = exec.CommandContext(ctx, "docker", "volume", "rm", volumeName(siteID)).Run()
	_ = exec.CommandContext(ctx, "docker", "network", "rm", networkName(siteID)).Run()
	return Result{Status: "success", Message: "site deleted", Meta: map[string]any{"site_id": siteID}}
}

func (e *DockerExecutor) restoreSite(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	if containerExists(ctx, containerName(siteID)) {
		return e.startService(ctx, command)
	}
	return e.createSite(ctx, command)
}

func (e *DockerExecutor) tailLogs(ctx context.Context, command Command) Result {
	siteID, _ := command.Payload["site_id"].(string)
	if siteID == "" {
		return Result{Status: "failed", Message: "site_id is required"}
	}
	out, err := exec.CommandContext(ctx, "docker", "logs", "--tail", "200", containerName(siteID)).CombinedOutput()
	if err != nil {
		return Result{Status: "failed", Message: string(out)}
	}
	return Result{Status: "success", Message: "logs collected", Meta: map[string]any{"site_id": siteID, "logs": string(out)}}
}

func imageFor(payload map[string]any) string {
	runtimeName := runtimeName(payload)
	version, _ := payload["version"].(string)
	if version == "" {
		version, _ = payload["runtime_version"].(string)
	}
	if version == "" {
		version = "latest"
	}
	switch runtimeName {
	case "php":
		return "php:" + version + "-fpm-alpine"
	case "node":
		return "node:" + version + "-alpine"
	case "python":
		return "python:" + version + "-alpine"
	case "static":
		return "nginx:alpine"
	case "docker":
		if image, ok := payload["image"].(string); ok && image != "" {
			return image
		}
		return "nginx:alpine"
	case "go":
		return "golang:" + version + "-alpine"
	default:
		return "nginx:alpine"
	}
}

func runtimeName(payload map[string]any) string {
	if value, ok := payload["runtime"].(string); ok && value != "" {
		return value
	}
	if value, ok := payload["runtime_type"].(string); ok && value != "" {
		return value
	}
	return "static"
}

func containerName(siteID string) string {
	return "cp-site-" + siteID
}

func networkName(siteID string) string {
	return "cp-net-" + siteID
}

func volumeName(siteID string) string {
	return "cp-vol-" + siteID
}

func ensureSiteNetwork(ctx context.Context, siteID string) (string, string, error) {
	name := networkName(siteID)
	if id, err := dockerInspectID(ctx, "network", name); err == nil {
		return name, id, nil
	}
	out, err := exec.CommandContext(ctx, "docker", "network", "create", "--driver", "bridge", "--label", "controlpanel.site_id="+siteID, name).CombinedOutput()
	if err != nil {
		return "", "", errors.New(string(out))
	}
	return name, strings.TrimSpace(string(out)), nil
}

func ensureSiteVolume(ctx context.Context, siteID string) (string, string, error) {
	name := volumeName(siteID)
	if id, err := dockerInspectID(ctx, "volume", name); err == nil {
		return name, id, nil
	}
	out, err := exec.CommandContext(ctx, "docker", "volume", "create", "--label", "controlpanel.site_id="+siteID, name).CombinedOutput()
	if err != nil {
		return "", "", errors.New(string(out))
	}
	id, _ := dockerInspectID(ctx, "volume", name)
	if id == "" {
		id = strings.TrimSpace(string(out))
	}
	return name, id, nil
}

func dockerInspectID(ctx context.Context, objectType string, name string) (string, error) {
	out, err := exec.CommandContext(ctx, "docker", objectType, "inspect", "-f", "{{.Id}}", name).Output()
	return strings.TrimSpace(string(out)), err
}

func containerExists(ctx context.Context, name string) bool {
	return exec.CommandContext(ctx, "docker", "inspect", name).Run() == nil
}

func containerRunning(ctx context.Context, name string) bool {
	out, err := exec.CommandContext(ctx, "docker", "inspect", "-f", "{{.State.Running}}", name).Output()
	return err == nil && strings.TrimSpace(string(out)) == "true"
}

func getenv(key string, fallback string) string {
	value := os.Getenv(key)
	if value == "" {
		return fallback
	}
	return value
}

func writeEnvFile(path string, raw any) error {
	lines := []string{}
	if env, ok := raw.(map[string]any); ok {
		for key, value := range env {
			cleanKey := strings.Map(func(r rune) rune {
				if (r >= 'A' && r <= 'Z') || (r >= '0' && r <= '9') || r == '_' {
					return r
				}
				if r >= 'a' && r <= 'z' {
					return r - 32
				}
				return -1
			}, key)
			if cleanKey != "" {
				lines = append(lines, cleanKey+"="+strings.ReplaceAll(fmt.Sprint(value), "\n", ""))
			}
		}
	}
	return os.WriteFile(path, []byte(strings.Join(lines, "\n")+"\n"), 0600)
}

func resourceLimits(payload map[string]any) map[string]string {
	quotas, _ := payload["quotas"].(map[string]any)
	cpuMillicores := numberFrom(quotas, "cpu_millicores", 500)
	memoryMb := numberFrom(quotas, "memory_mb", 512)
	return map[string]string{
		"cpus":       fmt.Sprintf("%.3f", float64(cpuMillicores)/1000.0),
		"memory":     fmt.Sprintf("%dm", memoryMb),
		"pids_limit": "256",
	}
}

func appPort(payload map[string]any) int {
	if raw, ok := payload["app_port"]; ok {
		switch value := raw.(type) {
		case float64:
			return int(value)
		case int:
			return value
		case string:
			if parsed, err := strconv.Atoi(value); err == nil {
				return parsed
			}
		}
	}
	if raw, ok := payload["upstream_port"]; ok {
		switch value := raw.(type) {
		case float64:
			return int(value)
		case int:
			return value
		case string:
			if parsed, err := strconv.Atoi(value); err == nil {
				return parsed
			}
		}
	}
	switch runtimeName(payload) {
	case "node":
		return 3000
	case "python":
		return 8000
	case "php":
		return 9000
	case "static":
		return 80
	default:
		return 8080
	}
}

func hostPort(payload map[string]any) int {
	raw, ok := payload["host_port"]
	if !ok {
		return 0
	}
	switch value := raw.(type) {
	case float64:
		return int(value)
	case int:
		return value
	case string:
		if parsed, err := strconv.Atoi(value); err == nil {
			return parsed
		}
	}
	return 0
}

func runtimeEnvArgs(payload map[string]any) []string {
	port := strconv.Itoa(appPort(payload))
	args := []string{"-e", "PORT=" + port, "-e", "HOST=0.0.0.0"}
	if runtimeName(payload) == "node" {
		args = append(args, "-e", "NODE_ENV="+stringValueDefault(payload, "node_env", "production"))
	}
	return args
}

func runtimePublishArgs(payload map[string]any) []string {
	port := hostPort(payload)
	if port == 0 {
		return []string{}
	}

	return []string{"-p", fmt.Sprintf("127.0.0.1:%d:%d/tcp", port, appPort(payload))}
}

func runtimeCommandArgs(payload map[string]any) []string {
	switch runtimeName(payload) {
	case "node":
		return []string{"sh", "-lc", nodeBootstrapCommand(payload)}
	default:
		return []string{}
	}
}

func nodeBootstrapCommand(payload map[string]any) string {
	installCommand := stringValue(payload, "install_command")
	if installCommand == "" {
		installCommand = `if [ -f package-lock.json ]; then npm ci --omit=dev || npm install --omit=dev; elif [ -f package.json ]; then npm install --omit=dev; fi`
	}
	buildCommand := stringValue(payload, "build_command")
	if buildCommand == "" {
		buildCommand = `if [ -f package.json ] && npm run | grep -qE '(^|[[:space:]])build($|[[:space:]])'; then npm run build; fi`
	}
	startCommand := stringValue(payload, "start_command")
	if startCommand == "" {
		startCommand = `if [ -f package.json ] && npm run | grep -qE '(^|[[:space:]])start($|[[:space:]])'; then npm run start; elif [ -f server.js ]; then node server.js; elif [ -f index.js ]; then node index.js; else echo "No Node.js start command found"; exit 78; fi`
	}

	return strings.Join([]string{
		"set -e",
		"cd /app",
		installCommand,
		buildCommand,
		startCommand,
	}, " && ")
}

func numberFrom(values map[string]any, key string, fallback int) int {
	if values == nil {
		return fallback
	}
	switch value := values[key].(type) {
	case float64:
		return int(value)
	case int:
		return value
	case string:
		if parsed, err := strconv.Atoi(value); err == nil {
			return parsed
		}
	}
	return fallback
}

func healthCommand(payload map[string]any) string {
	switch runtimeName(payload) {
	case "static":
		return "wget -qO- http://127.0.0.1/ >/dev/null || exit 1"
	case "node":
		return fmt.Sprintf("node -e \"require('http').get('http://127.0.0.1:%d/', r => process.exit(r.statusCode < 500 ? 0 : 1)).on('error', () => process.exit(1))\"", appPort(payload))
	case "python":
		return fmt.Sprintf("python -c \"import urllib.request; urllib.request.urlopen('http://127.0.0.1:%d/', timeout=5)\"", appPort(payload))
	default:
		return "test -d /app || exit 1"
	}
}

func configVersion(siteID string, domain string) string {
	sum := sha256.Sum256([]byte(siteID + ":" + domain + ":" + time.Now().UTC().Format(time.RFC3339Nano)))
	return hex.EncodeToString(sum[:])[:16]
}

func safeNginxName(siteID string) string {
	return strings.ReplaceAll(siteID, "-", "_")
}

func rollbackFile(path string, backupPath string) {
	if existing, err := os.ReadFile(backupPath); err == nil {
		_ = os.WriteFile(path, existing, 0640)
		return
	}
	_ = os.Remove(path)
}

func inspectHealth(ctx context.Context, name string) map[string]any {
	out, err := exec.CommandContext(ctx, "docker", "inspect", "-f", "{{.State.Status}}|{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}", name).Output()
	if err != nil {
		return map[string]any{"status": "unknown", "error": err.Error()}
	}
	parts := strings.Split(strings.TrimSpace(string(out)), "|")
	status := "unknown"
	health := "none"
	if len(parts) > 0 {
		status = parts[0]
	}
	if len(parts) > 1 {
		health = parts[1]
	}
	return map[string]any{"status": status, "health": health}
}

func (e *DockerExecutor) runtimeMeta(ctx context.Context, siteID string, payload map[string]any) map[string]any {
	containerID, _ := dockerInspectID(ctx, "container", containerName(siteID))
	networkID, _ := dockerInspectID(ctx, "network", networkName(siteID))
	volumeID, _ := dockerInspectID(ctx, "volume", volumeName(siteID))
	return map[string]any{
		"site_id":         siteID,
		"container_id":    containerID,
		"container_name":  containerName(siteID),
		"network_id":      networkID,
		"network_name":    networkName(siteID),
		"volume_id":       volumeID,
		"volume_name":     volumeName(siteID),
		"runtime_type":    runtimeName(payload),
		"runtime_version": payload["runtime_version"],
		"resource_limits": resourceLimits(payload),
		"health":          inspectHealth(ctx, containerName(siteID)),
	}
}

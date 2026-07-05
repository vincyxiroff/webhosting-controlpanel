package metrics

import (
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"runtime"
	"strconv"
	"strings"
	"time"

	"github.com/shirou/gopsutil/v4/cpu"
	"github.com/shirou/gopsutil/v4/disk"
	"github.com/shirou/gopsutil/v4/mem"
	"github.com/shirou/gopsutil/v4/net"
)

type Snapshot struct {
	CollectedAt    time.Time        `json:"collected_at"`
	Metrics        map[string]any    `json:"metrics"`
	Containers     []map[string]any  `json:"containers"`
	ActiveSites    []map[string]any  `json:"active_sites"`
	Health         map[string]any    `json:"health"`
	Capabilities   map[string]any    `json:"capabilities"`
	RuntimeSupport map[string]bool   `json:"runtime_support"`
}

func Collect(ctx context.Context) (Snapshot, error) {
	cpuPercent, err := cpu.PercentWithContext(ctx, time.Second, false)
	if err != nil {
		return Snapshot{}, err
	}
	memory, err := mem.VirtualMemoryWithContext(ctx)
	if err != nil {
		return Snapshot{}, err
	}
	rootDisk, err := disk.UsageWithContext(ctx, "/")
	if err != nil {
		return Snapshot{}, err
	}
	io, err := net.IOCountersWithContext(ctx, false)
	if err != nil {
		return Snapshot{}, err
	}

	var sent, recv uint64
	if len(io) > 0 {
		sent = io[0].BytesSent
		recv = io[0].BytesRecv
	}

	containers, activeSites := dockerState(ctx)

	return Snapshot{
		CollectedAt: time.Now().UTC(),
		Metrics: map[string]any{
			"cpu_percent":    cpuPercent[0],
			"memory_percent": memory.UsedPercent,
			"disk_percent":   rootDisk.UsedPercent,
			"bytes_sent":     sent,
			"bytes_recv":     recv,
			"load_average":   loadAverage(ctx),
		},
		Containers:  containers,
		ActiveSites: activeSites,
		Health: map[string]any{
			"status": "healthy",
		},
		Capabilities: map[string]any{
			"os":             runtime.GOOS,
			"arch":           runtime.GOARCH,
			"cpu_millicores": runtime.NumCPU() * 1000,
			"memory_mb":      int(memory.Total / 1024 / 1024),
			"disk_mb":        int(rootDisk.Total / 1024 / 1024),
		},
		RuntimeSupport: map[string]bool{
			"docker": commandExists("docker"),
			"php":    commandExists("php"),
			"node":   commandExists("node"),
			"python": commandExists("python3") || commandExists("python"),
			"go":     commandExists("go"),
		},
	}, nil
}

func commandExists(name string) bool {
	_, err := exec.LookPath(name)
	return err == nil
}

func loadAverage(ctx context.Context) []float64 {
	out, err := exec.CommandContext(ctx, "sh", "-c", "cat /proc/loadavg 2>/dev/null | awk '{print $1,$2,$3}'").Output()
	if err != nil {
		return []float64{0, 0, 0}
	}
	fields := strings.Fields(string(out))
	values := []float64{0, 0, 0}
	for i := 0; i < len(fields) && i < 3; i++ {
		if parsed, err := strconv.ParseFloat(fields[i], 64); err == nil {
			values[i] = parsed
		}
	}
	return values
}

func dockerState(ctx context.Context) ([]map[string]any, []map[string]any) {
	out, err := exec.CommandContext(ctx, "docker", "ps", "--format", "{{json .}}").Output()
	if err != nil {
		return []map[string]any{}, []map[string]any{}
	}
	stats := dockerStats(ctx)
	containers := []map[string]any{}
	activeSites := []map[string]any{}
	for _, line := range strings.Split(strings.TrimSpace(string(out)), "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		var row map[string]any
		if json.Unmarshal([]byte(line), &row) != nil {
			continue
		}
		name, _ := row["Names"].(string)
		if metric, ok := stats[name]; ok {
			row["Stats"] = metric
		}
		containers = append(containers, row)
		labels := row["Labels"]
		if labelString, ok := labels.(string); ok && strings.Contains(labelString, "controlpanel.site_id=") {
			siteID := extractLabel(labelString, "controlpanel.site_id")
			activeSites = append(activeSites, map[string]any{
				"site_id":          siteID,
				"container_status": "running",
				"service_status":   "running",
				"nginx_status":     "synced",
				"runtime":          map[string]any{
					"container": row["Names"],
					"stats": stats[name],
					"runtime_type": extractLabel(labelString, "controlpanel.runtime"),
					"container_config_hash": extractLabel(labelString, "controlpanel.container_config_hash"),
					"nginx_config_hash": extractLabel(labelString, "controlpanel.nginx_config_hash"),
					"volume_name": "cp-vol-" + siteID,
					"disk_usage_bytes": volumeDiskUsage(ctx, siteID),
					"request_count": requestCount(ctx, siteID),
				},
			})
		}
	}
	return containers, activeSites
}

func requestCount(ctx context.Context, siteID string) int {
	path := "/var/log/nginx/controlpanel-" + siteID + "-access.log"
	if _, err := os.Stat(path); err != nil {
		return 0
	}
	out, err := exec.CommandContext(ctx, "tail", "-n", "1000", path).Output()
	if err != nil {
		return 0
	}
	return strings.Count(string(out), "\n")
}

func volumeDiskUsage(ctx context.Context, siteID string) int64 {
	path := "/var/lib/docker/volumes/cp-vol-" + siteID + "/_data"
	if info, err := os.Stat(path); err != nil || !info.IsDir() {
		return 0
	}
	out, err := exec.CommandContext(ctx, "du", "-sb", path).Output()
	if err != nil {
		return 0
	}
	fields := strings.Fields(string(out))
	if len(fields) == 0 {
		return 0
	}
	value, err := strconv.ParseInt(fields[0], 10, 64)
	if err != nil {
		return 0
	}
	return value
}

func dockerStats(ctx context.Context) map[string]map[string]any {
	out, err := exec.CommandContext(ctx, "docker", "stats", "--no-stream", "--format", "{{json .}}").Output()
	if err != nil {
		return map[string]map[string]any{}
	}
	result := map[string]map[string]any{}
	for _, line := range strings.Split(strings.TrimSpace(string(out)), "\n") {
		if strings.TrimSpace(line) == "" {
			continue
		}
		var row map[string]any
		if json.Unmarshal([]byte(line), &row) != nil {
			continue
		}
		name, _ := row["Name"].(string)
		if name != "" {
			result[name] = row
		}
	}
	return result
}

func extractLabel(labels string, key string) string {
	for _, part := range strings.Split(labels, ",") {
		pair := strings.SplitN(strings.TrimSpace(part), "=", 2)
		if len(pair) == 2 && pair[0] == key {
			return pair[1]
		}
	}
	return ""
}

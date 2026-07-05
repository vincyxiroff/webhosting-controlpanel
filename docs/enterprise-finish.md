# Enterprise Finish Layers

This document tracks the systems required beyond a simple web panel.

## Scheduler

The placement engine now records every decision in `placement_decisions`. It applies hard constraints before scoring:

- CPU, RAM, and disk capacity after reservations.
- Per-plan overcommit policy.
- Region constraints.
- Required labels.
- Affinity and anti-affinity.

The score combines bin-packing headroom, live pressure, SLA tier priority, and affinity. Rejections are persisted for operator debugging.

## Multi-Node HA

`FailoverOrchestrator` detects stale heartbeats, marks nodes offline, opens incidents, and creates migration jobs that restore the latest backup, reroute edge traffic, and sync DNS.

Stateful recovery still depends on the configured storage policy and database backup strategy. The schema now supports incident lifecycle and recovery plans.

## Distributed Storage

`StorageReplicationService` models storage policies and replication jobs. Supported backends are intended to include:

- S3-compatible object storage.
- MinIO clusters.
- ZFS snapshots.
- rsync-based cold replication.

## Usage Metering And Enforcement

`UsageMeteringService` stores raw samples and rolls them into 1m, 5m, and 1h aggregates. `UsageEnforcementService` evaluates quotas and queues node commands for throttling or suspension.

## Edge Routing

`EdgeRoutingService` publishes per-site hostname routes with origin node, edge pool, routing policy, and health policy. Failover can repoint routes to a recovered origin.

## Security Engine

`SecurityPolicyEngine` creates scored security decisions from WAF matches, IP reputation, request rate anomalies, and bot-like client signals. Actions are `allow`, `rate_limit`, `challenge`, and `block`.


# PowerDNS

The control plane writes authoritative DNS intent into PostgreSQL and syncs PowerDNS records through the PowerDNS API. Zone writes are performed through queued jobs with idempotency keys so retries cannot duplicate records.


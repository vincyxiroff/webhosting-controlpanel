# Module Map

## Auth

JWT plus session hybrid auth, OAuth2 providers, passkeys, TOTP, backup codes, RBAC, API keys, and audit trail. Tokens are short-lived and refresh is session-bound.

## Tenancy

Tenant hierarchy supports provider, reseller, agency, SaaS, and customer accounts. All resources include `tenant_id`; reseller ownership is represented through parent-child relationships and delegated quotas.

## Hosting Plans

Plans define resources, software versions, feature flags, domain limits, database limits, email limits, security defaults, billing pricing, upgrade and downgrade rules, and node affinity.

## Nodes

Nodes register with a one-time token, complete mTLS bootstrap, report heartbeats, expose role capabilities, and execute signed operations.

## Sites

Sites model runtime, repository, domains, databases, SSL state, deployment pipeline, filesystem root, container identity, quotas, and node placement.

## NGINX

The VHost engine renders from typed directives, validates syntax in a staging path, writes a versioned snapshot, reloads NGINX, and rolls back on failure.

## SSL

Providers include Let's Encrypt, ZeroSSL, BuyPass, and custom certificates. Challenges support HTTP-01, TLS-ALPN-01, and DNS-01. Renewal is queued and monitored.

## DNS

PowerDNS-backed DNS zones support templates, DNSSEC, full record coverage, import/export, and cluster sync.

## Email

Postfix, Dovecot, Rspamd, and Roundcube integration supports mailboxes, forwarders, autoresponders, SPF, DKIM, DMARC, quotas, logs, and anti-abuse policy.

## Deployment

Git integrations, branch deployments, preview domains, build pipelines, environment variables, rollbacks, webhook triggers, and live logs.

## Marketplace

Apps are declarative manifests with install, update, health, backup, restore, and uninstall hooks.

## Billing

FOSSBilling is a native subsystem for provisioning, suspension, termination, plan changes, usage sync, metering, addons, migration, and billing webhooks.


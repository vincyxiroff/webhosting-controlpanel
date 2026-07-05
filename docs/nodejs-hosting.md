# Node.js Hosting

ControlPanel can host Node.js applications as isolated Docker containers behind NGINX.

## Runtime Model

```text
Node.js site
  -> Docker image node:{runtime_version}-alpine
  -> Docker volume mounted at /app
  -> PORT/HOST/NODE_ENV env vars
  -> host port allocated on 127.0.0.1
  -> bootstrap command install/build/start
  -> NGINX nodejs vhost template
```

Each site has its own container, network, volume, env file and NGINX vhost.

## Create Payload

```json
{
  "plan_id": "00000000-0000-0000-0000-000000000000",
  "name": "my-node-app",
  "primary_domain": "app.example.com",
  "runtime": "node",
  "runtime_version": "22",
  "vhost_template": "nodejs",
  "app_port": 3000,
  "host_port": 31000,
  "install_command": "npm ci --omit=dev",
  "build_command": "npm run build",
  "start_command": "npm run start",
  "environment": {
    "NODE_ENV": "production"
  }
}
```

Optional command fields can be omitted. Defaults:

| Field | Default |
| --- | --- |
| `app_port` | `3000` |
| `host_port` | first free TCP port on the assigned node from `31000` to `39999` |
| `install_command` | `npm ci --omit=dev` when `package-lock.json` exists, otherwise `npm install --omit=dev` |
| `build_command` | runs `npm run build` only if a build script exists |
| `start_command` | `npm run start`, then `node server.js`, then `node index.js` |
| `vhost_template` | `nodejs` |

The container receives:

```text
PORT={app_port}
HOST=0.0.0.0
NODE_ENV=production
```

The container is published only on localhost:

```text
127.0.0.1:{host_port} -> container:{app_port}
```

NGINX routes traffic to that local host port. This avoids port collisions between sites and avoids exposing Node containers directly to the internet.

## Framework Notes

| Framework | Typical settings |
| --- | --- |
| Express | `start_command: node server.js`, `app_port: 3000` |
| Next.js | `build_command: npm run build`, `start_command: npm run start`, `app_port: 3000` |
| NestJS | `build_command: npm run build`, `start_command: node dist/main.js`, `app_port: 3000` |
| Nuxt | `build_command: npm run build`, `start_command: npm run start`, `app_port: 3000` |
| Vite SSR | set the app to listen on `0.0.0.0` and `process.env.PORT` |

## NGINX

The `nodejs` template is stored at:

```text
infra/nginx/vhost-templates/nodejs.conf
```

It includes:

- websocket upgrade headers;
- long upstream timeouts;
- ACME challenge passthrough;
- forwarded host/protocol headers;
- per-site access/error logs.

## Reconciliation

`runtime_config` is included in desired-state hashing. Changing `app_port`, `host_port`, `vhost_template`, `document_root`, or command fields causes the consistency engine to detect drift and requeue runtime/NGINX commands.

## Port Allocation

The control plane stores reservations in `site_port_allocations`.

Rules:

- one `host_port` can be allocated only once per node;
- one site keeps its reservation across update/restore;
- requested `host_port` is rejected if already used on the same node;
- automatic allocation scans `31000-39999`;
- delete marks site ports as released.

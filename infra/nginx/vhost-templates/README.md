# ControlPanel VHost Templates

These local templates are adapted from common hosting-panel vhost patterns and informed by the public CloudPanel vhost-template catalog:

https://github.com/cloudpanel-io/vhost-templates

The upstream repository did not include a license file at the time of integration, so these files are not verbatim copies. They keep the useful operational ideas locally:

- ACME challenge passthrough;
- dotfile protection;
- long upstream timeouts for app runtimes;
- static asset caching;
- PHP-FPM `try_files` hardening;
- WordPress `xmlrpc.php` blocking;
- Laravel `/public` document root;
- reverse proxy websocket headers.

Supported local template names:

- `generic-php`
- `laravel`
- `wordpress`
- `nodejs`
- `static`
- `reverse-proxy`
- `python`

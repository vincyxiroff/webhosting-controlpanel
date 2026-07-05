<?php

namespace App\Domain\Nginx;

use App\Support\DomainEvent;
use App\Support\EventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VhostService
{
    public function __construct(private readonly EventRecorder $events)
    {
    }

    public function createRevision(string $siteId, string $tenantId, array $directives, string $actorId): array
    {
        $revisionId = (string) Str::uuid();
        $rendered = $this->render($siteId, $directives);

        DB::table('vhost_revisions')->insert([
            'id' => $revisionId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
            'directives' => json_encode($directives, JSON_THROW_ON_ERROR),
            'rendered_config' => $rendered,
            'status' => 'pending_validation',
            'created_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->events->record(new DomainEvent('vhost.revision.created', 'site', $siteId, [
            'revision_id' => $revisionId,
        ], $tenantId));

        return ['revision_id' => $revisionId, 'rendered_config' => $rendered];
    }

    private function render(string $siteId, array $directives): string
    {
        $serverNames = implode(' ', array_map('escapeshellarg', $directives['server_names'] ?? []));
        $upstream = $directives['upstream_url'] ?? 'http://127.0.0.1:8080';

        return strtr(file_get_contents(base_path('../../infra/nginx/site-template.conf')), [
            '{{ site_id }}' => $siteId,
            '{{ server_names }}' => $serverNames,
            '{{ upstream_url }}' => $upstream,
        ]);
    }
}


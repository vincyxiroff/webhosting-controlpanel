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
        $template = $this->safeTemplate($directives['vhost_template'] ?? $directives['template'] ?? 'reverse-proxy');
        $templatePath = base_path('../../infra/nginx/vhost-templates/' . $template . '.conf');
        if (! is_file($templatePath)) {
            $templatePath = base_path('../../infra/nginx/site-template.conf');
        }

        $serverNames = implode(' ', $this->serverNames($directives['server_names'] ?? []));
        $upstream = $directives['upstream_name'] ?? 'cp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $siteId);
        $documentRoot = $directives['document_root'] ?? '/app';

        return strtr(file_get_contents($templatePath), [
            '{{ site_id }}' => $siteId,
            '{{ server_names }}' => $serverNames,
            '{{ upstream_url }}' => $upstream,
            '{{ upstream_name }}' => $upstream,
            '{{ document_root }}' => $documentRoot,
        ]);
    }

    private function safeTemplate(string $template): string
    {
        $template = strtolower(str_replace('_', '-', trim($template)));
        if (! preg_match('/^[a-z0-9-]+$/', $template)) {
            return 'reverse-proxy';
        }

        return $template;
    }

    private function serverNames(array $serverNames): array
    {
        return collect($serverNames)
            ->map(fn (string $name): string => strtolower(trim($name)))
            ->filter(fn (string $name): bool => (bool) preg_match('/^[a-z0-9.*-]+(\.[a-z0-9.*-]+)*$/', $name))
            ->values()
            ->all() ?: ['_'];
    }
}

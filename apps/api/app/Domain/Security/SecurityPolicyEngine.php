<?php

namespace App\Domain\Security;

use Illuminate\Support\Facades\DB;

final class SecurityPolicyEngine
{
    public function analyzeRequest(string $siteId, array $request): array
    {
        $score = 0;
        $signals = [];

        if (($request['waf_match_count'] ?? 0) > 0) {
            $score += min(60, (int) $request['waf_match_count'] * 20);
            $signals[] = 'waf_rule_match';
        }
        if (($request['ip_reputation'] ?? 'unknown') === 'bad') {
            $score += 35;
            $signals[] = 'bad_ip_reputation';
        }
        if (($request['requests_per_minute'] ?? 0) > 300) {
            $score += 25;
            $signals[] = 'rate_anomaly';
        }
        if (($request['user_agent_entropy'] ?? 0) < 0.2) {
            $score += 10;
            $signals[] = 'bot_like_user_agent';
        }

        $action = match (true) {
            $score >= 80 => 'block',
            $score >= 50 => 'challenge',
            $score >= 30 => 'rate_limit',
            default => 'allow',
        };

        DB::table('security_events')->insert([
            'id' => (string) str()->uuid(),
            'site_id' => $siteId,
            'severity' => $score >= 80 ? 'critical' : ($score >= 50 ? 'high' : 'medium'),
            'score' => $score,
            'action' => $action,
            'signals' => json_encode($signals, JSON_THROW_ON_ERROR),
            'request_fingerprint' => hash('sha256', json_encode($request, JSON_THROW_ON_ERROR)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['score' => $score, 'action' => $action, 'signals' => $signals];
    }
}


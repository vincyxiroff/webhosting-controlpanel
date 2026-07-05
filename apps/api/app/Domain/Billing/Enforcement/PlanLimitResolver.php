<?php

namespace App\Domain\Billing\Enforcement;

use Illuminate\Support\Facades\DB;

final class PlanLimitResolver
{
    public function limitsForTenant(string $tenantId): array
    {
        $profile = DB::table('tenant_billing_profiles')->where('tenant_id', $tenantId)->first();
        if ($profile !== null) {
            return [
                'limits' => json_decode($profile->limits, true, 512, JSON_THROW_ON_ERROR),
                'soft' => json_decode($profile->soft_thresholds, true, 512, JSON_THROW_ON_ERROR),
                'hard' => json_decode($profile->hard_thresholds, true, 512, JSON_THROW_ON_ERROR),
                'billing_status' => $profile->billing_status,
            ];
        }

        $plan = DB::table('sites')
            ->join('hosting_plans', 'sites.plan_id', '=', 'hosting_plans.id')
            ->where('sites.tenant_id', $tenantId)
            ->select('hosting_plans.*')
            ->orderByDesc('hosting_plans.created_at')
            ->first();

        $billingPolicy = $plan ? $this->decode($plan->billing_policy ?? []) : [];
        $limits = $billingPolicy['limits'] ?? [
            'cpu_percent_avg' => 80,
            'memory_bytes_max' => 1024 * 1024 * 1024,
            'disk_usage_bytes_max' => 10 * 1024 * 1024 * 1024,
            'bandwidth_bytes_sum' => 10 * 1024 * 1024 * 1024,
            'request_count_sum' => 100000,
            'active_sites' => 5,
            'active_containers' => 5,
        ];

        return [
            'limits' => $limits,
            'soft' => $billingPolicy['soft_thresholds'] ?? ['ratio' => 0.8],
            'hard' => $billingPolicy['hard_thresholds'] ?? ['ratio' => 1.0],
            'billing_status' => 'active',
        ];
    }

    private function decode(mixed $value): array
    {
        if (is_string($value)) {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR) ?? [];
        }

        return is_array($value) ? $value : [];
    }
}

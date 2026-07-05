<?php

namespace App\Http\Controllers;

use App\Domain\Billing\FossBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BillingWebhookController
{
    public function receive(Request $request, FossBillingService $billing): JsonResponse
    {
        $billing->receiveWebhook($request->all(), (string) $request->header('X-FOSSBilling-Signature'));

        return response()->json(['status' => 'accepted'], 202);
    }
}


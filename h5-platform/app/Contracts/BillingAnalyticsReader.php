<?php

namespace App\Contracts;

interface BillingAnalyticsReader
{
    /** Internal read port, NOT an HTTP endpoint. Up to 200 UUID payment IDs; missing IDs omitted.
     * Associative result keyed by payment_id; strict row shape in analytics-v1.md.
     * The consumer must establish staff/link scope before calling. Never includes provider secrets/IDs/PII.
     */
    public function payments(array $paymentIds): array;
}

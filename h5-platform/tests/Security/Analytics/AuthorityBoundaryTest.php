<?php

namespace Tests\Security\Analytics;

use App\Contracts\BillingAnalyticsReader;
use App\Support\Analytics\BehaviorEventCatalog;
use App\Support\DependencyNotReady;
use App\Support\UnavailableBillingAnalyticsReader;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Shared authority boundaries only, never a substitute for the pending ingestion API tests. */
final class AuthorityBoundaryTest extends TestCase
{
    public function test_financial_reader_is_an_internal_port_and_missing_facts_are_not_zero(): void
    {
        $this->assertInstanceOf(BillingAnalyticsReader::class, app(BillingAnalyticsReader::class));
        foreach (Route::getRoutes() as $route) {
            $this->assertNotSame(BillingAnalyticsReader::class, $route->getActionName());
        }
        $this->expectException(DependencyNotReady::class);
        (new UnavailableBillingAnalyticsReader)->payments([]);
    }

    public function test_browser_catalog_never_grants_identity_or_money_authority(): void
    {
        $this->assertSame([], array_intersect(BehaviorEventCatalog::BROWSER_EVENTS, BehaviorEventCatalog::SERVER_EVENTS));
        foreach (['registration_succeeded', 'email_verified', 'payment_confirmed', 'refund_confirmed',
            'subscription_cancelled', 'subscription_resumed'] as $name) {
            $this->assertContains($name, BehaviorEventCatalog::SERVER_EVENTS);
            $this->assertNotContains($name, BehaviorEventCatalog::BROWSER_EVENTS);
        }
    }
}

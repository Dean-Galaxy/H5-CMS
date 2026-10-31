<?php

namespace App\Data;

/** Constructed by Audience only from its server-side, actually rendered purchase draft. */
final readonly class CheckoutPageContext extends ContractData
{
    public function __construct(public string $path, public string $rendered_at) {}
}

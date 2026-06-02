<?php

namespace Tests\Unit;

use App\Support\ShopwareStateResolver;
use PHPUnit\Framework\TestCase;

class ShopwareStateResolverTest extends TestCase
{
    public function test_reads_state_association_technical_name(): void
    {
        $this->assertSame('open', ShopwareStateResolver::technicalName([
            'state' => ['technicalName' => 'open'],
        ]));
    }

    public function test_reads_legacy_state_machine_state_technical_name(): void
    {
        $this->assertSame('paid', ShopwareStateResolver::technicalName([
            'stateMachineState' => ['technicalName' => 'paid'],
        ]));
    }

    public function test_reads_state_id(): void
    {
        $this->assertSame('abc123', ShopwareStateResolver::stateId([
            'stateId' => 'abc123',
        ]));
    }
}

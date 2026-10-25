<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_adding_is_exact_where_floats_are_not(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2); // the problem this class exists for
        $this->assertSame(0.3, Money::add(0.1, 0.2));
        $this->assertSame(0.0, Money::add(1000.10, 0.20, -1000.30));
    }

    public function test_paisa_reads_every_form_an_amount_comes_in(): void
    {
        $this->assertSame(100030, Money::paisa('1000.30')); // SQL decimal / request field
        $this->assertSame(100030, Money::paisa(1000.3));     // float cast
        $this->assertSame(100000, Money::paisa(1000));
        $this->assertSame(0, Money::paisa(null));
        $this->assertSame(1, Money::paisa(0.005));           // more than two decimals rounds to the paisa
        $this->assertSame(99999999999999, Money::paisa('999999999999.99')); // the largest a decimal(14,2) column holds
    }

    public function test_sum_takes_items_fields_or_a_callback(): void
    {
        $rows = [['due' => 0.1], ['due' => 0.2], ['due' => 0.4]];

        $this->assertSame(0.7, Money::sum($rows, 'due'));
        $this->assertSame(0.7, Money::sum([0.1, 0.2, 0.4]));
        $this->assertSame(1.4, Money::sum($rows, fn ($r) => $r['due'] * 2));
        $this->assertSame(0.0, Money::sum([]));
    }

    public function test_split_always_adds_back_to_the_total(): void
    {
        $this->assertSame([33333, 33333, 33334], Money::split(100000, 3));
        $this->assertSame([67, 67, 66], Money::split(200, 3));
        $this->assertSame([500], Money::split(500, 1));
        $this->assertSame(66000000, array_sum(Money::split(66000000, 7)));
    }

    public function test_shares_and_percentages_round_to_the_paisa(): void
    {
        // a 330,000 payment on a plan of 600,000 principal / 660,000 total is 300,000 principal
        $this->assertSame(30000000, Money::share(33000000, 600000, 660000));
        $this->assertSame(0, Money::share(100, 1, 0));
        $this->assertSame(1500000, Money::percent(3000000, 50));
        $this->assertSame(33, Money::percent(100, 33.333));
    }

    public function test_format_for_messages(): void
    {
        $this->assertSame('1,000.30', Money::format(100030));
    }
}

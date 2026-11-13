<?php

namespace Tests\Unit;

use App\Support\FeatureCatalog;
use PHPUnit\Framework\TestCase;

class FeatureCatalogTest extends TestCase
{
    public function test_keys_are_unique_and_typed(): void
    {
        $keys = FeatureCatalog::keys();
        $this->assertSame($keys, array_values(array_unique($keys)));

        foreach (FeatureCatalog::all() as [$key, $type, $description]) {
            $this->assertContains($type, [FeatureCatalog::BOOLEAN, FeatureCatalog::LIMIT], $key);
            $this->assertNotSame('', $description, $key);
            $this->assertSame($type, FeatureCatalog::typeOf($key));
        }

        $this->assertNull(FeatureCatalog::typeOf('no_such_feature'));
    }
}

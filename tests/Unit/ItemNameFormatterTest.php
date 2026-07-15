<?php

namespace Tests\Unit;

use App\Support\ItemNameFormatter;
use PHPUnit\Framework\TestCase;

class ItemNameFormatterTest extends TestCase
{
    public function test_formats_multi_variant_items_with_em_dash(): void
    {
        $this->assertSame(
            'Pastel — Carne',
            ItemNameFormatter::format('Pastel', 'Carne', 2, 2)
        );
    }

    public function test_omits_variant_suffix_for_single_variant_products(): void
    {
        $this->assertSame(
            'Milho Verde',
            ItemNameFormatter::format('Milho Verde', 'Unidade', 1, 1)
        );
    }

    public function test_normalizes_legacy_question_mark_separator(): void
    {
        $this->assertSame(
            'Pastel — Carne',
            ItemNameFormatter::normalizeLegacy('Pastel ? Carne')
        );
    }
}

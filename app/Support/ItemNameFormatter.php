<?php

namespace App\Support;

class ItemNameFormatter
{
  private const LEGACY_SEPARATOR = ' ? ';

  private const SEPARATOR = ' — ';

  public static function format(
    string $productName,
    string $variantLabel,
    int $availableOfferingVariantCount,
    int $productTemplateCount,
  ): string {
    $hasMultipleVariants = $availableOfferingVariantCount > 1 || $productTemplateCount > 1;

    if (! $hasMultipleVariants) {
      return $productName;
    }

    return $productName.self::SEPARATOR.$variantLabel;
  }

  public static function normalizeLegacy(string $name): string
  {
    return str_replace(self::LEGACY_SEPARATOR, self::SEPARATOR, $name);
  }
}

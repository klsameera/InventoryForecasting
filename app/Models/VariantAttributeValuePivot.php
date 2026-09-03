<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Typed pivot for `product_variants` <-> `attribute_values` (table
 * `variant_attribute_values`), so the denormalized `attribute_id` column is
 * statically known instead of relying on the generic, untyped Pivot model.
 *
 * @property int $product_variant_id
 * @property int $attribute_value_id
 * @property int $attribute_id
 */
class VariantAttributeValuePivot extends Pivot {}

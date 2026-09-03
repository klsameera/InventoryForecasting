<?php

use App\Models\Attribute;
use App\Models\AttributeValue;
use App\Models\ProductVariant;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('attribute.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the attribute list', function () {
    $user = User::factory()->create();
    Attribute::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('attribute.index'));

    $response->assertOk();
});

test('an attribute can be created with values', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('attribute.store'), [
        'name' => 'Size',
        'code' => 'ATTR-SIZE',
        'data_type' => 'select',
        'forecast_relevant' => true,
        'values' => [
            ['value' => '41', 'sort_order' => 0],
            ['value' => '42', 'sort_order' => 1],
        ],
    ]);

    $response->assertRedirect(route('attribute.index'));
    $attribute = Attribute::where('code', 'ATTR-SIZE')->firstOrFail();
    expect($attribute->values()->pluck('value')->all())->toBe(['41', '42']);
});

test('updating an attribute syncs its values', function () {
    $user = User::factory()->create();
    $attribute = Attribute::factory()->create();
    $kept = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => '41']);
    $removed = AttributeValue::factory()->create(['attribute_id' => $attribute->id, 'value' => '42']);

    $response = $this->actingAs($user)->post(route('attribute.update', $attribute->id), [
        'name' => $attribute->name,
        'code' => $attribute->code,
        'data_type' => $attribute->data_type,
        'forecast_relevant' => false,
        'values' => [
            ['id' => $kept->id, 'value' => '41', 'sort_order' => 0],
            ['value' => '43', 'sort_order' => 1],
        ],
    ]);

    $response->assertRedirect(route('attribute.index'));
    $this->assertDatabaseHas('attribute_values', ['id' => $kept->id]);
    $this->assertDatabaseMissing('attribute_values', ['id' => $removed->id]);
    $this->assertDatabaseHas('attribute_values', ['attribute_id' => $attribute->id, 'value' => '43']);
});

test('an attribute value in use by a variant cannot be removed', function () {
    $user = User::factory()->create();
    $attribute = Attribute::factory()->create();
    $value = AttributeValue::factory()->create(['attribute_id' => $attribute->id]);
    $variant = ProductVariant::factory()->create();
    $variant->attributeValues()->attach($value->id, ['attribute_id' => $attribute->id]);

    $response = $this->actingAs($user)->post(route('attribute.update', $attribute->id), [
        'name' => $attribute->name,
        'code' => $attribute->code,
        'data_type' => $attribute->data_type,
        'forecast_relevant' => false,
        'values' => [],
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('attribute_values', ['id' => $value->id]);
});

test('an attribute can be soft deleted', function () {
    $user = User::factory()->create();
    $attribute = Attribute::factory()->create();

    $response = $this->actingAs($user)->delete(route('attribute.delete', $attribute->id));

    $response->assertRedirect();
    $this->assertSoftDeleted('attributes', ['id' => $attribute->id]);
});

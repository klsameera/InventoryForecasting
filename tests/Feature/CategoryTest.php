<?php

use App\Models\Category;
use App\Models\Product;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('category.index'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can view the category list', function () {
    $user = User::factory()->create();
    Category::factory()->count(3)->create();

    $response = $this->actingAs($user)->get(route('category.index'));

    $response->assertOk();
});

test('a category can be created with a parent', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create();

    $response = $this->actingAs($user)->post(route('category.store'), [
        'parent_id' => $parent->id,
        'name' => 'Running shoes',
        'code' => 'CAT-001',
        'status' => true,
    ]);

    $response->assertRedirect(route('category.index'));
    $this->assertDatabaseHas('categories', [
        'name' => 'Running shoes',
        'parent_id' => $parent->id,
    ]);
});

test('a category can be updated', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create(['name' => 'Old Name']);

    $response = $this->actingAs($user)->post(route('category.update', $category->id), [
        'name' => 'New Name',
        'code' => $category->code,
        'status' => true,
    ]);

    $response->assertRedirect(route('category.index'));
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'New Name']);
});

test('a category cannot become its own parent', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $response = $this->actingAs($user)->post(route('category.update', $category->id), [
        'parent_id' => $category->id,
        'name' => $category->name,
        'code' => $category->code,
        'status' => true,
    ]);

    $response->assertInertiaFlash('toast.type', 'error');
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'parent_id' => null]);
});

test('a category with sub-categories cannot be deleted', function () {
    $user = User::factory()->create();
    $parent = Category::factory()->create();
    Category::factory()->create(['parent_id' => $parent->id]);

    $response = $this->actingAs($user)->delete(route('category.delete', $parent->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('categories', ['id' => $parent->id, 'deleted_at' => null]);
});

test('a category with products cannot be deleted', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);

    $response = $this->actingAs($user)->delete(route('category.delete', $category->id));

    $response->assertRedirect();
    $this->assertDatabaseHas('categories', ['id' => $category->id, 'deleted_at' => null]);
});

test('an empty category can be soft deleted', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $response = $this->actingAs($user)->delete(route('category.delete', $category->id));

    $response->assertRedirect();
    $this->assertSoftDeleted('categories', ['id' => $category->id]);
});

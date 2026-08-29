<?php

use App\Models\User;

test('guests are redirected to the panel login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect('/admin/login');
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

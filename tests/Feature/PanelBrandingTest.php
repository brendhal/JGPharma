<?php

use App\Enums\UserRole;
use App\Filament\Pages\PointOfSale;
use App\Models\User;

test('the brand pairs the logo with the shop name', function () {
    $brand = view('filament.brand')->render();

    expect($brand)
        ->toContain('JG RAMOS DRUGMART')
        ->toContain('logo.png');
});

test('the shop name greets every role on the POS terminal', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(PointOfSale::getUrl(panel: 'admin'))
        ->assertOk()
        ->assertSee('JG RAMOS DRUGMART');
})->with([
    'administrator' => UserRole::ADMIN,
    'branch manager' => UserRole::MANAGER,
    'cashier' => UserRole::CASHIER,
]);

test('the shop name greets visitors on the login screen', function () {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('JG RAMOS DRUGMART');
});

test('the POS selects paint a single, pinned chevron', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);

    $page = $this->actingAs($cashier)
        ->get(PointOfSale::getUrl(panel: 'admin'))
        ->assertOk()
        ->getContent();

    // Filament paints a chevron on every <select>. The page must pin it down
    // itself: the `background` shorthand resets background-repeat/size/position
    // and tiles that arrow the length of the field.
    expect($page)
        ->toContain('select.pos-select')
        ->toContain('appearance: none')
        ->toContain('background-repeat: no-repeat')
        ->toContain('background-position: right 0.625rem center');
});

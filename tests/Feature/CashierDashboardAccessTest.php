<?php

use App\Enums\UserRole;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\PointOfSale;
use App\Filament\Widgets\RecentTransactions;
use App\Filament\Widgets\SalesOverview;
use App\Filament\Widgets\StockAlerts;
use App\Models\User;

test('cashiers are redirected from the dashboard to the POS terminal', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);

    $this->actingAs($cashier)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertRedirect(PointOfSale::getUrl(panel: 'admin'));
});

test('the dashboard stays out of the cashier navigation', function () {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);

    $this->actingAs($cashier);

    expect(Dashboard::canAccess())->toBeFalse();
});

test('management can still open the dashboard', function (UserRole $role) {
    $user = User::factory()->create(['role' => $role]);

    $this->actingAs($user)
        ->get(Dashboard::getUrl(panel: 'admin'))
        ->assertOk();
})->with([
    'administrator' => UserRole::ADMIN,
    'branch manager' => UserRole::MANAGER,
]);

test('store-wide widgets are hidden from cashiers', function (string $widget) {
    $cashier = User::factory()->create(['role' => UserRole::CASHIER]);

    $this->actingAs($cashier);

    expect($widget::canView())->toBeFalse();
})->with([
    SalesOverview::class,
    StockAlerts::class,
    RecentTransactions::class,
]);

test('store-wide widgets stay visible to management', function (string $widget) {
    $admin = User::factory()->create(['role' => UserRole::ADMIN]);

    $this->actingAs($admin);

    expect($widget::canView())->toBeTrue();
})->with([
    SalesOverview::class,
    StockAlerts::class,
    RecentTransactions::class,
]);

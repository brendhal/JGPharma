<?php

use App\Http\Controllers\CollectionsReportController;
use App\Http\Controllers\DisposalReportController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SalesReportController;
use App\Http\Controllers\SupplierClaimController;
use App\Http\Controllers\SupplierPriceComparisonController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin/login')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

/*
| Printables opened from the Filament panel in a new tab. Auth only — the
| panel's own users reach these, and ReceiptController enforces the
| branch check itself.
*/
Route::middleware('auth')->group(function () {
    Route::get('labels/{product}', [LabelController::class, 'sheet'])->name('labels.sheet');
    Route::get('labels/{product}/qr', [LabelController::class, 'qr'])->name('labels.qr');
    Route::get('receipts/{sale}', ReceiptController::class)->name('receipts.show');
    Route::get('purchase-orders/{purchase}', PurchaseOrderController::class)->name('purchases.print');
    Route::get('sales-report', SalesReportController::class)->name('sales.report');
    Route::get('collections-report', CollectionsReportController::class)->name('collections.report');
    Route::get('disposal-report', DisposalReportController::class)->name('disposals.report');
    Route::get('supplier-claims', SupplierClaimController::class)->name('disposals.claims');
    Route::get('supplier-price-comparison', SupplierPriceComparisonController::class)->name('suppliers.price-comparison');
});

require __DIR__.'/settings.php';

<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CollectionController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DraftController;
use App\Http\Controllers\Api\InstallmentController;
use App\Http\Controllers\Api\InvestorController;
use App\Http\Controllers\Api\InvestorSettlementController;
use App\Http\Controllers\Api\PayableController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PhotoController;
use App\Http\Controllers\Api\PlatformSettingsController;
use App\Http\Controllers\Api\PromiseController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\ShopController;
use App\Http\Controllers\Api\SignupController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleCategoryController;
use App\Http\Controllers\Api\VehicleColorController;
use App\Http\Controllers\Api\VehicleController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:10,1');
Route::get('auth/config', [AuthController::class, 'config']);
Route::post('auth/google', [AuthController::class, 'google'])->middleware('throttle:10,1');
Route::post('signup', [SignupController::class, 'store'])->middleware('throttle:5,1');
Route::post('signup/google', [SignupController::class, 'google'])->middleware('throttle:5,1');
Route::post('signup/confirm', [SignupController::class, 'confirm'])->middleware('throttle:10,1');
Route::post('signup/resend', [SignupController::class, 'resend'])->middleware('throttle:signup-resend');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::get('me', [AuthController::class, 'me']);
    Route::put('profile', [AuthController::class, 'updateProfile']);
    Route::post('profile/photo', [PhotoController::class, 'storeOwn']);
    Route::delete('profile/photo', [PhotoController::class, 'destroyOwn']);

    // Platform: super admin only.
    Route::middleware('role:super_admin')->group(function () {
        Route::apiResource('shops', ShopController::class)->except('destroy');
        Route::post('permissions', [PermissionController::class, 'store']);
        Route::put('permissions/{permission}', [PermissionController::class, 'update']);
        Route::delete('permissions/{permission}', [PermissionController::class, 'destroy']);
        Route::get('platform-settings', [PlatformSettingsController::class, 'show']);
        Route::put('platform-settings', [PlatformSettingsController::class, 'update']);
        Route::post('platform-settings/test-mail', [PlatformSettingsController::class, 'testMail'])->middleware('throttle:5,1');
    });

    Route::get('vehicle-categories', [VehicleCategoryController::class, 'index']);
    Route::post('vehicle-categories', [VehicleCategoryController::class, 'store']);
    Route::put('vehicle-categories/{category}', [VehicleCategoryController::class, 'update']);
    Route::delete('vehicle-categories/{category}', [VehicleCategoryController::class, 'destroy']);
    Route::get('permissions', [PermissionController::class, 'index'])->middleware('role:super_admin,shop_owner');

    // Shop owner: manage the shop's users and what each role may do.
    Route::middleware(['shop', 'role:shop_owner'])->group(function () {
        Route::get('roles', [RoleController::class, 'index']);
        Route::put('roles/{role}/permissions', [RoleController::class, 'update']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::post('users/{user}/photo', [PhotoController::class, 'storeUser']);
        Route::delete('users/{user}/photo', [PhotoController::class, 'destroyUser']);
    });

    // Shop data: requires a shop user holding the matching permission (see each controller).
    Route::middleware('shop')->group(function () {
        Route::get('dashboard', DashboardController::class);

        // photos of customers, suppliers and investors: whoever may edit them may change the photo
        foreach (['customers' => 'parties.edit', 'suppliers' => 'parties.edit', 'investors' => 'investors.edit'] as $type => $permission) {
            Route::post("$type/{id}/photo", [PhotoController::class, 'store'])->defaults('type', $type)->middleware("permission:$permission");
            Route::delete("$type/{id}/photo", [PhotoController::class, 'destroy'])->defaults('type', $type)->middleware("permission:$permission");
        }
        Route::get('reports/stock', [ReportController::class, 'stock']);
        Route::get('reports/dues', [ReportController::class, 'dues']);
        Route::get('reports/purchases', [ReportController::class, 'purchases']);
        Route::get('reports/sales', [ReportController::class, 'sales']);
        Route::get('reports/investor-transactions', [ReportController::class, 'investorTransactions']);
        Route::get('reports/daily', [ReportController::class, 'daily']);
        Route::get('reports/transactions', [ReportController::class, 'transactions']);
        Route::get('reports/person-summary', [ReportController::class, 'personSummary']);
        Route::get('reports/person-transactions', [ReportController::class, 'personTransactions']);
        Route::get('reports/investors', [ReportController::class, 'investors']);

        Route::apiResource('customers', CustomerController::class)->except('destroy');
        Route::get('customers/{customer}/history', [CustomerController::class, 'history']);
        Route::apiResource('suppliers', SupplierController::class)->except('destroy');
        Route::get('suppliers/{supplier}/history', [SupplierController::class, 'history']);

        Route::apiResource('investors', InvestorController::class)->except('destroy');
        Route::put('investors/{investor}/profit-formula', [InvestorController::class, 'saveProfitFormula']);
        Route::get('investors/{investor}/vehicle-payments', [InvestorController::class, 'vehiclePayments']);
        Route::get('investors/{investor}/portfolio', [InvestorController::class, 'portfolio']);

        Route::get('vehicles/lookup', [VehicleController::class, 'lookup']);
        Route::get('vehicles/owned', [VehicleController::class, 'owned']);
        Route::apiResource('vehicles', VehicleController::class)->only(['index', 'show', 'update']);
        Route::get('vehicles/{vehicle}/history', [VehicleController::class, 'history']);

        Route::apiResource('purchases', PurchaseController::class)->except('destroy');
        Route::get('accounts', [AccountController::class, 'summary']);
        Route::post('accounts', [AccountController::class, 'store']);
        Route::get('payables', [PayableController::class, 'index']);
        Route::post('purchases/{purchase}/payments', [PayableController::class, 'pay']);
        Route::get('purchases/{purchase}/payments', [PayableController::class, 'purchasePayments']);
        Route::get('advances', [PayableController::class, 'advances']);
        Route::post('advances', [PayableController::class, 'storeAdvance']);
        Route::apiResource('sales', SaleController::class)->only(['index', 'store', 'show']);
        Route::post('sales/{sale}/payments', [PaymentController::class, 'store']);
        Route::get('promises', [PromiseController::class, 'index']);
        Route::post('sales/{sale}/promises', [PromiseController::class, 'store']);
        Route::patch('sales/{sale}/registration', [RegistrationController::class, 'update']);

        Route::get('installments', [InstallmentController::class, 'index']);

        Route::apiResource('investor-settlements', InvestorSettlementController::class)
            ->parameters(['investor-settlements' => 'settlement'])
            ->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::get('vehicle-colors', [VehicleColorController::class, 'index']);
        Route::post('vehicle-colors', [VehicleColorController::class, 'store']);
        Route::get('drafts', [DraftController::class, 'index']);
        Route::post('drafts', [DraftController::class, 'store']);
        Route::put('drafts/{draft}', [DraftController::class, 'update']);
        Route::delete('drafts/{draft}', [DraftController::class, 'destroy']);
        Route::get('settings', [SettingsController::class, 'show']);
        Route::put('settings', [SettingsController::class, 'update']);
        Route::get('shop', [SettingsController::class, 'shop']);
        Route::put('shop', [SettingsController::class, 'updateShop']);
        Route::get('collections/summary', [CollectionController::class, 'summary']);
        Route::get('collections/upcoming', [CollectionController::class, 'upcoming']);
        Route::get('collections/call-history', [CollectionController::class, 'callHistory']);
        Route::post('collections/call', [CollectionController::class, 'markCalled']);
        Route::delete('collections/call', [CollectionController::class, 'unmarkCalled']);
        Route::delete('customers/{customer}', [CustomerController::class, 'destroy']);
        Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy']);
        Route::delete('investors/{investor}', [InvestorController::class, 'destroy']);
        Route::delete('vehicles/{vehicle}', [VehicleController::class, 'destroy']);
        Route::delete('purchases/{purchase}', [PurchaseController::class, 'destroy']);
        Route::post('sales/{sale}/cancel', [SaleController::class, 'cancel']);
    });
});

<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\CheckoutController;
use App\Http\Controllers\Api\V1\GuestResponseController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\MeInvitationController;
use App\Http\Controllers\Api\V1\PaymentCallbackController;
use App\Http\Controllers\Api\V1\RsvpController;
use App\Http\Controllers\Api\V1\VoucherController;
use App\Http\Controllers\Api\V1\WishController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('/health', fn () => response()->json(['status' => 'ok']))->name('api.health');
    Route::get('/templates', [CatalogController::class, 'templates']);
    Route::get('/packages', [CatalogController::class, 'packages']);
    Route::get('/invitations/public/{slug}', [InvitationController::class, 'publicShow'])
        ->where('slug', '[a-z0-9-]+');
    Route::post('/invitations/public/{slug}/rsvp', [RsvpController::class, 'store'])
        ->middleware('throttle:8,1')
        ->where('slug', '[a-z0-9-]+');

    Route::get('/invitations/public/{slug}/wishes', [WishController::class, 'index'])
        ->middleware('throttle:60,1')
        ->where('slug', '[a-z0-9-]+');
    Route::post('/invitations/public/{slug}/wishes', [WishController::class, 'store'])
        ->middleware('throttle:8,1')
        ->where('slug', '[a-z0-9-]+');

    Route::post('/vouchers/validate', [VoucherController::class, 'validateCode'])->middleware('throttle:20,1');
    Route::post('/checkout', [CheckoutController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/orders/{reference}', [CheckoutController::class, 'status'])
        ->middleware('throttle:30,1')
        ->where('reference', 'JK-[A-Z0-9]+');
    Route::post('/payments/billplz/callback', [PaymentCallbackController::class, 'billplz'])->middleware('throttle:60,1');
    Route::post('/payments/billplz/redirect', [PaymentCallbackController::class, 'billplzRedirect'])->middleware('throttle:60,1');

    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('/admin/auth/login', [AuthController::class, 'adminLogin'])->middleware('throttle:5,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/me/invitations', [MeInvitationController::class, 'index']);
        Route::post('/me/invitations', [MeInvitationController::class, 'store']);
        Route::put('/me/invitations/{invitation}', [MeInvitationController::class, 'update']);
        Route::delete('/me/invitations/{invitation}', [MeInvitationController::class, 'destroy']);
        Route::post('/me/claim', [MeInvitationController::class, 'claim'])->middleware('throttle:10,1');
        Route::get('/me/invitations/{invitation}/rsvps/export', [RsvpController::class, 'export']);
        Route::get('/me/invitations/{invitation}/rsvps', [RsvpController::class, 'index']);
        // Dashboard "Jawapan tetamu": RSVP + doa & ucapan for one active card, and the Excel download.
        Route::get('/me/invitations/{invitation}/responses', [GuestResponseController::class, 'index']);
        Route::get('/me/invitations/{invitation}/responses/export', [GuestResponseController::class, 'export'])->middleware('throttle:20,1');

        Route::prefix('admin')->middleware('admin')->group(function () {
            Route::get('/vouchers', [VoucherController::class, 'index']);
            Route::post('/vouchers', [VoucherController::class, 'store']);
            Route::put('/vouchers/{voucher}', [VoucherController::class, 'update']);
            Route::delete('/vouchers/{voucher}', [VoucherController::class, 'destroy']);
            Route::get('/stats', [AdminController::class, 'stats']);
            Route::get('/customers/export', [AdminController::class, 'exportCustomers']);
            Route::get('/customers', [AdminController::class, 'customers']);
            Route::get('/orders', [AdminController::class, 'orders']);
            Route::get('/orders/{order}', [AdminController::class, 'order']);
            Route::post('/orders/{order}/sync', [AdminController::class, 'syncOrder']);
            Route::get('/invitations', [AdminController::class, 'invitations']);
            Route::get('/invitations/{invitation}', [AdminController::class, 'invitation']);
        });
    });
});

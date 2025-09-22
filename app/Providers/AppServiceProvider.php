<?php

namespace App\Providers;

use App\Models\Payment;
use App\Models\PurchasePayment;
use App\Observers\PaymentObserver;
use App\Observers\PurchasePaymentObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Payment::observe(PaymentObserver::class);
        PurchasePayment::observe(PurchasePaymentObserver::class);
    }
}

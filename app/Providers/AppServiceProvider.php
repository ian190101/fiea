<?php

namespace App\Providers;

use App\Contracts\DatabaseSnapshotWriter;
use App\Contracts\ReconciliationServiceInterface;
use App\Models\AccountingReview;
use App\Models\BillingCase;
use App\Models\Invoice;
use App\Models\User;
use App\Policies\AccountingReviewPolicy;
use App\Policies\BillingCasePolicy;
use App\Policies\InvoicePolicy;
use App\Services\Backup\ConfiguredSnapshotWriter;
use App\Services\Finance\IcInvoiceCalculator;
use App\Services\Finance\InvoiceCalculatorRegistry;
use App\Services\Finance\MatInvoiceCalculator;
use App\Services\Finance\ReconciliationService;
use App\Services\MailConfiguration;
use App\Services\OperationalHeartbeat;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MailConfiguration::class);
        $this->app->resolving('mail.manager', fn () => app(MailConfiguration::class)->apply());
        $this->app->bind(DatabaseSnapshotWriter::class,
            ConfiguredSnapshotWriter::class);
        $this->app->bind(ReconciliationServiceInterface::class,
            ReconciliationService::class);
        $this->app->singleton(InvoiceCalculatorRegistry::class, fn ($app) => new InvoiceCalculatorRegistry([
            'IC' => $app->make(IcInvoiceCalculator::class),
            'MAT' => $app->make(MatInvoiceCalculator::class),
        ])
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Queue::looping(function (Looping $event): void {
            if ($event->connectionName === config('queue.default')) {
                app(OperationalHeartbeat::class)->publish('queue');
            }
        });
        Event::listen(JobProcessing::class,
            function (JobProcessing $event): void {
                app(MailConfiguration::class)->apply();
                if ($event->connectionName === config('queue.default')) {
                    app(OperationalHeartbeat::class)->publish('queue');
                }
            });
        Event::listen(ScheduledTaskStarting::class,
            fn () => app(OperationalHeartbeat::class)->publish('scheduler'));
        Event::listen(CommandFinished::class,
            function (CommandFinished $event): void {
                if ($event->command === 'schedule:run' && $event->exitCode === 0) {
                    app(OperationalHeartbeat::class)->publish('scheduler');
                }
            });
        Model::preventLazyLoading(! app()->isProduction());
        RateLimiter::for('financial-write', fn ($request) => [
            Limit::perMinute(60)->by('financial:actor:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(600)->by('financial:global'),
        ]);
        RateLimiter::for('document-build', fn ($request) => [
            Limit::perMinute(10)->by('document:actor:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(100)->by('document:global'),
        ]);
        RateLimiter::for('bulk-import', fn ($request) => [
            Limit::perMinute(3)->by('import:actor:'.($request->user()?->id ?? $request->ip())),
            Limit::perMinute(30)->by('import:global'),
        ]);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(BillingCase::class, BillingCasePolicy::class);
        Gate::policy(AccountingReview::class, AccountingReviewPolicy::class);
        Gate::define('scope.manage', fn (User $user): bool => $user->hasPermission('scope.manage'));
        Vite::prefetch(concurrency: 3);
        if ($this->app->environment('production') || env('FORCE_HTTPS', false)) {
            URL::forceScheme('https');
        }
    }
}

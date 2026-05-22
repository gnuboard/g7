<?php

namespace Modules\Sirsoft\Inquiry\Providers;

use App\Extension\BaseModuleServiceProvider;

class InquiryServiceProvider extends BaseModuleServiceProvider
{
    protected string $moduleIdentifier = 'sirsoft-inquiry';

    /**
     * Repository 인터페이스 → 구현체 매핑.
     * Task 16-19 에서 채워짐.
     */
    protected array $repositories = [
        \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryRepositoryInterface::class
            => \Modules\Sirsoft\Inquiry\Repositories\InquiryRepository::class,
        \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryQuoteRepositoryInterface::class
            => \Modules\Sirsoft\Inquiry\Repositories\InquiryQuoteRepository::class,
        \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryMessageRepositoryInterface::class
            => \Modules\Sirsoft\Inquiry\Repositories\InquiryMessageRepository::class,
        \Modules\Sirsoft\Inquiry\Repositories\Contracts\InquiryAttachmentRepositoryInterface::class
            => \Modules\Sirsoft\Inquiry\Repositories\InquiryAttachmentRepository::class,
    ];

    protected array $cacheServices = [];

    protected array $storageServices = [];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(
            $this->getProviderPath() . '/../../config/inquiry.php',
            'inquiry'
        );

        // Best-effort ecommerce integration: listen to Order::saved() if the module is present.
        if (class_exists('\Modules\Sirsoft\Ecommerce\Models\Order')) {
            \Modules\Sirsoft\Ecommerce\Models\Order::saved(function ($order) {
                $status = $order->order_status instanceof \BackedEnum
                    ? $order->order_status->value
                    : (string) ($order->order_status ?? '');
                if (in_array($status, ['payment_complete', 'confirmed'], true)) {
                    $this->app->make(\Modules\Sirsoft\Inquiry\Listeners\HandleOrderPaid::class)
                        ->handle((object) ['order' => $order]);
                }
            });
        }
    }

    public function boot(): void
    {
        parent::boot();

        \Illuminate\Support\Facades\Gate::policy(
            \Modules\Sirsoft\Inquiry\Models\Inquiry::class,
            \Modules\Sirsoft\Inquiry\Policies\InquiryPolicy::class,
        );

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\Sirsoft\Inquiry\Console\Commands\ExpireQuotesCommand::class,
            ]);
        }

        $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule) {
            $schedule->command('inquiry:expire-quotes')->daily();
        });

        // 채널 필터 등록
        $this->app->make(\Modules\Sirsoft\Inquiry\Listeners\InquiryNotificationChannelListener::class)->register();

        // 이벤트 listener 등록
        \Illuminate\Support\Facades\Event::listen(
            \Modules\Sirsoft\Inquiry\Events\InquiryStatusTransitioned::class,
            \Modules\Sirsoft\Inquiry\Listeners\DispatchInquiryStatusNotifications::class . '@handle'
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\Sirsoft\Inquiry\Events\InquiryMessagePosted::class,
            \Modules\Sirsoft\Inquiry\Listeners\DispatchInquiryMessageNotification::class . '@handle'
        );

        // 권한 시드 (런타임 보장 — DB 있을 때만 실행)
        $this->app->booted(function () {
            if (\Schema::hasTable('permissions')) {
                \App\Models\Permission::firstOrCreate(
                    ['identifier' => 'inquiry.notify'],
                    ['name' => 'Inquiry Notify']
                );
                \App\Models\Permission::firstOrCreate(
                    ['identifier' => 'inquiry.manage'],
                    ['name' => 'Inquiry Manage']
                );
            }
        });
    }
}

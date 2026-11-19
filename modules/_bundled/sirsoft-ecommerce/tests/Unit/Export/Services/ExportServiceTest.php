<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Export\Services;

use Illuminate\Support\Str;
use Modules\Sirsoft\Ecommerce\Enums\ExportStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Export;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\ExportService;
use Modules\Sirsoft\Ecommerce\Tests\ExportTestCase;

/**
 * 내보내기 서비스 단위 테스트 — 보관 기간 해석과 멈춘 작업 정리
 *
 * 요청·생성·다운로드 흐름은 컨트롤러 기능 테스트가 맡는다. 여기서는 그 흐름이 기대는 두 판정을 따로 잠근다.
 * - 보관 기간: 이커머스 환경설정값을 쓰고, 비었거나 1 미만이면 기본값(7일)으로 대신한다.
 * - 정리: 시간 한도를 넘겨 멈춘 진행중 작업은 실패(timeout)로 돌리고, 한도 안의 작업은 건드리지 않는다.
 */
class ExportServiceTest extends ExportTestCase
{
    /**
     * 보관 기간은 설정값을 쓰고, 잘못된 값이면 기본값으로 대신한다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=settings
     *
     * @effects retention_range_validated
     */
    public function test_retention_days_uses_setting_and_falls_back(): void
    {
        $service = app(ExportService::class);

        $this->setRetentionDays(12);
        $this->assertSame(12, $service->retentionDays());

        $this->setRetentionDays(0);
        $this->assertSame(ExportService::DEFAULT_RETENTION_DAYS, $service->retentionDays());
    }

    /**
     * 값의 대체 언어는 사이트 기본 언어다 — 번역 문구용 env 고정값(app.fallback_locale)이 아니다.
     *
     * 영어가 기본인 사이트에서 고른 언어(일본어 등)의 값이 비면 영어 값으로 채워야 한다. 종전에는 env 의 ko 를 써서
     * 한국어로 채웠다. 사이트 기본 언어가 내보내기 데이터 언어에 없으면 데이터 언어의 첫 언어를 쓴다.
     *
     * @return void 반환값 없음
     *
     * @scenario locale=site_default
     *
     * @effects empty_value_falls_back_to_site_default_locale
     */
    public function test_value_fallback_locale_is_site_default_locale(): void
    {
        config(['app.translatable_locales' => ['ko', 'en'], 'app.fallback_locale' => 'ko', 'app.site_locale' => 'en']);
        $fallback = new \ReflectionMethod(ExportService::class, 'fallbackLocale');

        $this->assertSame('en', $fallback->invoke(app(ExportService::class)));

        config(['app.site_locale' => 'ja']);
        $this->assertSame('ko', $fallback->invoke(app(ExportService::class)), '데이터 언어에 없는 기본 언어면 첫 데이터 언어');
    }

    /**
     * 한도를 넘겨 멈춘 진행중 작업만 실패로 돌린다.
     *
     * @return void 반환값 없음
     *
     * @scenario action=prune
     *
     * @effects expired_files_pruned
     */
    public function test_prune_fails_only_stale_processing_jobs(): void
    {
        $user = $this->createAdminUser([]);
        $make = fn (string $startedAt) => Export::query()->create([
            'uuid' => (string) Str::uuid(),
            'type' => 'orders',
            'format' => 'csv',
            'status' => ExportStatusEnum::PROCESSING->value,
            'requested_by' => $user->id,
            'options' => ['fields' => ['order.order_number']],
            'requested_at' => $startedAt,
            'started_at' => $startedAt,
        ]);
        $stale = $make(now()->subSeconds(ExportService::JOB_TIMEOUT_SECONDS + 600)->toDateTimeString());
        $running = $make(now()->subMinutes(5)->toDateTimeString());

        $result = app(ExportService::class)->prune();

        $this->assertSame(1, $result['failed']);
        $this->assertSame(ExportStatusEnum::FAILED, $stale->refresh()->status);
        $this->assertSame('timeout', $stale->error_code);
        $this->assertSame(ExportStatusEnum::PROCESSING, $running->refresh()->status);
    }

    /**
     * 「내보내기 파일 보관 기간」 설정을 바꿉니다.
     *
     * @param  int  $days  보관 기간(일)
     * @return void 반환값 없음
     */
    private function setRetentionDays(int $days): void
    {
        app(EcommerceSettingsService::class)->setSetting('basic_info.export_retention_days', $days);
    }
}

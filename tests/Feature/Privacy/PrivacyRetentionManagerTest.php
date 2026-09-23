<?php

namespace Tests\Feature\Privacy;

use App\Extension\HookManager;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\Handlers\WithdrawnUserRetentionHandler;
use App\Privacy\PrivacyRetentionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 보유기간 핸들러 수집·검증 회귀 테스트 (이슈 #636 S4)
 *
 * 확장이 잘못 등록한 핸들러 하나가 다른 도메인의 법정 파기까지 멈추면 안 되고, 반대로
 * 조용히 버려져서도 안 된다는 계약을 고정합니다.
 *
 * @scenario target=privacy_withdrawn_users, row_age=within_retention, row_age=beyond_retention
 *
 * @effects retention_handlers_are_collected_from_core_and_extensions, disabled_handler_is_reported_separately_from_zero_targets
 */
class PrivacyRetentionManagerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * B1: 코어 기본 핸들러가 등록되어 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects retention_handlers_are_collected_from_core_and_extensions
     */
    public function test_core_handler_is_registered_by_default(): void
    {
        $handlers = app(PrivacyRetentionManager::class)->handlers();

        $this->assertArrayHasKey('user-withdrawn', $handlers);
        $this->assertInstanceOf(WithdrawnUserRetentionHandler::class, $handlers['user-withdrawn']);
    }

    /**
     * B2: 확장이 필터 훅으로 자기 핸들러를 더할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects retention_handlers_are_collected_from_core_and_extensions
     */
    public function test_extension_handler_is_collected_through_the_filter_hook(): void
    {
        HookManager::addFilter(PrivacyRetentionManager::HANDLERS_FILTER, function (array $handlers) {
            $handlers[] = FakeRetentionHandler::class;

            return $handlers;
        });

        $handlers = app(PrivacyRetentionManager::class)->handlers();

        $this->assertArrayHasKey('fake-domain', $handlers);
        $this->assertArrayHasKey('user-withdrawn', $handlers, '확장 등록이 코어 기본을 밀어내지 않는다');
    }

    /**
     * B3: 계약을 구현하지 않은 클래스는 기록 후 건너뛰고, 나머지는 계속 수집된다.
     *
     * 조용히 버리면 운영자는 그 도메인이 처리되고 있다고 믿게 되므로 경고가 남는지도 본다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects retention_handlers_are_collected_from_core_and_extensions
     */
    public function test_invalid_handler_is_logged_and_skipped_without_breaking_others(): void
    {
        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->withArgs(fn (string $message) => str_contains($message, '계약을 구현하지 않음'));

        HookManager::addFilter(PrivacyRetentionManager::HANDLERS_FILTER, function (array $handlers) {
            $handlers[] = NotAHandler::class;

            return $handlers;
        });

        $handlers = app(PrivacyRetentionManager::class)->handlers();

        $this->assertArrayHasKey('user-withdrawn', $handlers);
        $this->assertCount(1, $handlers);
    }

    /**
     * B4: 존재하지 않는 클래스명도 같은 방식으로 건너뛴다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects retention_handlers_are_collected_from_core_and_extensions
     */
    public function test_missing_handler_class_is_skipped(): void
    {
        Log::shouldReceive('warning')->atLeast()->once();

        HookManager::addFilter(PrivacyRetentionManager::HANDLERS_FILTER, function (array $handlers) {
            $handlers[] = 'App\\Privacy\\Handlers\\ThisDoesNotExist';

            return $handlers;
        });

        $this->assertArrayHasKey('user-withdrawn', app(PrivacyRetentionManager::class)->handlers());
    }

    /**
     * B5: 꺼진 핸들러는 "대상 0건" 이 아니라 "꺼짐" 으로 구분 보고된다.
     *
     * 둘을 같은 0 으로 뭉개면 운영자가 켠 줄 아는 기능이 놀고 있어도 알 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects disabled_handler_is_reported_separately_from_zero_targets
     */
    public function test_disabled_handler_is_reported_as_disabled_not_zero(): void
    {
        // privacy 설정 기본값은 전부 꺼짐이다 — 켜지 않은 상태를 그대로 본다.
        $results = app(PrivacyRetentionManager::class)->process(dryRun: true);

        $this->assertSame('disabled', $results['user-withdrawn']['status']);
        $this->assertSame(0, $results['user-withdrawn']['count']);

        $preview = app(PrivacyRetentionManager::class)->preview();
        $this->assertNull($preview['user-withdrawn'], '꺼진 핸들러의 미리보기는 건수가 아니라 null 이다');
    }

    /**
     * B6: 한 핸들러가 던져도 나머지는 처리되고, 실패는 결과에 남는다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=real_run
     *
     * @effects retention_handlers_are_collected_from_core_and_extensions
     */
    public function test_failing_handler_does_not_stop_the_others(): void
    {
        Log::shouldReceive('error')->atLeast()->once();

        HookManager::addFilter(PrivacyRetentionManager::HANDLERS_FILTER, function (array $handlers) {
            $handlers[] = ThrowingRetentionHandler::class;

            return $handlers;
        });

        $results = app(PrivacyRetentionManager::class)->process(dryRun: false);

        $this->assertSame('failed', $results['throwing-domain']['status']);
        $this->assertArrayHasKey('error', $results['throwing-domain']);
        $this->assertArrayHasKey('user-withdrawn', $results, '실패한 도메인이 다른 도메인 처리를 막지 않는다');
    }

    /**
     * B7: `--handler=` 로 한 도메인만 실행할 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario toggle=off, row_age=beyond_retention, run_mode=dry_run
     *
     * @effects retention_handlers_are_collected_from_core_and_extensions
     */
    public function test_only_option_restricts_execution_to_one_handler(): void
    {
        HookManager::addFilter(PrivacyRetentionManager::HANDLERS_FILTER, function (array $handlers) {
            $handlers[] = FakeRetentionHandler::class;

            return $handlers;
        });

        $results = app(PrivacyRetentionManager::class)->process(dryRun: true, only: 'fake-domain');

        $this->assertSame(['fake-domain'], array_keys($results));
    }
}

/**
 * 시험용 핸들러 — 항상 켜져 있고 고정 건수를 돌려준다.
 */
class FakeRetentionHandler implements PrivacyRetentionHandlerInterface
{
    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'fake-domain';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return '시험 도메인';
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return 7;
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun): array
    {
        return ['count' => 7, 'mode' => 'anonymize'];
    }
}

/**
 * 시험용 핸들러 — 실행 중 예외를 던진다.
 */
class ThrowingRetentionHandler implements PrivacyRetentionHandlerInterface
{
    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'throwing-domain';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return '터지는 도메인';
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return 1;
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun): array
    {
        throw new \RuntimeException('의도된 실패');
    }
}

/**
 * 시험용 — 계약을 구현하지 않은 클래스.
 */
class NotAHandler
{
    /**
     * 계약과 무관한 메서드.
     *
     * @return string 아무 값
     */
    public function id(): string
    {
        return 'not-a-handler';
    }
}

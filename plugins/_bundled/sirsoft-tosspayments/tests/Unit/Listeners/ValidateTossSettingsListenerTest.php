<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Tosspayments\Tests\Unit\Listeners;

use App\Extension\HookListenerRegistrar;
use App\Extension\HookManager;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Tosspayments\Listeners\ValidateTossSettingsListener;
use Plugins\Sirsoft\Tosspayments\Plugin;
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

/**
 * 토스 플러그인 설정 서버측 검증.
 *
 * 배경(#454): vbank_valid_hours 는 토스 가상계좌 유효시간(1~2160시간, 최대 90일) 제약을 받는다.
 * 설정 UI 는 input[max=2160] 으로 막지만 그것은 클라이언트 힌트일 뿐이고, 관리자 설정 저장 API 를
 * 직접 호출하면 범위 밖 값(예: 9999)이 그대로 저장되어 결제창 호출 시 토스가 거부한다.
 * KG 의 ValidateCbtSettingsListener 와 동일하게 core.plugin_settings.before_save 훅에서 검증한다.
 */
class ValidateTossSettingsListenerTest extends PluginTestCase
{
    private ValidateTossSettingsListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new ValidateTossSettingsListener;
    }

    public function test_plugin_registers_settings_validation_listener(): void
    {
        $this->assertContains(ValidateTossSettingsListener::class, (new Plugin)->getHookListeners());
    }

    public function test_other_plugin_settings_are_not_validated(): void
    {
        // 타 플러그인 식별자면 early-return (예외 없음)
        $this->listener->validateBeforeSave('sirsoft-pay_kginicis', ['vbank_valid_hours' => 9999]);

        $this->addToAssertionCount(1);
    }

    /**
     * 훅 디스패치 경로를 실제로 통과시켜 예외가 저장 호출자에게 전파되는지 검증한다.
     *
     * 리스너 메서드를 직접 호출하는 테스트는 이 결함을 잡지 못한다 — Action 훅의 기본값은
     * 큐 디스패치이고, 'sync' => true 가 없으면 ValidationException 이 워커 안에서 죽어
     * PluginSettingsService::save() 가 doAction 직후 저장을 그대로 진행한다.
     *
     * phpunit.xml 은 QUEUE_CONNECTION=sync 라 큐 경로도 즉시 실행되어 결함이 가려진다.
     * Queue::fake() 로 운영(database 드라이버)과 같은 비동기 상황을 만들어야 가드가 성립한다.
     */
    public function test_validation_exception_propagates_through_the_hook_chain(): void
    {
        Queue::fake();

        HookManager::clearAction('core.plugin_settings.before_save');
        HookListenerRegistrar::clear();
        HookListenerRegistrar::register(ValidateTossSettingsListener::class, 'test');

        $this->expectException(ValidationException::class);

        HookManager::doAction(
            'core.plugin_settings.before_save',
            'sirsoft-tosspayments',
            ['vbank_valid_hours' => 9999],
        );
    }

    /**
     * @return array<string, array{int}>
     */
    public static function validHoursProvider(): array
    {
        return [
            '최소 경계 1시간' => [1],
            '기본 24시간' => [24],
            '최대 경계 2160시간(90일)' => [2160],
        ];
    }

    #[DataProvider('validHoursProvider')]
    public function test_valid_vbank_valid_hours_pass(int $hours): void
    {
        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['vbank_valid_hours' => $hours]);

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidHoursProvider(): array
    {
        return [
            '상한 초과 (토스 최대 2160)' => [9999],
            '상한 바로 위' => [2161],
            '0 시간' => [0],
            '음수' => [-1],
        ];
    }

    #[DataProvider('invalidHoursProvider')]
    public function test_out_of_range_vbank_valid_hours_are_rejected(mixed $hours): void
    {
        $this->expectException(ValidationException::class);

        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['vbank_valid_hours' => $hours]);
    }

    public function test_rejected_message_targets_the_field(): void
    {
        try {
            $this->listener->validateBeforeSave('sirsoft-tosspayments', ['vbank_valid_hours' => 9999]);
            $this->fail('범위 밖 vbank_valid_hours 가 예외 없이 통과했습니다.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('vbank_valid_hours', $e->errors());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidEscrowProvider(): array
    {
        return [
            '허용 목록 밖 값' => ['maybe'],
            '빈 문자열' => [''],
        ];
    }

    #[DataProvider('invalidEscrowProvider')]
    public function test_invalid_use_escrow_is_rejected(string $value): void
    {
        $this->expectException(ValidationException::class);

        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['use_escrow' => $value]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validEscrowProvider(): array
    {
        return [
            'off' => ['off'],
            'on' => ['on'],
            'buyer_choice' => ['buyer_choice'],
        ];
    }

    #[DataProvider('validEscrowProvider')]
    public function test_valid_use_escrow_passes(string $value): void
    {
        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['use_escrow' => $value]);

        $this->addToAssertionCount(1);
    }

    public function test_settings_without_the_validated_keys_pass(): void
    {
        // 부분 저장(다른 키만 전송)에서 미포함 키를 강제하지 않는다.
        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['is_test_mode' => true]);

        $this->addToAssertionCount(1);
    }
}

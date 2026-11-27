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
 * 배경: vbank_valid_hours 는 토스 가상계좌 유효시간(1~2160시간, 최대 90일) 제약을 받는다.
 * 설정 UI 는 input[max=2160] 으로 막지만 그것은 클라이언트 힌트일 뿐이고, 관리자 설정 저장 API 를
 * 직접 호출하면 범위 밖 값(예: 9999)이 그대로 저장되어 결제창 호출 시 토스가 거부한다.
 * KG 의 ValidateCbtSettingsListener 와 동일하게 core.plugin_settings.before_save 훅에서 검증한다.
 */
class ValidateTossSettingsListenerTest extends PluginTestCase
{
    private ValidateTossSettingsListener $listener;

    /**
     * 테스트마다 설정 검증 리스너를 새로 만든다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->listener = new ValidateTossSettingsListener;
    }

    /**
     * 플러그인의 훅 리스너 목록에 설정 검증 리스너가 들어 있다.
     *
     * @effects settings_validation_listener_registered
     *
     * @return void 반환값 없음
     */
    public function test_plugin_registers_settings_validation_listener(): void
    {
        $this->assertContains(ValidateTossSettingsListener::class, (new Plugin)->getHookListeners());
    }

    /**
     * 다른 플러그인의 설정이면 범위 밖 값이라도 검사하지 않고 넘어간다.
     *
     * @scenario settings_range=out_of_range
     *
     * @effects other_plugin_settings_not_validated
     *
     * @return void 반환값 없음
     */
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
     *
     * @scenario settings_range=out_of_range
     *
     * @effects settings_validation_error_propagates_through_hook, out_of_range_vbank_hours_rejected
     *
     * @return void 반환값 없음
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
     * 통과해야 하는 가상계좌 유효시간 경계값을 준다.
     *
     * @return array<string, array{int}> 이름별 유효시간 값
     */
    public static function validHoursProvider(): array
    {
        return [
            '최소 경계 1시간' => [1],
            '기본 24시간' => [24],
            '최대 경계 2160시간(90일)' => [2160],
        ];
    }

    /**
     * 1~2160 시간 범위의 가상계좌 유효시간은 통과한다.
     *
     * @param  int  $hours  가상계좌 유효시간(시간)
     *
     * @scenario settings_range=valid
     *
     * @effects in_range_vbank_hours_pass
     *
     * @return void 반환값 없음
     */
    #[DataProvider('validHoursProvider')]
    public function test_valid_vbank_valid_hours_pass(int $hours): void
    {
        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['vbank_valid_hours' => $hours]);

        $this->addToAssertionCount(1);
    }

    /**
     * 막혀야 하는 가상계좌 유효시간 값을 준다.
     *
     * @return array<string, array{mixed}> 이름별 범위 밖 값
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

    /**
     * 범위 밖 가상계좌 유효시간은 검증 예외로 막힌다.
     *
     * @param  mixed  $hours  범위 밖 유효시간 값
     *
     * @scenario settings_range=out_of_range
     *
     * @effects out_of_range_vbank_hours_rejected
     *
     * @return void 반환값 없음
     */
    #[DataProvider('invalidHoursProvider')]
    public function test_out_of_range_vbank_valid_hours_are_rejected(mixed $hours): void
    {
        $this->expectException(ValidationException::class);

        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['vbank_valid_hours' => $hours]);
    }

    /**
     * 범위 밖 값의 오류는 vbank_valid_hours 필드에 걸린다.
     *
     * @scenario settings_range=out_of_range
     *
     * @effects out_of_range_error_targets_vbank_hours_field
     *
     * @return void 반환값 없음
     */
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
     * 막혀야 하는 에스크로 설정 값을 준다.
     *
     * @return array<string, array{string}> 이름별 허용되지 않는 값
     */
    public static function invalidEscrowProvider(): array
    {
        return [
            '허용 목록 밖 값' => ['maybe'],
            '빈 문자열' => [''],
        ];
    }

    /**
     * 허용 목록 밖 에스크로 값은 검증 예외로 막힌다.
     *
     * @param  string  $value  허용되지 않는 에스크로 값
     *
     * @scenario settings_range=out_of_range
     *
     * @effects invalid_use_escrow_rejected
     *
     * @return void 반환값 없음
     */
    #[DataProvider('invalidEscrowProvider')]
    public function test_invalid_use_escrow_is_rejected(string $value): void
    {
        $this->expectException(ValidationException::class);

        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['use_escrow' => $value]);
    }

    /**
     * 통과해야 하는 에스크로 설정 값(off·on·buyer_choice)을 준다.
     *
     * @return array<string, array{string}> 이름별 허용 값
     */
    public static function validEscrowProvider(): array
    {
        return [
            'off' => ['off'],
            'on' => ['on'],
            'buyer_choice' => ['buyer_choice'],
        ];
    }

    /**
     * 허용된 에스크로 값은 통과한다.
     *
     * @param  string  $value  허용된 에스크로 값
     *
     * @scenario settings_range=valid
     *
     * @effects valid_use_escrow_passes
     *
     * @return void 반환값 없음
     */
    #[DataProvider('validEscrowProvider')]
    public function test_valid_use_escrow_passes(string $value): void
    {
        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['use_escrow' => $value]);

        $this->addToAssertionCount(1);
    }

    /**
     * 검증 대상 키가 없는 부분 저장은 통과한다.
     *
     * @scenario settings_range=valid
     *
     * @effects settings_without_validated_keys_pass
     *
     * @return void 반환값 없음
     */
    public function test_settings_without_the_validated_keys_pass(): void
    {
        // 부분 저장(다른 키만 전송)에서 미포함 키를 강제하지 않는다.
        $this->listener->validateBeforeSave('sirsoft-tosspayments', ['is_test_mode' => true]);

        $this->addToAssertionCount(1);
    }
}

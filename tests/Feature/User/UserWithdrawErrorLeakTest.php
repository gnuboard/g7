<?php

namespace Tests\Feature\User;

use App\Extension\HookManager;
use App\Models\User;
use App\Services\UserService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 회원 탈퇴 실패 시 SQL 원문이 사용자 응답/예외 메시지에 노출되지 않는지 검증합니다.
 *
 * 결함(㉚-2): withdrawUser() catch 블록이 __('user.withdraw_failed', ['error' => $e->getMessage()])
 * 로 예외 원문(SQLSTATE·쿼리·테이블명 등)을 사용자에게 노출했다. 원본은 Log 로만 남기고
 * 사용자 메시지는 원문 없는 고정 i18n 키로 교체한다.
 */
class UserWithdrawErrorLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 탈퇴 처리 중 QueryException 이 발생해도 그 예외 메시지에 SQL 원문이 없어야 합니다.
     *
     * @scenario case=user_withdraw_failure_masked
     *
     * @effects failure_response_omits_sql_text
     */
    public function test_withdraw_failure_message_does_not_leak_sql(): void
    {
        $user = User::factory()->create();

        // 탈퇴 트랜잭션 진입 직후(첫 훅) QueryException 을 유발한다.
        // DDL 로 유발하면 암묵 커밋이 RefreshDatabase savepoint 를 깨므로, 훅에서
        // 예외를 던져 트랜잭션이 정상 롤백되도록 한다. 예외 메시지에는 실제 SQL 원문
        // (SQLSTATE·쿼리·테이블명)을 담아, 이 원문이 사용자 메시지로 새지 않음을 검증한다.
        $sqlOriginal = "SQLSTATE[42S02]: Base table or view not found: 1146 Table 'g7.user_consents' doesn't exist "
            .'(Connection: mysql, SQL: delete from `user_consents` where `user_id` = '.$user->id.')';

        HookManager::addAction('core.user.before_withdraw', function () use ($sqlOriginal): void {
            throw new QueryException('mysql', 'delete from `user_consents`', [], new \RuntimeException($sqlOriginal));
        });

        $service = app(UserService::class);

        try {
            $service->withdrawUser($user);
            $this->fail('탈퇴가 실패해 ValidationException 이 발생해야 합니다.');
        } catch (ValidationException $e) {
            $messages = collect($e->errors())->flatten()->implode(' ');

            // 원문(SQL 상태코드·쿼리 키워드·테이블명·placeholder 잔재)이 없어야 한다.
            $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $messages);
            $this->assertStringNotContainsStringIgnoringCase('select ', $messages);
            $this->assertStringNotContainsStringIgnoringCase('delete from', $messages);
            $this->assertStringNotContainsString('user_consents', $messages);
            $this->assertStringNotContainsString(':error', $messages);
        }
    }

    /**
     * 탈퇴 실패 다국어 키가 원문 placeholder(:error) 를 담지 않아야 합니다.
     *
     * @scenario case=masked_lang_keys
     *
     * @effects masked_lang_keys_have_no_error_placeholder
     */
    public function test_withdraw_failed_lang_key_has_no_error_placeholder(): void
    {
        foreach (['ko', 'en'] as $locale) {
            $message = __('user.withdraw_failed', [], $locale);

            $this->assertStringNotContainsString(':error', $message);
            // 키가 해석되어 실제 문구가 반환되어야 한다 (키 원문 그대로가 아님).
            $this->assertNotSame('user.withdraw_failed', $message);
        }
    }
}

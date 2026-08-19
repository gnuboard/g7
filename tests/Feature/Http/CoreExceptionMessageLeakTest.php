<?php

namespace Tests\Feature\Http;

use App\Models\User;
use App\Services\UserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * 코어 서비스의 generic catch 가 예외 원문(SQLSTATE·쿼리·DB명)을 사용자 응답 메시지에
 * 노출하지 않는지 검증합니다.
 *
 * 결함(N-9): UserService 의 catch 블록이 __('...', ['error' => $e->getMessage()]) 로 예외
 * 원문을 사용자 메시지에 실었다(회원 대상 경로 포함). 원본은 Log 로만 남기고 사용자 메시지는
 * 원문 없는 고정 i18n 키로 교체한다.
 *
 * 범위 주의: Module/Plugin/Template/Settings 의 설치·활성화·제거 실패 메시지는 관리자 전용
 * 진단 정보로서 예외 원문 노출을 유지한다(exceptions.md — 관리자 면의 진단 원문 노출은 허용).
 * 따라서 그 키들은 본 테스트의 대상이 아니다.
 */
class CoreExceptionMessageLeakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 사용자 생성 실패(DB 예외) 시 예외 메시지에 SQL 원문이 없어야 합니다.
     *
     * @scenario case=user_create_failure_masked
     *
     * @effects failure_response_omits_sql_text
     */
    public function test_user_create_failure_message_does_not_leak_sql(): void
    {
        $existing = User::factory()->create();

        // 동일 이메일로 생성 시도 → unique 제약 위반(QueryException) → generic catch 진입
        $data = User::factory()->raw(['email' => $existing->email]);

        $service = app(UserService::class);

        try {
            $service->createUser($data);
            $this->fail('중복 이메일로 생성이 실패해 ValidationException 이 발생해야 합니다.');
        } catch (ValidationException $e) {
            $this->assertMessagesHaveNoSqlLeak($e->errors());
        }
    }

    /**
     * 영향받은 다국어 키가 원문 placeholder(:error) 를 담지 않고 정상 해석되어야 합니다.
     *
     * @scenario case=masked_lang_keys
     *
     * @effects masked_lang_keys_have_no_error_placeholder
     */
    public function test_affected_lang_keys_have_no_error_placeholder(): void
    {
        $keys = [
            'user.create_failed',
            'user.update_failed',
            'user.delete_failed',
        ];

        foreach (['ko', 'en'] as $locale) {
            foreach ($keys as $key) {
                $message = __($key, [], $locale);

                $this->assertStringNotContainsString(':error', $message, "{$key} ({$locale}) 에 :error placeholder 가 남아 있습니다.");
                $this->assertNotSame($key, $message, "{$key} ({$locale}) 가 해석되지 않았습니다.");
            }
        }
    }

    /**
     * 메시지 집합에 SQL 원문/키워드/placeholder 잔재가 없음을 단언합니다.
     *
     * @param  array<string, array<int, string>>  $errors  ValidationException errors 배열
     */
    private function assertMessagesHaveNoSqlLeak(array $errors): void
    {
        $messages = collect($errors)->flatten()->implode(' ');

        $this->assertStringNotContainsStringIgnoringCase('SQLSTATE', $messages);
        $this->assertStringNotContainsStringIgnoringCase('select ', $messages);
        $this->assertStringNotContainsStringIgnoringCase('insert ', $messages);
        $this->assertStringNotContainsStringIgnoringCase('update ', $messages);
        $this->assertStringNotContainsStringIgnoringCase('delete ', $messages);
        $this->assertStringNotContainsString(':error', $messages);
    }
}

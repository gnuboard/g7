<?php

namespace Tests\Unit\Scheduling;

use App\Scheduling\WebCronTokenStore;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use Tests\TestCase;

/**
 * 호출 번호(토큰) 보관처의 대조 계약 (A-5 · D20).
 *
 * 이 토큰은 **인증 없는 실행 권한**을 가진 자격증명이라, 대조 방식 자체가 계약이다.
 * 일반 문자열 비교(`===`)는 첫 불일치 자리에서 즉시 반환하므로 응답 시간이 맞은
 * 앞자리 수에 비례한다 — 앞에서부터 한 자리씩 맞춰 가는 추측이 성립한다.
 *
 * 그런데 이 축은 **행위로 재어지지 않는다**. `hash_equals` 를 `===` 로 되돌려도
 * 맞으면 참, 틀리면 거짓이라는 결과는 완전히 같고, 시간차는 단위 테스트가 재기에
 * 신뢰할 수 없는 양이다. 실제로 그래서 이 자리는 「코드는 맞는데 되돌려도 전 테스트
 * 초록」인 상태로 남아 있었다. 따라서 **소스 계약**으로 잠근다.
 *
 * @scenario caller=external, token=valid, token=invalid
 */
class WebCronTokenStoreTest extends TestCase
{
    /** 케이스 전용 토큰 파일 */
    private string $tokenPath;

    /**
     * 테스트마다 겹치지 않는 웹 크론 토큰 파일 경로를 정한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->tokenPath = storage_path('framework/testing/web-cron-token-'.bin2hex(random_bytes(4)).'.json');
    }

    /**
     * 테스트가 만든 웹 크론 토큰 파일을 지운다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if (is_file($this->tokenPath)) {
            @unlink($this->tokenPath);
        }

        parent::tearDown();
    }

    /**
     * 대조는 상수 시간 비교여야 한다.
     *
     * 판정 대상은 `matches()` 의 **본문**이다 — 호출 여부를 저장소 전체에서 세면
     * 다른 곳의 `hash_equals` 하나가 이 자리의 후퇴를 가려 준다.
     *
     * @effects web_cron_token_is_compared_in_constant_time
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_token_comparison_uses_constant_time_equality(): void
    {
        $method = new ReflectionMethod(WebCronTokenStore::class, 'matches');
        $lines = file((string) $method->getFileName(), FILE_IGNORE_NEW_LINES) ?: [];
        $body = implode("\n", array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $this->assertStringContainsString(
            'hash_equals(',
            $body,
            'WebCronTokenStore::matches() 가 상수 시간 비교를 쓰지 않는다 — 인증 없는 실행 '
            .'표면의 자격증명이라 앞자리부터 맞춰 가는 추측이 성립한다.',
        );

        $this->assertDoesNotMatchRegularExpression(
            '/\$stored\s*={2,3}\s*\$candidate|\$candidate\s*={2,3}\s*\$stored/',
            $body,
            'WebCronTokenStore::matches() 가 제시된 토큰을 일반 비교 연산자로 대조한다 — '
            .'조기 반환이 응답 시간에 앞자리 일치 수를 흘린다.',
        );
    }

    /**
     * 대조 결과 자체는 그대로다 (계약을 바꾸지 않았음을 함께 고정).
     *
     * @effects web_cron_token_matches_only_the_stored_value
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_token_matches_only_the_stored_value(): void
    {
        $store = new WebCronTokenStore($this->tokenPath);
        $token = $store->rotate();

        $this->assertTrue($store->matches($token));
        // 끝 글자가 이미 `0` 이면 같은 토큰이 되므로 다른 글자로 바꾼다
        $this->assertFalse($store->matches(substr($token, 0, -1).(str_ends_with($token, '0') ? '1' : '0')));
        $this->assertFalse($store->matches(substr($token, 0, -1)));
        $this->assertFalse($store->matches(''));
        $this->assertFalse($store->matches(null));
    }

    /**
     * 토큰이 발급되지 않았으면 무엇을 제시해도 통과하지 못한다.
     *
     * @effects web_cron_token_absent_rejects_every_candidate
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function test_absent_token_rejects_every_candidate(): void
    {
        File::delete($this->tokenPath);
        $store = new WebCronTokenStore($this->tokenPath);

        $this->assertFalse($store->matches(''));
        $this->assertFalse($store->matches(str_repeat('a', 64)));
    }
}

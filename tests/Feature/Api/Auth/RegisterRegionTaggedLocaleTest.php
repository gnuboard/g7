<?php

namespace Tests\Feature\Api\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 회원가입 — 지역 하위 태그 국가·지역 표기 언어 저장 (#148)
 *
 * 종전 정규식(`^([a-z]{2})-([A-Z]{2})`)은 `fil-PH` 를 놓치고 `zh-Hant-TW` 를 중국(CN)으로,
 * `es-419` 를 스페인(ES)으로 잘못 저장했고, `users.language` varchar(5) 가 다섯 글자보다 긴 언어를 거부해 500 이었다.
 */
class RegisterRegionTaggedLocaleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 가입 환영 메일 단언과 같은 조건으로 mail 채널을 준비 완료 상태로 둡니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->enableMailChannelReadiness();
    }

    /**
     * 가입 요청 본문을 만듭니다.
     *
     * @param  string  $email  이메일
     * @param  array<string, mixed>  $extra  덧붙일 필드
     * @return array<string, mixed> 요청 본문
     */
    private function payload(string $email, array $extra = []): array
    {
        return array_merge([
            'name' => '테스트 사용자',
            'email' => $email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'agree_terms' => true,
            'agree_privacy' => true,
        ], $extra);
    }

    /**
     * 가입 국가는 Accept-Language 첫 항목의 지역 하위 태그에서 읽는다. 지역 없는 세 글자 코드(`fil`)는 비워 둔다(D3).
     *
     * @scenario shape=region_tagged
     *
     * @effects signup_country_reads_region_subtag
     *
     * @return void 반환값 없음
     */
    public function test_register_country_reads_region_subtag(): void
    {
        $cases = [
            'fil-PH,fil;q=0.9' => 'PH',
            'zh-Hant-TW,zh;q=0.9' => 'TW',
            'es-419' => null,
            'pt-br' => 'BR',
            'fil' => null,
            'ko' => 'KR',
        ];

        $index = 0;
        foreach ($cases as $header => $expected) {
            $email = 'region'.(++$index).'@example.com';
            $this->withHeaders([
                'Accept' => 'application/json',
                'Accept-Language' => $header,
            ])->postJson('/api/auth/register', $this->payload($email))->assertStatus(201);

            $this->assertSame($expected, User::where('email', $email)->value('country'), $header);
        }
    }

    /**
     * 다섯 글자보다 긴 지역 표기 언어로 가입해도 저장된다.
     *
     * @scenario shape=region_tagged
     *
     * @effects user_language_column_holds_region_tag
     *
     * @return void 반환값 없음
     */
    public function test_register_stores_region_tagged_language(): void
    {
        config(['app.supported_locales' => ['ko', 'en', 'zh-Hant-TW']]);

        $this->withHeaders(['Accept' => 'application/json'])
            ->postJson('/api/auth/register', $this->payload('zhhanttw@example.com', ['language' => 'zh-Hant-TW']))
            ->assertStatus(201);

        $this->assertSame('zh-Hant-TW', User::where('email', 'zhhanttw@example.com')->value('language'));
    }
}

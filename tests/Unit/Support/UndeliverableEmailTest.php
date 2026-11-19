<?php

namespace Tests\Unit\Support;

use App\Support\UndeliverableEmail;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 메일을 보낼 수 없는 주소 판정 (#40 K10 · R10)
 *
 * 외부 인증 회원의 대체 주소(`…@xxx.invalid`)와 빈 값은 「이메일 없음」으로 본다.
 */
class UndeliverableEmailTest extends TestCase
{
    /**
     * 판정 사례를 돌려줍니다.
     *
     * @return array<string, array{0: string|null, 1: bool}> 이름 => [주소, 기대값]
     */
    public static function cases(): array
    {
        return [
            'null' => [null, true],
            '빈 문자열' => ['', true],
            '공백' => ['   ', true],
            '대체 주소' => ['kakao_1a2b3c4d5e6f@social.invalid', true],
            '대문자 TLD' => ['someone@SOCIAL.INVALID', true],
            '최상위 바로 invalid' => ['someone@invalid', true],
            '끝 점' => ['someone@social.invalid.', true],
            '일반 주소' => ['member@example.com', false],
            'invalid 가 앞 레이블' => ['member@invalid.example.com', false],
            '로컬 부분에 invalid' => ['user.invalid@example.com', false],
        ];
    }

    /**
     * 주소별 판정이 기대와 같다.
     *
     * @scenario email=null|empty|placeholder|normal
     *
     * @effects undeliverable_email_judged
     *
     * @param  string|null  $email  주소
     * @param  bool  $expected  기대값
     * @return void 반환값 없음
     */
    #[Test]
    #[DataProvider('cases')]
    public function 판정이_기대와_같다(?string $email, bool $expected): void
    {
        $this->assertSame($expected, UndeliverableEmail::is($email));
    }
}

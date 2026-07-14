<?php

namespace Plugins\Sirsoft\VerificationKginicis\Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Plugins\Sirsoft\VerificationKginicis\Services\InicisGateway;
use Plugins\Sirsoft\VerificationKginicis\Tests\PluginTestCase;

/**
 * InicisGateway::validateAuthUrl 의 목적지 위조 차단 검증.
 *
 * 콜백 body 의 `authRequestUrl` 은 인증 없이 외부에서 들어오는 값이며, 이 값이 그대로
 * 서버-서버 STEP3 POST 의 목적지가 된다. host 를 완전 일치로 검증하지 않으면 공격자가
 * 내부망 주소나 자신의 서버로 요청을 유도해 SSRF 및 본인인증 결과 위조가 가능하다.
 */
class InicisGatewayValidateAuthUrlTest extends PluginTestCase
{
    private InicisGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new InicisGateway;
    }

    /**
     * 이니시스 표준 도메인은 통과한다.
     *
     * @param  string  $url  검증 대상 authRequestUrl
     */
    #[Test]
    #[DataProvider('validAuthUrlProvider')]
    public function it_accepts_official_inicis_auth_urls(string $url): void
    {
        $this->assertTrue(
            $this->gateway->validateAuthUrl($url),
            "정상 이니시스 URL 이 거부됨: {$url}"
        );
    }

    /**
     * 표준 도메인으로 위장한 URL 은 모두 거부한다.
     *
     * @param  string  $url  검증 대상 authRequestUrl
     * @param  string  $reason  차단 사유 (실패 메시지용)
     */
    #[Test]
    #[DataProvider('forgedAuthUrlProvider')]
    public function it_rejects_forged_auth_urls(string $url, string $reason): void
    {
        $this->assertFalse(
            $this->gateway->validateAuthUrl($url),
            "위조 URL 이 통과함 ({$reason}): {$url}"
        );
    }

    /**
     * 정상 authRequestUrl 목록.
     *
     * @return array<string, array{string}>
     */
    public static function validAuthUrlProvider(): array
    {
        return [
            '표준 kssa 도메인' => ['https://kssa.inicis.com/auth/result'],
            '표준 fcsa 도메인' => ['https://fcsa.inicis.com/auth/result'],
            '대소문자 혼용' => ['https://KSSA.INICIS.COM/auth/result'],
        ];
    }

    /**
     * 위조 authRequestUrl 목록 — 접두사 매칭 우회 벡터 전수.
     *
     * @return array<string, array{string, string}>
     */
    public static function forgedAuthUrlProvider(): array
    {
        return [
            'userinfo 로 루프백 위장' => ['https://kssa.inicis.com@127.0.0.1/', 'userinfo(@) 뒤가 실제 목적지'],
            'userinfo 로 메타데이터 위장' => ['https://kssa.inicis.com@169.254.169.254/latest/meta-data/', '클라우드 메타데이터 탈취'],
            'userinfo 로 공격자 서버 위장' => ['https://kssa.inicis.com@attacker.example/fake', '인증결과 위조'],
            '접미사 확장 도메인' => ['https://kssa.inicis.com.attacker.example/fake', '공격자 소유 도메인'],
            '하이픈 접미사' => ['https://fcsa.inicis.com-evil.example/', '공격자 소유 도메인'],
            '내부 IP 직접 지정' => ['https://127.0.0.1/', '내부망 직접 타격'],
            '경로에만 표준 도메인' => ['https://attacker.example/https://kssa.inicis.com', '경로 위장'],
            'http 다운그레이드' => ['http://kssa.inicis.com/auth', '평문 전송'],
            'scheme 부재' => ['//kssa.inicis.com/auth', 'scheme 부재'],
            '빈 문자열' => ['', '입력 없음'],
        ];
    }
}

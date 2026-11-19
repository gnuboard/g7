<?php

namespace Plugins\Sirsoft\Sso\Tests\Feature\Saml;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Plugins\Sirsoft\Sso\Mock\MockGate;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlController;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * 모의 SAML IdP 의 서명 키가 새로 만들어지면 그 IdP 를 가리키는 SP 메타데이터 캐시를 함께 비운다.
 *
 * 모의 키는 모의 활성 최대 시간(240분)만 캐시에 살고 SP 메타데이터 캐시는 하루라, 오래 켜 둔 개발·검수 사이트에서는 키가 바뀐 뒤에도
 * 낡은 인증서로 응답 서명을 검증해 모의 SAML 로그인이 `saml_invalid` 가 됐다(F1 마감 관찰). 운영 IdP 메타데이터 캐시는 건드리지 않는다.
 *
 * @scenario-manifest plugins/_bundled/sirsoft-sso/tests/scenarios/mock-saml-key-lifetime.yaml
 *
 * @since 1.0.0
 */
class MockSamlKeyLifetimeTest extends PluginTestCase
{
    use SamlFixtures;

    /** @var int 모의 메타데이터 받기 횟수 */
    private int $metadataFetches = 0;

    /**
     * 모의 IdP 메타데이터 주소로 가는 서버 간 요청을 모의 컨트롤러로 돌리고 횟수를 셉니다.
     *
     * @return void 반환값 없음
     */
    private function fakeMetadataHttp(): void
    {
        Http::fake(function (ClientRequest $request) {
            if (! str_starts_with($request->url(), MockSamlController::entityId().'/metadata')) {
                return null;
            }
            $this->metadataFetches++;
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $response = app(MockSamlController::class)->metadata(Request::create($request->url(), 'GET', $query));

            return Http::response((string) $response->getContent(), $response->getStatusCode(), ['Content-Type' => 'application/samlmetadata+xml']);
        });
    }

    /**
     * 키가 살아 있는 동안은 캐시된 메타데이터로 로그인하고, 키 수명이 지나 새 키가 생기면 메타데이터를 다시 받아 로그인이 된다.
     *
     * @scenario key=same|regenerated, metadata=url_cached
     *
     * @effects metadata_cached_while_key_lives, metadata_refetched_on_new_key, mock_login_succeeds_after_key_expiry
     *
     * @return void 반환값 없음
     */
    public function test_new_mock_key_drops_cached_sp_metadata(): void
    {
        $this->fakeMetadataHttp();
        $idp = $this->mockSamlIdp(['config' => ['idp_metadata_xml' => '', 'idp_metadata_url' => MockSamlController::metadataUrl()]]);

        $first = $this->samlRoundTrip($idp, 'ok', ['name_id' => 'saml-life-1', 'email' => $this->uniqueEmail('corp798.test')]);
        $this->assertNotSame('', $this->redirectQuery($first)['login_handoff'] ?? '');
        $second = $this->samlRoundTrip($idp, 'ok', ['name_id' => 'saml-life-1']);
        $this->assertNotSame('', $this->redirectQuery($second)['login_handoff'] ?? '');
        $this->assertSame(1, $this->metadataFetches, '키가 그대로면 캐시된 메타데이터를 쓴다');

        // 모의 키 수명(모의 활성 최대 시간)이 지난다 — 운영자는 모의 게이트를 다시 켜 둔 상태
        $this->travel(MockGate::MAX_MINUTES + 1)->minutes();
        app(MockGate::class)->enable(30);

        $after = $this->samlRoundTrip($idp, 'ok', ['name_id' => 'saml-life-1']);
        $this->assertNull($this->redirectQuery($after)['sso_error'] ?? null, '새 키로 서명한 응답을 낡은 메타데이터 인증서로 검증하면 saml_invalid');
        $this->assertNotSame('', $this->redirectQuery($after)['login_handoff'] ?? '');
        $this->assertSame(2, $this->metadataFetches, '새 키가 생기면 메타데이터를 다시 받는다');
    }
}

<?php

namespace Plugins\Sirsoft\Sso\Tests\Unit\Saml;

use App\Support\SiteUrl;
use Illuminate\Http\Request;
use OneLogin\Saml2\Response;
use OneLogin\Saml2\ValidationError;
use PHPUnit\Framework\Attributes\DataProvider;
use Plugins\Sirsoft\Sso\Drivers\Saml\SamlDriver;
use Plugins\Sirsoft\Sso\DTO\ExternalIdentity;
use Plugins\Sirsoft\Sso\DTO\LoginIntent;
use Plugins\Sirsoft\Sso\Exceptions\SsoException;
use Plugins\Sirsoft\Sso\Mock\Saml\MockSamlResponses;
use Plugins\Sirsoft\Sso\Models\IdentityProvider;
use Plugins\Sirsoft\Sso\Services\ProtocolRegistry;
use Plugins\Sirsoft\Sso\Tests\Fixtures\Saml\SamlFixtures;
use Plugins\Sirsoft\Sso\Tests\PluginTestCase;

/**
 * SAML 응답 검증 — 모의 IdP 변종 카탈로그(부록 C-2) 전수. 행 수 = 변종 수(19 — Keycloak 실측으로 `repeat_attribute` 추가).
 *
 * 응답은 모의 IdP 생성기가 실제로 서명한 XML 이고, 드라이버의 `completeLogin()` 이 라이브러리 검증(서명, 스키마, 시각, 대상, Audience,
 * InResponseTo)을 거쳐 신원 또는 오류 코드를 낸다.
 *
 * @since 1.0.0
 */
class SamlResponseValidationTest extends PluginTestCase
{
    use SamlFixtures;

    /**
     * 변종별 기대값 — null 이면 성공(신원 단언은 별도 시험), 문자열이면 그 오류 코드.
     *
     * @return array<string, array{0: string, 1: string|null}> 변종 → 기대 오류
     */
    public static function variants(): array
    {
        return [
            'ok' => ['ok', null],
            'mfa_context' => ['mfa_context', null],
            'groups_multi' => ['groups_multi', null],
            'no_attributes' => ['no_attributes', null],
            'unsigned' => ['unsigned', 'saml_invalid'],
            'wrong_key' => ['wrong_key', 'saml_invalid'],
            'expired' => ['expired', 'saml_invalid'],
            'not_yet_valid' => ['not_yet_valid', 'saml_invalid'],
            'audience_other' => ['audience_other', 'saml_invalid'],
            'destination_other' => ['destination_other', 'saml_invalid'],
            'in_response_to_mismatch' => ['in_response_to_mismatch', 'saml_invalid'],
            'unsolicited' => ['unsolicited', 'saml_invalid'],
            'replay' => ['replay', null],
            'xsw_wrap' => ['xsw_wrap', 'saml_invalid'],
            'xsw_sibling' => ['xsw_sibling', 'saml_invalid'],
            'comment_injection' => ['comment_injection', null],
            'encrypted_assertion' => ['encrypted_assertion', null],
            'status_responder' => ['status_responder', 'saml_invalid'],
            'repeat_attribute' => ['repeat_attribute', null],
        ];
    }

    /**
     * 레지스트리의 SAML 드라이버.
     *
     * @return SamlDriver 드라이버
     */
    private function driver(): SamlDriver
    {
        return app(ProtocolRegistry::class)->get('saml');
    }

    /**
     * SP-initiated 시작 정보 (state 에 AuthnRequest ID 를 담은 모양).
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $requestId  로그인 요청(AuthnRequest) ID
     * @return LoginIntent 시작 정보
     */
    private function intent(IdentityProvider $idp, string $requestId): LoginIntent
    {
        return new LoginIntent(LoginIntent::LOGIN, 'user', '/', (int) $idp->id, $idp->key, 'state', '', '', 'bind', createdAt: time(), extra: [SamlDriver::EXTRA_REQUEST_ID => $requestId]);
    }

    /**
     * ACS 요청을 만듭니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $samlResponse  base64 응답
     * @return Request 요청
     */
    private function acsRequest(IdentityProvider $idp, string $samlResponse): Request
    {
        return Request::create(SiteUrl::absolute(SamlDriver::acsPath($idp)), 'POST', ['SAMLResponse' => $samlResponse]);
    }

    /**
     * 변종 하나를 SP-initiated 응답으로 검증합니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @param  string  $variant  변종
     * @param  array<string, mixed>  $overrides  응답 값
     * @return ExternalIdentity 신원
     */
    private function complete(IdentityProvider $idp, string $variant, array $overrides = []): ExternalIdentity
    {
        $requestId = '_req_'.bin2hex(random_bytes(10));

        return $this->driver()->completeLogin($idp, $this->acsRequest($idp, $this->samlResponse($idp, $variant, $requestId, $overrides)), $this->intent($idp, $requestId));
    }

    /**
     * 암호화 어설션을 풀 SP 키를 IdP 에 넣습니다.
     *
     * @param  IdentityProvider  $idp  대상 IdP
     * @return IdentityProvider 키를 넣은 IdP
     */
    private function withSpKey(IdentityProvider $idp): IdentityProvider
    {
        $keys = $this->cachedSpKeyPair();
        $idp->forceFill(['secrets' => ['sp_certificate' => $keys['certificate'], 'sp_private_key' => $keys['private_key']]])->save();

        return $idp->fresh();
    }

    /**
     * 모의 IdP 변종마다 드라이버가 신원 또는 오류 코드를 내는지 확인한다.
     *
     * @scenario protocol=saml, variant=appendix_c2
     *
     * @effects identity_or_error_code
     *
     * @param  string  $variant  변종
     * @param  string|null  $expectedError  기대 오류 코드 (null 이면 성공)
     * @return void 반환값 없음
     */
    #[DataProvider('variants')]
    public function test_variant_yields_identity_or_error(string $variant, ?string $expectedError): void
    {
        $idp = $this->mockSamlIdp();
        if ($variant === 'encrypted_assertion') {
            $idp = $this->withSpKey($idp);
        }

        try {
            $identity = $this->complete($idp, $variant);
        } catch (SsoException $e) {
            $this->assertSame($expectedError, $e->errorCode, $variant.' → '.$e->getMessage());

            return;
        }

        $this->assertNull($expectedError, $variant.' 은 거절돼야 한다');
        $this->assertNotSame('', $identity->subject);
    }

    /**
     * 정상 응답의 신원 칸(subject, 형식, 이메일, 이름, 그룹, 세션 참조, 다중 인증 신호)을 확인한다.
     *
     * @scenario protocol=saml, variant=ok|mfa_context|groups_multi|no_attributes
     *
     * @effects identity_fields_mapped
     *
     * @return void 반환값 없음
     */
    public function test_identity_fields_are_mapped(): void
    {
        $idp = $this->mockSamlIdp();

        $ok = $this->complete($idp, 'ok', ['name_id' => 'Emp-ABC', 'email' => 'Saml.Kim@Corp798.test', 'session_index' => '_s1']);
        $this->assertSame('Emp-ABC', $ok->subject, 'subject 는 대소문자를 그대로 둔다(utf8mb4_bin 연결 키)');
        $this->assertSame('urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', $ok->subjectFormat);
        $this->assertSame('saml.kim@corp798.test', $ok->email);
        $this->assertSame('김에스', $ok->displayName);
        $this->assertSame(['인사팀'], $ok->groups);
        $this->assertSame('_s1', $ok->sessionRef);
        $this->assertSame('Emp-ABC', $ok->nameId);
        $this->assertFalse($ok->mfa, '설정한 다중 인증 수준이 아니면 false');
        $this->assertSame('인사부', $ok->attributes['department'] ?? null);

        $this->assertTrue($this->complete($idp, 'mfa_context')->mfa);
        $this->assertSame(['인사팀', '개발팀', '전사'], $this->complete($idp, 'groups_multi')->groups);

        $bare = $this->complete($idp, 'no_attributes');
        $this->assertNull($bare->email);
        $this->assertSame([], $bare->groups);
    }

    /**
     * 같은 이름 속성을 여러 번 보내는 응답(Keycloak 기본 role_list 의 `Role`)을 받아 값을 합친다.
     * 수정 전에는 라이브러리 strict 기본이 ValidationError 41 로 거절하고 화면에 token_error 가 보였다(F1 실측).
     *
     * @scenario protocol=saml, variant=repeat_attribute
     *
     * @effects repeated_attribute_values_merged
     *
     * @return void 반환값 없음
     */
    public function test_repeated_attribute_names_are_merged(): void
    {
        $idp = $this->mockSamlIdp();

        $identity = $this->complete($idp, 'repeat_attribute', ['groups' => ['인사팀', '개발팀']]);

        $this->assertSame(['인사팀', '개발팀'], $identity->groups);
        $this->assertSame(['manage-account', 'view-profile'], $identity->attributes['Role'] ?? null);
        $this->assertSame('saml.kim@corp798.test', $identity->email);
    }

    /**
     * 검증 뒤 단계에서 라이브러리 예외가 나도 SAML 오류(`saml_invalid`, detail 에 라이브러리 코드)로 바뀐다 —
     * 모르는 예외로 흘러 OIDC 토큰 교환 오류(`token_error`)로 보이지 않게.
     *
     * @scenario protocol=saml, library_error=after_validation
     *
     * @effects library_error_maps_to_saml_invalid
     *
     * @return void 반환값 없음
     */
    public function test_library_error_after_validation_maps_to_saml_invalid(): void
    {
        $idp = $this->mockSamlIdp();
        $response = $this->createMock(Response::class);
        $response->method('getNameIdData')->willReturn(['Value' => 'Emp-ABC', 'Format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent']);
        $response->method('getAttributes')->willThrowException(
            new ValidationError('Found an Attribute element with duplicated Name', ValidationError::DUPLICATED_ATTRIBUTE_NAME_FOUND)
        );

        try {
            $this->driver()->identityFromResponse($idp, $response);
            $this->fail('라이브러리 예외가 saml_invalid 로 바뀌어야 한다');
        } catch (SsoException $e) {
            $this->assertSame('saml_invalid', $e->errorCode);
            $this->assertStringContainsString('41', $e->getMessage());
        }
    }

    /**
     * 다중 인증 수준 목록이 비어 있으면 신호를 알 수 없음(null)으로 둔다.
     *
     * @scenario protocol=saml, mfa_authn_contexts=empty
     *
     * @effects mfa_unknown
     *
     * @return void 반환값 없음
     */
    public function test_mfa_is_unknown_without_configured_contexts(): void
    {
        $idp = $this->mockSamlIdp(['config' => ['mfa_authn_contexts' => []]]);

        $this->assertNull($this->complete($idp, 'mfa_context')->mfa);
    }

    /**
     * 같은 응답을 두 번 받으면 두 번째는 재전송으로 거절한다.
     *
     * @scenario protocol=saml, variant=replay
     *
     * @effects second_rejected_response_replayed
     *
     * @return void 반환값 없음
     */
    public function test_same_response_twice_is_replay(): void
    {
        $idp = $this->mockSamlIdp();
        $requestId = '_req_replay';
        $response = $this->samlResponse($idp, 'replay', $requestId);

        $this->driver()->completeLogin($idp, $this->acsRequest($idp, $response), $this->intent($idp, $requestId));

        try {
            $this->driver()->completeLogin($idp, $this->acsRequest($idp, $response), $this->intent($idp, $requestId));
            $this->fail('같은 응답이 두 번 통과했다');
        } catch (SsoException $e) {
            $this->assertSame('response_replayed', $e->errorCode);
        }
    }

    /**
     * NameID 가운데 주석이 있어도 앞부분만 읽지 않고 서명된 값 전체를 subject 로 쓴다.
     *
     * @scenario protocol=saml, variant=comment_injection
     *
     * @effects subject_is_full_signed_value
     *
     * @return void 반환값 없음
     */
    public function test_comment_injection_reads_full_name_id(): void
    {
        $idp = $this->mockSamlIdp();

        $identity = $this->complete($idp, 'comment_injection', ['name_id' => 'victim@corp798.test.attacker.example']);

        $this->assertSame('victim@corp798.test.attacker.example', $identity->subject);
    }

    /**
     * 서명 감싸기 두 변종은 위조 어설션의 NameID 를 신원으로 내지 않는다.
     *
     * @scenario protocol=saml, variant=xsw_wrap|xsw_sibling
     *
     * @effects rejected_no_identity
     *
     * @return void 반환값 없음
     */
    public function test_signature_wrapping_is_rejected(): void
    {
        $idp = $this->mockSamlIdp();

        foreach (['xsw_wrap', 'xsw_sibling'] as $variant) {
            try {
                $this->complete($idp, $variant);
                $this->fail($variant.' 이 통과했다');
            } catch (SsoException $e) {
                $this->assertSame('saml_invalid', $e->errorCode, $variant);
            }
        }
    }

    /**
     * 어설션 암호화를 요구하면 평문 어설션을 거절하고, SP 키로 암호화된 어설션은 푼다.
     *
     * @scenario protocol=saml, want_assertions_encrypted=true, variant=ok|encrypted_assertion
     *
     * @effects plain_rejected, encrypted_decrypted
     *
     * @return void 반환값 없음
     */
    public function test_encryption_required_rejects_plain_assertion(): void
    {
        $idp = $this->withSpKey($this->mockSamlIdp(['config' => ['want_assertions_encrypted' => true]]));

        $this->assertSame('saml-emp-001', $this->complete($idp, 'encrypted_assertion')->subject);

        try {
            $this->complete($idp, 'ok');
            $this->fail('평문 어설션이 통과했다');
        } catch (SsoException $e) {
            $this->assertSame('saml_invalid', $e->errorCode);
        }
    }

    /**
     * 키 교체 직후 이전 SP 키로 암호화된 어설션은 병행 기간 안이면 이전 키로 푼다.
     *
     * @scenario protocol=saml, sp_key=rotated_within_30_days
     *
     * @effects decrypted_with_previous_key
     *
     * @return void 반환값 없음
     */
    public function test_previous_sp_key_decrypts_during_rollover(): void
    {
        $idp = $this->withSpKey($this->mockSamlIdp());
        $old = $idp->secrets;
        $encryptedForOld = $this->samlResponse($idp, 'encrypted_assertion', '_req_old');

        $new = $this->cachedSpKeyPair('sp_new');
        $idp->forceFill(['secrets' => [
            'sp_certificate' => $new['certificate'],
            'sp_private_key' => $new['private_key'],
            'sp_previous_certificate' => $old['sp_certificate'],
            'sp_previous_private_key' => $old['sp_private_key'],
            'sp_previous_until' => now()->addDays(30)->toIso8601String(),
        ]])->save();
        $idp = $idp->fresh();

        $identity = $this->driver()->completeLogin($idp, $this->acsRequest($idp, $encryptedForOld), $this->intent($idp, '_req_old'));

        $this->assertSame('saml-emp-001', $identity->subject);
    }

    /**
     * IdP 거절(Responder + RequestDenied)은 access_denied 로 구분한다.
     *
     * @scenario protocol=saml, status=Responder/RequestDenied
     *
     * @effects access_denied
     *
     * @return void 반환값 없음
     */
    public function test_request_denied_status_maps_to_access_denied(): void
    {
        $idp = $this->mockSamlIdp();

        try {
            $this->complete($idp, 'status_responder', ['sub_status' => MockSamlResponses::STATUS_REQUEST_DENIED]);
            $this->fail('거절 응답이 통과했다');
        } catch (SsoException $e) {
            $this->assertSame('access_denied', $e->errorCode);
        }
    }

    /**
     * state 에 AuthnRequest ID 가 없으면 응답을 읽지 않고 거절한다.
     *
     * @scenario protocol=saml, state=missing_request_id
     *
     * @effects rejected_before_parse
     *
     * @return void 반환값 없음
     */
    public function test_missing_request_id_in_state_is_rejected(): void
    {
        $idp = $this->mockSamlIdp();
        $intent = new LoginIntent(LoginIntent::LOGIN, 'user', '/', (int) $idp->id, $idp->key, 'state', '', '', 'bind');

        $this->expectException(SsoException::class);
        $this->driver()->completeLogin($idp, $this->acsRequest($idp, $this->samlResponse($idp, 'ok', '_x')), $intent);
    }

    /**
     * 크기 상한을 넘는 응답은 라이브러리에 넘기지 않고 거절한다.
     *
     * @scenario protocol=saml, size=over_256kb
     *
     * @effects rejected_before_parse
     *
     * @return void 반환값 없음
     */
    public function test_oversized_response_is_rejected(): void
    {
        $idp = $this->mockSamlIdp();

        try {
            $this->driver()->completeLogin($idp, $this->acsRequest($idp, str_repeat('A', 400000)), $this->intent($idp, '_x'));
            $this->fail('큰 응답이 통과했다');
        } catch (SsoException $e) {
            $this->assertSame('saml_invalid', $e->errorCode);
        }
    }

    /**
     * transient NameID 는 로그인마다 바뀌어 연결 키가 될 수 없으므로 거절한다.
     *
     * @scenario protocol=saml, name_id_format=transient, subject_source=name_id
     *
     * @effects rejected
     *
     * @return void 반환값 없음
     */
    public function test_transient_name_id_cannot_be_subject(): void
    {
        $idp = $this->mockSamlIdp();

        try {
            $this->complete($idp, 'ok', ['name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient']);
            $this->fail('transient NameID 가 통과했다');
        } catch (SsoException $e) {
            $this->assertSame('saml_invalid', $e->errorCode);
        }
    }

    /**
     * subject 출처를 속성으로 고르면 그 속성 값을 subject 로 쓴다.
     *
     * @scenario protocol=saml, subject_source=attribute
     *
     * @effects subject_from_attribute
     *
     * @return void 반환값 없음
     */
    public function test_subject_from_attribute(): void
    {
        $idp = $this->mockSamlIdp(['config' => ['subject_source' => 'attribute', 'subject_attribute' => 'email']]);

        $this->assertSame('saml.kim@corp798.test', $this->complete($idp, 'ok', ['name_id_format' => 'urn:oasis:names:tc:SAML:2.0:nameid-format:transient'])->subject);
    }
}

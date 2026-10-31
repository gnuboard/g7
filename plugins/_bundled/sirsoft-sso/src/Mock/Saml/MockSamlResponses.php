<?php

namespace Plugins\Sirsoft\Sso\Mock\Saml;

use DOMDocument;
use DOMElement;
use Illuminate\Support\Str;
use RobRichards\XMLSecLibs\XMLSecEnc;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * 모의 SAML IdP 의 응답 변종 생성기 (계획서 부록 C-2).
 *
 * 실제 IdP 처럼 어설션에 서명하고, 변종마다 한 가지를 어긋나게 만든다 — SP 드라이버가 그 어긋남을 라이브러리 검증으로 걸러 내는지
 * 시험과 실측에서 확인한다. 서명은 `xmlseclibs` 로 직접 만든다(모의 IdP 는 서명을 「만드는」 쪽이라 SP 의 「검증 직접 구현 금지」 대상이
 * 아니다).
 *
 * @since 1.0.0
 */
final class MockSamlResponses
{
    /** SAML 프로토콜 이름공간 */
    public const NS_SAMLP = 'urn:oasis:names:tc:SAML:2.0:protocol';

    /** SAML 어설션 이름공간 */
    public const NS_SAML = 'urn:oasis:names:tc:SAML:2.0:assertion';

    /** 다중 인증으로 내보내는 AuthnContextClassRef (`mfa_context` 변종) */
    public const MFA_CONTEXT = 'https://refeds.org/profile/mfa';

    /** 기본 AuthnContextClassRef */
    public const PASSWORD_CONTEXT = 'urn:oasis:names:tc:SAML:2.0:ac:classes:PasswordProtectedTransport';

    /** @var array<int, string> 응답 변종 */
    public const VARIANTS = [
        'ok', 'mfa_context', 'groups_multi', 'no_attributes', 'unsigned', 'wrong_key', 'expired', 'not_yet_valid',
        'audience_other', 'destination_other', 'in_response_to_mismatch', 'unsolicited', 'replay', 'xsw_wrap',
        'xsw_sibling', 'comment_injection', 'encrypted_assertion', 'status_responder', 'repeat_attribute',
    ];

    /** @var array<int, string> 메타데이터 변종 */
    public const METADATA_VARIANTS = ['ok', 'cert_expiring_7d', 'cert_rotated'];

    /** 사용자가 IdP 에서 거절했을 때의 하위 상태 코드 */
    public const STATUS_REQUEST_DENIED = 'urn:oasis:names:tc:SAML:2.0:status:RequestDenied';

    /** 다른 SP 로 보낸 것처럼 꾸밀 때 쓰는 주소 */
    public const OTHER_SP = 'https://other-sp.example.test/saml/metadata';

    /**
     * 응답 XML 을 만듭니다.
     *
     * @param  string  $variant  변종
     * @param  array{issuer: string, destination: string, audience: string, in_response_to?: string|null, name_id: string, name_id_format?: string, email?: string|null, name?: string|null, groups?: array<int, string>, session_index?: string, sp_certificate?: string|null, sign_response?: bool, now?: int}  $p  값
     * @param  array{certificate: string, private_key: string}  $key  서명 키
     * @param  array{certificate: string, private_key: string}|null  $otherKey  `wrong_key` 변종 서명 키
     * @return string 응답 XML
     */
    public static function response(string $variant, array $p, array $key, ?array $otherKey = null): string
    {
        $now = $p['now'] ?? time();
        $responseId = '_'.Str::random(40);
        $inResponseTo = match ($variant) {
            'unsolicited' => null,
            'in_response_to_mismatch' => '_'.Str::random(40),
            default => $p['in_response_to'] ?? null,
        };
        $destination = $variant === 'destination_other' ? self::OTHER_SP.'/acs' : $p['destination'];

        $doc = new DOMDocument('1.0', 'UTF-8');
        $response = $doc->createElementNS(self::NS_SAMLP, 'samlp:Response');
        $response->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:saml', self::NS_SAML);
        $response->setAttribute('ID', $responseId);
        $response->setAttribute('Version', '2.0');
        $response->setAttribute('IssueInstant', self::time($now));
        $response->setAttribute('Destination', $destination);
        if ($inResponseTo !== null) {
            $response->setAttribute('InResponseTo', $inResponseTo);
        }
        $doc->appendChild($response);
        $response->appendChild($doc->createElementNS(self::NS_SAML, 'saml:Issuer', $p['issuer']));

        if ($variant === 'status_responder') {
            $sub = is_string($p['sub_status'] ?? null) ? $p['sub_status'] : null;
            $response->appendChild(self::status($doc, 'urn:oasis:names:tc:SAML:2.0:status:Responder', $sub));

            return $doc->saveXML();
        }

        $response->appendChild(self::status($doc, 'urn:oasis:names:tc:SAML:2.0:status:Success'));

        $assertion = self::assertion($doc, $variant, $p, $now, $inResponseTo, $destination);
        $response->appendChild($assertion);

        if ($variant !== 'unsigned') {
            self::sign($assertion, $variant === 'wrong_key' && $otherKey !== null ? $otherKey : $key);
        }

        if (in_array($variant, ['xsw_wrap', 'xsw_sibling'], true)) {
            self::wrapSignature($doc, $response, $assertion, $variant, $p, $now, $inResponseTo, $destination);
        }

        if ($variant === 'encrypted_assertion' && is_string($p['sp_certificate'] ?? null) && $p['sp_certificate'] !== '') {
            self::encrypt($doc, $response, $assertion, $p['sp_certificate']);
        }

        if (($p['sign_response'] ?? false) === true && $variant !== 'unsigned') {
            self::sign($response, $key);
        }

        return $doc->saveXML();
    }

    /**
     * IdP 메타데이터 XML 을 만듭니다.
     *
     * @param  string  $entityId  IdP Entity ID
     * @param  string  $ssoUrl  SSO 주소
     * @param  string  $sloUrl  SLO 주소
     * @param  array<int, string>  $certificates  서명 인증서 PEM (첫째가 응답 서명 키 — 변종은 여기에 인증서를 더한다)
     * @return string XML
     */
    public static function metadata(string $entityId, string $ssoUrl, string $sloUrl, array $certificates): string
    {
        $ns = 'urn:oasis:names:tc:SAML:2.0:metadata';
        $doc = new DOMDocument('1.0', 'UTF-8');
        $entity = $doc->createElementNS($ns, 'md:EntityDescriptor');
        $entity->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:ds', XMLSecurityDSig::XMLDSIGNS);
        $entity->setAttribute('entityID', $entityId);
        $doc->appendChild($entity);

        $descriptor = $doc->createElementNS($ns, 'md:IDPSSODescriptor');
        $descriptor->setAttribute('protocolSupportEnumeration', self::NS_SAMLP);
        $descriptor->setAttribute('WantAuthnRequestsSigned', 'false');
        $entity->appendChild($descriptor);

        foreach ($certificates as $certificate) {
            $keyDescriptor = $doc->createElementNS($ns, 'md:KeyDescriptor');
            $keyDescriptor->setAttribute('use', 'signing');
            $keyInfo = $doc->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'ds:KeyInfo');
            $x509Data = $doc->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'ds:X509Data');
            $x509Data->appendChild($doc->createElementNS(XMLSecurityDSig::XMLDSIGNS, 'ds:X509Certificate', self::certBody($certificate)));
            $keyInfo->appendChild($x509Data);
            $keyDescriptor->appendChild($keyInfo);
            $descriptor->appendChild($keyDescriptor);
        }

        $slo = $doc->createElementNS($ns, 'md:SingleLogoutService');
        $slo->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect');
        $slo->setAttribute('Location', $sloUrl);
        $descriptor->appendChild($slo);

        foreach (['urn:oasis:names:tc:SAML:2.0:nameid-format:persistent', 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress'] as $format) {
            $descriptor->appendChild($doc->createElementNS($ns, 'md:NameIDFormat', $format));
        }

        foreach (['HTTP-Redirect', 'HTTP-POST'] as $binding) {
            $sso = $doc->createElementNS($ns, 'md:SingleSignOnService');
            $sso->setAttribute('Binding', 'urn:oasis:names:tc:SAML:2.0:bindings:'.$binding);
            $sso->setAttribute('Location', $ssoUrl);
            $descriptor->appendChild($sso);
        }

        return $doc->saveXML();
    }

    /**
     * IdP 발 LogoutRequest 를 Redirect 바인딩 주소(서명 포함)로 만듭니다.
     *
     * @param  string  $slsUrl  SP 의 SLS 주소
     * @param  string  $issuer  IdP Entity ID
     * @param  string  $nameId  NameID
     * @param  string|null  $sessionIndex  SessionIndex
     * @param  array{certificate: string, private_key: string}|null  $key  서명 키 (null 이면 서명하지 않음)
     * @param  string|null  $relayState  RelayState
     * @return string SLS 주소 + 쿼리
     */
    public static function logoutRequestUrl(string $slsUrl, string $issuer, string $nameId, ?string $sessionIndex, ?array $key, ?string $relayState = null): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElementNS(self::NS_SAMLP, 'samlp:LogoutRequest');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:saml', self::NS_SAML);
        $root->setAttribute('ID', '_'.Str::random(40));
        $root->setAttribute('Version', '2.0');
        $root->setAttribute('IssueInstant', self::time(time()));
        $root->setAttribute('Destination', $slsUrl);
        $root->setAttribute('NotOnOrAfter', self::time(time() + 300));
        $doc->appendChild($root);
        $root->appendChild($doc->createElementNS(self::NS_SAML, 'saml:Issuer', $issuer));
        $nameIdNode = $doc->createElementNS(self::NS_SAML, 'saml:NameID');
        $nameIdNode->appendChild($doc->createTextNode($nameId));
        $root->appendChild($nameIdNode);
        if ($sessionIndex !== null) {
            $root->appendChild($doc->createElementNS(self::NS_SAMLP, 'samlp:SessionIndex', $sessionIndex));
        }

        return self::redirectUrl($slsUrl, 'SAMLRequest', (string) $doc->saveXML($root), $key, $relayState);
    }

    /**
     * SP 의 LogoutRequest 에 답하는 LogoutResponse 주소를 만듭니다 (서명하지 않는다).
     *
     * @param  string  $slsUrl  SP 의 SLS 주소
     * @param  string  $issuer  IdP Entity ID
     * @param  string  $inResponseTo  요청 ID
     * @param  string|null  $relayState  RelayState
     * @return string SLS 주소 + 쿼리
     */
    public static function logoutResponseUrl(string $slsUrl, string $issuer, string $inResponseTo, ?string $relayState): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElementNS(self::NS_SAMLP, 'samlp:LogoutResponse');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:saml', self::NS_SAML);
        $root->setAttribute('ID', '_'.Str::random(40));
        $root->setAttribute('Version', '2.0');
        $root->setAttribute('IssueInstant', self::time(time()));
        $root->setAttribute('Destination', $slsUrl);
        $root->setAttribute('InResponseTo', $inResponseTo);
        $doc->appendChild($root);
        $root->appendChild($doc->createElementNS(self::NS_SAML, 'saml:Issuer', $issuer));
        $root->appendChild(self::status($doc, 'urn:oasis:names:tc:SAML:2.0:status:Success'));

        return self::redirectUrl($slsUrl, 'SAMLResponse', (string) $doc->saveXML($root), null, $relayState);
    }

    /**
     * 인증서 본문만 꺼냅니다.
     *
     * @param  string  $pem  인증서 PEM
     * @return string 본문
     */
    public static function certBody(string $pem): string
    {
        return preg_replace('/-----(BEGIN|END) CERTIFICATE-----|\s+/', '', $pem) ?? '';
    }

    /**
     * 어설션을 만듭니다.
     *
     * @param  DOMDocument  $doc  문서
     * @param  string  $variant  변종
     * @param  array<string, mixed>  $p  값
     * @param  int  $now  기준 시각
     * @param  string|null  $inResponseTo  요청 ID
     * @param  string  $recipient  받는 주소
     * @param  string|null  $nameIdOverride  NameID 를 바꿀 때 (위조 어설션)
     * @return DOMElement 어설션
     */
    private static function assertion(DOMDocument $doc, string $variant, array $p, int $now, ?string $inResponseTo, string $recipient, ?string $nameIdOverride = null): DOMElement
    {
        $notBefore = $variant === 'not_yet_valid' ? $now + 3600 : $now - 60;
        $notOnOrAfter = $variant === 'expired' ? $now - 3600 : $now + 300;

        $assertion = $doc->createElementNS(self::NS_SAML, 'saml:Assertion');
        $assertion->setAttribute('ID', '_'.Str::random(40));
        $assertion->setAttribute('Version', '2.0');
        $assertion->setAttribute('IssueInstant', self::time($now));
        $assertion->appendChild($doc->createElementNS(self::NS_SAML, 'saml:Issuer', (string) $p['issuer']));

        $subject = $doc->createElementNS(self::NS_SAML, 'saml:Subject');
        $nameId = $doc->createElementNS(self::NS_SAML, 'saml:NameID');
        $nameId->setAttribute('Format', (string) ($p['name_id_format'] ?? 'urn:oasis:names:tc:SAML:2.0:nameid-format:persistent'));
        $value = $nameIdOverride ?? (string) $p['name_id'];
        if ($variant === 'comment_injection' && $nameIdOverride === null) {
            // NameID 가운데 주석 — 첫 텍스트 노드만 읽는 SP 는 앞부분(다른 사람 값)으로 잘못 읽는다.
            $cut = (int) floor(strlen($value) / 2);
            $nameId->appendChild($doc->createTextNode(substr($value, 0, $cut)));
            $nameId->appendChild($doc->createComment(''));
            $nameId->appendChild($doc->createTextNode(substr($value, $cut)));
        } else {
            $nameId->appendChild($doc->createTextNode($value));
        }
        $subject->appendChild($nameId);

        $confirmation = $doc->createElementNS(self::NS_SAML, 'saml:SubjectConfirmation');
        $confirmation->setAttribute('Method', 'urn:oasis:names:tc:SAML:2.0:cm:bearer');
        $data = $doc->createElementNS(self::NS_SAML, 'saml:SubjectConfirmationData');
        $data->setAttribute('NotOnOrAfter', self::time($notOnOrAfter));
        $data->setAttribute('Recipient', $recipient);
        if ($inResponseTo !== null) {
            $data->setAttribute('InResponseTo', $inResponseTo);
        }
        $confirmation->appendChild($data);
        $subject->appendChild($confirmation);
        $assertion->appendChild($subject);

        $conditions = $doc->createElementNS(self::NS_SAML, 'saml:Conditions');
        $conditions->setAttribute('NotBefore', self::time($notBefore));
        $conditions->setAttribute('NotOnOrAfter', self::time($notOnOrAfter));
        $restriction = $doc->createElementNS(self::NS_SAML, 'saml:AudienceRestriction');
        $restriction->appendChild($doc->createElementNS(self::NS_SAML, 'saml:Audience', $variant === 'audience_other' ? self::OTHER_SP : (string) $p['audience']));
        $conditions->appendChild($restriction);
        $assertion->appendChild($conditions);

        $authn = $doc->createElementNS(self::NS_SAML, 'saml:AuthnStatement');
        $authn->setAttribute('AuthnInstant', self::time($now - 5));
        $authn->setAttribute('SessionIndex', (string) ($p['session_index'] ?? '_session_'.Str::random(20)));
        $authn->setAttribute('SessionNotOnOrAfter', self::time($now + 28800));
        $context = $doc->createElementNS(self::NS_SAML, 'saml:AuthnContext');
        $context->appendChild($doc->createElementNS(self::NS_SAML, 'saml:AuthnContextClassRef', $variant === 'mfa_context' ? self::MFA_CONTEXT : self::PASSWORD_CONTEXT));
        $authn->appendChild($context);
        $assertion->appendChild($authn);

        if ($variant !== 'no_attributes') {
            $groups = $variant === 'groups_multi' ? ['인사팀', '개발팀', '전사'] : (array) ($p['groups'] ?? []);
            $statement = $doc->createElementNS(self::NS_SAML, 'saml:AttributeStatement');
            $statement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xs', 'http://www.w3.org/2001/XMLSchema');
            $statement->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
            $attributes = array_filter([
                'email' => isset($p['email']) && $p['email'] !== '' ? [(string) $p['email']] : [],
                'displayName' => isset($p['name']) && $p['name'] !== '' ? [(string) $p['name']] : [],
                'groups' => array_values(array_map('strval', $groups)),
                'department' => ['인사부'],
            ]);
            // repeat_attribute: Keycloak 기본 role_list 처럼 같은 이름 속성을 값마다 따로 보낸다(그룹 2개 + Role 2개)
            $elements = [];
            foreach ($attributes as $name => $values) {
                $elements = array_merge($elements, $variant === 'repeat_attribute' ? array_map(fn ($v) => [$name, [$v]], $values) : [[$name, $values]]);
            }
            if ($variant === 'repeat_attribute') {
                $elements[] = ['Role', ['manage-account']];
                $elements[] = ['Role', ['view-profile']];
            }
            foreach ($elements as [$name, $values]) {
                $attribute = $doc->createElementNS(self::NS_SAML, 'saml:Attribute');
                $attribute->setAttribute('Name', $name);
                $attribute->setAttribute('NameFormat', 'urn:oasis:names:tc:SAML:2.0:attrname-format:basic');
                foreach ($values as $item) {
                    $valueNode = $doc->createElementNS(self::NS_SAML, 'saml:AttributeValue');
                    $valueNode->setAttributeNS('http://www.w3.org/2001/XMLSchema-instance', 'xsi:type', 'xs:string');
                    $valueNode->appendChild($doc->createTextNode($item));
                    $attribute->appendChild($valueNode);
                }
                $statement->appendChild($attribute);
            }
            $assertion->appendChild($statement);
        }

        return $assertion;
    }

    /**
     * 서명 감싸기(XSW) — 서명된 어설션은 그대로 두고 다른 사람 NameID 의 위조 어설션을 덧붙인다.
     *
     * `xsw_wrap` 은 위조 어설션 안(Advice)에 서명된 원본을 숨기고, `xsw_sibling` 은 위조 어설션을 원본 앞 형제로 둔다.
     *
     * @param  DOMDocument  $doc  문서
     * @param  DOMElement  $response  응답
     * @param  DOMElement  $signed  서명된 원본 어설션
     * @param  string  $variant  xsw_wrap, xsw_sibling
     * @param  array<string, mixed>  $p  값
     * @param  int  $now  기준 시각
     * @param  string|null  $inResponseTo  요청 ID
     * @param  string  $recipient  받는 주소
     * @return void 반환값 없음
     */
    private static function wrapSignature(DOMDocument $doc, DOMElement $response, DOMElement $signed, string $variant, array $p, int $now, ?string $inResponseTo, string $recipient): void
    {
        $forged = self::assertion($doc, 'ok', $p, $now, $inResponseTo, $recipient, 'victim-'.Str::random(8));

        if ($variant === 'xsw_wrap') {
            $response->removeChild($signed);
            $advice = $doc->createElementNS(self::NS_SAML, 'saml:Advice');
            $advice->appendChild($signed);
            $conditions = $forged->getElementsByTagNameNS(self::NS_SAML, 'Conditions')->item(0);
            $forged->insertBefore($advice, $conditions?->nextSibling);
            $response->appendChild($forged);

            return;
        }

        $response->insertBefore($forged, $signed);
    }

    /**
     * 어설션을 SP 인증서로 암호화합니다 (AES-128-CBC + RSA-OAEP).
     *
     * @param  DOMDocument  $doc  문서
     * @param  DOMElement  $response  응답
     * @param  DOMElement  $assertion  서명된 어설션
     * @param  string  $spCertificate  SP 인증서 PEM
     * @return void 반환값 없음
     */
    private static function encrypt(DOMDocument $doc, DOMElement $response, DOMElement $assertion, string $spCertificate): void
    {
        $pem = str_contains($spCertificate, 'BEGIN CERTIFICATE') ? $spCertificate
            : "-----BEGIN CERTIFICATE-----\n".chunk_split(self::certBody($spCertificate), 64, "\n")."-----END CERTIFICATE-----\n";

        $symmetric = new XMLSecurityKey(XMLSecurityKey::AES128_CBC);
        $symmetric->generateSessionKey();
        $transport = new XMLSecurityKey(XMLSecurityKey::RSA_OAEP_MGF1P, ['type' => 'public']);
        $transport->loadKey($pem, false, true);

        $enc = new XMLSecEnc;
        $enc->setNode($assertion);
        $enc->type = XMLSecEnc::Element;
        $enc->encryptKey($transport, $symmetric);
        $encryptedData = $enc->encryptNode($symmetric, false);

        $wrapper = $doc->createElementNS(self::NS_SAML, 'saml:EncryptedAssertion');
        $wrapper->appendChild($doc->importNode($encryptedData, true));
        $response->replaceChild($wrapper, $assertion);
    }

    /**
     * 요소에 enveloped 서명(RSA-SHA256, exc-c14n)을 넣습니다 — Issuer 바로 뒤.
     *
     * @param  DOMElement  $element  서명할 요소 (ID 속성 필수)
     * @param  array{certificate: string, private_key: string}  $key  서명 키
     * @return void 반환값 없음
     */
    private static function sign(DOMElement $element, array $key): void
    {
        $dsig = new XMLSecurityDSig;
        $dsig->setCanonicalMethod(XMLSecurityDSig::EXC_C14N);
        $dsig->addReference(
            $element,
            XMLSecurityDSig::SHA256,
            ['http://www.w3.org/2000/09/xmldsig#enveloped-signature', XMLSecurityDSig::EXC_C14N],
            ['id_name' => 'ID', 'overwrite' => false],
        );

        $privateKey = new XMLSecurityKey(XMLSecurityKey::RSA_SHA256, ['type' => 'private']);
        $privateKey->loadKey($key['private_key']);
        $dsig->sign($privateKey);
        $dsig->add509Cert($key['certificate'], true);

        $issuer = $element->getElementsByTagNameNS(self::NS_SAML, 'Issuer')->item(0);
        $dsig->insertSignature($element, $issuer?->nextSibling);
    }

    /**
     * Status 요소.
     *
     * @param  DOMDocument  $doc  문서
     * @param  string  $code  상태 코드
     * @param  string|null  $subCode  하위 상태 코드
     * @return DOMElement 요소
     */
    private static function status(DOMDocument $doc, string $code, ?string $subCode = null): DOMElement
    {
        $status = $doc->createElementNS(self::NS_SAMLP, 'samlp:Status');
        $statusCode = $doc->createElementNS(self::NS_SAMLP, 'samlp:StatusCode');
        $statusCode->setAttribute('Value', $code);
        if ($subCode !== null) {
            $sub = $doc->createElementNS(self::NS_SAMLP, 'samlp:StatusCode');
            $sub->setAttribute('Value', $subCode);
            $statusCode->appendChild($sub);
        }
        $status->appendChild($statusCode);

        return $status;
    }

    /**
     * Redirect 바인딩 주소 — deflate + base64, 키가 있으면 SigAlg·Signature.
     *
     * @param  string  $url  받는 주소
     * @param  string  $param  SAMLRequest 또는 SAMLResponse
     * @param  string  $xml  메시지
     * @param  array{certificate: string, private_key: string}|null  $key  서명 키
     * @param  string|null  $relayState  RelayState
     * @return string 주소
     */
    private static function redirectUrl(string $url, string $param, string $xml, ?array $key, ?string $relayState): string
    {
        $query = $param.'='.urlencode(base64_encode((string) gzdeflate($xml)));
        if ($relayState !== null && $relayState !== '') {
            $query .= '&RelayState='.urlencode($relayState);
        }

        if ($key !== null) {
            $query .= '&SigAlg='.urlencode(XMLSecurityKey::RSA_SHA256);
            $signature = '';
            openssl_sign($query, $signature, $key['private_key'], OPENSSL_ALGO_SHA256);
            $query .= '&Signature='.urlencode(base64_encode($signature));
        }

        return $url.(str_contains($url, '?') ? '&' : '?').$query;
    }

    /**
     * SAML 시각 문자열.
     *
     * @param  int  $timestamp  유닉스 초
     * @return string `YYYY-MM-DDTHH:MM:SSZ`
     */
    private static function time(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $timestamp);
    }
}

<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\EncryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\RemoteCallException;
use Plugins\Sirsoft\VerificationNhnkcp\Support\KcpCertCrypto;

/**
 * NHN KCP 본인확인 V2 REST API 게이트웨이 구현체.
 *
 * KCP 규격 (developer.kcp.co.kr 본인확인 V2):
 *  - 거래등록: body 는 요청 JSON 을 AES 암호화한 문자열 그 자체, 헤더에 `site_cd` 와 salt(`rv`)
 *  - 결과조회: body 는 평문 JSON `{reg_cert_key, ordr_idxx}`, 헤더에 `site_cd`
 *    응답의 `enc_cert_data` 를 응답 `rv` 로 복호화하면 PII JSON 이 나온다.
 *
 * 외부 통신/암호화 디테일이 본 클래스에 캡슐화되어, Provider/Resolver 는 인터페이스만 의존한다.
 *
 * @since 1.0.0
 */
class KcpCertClient implements KcpCertClientInterface
{
    /** 테스트 환경 호스트 (KCP 공용 테스트) */
    private const TEST_HOST = 'https://testcert.kcp.co.kr';

    /** 라이브 환경 호스트 */
    private const LIVE_HOST = 'https://cert.kcp.co.kr';

    /** 거래등록 엔드포인트 경로 */
    private const REGISTER_PATH = '/api/reg/certDataReg.do';

    /** 결과조회 엔드포인트 경로 */
    private const QUERY_PATH = '/api/query/getCertData.do';

    /** 연결 타임아웃 (초) */
    private const CONNECT_TIMEOUT = 10;

    /** 응답 타임아웃 (초) */
    private const TIMEOUT = 30;

    /** KCP 정상 응답 코드 */
    public const RES_CD_SUCCESS = '0000';

    /**
     * @param  string  $siteCd  가맹점 사이트코드
     * @param  string  $encKey  가맹점 암호화 키
     * @param  bool  $isTestMode  테스트 모드 여부
     */
    public function __construct(
        protected readonly string $siteCd = '',
        protected readonly string $encKey = '',
        protected readonly bool $isTestMode = true,
    ) {}

    /**
     * 자격증명/모드를 주입한 새 인스턴스를 반환한다 (불변 복제 패턴).
     *
     * @param  string  $siteCd  가맹점 사이트코드 (라이브는 SM 프리픽스 포함된 최종값)
     * @param  string  $encKey  가맹점 암호화 키
     * @param  bool  $isTestMode  테스트 모드 여부
     * @return static 자격증명이 주입된 새 인스턴스
     */
    public function withCredentials(string $siteCd, string $encKey, bool $isTestMode): static
    {
        return new static($siteCd, $encKey, $isTestMode);
    }

    /**
     * 거래등록 — 표준창 호출에 필요한 `call_url` 과 `reg_cert_key` 를 발급받는다.
     *
     * @param  string  $ordrIdxx  가맹점 주문번호 (유니크, 50자 이내)
     * @param  string  $retUrl  표준창 인증 종료 후 KCP 가 form POST 할 콜백 URL (256자 이내)
     * @param  string  $webSiteid  웹사이트인증 계약 시 사용하는 사이트 ID (미계약 시 빈 문자열)
     * @param  array<int, string>  $paramOpts  가맹점 자유 파라미터 param_opt_1~3
     * @return array{res_cd: string, res_msg: string, call_url: string, reg_cert_key: string} KCP 응답
     *
     * @throws EncryptException 요청 암호화 실패
     * @throws RemoteCallException 통신/HTTP/JSON 오류
     */
    public function registerTrade(string $ordrIdxx, string $retUrl, string $webSiteid = '', array $paramOpts = []): array
    {
        $this->assertFieldLengths($ordrIdxx, $retUrl, $webSiteid);

        $payload = [
            'site_cd' => $this->siteCd,
            'ordr_idxx' => $ordrIdxx,
            'Ret_URL' => $retUrl,
            'web_siteid' => $webSiteid,
            'param_opt_1' => (string) ($paramOpts[0] ?? ''),
            'param_opt_2' => (string) ($paramOpts[1] ?? ''),
            'param_opt_3' => (string) ($paramOpts[2] ?? ''),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RemoteCallException('register_trade_json_encode_failed');
        }

        $encrypted = KcpCertCrypto::encrypt($json, $this->encKey, $this->siteCd);

        $body = $this->post(
            $this->host().self::REGISTER_PATH,
            $encrypted['enc_data'],
            ['site_cd' => $this->siteCd, 'rv' => $encrypted['rv']],
        );

        return [
            'res_cd' => (string) ($body['res_cd'] ?? ''),
            'res_msg' => (string) ($body['res_msg'] ?? ''),
            'call_url' => (string) ($body['call_url'] ?? ''),
            'reg_cert_key' => (string) ($body['reg_cert_key'] ?? ''),
        ];
    }

    /**
     * 결과조회 — 인증 결과를 조회하고 복호화한 PII 를 반환한다.
     *
     * `ordr_idxx` 는 모바일(페이지 전환) 흐름에서 필수이므로 PC/모바일 구분 없이 항상 전송한다.
     *
     * @param  string  $regCertKey  거래등록 응답의 거래키
     * @param  string  $ordrIdxx  거래등록에 사용한 가맹점 주문번호
     * @return array<string, mixed> `res_cd`/`res_msg` + 성공 시 복호화 필드
     *
     * @throws RemoteCallException 통신/HTTP/JSON 오류
     * @throws DecryptException 응답 복호화 실패
     */
    public function fetchCertData(string $regCertKey, string $ordrIdxx): array
    {
        $json = json_encode(
            ['reg_cert_key' => $regCertKey, 'ordr_idxx' => $ordrIdxx],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if ($json === false) {
            throw new RemoteCallException('fetch_cert_data_json_encode_failed');
        }

        $body = $this->post($this->host().self::QUERY_PATH, $json, ['site_cd' => $this->siteCd]);

        $resCd = (string) ($body['res_cd'] ?? '');
        $resMsg = (string) ($body['res_msg'] ?? '');

        if ($resCd !== self::RES_CD_SUCCESS) {
            return ['res_cd' => $resCd, 'res_msg' => $resMsg];
        }

        $decrypted = $this->decryptCertData(
            (string) ($body['enc_cert_data'] ?? ''),
            (string) ($body['rv'] ?? ''),
        );

        return array_merge(['res_cd' => $resCd, 'res_msg' => $resMsg], $decrypted);
    }

    /**
     * 거래등록 필드가 KCP 규격 길이를 넘지 않는지 확인한다.
     *
     * 초과 상태로 보내면 KCP 가 거절하는데, 그 응답은 어느 필드가 문제인지 알려주지 않아
     * 운영자가 원인을 짚기 어렵다. 보내기 전에 필드 이름과 함께 실패시킨다(값은 로그에 남기지
     * 않는다 — Ret_URL 을 제외한 나머지는 가맹점 식별 정보다).
     *
     * @param  string  $ordrIdxx  가맹점 주문번호
     * @param  string  $retUrl  콜백 URL
     * @param  string  $webSiteid  웹사이트 ID
     *
     * @throws RemoteCallException 규격 길이 초과 시
     */
    protected function assertFieldLengths(string $ordrIdxx, string $retUrl, string $webSiteid): void
    {
        $limits = [
            'ordr_idxx' => [$ordrIdxx, 50],
            'Ret_URL' => [$retUrl, 256],
            'web_siteid' => [$webSiteid, 12],
        ];

        foreach ($limits as $field => [$value, $max]) {
            if (mb_strlen($value) <= $max) {
                continue;
            }

            Log::warning('KCP 거래등록: 규격 길이 초과', [
                'field' => $field,
                'length' => mb_strlen($value),
                'max' => $max,
            ]);

            throw new RemoteCallException('register_trade_field_too_long:'.$field);
        }
    }

    /**
     * 결과조회 응답의 암호문을 복호화하여 표준 키 배열로 정규화한다.
     *
     * KCP 응답의 CI/DI 키는 대문자(`CI`/`DI`) 가 표준이나, 규격 변형에 대비해 소문자도 수용한다.
     *
     * @param  string  $encCertData  base64 암호문
     * @param  string  $rv  base64 salt
     * @return array<string, string> 정규화된 PII 배열
     *
     * @throws DecryptException 복호화 또는 JSON 파싱 실패
     */
    protected function decryptCertData(string $encCertData, string $rv): array
    {
        if ($encCertData === '' || $rv === '') {
            throw new DecryptException('enc_cert_data');
        }

        $plain = KcpCertCrypto::decrypt($encCertData, $rv, $this->encKey, $this->siteCd);
        $decoded = json_decode($plain, true);

        if (! is_array($decoded)) {
            throw new DecryptException('enc_cert_data');
        }

        $this->warnOnUnmappedKeys($decoded);

        return [
            'user_name' => trim((string) ($decoded['user_name'] ?? '')),
            'birth_day' => trim((string) ($decoded['birth_day'] ?? '')),
            'phone_no' => trim((string) ($decoded['phone_no'] ?? '')),
            'comm_id' => trim((string) ($decoded['comm_id'] ?? '')),
            'sex_code' => trim((string) ($decoded['sex_code'] ?? '')),
            'local_code' => trim((string) ($decoded['local_code'] ?? '')),
            'ci' => $this->readIdentifier($decoded, 'CI'),
            'di' => $this->readIdentifier($decoded, 'DI'),
        ];
    }

    /**
     * 식별값(CI/DI)을 대소문자·URL 인코딩 변형까지 수용해 읽는다.
     *
     * KCP 규격은 값 자체(`CI`/`DI`) 외에 urlencode 된 사본(`CI_URL`/`DI_URL`)을 함께 내려준다.
     * 표준 키가 비어 있는 응답 변형에서도 식별값을 잃지 않도록 다음 순서로 찾는다:
     * 대문자 → 소문자 → `*_URL`(디코드) → `*_url`(디코드).
     *
     * 식별값이 비면 중복 가입 차단이 조용히 무력화되므로, 읽을 수 있는 경로는 모두 시도한다.
     *
     * @param  array<string, mixed>  $decoded  복호화된 원본 배열
     * @param  string  $upper  대문자 키 이름 ('CI' 또는 'DI')
     * @return string 식별값 (전부 비어 있으면 빈 문자열)
     */
    protected function readIdentifier(array $decoded, string $upper): string
    {
        $lower = strtolower($upper);

        foreach ([$upper, $lower] as $key) {
            $value = trim((string) ($decoded[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        foreach ([$upper.'_URL', $lower.'_url'] as $key) {
            $raw = trim((string) ($decoded[$key] ?? ''));
            if ($raw === '') {
                continue;
            }

            $decodedValue = trim(urldecode($raw));
            if ($decodedValue !== '') {
                Log::warning('KCP 결과조회: 식별값을 URL 인코딩 키에서 회수', ['key' => $key]);

                return $decodedValue;
            }
        }

        return '';
    }

    /**
     * 복호화 결과에 우리가 읽는 이름의 키가 없을 때 원본 키 목록을 경고로 남긴다.
     *
     * 신원 핵심값이 비어 인증이 거절되면 운영자는 "KCP 가 안 준 것"인지 "이름이 달라 우리가 못
     * 읽은 것"인지 구분해야 한다. 값은 전부 PII 이므로 **키 이름만** 기록한다.
     *
     * @param  array<string, mixed>  $decoded  복호화된 원본 배열
     */
    protected function warnOnUnmappedKeys(array $decoded): void
    {
        $expected = ['user_name', 'birth_day', 'phone_no', 'comm_id', 'sex_code', 'local_code'];
        $missing = array_values(array_filter(
            $expected,
            static fn (string $key): bool => ! array_key_exists($key, $decoded),
        ));

        // CI/DI 는 대소문자·_URL 변형을 허용하므로 단일 키 존재 여부로 판정할 수 없다.
        // 어느 변형으로도 읽히지 않을 때만 누락으로 본다 (중복 가입 차단의 유일 판정 키라 관측 필수).
        foreach (['CI', 'DI'] as $identifier) {
            if ($this->readIdentifier($decoded, $identifier) === '') {
                $missing[] = $identifier;
            }
        }

        if ($missing === []) {
            return;
        }

        Log::warning('KCP 결과조회: 규격과 다른 응답 키 구성', [
            'expected_but_absent' => $missing,
            'response_keys' => array_keys($decoded),
        ]);
    }

    /**
     * 현재 모드의 KCP 호스트를 반환한다.
     *
     * @return string 호스트 URL
     */
    protected function host(): string
    {
        return $this->isTestMode ? self::TEST_HOST : self::LIVE_HOST;
    }

    /**
     * KCP 엔드포인트로 POST 요청을 보내고 JSON 응답을 배열로 반환한다.
     *
     * @param  string  $url  요청 URL
     * @param  string  $body  요청 본문 (암호문 문자열 또는 평문 JSON)
     * @param  array<string, string>  $headers  추가 헤더 (site_cd / rv)
     * @return array<string, mixed> 응답 JSON
     *
     * @throws RemoteCallException 통신/HTTP/JSON 오류
     */
    protected function post(string $url, string $body, array $headers): array
    {
        try {
            $response = Http::withHeaders(array_merge($headers, ['Accept' => 'application/json']))
                ->withBody($body, 'application/json')
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->post($url);
        } catch (\Throwable $e) {
            throw new RemoteCallException($e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            throw new RemoteCallException('HTTP '.$response->status(), $response->status());
        }

        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw new RemoteCallException('response_json_parse_failed', $response->status());
        }

        return $decoded;
    }
}

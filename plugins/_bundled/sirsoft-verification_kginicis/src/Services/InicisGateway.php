<?php

namespace Plugins\Sirsoft\VerificationKginicis\Services;

use Illuminate\Support\Str;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\InvalidAuthUrlException;
use Plugins\Sirsoft\VerificationKginicis\Exceptions\RemoteCallException;

/**
 * KG이니시스 STEP3 (`authRequestUrl`) 서버-서버 호출 + KISA SEED CBC 복호화 게이트웨이.
 *
 * 코어/Service/Listener 가 인터페이스 (`InicisGatewayInterface`) 만 의존하도록 ServiceProvider 에서
 * 본 구현체로 binding 된다. 외부 통신/암호화 디테일이 본 클래스에 캡슐화된다.
 *
 * @since 1.0.0-beta.1
 */
class InicisGateway implements InicisGatewayInterface
{
    /** SEED CBC 의 IV (이니시스 표준 — 코드 내부 고정값) */
    private const SEED_IV = 'SASKGINICIS00000';

    /** STEP3 호출 timeout (초) */
    private const STEP3_TIMEOUT = 10;

    /** 이니시스 표준 도메인 화이트리스트 (위조 차단) */
    private const ALLOWED_DOMAINS = [
        'https://kssa.inicis.com',
        'https://fcsa.inicis.com',
    ];

    /** STEP3 응답 중 SEED CBC 로 암호화된 PII 필드 키 */
    private const ENCRYPTED_FIELDS = [
        'userName',
        'userPhone',
        'userBirthday',
        'userCi',
        'userCi2',
        'userDi',
    ];

    /**
     * 이니시스 콜백의 authRequestUrl 이 표준 도메인인지 검증한다.
     *
     * @param  string  $url  콜백으로 수신한 authRequestUrl
     * @return bool 표준 도메인 여부
     */
    public function validateAuthUrl(string $url): bool
    {
        foreach (self::ALLOWED_DOMAINS as $allowed) {
            if (str_starts_with($url, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * STEP3 (인증결과 확인 API) 호출 + SEED CBC 복호화.
     *
     * @param  string  $authRequestUrl  STEP2 callback 의 authRequestUrl
     * @param  string  $txId  STEP2 callback 의 txId
     * @param  string  $token  STEP2 callback 의 token (SEED 키)
     * @return array<string, mixed> 응답 필드 14개 (PII 평문 복호화 상태)
     *
     * @throws InvalidAuthUrlException 위조 도메인
     * @throws RemoteCallException 통신/HTTP 오류
     * @throws DecryptException SEED 복호화 실패
     */
    public function verifyResult(string $authRequestUrl, string $txId, string $token): array
    {
        if (! $this->validateAuthUrl($authRequestUrl)) {
            throw new InvalidAuthUrlException($authRequestUrl);
        }

        // 가맹점 MID 는 challenge 시작 시 metadata 에 저장된 값을 호출자가 컨텍스트로 전달하지 않는다 —
        // 이니시스 표준은 STEP3 요청 body 에 mid + txId 두 필드만 보낸다 (KISA 샘플 success.php 참조).
        // mid 는 callback 직전 단계에서 provider 가 알고 있어야 하므로 호출자 (Provider) 가 직접 처리하는 게 맞다.
        // → verifyResult 시그니처에 mid 인자 추가가 더 정확하나, plan 단순화 위해 `txId` 만 전달받고
        //    `mid` 는 caller 가 사전에 setSettings 로 주입하도록 한다 (withConfig 패턴).
        $response = $this->postJson($authRequestUrl, ['txId' => $txId]);

        $payload = json_decode($response['body'], true);

        if (! is_array($payload)) {
            throw new RemoteCallException(__('sirsoft-verification_kginicis::exceptions.remote_call_failed'), $response['status']);
        }

        if (! empty($token)) {
            foreach (self::ENCRYPTED_FIELDS as $field) {
                if (! empty($payload[$field])) {
                    try {
                        $payload[$field] = decrypt_SEED($payload[$field], $token, self::SEED_IV);
                    } catch (\Throwable $e) {
                        throw new DecryptException($field, $e);
                    }
                }
            }
        }

        return $payload;
    }

    /**
     * 18자 가맹점 거래 ID (mTxId) 를 생성한다.
     *
     * @return string 18자 영숫자 문자열
     */
    public function generateMTxId(): string
    {
        // 18자 — 이니시스 매뉴얼 표준 길이. UUID v4 기반 영숫자 (대소문자 + 숫자) 18자 절단.
        return substr(Str::random(18), 0, 18);
    }

    /**
     * 이니시스 STEP3 endpoint 로 JSON POST 요청을 보낸다.
     *
     * curl SSL_VERIFYPEER=true 강제 (KISA 샘플은 false 라 위조 방어 불가 — 본 구현은 강제 true).
     *
     * @param  string  $url  POST 대상 URL (표준 도메인 검증된 authRequestUrl)
     * @param  array<string, mixed>  $body  JSON body
     * @return array{status: int, body: string} HTTP status + 응답 body
     *
     * @throws RemoteCallException
     */
    protected function postJson(string $url, array $body): array
    {
        $ch = curl_init();

        try {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::STEP3_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::STEP3_TIMEOUT);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/json',
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

            $responseBody = curl_exec($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);

            if ($curlErrno !== 0 || $responseBody === false) {
                throw new RemoteCallException(curl_error($ch), $httpStatus);
            }

            if ($httpStatus >= 400) {
                throw new RemoteCallException("HTTP {$httpStatus}", $httpStatus);
            }

            return [
                'status' => $httpStatus,
                'body' => (string) $responseBody,
            ];
        } finally {
            curl_close($ch);
        }
    }
}

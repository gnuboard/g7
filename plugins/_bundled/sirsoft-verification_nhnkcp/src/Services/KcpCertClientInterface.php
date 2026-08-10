<?php

namespace Plugins\Sirsoft\VerificationNhnkcp\Services;

use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\DecryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\EncryptException;
use Plugins\Sirsoft\VerificationNhnkcp\Exceptions\RemoteCallException;

/**
 * NHN KCP 본인확인 V2 REST API 게이트웨이 계약.
 *
 * 두 개의 서버-서버 호출을 캡슐화한다:
 *  ① 거래등록 `POST {host}/api/reg/certDataReg.do` — 표준창 호출 URL + 거래키 발급
 *  ② 결과조회 `POST {host}/api/query/getCertData.do` — 인증 결과(PII) 회수 + 복호화
 *
 * 모드(테스트/라이브)에 따른 호스트·자격증명은 구현체가 `withCredentials()` 로 주입받는다.
 *
 * @since 1.0.0
 */
interface KcpCertClientInterface
{
    /**
     * 자격증명/모드를 주입한 새 인스턴스를 반환한다 (불변 복제 패턴).
     *
     * @param  string  $siteCd  가맹점 사이트코드 (라이브는 SM 프리픽스 포함된 최종값)
     * @param  string  $encKey  가맹점 암호화 키
     * @param  bool  $isTestMode  테스트 모드 여부 (호스트 결정)
     * @return static 자격증명이 주입된 새 인스턴스
     */
    public function withCredentials(string $siteCd, string $encKey, bool $isTestMode): static;

    /**
     * 거래등록 — 표준창 호출에 필요한 `call_url` 과 `reg_cert_key` 를 발급받는다.
     *
     * @param  string  $ordrIdxx  가맹점 주문번호 (유니크, 50자 이내)
     * @param  string  $retUrl  표준창 인증 종료 후 KCP 가 form POST 할 콜백 URL (256자 이내)
     * @param  string  $webSiteid  웹사이트인증 계약 시 사용하는 사이트 ID (미계약 시 빈 문자열)
     * @param  array<int, string>  $paramOpts  가맹점 자유 파라미터 param_opt_1~3 (각 500자 이내)
     * @return array{res_cd: string, res_msg: string, call_url: string, reg_cert_key: string} KCP 응답
     *
     * @throws EncryptException 요청 암호화 실패
     * @throws RemoteCallException 통신/HTTP/JSON 오류
     */
    public function registerTrade(string $ordrIdxx, string $retUrl, string $webSiteid = '', array $paramOpts = []): array;

    /**
     * 결과조회 — 인증 결과를 조회하고 복호화한 PII 를 반환한다.
     *
     * @param  string  $regCertKey  거래등록 응답의 거래키
     * @param  string  $ordrIdxx  거래등록에 사용한 가맹점 주문번호
     * @return array<string, mixed> `res_cd`/`res_msg` + 성공 시 복호화 필드
     *                              (user_name, birth_day, phone_no, comm_id, sex_code, local_code, ci, di)
     *
     * @throws RemoteCallException 통신/HTTP/JSON 오류
     * @throws DecryptException 응답 복호화 실패
     */
    public function fetchCertData(string $regCertKey, string $ordrIdxx): array;
}

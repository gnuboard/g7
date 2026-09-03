<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 알림톡 작성 모달 참조 조회 서비스 (#597).
 *
 * 알림톡 템플릿 작성 모달이 소비하는 카테고리·발신프로필 kapi 조회를 위임한다.
 * 템플릿 목록/상세의 실시간 조회(구 Phase 5)는 DB 기반 라이프사이클
 * (BizppurioTemplateService)로 대체되어 제거됐다 — 발송 전 실시간 조회는 없다.
 */
class AlimtalkTemplateService
{
    /**
     * @param  BizppurioKakaoApiClient  $kakao  카카오 관리 API 클라이언트
     */
    public function __construct(
        private readonly BizppurioKakaoApiClient $kakao,
    ) {}

    /**
     * 템플릿 등록에 사용할 카테고리 목록 전체를 조회합니다.
     *
     * @return array<int, array<string, mixed>> [{code, name, groupName}]
     *
     * @throws BizppurioApiException 자격증명 미설정·조회 실패 시
     */
    public function categories(): array
    {
        $response = $this->kakao->request('/v3/kakao/template/category/all');

        $this->assertSuccess($response);

        return array_values((array) ($response['data'] ?? []));
    }

    /**
     * 발신프로필(사용중) 목록을 조회합니다.
     *
     * 규격(5.발신프로필관리): `/v3/kakao/profile/use` 응답의 data 는
     * `{success: [...프로필], fail: [...조회실패]}` 2단 봉투다. 실제 발신프로필 목록은
     * data.success 배열에 담기므로 그 배열을 반환한다(data 통째 반환 시 success/fail
     * 껍데기가 소비처에 그대로 노출됨).
     *
     * @return array<int, array<string, mixed>> 발신프로필 목록(data.success)
     *
     * @throws BizppurioApiException 자격증명 미설정·조회 실패 시
     */
    public function senderProfiles(): array
    {
        $response = $this->kakao->getSenderProfiles();

        $this->assertSuccess($response);

        return array_values((array) ($response['data']['success'] ?? []));
    }

    /**
     * kapi 응답이 성공(200)이 아니면 message 를 담아 예외를 던집니다.
     *
     * @param  array<string, mixed>  $response  kapi 응답
     *
     * @throws BizppurioApiException 실패 코드 시
     */
    private function assertSuccess(array $response): void
    {
        if ($this->kakao->isSuccess($response)) {
            return;
        }

        $message = (string) ($response['message'] ?? '');
        $code = (string) ($response['code'] ?? '');

        throw new BizppurioApiException(
            $message !== ''
                ? $message
                : __('sirsoft-message_bizppurio::messages.error.kakao_request_failed'),
            resultCode: $code !== '' ? $code : null,
        );
    }
}

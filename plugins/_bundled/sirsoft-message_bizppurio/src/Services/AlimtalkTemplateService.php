<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Services\PluginSettingsService;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;

/**
 * 알림톡 템플릿 조회 서비스 (Phase 5).
 *
 * 카카오 관리 API(kapi.ppurio.com)를 BizppurioKakaoApiClient 로 위임하여 알림톡 템플릿을
 * 실시간 조회한다(목록·상세·카테고리·발신프로필). 등록·수정·삭제·검수·상태변경은 비즈뿌리오
 * 콘솔로 위임하며, 이 화면은 목록·상태·내용 조회 + 알림 연결만 담당한다. 템플릿은 DB 에
 * 저장하지 않고 매 요청 실시간으로 조회한다(계획서 §6-3).
 *
 * 이 서비스는 serviceStatus(REG/REQ/REJ/RDY/ACT/DMT/STP/BLK)를 상태 배지로 매핑하는
 * 도메인 로직을 담당한다(RDY/ACT 만 알림 연결 가능 상태).
 *
 * 발신프로필 키(senderKey)는 환경설정(sender_key)에서 가져오며, 미설정 시 조회 자체가
 * 불가능하므로 화면은 readiness 로 사전 안내한다(§6-3).
 */
class AlimtalkTemplateService
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 목록 조회 기본 페이지 크기 */
    private const DEFAULT_COUNT = 20;

    /** kapi 결과코드: 요청한 데이터가 없음(검색 결과 0건 포함, 13.응답코드정의.md) */
    private const NOT_FOUND_CODE = '508';

    /**
     * serviceStatus → 상태 배지 매핑.
     *
     * key = kapi serviceStatus, value = ['label_key' => lang key, 'variant' => 배지 색].
     * variant 는 프론트가 배지 색상 클래스로 사용한다(green/yellow/red/gray/dark/purple).
     *
     * @var array<string, array{label_key: string, variant: string}>
     */
    private const STATUS_BADGES = [
        'RDY' => ['label_key' => 'sendable', 'variant' => 'green'],
        'ACT' => ['label_key' => 'sendable', 'variant' => 'green'],
        'REQ' => ['label_key' => 'inspecting', 'variant' => 'yellow'],
        'REJ' => ['label_key' => 'rejected', 'variant' => 'red'],
        'REG' => ['label_key' => 'uninspected', 'variant' => 'gray'],
        'STP' => ['label_key' => 'stopped', 'variant' => 'dark'],
        'BLK' => ['label_key' => 'blocked', 'variant' => 'dark'],
        'DMT' => ['label_key' => 'dormant', 'variant' => 'purple'],
    ];

    /**
     * @param  BizppurioKakaoApiClient  $kakao  카카오 관리 API 클라이언트
     * @param  PluginSettingsService  $pluginSettings  환경설정 조회(sender_key)
     */
    public function __construct(
        private readonly BizppurioKakaoApiClient $kakao,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 알림톡 템플릿 목록을 실시간 조회합니다.
     *
     * @param  array<string, mixed>  $filters  status(templateStatus)·keyword·page·count
     * @return array{templates: array<int, array<string, mixed>>, pagination: array<string, int>}
     *
     * @throws BizppurioApiException 자격증명 미설정·조회 실패 시(결과코드 508 제외)
     */
    public function list(array $filters = []): array
    {
        $params = [
            'count' => (int) ($filters['count'] ?? self::DEFAULT_COUNT),
            'page' => max(1, (int) ($filters['page'] ?? 1)),
        ];

        if (! empty($filters['status'])) {
            $params['templateStatus'] = (string) $filters['status'];
        }

        if (! empty($filters['keyword'])) {
            $params['keyword'] = (string) $filters['keyword'];
        }

        $response = $this->kakao->getTemplateList($this->senderKey(), $params);

        try {
            $this->assertSuccess($response);
        } catch (BizppurioApiException $e) {
            // 508 = "요청한 데이터가 없음"(검색 결과 0건 포함). 카카오는 이 경우를 목록
            // 조회 실패로 응답하지만, 실제로는 정상적인 빈 결과이므로 예외로 취급하지 않는다.
            if ($e->getResultCode() === self::NOT_FOUND_CODE) {
                return [
                    'templates' => [],
                    'pagination' => [
                        'total' => 0,
                        'total_page' => 1,
                        'current_page' => $params['page'],
                        'per_page' => $params['count'],
                    ],
                ];
            }

            throw $e;
        }

        $rows = (array) ($response['data']['list'] ?? $response['data'] ?? []);

        return [
            'templates' => array_map(fn (array $row) => $this->decorate($row), $rows),
            'pagination' => [
                'total' => (int) ($response['totalCount'] ?? count($rows)),
                'total_page' => (int) ($response['totalPage'] ?? 1),
                'current_page' => (int) ($response['currentPage'] ?? $params['page']),
                'per_page' => $params['count'],
            ],
        ];
    }

    /**
     * 알림톡 템플릿 상세를 실시간 조회합니다.
     *
     * @param  string  $templateCode  템플릿 코드
     * @return array<string, mixed> 배지·가능 액션이 부가된 템플릿 상세
     *
     * @throws BizppurioApiException 자격증명 미설정·조회 실패 시
     */
    public function detail(string $templateCode): array
    {
        $response = $this->kakao->getTemplateDetail($this->senderKey(), $templateCode);

        $this->assertSuccess($response);

        return $this->decorate((array) ($response['data'] ?? []));
    }

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
     * 템플릿 행에 상태 배지를 부가합니다.
     *
     * serviceStatus(목록) 또는 inspectionStatus/status(상세)에서 배지 기준 상태를 도출한다.
     * RDY/ACT(승인) 상태만 알림 연결 가능하며, 프론트가 배지로 이를 안내한다.
     *
     * @param  array<string, mixed>  $row  kapi 템플릿 행
     * @return array<string, mixed> 배지가 부가된 행
     */
    private function decorate(array $row): array
    {
        $status = (string) ($row['serviceStatus'] ?? $this->deriveStatus($row));
        $badge = self::STATUS_BADGES[$status] ?? ['label_key' => 'unknown', 'variant' => 'gray'];

        $row['service_status'] = $status;
        $row['status_badge'] = [
            // 프론트 $t() 가 해석하는 프론트 lang 키 형식(templates.status.*)으로 준다.
            // 프론트(en/ko.json)에는 이 키만 존재하며, 백엔드 messages.php 네임스페이스
            // (::messages.template.status.*)는 프론트에 없어 원문이 그대로 노출된다.
            'label_key' => 'sirsoft-message_bizppurio.templates.status.'.$badge['label_key'],
            'variant' => $badge['variant'],
        ];

        return $row;
    }

    /**
     * 상세 응답에서 serviceStatus 가 없을 때 status/inspectionStatus 로 상태를 추정합니다.
     *
     * 상세 조회는 serviceStatus 대신 status(S/A/R)+inspectionStatus(REG/REQ/REJ/APR)를
     * 내려주므로, 목록과 동일한 배지 체계로 환원한다.
     *
     * @param  array<string, mixed>  $row  kapi 템플릿 상세 행
     * @return string serviceStatus 코드
     */
    private function deriveStatus(array $row): string
    {
        $inspection = (string) ($row['inspectionStatus'] ?? '');
        $status = (string) ($row['status'] ?? '');
        $block = (bool) ($row['block'] ?? false);
        $dormant = (bool) ($row['dormant'] ?? false);

        return match (true) {
            $block => 'BLK',
            $dormant => 'DMT',
            $inspection === 'REQ' => 'REQ',
            $inspection === 'REJ' => 'REJ',
            $inspection === 'APR' && $status === 'S' => 'STP',
            $inspection === 'APR' && $status === 'A' => 'ACT',
            $inspection === 'APR' => 'RDY',
            default => 'REG',
        };
    }

    /**
     * 환경설정에서 발신프로필 키(sender_key)를 조회합니다.
     *
     * @return string 발신프로필 키
     *
     * @throws BizppurioApiException 미설정 시
     */
    private function senderKey(): string
    {
        $settings = $this->pluginSettings->get(self::PLUGIN_IDENTIFIER) ?? [];
        $senderKey = (string) ($settings['sender_key'] ?? '');

        if ($senderKey === '') {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.sender_key_missing'),
            );
        }

        return $senderKey;
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

<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioNotificationBinding;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioNotificationBindingRepositoryInterface;

/**
 * 이벤트↔알림톡 템플릿 연동(binding) 서비스 (계획서 §6-2, Phase 6 재설계 A).
 *
 * 알림 설정 알림톡 탭은 코어 기본 목록을 그대로 사용한다(⚑⚑ 결정 A). 연결 템플릿·SMS 대체는
 * 코어 목록 행 하단에 얹은 [연결/변경] 버튼 → 우리 연결 모달에서 입력하고, 우리 API
 * (POST notification-bindings)로 이 서비스의 bind/unbind 를 직접 호출해 저장된다
 * (코어 저장 버튼과 무관).
 *
 * 이 서비스는 (1) 연결 모달이 소비하는 조회 — 승인 템플릿 드롭다운(approvedTemplates)
 * 과 현재 연동 맵(all) — 및 (2) 연결 모달 저장이 호출하는 bind/unbind 를 제공한다.
 *
 * 연결 대상 템플릿 드롭다운은 "발송 가능(승인) 상태" 템플릿만 노출한다 — 미승인 템플릿에
 * 연동해도 발송이 거부되기 때문. 승인 판정은 AlimtalkTemplateService 의 배지 매핑(RDY/ACT
 * = 발송가능)을 재사용한다.
 */
class NotificationBindingService
{
    /** 연동 대상 채널 (1차 알림톡 고정) */
    private const CHANNEL = 'alimtalk';

    /** 발송 가능(연결 허용) 카카오 템플릿 상태 — RDY(발송전)·ACT(정상) */
    private const SENDABLE_STATUSES = ['RDY', 'ACT'];

    /**
     * @param  BizppurioNotificationBindingRepositoryInterface  $bindings  연동 조회/저장
     * @param  AlimtalkTemplateService  $templates  카카오 승인 템플릿 조회
     * @param  KakaoTemplateContentResolver  $kakaoContent  발송용 템플릿 내용 캐시(수동 초기화 위임)
     */
    public function __construct(
        private readonly BizppurioNotificationBindingRepositoryInterface $bindings,
        private readonly AlimtalkTemplateService $templates,
        private readonly KakaoTemplateContentResolver $kakaoContent,
    ) {}

    /**
     * 알림톡 채널의 모든 연동을 notification_type → 연동 정보 맵으로 반환합니다.
     *
     * 코어 편집 모달 전용 칸이 편집 중인 알림의 기존 연동(연결 템플릿·SMS 대체)을 프리필하는
     * 데 쓴다. 코어 목록은 코어가 렌더하므로(⚑⚑ 결정 A) 알림 정의와 조인하지 않고 binding
     * 만 내려준다. 편집 모달은 def.type 으로 이 맵을 조회한다.
     *
     * $withAvailability=true 이면(알림톡 탭 진입 표시용) 카카오 승인 목록을 1회 조회해 각 연동에
     * is_unavailable(연결한 카카오 템플릿이 삭제·차단·미승인되어 발송 불가) 플래그를 부여한다.
     * 저장 응답(store)처럼 프리필만 필요한 경로는 기본값(false)으로 호출해 카카오 조회를 생략한다.
     *
     * @param  bool  $withAvailability  카카오 승인 목록과 대조해 소실 여부를 부여할지 (표시용)
     * @return array<string, array<string, mixed>> notification_type 키의 연동 맵
     */
    public function all(bool $withAvailability = false): array
    {
        // 소실 판정 대상 = 발송 가능한(승인) 카카오 템플릿 코드 집합. 조회 실패(카카오 장애·자격증명
        // 미설정)면 null 을 반환해 판정 자체를 건너뛴다 — 살아 있는 연동이 일시 장애로 "사용 불가"로
        // 오탐되어 화면이 전부 경고로 물드는 것을 막는다(발송 시점 판정과 동일한 안전측 기준).
        $sendableCodes = $withAvailability ? $this->sendableTemplateCodesOrNull() : null;

        return $this->bindings->allByChannel(self::CHANNEL)
            ->keyBy('notification_type')
            ->map(function (BizppurioNotificationBinding $binding) use ($sendableCodes) {
                $info = [
                    'notification_type' => $binding->notification_type,
                    'template_code' => $binding->template_code,
                    'template_name' => $binding->template_name,
                    'fallback_sms_enabled' => (bool) $binding->fallback_sms_enabled,
                ];

                // 승인 목록 조회에 성공했을 때만 소실 여부를 부여한다(null=판정 생략).
                if ($sendableCodes !== null) {
                    $info['is_unavailable'] = ! in_array($binding->template_code, $sendableCodes, true);
                }

                return $info;
            })
            ->all();
    }

    /**
     * 발송 가능한(승인) 카카오 템플릿 코드 집합을 반환하되, 조회 실패 시 null 을 반환합니다.
     *
     * approvedTemplates() 는 자격증명 미설정·카카오 장애 시 BizppurioApiException 을 던진다. 소실
     * 판정은 "표시 부가정보"이므로 실패를 삼키고 null 을 반환해, 호출부가 판정을 건너뛰게 한다.
     *
     * @return array<int, string>|null 승인 template_code 목록(성공) 또는 null(조회 실패)
     */
    private function sendableTemplateCodesOrNull(): ?array
    {
        try {
            return array_column($this->approvedTemplates(), 'template_code');
        } catch (BizppurioApiException) {
            return null;
        }
    }

    /**
     * 연결 가능한(발송 가능/승인) 알림톡 템플릿 목록을 반환합니다 (연동 모달 드롭다운).
     *
     * 카카오 템플릿 목록 중 serviceStatus 가 RDY/ACT 인 항목만 노출한다. 미승인 템플릿에
     * 연동해도 발송이 거부되므로 애초에 선택지에서 제외한다.
     *
     * @return array<int, array{template_code: string, template_name: string}>
     *
     * @throws BizppurioApiException 자격증명 미설정·조회 실패 시
     */
    public function approvedTemplates(): array
    {
        // 승인 상태 필터는 kapi 에 status 파라미터로 위임하지 않고(상태 2종 조회 불가), 전체를
        // 받아 serviceStatus 로 거른다. 페이지네이션 대신 넉넉한 count 로 1회 조회한다.
        $result = $this->templates->list(['count' => 100]);

        return collect($result['templates'] ?? [])
            ->filter(fn (array $row) => in_array((string) ($row['serviceStatus'] ?? $row['status'] ?? ''), self::SENDABLE_STATUSES, true))
            ->map(fn (array $row) => [
                'template_code' => (string) ($row['templateCode'] ?? $row['code'] ?? ''),
                'template_name' => (string) ($row['templateName'] ?? $row['name'] ?? ''),
            ])
            ->filter(fn (array $row) => $row['template_code'] !== '')
            ->values()
            ->all();
    }

    /**
     * 알림에 알림톡 템플릿을 연결(생성/갱신)합니다 (연동 모달 저장).
     *
     * 저장 전 카카오 승인 상태(RDY/ACT)를 재검증한다 — 드롭다운이 승인 템플릿만 보여주지만
     * 그 필터는 화면 단계일 뿐이라, API 를 직접 호출하면 미승인 템플릿도 저장될 수 있었다(회귀).
     * 카카오 조회 자체가 실패(장애·자격증명 미설정)하면 승인 여부를 판정할 수 없으므로 안전측으로
     * 저장을 거부한다 — 조회 실패를 "승인됨"으로 잘못 해석해 미승인 템플릿이 새는 것을 막는다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  array<string, mixed>  $data  template_code / template_name / fallback_sms_enabled
     * @return BizppurioNotificationBinding 저장된 연동
     *
     * @throws BizppurioApiException 카카오 조회 실패, 또는 미승인·존재하지 않는 템플릿 코드
     */
    public function bind(string $notificationType, array $data): BizppurioNotificationBinding
    {
        $templateCode = (string) $data['template_code'];

        $this->assertSendable($templateCode);

        return $this->bindings->upsert($notificationType, self::CHANNEL, [
            'template_code' => $templateCode,
            'template_name' => (string) $data['template_name'],
            'fallback_sms_enabled' => (bool) ($data['fallback_sms_enabled'] ?? false),
            'is_active' => true,
        ]);
    }

    /**
     * 템플릿 코드가 발송 가능(승인) 상태인지 검증합니다. 아니면 예외를 던집니다.
     *
     * @param  string  $templateCode  검증할 카카오 템플릿 코드
     *
     * @throws BizppurioApiException 카카오 조회 실패, 또는 미승인·존재하지 않는 템플릿 코드
     */
    private function assertSendable(string $templateCode): void
    {
        $sendableCodes = array_column($this->approvedTemplates(), 'template_code');

        if (! in_array($templateCode, $sendableCodes, true)) {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.template_not_sendable', ['code' => $templateCode]),
            );
        }
    }

    /**
     * 알림의 알림톡 연동을 해제(삭제)합니다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     */
    public function unbind(string $notificationType): void
    {
        $this->bindings->delete($notificationType, self::CHANNEL);
    }

    /**
     * 연결된 모든 알림톡 템플릿의 발송용 내용 캐시를 초기화합니다 (관리자 수동 갱신).
     *
     * 카카오에서 템플릿 내용을 방금 바꿔 캐시 만료(기본 1시간)를 기다리지 않고 즉시 반영하고
     * 싶을 때 사용한다. 연결(binding)된 template_code 를 모아 각 캐시를 비우면 다음 발송에서
     * 최신 내용으로 재조회된다.
     *
     * @return int 초기화한 캐시 키 수(연결된 고유 template_code 수)
     */
    public function clearTemplateContentCache(): int
    {
        $codes = $this->bindings->allByChannel(self::CHANNEL)
            ->pluck('template_code')
            ->all();

        return $this->kakaoContent->clearMany($codes);
    }

    /**
     * 연결 모달이 넘긴 값으로 연동을 반영합니다.
     *
     * 우리 연결 저장 API(NotificationBindingController::store → POST notification-bindings)에서
     * 호출된다. 연결 템플릿 코드가 비어 있으면 연동 해제, 있으면 생성/갱신한다. 이 "빈 값=해제"
     * 규칙 덕분에 연결 모달에서 드롭다운을 "연결 안 함"으로 바꾸고 저장하면 저장 한 번으로
     * 해제까지 처리된다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  string|null  $templateCode  연결할 카카오 템플릿 코드 (빈 값=해제)
     * @param  string|null  $templateName  템플릿 이름 스냅샷 (고아 감지용)
     * @param  bool  $fallbackSmsEnabled  실패 시 SMS 대체발송 여부
     *
     * @throws BizppurioApiException 카카오 조회 실패, 또는 미승인·존재하지 않는 템플릿 코드 (해제 시에는 미발생)
     */
    public function applyFromTemplateSave(
        string $notificationType,
        ?string $templateCode,
        ?string $templateName,
        bool $fallbackSmsEnabled,
    ): void {
        $code = trim((string) $templateCode);

        if ($code === '') {
            $this->unbind($notificationType);

            return;
        }

        $this->bind($notificationType, [
            'template_code' => $code,
            'template_name' => trim((string) $templateName),
            'fallback_sms_enabled' => $fallbackSmsEnabled,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Services;

use App\Services\PluginSettingsService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\MessageBizppurio\Enums\BizppurioTemplateStatus;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioApiException;
use Plugins\Sirsoft\MessageBizppurio\Exceptions\BizppurioTemplateStateException;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioTemplate;
use Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts\BizppurioTemplateRepositoryInterface;

/**
 * 비즈뿌리오 알림 템플릿 라이프사이클 서비스 (#597).
 *
 * 알림톡 템플릿의 시스템 등록 → 검수 신청 → 승인 후 활성화 흐름을 오케스트레이션한다.
 *
 *  - 등록/수정/삭제: DB 행 관리 + 상태 전이 가드(수정·삭제는 draft/rejected 만).
 *  - 검수 신청: template_code 자체 채번(codeCheck 검증) → kapi add(최초)/update(재신청)
 *    → kapi request → status=requested.
 *  - 검수/승인 취소·휴면 해제: kapi 상태변경 API 위임 후 우리 상태 반영.
 *  - 상태 동기화: kapi 조회 결과의 serviceStatus 를 BizppurioTemplateStatus 로 환원해
 *    반영한다. 승인 전이 시 content 를 approved_content 로 동결(발송 SSoT)하고, 반려 시
 *    detail 의 comments 를 inspection_detail 로 저장한다.
 *
 * 발송 이전 실시간 조회는 없다 — 발송 드라이버는 이 서비스가 유지하는 DB 행만 판정한다.
 */
class BizppurioTemplateService
{
    /** 플러그인 식별자 (manifest 와 일치) */
    private const PLUGIN_IDENTIFIER = 'sirsoft-message_bizppurio';

    /** 자체 채번 template_code 접두사 */
    private const CODE_PREFIX = 'g7';

    /** codeCheck 충돌 시 세대 증가 재시도 최대 횟수 */
    private const CODE_CHECK_MAX_ATTEMPTS = 3;

    /** 동기화 배치의 kapi 목록 페이지 크기 */
    private const SYNC_LIST_COUNT = 500;

    /** 동기화 배치의 kapi 목록 최대 페이지 수 (무한 루프 방지) */
    private const SYNC_LIST_MAX_PAGES = 10;

    /**
     * @param  BizppurioTemplateRepositoryInterface  $templates  템플릿 행 저장소
     * @param  BizppurioKakaoApiClient  $kakao  카카오 관리 API 클라이언트
     * @param  PluginSettingsService  $pluginSettings  환경설정 조회(sender_key)
     */
    public function __construct(
        private readonly BizppurioTemplateRepositoryInterface $templates,
        private readonly BizppurioKakaoApiClient $kakao,
        private readonly PluginSettingsService $pluginSettings,
    ) {}

    /**
     * 관리 화면 목록을 페이지네이션 조회합니다.
     *
     * @param  array<string, mixed>  $filters  status / search
     * @param  int  $page  페이지 번호
     * @param  int  $perPage  페이지 크기
     * @return LengthAwarePaginator 페이지네이션 결과
     */
    public function list(array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        return $this->templates->paginateWithDefinitions($filters, $page, $perPage);
    }

    /**
     * 알림 설정 탭 행 표시용 요약 맵을 반환합니다 (notification_type 키).
     *
     * @return array<string, array<string, mixed>> notification_type → 요약
     */
    public function summaryMap(): array
    {
        return $this->templates->allSummaries()
            ->keyBy('notification_type')
            ->map(fn (BizppurioTemplate $template): array => [
                'id' => $template->id,
                'notification_type' => $template->notification_type,
                'alimtalk_enabled' => $template->alimtalk_enabled,
                'template_code' => $template->template_code,
                'status' => $template->status->value,
                'has_content' => (bool) $template->getAttribute('has_content'),
                'requested_at' => $template->requested_at?->toIso8601String(),
                'approved_at' => $template->approved_at?->toIso8601String(),
                'last_synced_at' => $template->last_synced_at?->toIso8601String(),
                'inspection_detail' => $template->inspection_detail,
                'fallback_sms_enabled' => $template->fallback_sms_enabled,
                'sms_body' => $template->sms_body,
                // 본문 유무·미리보기는 모델이 판정한다 — 화면이 로케일 맵을 직접 훑으면 발송 게이트
                // (hasSmsBody: 공백 제거 후 비교, 폴백은 config('app.fallback_locale'))와 규칙이 갈려
                // "화면엔 본문 있음 / 실제로는 미발송" 이 된다. 판정 한 벌만 둔다.
                'has_sms_body' => $template->hasSmsBody(),
                'sms_body_preview' => $template->getLocalizedSmsBody(),
                'sms_only' => $template->sms_only,
                'is_active' => $template->is_active,
            ])
            ->all();
    }

    /**
     * PK 로 템플릿을 조회합니다.
     *
     * @param  int  $id  템플릿 PK
     * @return BizppurioTemplate|null 매칭 행 또는 null
     */
    public function find(int $id): ?BizppurioTemplate
    {
        return $this->templates->find($id);
    }

    /**
     * 템플릿 행을 생성합니다 (status=draft).
     *
     * @param  array<string, mixed>  $data  FormRequest 검증 데이터
     * @return BizppurioTemplate 생성된 행
     */
    public function create(array $data): BizppurioTemplate
    {
        $data['status'] = BizppurioTemplateStatus::Draft->value;

        return $this->templates->create($data);
    }

    /**
     * 발송 설정(알림톡 사용·대체 SMS·SMS 본문·SMS 단독·활성)을 upsert 합니다 (#597 §4.1).
     *
     * 알림 설정 탭 행의 토글이 즉시 저장하는 경로다. 대상 행이 없으면 draft 로 생성한다.
     * 알림톡 content 는 이 경로로 변경되지 않으므로 상태 가드가 필요 없다.
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  array<string, mixed>  $data  발송 설정 필드 (FormRequest 검증 완료)
     * @return BizppurioTemplate 저장된 행
     */
    public function upsertDelivery(string $notificationType, array $data): BizppurioTemplate
    {
        $template = $this->templates->findByType($notificationType);

        if ($template === null) {
            return $this->templates->create(array_merge($data, [
                'notification_type' => $notificationType,
                'status' => BizppurioTemplateStatus::Draft->value,
            ]));
        }

        return $this->templates->update($template, $data);
    }

    /**
     * 템플릿 행을 갱신합니다.
     *
     * 카카오 등록 내용(content)은 draft/rejected 상태에서만 실을 수 있다 —
     * requested 는 검수취소 먼저, approved 는 승인취소 먼저(§3.2). SMS·활성 플래그
     * (alimtalk_enabled/fallback_sms_enabled/sms_body/sms_only/is_active)는 라이프사이클과
     * 무관한 발송 설정이므로 상태와 무관하게 수정할 수 있다.
     *
     * 판정은 content 키의 **존재 여부**만 본다. 값의 동일 여부는 보지 않는다 —
     * `content` 는 MySQL json 컬럼이고 DB 가 객체 키를 (길이, 사전순)으로 정규화해
     * 저장하므로, 화면이 보낸 순서와 DB 에서 읽은 순서가 구조적으로 일치할 수 없다.
     * 동일성 비교는 항상 "변경됨" 으로 떨어져 완화가 성립하지 않았고, 화면도 검수중·승인
     * 상태에서는 수정 진입 자체를 막고 있어(3면 row_footer·관리화면 모두 draft‖rejected
     * 게이트) 그 완화가 보호하는 경로가 애초에 없었다. 발송 설정만 바꾸는 경로는
     * content 키를 싣지 않으므로 이 가드에 걸리지 않는다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @param  array<string, mixed>  $data  FormRequest 검증 데이터
     * @return BizppurioTemplate 갱신된 행
     *
     * @throws BizppurioTemplateStateException content 수정 불가 상태일 때
     */
    public function update(BizppurioTemplate $template, array $data): BizppurioTemplate
    {
        if (array_key_exists('content', $data) && ! $template->status->allowsContentEdit()) {
            throw new BizppurioTemplateStateException(
                'sirsoft-message_bizppurio::messages.template.content_locked',
                ['status' => $template->status->value],
            );
        }

        return $this->templates->update($template, $data);
    }

    /**
     * 검수를 신청합니다: 채번 → kapi add/update → kapi request → status=requested.
     *
     * - content 미작성이면 신청 불가.
     * - draft/rejected 에서만 신청 가능(requested 는 이미 검수중, approved 는 승인취소 먼저).
     * - template_code 가 없으면(최초) 자체 채번 후 kapi add, 있으면(재신청) kapi update.
     *   add 성공 시점에만 template_code 를 행에 확정하므로, add 자체가 실패하면 다음
     *   시도에서 다시 채번부터 진행된다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return BizppurioTemplate 갱신된 행 (status=requested)
     *
     * @throws BizppurioTemplateStateException 신청 불가 상태·content 미작성
     * @throws BizppurioApiException kapi 실패 (사유 원문 보존)
     */
    public function requestInspection(BizppurioTemplate $template): BizppurioTemplate
    {
        if (! is_array($template->content) || $template->content === []) {
            throw new BizppurioTemplateStateException(
                'sirsoft-message_bizppurio::messages.template.content_missing',
            );
        }

        if (! $template->status->allowsContentEdit()) {
            throw new BizppurioTemplateStateException(
                'sirsoft-message_bizppurio::messages.template.request_not_allowed',
                ['status' => $template->status->value],
            );
        }

        // 원자 선점: 같은 draft/rejected 행을 동시에 든 요청(더블 클릭·이중 탭·복수 관리자)
        // 중 하나만 통과한다. 화면의 disabled 가드는 리렌더 전 창(~수십 ms)을 못 막으므로
        // 중복 kapi 제출 차단의 SSoT 는 이 선점이다 (§6.3 10c).
        $originalStatus = $template->status;

        if (! $this->templates->claimForInspection($template)) {
            throw new BizppurioTemplateStateException(
                'sirsoft-message_bizppurio::messages.template.request_not_allowed',
                ['status' => BizppurioTemplateStatus::Requested->value],
            );
        }

        try {
            $senderKey = $this->senderKey();
            $payload = $template->content;
            $payload['senderKey'] = $senderKey;

            if ($template->template_code === null) {
                $code = $this->generateTemplateCode($senderKey, $template->notification_type);
                $payload['templateCode'] = $code;

                $this->assertSuccess($this->kakao->addTemplate($payload));

                $template = $this->templates->update($template, [
                    'template_code' => $code,
                    'sender_key' => $senderKey,
                ]);
            } else {
                $payload['templateCode'] = $template->template_code;

                $this->assertSuccess($this->kakao->updateTemplate($payload));
            }

            $this->assertSuccess($this->kakao->requestInspection($senderKey, (string) $template->template_code));
        } catch (\Throwable $e) {
            // 선점 해제: kapi 미완료 상태로 requested 가 남지 않도록 원래 상태로 복귀.
            $this->templates->update($template, ['status' => $originalStatus->value]);

            throw $e;
        }

        return $this->templates->update($template, [
            'status' => BizppurioTemplateStatus::Requested->value,
            'sender_key' => $senderKey,
            'requested_at' => now(),
            'last_synced_at' => now(),
            'inspection_detail' => null,
        ]);
    }

    /**
     * 검수 신청을 취소합니다 (kapi cancel_request, REQ→REG).
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return BizppurioTemplate 갱신된 행 (status=draft)
     *
     * @throws BizppurioTemplateStateException 검수중이 아닐 때
     * @throws BizppurioApiException kapi 실패
     */
    public function cancelRequest(BizppurioTemplate $template): BizppurioTemplate
    {
        if ($template->status !== BizppurioTemplateStatus::Requested) {
            throw new BizppurioTemplateStateException(
                'sirsoft-message_bizppurio::messages.template.cancel_request_not_allowed',
                ['status' => $template->status->value],
            );
        }

        $this->assertSuccess($this->kakao->cancelInspection(
            $this->rowSenderKey($template),
            (string) $template->template_code,
        ));

        return $this->templates->update($template, [
            'status' => BizppurioTemplateStatus::Draft->value,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * 승인을 취소합니다 (kapi cancel_approval, 승인→등록 복귀).
     *
     * approved_content 는 유지하되 status 가 approved 를 벗어나므로 발송 게이트가
     * 즉시 차단된다(§3.2 — 운영자 확인 모달이 이 효과를 사전 경고한다).
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return BizppurioTemplate 갱신된 행 (status=draft)
     *
     * @throws BizppurioTemplateStateException 승인 상태가 아닐 때
     * @throws BizppurioApiException kapi 실패
     */
    public function cancelApproval(BizppurioTemplate $template): BizppurioTemplate
    {
        if ($template->status !== BizppurioTemplateStatus::Approved) {
            throw new BizppurioTemplateStateException(
                'sirsoft-message_bizppurio::messages.template.cancel_approval_not_allowed',
                ['status' => $template->status->value],
            );
        }

        $this->assertSuccess($this->kakao->cancelApproval(
            $this->rowSenderKey($template),
            (string) $template->template_code,
        ));

        return $this->templates->update($template, [
            'status' => BizppurioTemplateStatus::Draft->value,
            'last_synced_at' => now(),
        ]);
    }

    /**
     * 휴면(DMT) 템플릿을 해제하고 실제 상태를 재동기화합니다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return BizppurioTemplate 갱신된 행
     *
     * @throws BizppurioTemplateStateException 휴면 상태가 아닐 때
     * @throws BizppurioApiException kapi 실패
     */
    public function releaseDormant(BizppurioTemplate $template): BizppurioTemplate
    {
        if ($template->status !== BizppurioTemplateStatus::Dormant) {
            throw new BizppurioTemplateStateException(
                'sirsoft-message_bizppurio::messages.template.release_not_allowed',
                ['status' => $template->status->value],
            );
        }

        $this->assertSuccess($this->kakao->releaseDormant(
            $this->rowSenderKey($template),
            (string) $template->template_code,
        ));

        // 해제 후 카카오측 실제 상태(RDY/ACT 등)를 되받아 반영한다.
        return $this->sync($template);
    }

    /**
     * 단건 상태를 카카오와 동기화합니다 (수동 [새로고침] / 휴면 해제 후).
     *
     * 카카오측 코드가 없는(등록 전) 행은 동기화 대상이 아니므로 그대로 반환한다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return BizppurioTemplate 갱신된 행
     *
     * @throws BizppurioApiException kapi 실패
     */
    public function sync(BizppurioTemplate $template): BizppurioTemplate
    {
        if ($template->template_code === null) {
            return $template;
        }

        $response = $this->kakao->getTemplateDetail(
            $this->rowSenderKey($template),
            $template->template_code,
        );
        $this->assertSuccess($response);

        $detail = (array) ($response['data'] ?? []);
        $serviceStatus = BizppurioTemplateStatus::serviceStatusFromDetail($detail);

        return $this->applyServiceStatus($template, $serviceStatus, $detail);
    }

    /**
     * 검수중(requested) 행 전체를 카카오와 일괄 동기화합니다 (스케줄 커맨드).
     *
     * requested 행이 없으면 kapi 를 호출하지 않는다. 행별 detail 호출 대신 senderKey 별
     * template/list 1회(페이지 순회)로 일괄 대조한다(레이트리밋 보호). 반려로 전이한 행만
     * 사유(comments) 확보를 위해 detail 을 추가 호출한다.
     *
     * @return array{checked: int, transitioned: int} 점검·전이 행 수
     */
    public function syncRequested(): array
    {
        $rows = $this->templates->allByStatus(BizppurioTemplateStatus::Requested->value)
            ->filter(fn (BizppurioTemplate $row) => $row->template_code !== null);

        if ($rows->isEmpty()) {
            return ['checked' => 0, 'transitioned' => 0];
        }

        $transitioned = 0;

        // 그룹핑은 try 밖에서 평가되므로 여기서 미리 감싼다 — 스냅샷도 환경설정도 비어 있으면
        // rowSenderKey() 가 예외를 던지고, 그대로 두면 30분 배치 전체가 죽는다.
        try {
            $groups = $rows->groupBy(fn (BizppurioTemplate $row) => $this->rowSenderKey($row));
        } catch (BizppurioApiException $e) {
            Log::warning('[sirsoft-message_bizppurio] 발신프로필 키를 해석할 수 없어 상태 동기화를 건너뜁니다', [
                'result_code' => $e->getResultCode(),
                'message' => $e->getMessage(),
            ]);

            return ['checked' => $rows->count(), 'transitioned' => 0];
        }

        foreach ($groups as $senderKey => $group) {
            try {
                $statusMap = $this->fetchServiceStatusMap((string) $senderKey);
            } catch (BizppurioApiException $e) {
                Log::warning('[sirsoft-message_bizppurio] 템플릿 상태 일괄 조회 실패 — 이번 주기 건너뜀', [
                    'sender_key' => $senderKey,
                    'result_code' => $e->getResultCode(),
                    'message' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($group as $row) {
                $serviceStatus = $statusMap[$row->template_code] ?? null;
                if ($serviceStatus === null) {
                    continue;
                }

                $before = $row->status;
                $detail = null;

                // 반려 전이만 사유 원문(comments) 확보를 위해 상세를 추가 조회한다.
                if (BizppurioTemplateStatus::tryFromServiceStatus($serviceStatus) === BizppurioTemplateStatus::Rejected) {
                    $detail = $this->fetchDetailOrNull((string) $senderKey, (string) $row->template_code);
                }

                $updated = $this->applyServiceStatus($row, $serviceStatus, $detail);

                if ($updated->status !== $before) {
                    $transitioned++;
                }
            }
        }

        return ['checked' => $rows->count(), 'transitioned' => $transitioned];
    }

    /**
     * 템플릿 행을 삭제합니다. 카카오측 코드는 삭제 가능 상태일 때만 함께 지웁니다.
     *
     * kapi delete 는 status R + REG/REJ 에서만 허용된다(부록 A-4). 우리 상태가
     * draft/rejected 면 kapi 삭제를 시도하고, 그 외 상태(또는 kapi 삭제 실패)면 카카오측은
     * 그대로 두고 DB 행만 삭제한다 — 응답에 사유를 명시해 운영자가 알 수 있게 한다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return array{kakao_deleted: bool, kakao_skip_reason: string|null} 카카오측 삭제 여부·미삭제 사유
     */
    public function delete(BizppurioTemplate $template): array
    {
        $kakaoDeleted = false;
        $skipReason = null;

        if ($template->template_code === null) {
            $skipReason = 'not_registered';
        } elseif (! $template->status->allowsContentEdit()) {
            $skipReason = 'state_not_deletable';
        } else {
            try {
                $this->assertSuccess($this->kakao->deleteTemplate(
                    $this->rowSenderKey($template),
                    $template->template_code,
                ));
                $kakaoDeleted = true;
            } catch (BizppurioApiException $e) {
                $skipReason = $e->getMessage();
                Log::warning('[sirsoft-message_bizppurio] 카카오측 템플릿 삭제 실패 — DB 행만 삭제', [
                    'template_code' => $template->template_code,
                    'result_code' => $e->getResultCode(),
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $this->templates->delete($template);

        return ['kakao_deleted' => $kakaoDeleted, 'kakao_skip_reason' => $skipReason];
    }

    /**
     * 이미지형 템플릿용 이미지를 업로드하고 URL 을 반환합니다 (업로드 프록시).
     *
     * @param  UploadedFile  $file  검증 완료된 업로드 파일
     * @return string 카카오가 반환한 이미지 URL (templateImageUrl 에 사용)
     *
     * @throws BizppurioApiException 업로드 실패
     */
    public function uploadImage(UploadedFile $file): string
    {
        $response = $this->kakao->uploadTemplateImage(
            $file->getRealPath(),
            $file->getClientOriginalName(),
        );
        $this->assertSuccess($response);

        // 부록 A-7: data 봉투가 아니라 응답 최상위 image 필드에 URL 이 실린다.
        $url = (string) ($response['image'] ?? '');

        if ($url === '') {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.invalid_response'),
            );
        }

        return $url;
    }

    /**
     * kapi serviceStatus 를 행에 반영합니다 (동기화 공통 경로).
     *
     * - 승인 전이 시 content 를 approved_content 로 동결하고 approved_at 을 확정한다.
     * - 반려 시 상세의 comments 를 inspection_detail 로 저장한다.
     * - 알 수 없는 serviceStatus 는 상태를 덮어쓰지 않고 동기화 시각만 남긴다.
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @param  string  $serviceStatus  kapi serviceStatus
     * @param  array<string, mixed>|null  $detail  kapi 상세 (comments 소스, 없으면 null)
     * @return BizppurioTemplate 갱신된 행
     */
    private function applyServiceStatus(
        BizppurioTemplate $template,
        string $serviceStatus,
        ?array $detail = null,
    ): BizppurioTemplate {
        $status = BizppurioTemplateStatus::tryFromServiceStatus($serviceStatus);

        $data = ['last_synced_at' => now()];

        if ($status !== null) {
            $data['status'] = $status->value;

            if ($status === BizppurioTemplateStatus::Approved && $template->status !== BizppurioTemplateStatus::Approved) {
                $data['approved_content'] = $template->content;
                $data['approved_at'] = now();
            }

            if ($status === BizppurioTemplateStatus::Rejected && is_array($detail) && isset($detail['comments'])) {
                $data['inspection_detail'] = (array) $detail['comments'];
            }
        }

        return $this->templates->update($template, $data);
    }

    /**
     * senderKey 의 전체 템플릿 상태 맵(templateCode → serviceStatus)을 조회합니다.
     *
     * kapi list 를 페이지 순회로 일괄 조회한다(최대 페이지 상한으로 무한 루프 방지).
     *
     * @param  string  $senderKey  발신프로필 키
     * @return array<string, string> templateCode → serviceStatus
     *
     * @throws BizppurioApiException 조회 실패
     */
    private function fetchServiceStatusMap(string $senderKey): array
    {
        $map = [];
        $page = 1;

        do {
            $response = $this->kakao->getTemplateList($senderKey, [
                'count' => self::SYNC_LIST_COUNT,
                'page' => $page,
            ]);
            $this->assertSuccess($response);

            $data = (array) ($response['data'] ?? []);
            foreach ((array) ($data['list'] ?? []) as $row) {
                if (is_array($row) && ! empty($row['templateCode'])) {
                    $map[(string) $row['templateCode']] = (string) ($row['serviceStatus'] ?? '');
                }
            }

            $totalPage = (int) ($data['totalPage'] ?? 1);
            $page++;
        } while ($page <= $totalPage && $page <= self::SYNC_LIST_MAX_PAGES);

        return $map;
    }

    /**
     * 상세를 조회하되 실패는 null 로 되돌립니다 (반려 사유 확보는 부가 정보).
     *
     * @param  string  $senderKey  발신프로필 키
     * @param  string  $templateCode  템플릿 코드
     * @return array<string, mixed>|null 상세 data 또는 실패 시 null
     */
    private function fetchDetailOrNull(string $senderKey, string $templateCode): ?array
    {
        try {
            $response = $this->kakao->getTemplateDetail($senderKey, $templateCode);
            $this->assertSuccess($response);

            return (array) ($response['data'] ?? []);
        } catch (BizppurioApiException $e) {
            // 반려 사유(comments)를 못 받으면 화면에는 "반려됐는데 사유가 없음" 만 남는다 —
            // 상태 전이 자체는 계속 진행하되, 사유가 비는 원인은 로그로 남긴다.
            Log::warning('[sirsoft-message_bizppurio] 템플릿 상세 조회 실패 — 반려 사유를 저장하지 못했습니다', [
                'sender_key' => $senderKey,
                'template_code' => $templateCode,
                'result_code' => $e->getResultCode(),
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * template_code 를 자체 채번합니다 (§3.2).
     *
     * `g7_{md5(notification_type) 앞 8자}_{세대}` 형식(≤30자, 영문/숫자/언더스코어).
     * 우리 DB 와 kapi codeCheck 양쪽에서 미사용일 때만 확정하고, 충돌 시 세대를 올려
     * 최대 3회 재시도한다.
     *
     * @param  string  $senderKey  발신프로필 키
     * @param  string  $notificationType  알림 유형
     * @return string 확정된 템플릿 코드
     *
     * @throws BizppurioApiException 재시도 소진·codeCheck 호출 실패
     */
    private function generateTemplateCode(string $senderKey, string $notificationType): string
    {
        $base = self::CODE_PREFIX.'_'.substr(md5($notificationType), 0, 8);

        for ($generation = 1; $generation <= self::CODE_CHECK_MAX_ATTEMPTS; $generation++) {
            $code = $base.'_'.$generation;

            if ($this->templates->templateCodeExists($code)) {
                continue;
            }

            // codeCheck: code 200 = 사용 가능, 504(중복) 등 = 불가 → 세대 증가 재시도(A-4).
            $response = $this->kakao->checkTemplateCode($senderKey, $code);
            if ($this->kakao->isSuccess($response)) {
                return $code;
            }
        }

        throw new BizppurioApiException(
            __('sirsoft-message_bizppurio::messages.template.code_generation_failed'),
        );
    }

    /**
     * 행의 발신프로필 키를 반환합니다 (스냅샷 우선, 없으면 현재 설정).
     *
     * @param  BizppurioTemplate  $template  대상 행
     * @return string 발신프로필 키
     *
     * @throws BizppurioApiException 스냅샷·설정 모두 없을 때
     */
    private function rowSenderKey(BizppurioTemplate $template): string
    {
        $snapshot = trim((string) $template->sender_key);

        return $snapshot !== '' ? $snapshot : $this->senderKey();
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
        $senderKey = trim((string) $this->pluginSettings->get(self::PLUGIN_IDENTIFIER, 'sender_key', ''));

        if ($senderKey === '') {
            throw new BizppurioApiException(
                __('sirsoft-message_bizppurio::messages.error.sender_key_missing'),
            );
        }

        return $senderKey;
    }

    /**
     * kapi 응답이 성공(200)이 아니면 message 원문을 담아 예외를 던집니다.
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

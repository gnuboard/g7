<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\MessageBizppurio\Repositories\Contracts;

use Illuminate\Support\Collection;
use Plugins\Sirsoft\MessageBizppurio\Models\BizppurioNotificationBinding;

/**
 * 비즈뿌리오 이벤트↔알림톡 템플릿 연결 Repository 계약.
 *
 * 알림톡 탭 연동 CRUD(계획서 §6-2, Phase 6)와 알림톡 발송 시 template_code 해석을
 * 담당한다. Phase 4 는 계약과 기본 조회/저장을 제공한다.
 */
interface BizppurioNotificationBindingRepositoryInterface
{
    /**
     * 알림 유형+채널로 활성 연동을 조회합니다 (발송 시 template_code 해석).
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  string  $channel  채널 (기본 alimtalk)
     * @return BizppurioNotificationBinding|null 매칭 연동 또는 null(미연결)
     */
    public function findActive(string $notificationType, string $channel = 'alimtalk'): ?BizppurioNotificationBinding;

    /**
     * 채널의 모든 연동을 조회합니다 (알림톡 탭 목록).
     *
     * @param  string  $channel  채널 (기본 alimtalk)
     * @return Collection<int, BizppurioNotificationBinding>
     */
    public function allByChannel(string $channel = 'alimtalk'): Collection;

    /**
     * 알림 유형+채널 연동을 생성하거나 갱신합니다 (탭 편집 모달 저장).
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  string  $channel  채널
     * @param  array<string, mixed>  $data  template_code / template_name / fallback_sms_enabled / is_active
     * @return BizppurioNotificationBinding 저장된 연동
     */
    public function upsert(string $notificationType, string $channel, array $data): BizppurioNotificationBinding;

    /**
     * 알림 유형+채널 연동을 삭제합니다 (연동 해제).
     *
     * @param  string  $notificationType  코어 notification_definitions.type
     * @param  string  $channel  채널
     * @return void
     */
    public function delete(string $notificationType, string $channel = 'alimtalk'): void;
}

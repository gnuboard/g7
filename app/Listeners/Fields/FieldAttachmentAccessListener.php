<?php

namespace App\Listeners\Fields;

use App\Contracts\Extension\HookListenerInterface;
use App\Fields\FieldRegistry;
use App\Fields\FieldSet;
use App\Fields\Storage\FieldFileStorage;
use App\Helpers\PermissionHelper;
use App\Models\Attachment;
use App\Models\FieldDefinition;
use App\Models\User;

/**
 * 회원 파일·이미지 항목의 첨부 열람 권한을 판정합니다 (필터 `core.attachment.filter_can_download`).
 *
 * 회원 항목에 올린 파일은 주소(해시)를 안다고 누구나 받을 수 있으면 안 된다.
 *
 * | 요청자 | 결과 |
 * | --- | --- |
 * | 회원 조회 권한(`core.users.read`)이 있는 관리자 | 허용 |
 * | 본인 | 허용. 단 관리자만 열람 항목은 거부 |
 * | 그 밖(다른 회원, 비로그인) | 공개 프로필에 노출하는 이미지 항목만 허용 |
 *
 * 회원 항목 첨부가 아니면 의견을 내지 않는다(앞선 결과를 그대로 돌려준다).
 *
 * @since 7.2.0
 */
class FieldAttachmentAccessListener implements HookListenerInterface
{
    /**
     * @param  FieldRegistry  $registry  항목 등록소
     */
    public function __construct(protected FieldRegistry $registry) {}

    /**
     * 구독할 훅과 메서드 매핑을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 훅 => 옵션
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.attachment.filter_can_download' => ['method' => 'canDownload', 'priority' => 10, 'type' => 'filter'],
        ];
    }

    /**
     * 인터페이스 기본 핸들러 (개별 메서드에서 처리).
     *
     * @param  mixed  ...$args  훅 인자
     * @return void
     */
    public function handle(...$args): void {}

    /**
     * 회원 항목 첨부의 열람 가능 여부를 돌려줍니다.
     *
     * @param  mixed  $verdict  앞선 필터 결과 (true | false | null)
     * @param  mixed  ...$args  [Attachment, 요청한 사용자|null]
     * @return mixed 회원 항목 첨부면 true 또는 false, 아니면 앞선 결과
     */
    public function canDownload(mixed $verdict, mixed ...$args): mixed
    {
        $attachment = $args[0] ?? null;
        if (! $attachment instanceof Attachment || $attachment->attachmentable_type !== User::class) {
            return $verdict;
        }

        $key = FieldFileStorage::keyFromCollection($attachment->collection);
        if ($key === null) {
            return $verdict;
        }

        // 다른 구독자가 이미 거부했으면 뒤집지 않는다.
        if ($verdict === false) {
            return false;
        }

        $viewer = ($args[1] ?? null) instanceof User ? $args[1] : null;
        $definition = $this->registry->find(FieldSet::CORE_USER, $key);

        if ($viewer !== null && PermissionHelper::check('core.users.read', $viewer)) {
            return true;
        }

        // 항목이 지워졌거나 꺼진 확장의 것이면 관리자 말고는 열 수 없다.
        if ($definition === null) {
            return false;
        }

        if ($viewer !== null && (int) $viewer->getKey() === (int) $attachment->attachmentable_id) {
            return $definition->edit_scope !== FieldDefinition::SCOPE_ADMIN_ONLY;
        }

        return $definition->enabled && $definition->public_profile && $definition->type === 'image'
            && $definition->edit_scope !== FieldDefinition::SCOPE_ADMIN_ONLY;
    }
}

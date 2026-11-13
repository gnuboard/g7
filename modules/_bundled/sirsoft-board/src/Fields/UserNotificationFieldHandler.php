<?php

namespace Modules\Sirsoft\Board\Fields;

use App\Fields\Contracts\FieldValueHandlerInterface;
use App\Fields\FieldContext;
use App\Models\FieldDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Modules\Sirsoft\Board\Services\UserNotificationSettingService;

/**
 * 회원 항목(core.user)으로 선언한 게시판 알림 수신 설정 4칸의 값 저장소 어댑터.
 *
 * 값은 종전과 같이 `board_user_notification_settings` 의 회원별 한 행에 둔다. 코어가 회원 저장(가입, 관리자 추가와 수정,
 * 내 정보 수정)의 같은 트랜잭션 안에서 바뀐 칸만 `write()` 로 넘기므로, 이 클래스는 그 칸 하나만 고친다.
 * 요청에 실리지 않은 칸은 코어가 넘기지 않으며 저장된 값이 그대로 남는다.
 */
class UserNotificationFieldHandler implements FieldValueHandlerInterface
{
    /**
     * 이 핸들러가 맡는 항목 키 (설정 테이블의 컬럼명과 같다)
     */
    public const FIELD_KEYS = [
        'notify_post_complete',
        'notify_post_reply',
        'notify_comment',
        'notify_reply_comment',
    ];

    /**
     * @param  UserNotificationSettingService  $service  알림 설정 서비스
     */
    public function __construct(
        private UserNotificationSettingService $service,
    ) {}

    /**
     * 회원의 알림 수신 여부를 읽습니다.
     *
     * @param  Model  $owner  회원
     * @param  FieldDefinition  $definition  항목 정의
     * @return bool 수신하면 true (설정 행이 없으면 false)
     */
    public function read(Model $owner, FieldDefinition $definition): mixed
    {
        if (! $this->handles($definition)) {
            return false;
        }

        try {
            $settings = $this->service->getByUserId((int) $owner->getKey());
        } catch (QueryException) {
            // 설정 테이블이 아직 없는 구간(마이그레이션 전)에서는 기본값으로 답한다
            return false;
        }

        return $settings !== null && (bool) $settings->{$definition->key};
    }

    /**
     * 회원의 알림 수신 여부 한 칸을 저장합니다.
     *
     * @param  Model  $owner  회원
     * @param  FieldDefinition  $definition  항목 정의
     * @param  mixed  $value  정규화한 값 (비우면 null 이며 false 로 저장한다)
     * @param  FieldContext  $context  요청 문맥
     * @return void 반환값 없음
     */
    public function write(Model $owner, FieldDefinition $definition, mixed $value, FieldContext $context): void
    {
        if (! $this->handles($definition)) {
            return;
        }

        $this->service->createOrUpdate((int) $owner->getKey(), [$definition->key => (bool) $value]);
    }

    /**
     * 유형 규칙(boolean)에 더할 규칙이 없습니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @param  string  $attribute  요청 속성 경로
     * @param  FieldContext  $context  요청 문맥
     * @return array<int, mixed> 빈 배열
     */
    public function extraRules(FieldDefinition $definition, string $attribute, FieldContext $context): array
    {
        return [];
    }

    /**
     * 회원의 알림 설정 행을 지웁니다.
     *
     * 네 항목이 한 행을 나눠 쓰므로 항목마다 불려도 첫 호출이 지우고 나머지는 지울 것이 없다.
     *
     * @param  Model  $owner  회원
     * @param  FieldDefinition  $definition  항목 정의
     * @return void 반환값 없음
     */
    public function purge(Model $owner, FieldDefinition $definition): void
    {
        $this->service->delete((int) $owner->getKey());
    }

    /**
     * 이 핸들러가 맡는 항목인지 반환합니다.
     *
     * @param  FieldDefinition  $definition  항목 정의
     * @return bool 알림 설정 4칸 가운데 하나면 true
     */
    private function handles(FieldDefinition $definition): bool
    {
        return in_array($definition->key, self::FIELD_KEYS, true);
    }
}

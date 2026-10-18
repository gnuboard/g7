<?php

namespace Database\Seeders\Sample;

use App\Database\Sample\AbstractNotificationLogSampleSeeder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 코어 알림 발송 이력 샘플 시더.
 *
 * extension_type='core' 인 알림 정의(welcome / reset_password / password_changed)
 * 만 사용하여 코어 영역 발송 이력을 채운다. 모듈 알림은 각 모듈 자체 시더에서 처리.
 *
 * 수동 실행:
 *   php artisan db:seed --class="Database\Seeders\Sample\NotificationLogSeeder"
 *   php artisan db:seed --class="Database\Seeders\Sample\NotificationLogSeeder" --count=core_notification_logs=300
 */
class NotificationLogSeeder extends AbstractNotificationLogSampleSeeder
{
    /**
     * 코어 정의만 필터링.
     *
     * @param  Builder  $query  NotificationDefinition 쿼리
     * @return Builder 코어 영역 쿼리
     */
    protected function applyDefinitionScope(Builder $query): Builder
    {
        return $query->where('extension_type', 'core');
    }

    /**
     * @return string 카운트 옵션 키
     */
    protected function countKey(): string
    {
        return 'core_notification_logs';
    }

    /**
     * @return int 기본 건수
     */
    protected function defaultCount(): int
    {
        return 100;
    }

    /**
     * @return string 영역 라벨
     */
    protected function scopeLabel(): string
    {
        return '[코어]';
    }

    /**
     * @return array<string, string>
     */
    protected function subjectMap(): array
    {
        return [
            'welcome' => '[G7] 회원가입을 환영합니다',
            'reset_password' => '[G7] 비밀번호 재설정 안내',
            'password_changed' => '[G7] 비밀번호가 변경되었습니다',
        ];
    }

    /**
     * @return array<string, callable(User, Carbon): string>
     */
    protected function bodyMap(): array
    {
        return [
            'welcome' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\nG7에 가입해 주셔서 감사합니다.\n다양한 기능을 자유롭게 이용하실 수 있도록 도와드리겠습니다.\n\n감사합니다.\nG7 팀 드림",
            'reset_password' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n비밀번호 재설정 요청이 접수되었습니다.\n아래 링크를 클릭하여 새 비밀번호를 설정해 주세요.\n링크는 30분간 유효합니다.\n\n본인이 요청하지 않으셨다면 이 메일을 무시하셔도 됩니다.",
            'password_changed' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n계정 비밀번호가 정상적으로 변경되었습니다.\n변경 일시: {$t->format('Y-m-d H:i')}\n\n본인이 변경하지 않으셨다면 즉시 고객센터로 문의해 주세요.",
        ];
    }
}

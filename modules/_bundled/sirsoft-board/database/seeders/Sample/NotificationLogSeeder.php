<?php

namespace Modules\Sirsoft\Board\Database\Seeders\Sample;

use App\Database\Sample\AbstractNotificationLogSampleSeeder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * 게시판 모듈 알림 발송 이력 샘플 시더.
 *
 * extension_identifier='sirsoft-board' 인 알림 정의(댓글/답글/신고 7종)만 사용하여
 * 게시판 영역 발송 이력을 채운다.
 *
 * 수동 실행:
 *   php artisan module:seed sirsoft-board \
 *     --class="Sample\\NotificationLogSeeder" --sample
 */
class NotificationLogSeeder extends AbstractNotificationLogSampleSeeder
{
    /**
     * 게시판 모듈 정의만 필터링.
     *
     * @param  Builder  $query  NotificationDefinition 쿼리
     * @return Builder 게시판 영역 쿼리
     */
    protected function applyDefinitionScope(Builder $query): Builder
    {
        return $query->where('extension_identifier', 'sirsoft-board');
    }

    /**
     * @return string 카운트 옵션 키
     */
    protected function countKey(): string
    {
        return 'board_notification_logs';
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
        return '[게시판]';
    }

    /**
     * @return array<string, string>
     */
    protected function subjectMap(): array
    {
        return [
            'new_comment' => '[G7] 새 댓글이 등록되었습니다',
            'reply_comment' => '[G7] 작성하신 댓글에 답글이 달렸습니다',
            'post_reply' => '[G7] 답변글이 등록되었습니다',
            'post_action' => '[G7] 게시물 처리 결과 안내',
            'new_post_admin' => '[G7관리자] 새 게시글이 등록되었습니다',
            'report_received_admin' => '[G7관리자] 게시물 신고가 접수되었습니다',
            'report_action' => '[G7] 신고 처리 결과 안내',
        ];
    }

    /**
     * @return array<string, callable(User, Carbon): string>
     */
    protected function bodyMap(): array
    {
        return [
            'new_comment' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n작성하신 게시글에 새 댓글이 등록되었습니다.\n게시글에서 확인해 주세요.",
            'reply_comment' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n남기신 댓글에 누군가 답글을 작성했습니다.",
            'post_reply' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n작성하신 게시글에 답변글이 등록되었습니다.",
            'post_action' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n게시물에 대한 관리자 조치가 적용되었습니다.\n자세한 내용은 알림 메시지에서 확인해 주세요.",
            'new_post_admin' => fn (User $u, Carbon $t) => "관리자님,\n\n새 게시글이 등록되었습니다.\n검토가 필요한 경우 관리자 페이지에서 확인해 주세요.",
            'report_received_admin' => fn (User $u, Carbon $t) => "관리자님,\n\n게시물 신고가 접수되었습니다.\n신속한 처리를 부탁드립니다.",
            'report_action' => fn (User $u, Carbon $t) => "안녕하세요 {$u->name}님,\n\n접수하신 신고 건에 대한 처리 결과를 안내드립니다.\n자세한 내용은 알림 메시지에서 확인하실 수 있습니다.",
        ];
    }
}

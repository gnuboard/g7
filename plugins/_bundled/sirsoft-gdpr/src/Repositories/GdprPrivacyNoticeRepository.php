<?php

namespace Plugins\Sirsoft\Gdpr\Repositories;

use App\Enums\UserStatus;
use App\Models\User;
use App\Repositories\Concerns\PaginatesWithDeferredJoin;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;
use Plugins\Sirsoft\Gdpr\Enums\PrivacyNoticeStatus;
use Plugins\Sirsoft\Gdpr\Models\GdprPrivacyNoticeLog;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPrivacyNoticeRepositoryInterface;

/** 개인정보 연간 통지 대상과 불변 이력을 조회합니다. */
class GdprPrivacyNoticeRepository implements GdprPrivacyNoticeRepositoryInterface
{
    use PaginatesWithDeferredJoin;

    /**
     * 활성 회원만 ID 순서로 유계 조회합니다. scopeActive는 탈퇴만 제외하므로 사용하지 않습니다.
     *
     * @return LazyCollection 처리 결과
     */
    public function candidates(): LazyCollection
    {
        return User::query()->where('status', UserStatus::Active)
            ->where('created_at', '<=', now()->subYearNoOverflow())
            ->select(['id'])->lazyById(100);
    }

    /**
     * 동일 회원의 중복 접수를 막기 위해 회원 행을 잠급니다.
     *
     * @param  int  $userId  회원 ID
     * @return ?User 처리 결과
     */
    public function lockUser(int $userId): ?User
    {
        return User::query()->whereKey($userId)->lockForUpdate()->first();
    }

    /**
     * 최근 1년 성공 또는 아직 처리 중인 채널을 제외합니다.
     *
     * @param  int  $userId  회원 ID
     * @param  array  $channels  채널 목록
     * @return array 처리 결과
     */
    public function dueChannels(int $userId, array $channels): array
    {
        $table = (new GdprPrivacyNoticeLog)->getTable();
        $due = [];
        foreach ($channels as $channel) {
            $base = GdprPrivacyNoticeLog::query()->where('user_id', $userId)->where('is_test', false)
                ->whereJsonContains('channels', $channel);
            $sent = (clone $base)->where('status', PrivacyNoticeStatus::Sent)
                ->where('sent_at', '>', now()->subYearNoOverflow())->exists();
            $pending = (clone $base)->where('status', PrivacyNoticeStatus::Queued)
                ->whereNotExists(function ($query) use ($channel, $table) {
                    $query->select('results.id')->from($table.' as results')
                        ->whereColumn('results.attempt_id', $table.'.attempt_id')
                        ->whereIn('results.status', [PrivacyNoticeStatus::Sent->value, PrivacyNoticeStatus::Failed->value])
                        ->whereJsonContains('results.channels', $channel);
                })->exists();
            if (! $sent && ! $pending) {
                $due[] = $channel;
            }
        }

        return $due;
    }

    /**
     * 기존 행을 갱신하지 않고 이벤트를 추가합니다.
     *
     * @param  array  $data  이력 데이터
     * @return GdprPrivacyNoticeLog 처리 결과
     */
    public function append(array $data): GdprPrivacyNoticeLog
    {
        return GdprPrivacyNoticeLog::query()->create($data);
    }

    /**
     * 워커 재실행 시 완료된 채널은 재발송하지 않습니다.
     *
     * @param  string  $attemptId  접수 식별자
     * @return array 처리 결과
     */
    public function pending(string $attemptId): array
    {
        // 접수 1행 + 최대 두 채널 결과 2행이 요청별 최대 크기입니다.
        $logs = GdprPrivacyNoticeLog::query()->where('attempt_id', $attemptId)->limit(3)->get(['status', 'channels']);
        $queued = $logs->where('status', PrivacyNoticeStatus::Queued)->pluck('channels')->flatten()->all();
        $finished = $logs->where('status', '!=', PrivacyNoticeStatus::Queued)->pluck('channels')->flatten()->all();

        return array_values(array_diff($queued, $finished));
    }

    /**
     * 최초 접수의 식별정보 없는 스냅샷을 반환합니다.
     *
     * @param  string  $attemptId  접수 식별자
     * @return ?GdprPrivacyNoticeLog 처리 결과
     */
    public function findAttempt(string $attemptId): ?GdprPrivacyNoticeLog
    {
        return GdprPrivacyNoticeLog::query()->where('attempt_id', $attemptId)
            ->where('status', PrivacyNoticeStatus::Queued)->first();
    }

    /**
     * 관리자 이력은 최신 이벤트부터 조회합니다.
     *
     * @param  int  $perPage  페이지당 행 수
     * @return LengthAwarePaginator 처리 결과
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->paginateWithDeferredJoin(
            GdprPrivacyNoticeLog::query(),
            ['id', 'user_id', 'attempt_id', 'status', 'channels', 'is_test', 'sent_at', 'created_at'],
            [['column' => 'id', 'direction' => 'desc']],
            $perPage,
        );
    }

    /**
     * 완전 삭제의 유일한 UPDATE 예외: 증적은 보존하고 회원 연결만 제거합니다.
     *
     * @param  int  $userId  회원 ID
     * @return void 반환값 없음
     */
    public function anonymize(int $userId): void
    {
        GdprPrivacyNoticeLog::query()->where('user_id', $userId)->update(['user_id' => null]);
    }
}

<?php

namespace Modules\Sirsoft\Board\Listeners;

use App\Contracts\Extension\HookListenerInterface;
use App\Models\User;
use Illuminate\Database\QueryException;
use Modules\Sirsoft\Board\Services\ReactionService;
use Modules\Sirsoft\Board\Traits\ChecksBoardPermission;
use Modules\Sirsoft\Board\Traits\FormatsBoardDate;

/**
 * 회원 상세 반응 요약 주입 리스너 (filter 훅)
 *
 * 코어 UserResource 의 관리자 상세(withAdminInfo)·프로필(/api/me) 응답에 발화하는
 * core.user.filter_resource_data 필터 훅을 구독해, 회원의 반응 건수(게시글·댓글)와
 * 최근 반응 시각을 **관리자 응답에만** 병합합니다 (UserScrapInfoListener 와 동형).
 *
 * 프로필(/api/me)에는 싣지 않는다 — 마이페이지 「내 반응」 탭이 이미 목록 API 로 같은
 * 정보를 더 정확하게 내고, 프로필 응답은 모든 화면 부팅 경로에서 읽히므로 여기에 집계
 * 쿼리를 얹으면 반응을 쓰지 않는 화면까지 비용을 낸다.
 *
 * 회원 목록(toListArray)에는 이 훅이 발화하지 않으므로 N+1·목록 풀스캔이 구조적으로 없습니다.
 */
class UserReactionInfoListener implements HookListenerInterface
{
    use ChecksBoardPermission;
    use FormatsBoardDate;

    /**
     * @param  ReactionService  $reactionService  반응 서비스 (요약 집계)
     */
    public function __construct(
        private ReactionService $reactionService,
    ) {}

    /**
     * 구독할 훅 목록 반환
     *
     * @return array<string, array{method: string, type: string, priority: int}> 훅 매핑
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'core.user.filter_resource_data' => [
                'method' => 'injectReactionSummary',
                'type' => 'filter',
                'priority' => 31,
            ],
        ];
    }

    /**
     * 기본 핸들러 (getSubscribedHooks 의 method 매핑 사용)
     *
     * @param  mixed  ...$args  훅 인수
     * @return void
     */
    public function handle(...$args): void
    {
        // method 매핑(injectReactionSummary)을 사용하므로 직접 호출되지 않음
    }

    /**
     * 관리자 회원 상세 응답에 반응 요약을 병합합니다.
     *
     * 판정은 ChecksBoardPermission::isAdminRequest 한 곳에서만 한다 — 사설 복제본을 두면
     * 규칙이 바뀔 때 한쪽만 누락되어 프로필 응답으로 새어나간다.
     *
     * @param  array<string, mixed>  $data  코어가 직렬화한 회원 응답 데이터
     * @param  User  $user  회원 모델
     * @return array<string, mixed> 반응 요약이 병합된 데이터
     */
    public function injectReactionSummary(array $data, User $user): array
    {
        if (! $this->isAdminRequest(request())) {
            return $data;
        }

        // 반응 조회 권한이 없는 관리자에게는 싣지 않는다 — 실으면 회원 상세가 「반응」 탭과
        // 건수를 그리고, 탭의 「이 회원 반응 목록 보기」는 403 화면으로 끝난다.
        if (! $this->checkModulePermission('reactions', 'view')) {
            return $data;
        }

        try {
            $summary = $this->reactionService->summaryForUser($user->id);
        } catch (QueryException $e) {
            // 테이블이 아직 없거나(설치 직후) 조회가 실패해도 회원 상세 화면 자체는 떠야 한다.
            return $this->merge($data, ['count' => 0, 'post_count' => 0, 'comment_count' => 0], null);
        }

        return $this->merge(
            $data,
            $summary,
            $summary['latest_reacted_at'] !== null
                ? $this->formatCreatedAtFormat(
                    $summary['latest_reacted_at'],
                    g7_module_settings('sirsoft-board', 'display.date_display_format', 'standard')
                )
                : null
        );
    }

    /**
     * 반응 요약 블록을 병합합니다.
     *
     * @param  array<string, mixed>  $data  회원 응답 데이터
     * @param  array<string, mixed>  $summary  요약 집계 (count·post_count·comment_count)
     * @param  string|null  $latest  최근 반응 시각 표시 문자열
     * @return array<string, mixed> 병합 결과
     */
    private function merge(array $data, array $summary, ?string $latest): array
    {
        $data['board_reactions'] = [
            'count' => (int) ($summary['count'] ?? 0),
            'post_count' => (int) ($summary['post_count'] ?? 0),
            'comment_count' => (int) ($summary['comment_count'] ?? 0),
            'latest_reacted_at' => $latest,
        ];

        return $data;
    }
}

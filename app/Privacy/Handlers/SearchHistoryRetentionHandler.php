<?php

namespace App\Privacy\Handlers;

use App\Contracts\Repositories\UserSearchHistoryRepositoryInterface;
use App\Extension\HookManager;
use App\Privacy\Contracts\PrivacyRetentionHandlerInterface;
use App\Privacy\DTO\PrivacyRetentionTarget;
use App\Support\SearchScopes;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 회원 최근 검색어 보관 기한 파기 핸들러
 *
 * 환경설정 > 개인정보 「최근 검색어」 카드에서 범위마다 정한 보관 기한(일)이 지난 검색어를 지웁니다.
 * 0(기한 없음)인 범위는 건드리지 않습니다. 범위의 기한은 설정 행 → 확장 선언값 → 기본값(기한 없음) 순으로
 * 정하고({@see SearchScopes::retentionPolicies()}), 확장을 끈 동안에도 저장된 설정 행이 있으면 그 기한을 지킵니다.
 *
 * ## 켜짐 판정
 *
 * 별도 토글이 없습니다 — 운영자가 어느 범위에든 1일 이상의 기한을 적는 것이 켜는 행위입니다. 기본 선언값은
 * 기한 없음이라 설치만으로 아무것도 지워지지 않습니다.
 *
 * ## 대상 목록은 검색어를 보이지 않는다
 *
 * 명단은 회원·범위로 묶어 건수와 가장 오래된 검색일만 냅니다 — 검색어 자체는 개인의 관심사라 관리자 화면에
 * 내보내지 않습니다(개인정보 최소 노출). 건수({@see preview()})와 파기({@see process()})는 검색어 행 단위입니다.
 */
class SearchHistoryRetentionHandler implements PrivacyRetentionHandlerInterface
{
    /** 이 핸들러가 발행하는 prune 훅 접두사 */
    public const HOOK_PREFIX = 'core.privacy.search_history';

    /**
     * @param  UserSearchHistoryRepositoryInterface  $histories  최근 검색어 저장소
     */
    public function __construct(
        private readonly UserSearchHistoryRepositoryInterface $histories,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function id(): string
    {
        return 'search-history';
    }

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return __('privacy.handlers.search_history');
    }

    /**
     * {@inheritDoc}
     */
    public function isEnabled(): bool
    {
        return SearchScopes::retentionPolicies() !== [];
    }

    /**
     * {@inheritDoc}
     */
    public function preview(): int
    {
        return $this->histories->countExpired(SearchScopes::retentionPolicies(), Carbon::now());
    }

    /**
     * {@inheritDoc}
     */
    public function targets(int $page, int $perPage): LengthAwarePaginator
    {
        $declared = SearchScopes::all();

        return $this->histories
            ->paginateExpiredGroups(SearchScopes::retentionPolicies(), Carbon::now(), $page, $perPage)
            ->through(fn (object $row) => new PrivacyRetentionTarget(
                id: $row->user_id.':'.$row->scope,
                title: (string) $row->user_name,
                subtitle: SearchScopes::labelText($declared[$row->scope]['label'] ?? null, (string) $row->scope),
                context: __('privacy.targets.keyword_count_value', ['count' => (int) $row->keyword_count]),
                date: $row->oldest_searched_at !== null ? Carbon::parse($row->oldest_searched_at) : null,
                url: '/admin/users/'.$row->user_uuid,
            ));
    }

    /**
     * {@inheritDoc}
     */
    public function targetColumns(): array
    {
        return [
            'title' => __('privacy.targets.columns.member'),
            'subtitle' => __('privacy.targets.columns.search_scope'),
            'context' => __('privacy.targets.columns.keyword_count'),
            'date' => __('privacy.targets.columns.oldest_searched_at'),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function targetPermissions(): array
    {
        return ['core.settings.read', 'core.users.read'];
    }

    /**
     * {@inheritDoc}
     */
    public function runPermissions(): array
    {
        return ['core.settings.update', 'core.users.update'];
    }

    /**
     * {@inheritDoc}
     */
    public function process(bool $dryRun, bool $force = false): array
    {
        // 토글은 「기한을 적은 범위가 있는가」 하나다 — 기한이 없으면 직권 실행이어도 지울 대상이 없다.
        $policies = SearchScopes::retentionPolicies();

        if ($policies === []) {
            return ['count' => 0, 'mode' => $force ? 'delete' : 'disabled'];
        }

        $now = Carbon::now();

        if ($dryRun) {
            return ['count' => $this->histories->countExpired($policies, $now), 'mode' => 'delete'];
        }

        HookManager::doAction(self::HOOK_PREFIX.'.before_prune', $policies);

        $deleted = $this->histories->deleteExpired($policies, $now);

        HookManager::doAction(self::HOOK_PREFIX.'.after_prune', $policies, $deleted);

        if ($deleted > 0) {
            Log::info('최근 검색어 보관 기한 파기 완료', ['policies' => $policies, 'count' => $deleted]);
        }

        return ['count' => $deleted, 'mode' => 'delete'];
    }
}

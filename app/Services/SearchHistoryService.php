<?php

namespace App\Services;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Contracts\Repositories\PluginRepositoryInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Contracts\Repositories\UserSearchHistoryRepositoryInterface;
use App\Extension\HookManager;
use App\Models\User;
use App\Models\UserSearchHistory;
use App\Support\SearchScopes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 회원 최근 검색어 서비스
 *
 * 검색창 제출 때 엔진이 부르는 기록·목록·삭제와, 로그인 때 브라우저(비회원) 목록을 서버로 옮기는 병합을
 * 맡는다. 범위(`scope`)는 {@see SearchScopes} 가 집계한 선언 범위만 받는다 — 요청 검증이 먼저 거르고,
 * 이 서비스는 기능 사용 여부와 회원의 자동 저장 여부를 본다.
 *
 * - 자동 저장 끄기: 기록을 멈추고 목록도 감춘다(저장된 검색어는 지우지 않는다). 회원 단위 하나다.
 * - 보관 개수: 기록·병합 뒤 범위마다 넘치는 옛 행을 지운다. 보관 기한은 개인정보 보유기간 작업이 지운다.
 * - 탈퇴·삭제: {@see self::purgeForUser()} 로 명시 삭제한다(FK CASCADE 에 기대지 않는다).
 *
 * @since 7.1.5
 */
class SearchHistoryService
{
    /** 검색어 최대 글자 수 */
    public const KEYWORD_MAX_LENGTH = 100;

    /** 한 번에 병합할 수 있는 최대 항목 수 */
    public const MERGE_MAX_ITEMS = 200;

    /**
     * @param  UserSearchHistoryRepositoryInterface  $histories  최근 검색어 저장소
     * @param  UserRepositoryInterface  $users  회원 저장소
     */
    public function __construct(
        private readonly UserSearchHistoryRepositoryInterface $histories,
        private readonly UserRepositoryInterface $users,
    ) {}

    /**
     * 검색어를 저장 모양으로 정규화합니다 — 앞뒤 공백 제거, 연속 공백(줄바꿈 포함)을 하나로, 최대 100자.
     *
     * @param  string  $keyword  입력 검색어
     * @return string 정규화한 검색어 (비면 빈 문자열)
     */
    public static function normalizeKeyword(string $keyword): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', $keyword);
        $trimmed = trim(is_string($collapsed) ? $collapsed : $keyword);

        return mb_substr($trimmed, 0, self::KEYWORD_MAX_LENGTH);
    }

    /**
     * 범위에 기록·표시를 할 수 있는지 판정합니다 (기능 켬 + 지금 선언된 범위).
     *
     * @param  string  $scope  검색 범위 id
     * @return bool 쓸 수 있으면 true
     */
    public function isScopeAvailable(string $scope): bool
    {
        return SearchScopes::enabled() && SearchScopes::isDeclared($scope);
    }

    /**
     * 회원의 자동 저장 여부를 반환합니다 (값이 없으면 켬).
     *
     * @param  User  $user  회원
     * @return bool 자동 저장 여부
     */
    public function autoSave(User $user): bool
    {
        return $user->search_history_auto_save !== false;
    }

    /**
     * 회원·범위의 최근 검색어 목록을 반환합니다.
     *
     * 기능이 꺼졌거나 범위가 선언되지 않았으면 `enabled=false` 와 빈 목록, 자동 저장을 껐으면 빈 목록(감춤)이다.
     *
     * @param  User  $user  회원
     * @param  string  $scope  검색 범위 id
     * @return array{enabled: bool, auto_save: bool, max_count: int, items: Collection<int, UserSearchHistory>} 목록과 상태
     */
    public function list(User $user, string $scope): array
    {
        $autoSave = $this->autoSave($user);
        $maxCount = SearchScopes::effective($scope)['max_count'];

        if (! $this->isScopeAvailable($scope)) {
            return ['enabled' => false, 'auto_save' => $autoSave, 'max_count' => $maxCount, 'items' => collect()];
        }

        return [
            'enabled' => true,
            'auto_save' => $autoSave,
            'max_count' => $maxCount,
            'items' => $autoSave ? $this->histories->latestForUser((int) $user->getKey(), $scope, $maxCount) : collect(),
        ];
    }

    /**
     * 검색어 하나를 기록합니다. 기능 꺼짐·범위 미선언·자동 저장 꺼짐·빈 검색어면 아무것도 하지 않습니다.
     *
     * @param  User  $user  회원
     * @param  string  $scope  검색 범위 id
     * @param  string  $keyword  검색어
     * @return UserSearchHistory|null 기록한 행 (기록하지 않았으면 null)
     */
    public function record(User $user, string $scope, string $keyword): ?UserSearchHistory
    {
        $keyword = self::normalizeKeyword($keyword);

        if ($keyword === '' || ! $this->autoSave($user) || ! $this->isScopeAvailable($scope)) {
            return null;
        }

        // 확장이 저장하지 않을 검색어를 거를 수 있다(예: 금칙어) — 빈 문자열이나 문자열이 아닌 값을 돌려주면 기록하지 않는다.
        $filtered = HookManager::applyFilters('core.search_history.filter_keyword', $keyword, $scope, $user);
        $keyword = is_string($filtered) ? self::normalizeKeyword($filtered) : '';

        if ($keyword === '') {
            return null;
        }

        $userId = (int) $user->getKey();

        $row = DB::transaction(function () use ($userId, $scope, $keyword) {
            $row = $this->histories->upsert($userId, $scope, $keyword, Carbon::now());
            $this->histories->pruneBeyond($userId, $scope, SearchScopes::effective($scope)['max_count']);

            return $row;
        });

        HookManager::doAction('core.search_history.after_record', $row, $user);

        return $row;
    }

    /**
     * 브라우저(비회원) 목록을 회원 목록에 병합합니다 — 검색 일시 순으로 넣어 같은 검색어는 하나로, 범위별 보관 개수로 자른다.
     *
     * 선언되지 않은 범위·빈 검색어는 건너뛴다. 자동 저장을 끈 회원이면 아무것도 넣지 않는다(목록을 지우는 일은 브라우저가 한다).
     * 미래 일시는 지금으로 맞춘다.
     *
     * @param  User  $user  회원
     * @param  array<int, array{scope: string, keyword: string, searched_at?: string|null}>  $items  병합할 항목
     * @return int 넣은 항목 수
     */
    public function merge(User $user, array $items): int
    {
        if (! $this->autoSave($user) || ! SearchScopes::enabled()) {
            return 0;
        }

        $declared = SearchScopes::keys();
        $now = Carbon::now();
        $prepared = [];

        foreach (array_slice($items, 0, self::MERGE_MAX_ITEMS) as $item) {
            $scope = (string) ($item['scope'] ?? '');
            $keyword = self::normalizeKeyword((string) ($item['keyword'] ?? ''));

            if ($keyword === '' || ! in_array($scope, $declared, true)) {
                continue;
            }

            $prepared[] = [
                'scope' => $scope,
                'keyword' => $keyword,
                'searched_at' => $this->parseSearchedAt($item['searched_at'] ?? null, $now),
            ];
        }

        if ($prepared === []) {
            return 0;
        }

        usort($prepared, fn (array $a, array $b) => $a['searched_at']->getTimestamp() <=> $b['searched_at']->getTimestamp());

        $userId = (int) $user->getKey();

        return DB::transaction(function () use ($userId, $prepared) {
            $scopes = [];
            foreach ($prepared as $item) {
                $this->histories->upsert($userId, $item['scope'], $item['keyword'], $item['searched_at']);
                $scopes[$item['scope']] = true;
            }

            foreach (array_keys($scopes) as $scope) {
                $this->histories->pruneBeyond($userId, $scope, SearchScopes::effective($scope)['max_count']);
            }

            return count($prepared);
        });
    }

    /**
     * 회원의 검색어 한 건을 지웁니다.
     *
     * @param  User  $user  회원
     * @param  int  $id  검색어 ID
     * @return bool 지웠으면 true, 없거나 다른 회원의 것이면 false
     */
    public function delete(User $user, int $id): bool
    {
        $row = $this->histories->findForUser((int) $user->getKey(), $id);

        return $row !== null && $this->histories->delete($row);
    }

    /**
     * 회원의 검색어를 모두 지웁니다 (범위를 주면 그 범위만).
     *
     * @param  User  $user  회원
     * @param  string|null  $scope  검색 범위 id (null 이면 전 범위)
     * @return int 지운 건수
     */
    public function clear(User $user, ?string $scope = null): int
    {
        return $this->histories->deleteByUser((int) $user->getKey(), $scope);
    }

    /**
     * 회원의 자동 저장 여부를 바꿉니다.
     *
     * @param  User  $user  회원
     * @param  bool  $autoSave  자동 저장 여부
     * @return bool 바뀐 값
     */
    public function setAutoSave(User $user, bool $autoSave): bool
    {
        $this->users->update($user, ['search_history_auto_save' => $autoSave]);
        $user->search_history_auto_save = $autoSave;

        return $autoSave;
    }

    /**
     * 탈퇴·삭제 회원의 검색어를 모두 지웁니다 (명시적 삭제 — CASCADE 의존 금지).
     *
     * @param  User  $user  회원
     * @return int 지운 건수
     */
    public function purgeForUser(User $user): int
    {
        return $this->histories->deleteByUser((int) $user->getKey());
    }

    /**
     * 관리자 회원 상세에 보일 최근 검색어를 범위별로 모읍니다.
     *
     * 회원 정보 열람이라 사이트가 기능을 껐거나 회원이 자동 저장을 꺼도 **저장된 검색어는 그대로** 보인다 — 끄기는
     * 기록을 멈출 뿐 지우지 않는다. 지금 선언된 범위만 싣는다: 확장을 끄면 그 범위는 빠지고 행은 남는다(환경설정
     * 카드와 같은 규칙). 범위마다 설정 상한(50)까지 최신순이다.
     *
     * @param  User  $user  회원
     * @return array{enabled: bool, auto_save: bool, scopes: array<string, array{owner: string, label: string, items: Collection<int, UserSearchHistory>}>} 범위별 검색어
     */
    public function overviewForAdmin(User $user): array
    {
        $scopes = [];
        foreach (SearchScopes::labeled() as $scope => $display) {
            $scopes[$scope] = $display + [
                'items' => $this->histories->latestForUser((int) $user->getKey(), $scope, SearchScopes::maxCountLimit()),
            ];
        }

        return [
            'enabled' => SearchScopes::enabled(),
            'auto_save' => $this->autoSave($user),
            'scopes' => $scopes,
        ];
    }

    /**
     * 관리자 환경설정 응답의 `_meta.search_scopes` 를 만듭니다 (소유 확장 이름은 설치 기록에서 읽는다).
     *
     * @return array<int, array{scope: string, owner: string, owner_label: string, label: string, default_max_count: int, default_retention_days: int}> 범위 메타 목록
     */
    public function settingsMeta(): array
    {
        return SearchScopes::forSettingsMeta(fn (string $owner): string => $this->ownerLabel($owner));
    }

    /**
     * 소유자 키의 사람이 읽는 이름을 반환합니다 (설치 기록의 다국어 이름 → 식별자).
     *
     * @param  string  $owner  소유자 키 (`core`·`module:{id}`·`plugin:{id}`)
     * @return string 이름
     */
    private function ownerLabel(string $owner): string
    {
        if ($owner === SearchScopes::CORE_OWNER) {
            return (string) __('search.history.owner_core');
        }

        [$type, $identifier] = array_pad(explode(':', $owner, 2), 2, '');

        try {
            $record = match ($type) {
                'module' => app(ModuleRepositoryInterface::class)->findByIdentifier($identifier),
                'plugin' => app(PluginRepositoryInterface::class)->findByIdentifier($identifier),
                default => null,
            };

            $name = $record?->getLocalizedName();
            if (is_string($name) && $name !== '') {
                return $name;
            }
        } catch (\Throwable $e) {
            Log::warning('최근 검색어 범위 소유 확장 이름 조회 실패', ['owner' => $owner, 'error' => $e->getMessage()]);
        }

        return $identifier !== '' ? $identifier : $owner;
    }

    /**
     * 병합 항목의 검색 일시를 읽습니다 (못 읽거나 미래면 지금).
     *
     * @param  mixed  $value  ISO 8601 문자열 또는 밀리초 정수
     * @param  Carbon  $now  지금
     * @return Carbon 검색 일시
     */
    private function parseSearchedAt(mixed $value, Carbon $now): Carbon
    {
        try {
            $at = match (true) {
                is_int($value) => Carbon::createFromTimestampMs($value),
                is_string($value) && $value !== '' => Carbon::parse($value),
                default => null,
            };
        } catch (\Throwable) {
            $at = null;
        }

        if ($at === null || $at->gt($now)) {
            return $now->copy();
        }

        return $at->setTimezone($now->getTimezone());
    }
}

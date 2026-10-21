<?php

namespace Modules\Sirsoft\Board\Support;

use App\Extension\HookManager;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Board\Models\Board;

/**
 * 게시글 목록 쿼리 확장 조건 묶음
 *
 * 확장이 `sirsoft-board.post.list_query_extensions` 필터로 돌려준 조건 함수(`fn (Builder $query, array $context)`)를
 * 목록 구분값별로 모아 저장소에 넘기는 값 객체다. 훅 발행은 서비스·리스너·SEO 기여자에서 {@see self::collect()} 로만
 * 하고, 저장소는 받은 객체로 {@see self::apply()} 만 부른다 — 저장소는 훅을 모른다(코어 회원 목록과 같은 구조).
 *
 * 조건은 게시판이 자기 조건(게시판·공지·원글·권한 범위·삭제·필터·검색)을 다 건 뒤에 붙는다. 그래서 확장은
 * 결과를 좁히기만 한다. 같은 축으로 캐시를 나눌 때는 {@see self::cacheSuffix()} 로 키 접미사를 얻는다.
 */
final class PostQueryExtensions
{
    /**
     * 목록 쿼리 확장 필터 훅 이름 (리스너 유무 확인용 — 발행은 리터럴 호출 한 곳)
     */
    private const HOOK = 'sirsoft-board.post.list_query_extensions';

    /**
     * @param  array<string, array<int, callable>>  $closures  구분값별 조건 함수
     * @param  array<string, array<string, mixed>>  $payloads  구분값별로 조건 함수에 넘길 맥락
     */
    private function __construct(
        private readonly array $closures = [],
        private readonly array $payloads = [],
    ) {}

    /**
     * 조건이 하나도 없는 묶음을 만듭니다 (리스너 없음과 같다).
     *
     * @return self 빈 묶음
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * 구분값마다 목록 쿼리 확장 훅을 한 번씩 발행해 조건 함수를 모읍니다.
     *
     * 리스너가 하나도 없으면 훅을 부르지 않고 빈 묶음을 돌려준다 — 게시판 조회·맥락 조립 비용도 들지 않는다.
     * 리스너는 받은 배열에 조건 함수를 더해 돌려준다. 호출할 수 없는 원소는 버리고 경고를 남긴다.
     *
     * @param  array<int, string>  $contexts  목록 구분값 (list, notice, reply, thread, count, adjacent, search, search_across,
     *                                        recent, popular, board_recent, user_activity, user_stats, admin_dashboard, sitemap)
     * @param  array<string, mixed>  $meta  맥락 재료 — audience(user|admin), board(Board|Closure|null — 클로저면 리스너가 있을 때만
     *                                      불러 게시판을 얻는다), board_ids(?list<int>), subject_user_id(?int)
     * @return self 구분값별 조건 묶음
     */
    public static function collect(array $contexts, array $meta = []): self
    {
        // 우선순위별 콜백 묶음 중 하나라도 남아 있어야 리스너가 있는 것이다(걷어낸 훅은 빈 묶음이 남는다)
        if (array_filter(HookManager::getFilters()[self::HOOK] ?? []) === []) {
            return self::none();
        }

        $base = self::basePayload($meta);
        $closures = [];
        $payloads = [];

        foreach ($contexts as $context) {
            $payload = ['context' => $context] + $base;
            $returned = HookManager::applyFilters('sirsoft-board.post.list_query_extensions', [], $payload);

            if (! is_array($returned)) {
                Log::warning('sirsoft-board: list_query_extensions 리스너가 배열이 아닌 값을 돌려줘 무시합니다.', [
                    'context' => $context,
                    'type' => get_debug_type($returned),
                ]);
                $returned = [];
            }

            $callables = [];
            foreach ($returned as $item) {
                if (is_callable($item)) {
                    $callables[] = $item;

                    continue;
                }

                Log::warning('sirsoft-board: list_query_extensions 리스너가 호출할 수 없는 조건을 돌려줘 버립니다.', [
                    'context' => $context,
                    'type' => get_debug_type($item),
                ]);
            }

            if ($callables !== []) {
                $closures[$context] = $callables;
                $payloads[$context] = $payload;
            }
        }

        return new self($closures, $payloads);
    }

    /**
     * 한 구분값의 조건 함수를 쿼리에 차례로 적용합니다. 없으면 아무 일도 하지 않습니다.
     *
     * @param  string  $context  목록 구분값
     * @param  Builder  $query  코어 조건을 다 건 게시글 쿼리
     * @return void 반환값 없음
     */
    public function apply(string $context, Builder $query): void
    {
        foreach ($this->closures[$context] ?? [] as $closure) {
            $closure($query, $this->payloads[$context]);
        }
    }

    /**
     * 적용할 조건이 하나도 없는지 확인합니다.
     *
     * @return bool 조건이 없으면 true
     */
    public function isEmpty(): bool
    {
        return $this->closures === [];
    }

    /**
     * 목록 캐시 키에 붙일 확장 축 접미사를 구합니다.
     *
     * `sirsoft-board.post.filter_list_cache_variant` 필터가 돌려준 스칼라 목록으로 접미사를 만든다. 목록이 비면 빈 문자열이라
     * 리스너가 없을 때 키는 지금과 같다(업데이트 직후 캐시를 버리지 않는다). 스칼라가 아닌 원소는 버린다.
     *
     * @param  string  $cache  캐시 이름 (normal_count, adjacent, user_activities_total, user_activity_stats, user_public_stats,
     *                         recent, popular)
     * @param  array<string, mixed>  $meta  맥락 재료 — board_id(?int), audience(user|admin)
     * @return string 접미사 (`_ev{md5}`) 또는 빈 문자열
     */
    public static function cacheSuffix(string $cache, array $meta = []): string
    {
        $parts = HookManager::applyFilters('sirsoft-board.post.filter_list_cache_variant', [], [
            'cache' => $cache,
            'board_id' => $meta['board_id'] ?? null,
            'audience' => $meta['audience'] ?? 'user',
            'user' => Auth::user(),
            'locale' => app()->getLocale(),
        ]);

        if (! is_array($parts)) {
            return '';
        }

        $parts = array_values(array_filter($parts, fn ($part) => is_string($part) || is_int($part)));

        return $parts === [] ? '' : '_ev'.md5(implode('|', $parts));
    }

    /**
     * 구분값을 뺀 공통 맥락을 만듭니다.
     *
     * @param  array<string, mixed>  $meta  맥락 재료
     * @return array<string, mixed> 공통 맥락 (audience, board, board_ids, user, locale, subject_user_id)
     */
    private static function basePayload(array $meta): array
    {
        // 게시판 조회는 호출한 서비스가 넘긴 클로저로만 한다 — 리스너가 없으면 이 지점에 오지 않아 조회도 없다
        $board = $meta['board'] ?? null;
        if ($board instanceof Closure) {
            $board = $board();
        }
        if (! $board instanceof Board) {
            $board = null;
        }

        return [
            'audience' => ($meta['audience'] ?? 'user') === 'admin' ? 'admin' : 'user',
            'board' => $board,
            'board_ids' => isset($meta['board_ids']) ? array_values(array_map('intval', (array) $meta['board_ids'])) : null,
            'user' => Auth::user(),
            'locale' => app()->getLocale(),
            'subject_user_id' => isset($meta['subject_user_id']) ? (int) $meta['subject_user_id'] : null,
        ];
    }
}

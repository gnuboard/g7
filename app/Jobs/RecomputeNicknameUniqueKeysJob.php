<?php

namespace App\Jobs;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\UserRepositoryInterface;
use App\Services\NicknamePolicyService;
use App\Support\NicknameNormalizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * 회원 닉네임의 비교 컬럼(정규화 값, 중복 키)을 다시 계산하는 큐 작업.
 *
 * 닉네임 중복 금지를 켜거나 끌 때, 그리고 업그레이드 뒤 정규화 값이 비어 있는 기존 회원을 채울 때 돈다.
 * 중복 금지가 켜져 있으면 한 회원만 쓰는 닉네임에만 중복 키를 채운다 — 이미 겹치는 닉네임은 키를 비워
 * 그대로 두고, 그 회원이 닉네임을 바꾸는 시점부터 막는다.
 *
 * 여러 번 돌아도 결과가 같다. 진행 상태는 캐시에 남겨 관리자 화면이 읽는다.
 *
 * @since 7.2.0
 */
class RecomputeNicknameUniqueKeysJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** 진행 상태 캐시 키 */
    public const PROGRESS_CACHE_KEY = 'core:member:nickname_recompute';

    /** 진행 상태를 남겨 두는 시간(초) */
    public const PROGRESS_TTL_SECONDS = 86400;

    /** 한 번에 읽는 회원 수 */
    public const CHUNK_SIZE = 500;

    /**
     * @var int 타임아웃 (초) — 회원이 많은 사이트에 대비해 넉넉히 둔다
     */
    public int $timeout = 1800;

    /**
     * @var int 같은 작업의 중복 접수를 막는 시간 (초)
     */
    public int $uniqueFor = 1800;

    /**
     * @var CacheInterface|null 진행 상태를 남길 캐시 (실행 중에만 채워진다)
     */
    private ?CacheInterface $cache = null;

    /**
     * 진행 상태를 읽습니다.
     *
     * @return array{status: string, processed: int, total: int, unique: bool|null, started_at: string|null, finished_at: string|null} 진행 상태 (돈 적 없으면 status `idle`)
     */
    public static function progress(): array
    {
        $progress = app(CacheInterface::class)->get(self::PROGRESS_CACHE_KEY);

        return is_array($progress) ? $progress : [
            'status' => 'idle', 'processed' => 0, 'total' => 0, 'unique' => null, 'started_at' => null, 'finished_at' => null,
        ];
    }

    /**
     * 작업을 실행합니다.
     *
     * @param  UserRepositoryInterface  $users  회원 저장소
     * @param  NicknamePolicyService  $policy  닉네임 정책
     * @param  CacheInterface  $cache  코어 캐시 (진행 상태)
     * @return void
     */
    public function handle(UserRepositoryInterface $users, NicknamePolicyService $policy, CacheInterface $cache): void
    {
        $this->cache = $cache;
        $unique = $policy->uniqueEnabled();
        $total = $users->countMembersWithNickname();
        $startedAt = now()->toIso8601String();
        $processed = 0;

        // 두 번 훑는다(정규화 값 → 중복 키). 진행 수는 두 번을 합쳐 센다.
        $this->report('running', 0, $total * 2, $unique, $startedAt);

        // 1) 정규화 값 — 닉네임 글자에서 다시 계산해 다른 것만 고친다.
        $afterId = 0;
        do {
            $rows = $users->nicknameRowsAfter($afterId, self::CHUNK_SIZE);
            foreach ($rows as $row) {
                $normalized = NicknameNormalizer::normalize($row->nickname);
                if ($row->nickname_normalized !== $normalized) {
                    // 정규화 값이 바뀌면 옛 중복 키는 믿을 수 없다 — 다음 단계가 다시 채운다.
                    $users->updateNicknameKeys((int) $row->id, ['nickname_normalized' => $normalized, 'nickname_unique_key' => null]);
                }
                $afterId = (int) $row->id;
            }
            $processed += $rows->count();
            $this->report('running', $processed, $total * 2, $unique, $startedAt);
        } while ($rows->count() === self::CHUNK_SIZE);

        // 2) 중복 키 — 꺼져 있으면 전부 비우고, 켜져 있으면 한 회원만 쓰는 닉네임에만 채운다.
        if (! $unique) {
            $users->clearNicknameUniqueKeys();
        } else {
            $afterId = 0;
            do {
                $rows = $users->nicknameRowsAfter($afterId, self::CHUNK_SIZE);
                $duplicated = array_flip($users->duplicatedNicknameNormalized($rows->pluck('nickname_normalized')->filter()->all()));
                foreach ($rows as $row) {
                    $key = $row->nickname_normalized !== null && ! isset($duplicated[$row->nickname_normalized])
                        ? $row->nickname_normalized
                        : null;
                    if ($row->nickname_unique_key !== $key) {
                        $users->updateNicknameKeys((int) $row->id, ['nickname_unique_key' => $key]);
                    }
                    $afterId = (int) $row->id;
                }
                $processed += $rows->count();
                $this->report('running', $processed, $total * 2, $unique, $startedAt);
            } while ($rows->count() === self::CHUNK_SIZE);
        }

        $this->report('done', $total * 2, $total * 2, $unique, $startedAt, now()->toIso8601String());
    }

    /**
     * 진행 상태를 캐시에 남깁니다.
     *
     * @param  string  $status  상태 (running, done)
     * @param  int  $processed  처리한 수
     * @param  int  $total  전체 수
     * @param  bool  $unique  이번 실행이 본 중복 금지 설정
     * @param  string  $startedAt  시작 시각 (ISO 8601)
     * @param  string|null  $finishedAt  끝난 시각 (ISO 8601)
     * @return void
     */
    private function report(string $status, int $processed, int $total, bool $unique, string $startedAt, ?string $finishedAt = null): void
    {
        $this->cache?->put(self::PROGRESS_CACHE_KEY, [
            'status' => $status,
            'processed' => min($processed, $total),
            'total' => $total,
            'unique' => $unique,
            'started_at' => $startedAt,
            'finished_at' => $finishedAt,
        ], self::PROGRESS_TTL_SECONDS);
    }
}

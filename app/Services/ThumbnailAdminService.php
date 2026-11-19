<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\HookManager;
use App\Support\ExecutionEnvironment;
use App\Support\LocalizedValue;
use App\Thumbnails\DTO\ThumbnailSpec;
use App\Thumbnails\ThumbnailPregenerator;
use App\Thumbnails\ThumbnailPresetRegistry;
use App\Thumbnails\ThumbnailPurgeBuffer;
use App\Thumbnails\ThumbnailSourceProviders;
use App\Thumbnails\ThumbnailStore;
use Illuminate\Support\Facades\Lang;

/**
 * 썸네일 캐시 관리 서비스 (환경설정 > 업로드 탭 「저장 위치 · 캐시」)
 *
 * 저장 위치는 원본 분류(공개·권한)별 두 줄이다. 두 분류가 같은 디스크를 써도 한 줄로 합치지 않고
 * 원본 제공자 판정으로 따로 세고 따로 지운다 — 공개만 비웠는데 권한 이미지 썸네일까지 사라지면 안 된다.
 */
class ThumbnailAdminService
{
    /** @var list<string> 저장 위치 종류 (화면 표시 순서) */
    public const LOCATIONS = [ThumbnailStore::KIND_PUBLIC, ThumbnailStore::KIND_GATED];

    /** 두 저장 위치 모두 */
    public const LOCATION_ALL = 'all';

    /** 사용량 캐시 유지 시간(초) — 업로드 탭을 열 때마다 디스크 전체를 훑지 않게 하되, 숫자가 오래 어긋나지는 않게 짧게 둔다 */
    private const STATS_CACHE_TTL = 60;

    /**
     * ThumbnailAdminService 생성자
     *
     * @param  ThumbnailStore  $store  파생본 저장소
     * @param  ThumbnailPurgeBuffer  $purgeBuffer  퍼지 묶음
     * @param  ThumbnailPregenerator  $pregenerator  미리 만들기
     * @param  ThumbnailPresetRegistry  $registry  규격 레지스트리
     * @param  ThumbnailSourceProviders  $providers  원본 제공자
     * @param  DriverRegistryService  $drivers  드라이버 카탈로그 (디스크 라벨)
     * @param  CacheInterface  $cache  코어 캐시 (사용량)
     */
    public function __construct(
        private readonly ThumbnailStore $store,
        private readonly ThumbnailPurgeBuffer $purgeBuffer,
        private readonly ThumbnailPregenerator $pregenerator,
        private readonly ThumbnailPresetRegistry $registry,
        private readonly ThumbnailSourceProviders $providers,
        private readonly DriverRegistryService $drivers,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 저장 위치별 사용량과 화면 선택지
     *
     * 로컬 디스크만 파일 수·용량을 센다. 원격 디스크(S3·CDN)는 목록 조회 비용 때문에 `remote: true` 에 null 이다.
     * 파일 수가 원본 수 × 규격 수로 늘어 전체 순회가 비싸므로 집계는 짧게 캐시하고, 비우기·미리 만들기 뒤에 지운다.
     * 키에 디스크 이름을 넣어 저장 위치를 바꾸면 옛 집계를 보지 않는다. 명령으로 만들거나 지운 변화는 유지 시간이 지나면 보인다.
     *
     * @return array{locations: list<array<string, mixed>>, presets: list<string>, sources: list<array{key: string, label: string}>} 사용량
     */
    public function stats(): array
    {
        // 두 위치가 같은 디스크를 쓰면 그 디스크를 한 번만 훑는다(statsAll)
        $locations = array_map(
            fn (array $stats) => $stats + ['disk_label' => $this->diskLabel($stats['disk'])],
            $this->cache->remember($this->statsCacheKey(), fn () => $this->store->statsAll(), self::STATS_CACHE_TTL)
        );

        return [
            'locations' => $locations,
            'presets' => $this->presetNames(),
            'sources' => array_values(array_map(
                fn ($provider) => ['key' => $provider->key(), 'label' => (string) __($provider->label())],
                $this->providers->all()
            )),
        ];
    }

    /**
     * 저장 위치 하나(또는 둘 다)의 썸네일을 한 걸음 지웁니다. 화면은 `next_cursor` 가 null 이 될 때까지 부른다.
     *
     * 같은 디스크를 나눠 쓰는 위치나 규격 하나만 지울 때는 파일마다 판정해야 해서, 한 요청이 파일 전체를 훑지 않게
     * 시간 예산만큼만 걷고 커서를 돌려준다. 커서는 `{위치 순번}:{마지막으로 본 파생본 키의 base64url}` 이다
     * (위치 순번은 `all` 일 때 public 0·gated 1, 한 위치면 0). 시작 훅 `core.thumbnail.before_purge` 는 첫 걸음에만
     * 발화한다. 지운 접두는 퍼지 묶음으로 모아 요청 끝에 `core.thumbnail.after_purge` 를 한 번 발화한다.
     *
     * @param  string  $location  public·gated·all
     * @param  string|null  $preset  규격 이름 (null 이면 전 규격)
     * @param  string|null  $cursor  이전 걸음의 next_cursor (처음이면 null)
     * @param  float|null  $timeBudget  이번 걸음의 시간 예산(초, null 이면 업로드 탭 「고급」 `core.thumbnail.purge_time_budget` — 시험이 좁혀 쓴다)
     * @return array{deleted: array<string, int>, next_cursor: ?string} 이번 걸음에 위치별로 지운 파일 수(디스크를 통째로 비우면 -1)와 다음 커서
     */
    public function purge(string $location, ?string $preset = null, ?string $cursor = null, ?float $timeBudget = null): array
    {
        $kinds = $location === self::LOCATION_ALL ? self::LOCATIONS : [$location];
        [$index, $after] = $this->parsePurgeCursor($cursor, count($kinds));
        $budget = $timeBudget ?? $this->timeBudget((float) config('core.thumbnail.purge_time_budget', 10));
        $startedAt = microtime(true);

        if ($cursor === null) {
            HookManager::doAction('core.thumbnail.before_purge', $location, $preset);
        }

        $result = $this->purgeBuffer->batch(function () use ($kinds, $preset, $index, $after, $budget, $startedAt) {
            $deleted = [];
            $next = null;

            for ($i = $index; $i < count($kinds); $i++) {
                $elapsed = microtime(true) - $startedAt;

                // 앞 위치에서 예산을 다 썼으면 다음 위치는 다음 걸음에 처음부터
                if ($i > $index && $elapsed >= $budget) {
                    $next = $i.':';
                    break;
                }

                $step = $this->store->purgeLocationStep($kinds[$i], $preset, $i === $index ? $after : null, max(0.0, $budget - $elapsed));
                $deleted[$kinds[$i]] = $step['deleted'];

                if ($step['next_cursor'] !== null) {
                    $next = $i.':'.rtrim(strtr(base64_encode($step['next_cursor']), '+/', '-_'), '=');
                    break;
                }
            }

            return ['deleted' => $deleted, 'next_cursor' => $next];
        });

        $this->cache->forget($this->statsCacheKey());

        return $result;
    }

    /**
     * 비우기 커서를 위치 순번과 마지막으로 본 파생본 키로 나눕니다 — 형식이 틀리면 처음부터(비우기는 다시 걸어도 같다).
     *
     * @param  string|null  $cursor  커서
     * @param  int  $locations  위치 수
     * @return array{0: int, 1: ?string} [위치 순번, 파생본 키]
     */
    private function parsePurgeCursor(?string $cursor, int $locations): array
    {
        if ($cursor === null || preg_match('/^(\d):([A-Za-z0-9_-]*)$/', $cursor, $matches) !== 1 || (int) $matches[1] >= $locations) {
            return [0, null];
        }

        $decoded = $matches[2] === '' ? null : base64_decode(strtr($matches[2], '-_', '+/'), true);

        return [(int) $matches[1], is_string($decoded) && $decoded !== '' ? $decoded : null];
    }

    /**
     * 「기존 이미지 썸네일 만들기」 한 번분을 처리합니다. 화면은 `next_cursor` 가 null 이 될 때까지 부른다.
     *
     * @param  string|null  $cursor  이전 호출의 next_cursor (처음이면 null)
     * @param  list<string>  $sources  원본 제공자 키 (빈 목록이면 전부)
     * @param  list<string>  $presets  규격 이름 (빈 목록이면 사용 중인 규격 전부)
     * @return array{processed: int, generated: int, next_cursor: ?string, total: int} 진행 결과
     */
    public function pregenerate(?string $cursor, array $sources, array $presets): array
    {
        $result = $this->pregenerator->batch(
            $cursor,
            $sources,
            $presets,
            max(1, (int) config('core.thumbnail.pregenerate_batch', 20)),
            timeBudget: $this->timeBudget((float) config('core.thumbnail.pregenerate_time_budget', 15))
        );

        $this->cache->forget($this->statsCacheKey());

        return $result;
    }

    /**
     * 사용량 캐시 키 — 저장 위치별 디스크 이름을 넣는다.
     *
     * @return string 캐시 키
     */
    private function statsCacheKey(): string
    {
        return 'thumbnail.stats.'.md5(implode('|', array_map($this->store->diskForKind(...), self::LOCATIONS)));
    }

    /**
     * 요청 하나에 쓸 시간 예산(초) — 서버의 `max_execution_time` 이 더 짧으면 그 절반.
     *
     * @param  float  $configured  설정된 예산(초)
     * @return float 시간 예산
     */
    private function timeBudget(float $configured): float
    {
        $budget = max(0.0, $configured);
        $limit = ExecutionEnvironment::hasFunction('ini_get') ? (int) ini_get('max_execution_time') : 0;

        // audit:allow service-config-value-clamp reason: 입력 상한이 아니라 서버 실행 시간과 맞추는 계산이다 — 절반은 원본 하나분의 여유
        return $limit > 0 ? min($budget, $limit / 2) : $budget;
    }

    /**
     * 규격 이름 전부 (꺼진 규격 포함 — 꺼 둔 규격의 파일도 비울 수 있어야 한다)
     *
     * @return list<string> 규격 이름
     */
    public function presetNames(): array
    {
        return array_values(array_map(fn (ThumbnailSpec $spec) => $spec->name, $this->registry->all()));
    }

    /**
     * 원본 제공자 키 전부
     *
     * @return list<string> 키
     */
    public function sourceKeys(): array
    {
        return array_keys($this->providers->all());
    }

    /**
     * 디스크 표시 이름 — 썸네일 전용 디스크는 `thumbnail.disks.*`, 그 밖은 공개 자산 디스크 카탈로그 라벨, 둘 다 없으면 디스크 이름.
     *
     * @param  string  $disk  디스크 이름
     * @return string 표시 이름
     */
    private function diskLabel(string $disk): string
    {
        $key = "thumbnail.disks.{$disk}";

        if (Lang::has($key)) {
            return (string) __($key);
        }

        foreach ($this->drivers->getAvailableDrivers('public_asset') as $driver) {
            if (($driver['id'] ?? null) !== $disk) {
                continue;
            }

            $label = $driver['label'] ?? null;

            if (is_array($label)) {
                $label = LocalizedValue::pick($label);
            }

            if (is_string($label) && $label !== '') {
                return $label;
            }
        }

        return $disk;
    }
}

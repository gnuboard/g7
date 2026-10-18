<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Extension\CoreVersionChecker;
use App\Services\Core\BundledInventory;
use App\Services\Core\BundledSourcePlanner;

/**
 * 미배치 공식 번들 안내 서비스 (공개 #146).
 *
 * 코어 업데이트(또는 core:bundled-sources)가 남긴 기록 파일에서 「코어 배포본에 있지만 이 프로젝트에
 * 배치하지 않은 공식 번들」을 유형별로 골라 관리자 목록 화면 안내의 데이터 소스로 쓴다.
 *
 * 필터: 기록의 코어 버전 ≠ 현재 버전이면 비움, 숨김(샘플) 제외, 지금 디스크에 생긴 것·설치된 것 제외,
 * 이 사용자가 같은 해시로 닫은 유형 제외. 닫기는 관리자별로 `{type}:{hash}` 를 30일 캐시에 남긴다 —
 * 새 기록(해시 변경)이면 다시 보인다. 같은 구조의 `ExtensionCompatibilityAlertService` 를 일반화하지 않고
 * 따로 둔다(닫기 키와 재표시 규칙이 다르다).
 *
 * 캐시 키: `ext.unplaced_bundled_dismissed.{userId}`
 */
class UnplacedBundledNoticeService
{
    /** 사용자별 닫기 이력 캐시 키 접두사 */
    private const DISMISS_CACHE_PREFIX = 'ext.unplaced_bundled_dismissed.';

    /** 닫기 이력 보존 기간(초) */
    private const DISMISS_CACHE_TTL = 86400 * 30;

    /** 유형 → 응답 키 */
    public const TYPE_KEYS = [
        'module' => 'modules',
        'plugin' => 'plugins',
        'template' => 'templates',
        'language_pack' => 'language_packs',
    ];

    /**
     * @param  CacheInterface  $cache  닫기 이력 저장소
     * @param  BundledSourcePlanner  $planner  기록 파일·디스크·설치 판정
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly BundledSourcePlanner $planner,
    ) {}

    /**
     * 사용자에게 보일 미배치 공식 번들 안내를 유형별로 돌려줍니다.
     *
     * @param  int|null  $userId  관리자 ID (닫기 이력 조회)
     * @param  array<int, string>  $allowedTypes  조회 권한이 있는 유형 (module|plugin|template|language_pack)
     * @return array{items: array<string, array<int, array{identifier: string, name: string, version: string}>>, hash: string|null, core_version: string, command: string, commands: array<string, string>}
     */
    public function noticeFor(?int $userId, array $allowedTypes): array
    {
        $current = CoreVersionChecker::getCoreVersion();
        $items = array_fill_keys(array_values(self::TYPE_KEYS), []);
        $result = ['items' => $items, 'hash' => null, 'core_version' => $current, 'command' => 'php artisan core:bundled-sources place', 'commands' => []];

        $record = $this->planner->readRecord();
        if ($record === null || ($record['core_version'] ?? null) !== $current) {
            return $result;
        }

        $hash = (string) ($record['hash'] ?? BundledSourcePlanner::hashUnplaced($record['unplaced']));
        $result['hash'] = $hash;

        $dismissed = array_flip($this->getDismissed($userId));
        $installed = $this->planner->installedKeys();
        $identifierCount = array_count_values(array_map(fn (array $item) => (string) $item['identifier'], $record['unplaced']));

        foreach ($record['unplaced'] as $item) {
            $type = (string) $item['type'];
            $identifier = (string) $item['identifier'];
            if (! in_array($type, $allowedTypes, true) || ! empty($item['hidden'])) {
                continue;
            }
            if (isset($dismissed[$type.':'.$hash])) {
                continue;
            }
            if (isset($installed[BundledInventory::key($type, $identifier)]) || $this->planner->isOnDisk($type, $identifier)) {
                continue;
            }

            $items[self::TYPE_KEYS[$type]][] = [
                'identifier' => $identifier,
                'name' => $this->localizedName($item['name'] ?? $identifier, $identifier),
                'version' => (string) ($item['version'] ?? ''),
                'argument' => ($identifierCount[$identifier] ?? 0) > 1 ? $type.':'.$identifier : $identifier,
            ];
        }

        $commands = [];
        foreach ($items as $key => $list) {
            if ($list !== []) {
                $commands[$key] = $result['command'].' '.implode(' ', array_column($list, 'argument'));
            }
            $items[$key] = array_map(fn (array $row) => array_diff_key($row, ['argument' => true]), $list);
        }

        $result['items'] = $items;
        $result['commands'] = $commands;

        return $result;
    }

    /**
     * 한 유형의 안내를 현재 기록 해시로 닫습니다(관리자별).
     *
     * @param  int|null  $userId  관리자 ID (없으면 무시)
     * @param  string  $type  module|plugin|template|language_pack
     * @return string|null 닫은 해시 (기록이 없으면 null)
     */
    public function dismiss(?int $userId, string $type): ?string
    {
        if (! $userId) {
            return null;
        }

        $record = $this->planner->readRecord();
        if ($record === null) {
            return null;
        }

        $hash = (string) ($record['hash'] ?? BundledSourcePlanner::hashUnplaced($record['unplaced']));
        $entry = $type.':'.$hash;

        $key = self::DISMISS_CACHE_PREFIX.$userId;
        $dismissed = $this->getDismissed($userId);
        if (! in_array($entry, $dismissed, true)) {
            $dismissed[] = $entry;
            $this->cache->put($key, $dismissed, self::DISMISS_CACHE_TTL);
        }

        return $hash;
    }

    /**
     * 사용자의 닫기 이력(`{type}:{hash}` 목록)을 읽습니다.
     *
     * @param  int|null  $userId  관리자 ID
     * @return array<int, string> 닫기 이력
     */
    private function getDismissed(?int $userId): array
    {
        if (! $userId) {
            return [];
        }

        $cached = $this->cache->get(self::DISMISS_CACHE_PREFIX.$userId, []);

        return is_array($cached) ? array_values(array_filter($cached, 'is_string')) : [];
    }

    /**
     * 다국어 이름에서 현재 로케일 값을 고릅니다(없으면 ko → en → 첫 값 → 식별자).
     *
     * @param  mixed  $name  기록의 name (array 또는 string)
     * @param  string  $fallback  값이 없을 때 쓸 식별자
     * @return string 이름
     */
    private function localizedName(mixed $name, string $fallback): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }
        if (! is_array($name) || $name === []) {
            return $fallback;
        }

        $locale = app()->getLocale();
        $value = $name[$locale] ?? $name['ko'] ?? $name['en'] ?? reset($name);

        return is_string($value) && $value !== '' ? $value : $fallback;
    }
}

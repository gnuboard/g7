<?php

namespace App\Services\Core;

/**
 * 릴리스 배포본에 든 공식 번들 목록(릴리스 인벤토리)입니다.
 *
 * `{modules,plugins,templates}/_bundled/*` 와 `lang-packs/_bundled/*` 의 manifest 를 읽은 결과다.
 * 항목 키는 `{type}:{identifier}` 이고 type 은 module|plugin|template|language_pack 이다.
 * 코어는 번들 식별자를 이름으로 알지 않는다 — 모든 판정은 이 목록과 DB·디스크로만 한다.
 *
 * 항목 모양:
 *  - type, identifier, version, name(array|string), hidden(bool)
 *  - dependencies: ['modules' => string[], 'plugins' => string[]] (확장만)
 *  - scope, target_identifier (언어팩만)
 *  - path: base 상대경로 (예 `plugins/_bundled/{id}`)
 */
final class BundledInventory
{
    /**
     * @param  string|null  $coreVersion  배포본 config/app.php 의 코어 버전 (읽지 못하면 null)
     * @param  array<string, array<string, mixed>>  $entries  `{type}:{identifier}` => 항목
     * @param  string|null  $root  배포본 루트 디렉토리 (zip 에서 목록만 읽었으면 null)
     */
    public function __construct(
        public readonly ?string $coreVersion,
        private readonly array $entries,
        public readonly ?string $root = null,
    ) {}

    /**
     * 모든 항목을 돌려줍니다.
     *
     * @return array<string, array<string, mixed>> `{type}:{identifier}` => 항목
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * 항목 키로 항목을 찾습니다.
     *
     * @param  string  $key  `{type}:{identifier}`
     * @return array<string, mixed>|null 항목 또는 null
     */
    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    /**
     * 항목 키가 목록에 있는지 판정합니다.
     *
     * @param  string  $key  `{type}:{identifier}`
     * @return bool 있으면 true
     */
    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    /**
     * 식별자가 같은 항목의 키를 모두 찾습니다(유형 무관).
     *
     * @param  string  $identifier  확장·언어팩 식별자
     * @return array<int, string> 일치하는 항목 키 목록
     */
    public function keysForIdentifier(string $identifier): array
    {
        $keys = [];
        foreach ($this->entries as $key => $entry) {
            if ($entry['identifier'] === $identifier) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * 항목 키를 만듭니다.
     *
     * @param  string  $type  module|plugin|template|language_pack
     * @param  string  $identifier  식별자
     * @return string `{type}:{identifier}`
     */
    public static function key(string $type, string $identifier): string
    {
        return $type.':'.$identifier;
    }
}

<?php

namespace App\Analytics\DTO;

use App\Analytics\LocalizedText;
use Closure;

/**
 * 등록된 커스텀 차원 정의
 *
 * 확장이 `getAnalyticsDimensions()` 로 선언한 차원을 정규화한 결과입니다. 수집 경로는
 * 이 정의가 있는 차원 값만 원장에 남기고, 집계는 이 정의대로 `ext:{식별자}:{키}` 행을
 * 만듭니다.
 *
 * 원장에는 `{식별자}:{키}` 를 이름으로 씁니다 — 두 확장이 같은 짧은 키(`category_id`)를
 * 선언해도 값이 섞이지 않고, 확장을 데이터까지 지울 때 그 확장의 값만 골라낼 수 있습니다.
 */
final readonly class DimensionDefinition
{
    /**
     * 허용하는 범위
     *
     * @var array<int, string>
     */
    public const SCOPES = ['action', 'visit'];

    /**
     * 허용하는 값 종류
     *
     * @var array<int, string>
     */
    public const VALUE_TYPES = ['int', 'string', 'enum'];

    /**
     * 집계 라벨 수 기본 상한
     */
    public const DEFAULT_MAX_CARDINALITY = 500;

    /**
     * 집계 라벨 수 최대 상한
     *
     * 확장이 상한을 지나치게 크게 잡아 하루치 차원 표가 부풀지 않도록 코어가 자릅니다.
     */
    public const CARDINALITY_CEILING = 10000;

    /**
     * 차원 정의를 생성합니다.
     *
     * @param  string  $key  확장 안에서 고유한 키
     * @param  string  $ownerType  공급자 종류 (module, plugin)
     * @param  string  $ownerIdentifier  공급자 식별자
     * @param  string  $scope  범위 (action: 액션마다, visit: 방문에 한 번)
     * @param  array<string, string>  $label  로케일별 이름
     * @param  string  $valueType  값 종류
     * @param  array<int, string>  $values  enum 허용값
     * @param  int  $maxCardinality  하루 집계 라벨 수 상한
     * @param  bool  $pii  개인정보 여부 — 참이면 익명 모드에서 적재하지 않고 소급 익명화 때 지웁니다
     * @param  Closure|null  $labelResolver  원값 목록 → 표시 이름 맵
     */
    public function __construct(
        public string $key,
        public string $ownerType,
        public string $ownerIdentifier,
        public string $scope,
        public array $label,
        public string $valueType,
        public array $values,
        public int $maxCardinality,
        public bool $pii,
        public ?Closure $labelResolver = null,
    ) {}

    /**
     * 원장 `custom_dimensions` 에 쓰는 이름을 반환합니다.
     *
     * @return string `{식별자}:{키}`
     */
    public function storageKey(): string
    {
        return $this->ownerIdentifier.':'.$this->key;
    }

    /**
     * 차원 집계 표의 차원 이름을 반환합니다.
     *
     * @return string `ext:{식별자}:{키}`
     */
    public function statsDimension(): string
    {
        return 'ext:'.$this->storageKey();
    }

    /**
     * 현재 로케일의 이름을 반환합니다.
     *
     * @param  string|null  $locale  고를 로케일 (없으면 현재 앱 로케일)
     * @return string 이름 (지정 로케일 → en → 첫 값 → 키 순)
     */
    public function localizedLabel(?string $locale = null): string
    {
        return LocalizedText::pick($this->label, $this->storageKey(), $locale);
    }

    /**
     * 값이 선언된 종류에 맞는지 확인합니다.
     *
     * @param  string  $value  전송된 값
     * @return bool 맞으면 true
     */
    public function accepts(string $value): bool
    {
        return match ($this->valueType) {
            'int' => filter_var($value, FILTER_VALIDATE_INT) !== false,
            'enum' => in_array($value, $this->values, true),
            default => $value !== '',
        };
    }

    /**
     * 원값을 표시 이름으로 풉니다.
     *
     * 확장의 풀이 함수가 던지거나 모르는 값을 돌려주면 원값을 그대로 둡니다 — 리포트가
     * 확장 하나의 실패로 통째로 깨지면 안 됩니다.
     *
     * @param  array<int, string>  $rawValues  원값 목록
     * @return array<string, string> 원값 → 표시 이름
     */
    public function resolveLabels(array $rawValues): array
    {
        if ($this->labelResolver === null || $rawValues === []) {
            return [];
        }

        try {
            $resolved = ($this->labelResolver)(array_values(array_unique($rawValues)));
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($resolved)) {
            return [];
        }

        $map = [];

        foreach ($resolved as $raw => $display) {
            if (is_scalar($display) && (string) $display !== '') {
                $map[(string) $raw] = (string) $display;
            }
        }

        return $map;
    }

    /**
     * API 응답용 배열로 바꿉니다.
     *
     * @return array<string, mixed> 차원 정의
     */
    public function toArray(): array
    {
        return [
            'key' => $this->storageKey(),
            'dimension' => $this->statsDimension(),
            'owner_type' => $this->ownerType,
            'owner_identifier' => $this->ownerIdentifier,
            'scope' => $this->scope,
            'label' => $this->localizedLabel(),
            'value_type' => $this->valueType,
            'values' => $this->values,
            'max_cardinality' => $this->maxCardinality,
            'pii' => $this->pii,
        ];
    }
}

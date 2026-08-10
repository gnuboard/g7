<?php

namespace App\Benchmark\DTO;

use App\Enums\BenchmarkAxis;

/**
 * 계측 프로파일 — 코어 config / 확장 선언에서 수집된 계측 대상 1건 (Value Object)
 *
 * 레지스트리가 선언 배열을 검증해 이 객체로 정규화하고, 축 실행기가 그대로 받아 씁니다.
 * 축별 옵션 스키마가 서로 다르므로 공통 필드(키·축·출처·라벨)만 프로퍼티로 승격하고
 * 축 고유 옵션은 `options` 에 남깁니다 — 축이 늘 때 VO 를 고치지 않아도 되게 합니다.
 */
final readonly class BenchmarkProfile
{
    /**
     * @param  string  $key  선언된 프로파일 키 (확장 내부에서만 고유)
     * @param  BenchmarkAxis  $axis  계측 축
     * @param  string  $sourceKind  출처 종류 (core|module|plugin)
     * @param  string  $sourceIdentifier  출처 식별자 (코어는 'core')
     * @param  array<string, mixed>  $options  축 고유 옵션
     * @param  string|null  $label  표시용 설명
     */
    public function __construct(
        public string $key,
        public BenchmarkAxis $axis,
        public string $sourceKind,
        public string $sourceIdentifier,
        public array $options = [],
        public ?string $label = null,
    ) {}

    /**
     * 출처를 포함한 전역 고유 키를 반환합니다.
     *
     * 서로 다른 확장이 같은 키(`orders` 등)를 선언할 수 있으므로 충돌 시에는 이 키로
     * 지목합니다. 커맨드는 짧은 키가 유일할 때만 짧은 키를 허용합니다.
     *
     * @return string `{출처}/{키}` 형태의 정규화 키
     */
    public function qualifiedKey(): string
    {
        return $this->sourceIdentifier.'/'.$this->key;
    }

    /**
     * 축 고유 옵션 값을 읽습니다.
     *
     * @param  string  $name  옵션 키
     * @param  mixed  $default  미선언 시 기본값
     * @return mixed 옵션 값
     */
    public function option(string $name, mixed $default = null): mixed
    {
        return $this->options[$name] ?? $default;
    }

    /**
     * 이 프로파일 실행이 데이터를 변경하는지 판정합니다.
     *
     * 축 기본값을 쓰되, 프로파일이 `mutating` 을 명시하면 그 선언을 따릅니다.
     * `screen` 축은 GET 이 아니면 변경으로 봅니다.
     *
     * @return bool 데이터 변경 여부
     */
    public function mutates(): bool
    {
        $declared = $this->options['mutating'] ?? null;

        if (is_bool($declared)) {
            return $declared;
        }

        if ($this->axis === BenchmarkAxis::Screen) {
            return strtoupper((string) $this->option('method', 'GET')) !== 'GET';
        }

        return $this->axis->mutatesByDefault();
    }

    /**
     * 배열로 직렬화합니다. (`--json` / 리포트 출력용)
     *
     * @return array<string, mixed> 직렬화 결과
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'qualified_key' => $this->qualifiedKey(),
            'axis' => $this->axis->value,
            'source' => ['kind' => $this->sourceKind, 'identifier' => $this->sourceIdentifier],
            'label' => $this->label,
            'options' => $this->options,
        ];
    }
}

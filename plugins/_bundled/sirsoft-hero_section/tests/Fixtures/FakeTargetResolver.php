<?php

namespace Plugins\Sirsoft\HeroSection\Tests\Fixtures;

use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;

/**
 * 시험용 대상 해석기 — 모듈 DB 없이 동적 위치를 시험한다. 대상은 정적 목록이고, 지우기(`forget`)로 「없는 대상」을 만든다.
 */
class FakeTargetResolver implements TargetResolverInterface
{
    /**
     * 대상 목록 (키 => [slug, 이름])
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public static array $targets = [];

    /**
     * 해석(resolve) 호출 수 — 공개 캐시가 대상 테이블을 다시 묻지 않는지 본다.
     */
    public static int $resolveCalls = 0;

    /**
     * 이름 풀기(labels) 호출 수 — 목록·순서 창이 대상마다 한 번만 푸는지 본다.
     */
    public static int $labelCalls = 0;

    /**
     * 기본 대상(1 alpha 알파, 2 beta 베타)으로 되돌립니다.
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$targets = ['1' => ['alpha', '알파'], '2' => ['beta', '베타']];
        self::$resolveCalls = 0;
        self::$labelCalls = 0;
    }

    /**
     * 대상을 지웁니다(게시판 삭제 흉내).
     *
     * @param  string  $key  대상 키
     * @return void 반환값 없음
     */
    public static function forget(string $key): void
    {
        unset(self::$targets[$key]);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $value  화면 값(slug 또는 키)
     * @return string|null 대상 키
     */
    public function resolve(string $value): ?string
    {
        self::$resolveCalls++;
        foreach (self::$targets as $key => [$slug]) {
            if ($slug === $value || (string) $key === $value) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  대상 키
     * @return bool 있으면 true
     */
    public function exists(string $key): bool
    {
        return isset(self::$targets[$key]);
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  대상 키
     * @return array<string, string> 키 => 이름
     */
    public function labels(array $keys): array
    {
        self::$labelCalls++;
        $labels = [];
        foreach ($keys as $key) {
            if (isset(self::$targets[$key])) {
                $labels[(string) $key] = self::$targets[$key][1];
            }
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어
     * @param  int  $limit  최대 건수
     * @return array<int, array{key: string, label: string, hint: string}> 고르기 항목
     */
    public function search(string $keyword, int $limit): array
    {
        $items = [];
        foreach (self::$targets as $key => [$slug, $label]) {
            if ($keyword === '' || str_contains($label, $keyword) || str_contains($slug, $keyword)) {
                $items[] = ['key' => (string) $key, 'label' => $label, 'hint' => $slug];
            }
        }

        return array_slice($items, 0, $limit);
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  대상 키
     * @return string|null 사이트 경로
     */
    public function siteUrl(string $key): ?string
    {
        return isset(self::$targets[$key]) ? '/fake/'.self::$targets[$key][0] : null;
    }
}

<?php

namespace Plugins\Sirsoft\HeroSection\Support\Targets;

use Modules\Sirsoft\Board\Contracts\BoardDirectory;
use Modules\Sirsoft\Board\Contracts\Data\BoardSummary;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;
use Plugins\Sirsoft\HeroSection\Contracts\TargetSiteUrlsInterface;

/**
 * 게시판 대상 해석기 — 게시판 목록 위치(`board_hero`). 저장 키는 게시판 id, 화면 값은 주소의 slug(없을 때만 숫자 id — slug 가 먼저다).
 *
 * 게시판 모듈이 켜져 있고 조회 계약({@see BoardDirectory})이 바인딩돼 있을 때만 위치가 등록되므로 이 클래스도 그때만 만들어진다.
 * 게시판 모듈이 공개한 계약만 쓴다 — 게시판 Model·저장소는 게시판 내부다.
 */
class BoardTargetResolver implements TargetResolverInterface, TargetSiteUrlsInterface
{
    /**
     * BoardTargetResolver 생성자
     *
     * @param  BoardDirectory  $boards  게시판 조회 계약
     */
    public function __construct(private BoardDirectory $boards) {}

    /**
     * {@inheritDoc}
     *
     * @param  string  $value  화면 값(slug 또는 id)
     * @return string|null 게시판 id
     */
    public function resolve(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $board = $this->boards->findBySlug($value) ?? (ctype_digit($value) ? $this->boards->findById((int) $value) : null);

        return $board === null ? null : (string) $board->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  게시판 id
     * @return bool 있으면 true
     */
    public function exists(string $key): bool
    {
        return $this->board($key) !== null;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  게시판 id 목록
     * @return array<string, string> 게시판 id => 이름
     */
    public function labels(array $keys): array
    {
        $labels = [];
        foreach ($this->boards->findByIds(self::ids($keys)) as $id => $board) {
            $labels[(string) $id] = $board->label;
        }

        return $labels;
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $keyword  검색어(이름·slug)
     * @param  int  $limit  최대 건수
     * @return array<int, array{key: string, label: string, hint: string}> 고르기 항목
     */
    public function search(string $keyword, int $limit): array
    {
        return array_map(fn (BoardSummary $board) => [
            'key' => (string) $board->id,
            'label' => $board->label,
            'hint' => $board->slug,
        ], $this->boards->search($keyword, $limit));
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  게시판 id
     * @return string|null 게시판 목록 경로
     */
    public function siteUrl(string $key): ?string
    {
        return $this->board($key)?->url;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  게시판 id 목록
     * @return array<string, string> 게시판 id => 사이트 경로
     */
    public function siteUrls(array $keys): array
    {
        $urls = [];
        foreach ($this->boards->findByIds(self::ids($keys)) as $id => $board) {
            $urls[(string) $id] = $board->url;
        }

        return $urls;
    }

    /**
     * 저장 키로 게시판을 찾습니다.
     *
     * @param  string  $key  게시판 id
     * @return BoardSummary|null 게시판 요약
     */
    private function board(string $key): ?BoardSummary
    {
        return ctype_digit($key) ? $this->boards->findById((int) $key) : null;
    }

    /**
     * 저장 키 목록에서 숫자 id 만 남깁니다.
     *
     * @param  array<int, string>  $keys  저장 키 목록
     * @return array<int, int> id 목록
     */
    private static function ids(array $keys): array
    {
        return array_values(array_unique(array_map('intval', array_filter($keys, fn ($key) => ctype_digit((string) $key)))));
    }
}

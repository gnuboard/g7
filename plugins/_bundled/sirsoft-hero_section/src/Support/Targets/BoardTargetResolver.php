<?php

namespace Plugins\Sirsoft\HeroSection\Support\Targets;

use Modules\Sirsoft\Board\Models\Board;
use Modules\Sirsoft\Board\Repositories\Contracts\BoardRepositoryInterface;
use Plugins\Sirsoft\HeroSection\Contracts\TargetResolverInterface;
use Plugins\Sirsoft\HeroSection\Contracts\TargetSiteUrlsInterface;

/**
 * 게시판 대상 해석기 — 게시판 목록 위치(`board_hero`). 저장 키는 게시판 id, 화면 값은 주소의 slug(없을 때만 숫자 id — slug 가 먼저다).
 *
 * 게시판 모듈이 켜져 있을 때만 위치가 등록되므로 이 클래스도 그때만 만들어진다. 게시판 모듈의 저장소 계약만 쓴다.
 */
class BoardTargetResolver implements TargetResolverInterface, TargetSiteUrlsInterface
{
    /**
     * BoardTargetResolver 생성자
     *
     * @param  BoardRepositoryInterface  $boards  게시판 저장소
     */
    public function __construct(private BoardRepositoryInterface $boards) {}

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

        $board = $this->boards->findBySlug($value) ?? (ctype_digit($value) ? $this->boards->find((int) $value) : null);

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
        $keys = array_values(array_unique(array_map('strval', $keys)));
        if (count($keys) === 1) {
            $board = $this->board($keys[0]);

            return $board === null ? [] : [$keys[0] => $board->getLocalizedName()];
        }

        // 여러 건이면 게시판마다 묻지 않고 한 번에 읽는다 — 게시판 저장소에 id 묶음 조회가 없고, 게시판 수는 운영상 수십 개다
        $byId = $this->boards->all()->keyBy(fn (Board $board) => (string) $board->id);
        $labels = [];
        foreach ($keys as $key) {
            if (isset($byId[$key])) {
                $labels[$key] = $byId[$key]->getLocalizedName();
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
        $boards = trim($keyword) === '' ? $this->boards->all() : $this->boards->search(trim($keyword));

        return $boards->take($limit)->map(fn (Board $board) => [
            'key' => (string) $board->id,
            'label' => $board->getLocalizedName(),
            'hint' => (string) $board->slug,
        ])->values()->all();
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $key  게시판 id
     * @return string|null 게시판 목록 경로
     */
    public function siteUrl(string $key): ?string
    {
        $board = $this->board($key);

        return $board === null ? null : '/board/'.$board->slug;
    }

    /**
     * {@inheritDoc}
     *
     * @param  array<int, string>  $keys  board id 목록
     * @return array<string, string> board id => 사이트 경로
     */
    public function siteUrls(array $keys): array
    {
        // 게시판 저장소에 id 묶음 조회가 없어 한 번에 읽는다 — 게시판 수는 운영상 수십 개다(labels 와 같은 판단)
        $byId = $this->boards->all()->keyBy(fn (Board $board) => (string) $board->id);
        $urls = [];
        foreach (array_unique(array_map('strval', $keys)) as $key) {
            if (isset($byId[$key])) {
                $urls[$key] = '/board/'.$byId[$key]->slug;
            }
        }

        return $urls;
    }

    /**
     * 저장 키로 게시판을 찾습니다.
     *
     * @param  string  $key  게시판 id
     * @return Board|null 게시판
     */
    private function board(string $key): ?Board
    {
        return ctype_digit($key) ? $this->boards->find((int) $key) : null;
    }
}

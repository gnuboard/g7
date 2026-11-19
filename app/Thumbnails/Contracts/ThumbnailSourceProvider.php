<?php

namespace App\Thumbnails\Contracts;

use App\Thumbnails\DTO\ThumbnailSource;

/**
 * 썸네일 원본 제공자 — 「이 디스크·경로의 원본은 누구의 것이고 게이트가 있는가」를 아는 쪽.
 *
 * 필터 `core.thumbnail.filter_source_providers`(값: 제공자 목록)로 모은다. 쓰임새 세 가지:
 *  - 등록 시 미리 생성: 저장소 계층이 방금 쓴 파일이 어느 제공자 것인지(`matches`)로 게이트 여부를 정한다.
 *  - 캐시 관리: 공개·권한 두 저장 위치가 같은 디스크를 써도 파생본을 분류별로 세고 지운다.
 *  - 기존 이미지 썸네일 만들기: `count`·`chunk` 로 원본을 나눠 돈다.
 */
interface ThumbnailSourceProvider
{
    /**
     * 제공자 키 (예: core.attachment, sirsoft-board.attachment)
     *
     * @return string 키
     */
    public function key(): string;

    /**
     * 화면 표시용 다국어 키
     *
     * @return string 다국어 키
     */
    public function label(): string;

    /**
     * 권한이 걸린 원본인지
     *
     * @return bool 게이트면 true
     */
    public function gated(): bool;

    /**
     * 디스크 위 전체 경로가 이 제공자의 원본인지 (임시 업로드 `temp/` 는 제외해야 한다)
     *
     * @param  string  $disk  원본 디스크
     * @param  string  $path  디스크 위 전체 경로
     * @return bool 이 제공자 것이면 true
     */
    public function matches(string $disk, string $path): bool;

    /**
     * 래스터 이미지 원본 수
     *
     * @return int 개수
     */
    public function count(): int;

    /**
     * 원본을 커서 단위로 나눠 돌려줍니다.
     *
     * @param  string|null  $cursor  이전 호출의 `next` (처음이면 null)
     * @param  int  $limit  최대 개수
     * @return array{items: list<ThumbnailSource>, next: ?string} 원본과 다음 커서 (끝이면 null)
     */
    public function chunk(?string $cursor, int $limit): array;
}

<?php

namespace App\Contracts\Extension;

/**
 * 스트림 접근을 지원하는 확장 저장소 계약
 *
 * `StorageInterface` 에 메서드를 더하면 그 계약을 직접 구현한 제3자 드라이버가 깨지므로 별도 계약으로 둔다.
 * 코어가 주는 세 드라이버(`CoreStorageDriver`·`ModuleStorageDriver`·`PluginStorageDriver`)가 구현한다.
 * 큰 파일(동영상)을 메모리에 통째로 올리지 않고 옮기거나(파일 보상 도구), 일부 구간만 내보낼(Range 서빙) 때 쓴다.
 * 경로는 `StorageInterface` 와 같이 카테고리 기준 상대 경로이고, 같은 경로 가드를 거친다.
 */
interface StreamingStorageInterface extends StorageInterface
{
    /**
     * 파일을 읽기 스트림으로 엽니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @return resource|null 읽기 스트림 (없으면 null)
     */
    public function readStream(string $category, string $path);

    /**
     * 스트림 내용을 파일로 씁니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @param  resource  $stream  읽을 스트림
     * @return bool 성공 여부
     */
    public function writeStream(string $category, string $path, $stream): bool;

    /**
     * 파일 크기(바이트)를 돌려줍니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @return int|null 크기 (없으면 null)
     */
    public function size(string $category, string $path): ?int;

    /**
     * 디스크가 로컬 파일 시스템이면 실제 경로를 돌려줍니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @return string|null 로컬 절대 경로 (원격 디스크이거나 파일이 없으면 null)
     */
    public function localPath(string $category, string $path): ?string;

    /**
     * 파일의 바이트 구간 [start, end] 를 읽기 스트림으로 엽니다(양 끝 포함).
     *
     * s3 처럼 Range 를 아는 디스크는 그 구간만 받는다. 그 밖에는 시크하고, 시크가 안 되는 스트림은 앞부분을 읽어 버린다.
     * 반환 스트림은 시작 위치에 놓여 있고, 읽는 쪽이 `end - start + 1` 바이트만 읽는다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  상대 경로
     * @param  int  $start  시작 바이트 (0 기준)
     * @param  int  $end  끝 바이트 (포함)
     * @return resource|null 읽기 스트림 (없으면 null)
     */
    public function readRangeStream(string $category, string $path, int $start, int $end);
}

<?php

namespace App\Contracts\Extension;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 확장(모듈/플러그인) 스토리지 인터페이스
 *
 * 모듈과 플러그인에서 파일을 저장하고 관리하기 위한 표준화된 인터페이스입니다.
 * 카테고리별로 파일을 분리하여 저장하며, 다양한 스토리지 백엔드를 지원합니다.
 */
interface StorageInterface
{
    /**
     * 파일을 저장합니다.
     *
     * @param  string  $category  카테고리 (settings, attachments, images, cache, temp)
     * @param  string  $path  파일 경로 (카테고리 하위 상대 경로)
     * @param  mixed  $content  파일 내용 (string|resource)
     * @return bool 저장 성공 여부
     */
    public function put(string $category, string $path, mixed $content): bool;

    /**
     * 파일 내용을 가져옵니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로
     * @return string|null 파일 내용 (파일이 없으면 null)
     */
    public function get(string $category, string $path): ?string;

    /**
     * 파일이 존재하는지 확인합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로
     * @return bool 파일 존재 여부
     */
    public function exists(string $category, string $path): bool;

    /**
     * 파일을 삭제합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로
     * @return bool 삭제 성공 여부
     */
    public function delete(string $category, string $path): bool;

    /**
     * 파일의 공개 URL을 반환합니다.
     *
     * public 디스크이거나 `filesystems.disks.{disk}.url` 이 설정된 디스크(S3+CDN 등)면
     * 직접 URL 을 반환하고, 그 외에는 null 을 반환합니다 (별도 API 엔드포인트 사용).
     * 생성 결과는 디스크 종류와 무관하게 `core.storage.filter_url` 필터 훅을 항상 통과하므로,
     * 확장이 URL 을 공급/수정/차단할 수 있습니다 (null 반환도 훅 발화 대상).
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로
     * @return string|null 파일 URL (직접 URL 불가 디스크이고 훅 공급도 없으면 null)
     */
    public function url(string $category, string $path): ?string;

    /**
     * 디렉토리 내 모든 파일 목록을 반환합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $directory  디렉토리 경로 (카테고리 하위 상대 경로, 빈 문자열이면 카테고리 루트)
     * @return array 파일 경로 배열
     */
    public function files(string $category, string $directory = ''): array;

    /**
     * 디렉토리와 그 하위의 모든 파일을 삭제합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $directory  디렉토리 경로 (카테고리 하위 상대 경로, 빈 문자열이면 카테고리 루트)
     * @return bool 삭제 성공 여부
     */
    public function deleteDirectory(string $category, string $directory = ''): bool;

    /**
     * 카테고리의 전체 파일 시스템 경로를 반환합니다.
     *
     * @param  string  $category  카테고리
     * @return string 전체 경로 (예: storage/app/modules/{identifier}/{category})
     */
    public function getBasePath(string $category): string;

    /**
     * 사용 중인 디스크 이름을 반환합니다.
     *
     * @return string 디스크 이름 (local, public, s3 등)
     */
    public function getDisk(): string;

    /**
     * 카테고리의 모든 파일을 삭제합니다.
     *
     * @param  string  $category  카테고리
     * @return bool 삭제 성공 여부
     */
    public function deleteAll(string $category): bool;

    /**
     * 파일을 스트리밍 응답으로 반환합니다.
     *
     * 파일을 다운로드하거나 브라우저에 표시할 수 있도록
     * StreamedResponse를 생성합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로
     * @param  string  $filename  다운로드 시 표시될 파일명
     * @param  array  $headers  추가 HTTP 헤더
     * @return StreamedResponse|null 파일 스트림 (파일이 없으면 null)
     */
    public function response(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse;

    /**
     * 사용할 디스크를 변경한 새 인스턴스를 반환합니다.
     *
     * 기존 인스턴스는 변경하지 않고, 새 디스크를 사용하는 복제된 인스턴스를 반환합니다.
     * 첨부파일 등 레코드별로 다른 디스크를 사용하는 경우에 활용합니다.
     *
     * @param  string  $disk  디스크 이름 (local, public, s3 등)
     * @return static 새 디스크를 사용하는 인스턴스
     */
    public function withDisk(string $disk): static;

    /**
     * 파일을 다운로드 응답으로 반환합니다.
     *
     * @param  string  $category  카테고리
     * @param  string  $path  파일 경로
     * @param  string  $filename  다운로드 시 표시될 파일명
     * @param  array  $headers  추가 HTTP 헤더
     * @return StreamedResponse|null 다운로드 응답 (파일이 없으면 null)
     */
    public function download(string $category, string $path, string $filename, array $headers = []): ?StreamedResponse;
}

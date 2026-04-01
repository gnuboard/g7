<?php

namespace App\Contracts\Repositories;

/**
 * 설정 저장소 인터페이스
 *
 * JSON 파일 기반 설정 관리를 위한 Repository 인터페이스입니다.
 */
interface ConfigRepositoryInterface
{
    /**
     * 모든 카테고리의 설정을 조회합니다.
     *
     * @return array<string, array<string, mixed>> 카테고리별 설정 배열
     */
    public function all(): array;

    /**
     * 특정 카테고리의 설정을 조회합니다.
     *
     * @param  string  $category  카테고리명 (예: 'general', 'mail')
     * @return array<string, mixed> 설정 배열
     */
    public function getCategory(string $category): array;

    /**
     * 도트 노테이션으로 특정 설정값을 조회합니다.
     *
     * @param  string  $key  설정 키 (예: 'mail.host', 'general.site_name')
     * @param  mixed  $default  기본값
     * @return mixed 설정값
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 도트 노테이션으로 특정 설정값을 저장합니다.
     *
     * @param  string  $key  설정 키
     * @param  mixed  $value  저장할 값
     * @return bool 저장 성공 여부
     */
    public function set(string $key, mixed $value): bool;

    /**
     * 여러 설정을 일괄 저장합니다.
     *
     * @param  array<string, mixed>  $settings  설정 배열
     * @return bool 저장 성공 여부
     */
    public function setMany(array $settings): bool;

    /**
     * 특정 카테고리의 설정을 저장합니다.
     *
     * @param  string  $category  카테고리명
     * @param  array<string, mixed>  $settings  설정 배열
     * @return bool 저장 성공 여부
     */
    public function saveCategory(string $category, array $settings): bool;

    /**
     * 설정 키 존재 여부를 확인합니다.
     *
     * @param  string  $key  설정 키
     * @return bool 존재 여부
     */
    public function has(string $key): bool;

    /**
     * 특정 설정을 삭제합니다.
     *
     * @param  string  $key  설정 키
     * @return bool 삭제 성공 여부
     */
    public function delete(string $key): bool;

    /**
     * 사용 가능한 카테고리 목록을 반환합니다.
     *
     * @return array<string> 카테고리 목록
     */
    public function getCategories(): array;

    /**
     * 카테고리 존재 여부를 확인합니다.
     *
     * @param  string  $category  카테고리명
     * @return bool 존재 여부
     */
    public function categoryExists(string $category): bool;

    /**
     * 설정 파일을 초기화합니다.
     *
     * @param  array<string, array<string, mixed>>  $settings  초기 설정값
     * @return bool 초기화 성공 여부
     */
    public function initialize(array $settings = []): bool;

    /**
     * 설정을 백업합니다.
     *
     * @return string 백업 파일 경로
     */
    public function backup(): string;

    /**
     * 백업에서 설정을 복원합니다.
     *
     * @param  string  $backupPath  백업 파일 경로
     * @return bool 복원 성공 여부
     */
    public function restore(string $backupPath): bool;

    /**
     * 기본 설정값을 반환합니다.
     *
     * @return array<string, array<string, mixed>> 카테고리별 기본 설정
     */
    public function getDefaults(): array;

    /**
     * 프론트엔드 스키마를 반환합니다.
     *
     * 프론트엔드에 노출할 설정 필드와 타입 캐스팅 규칙을 정의합니다.
     *
     * @return array<string, array<string, mixed>> 카테고리별 스키마
     */
    public function getFrontendSchema(): array;
}

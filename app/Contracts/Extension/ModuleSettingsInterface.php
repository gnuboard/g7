<?php

namespace App\Contracts\Extension;

/**
 * 모듈 환경설정 인터페이스
 *
 * 모듈별 환경설정 시스템을 구현하기 위한 계약입니다.
 * 환경설정이 필요한 모듈은 이 인터페이스를 구현해야 합니다.
 */
interface ModuleSettingsInterface
{
    /**
     * 모듈 설정 기본값 파일 경로 반환
     *
     * @return string|null defaults.json 파일의 절대 경로, 없으면 null
     */
    public function getSettingsDefaultsPath(): ?string;

    /**
     * 설정값 조회
     *
     * @param string $key 설정 키 (예: 'category.field' 또는 'field')
     * @param mixed $default 기본값
     * @return mixed 설정값
     */
    public function getSetting(string $key, mixed $default = null): mixed;

    /**
     * 설정값 저장
     *
     * @param string $key 설정 키
     * @param mixed $value 저장할 값
     * @return bool 성공 여부
     */
    public function setSetting(string $key, mixed $value): bool;

    /**
     * 전체 설정 조회
     *
     * @return array 모든 카테고리의 설정값
     */
    public function getAllSettings(): array;

    /**
     * 카테고리별 설정 조회
     *
     * @param string $category 카테고리명
     * @return array 카테고리의 설정값
     */
    public function getSettings(string $category): array;

    /**
     * 설정 저장
     *
     * @param array $settings 저장할 설정 배열
     * @return bool 성공 여부
     */
    public function saveSettings(array $settings): bool;

    /**
     * 프론트엔드용 설정 조회 (민감정보 제외)
     *
     * frontend_schema에 따라 민감하지 않은 설정만 반환합니다.
     *
     * @return array 프론트엔드에 노출 가능한 설정값
     */
    public function getFrontendSettings(): array;
}

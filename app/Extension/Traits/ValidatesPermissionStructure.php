<?php

namespace App\Extension\Traits;

use App\Contracts\Extension\ModuleInterface;

/**
 * 권한 계층형 구조 검증 기능을 제공하는 트레이트.
 *
 * 모듈 및 플러그인 설치 시 getPermissions()에서 반환하는
 * 권한 구조가 올바른 계층형인지 검증합니다.
 *
 * 계층 구조: 모듈(1레벨) → 카테고리(2레벨) → 개별 권한(3레벨)
 */
trait ValidatesPermissionStructure
{
    /**
     * 확장(모듈/플러그인)의 권한 구조가 올바른 계층형인지 검증합니다.
     *
     * @param  object  $extension  검증할 모듈 또는 플러그인 인스턴스
     * @param  string  $extensionType  확장 타입 ('module' 또는 'plugin')
     *
     * @throws \Exception 권한 구조가 올바르지 않을 때
     */
    protected function validatePermissionStructure(object $extension, string $extensionType = 'module'): void
    {
        $permissionConfig = $extension->getPermissions();
        $identifier = $extension->getIdentifier();

        // 권한이 없으면 검증 통과
        if (empty($permissionConfig)) {
            return;
        }

        // 계층형 구조 필수 필드 확인
        if (! isset($permissionConfig['categories'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, 'categories 필드가 필요합니다.')
            );
        }

        if (! is_array($permissionConfig['categories'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, 'categories는 배열이어야 합니다.')
            );
        }

        // name, description 필드 확인
        if (! isset($permissionConfig['name']) || ! is_array($permissionConfig['name'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, 'name 필드가 다국어 배열 형식이어야 합니다.')
            );
        }

        // 각 카테고리 검증
        foreach ($permissionConfig['categories'] as $index => $category) {
            $this->validateCategoryStructure($extension, $category, $index, $extensionType);
        }
    }

    /**
     * 개별 카테고리 구조를 검증합니다.
     *
     * @param  object  $extension  확장 인스턴스
     * @param  array  $category  카테고리 데이터
     * @param  int  $index  카테고리 인덱스
     * @param  string  $extensionType  확장 타입
     *
     * @throws \Exception 카테고리 구조가 올바르지 않을 때
     */
    protected function validateCategoryStructure(object $extension, array $category, int $index, string $extensionType): void
    {
        $identifier = $extension->getIdentifier();

        // identifier 필수
        if (! isset($category['identifier']) || empty($category['identifier'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, "categories[{$index}].identifier가 필요합니다.")
            );
        }

        // name 필수 (다국어 배열)
        if (! isset($category['name']) || ! is_array($category['name'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, "categories[{$index}].name이 다국어 배열 형식이어야 합니다.")
            );
        }

        // permissions 필수
        if (! isset($category['permissions']) || ! is_array($category['permissions'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, "categories[{$index}].permissions가 배열이어야 합니다.")
            );
        }

        // 각 권한 검증
        foreach ($category['permissions'] as $permIndex => $permission) {
            $this->validateIndividualPermissionStructure($extension, $permission, $index, $permIndex, $extensionType);
        }
    }

    /**
     * 개별 권한 구조를 검증합니다.
     *
     * @param  object  $extension  확장 인스턴스
     * @param  array  $permission  권한 데이터
     * @param  int  $categoryIndex  카테고리 인덱스
     * @param  int  $permIndex  권한 인덱스
     * @param  string  $extensionType  확장 타입
     *
     * @throws \Exception 권한 구조가 올바르지 않을 때
     */
    protected function validateIndividualPermissionStructure(object $extension, array $permission, int $categoryIndex, int $permIndex, string $extensionType): void
    {
        $identifier = $extension->getIdentifier();

        // action 필수
        if (! isset($permission['action']) || empty($permission['action'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, "categories[{$categoryIndex}].permissions[{$permIndex}].action이 필요합니다.")
            );
        }

        // name 필수 (다국어 배열)
        if (! isset($permission['name']) || ! is_array($permission['name'])) {
            throw new \Exception(
                $this->formatPermissionValidationError($extensionType, $identifier, "categories[{$categoryIndex}].permissions[{$permIndex}].name이 다국어 배열 형식이어야 합니다.")
            );
        }
    }

    /**
     * 권한 검증 에러 메시지를 포맷합니다.
     *
     * @param  string  $extensionType  확장 타입 ('module' 또는 'plugin')
     * @param  string  $identifier  확장 식별자
     * @param  string  $reason  에러 사유
     * @return string 포맷된 에러 메시지
     */
    protected function formatPermissionValidationError(string $extensionType, string $identifier, string $reason): string
    {
        $translationKey = $extensionType === 'module'
            ? 'modules.errors.invalid_permission_structure'
            : 'plugins.errors.invalid_permission_structure';

        return __($translationKey, [
            'identifier' => $identifier,
            'reason' => $reason,
        ]);
    }
}
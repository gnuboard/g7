<?php

namespace App\Contracts\Repositories;

use App\Models\Template;
use Illuminate\Database\Eloquent\Collection;

interface TemplateRepositoryInterface
{
    /**
     * 모든 템플릿 조회
     *
     * @param  string|null  $type  템플릿 타입 필터
     * @return Collection 템플릿 컬렉션
     */
    public function getAll(?string $type = null): Collection;

    /**
     * ID로 템플릿 조회
     *
     * @param  int  $id  템플릿 ID
     * @return Template|null 찾은 템플릿 모델 또는 null
     */
    public function findById(int $id): ?Template;

    /**
     * identifier로 템플릿 조회
     *
     * @param  string  $identifier  템플릿 식별자
     * @return Template|null 찾은 템플릿 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?Template;

    /**
     * 타입별 활성화된 템플릿 조회
     *
     * @param  string  $type  템플릿 타입
     * @return Template|null 찾은 템플릿 모델 또는 null
     */
    public function findActiveByType(string $type): ?Template;

    /**
     * 템플릿 업데이트
     *
     * @param  int  $id  템플릿 ID
     * @param  array  $data  업데이트할 데이터
     * @return Template 업데이트된 템플릿 모델
     */
    public function update(int $id, array $data): Template;

    /**
     * 템플릿 삭제 (Soft Delete)
     *
     * @param  int  $id  템플릿 ID
     * @return bool 삭제 성공 여부
     */
    public function delete(int $id): bool;

    /**
     * 특정 타입의 활성화된 모든 템플릿을 조회합니다.
     *
     * @param  string  $type  템플릿 타입 (admin, user 등)
     * @return Collection 활성화된 템플릿 컬렉션
     */
    public function getActiveByType(string $type): Collection;

    /**
     * 모든 활성화된 템플릿을 조회합니다.
     *
     * @return Collection 모든 활성화된 템플릿 컬렉션
     */
    public function getActive(): Collection;

    /**
     * 모든 템플릿을 identifier로 키잉하여 조회합니다.
     *
     * @return Collection identifier를 키로 하는 템플릿 컬렉션
     */
    public function getAllKeyedByIdentifier(): Collection;

    /**
     * 설치된 모든 템플릿의 identifier 목록을 조회합니다.
     *
     * @return array 설치된 템플릿 identifier 배열
     */
    public function getInstalledIdentifiers(): array;

    /**
     * 템플릿을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Template 생성 또는 업데이트된 템플릿 모델
     */
    public function updateOrCreate(array $attributes, array $values): Template;

    /**
     * 식별자로 템플릿 상태를 업데이트합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @param  array  $data  업데이트할 데이터
     * @return int 업데이트된 레코드 수
     */
    public function updateByIdentifier(string $identifier, array $data): int;

    /**
     * 식별자로 템플릿을 삭제합니다.
     *
     * @param  string  $identifier  템플릿 식별자
     * @return int 삭제된 레코드 수
     */
    public function deleteByIdentifier(string $identifier): int;

    /**
     * 특정 모듈에 의존하는 활성 템플릿을 조회합니다.
     *
     * @param  string  $moduleIdentifier  모듈 식별자
     * @return Collection 해당 모듈에 의존하는 활성 템플릿 컬렉션
     */
    public function findActiveByModuleDependency(string $moduleIdentifier): Collection;

    /**
     * 특정 플러그인에 의존하는 활성 템플릿을 조회합니다.
     *
     * @param  string  $pluginIdentifier  플러그인 식별자
     * @return Collection 해당 플러그인에 의존하는 활성 템플릿 컬렉션
     */
    public function findActiveByPluginDependency(string $pluginIdentifier): Collection;
}

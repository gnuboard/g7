<?php

namespace App\Contracts\Repositories;

use App\Models\Plugin;
use Illuminate\Database\Eloquent\Collection;

interface PluginRepositoryInterface
{
    /**
     * 모든 플러그인을 조회합니다.
     *
     * @return Collection 플러그인 컬렉션
     */
    public function getAll(): Collection;

    /**
     * 이름으로 플러그인을 찾습니다.
     *
     * @param  string  $name  찾을 플러그인 이름
     * @return Plugin|null 찾은 플러그인 모델 또는 null
     */
    public function findByName(string $name): ?Plugin;

    /**
     * 활성화된 플러그인들을 조회합니다.
     *
     * @return Collection 활성화된 플러그인 컬렉션
     */
    public function getActive(): Collection;

    /**
     * 새로운 플러그인을 생성합니다.
     *
     * @param  array  $data  플러그인 생성 데이터
     * @return Plugin 생성된 플러그인 모델
     */
    public function create(array $data): Plugin;

    /**
     * 기존 플러그인을 업데이트합니다.
     *
     * @param  Plugin  $plugin  업데이트할 플러그인 모델
     * @param  array  $data  업데이트할 데이터
     * @return bool 업데이트 성공 여부
     */
    public function update(Plugin $plugin, array $data): bool;

    /**
     * 플러그인을 삭제합니다.
     *
     * @param  Plugin  $plugin  삭제할 플러그인 모델
     * @return bool 삭제 성공 여부
     */
    public function delete(Plugin $plugin): bool;

    /**
     * 플러그인을 활성화합니다.
     *
     * @param  Plugin  $plugin  활성화할 플러그인 모델
     * @return bool 활성화 성공 여부
     */
    public function activate(Plugin $plugin): bool;

    /**
     * 플러그인을 비활성화합니다.
     *
     * @param  Plugin  $plugin  비활성화할 플러그인 모델
     * @return bool 비활성화 성공 여부
     */
    public function deactivate(Plugin $plugin): bool;

    /**
     * 플러그인의 설치 상태를 업데이트합니다.
     *
     * @param  Plugin  $plugin  대상 플러그인 모델
     * @param  bool  $installed  설치 상태 (기본값: true)
     * @return bool 업데이트 성공 여부
     */
    public function setInstalled(Plugin $plugin, bool $installed = true): bool;

    /**
     * 식별자로 플러그인을 찾습니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return Plugin|null 찾은 플러그인 모델 또는 null
     */
    public function findByIdentifier(string $identifier): ?Plugin;

    /**
     * 식별자로 활성화된 플러그인을 찾습니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return Plugin|null 찾은 플러그인 모델 또는 null
     */
    public function findActiveByIdentifier(string $identifier): ?Plugin;

    /**
     * 플러그인을 생성하거나 업데이트합니다.
     *
     * @param  array  $attributes  조회 조건
     * @param  array  $values  생성/업데이트할 데이터
     * @return Plugin 생성 또는 업데이트된 플러그인 모델
     */
    public function updateOrCreate(array $attributes, array $values): Plugin;

    /**
     * 식별자로 플러그인 상태를 업데이트합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @param  array  $data  업데이트할 데이터
     * @return int 업데이트된 레코드 수
     */
    public function updateByIdentifier(string $identifier, array $data): int;

    /**
     * 식별자로 플러그인을 삭제합니다.
     *
     * @param  string  $identifier  플러그인 식별자
     * @return int 삭제된 레코드 수
     */
    public function deleteByIdentifier(string $identifier): int;

    /**
     * 모든 플러그인을 identifier로 키잉하여 조회합니다.
     *
     * @return Collection identifier를 키로 하는 플러그인 컬렉션
     */
    public function getAllKeyedByIdentifier(): Collection;

    /**
     * 설치된 모든 플러그인의 identifier 목록을 조회합니다.
     *
     * @return array 설치된 플러그인 identifier 배열
     */
    public function getInstalledIdentifiers(): array;

    /**
     * 특정 모듈에 의존하는 활성 플러그인을 조회합니다.
     *
     * @param  string  $moduleIdentifier  의존 대상 모듈 식별자
     * @return Collection 해당 모듈에 의존하는 활성 플러그인 컬렉션
     */
    public function findActiveByModuleDependency(string $moduleIdentifier): Collection;

    /**
     * 특정 플러그인에 의존하는 활성 플러그인을 조회합니다.
     *
     * @param  string  $pluginIdentifier  의존 대상 플러그인 식별자
     * @return Collection 해당 플러그인에 의존하는 활성 플러그인 컬렉션
     */
    public function findActiveByPluginDependency(string $pluginIdentifier): Collection;

    /**
     * 활성화된 플러그인들의 identifier 목록을 반환합니다.
     *
     * @return array 활성화된 플러그인 identifier 배열
     */
    public function getActivePluginIdentifiers(): array;

    /**
     * 코어 버전 비호환으로 자동 비활성화된 플러그인을 조회합니다.
     *
     * `deactivated_reason = 'incompatible_core'` 인 레코드만 반환합니다.
     * 알림 영속화 + 재호환 판정 + 상단 배너 데이터 소스로 사용됩니다.
     *
     * @return Collection 자동 비활성화된 플러그인 컬렉션
     */
    public function findAutoDeactivated(): Collection;
}

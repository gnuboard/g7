<?php

namespace App\Contracts\Repositories;

use App\Models\IdentityPolicy;
use Illuminate\Support\Collection;

/**
 * identity_policies 테이블 Repository 계약.
 *
 * 정책은 선언형 Seeder (config/core.php.identity_policies + {벤더}IdentityPolicySeeder) 로 동기화됩니다.
 * Repository 는 런타임 조회 + 운영자 UI 편집 경로 두 방향 모두 지원합니다.
 *
 * @since 7.0.0-beta.4
 */
interface IdentityPolicyRepositoryInterface
{
    /**
     * key 로 조회합니다.
     *
     * @param  string  $key  정책 키 (예: core.auth.signup_before_submit)
     * @return IdentityPolicy|null
     */
    public function findByKey(string $key): ?IdentityPolicy;

    /**
     * id 로 조회합니다.
     *
     * @param  int  $id  정책 PK
     * @return IdentityPolicy|null
     */
    public function findById(int $id): ?IdentityPolicy;

    /**
     * scope + target 조합으로 매칭되는 활성 정책들을 반환합니다 (priority DESC 정렬).
     *
     * @param  string  $scope  'route' | 'hook'
     * @param  string  $target  scope 별 식별자 (route name 또는 hook name)
     * @return Collection<int, IdentityPolicy>
     */
    public function resolveByScopeTarget(string $scope, string $target): Collection;

    /**
     * key 존재 시 업데이트, 없으면 생성합니다. Seeder/SyncHelper 가 사용하는 upsert 경로.
     *
     * @param  array<string, mixed>  $attributes  정책 속성
     * @return IdentityPolicy upsert 된 정책
     */
    public function upsertByKey(array $attributes): IdentityPolicy;

    /**
     * key 기준 업데이트 (운영자 UI 편집 경로).
     *
     * @param  string  $key  정책 키
     * @param  array<string, mixed>  $attributes  변경할 속성
     * @param  array<int, string>  $overridesFields  user_overrides 에 append 할 필드명들
     * @return bool 업데이트 성공 여부
     */
    public function updateByKey(string $key, array $attributes, array $overridesFields = []): bool;

    /**
     * key 기준 삭제 (source_type=admin 인 정책만 허용).
     *
     * @param  string  $key  정책 키
     * @return bool 삭제 성공 여부
     */
    public function deleteByKey(string $key): bool;

    /**
     * source_type+source_identifier 에 속하지 않은 stale 정책을 제거합니다.
     *
     * @param  string  $sourceType  'core' | 'module' | 'plugin' | 'admin'
     * @param  string  $sourceIdentifier  vendor 식별자 (예: sirsoft-ecommerce, core)
     * @param  array<int, string>  $currentKeys  현재 선언된 key 목록
     * @return int 삭제된 행 수
     */
    public function cleanupStale(string $sourceType, string $sourceIdentifier, array $currentKeys): int;

    /**
     * 특정 source(확장) 가 등록한 정책 개수를 반환합니다.
     *
     * 모듈/플러그인 uninstall 모달의 "삭제될 데이터" 표시에 사용.
     *
     * @param  string  $sourceType  'core' | 'module' | 'plugin' | 'admin'
     * @param  string  $sourceIdentifier  확장 식별자
     * @return int
     */
    public function countBySource(string $sourceType, string $sourceIdentifier): int;

    /**
     * 목록 조회 (관리자 S1d DataGrid).
     *
     * @param  array<string, mixed>  $filters  필터 조건
     * @param  int  $perPage  페이지 크기
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function search(array $filters, int $perPage = 20);

    /**
     * 전체 활성 정책을 반환합니다.
     *
     * @return Collection<int, IdentityPolicy>
     */
    public function allEnabled(): Collection;

    /**
     * scope='route' 활성 정책을 [target => Collection<IdentityPolicy>] 맵으로 반환합니다.
     *
     * EnforceIdentityPolicy 미들웨어의 자동 매핑 lookup 진입점으로 사용. 부팅 시 1회 캐싱되며
     * IdentityPolicy 모델 saved/deleted 이벤트가 캐시를 즉시 invalidate 합니다.
     *
     * brace expansion 지원: target 'api.admin.{modules,plugins}.uninstall' 같은 표현은
     * 두 개의 라우트명으로 펼쳐 동일 정책 인스턴스를 양쪽에 매핑합니다.
     *
     * @return array<string, Collection<int, IdentityPolicy>> route name → 매칭 정책 컬렉션
     */
    public function getRouteScopeIndex(): array;

    /**
     * scope='hook' 활성 정책의 target 목록(중복 제거)을 반환합니다.
     *
     * EnforceIdentityPolicyListener::loadDynamicHookTargets() 가 부팅 시 동적 훅 구독을 위해
     * 호출하는 단일 진입점입니다. identity_policies 테이블이 존재하지 않거나 DB 미연결 환경
     * (마이그레이션 전 부팅) 에서는 빈 배열을 반환해 부팅을 보호합니다.
     *
     * @return list<string> 동적 hook target 목록
     */
    public function listHookTargets(): array;
}

<?php

namespace Plugins\Sirsoft\Gdpr\Services;

use App\Extension\HookManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Plugins\Sirsoft\Gdpr\Enums\GdprPolicyChangeType;
use Plugins\Sirsoft\Gdpr\Models\GdprPolicyVersion;
use Plugins\Sirsoft\Gdpr\Repositories\Contracts\GdprPolicyVersionRepositoryInterface;

/**
 * GDPR 정책 버전 발행 서비스
 *
 * 운영자가 GDPR 설정을 변경할 때 변경 종류를 자동 감지하여
 * Material 한 변경에만 새 정책 버전을 발행하고 snapshot 을 보존합니다.
 *
 * GDPR Art.7(1) 동의 입증 책임 + Art.30 처리 기록 의무를 충족합니다.
 */
class GdprPolicyVersionService
{
    /**
     * 플러그인 식별자 (훅 prefix)
     */
    private const PLUGIN_ID = 'sirsoft-gdpr';

    /**
     * GdprPolicyVersionService 생성자
     *
     * @param GdprPolicyVersionRepositoryInterface $repository 정책 버전 Repository
     */
    public function __construct(
        private readonly GdprPolicyVersionRepositoryInterface $repository,
    ) {}

    /**
     * 새 정책 버전을 발행합니다.
     *
     * 다음 version 은 현재 최신 version + 1 로 자동 계산됩니다.
     * 발행 row 가 없으면 1 부터 시작합니다.
     *
     * 훅 흐름:
     * - before_publish: 발행 직전 (취소 불가, 부가 작업용)
     * - filter_publish_data: snapshot/memo 등 발행 데이터 필터 가능
     * - after_publish: 발행 후 (활동 로그, 알림 등)
     *
     * @param GdprPolicyChangeType $changeType 변경 종류
     * @param array $snapshot 발행 시점 settings 스냅샷
     * @param string|null $memo 운영자 변경 사유 (Material 시 권장)
     * @param int|null $userId 발행 운영자 user_id (콘솔/시스템 발행 시 NULL)
     * @return GdprPolicyVersion 발행된 행
     */
    public function publish(
        GdprPolicyChangeType $changeType,
        array $snapshot,
        ?string $memo = null,
        ?int $userId = null,
    ): GdprPolicyVersion {
        $nextVersion = $this->repository->getCurrentVersion() + 1;

        $data = [
            'version' => $nextVersion,
            'change_type' => $changeType->value,
            'memo' => $memo,
            'snapshot' => $snapshot,
            'created_by' => $userId,
        ];

        HookManager::doAction(self::PLUGIN_ID.'.policy_version.before_publish', $data);
        $data = HookManager::applyFilters(self::PLUGIN_ID.'.policy_version.filter_publish_data', $data);

        $version = $this->repository->create($data);

        HookManager::doAction(self::PLUGIN_ID.'.policy_version.after_publish', $version);

        return $version;
    }

    /**
     * 현재 발행된 최신 정책 버전을 반환합니다.
     *
     * Controller / 외부 호출자는 본 메서드로 접근하며, Repository 를 직접 참조하지 않습니다
     * (controllers.md "Service 주입 필수 — Repository 직접 주입 금지" 준수).
     *
     * @param bool $loadCreatedBy createdBy 관계 eager load 여부
     * @return GdprPolicyVersion|null 발행 row 가 없으면 null
     */
    public function getCurrent(bool $loadCreatedBy = false): ?GdprPolicyVersion
    {
        $current = $this->repository->getCurrent();

        if ($current !== null && $loadCreatedBy) {
            $current->load('createdBy');
        }

        return $current;
    }

    /**
     * 현재 정책 버전의 정수 값을 반환합니다.
     *
     * 발행 row 가 없으면 0 을 반환합니다.
     *
     * @return int
     */
    public function getCurrentVersion(): int
    {
        return $this->repository->getCurrentVersion();
    }

    /**
     * 특정 version 정수에 해당하는 정책 버전 1건을 반환합니다.
     *
     * admin 동의 이력 / 정책 버전 이력 화면에서 행 클릭 시 그 시점 snapshot 조회용
     * (B-3 admin 한정 — GDPR Art.7(1) 입증 책임 충족).
     *
     * @param int $version 조회할 정책 버전 정수
     * @return GdprPolicyVersion|null 해당 버전 row 가 없으면 null
     */
    public function getByVersion(int $version): ?GdprPolicyVersion
    {
        return $this->repository->getByVersion($version);
    }

    /**
     * 정책 버전 이력을 페이지네이션 형태로 반환합니다 (version DESC).
     *
     * @param int $perPage 페이지당 행 수 (Repository 단에서 1~100 clamp)
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return $this->repository->paginate($perPage);
    }

    /**
     * 운영자가 명시적으로 발행하는 새 정책 버전 (수동 발행).
     *
     * 자동 감지 (Material) 와 별개로, 운영자가 *정책 본문 외부 수정* / *법인명 변경* /
     * *기타 의도적 동의 무효화* 가 필요하다고 판단한 경우 사용. 호출자는 현재 settings
     * snapshot 을 직접 전달해야 합니다 (Service 가 settings 모름 — 의존성 분리).
     *
     * change_type 은 항상 Material (운영자 의도적 발행 = 재동의 트리거).
     *
     * @param array $snapshot 발행 시점의 settings 스냅샷
     * @param string $memo 운영자 변경 사유 (필수)
     * @param int|null $userId 발행 운영자 user_id
     * @return GdprPolicyVersion 새 발행 행
     */
    public function publishManually(array $snapshot, string $memo, ?int $userId = null): GdprPolicyVersion
    {
        return $this->publish(GdprPolicyChangeType::Material, $snapshot, $memo, $userId);
    }

    /**
     * 이전 snapshot 과 새 snapshot 을 비교하여 변경 종류를 감지합니다.
     *
     * Material (재동의 트리거):
     * - cookie_categories 의 key 추가/삭제/rename
     * - cookie_categories.*.description 변경 (목적/처리 범위 변경)
     * - privacy_policy_slug 변경 (정책 본문 위치 변경)
     *
     * Non-material:
     * - 도메인 추가/삭제 (blocked_domains.*)
     * - cookie_categories.*.label 변경 (UI 라벨만)
     * - legal_entity_name / data_storage_location 변경 (회사 정보)
     * - banner_position / banner_enabled 변경 (UI 위치/토글)
     *
     * 동등 (변경 없음): NonMaterial 반환 (publish 호출자에서 publish 생략 결정)
     *
     * @param array $oldSnapshot 이전 settings snapshot (없으면 빈 배열)
     * @param array $newSnapshot 새 settings snapshot
     * @return GdprPolicyChangeType Material 또는 NonMaterial
     */
    public function detectChangeType(array $oldSnapshot, array $newSnapshot): GdprPolicyChangeType
    {
        // 카테고리 key 집합 비교 (추가/삭제/rename 감지)
        $oldKeys = $this->extractCategoryKeys($oldSnapshot);
        $newKeys = $this->extractCategoryKeys($newSnapshot);
        if ($oldKeys !== $newKeys) {
            return GdprPolicyChangeType::Material;
        }

        // 카테고리 description 변경 감지 (목적/범위 변경)
        if ($this->extractCategoryDescriptions($oldSnapshot) !== $this->extractCategoryDescriptions($newSnapshot)) {
            return GdprPolicyChangeType::Material;
        }

        // privacy_policy_slug 변경 감지
        $oldSlug = (string) ($oldSnapshot['privacy_policy_slug'] ?? '');
        $newSlug = (string) ($newSnapshot['privacy_policy_slug'] ?? '');
        if ($oldSlug !== $newSlug) {
            return GdprPolicyChangeType::Material;
        }

        return GdprPolicyChangeType::NonMaterial;
    }

    /**
     * cookie_categories 의 key 집합을 정렬된 배열로 반환합니다.
     *
     * @param array $snapshot
     * @return array<int, string>
     */
    private function extractCategoryKeys(array $snapshot): array
    {
        $categories = $snapshot['cookie_categories'] ?? [];
        if (! is_array($categories)) {
            return [];
        }

        $keys = [];
        foreach ($categories as $category) {
            $key = is_array($category) ? ($category['key'] ?? null) : null;
            if (is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        sort($keys);

        return $keys;
    }

    /**
     * cookie_categories 의 key → description 매핑을 반환합니다 (정렬 보장).
     *
     * @param array $snapshot
     * @return array<string, mixed>
     */
    private function extractCategoryDescriptions(array $snapshot): array
    {
        $categories = $snapshot['cookie_categories'] ?? [];
        if (! is_array($categories)) {
            return [];
        }

        $map = [];
        foreach ($categories as $category) {
            if (! is_array($category)) {
                continue;
            }
            $key = $category['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $map[$key] = $category['description'] ?? null;
        }

        ksort($map);

        return $map;
    }
}

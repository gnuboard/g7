<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\TemplateCustomTranslationRepositoryInterface;
use App\Exceptions\ConcurrentModificationException;
use App\Models\TemplateCustomTranslation;
use App\Services\LanguagePack\CustomTranslationUsageScanner;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

/**
 * 템플릿 커스텀 다국어 키 서비스.
 *
 * 레이아웃 편집기 인라인 편집에서 평문을 `$t:custom.{layout}.{seq}` 키로
 * 전환할 때 사용하는 동적 다국어 키의 생성/수정/삭제를 담당합니다.
 *
 * 책임:
 *  - 키 자동 생성 (`custom.{layoutKey}.{seq}`)
 *  - seq 충돌 시 재시도 (unique 제약 위반 → seq 증가)
 *  - 신규 키 생성 시 모든 활성 로케일에 폴백 시드
 *  - 낙관적 잠금 (`lock_version` — update 경로)
 *  - 커스텀 키 CRUD 시 다국어 캐시 무효화 
 */
class TemplateCustomTranslationService
{
    /**
     * seq 충돌 재시도 최대 횟수.
     */
    private const MAX_SEQ_RETRY = 5;

    /**
     * @param  TemplateCustomTranslationRepositoryInterface  $repository  커스텀 다국어 키 리포지토리
     * @param  CacheInterface  $cache  캐시 드라이버
     */
    public function __construct(
        private readonly TemplateCustomTranslationRepositoryInterface $repository,
        private readonly CacheInterface $cache,
    ) {}

    /**
     * 특정 템플릿의 커스텀 다국어 키 목록을 조회합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string|null  $layoutName  레이아웃 이름 필터
     * @param  string|null  $status  상태 필터
     * @return Collection<int, TemplateCustomTranslation> 커스텀 키 컬렉션
     */
    public function getList(int $templateId, ?string $layoutName = null, ?string $status = null): Collection
    {
        return $this->repository->getByTemplateId($templateId, $layoutName, $status);
    }

    /**
     * ID로 커스텀 다국어 키를 조회합니다.
     *
     * @param  int  $id  커스텀 키 ID
     * @return TemplateCustomTranslation|null 찾은 모델 또는 null
     */
    public function find(int $id): ?TemplateCustomTranslation
    {
        return $this->repository->findById($id);
    }

    /**
     * 커스텀 다국어 키를 생성합니다.
     *
     * 키는 `custom.{layoutKey}.{seq}` 형식으로 자동 생성하며, unique 제약
     * (template_id + translation_key) 위반 시 seq 를 증가시켜 재시도합니다.
     * `values` 는 모든 활성 로케일 키를 채우되 편집 로케일엔 입력값,
     * 나머지 로케일엔 동일 입력값을 폴백 시드합니다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $layoutName  생성 출처 레이아웃 이름 (예: board/list)
     * @param  string  $locale  편집 로케일
     * @param  string  $value  편집 로케일의 입력값 (평문)
     * @param  int|null  $createdBy  생성자 ID
     * @return TemplateCustomTranslation 생성된 모델
     */
    public function createKey(
        int $templateId,
        string $layoutName,
        string $locale,
        string $value,
        ?int $createdBy = null,
    ): TemplateCustomTranslation {
        $layoutKey = $this->normalizeLayoutKey($layoutName);
        $values = $this->seedLocaleValues($locale, $value);

        $seq = $this->repository->getMaxSeq($templateId, $layoutKey) + 1;

        for ($attempt = 0; $attempt < self::MAX_SEQ_RETRY; $attempt++) {
            $translationKey = "custom.{$layoutKey}.{$seq}";

            try {
                $model = $this->repository->create([
                    'template_id' => $templateId,
                    'layout_name' => $layoutName,
                    'translation_key' => $translationKey,
                    'values' => $values,
                    'status' => 'active',
                    'created_by' => $createdBy,
                    'updated_by' => $createdBy,
                    'lock_version' => 0,
                ]);

                $this->invalidateLanguageCache();

                return $model;
            } catch (QueryException $e) {
                // unique(template_id, translation_key) 위반 → seq 증가 재시도.
                if (! $this->isUniqueViolation($e)) {
                    throw $e;
                }
                $seq++;
            }
        }

        // 재시도 한도 초과 — 마지막 시도는 예외를 그대로 전파.
        $translationKey = "custom.{$layoutKey}.{$seq}";
        $model = $this->repository->create([
            'template_id' => $templateId,
            'layout_name' => $layoutName,
            'translation_key' => $translationKey,
            'values' => $values,
            'status' => 'active',
            'created_by' => $createdBy,
            'updated_by' => $createdBy,
            'lock_version' => 0,
        ]);

        $this->invalidateLanguageCache();

        return $model;
    }

    /**
     * 커스텀 다국어 키의 로케일별 값을 수정합니다 (낙관적 잠금).
     *
     * `expectedLockVersion` 이 현재 `lock_version` 과 다르면
     * ConcurrentModificationException 을 던집니다.
     *
     * @param  int  $id  커스텀 키 ID
     * @param  array<string, string>  $values  로케일별 번역 값
     * @param  int  $expectedLockVersion  편집기가 보유한 lock_version
     * @param  int|null  $updatedBy  수정자 ID
     * @return TemplateCustomTranslation 수정된 모델
     *
     * @throws ConcurrentModificationException 잠금 버전 불일치 시
     */
    public function updateValues(
        int $id,
        array $values,
        int $expectedLockVersion,
        ?int $updatedBy = null,
    ): TemplateCustomTranslation {
        $model = $this->repository->findById($id);

        if ($model === null) {
            throw new ModelNotFoundException(
                "Custom translation not found: id={$id}"
            );
        }

        $currentVersion = (int) ($model->lock_version ?? 0);

        if ($expectedLockVersion !== $currentVersion) {
            throw new ConcurrentModificationException(
                currentVersion: $currentVersion,
                expectedVersion: $expectedLockVersion,
                resource: "template_custom_translations:{$model->id}",
            );
        }

        $updated = $this->repository->update(
            $id,
            [
                'values' => $values,
                'updated_by' => $updatedBy,
            ],
            $currentVersion + 1,
        );

        $this->invalidateLanguageCache();

        return $updated;
    }

    /**
     * 커스텀 다국어 키를 삭제합니다.
     *
     * @param  int  $id  커스텀 키 ID
     * @return bool 삭제 성공 여부
     */
    public function deleteKey(int $id): bool
    {
        $deleted = $this->repository->delete($id);

        if ($deleted) {
            $this->invalidateLanguageCache();
        }

        return $deleted;
    }

    /**
     * 커스텀 다국어 키를 일괄 삭제합니다 (관리 모달 "선택 삭제"/"미사용 전체 삭제").
     *
     * 각 ID 를 Repository 위임으로 삭제하고, 1건 이상 삭제 시 다국어 캐시를
     * 1회만 무효화합니다 (개별 deleteKey 의 N회 무효화 회피).
     *
     * @param  array<int, int>  $ids  삭제할 커스텀 키 ID 목록
     * @return int 실제 삭제된 행 수
     */
    public function deleteKeys(array $ids): int
    {
        $deleted = 0;

        foreach ($ids as $id) {
            if ($this->repository->delete((int) $id)) {
                $deleted++;
            }
        }

        if ($deleted > 0) {
            $this->invalidateLanguageCache();
        }

        return $deleted;
    }

    /**
     * 레이아웃 이름을 키 네임스페이스로 정규화합니다.
     *
     * `board/list` → `board_list`. 정규화 규칙은 스캐너와 단일 SSoT 를 공유합니다
     * (좀비 감지 시 키에서 추출한 layoutKey 와 일관 매칭되도록 —.2).
     *
     * @param  string  $layoutName  레이아웃 이름
     * @return string 정규화된 키 네임스페이스
     */
    private function normalizeLayoutKey(string $layoutName): string
    {
        return CustomTranslationUsageScanner::normalizeLayoutKey($layoutName);
    }

    /**
     * 신규 키의 모든 활성 로케일 값을 시드합니다.
     *
     * 편집 로케일엔 입력값을, 나머지 활성 로케일엔 동일 입력값을 폴백으로
     * 채웁니다 (평문 → $t:key 전환 시 폴백 시드).
     *
     * @param  string  $editLocale  편집 로케일
     * @param  string  $value  편집 로케일 입력값
     * @return array<string, string> 로케일별 값
     */
    private function seedLocaleValues(string $editLocale, string $value): array
    {
        $locales = $this->activeLocales();
        $values = [];

        foreach ($locales as $locale) {
            $values[$locale] = $value;
        }

        // 편집 로케일이 supported_locales 에 없더라도 안전하게 포함.
        $values[$editLocale] = $value;

        return $values;
    }

    /**
     * 활성(지원) 로케일 목록을 반환합니다.
     *
     * @return array<int, string> 로케일 목록
     */
    private function activeLocales(): array
    {
        $locales = config('app.supported_locales', ['ko', 'en']);

        return is_array($locales) && $locales !== [] ? array_values($locales) : ['ko', 'en'];
    }

    /**
     * 다국어 캐시를 무효화합니다.
     *
     * `serveLanguage` 캐시 키가 `template.language.{id}.{locale}.v{cacheVersion}`
     * 형태로 `ext.cache_version` 을 포함하므로, 이 값을 갱신하면 다음
     * `loadTranslations` 가 재fetch 합니다.
     */
    private function invalidateLanguageCache(): void
    {
        $this->cache->put('ext.cache_version', time());
    }

    /**
     * QueryException 이 unique 제약 위반인지 판별합니다.
     *
     * @param  QueryException  $e  쿼리 예외
     * @return bool unique 위반 여부
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 (integrity constraint violation). MySQL 1062 / SQLite UNIQUE.
        return (string) ($e->errorInfo[0] ?? '') === '23000';
    }
}

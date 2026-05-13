<?php

namespace App\Listeners\LanguagePack;

use App\Contracts\Repositories\LanguagePackTranslationRepositoryInterface;
use App\Models\LanguagePack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * `core.language_packs.after_activate` / `after_deactivate` 액션 훅을 받아 DB JSON 다국어
 * 컬럼을 동기화하는 thin Listener (G7 표준 HookManager::doAction → addAction 메커니즘).
 *
 * 영속/도메인 책임은 모두 LanguagePackTranslationRepository 에 위임. 본 클래스는 seed 파일
 * 로딩 + Repository 호출 + 감사 로그 라우팅만 수행합니다.
 *
 * 정책 (계획서 §6.6, §9.4):
 *  - Case A: 기존 row 의 다국어 JSON 에 해당 locale 키 부재 → 추가
 *  - Case B: locale 키 존재 + user_overrides 에 컬럼 등록 → 건너뜀 (사용자 수정 보존)
 *  - Case C: locale 키 존재 + user_overrides 미등록 → 시드 값으로 덮어쓰기
 *  - 비활성화/제거 시: user_overrides 등록 locale 은 JSON 에서 제거하지 않음
 */
// audit:allow listener-must-implement-hooklistenerinterface reason: LanguagePackServiceProvider 가 HookManager::addAction 으로 직접 등록하는 명시 등록 패턴 (auto-discovery 대상 아님)
class SyncDatabaseTranslations
{
    /**
     * @param  LanguagePackTranslationRepositoryInterface  $translationRepository  다국어 컬럼 동기화 Repository
     */
    public function __construct(
        private readonly LanguagePackTranslationRepositoryInterface $translationRepository,
    ) {}

    /**
     * 활성화 훅 처리 — seed/*.json 의 locale 키를 DB JSON 컬럼에 병합.
     *
     * @param  LanguagePack  $pack  활성화된 언어팩
     * @return void
     */
    public function handleActivated(LanguagePack $pack): void
    {
        $seedBundle = $this->loadSeedBundle($pack, [
            'permissions',
            'roles',
            'menus',
            'notifications',
            'identity_messages',
            'manifest',
        ]);

        $audit = DB::transaction(fn () => $this->translationRepository->applySeedFromPack($pack, $seedBundle));

        $this->emitAudit('activated', $pack, $audit);
    }

    /**
     * 비활성화 훅 처리 — 해당 locale 키를 JSON 에서 제거 (user_overrides 등록 컬럼은 보존).
     *
     * @param  LanguagePack  $pack  비활성화된 언어팩
     * @return void
     */
    public function handleDeactivated(LanguagePack $pack): void
    {
        $audit = DB::transaction(fn () => $this->translationRepository->stripLocaleFromPack($pack));

        $this->emitAudit('deactivated', $pack, $audit);
    }

    /**
     * 언어팩 디렉토리의 seed/{entity}.json 묶음을 로드합니다.
     *
     * @param  array<int, string>  $entities  로드할 엔티티 이름 목록
     * @return array<string, array<string, mixed>> 엔티티별 seed 데이터 (없으면 미포함)
     */
    private function loadSeedBundle(LanguagePack $pack, array $entities): array
    {
        $bundle = [];
        foreach ($entities as $entity) {
            $seed = $this->loadSeed($pack, $entity);
            if ($seed !== null) {
                $bundle[$entity] = $seed;
            }
        }

        return $bundle;
    }

    /**
     * 단일 seed 파일을 로드합니다.
     *
     * @param  string  $entity  엔티티 이름 (permissions/roles/menus/notifications/identity_messages/manifest)
     * @return array<string, mixed>|null seed 데이터 또는 null
     */
    private function loadSeed(LanguagePack $pack, string $entity): ?array
    {
        $seedFile = $pack->resolveDirectory().DIRECTORY_SEPARATOR.'seed'.DIRECTORY_SEPARATOR.$entity.'.json';
        if (! File::isFile($seedFile)) {
            return null;
        }
        $decoded = json_decode(File::get($seedFile), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Repository 가 누적한 감사 항목을 language_pack 채널 로그로 라우팅합니다.
     *
     * @param  string  $action  최상위 동작 (activated/deactivated)
     * @param  array<int, array<string, mixed>>  $audit  Repository 가 반환한 감사 항목 배열
     * @return void
     */
    private function emitAudit(string $action, LanguagePack $pack, array $audit): void
    {
        $channel = config('logging.channels.language_pack') ? 'language_pack' : 'stack';
        $base = [
            'pack_id' => $pack->id,
            'identifier' => $pack->identifier,
            'scope' => $pack->scope,
            'target' => $pack->target_identifier,
        ];

        Log::channel($channel)->info('[lang-pack] '.$action, array_merge($base, ['locale' => $pack->locale]));

        foreach ($audit as $entry) {
            $sub = $entry['action'] ?? 'detail';
            unset($entry['action']);
            Log::channel($channel)->info('[lang-pack] '.$sub, array_merge($base, $entry));
        }
    }
}

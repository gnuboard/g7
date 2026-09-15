<?php

namespace Plugins\Sirsoft\Ckeditor5\Services;

use App\Contracts\Repositories\ModuleRepositoryInterface;
use App\Enums\ExtensionStatus;
use App\Extension\HookManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Plugins\Sirsoft\Ckeditor5\Models\Ckeditor5ImageUpload;
use Plugins\Sirsoft\Ckeditor5\Repositories\Contracts\ImageReferenceSourceRepositoryInterface;

/**
 * CKEditor5 업로드 이미지의 참조 여부 판정 서비스
 *
 * 에디터로 올린 이미지가 어떤 콘텐츠에서도 더 이상 쓰이지 않는지를 판정합니다.
 *
 * 판정 토큰이 둘인 이유: 본문에 박히는 URL 이 두 형태이기 때문입니다.
 *   - API 폴백형 `/api/plugins/sirsoft-ckeditor5/images/{12자hex}` → `hash` 가 들어간다
 *   - 디스크 직접 URL(`url` 설정 디스크/CDN) → 저장 파일명(uuid.ext)이 들어간다
 * 한쪽만 검사하면 다른 형태로 저장된 이미지를 "미참조" 로 오판해 삭제하게 됩니다.
 * `core.storage.filter_url` 훅으로 URL 이 변형될 수 있으므로 두 토큰을 OR 로 모두 검사합니다.
 *
 * 로그 사본 테이블(`notification_logs.body`, `mail_send_logs.body`,
 * `boards_report_logs.snapshot`)과 미리보기(`template_layout_previews`)는 참조 소스에서
 * 제외합니다. 이들은 자체 보존기간으로 삭제되는 사본이라 소스로 삼으면 "로그가 지워지는
 * 순간 이미지가 고아가 되는" 역전이 생깁니다.
 */
class ImageReferenceScanService
{
    /**
     * 참조 소스 확장 훅 이름
     */
    public const FILTER_REFERENCE_SOURCES = 'sirsoft-ckeditor5.image.filter_reference_sources';

    /**
     * 코어가 소유한 참조 소스 (테이블명은 프리픽스 제외 원시 이름)
     *
     * 모듈 콘텐츠(게시글·페이지·상품 설명 등)는 각 모듈이 훅으로 등록합니다.
     *
     * @var list<array{table: string, columns: list<string>}>
     */
    private const CORE_SOURCES = [
        ['table' => 'notification_templates', 'columns' => ['body']],
        ['table' => 'mail_templates', 'columns' => ['body']],
        ['table' => 'identity_message_templates', 'columns' => ['body']],
        ['table' => 'template_layouts', 'columns' => ['content']],
        ['table' => 'template_layout_extensions', 'columns' => ['content']],
        ['table' => 'template_layout_versions', 'columns' => ['content']],
    ];

    /**
     * 요청 수명 memoize 된 검증 완료 소스 목록
     *
     * @var list<array{table: string, columns: list<string>}>|null
     */
    private ?array $resolvedSources = null;

    /**
     * @param  ImageReferenceSourceRepositoryInterface  $sourceRepository  참조 소스 조회 리포지토리
     * @param  ModuleRepositoryInterface  $moduleRepository  모듈 상태 조회 리포지토리 (소스 불완전 감지)
     */
    public function __construct(
        protected ImageReferenceSourceRepositoryInterface $sourceRepository,
        protected ModuleRepositoryInterface $moduleRepository
    ) {}

    /**
     * 참조 소스가 불완전할 수 있는 상태인지 판정합니다.
     *
     * 참조 소스는 **활성** 모듈이 훅으로 등록한다 — 설치돼 있으나 비활성인 모듈이 있으면
     * 그 모듈 콘텐츠(게시글 본문 등)가 이번 판정에 빠져, 실제로 쓰이는 이미지가
     * "미참조" 로 오판된다(fail-open). 삭제(uninstalled)는 콘텐츠도 함께 정리되는
     * 상태이므로 대상이 아니다.
     *
     * @return bool 비활성 설치 모듈이 하나라도 있으면 true
     */
    public function hasPotentiallyMissingSources(): bool
    {
        // audit:allow query-unbounded-get reason: modules 는 설치된 확장 수에 묶인 설정성 테이블 — 행 수가 데이터 증가에 비례하지 않는다
        return $this->moduleRepository->getAll()
            ->contains(fn ($module) => ! $module->isActive()
                && $module->status !== ExtensionStatus::Uninstalled->value);
    }

    /**
     * 참조 스캔 대상 소스 목록을 반환합니다.
     *
     * 확장이 훅으로 추가한 선언은 테이블·컬럼 실재 여부를 검증하고, 불량 선언은
     * 경고만 남기고 건너뜁니다 — 한 확장의 잘못된 선언이 판정 전체를 멈추지 않게 합니다.
     *
     * @return list<array{table: string, columns: list<string>}> 검증된 소스 목록
     */
    public function getReferenceSources(): array
    {
        if ($this->resolvedSources !== null) {
            return $this->resolvedSources;
        }

        $declared = HookManager::applyFilters(self::FILTER_REFERENCE_SOURCES, self::CORE_SOURCES);

        if (! is_array($declared)) {
            Log::warning('CKEditor5 참조 소스 훅이 배열이 아닌 값을 반환해 코어 기본값을 사용합니다.');

            $declared = self::CORE_SOURCES;
        }

        return $this->resolvedSources = $this->validateSources($declared);
    }

    /**
     * 업로드 이미지가 어떤 콘텐츠에서든 참조되는지 판정합니다.
     *
     * @param  Ckeditor5ImageUpload  $upload  업로드 기록
     * @return bool 참조되면 true
     */
    public function isReferenced(Ckeditor5ImageUpload $upload): bool
    {
        $tokens = $this->buildTokens($upload);

        if ($tokens === []) {
            // 판정 토큰을 만들 수 없는 행은 안전 방향(참조됨)으로 본다.
            return true;
        }

        foreach ($this->getReferenceSources() as $source) {
            if ($this->sourceContains($source, $tokens)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 업로드 목록의 참조 여부를 일괄 판정합니다.
     *
     * 행별로 isReferenced 를 반복하면 비용이 (행 수 × 소스 수 × LIKE 전체 스캔) 으로
     * 커진다 — 여기서는 전 행의 토큰을 모아 소스당 1회 순회로 등장 토큰을 수집한다
     * (비용 상한: 소스 크기 합 1회 + 조기 종료).
     *
     * @param  Collection<int, Ckeditor5ImageUpload>|iterable<Ckeditor5ImageUpload>  $uploads  업로드 목록
     * @return array<int, bool> [업로드 ID => 참조 여부]
     */
    public function mapReferenced(iterable $uploads): array
    {
        $map = [];
        $tokenOwners = [];

        foreach ($uploads as $upload) {
            $id = (int) $upload->id;
            $tokens = $this->buildTokens($upload);

            if ($tokens === []) {
                // 판정 토큰을 만들 수 없는 행은 안전 방향(참조됨)으로 본다.
                $map[$id] = true;

                continue;
            }

            $map[$id] = false;

            foreach ($tokens as $token) {
                $tokenOwners[$token][] = $id;
            }
        }

        if ($tokenOwners === []) {
            return $map;
        }

        $pending = $tokenOwners;

        foreach ($this->getReferenceSources() as $source) {
            if ($pending === []) {
                break;
            }

            // PHP 배열 키는 순수 숫자 토큰을 int 로 접으므로 문자열로 복원해 넘긴다.
            $foundTokens = $this->sourceRepository->findTokensInSource(
                $source['table'],
                $source['columns'],
                array_map('strval', array_keys($pending))
            );

            foreach ($foundTokens as $token) {
                foreach ($pending[$token] ?? [] as $id) {
                    $map[$id] = true;
                }

                unset($pending[$token]);
            }
        }

        return $map;
    }

    /**
     * 판정 토큰(해시 + 저장 파일명)을 만듭니다.
     *
     * @param  Ckeditor5ImageUpload  $upload  업로드 기록
     * @return list<string> 토큰 목록
     */
    private function buildTokens(Ckeditor5ImageUpload $upload): array
    {
        $tokens = [];

        $hash = trim((string) ($upload->hash ?? ''));
        if ($hash !== '') {
            $tokens[] = $hash;
        }

        $filePath = trim((string) ($upload->file_path ?? ''));
        if ($filePath !== '') {
            $basename = basename($filePath);

            if ($basename !== '' && ! in_array($basename, $tokens, true)) {
                $tokens[] = $basename;
            }
        }

        return $tokens;
    }

    /**
     * 한 소스에 토큰이 하나라도 등장하는지 확인합니다.
     *
     * @param  array{table: string, columns: list<string>}  $source  참조 소스
     * @param  list<string>  $tokens  판정 토큰
     * @return bool 등장하면 true
     */
    private function sourceContains(array $source, array $tokens): bool
    {
        return $this->sourceRepository->containsAnyToken($source['table'], $source['columns'], $tokens);
    }

    /**
     * 훅으로 수집한 소스 선언을 검증합니다.
     *
     * @param  array  $declared  선언 목록
     * @return list<array{table: string, columns: list<string>}> 검증 통과 소스
     */
    private function validateSources(array $declared): array
    {
        $valid = [];

        foreach ($declared as $source) {
            if (! is_array($source) || ! isset($source['table'], $source['columns'])) {
                Log::warning('CKEditor5 참조 소스 선언 형식이 올바르지 않아 건너뜁니다.', [
                    'source' => $source,
                ]);

                continue;
            }

            $table = (string) $source['table'];
            $columns = is_array($source['columns']) ? array_values(array_map('strval', $source['columns'])) : [];

            if ($table === '' || $columns === []) {
                Log::warning('CKEditor5 참조 소스 선언에 테이블 또는 컬럼이 비어 있어 건너뜁니다.', [
                    'table' => $table,
                ]);

                continue;
            }

            $existingColumns = $this->sourceRepository->resolveExistingColumns($table, $columns);

            if ($existingColumns === []) {
                // 테이블 자체가 없거나(확장 미설치) 선언한 컬럼이 하나도 없는 경우다.
                // 한 확장의 잘못된 선언이 판정 전체를 멈추지 않도록 경고만 남기고 건너뛴다.
                Log::warning('CKEditor5 참조 소스에 존재하는 테이블/컬럼이 없어 건너뜁니다.', [
                    'table' => $table,
                    'columns' => $columns,
                ]);

                continue;
            }

            $valid[] = ['table' => $table, 'columns' => $existingColumns];
        }

        return $valid;
    }
}

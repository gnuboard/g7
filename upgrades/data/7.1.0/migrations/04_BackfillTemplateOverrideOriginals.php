<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Traits\ValidatesLayoutFiles;
use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * 7.0.x 가 원본 정보 없이 등록한 템플릿 오버라이드 레이아웃 행에 병합 기준(원본 본문·해시·크기)을 채웁니다 (#727 C-1).
 *
 * 7.0.x 의 오버라이드 등록(`templates/{식별자}/layouts/overrides/{모듈}/` 파일 → `template_layouts`,
 * source_type = template, source_identifier = 템플릿 식별자)은 `original_content`·`original_content_hash`·
 * `original_content_size` 를 비워 두었습니다. 7.1.0 은 원본 정보가 둘 다 없는 행을 운영자가 편집기 「새 레이아웃」 으로
 * 만든 화면으로 판정하므로(파일 동기화의 이름 충돌 규칙·고아 정리 예외), 그런 오버라이드 행은 keep 업데이트마다
 * `kept_name_collision` 으로 비켜 가 새 판을 영영 받지 못합니다.
 *
 * 규칙: 설치된 템플릿마다 활성 디렉토리의 오버라이드 파일을 7.1.0 등록과 같은 방법(모듈 디렉토리별 레이아웃 파일 검증·
 * 컴파일)으로 읽어, 같은 템플릿·같은 이름·source_type template·source_identifier 템플릿 식별자이면서 원본 해시와
 * 원본 본문이 모두 빈 행에만 그 파일의 원본 본문·해시·크기를 적습니다. 활성 디렉토리의 파일은 그 행을 마지막으로
 * 등록한 판이므로 병합 기준으로 쓸 수 있습니다 — 운영자가 그 뒤에 고친 행이면 다음 keep 업데이트가 3자 병합합니다.
 * 본문(`content`)은 바꾸지 않습니다. 대응하는 오버라이드 파일이 없는 행(편집기에서 만든 화면)은 건드리지 않습니다.
 *
 * 값 계산은 7.1.0 등록과 같습니다 — 본문 추출(slots·meta·data_sources·version), 원본 본문 = 조각 출처 표식이 있는
 * 판을 JSON 인코드, 해시·크기 = 표식을 걷은 판을 JSON 왕복 정규화한 문자열의 sha256·바이트 수. 추출·정규화·인코드는
 * 여기에 동결했습니다.
 *
 * V-1 판단(업그레이드 스텝 가이드 §3 원칙 2·§9 V-1 안전 패턴): 레이아웃 파일 검증·컴파일(`.g7.html` 마크업 컴파일과
 * partial 해석)은 스텝 안에 다시 쓸 수 없는 규모라 `ValidatesLayoutFiles` 트레이트의 `validateLayoutFiles()` 를 씁니다.
 * 이 메서드는 7.0.x 에도 같은 시그니처로 있던 기존 메서드이며(이전 버전 메모리에서 돌아도 호출이 성립), 7.0.x 판은
 * 원본(`original`) 항목을 돌려주지 않으므로 그때는 서비스 판(`data`)을 원본으로 씁니다. Manager·Service·Repository
 * 인스턴스는 쓰지 않고 행 갱신은 DB 파사드로만 합니다.
 *
 * idempotent: 채운 행은 원본 해시가 있어 두 번째 실행에서 대상이 되지 않습니다.
 */
class BackfillTemplateOverrideOriginals implements DataMigration
{
    use ValidatesLayoutFiles;

    /** json_encode 플래그 — 7.1.0 원본 인코드·해시 정규화 공통 */
    private const FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillTemplateOverrideOriginals';
    }

    /**
     * 설치된 템플릿마다 원본 정보가 없는 오버라이드 행에 병합 기준을 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('templates') || ! Schema::hasTable('template_layouts')
            || ! Schema::hasColumn('template_layouts', 'original_content') || ! Schema::hasColumn('template_layouts', 'original_content_hash')) {
            $context->logger->info('[core:7.1.0] templates·template_layouts 또는 원본 컬럼 부재 — 오버라이드 원본 채우기 스킵');

            return;
        }

        $filled = 0;
        foreach (DB::table('templates')->orderBy('id')->get(['id', 'identifier']) as $template) {
            $filled += $this->backfillTemplate($context, (int) $template->id, (string) $template->identifier);
        }

        $context->logger->info("[core:7.1.0] 템플릿 오버라이드 원본 채우기 완료 — {$filled}행");
    }

    /**
     * 템플릿 하나의 오버라이드 파일로 원본 정보가 없는 행을 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  int  $templateId  템플릿 DB ID
     * @param  string  $identifier  템플릿 식별자
     * @return int 채운 행 수
     */
    private function backfillTemplate(UpgradeContext $context, int $templateId, string $identifier): int
    {
        $overridesPath = base_path("templates/{$identifier}/layouts/overrides");
        if (! File::isDirectory($overridesPath)) {
            $context->logger->info("[core:7.1.0] {$identifier} 오버라이드 디렉토리 없음 — 스킵");

            return 0;
        }

        $filled = 0;
        foreach (File::directories($overridesPath) as $moduleDirectory) {
            $moduleIdentifier = basename($moduleDirectory);

            try {
                // 7.1.0 등록(TemplateManager::registerLayoutOverrides)과 같은 인자 — 이름·본문이 등록과 같게 나온다
                $validatedLayouts = $this->validateLayoutFiles($moduleDirectory, $moduleIdentifier, 'template', true);
            } catch (\Throwable $e) {
                $context->logger->warning("[core:7.1.0] {$identifier} 오버라이드 {$moduleIdentifier} 검증 실패 — 스킵", ['error' => $e->getMessage()]);

                continue;
            }

            foreach ($validatedLayouts as $validated) {
                $filled += $this->backfillRow($context, $templateId, $identifier, $validated);
            }
        }

        return $filled;
    }

    /**
     * 오버라이드 파일 하나에 대응하는, 원본 정보가 없는 행을 채웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @param  int  $templateId  템플릿 DB ID
     * @param  string  $identifier  템플릿 식별자
     * @param  array<string, mixed>  $validated  검증된 레이아웃 (`layout_name`·`data`·`original`(7.1.0 만))
     * @return int 채운 행 수 (0 또는 1)
     */
    private function backfillRow(UpgradeContext $context, int $templateId, string $identifier, array $validated): int
    {
        $layoutName = (string) $validated['layout_name'];
        $data = $this->extractLayoutContent((array) $validated['data']);
        $original = $this->extractLayoutContent((array) ($validated['original'] ?? $validated['data']));
        $normalized = $this->normalize($data);

        $updated = DB::table('template_layouts')
            ->where('template_id', $templateId)
            ->where('name', $layoutName)
            ->where('source_type', 'template')
            ->where('source_identifier', $identifier)
            ->whereNull('original_content_hash')
            ->where(fn ($query) => $query->whereNull('original_content')->orWhere('original_content', ''))
            ->update([
                'original_content' => (string) json_encode($original, self::FLAGS),
                'original_content_hash' => hash('sha256', $normalized),
                'original_content_size' => strlen($normalized),
            ]);

        if ($updated > 0) {
            $context->logger->info("[core:7.1.0] {$identifier} 오버라이드 {$layoutName} 원본 채움");
        }

        return $updated > 0 ? 1 : 0;
    }

    /**
     * 레이아웃 데이터에서 DB 본문으로 저장하는 칸만 뽑습니다 — 7.1.0 오버라이드 등록의 본문 추출을 동결한 사본.
     *
     * @param  array<string, mixed>  $layoutData  레이아웃 데이터
     * @return array<string, mixed> 본문 (slots·meta·data_sources·version)
     */
    private function extractLayoutContent(array $layoutData): array
    {
        $content = [];
        foreach (['slots', 'meta', 'data_sources', 'version'] as $key) {
            if (isset($layoutData[$key])) {
                $content[$key] = $layoutData[$key];
            }
        }

        return $content;
    }

    /**
     * 원본 해시·크기 계산용 정규화 JSON — 7.1.0 해시 정규화(JSON 왕복으로 빈 객체를 빈 배열로 맞춤)를 동결한 사본.
     *
     * @param  array<string, mixed>  $content  본문
     * @return string 정규화된 JSON 문자열
     */
    private function normalize(array $content): string
    {
        return (string) json_encode(json_decode((string) json_encode($content, self::FLAGS), true), self::FLAGS);
    }
}

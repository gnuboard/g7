<?php

namespace App\Extension\Layout;

use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutExtensionVersionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Enums\LayoutSyncMode;
use App\Extension\Layout\DTO\LayoutMergeResult;
use App\Extension\Layout\DTO\LayoutSyncEntry;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Extension\Traits\ValidatesLayoutFiles;
use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use App\Support\LayoutMarkup\EmptyMap;
use App\Support\LayoutMarkup\LayoutPartialOrigin;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * 파일 → DB 레이아웃 행 쓰기의 단일 진입점 (#727).
 *
 * 설치·활성화·템플릿 전환·확장 업데이트·수동 갱신이 모두 이 한 곳을 지나야 "운영자 수정 보존" 규칙이 갈리지 않는다.
 * 종전에는 보존 판정이 `refresh*Layouts()` 한 곳에만 있어, 같은 행을 쓰는 다른 경로(활성화·템플릿 전환·조각 재등록·
 * 오버라이드)가 전략을 모른 채 덮어써 운영자 수정이 흔적 없이 지워졌다.
 *
 * 규칙(계획서 §5.6):
 *  - 행이 없으면 생성하고 원본 본문(`original_content`, 조각 출처 표식 포함)을 남긴다.
 *  - 운영자가 지운 템플릿 화면: Keep 이면 건드리지 않음(`kept_deleted_by_operator`), Overwrite 면 되살려 새 판.
 *  - 운영자가 만든 같은 이름 화면(원본 해시·원본 본문 없음): Keep 이면 건드리지 않음(`kept_name_collision`).
 *  - Overwrite 또는 운영자가 안 고친 행: 새 판.
 *  - Keep + 운영자가 고친 행: 기준(base)을 찾아 {@see LayoutThreeWayMerger} 로 병합, 못 찾으면 통째 보존(`kept_no_base`).
 *
 * 상태가 없다(저장소만 들고 있다) — 생성자 주입으로 재사용해도, `app()` 로 새로 받아도 같다. 행 조회·쓰기는 모두 저장소
 * 인터페이스를 지난다.
 */
final class LayoutRowSynchronizer
{
    use ComputesLayoutContentHash;
    use ValidatesLayoutFiles;

    /**
     * @param  LayoutRepositoryInterface  $layouts  레이아웃 저장소
     * @param  LayoutVersionRepositoryInterface  $layoutVersions  레이아웃 버전 이력 저장소
     * @param  LayoutExtensionRepositoryInterface  $extensions  확장 조각 저장소
     * @param  LayoutExtensionVersionRepositoryInterface  $extensionVersions  확장 조각 버전 이력 저장소
     * @param  TemplateRepositoryInterface  $templates  템플릿 저장소
     */
    public function __construct(
        private LayoutRepositoryInterface $layouts,
        private LayoutVersionRepositoryInterface $layoutVersions,
        private LayoutExtensionRepositoryInterface $extensions,
        private LayoutExtensionVersionRepositoryInterface $extensionVersions,
        private TemplateRepositoryInterface $templates,
    ) {}

    /**
     * 레이아웃 행 하나를 파일 판으로 동기화합니다.
     *
     * @param  array{template_id: int, template: string, name: string, source_type: LayoutSourceType|string, source_identifier: string|null, track_extends?: bool, extends?: string|null}  $identity  행 식별·파생 정보
     * @param  array<string, mixed>  $fileData  파일 판 (서비스용, 표식 없음)
     * @param  array<string, mixed>  $fileOriginal  파일 판 원본 (조각 출처 표식 포함)
     * @param  LayoutSyncMode  $mode  동기화 모드
     * @param  array<string, mixed>|null  $backupOriginal  업데이트 백업을 컴파일한 이전 판 원본
     * @return LayoutSyncEntry 결과
     */
    public function syncLayout(array $identity, array $fileData, array $fileOriginal, LayoutSyncMode $mode, ?array $backupOriginal = null): LayoutSyncEntry
    {
        $entry = fn (string $verdict, array $applied = [], array $conflicts = []) => new LayoutSyncEntry('layout', $identity['template'], $identity['name'], $verdict, $applied, $conflicts);
        $row = $this->layouts->findWithTrashedByName((int) $identity['template_id'], $identity['name']);

        if ($row === null) {
            $this->layouts->updateOrCreate(
                ['template_id' => (int) $identity['template_id'], 'name' => $identity['name']],
                $this->freshLayoutValues($identity, $fileData, $fileOriginal) + ['content' => $fileData, 'created_by' => Auth::id(), 'updated_by' => Auth::id()]
            );

            return $entry('created');
        }

        $isTemplateRow = $this->sourceTypeOf($row) === LayoutSourceType::Template;

        if ($row->trashed()) {
            // 운영자는 템플릿 화면만 지울 수 있다(모듈·플러그인 화면 삭제는 파일 관리 API 가 막는다). 모듈·플러그인의
            // 지운 행은 확장 비활성화의 잔재라 되살려 평소대로 처리한다.
            if ($isTemplateRow && $mode === LayoutSyncMode::Keep) {
                return $entry('kept_deleted_by_operator');
            }

            $this->layouts->restoreTrashed($row);

            if ($isTemplateRow) {
                return $entry($this->overwriteLayout($row, $identity, $fileData, $fileOriginal) ? 'updated' : 'unchanged');
            }
        }

        if (! $this->hasFileOrigin($row)) {
            if ($mode === LayoutSyncMode::Keep) {
                return $entry('kept_name_collision');
            }

            return $entry($this->overwriteLayout($row, $identity, $fileData, $fileOriginal) ? 'updated' : 'unchanged');
        }

        if ($mode === LayoutSyncMode::Overwrite || ! $this->isOperatorModified($row)) {
            $changed = $this->overwriteLayout($row, $identity, $fileData, $fileOriginal);

            return $entry($changed ? ($mode === LayoutSyncMode::Overwrite ? 'updated' : 'took_theirs') : 'unchanged');
        }

        $base = $this->resolveBase($row, $backupOriginal, $fileOriginal);
        if ($base === null) {
            return $entry('kept_no_base');
        }

        $ours = $this->decodeRaw($row->getRawOriginal('content'));
        $result = (new LayoutThreeWayMerger)->merge($base, $ours, $fileOriginal);

        if ($result->verdict !== 'kept_invalid' && $this->validateDataSourceIds($result->merged['data_sources'] ?? [], $identity['name']) !== null) {
            $result = $result->withVerdict('kept_invalid', $ours);
        }

        if ($result->verdict === 'kept_invalid') {
            Log::warning('레이아웃 병합 결과 검증 실패 — 운영자 판을 통째로 유지합니다', ['template' => $identity['template'], 'layout' => $identity['name']]);

            return $entry('kept_invalid', [], $result->conflicts);
        }

        $changed = ! LayoutContentComparator::sameDocument($ours, $result->merged);
        $values = $this->freshLayoutValues($identity, $fileData, $fileOriginal, $result->merged);

        if ($changed) {
            $values['content'] = $result->merged;
            $values['lock_version'] = (int) $row->lock_version + 1;
        }

        $this->layouts->saveAttributes($row->id, $values);

        if (in_array($result->verdict, ['merged', 'conflict'], true)) {
            $this->recordLayoutVersion($row->id, $ours, $result);
        }

        return $entry($result->verdict, $result->applied, $result->conflicts);
    }

    /**
     * 새 버전 파일 목록에 없는 레이아웃 행을 처리합니다 — 삭제 후보만, 운영자 수정본은 Keep 에서 남깁니다 (D4·W15).
     *
     * @param  TemplateLayout  $row  행
     * @param  string  $template  템플릿 식별자 (보고용)
     * @param  LayoutSyncMode  $mode  동기화 모드
     * @param  bool  $candidate  이전 판 파일 목록에 있던 이름인지 ({@see isRemovalCandidate()})
     * @return LayoutSyncEntry|null 결과 (후보가 아니면 null)
     */
    public function removeMissingLayout(TemplateLayout $row, string $template, LayoutSyncMode $mode, bool $candidate): ?LayoutSyncEntry
    {
        if (! $candidate) {
            return null;
        }

        if ($mode === LayoutSyncMode::Keep && $this->isOperatorModified($row)) {
            return new LayoutSyncEntry('layout', $template, $row->name, 'kept_removed');
        }

        $this->layouts->forceDelete($row);

        return new LayoutSyncEntry('layout', $template, $row->name, 'deleted');
    }

    /**
     * 파일에 없는 행이 "새 버전에서 빠진 행" 인지 — 이전 판 파일 목록에 있던 이름인지 — 판정합니다 (W15).
     *
     * 운영자가 만들었거나 이름을 바꾼 행은 절대 지우지 않는다. 이전 판 이름 목록(업데이트 백업)이 있으면 그것으로,
     * 없으면 원본 본문의 `layout_name` 이 행 이름과 같은지로, 원본 본문도 없으면 원본 해시가 있는지로 판정한다.
     *
     * @param  TemplateLayout  $row  행
     * @param  array<int, string>|null  $previousNames  이전 판 레이아웃 이름 목록 (모르면 null)
     * @param  string  $namePrefix  DB 이름 접두어 (모듈·플러그인은 `{식별자}.`)
     * @return bool 삭제 후보면 true
     */
    public function isRemovalCandidate(TemplateLayout $row, ?array $previousNames, string $namePrefix = ''): bool
    {
        if ($previousNames !== null) {
            return in_array($row->name, $previousNames, true);
        }

        $raw = $row->getRawOriginal('original_content');
        if (is_string($raw) && $raw !== '') {
            $original = json_decode($raw, true);

            return is_array($original) && isset($original['layout_name']) && $namePrefix.$original['layout_name'] === $row->name;
        }

        return $row->original_content_hash !== null;
    }

    /**
     * 확장 조각 행 하나를 파일 판으로 동기화합니다.
     *
     * 지운 조각 행은 되살린다(확장 비활성화가 지운 행 — 기존 규칙). 조각은 include 를 해석하지 않으므로 원본 = 파일 판이다.
     *
     * @param  array<string, mixed>  $attributes  행 식별 조건 (템플릿·대상·출처·조각 식별자)
     * @param  array<string, mixed>  $content  파일 판
     * @param  LayoutSyncMode  $mode  동기화 모드
     * @param  string  $template  템플릿 식별자 (보고용)
     * @param  array<string, mixed>|null  $backupOriginal  업데이트 백업을 컴파일한 이전 판
     * @return LayoutSyncEntry 결과
     */
    public function syncExtension(array $attributes, array $content, LayoutSyncMode $mode, string $template, ?array $backupOriginal = null): LayoutSyncEntry
    {
        $name = ($attributes['target_name'] ?? '').':'.($attributes['fragment_key'] ?? '');
        $entry = fn (string $verdict, array $applied = [], array $conflicts = []) => new LayoutSyncEntry('extension', $template, $name, $verdict, $applied, $conflicts);

        $existing = $this->extensions->findWithTrashedByAttributes($attributes);

        if ($existing === null) {
            $this->extensions->create($attributes + $this->freshExtensionValues($content) + ['content' => $content]);

            return $entry('created');
        }

        if ($existing->trashed()) {
            $this->extensions->restoreTrashed($existing);
        }

        if ($mode === LayoutSyncMode::Overwrite || ! $this->isOperatorModified($existing)) {
            $changed = $this->overwriteExtension($existing, $content);

            return $entry($changed ? ($mode === LayoutSyncMode::Overwrite ? 'updated' : 'took_theirs') : 'unchanged');
        }

        $base = $this->resolveBase($existing, $backupOriginal, $content);
        if ($base === null) {
            $this->extensions->saveModelAttributes($existing, ['is_active' => true]);

            return $entry('kept_no_base');
        }

        $ours = $this->decodeRaw($existing->getRawOriginal('content'));
        $result = (new LayoutThreeWayMerger)->merge($base, $ours, $content);

        if ($result->verdict === 'kept_invalid') {
            $this->extensions->saveModelAttributes($existing, ['is_active' => true]);

            return $entry('kept_invalid', [], $result->conflicts);
        }

        $values = $this->freshExtensionValues($content, $result->merged);
        if (! LayoutContentComparator::sameDocument($ours, $result->merged)) {
            $values['content'] = $result->merged;
            $values['lock_version'] = (int) $existing->lock_version + 1;
        }
        $this->extensions->saveModelAttributes($existing, $values);

        if (in_array($result->verdict, ['merged', 'conflict'], true)) {
            if ($this->extensionVersions->getNextVersion($existing->id) <= 1) {
                $this->extensionVersions->saveVersion($existing->id, $ours, null);
            }
            $this->extensionVersions->saveVersion($existing->id, $result->merged, $ours, null, $this->summaryExtra($result));
        }

        return $entry($result->verdict, $result->applied, $result->conflicts);
    }

    /**
     * 소스에 더는 없는 조각 행을 처리합니다 — Keep 에서 운영자 수정본이면 남깁니다 (L10).
     *
     * @param  LayoutExtension  $row  행
     * @param  string  $template  템플릿 식별자 (보고용)
     * @param  LayoutSyncMode  $mode  동기화 모드
     * @return LayoutSyncEntry 결과
     */
    public function removeOrphanExtension(LayoutExtension $row, string $template, LayoutSyncMode $mode): LayoutSyncEntry
    {
        $name = $row->target_name.':'.$row->fragment_key;

        if ($mode === LayoutSyncMode::Keep && $this->isOperatorModified($row)) {
            return new LayoutSyncEntry('extension', $template, $name, 'kept_removed');
        }

        $this->extensions->softDelete($row);

        return new LayoutSyncEntry('extension', $template, $name, 'deleted');
    }

    /**
     * 행이 파일(설치·업데이트 등록)에서 온 적이 있는지 판정합니다.
     *
     * 원본 해시도 원본 본문(빈 문자열 포함)도 없으면 파일에서 온 적이 없는 행 — 운영자가 편집기 「새 레이아웃」 으로 만든
     * 화면이다. 이 행은 같은 이름의 새 파일로 덮거나(이름 겹침) 고아 정리로 지우지 않는다. 판정 지점을 여기 하나로 둔다
     * (7.1.0 업그레이드 스텝 04 는 스텝 독립 원칙상 같은 판정을 자기 안에 사본으로 둔다).
     *
     * @param  TemplateLayout  $row  레이아웃 행
     * @return bool 파일에서 온 적이 있으면 true
     */
    public function hasFileOrigin(TemplateLayout $row): bool
    {
        $rawOriginal = $row->getRawOriginal('original_content');

        return $row->original_content_hash !== null || ($rawOriginal !== null && $rawOriginal !== '');
    }

    /**
     * 운영자가 행을 고쳤는지 판정합니다.
     *
     * 원본 본문이 있으면 정규화 비교(키 순서·빈 map 차이를 수정으로 세지 않는다), 없으면 옛 방식 해시 비교, 해시도 없으면
     * 판정할 근거가 없으므로 "안 고침".
     *
     * @param  TemplateLayout|LayoutExtension  $row  행
     * @return bool 고쳤으면 true
     */
    public function isOperatorModified(TemplateLayout|LayoutExtension $row): bool
    {
        $raw = $row->getRawOriginal('original_content');

        if (is_string($raw) && $raw !== '') {
            return ! LayoutContentComparator::sameDocument($row->getRawOriginal('content'), LayoutPartialOrigin::strip(json_decode($raw, true)));
        }

        // 옛 방식 해시는 7.0.x 직렬화(빈 map `{}` 보존)로 남은 행도 있다 — DB 원문으로도 대조해 표기 차이를 수정으로 세지 않는다.
        if (is_string($row->original_content_hash) && $row->original_content_hash !== '') {
            return ! $this->contentMatchesOriginalHash($row->content ?? [], $row->original_content_hash, $this->rawStoredContent($row));
        }

        return false;
    }

    /**
     * 꺼 둔 확장을 업데이트할 때, 백업 삭제 전에 이전 판 원본을 미리 저장합니다 (D11).
     *
     * 비활성 확장은 업데이트 때 레이아웃을 갱신하지 않으므로, 켤 때 병합하려면 이전 판 원본이 필요하다. 원본 본문이 비어
     * 있고 백업 컴파일 결과의 해시가 원본 해시와 같은 행에만 채운다(본문·해시는 그대로).
     *
     * @param  string  $backupPath  업데이트 백업 디렉토리
     * @param  string  $type  확장 유형 (`templates`·`modules`·`plugins`)
     * @param  string  $identifier  확장 식별자
     * @return int 원본을 채운 행 수
     */
    public function stashBaseForInactiveUpdate(string $backupPath, string $type, string $identifier): int
    {
        $reader = new BackupLayoutReader;
        $originals = $reader->layouts($backupPath, $type, $identifier);
        $filled = 0;

        foreach ($this->rowsOwnedBy($type, $identifier) as $row) {
            if ($row->getRawOriginal('original_content') !== null || ! isset($originals[$row->name])) {
                continue;
            }

            if ($this->computeContentHash(LayoutPartialOrigin::strip($originals[$row->name])) === $row->original_content_hash) {
                $this->layouts->saveAttributes($row->id, ['original_content' => $this->encode($originals[$row->name])]);
                $filled++;
            }
        }

        if ($type !== 'templates') {
            $sourceType = $type === 'modules' ? LayoutSourceType::Module : LayoutSourceType::Plugin;
            $rows = $this->extensions->getWithoutOriginalContentBySource($sourceType, $identifier);

            foreach ($rows->groupBy('template_id') as $templateId => $group) {
                $templateIdentifier = (string) $this->templates->findById((int) $templateId)?->identifier;
                $fragments = $templateIdentifier !== '' ? $reader->fragments($backupPath, $identifier, $templateIdentifier) : [];

                foreach ($group as $row) {
                    $original = $fragments[$row->fragment_key] ?? null;
                    if ($original !== null && $this->computeContentHash($original) === $row->original_content_hash) {
                        $this->extensions->saveModelAttributes($row, ['original_content' => $this->encode($original)]);
                        $filled++;
                    }
                }
            }
        }

        return $filled;
    }

    /**
     * 업데이트 백업의 레이아웃을 컴파일해 이전 판 원본 표를 만듭니다.
     *
     * @param  string|null  $backupPath  업데이트 백업 디렉토리 (없으면 빈 배열)
     * @param  string  $type  확장 유형
     * @param  string  $identifier  확장 식별자
     * @return array<string, array<string, mixed>> DB 레이아웃 이름 => 원본
     */
    public function compileBackupOriginals(?string $backupPath, string $type, string $identifier): array
    {
        return $backupPath === null ? [] : (new BackupLayoutReader)->layouts($backupPath, $type, $identifier);
    }

    /**
     * 업데이트 백업의 확장 조각을 한 템플릿 표로 컴파일합니다.
     *
     * @param  string|null  $backupPath  업데이트 백업 디렉토리
     * @param  string  $identifier  확장 식별자
     * @param  string  $templateIdentifier  템플릿 식별자
     * @return array<string, array<string, mixed>> 조각 식별자 => 원본
     */
    public function compileBackupFragments(?string $backupPath, string $identifier, string $templateIdentifier): array
    {
        return $backupPath === null ? [] : (new BackupLayoutReader)->fragments($backupPath, $identifier, $templateIdentifier);
    }

    /**
     * 병합 기준(base)을 찾습니다 (W3) — ① 원본 본문 ② 해시가 맞는 백업 컴파일 ③ 해시가 맞는 지금 파일.
     *
     * @param  TemplateLayout|LayoutExtension  $row  행
     * @param  array<string, mixed>|null  $backupOriginal  백업 컴파일 원본
     * @param  array<string, mixed>  $fileOriginal  지금 파일 원본
     * @return array<string, mixed>|null 기준, 없으면 null
     */
    private function resolveBase(TemplateLayout|LayoutExtension $row, ?array $backupOriginal, array $fileOriginal): ?array
    {
        $raw = $row->getRawOriginal('original_content');
        if (is_string($raw) && $raw !== '') {
            $decoded = $this->decodeRaw($raw);

            return is_array($decoded) ? $decoded : null;
        }

        foreach ([$backupOriginal, $fileOriginal] as $candidate) {
            if (is_array($candidate) && $row->original_content_hash !== null
                && $this->computeContentHash(LayoutPartialOrigin::strip($candidate)) === $row->original_content_hash) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * 레이아웃 행을 파일 판으로 덮어씁니다 (내용이 같으면 원본 정보만 갱신).
     *
     * @param  TemplateLayout  $row  행
     * @param  array<string, mixed>  $identity  행 식별 정보
     * @param  array<string, mixed>  $fileData  파일 판
     * @param  array<string, mixed>  $fileOriginal  파일 판 원본
     * @return bool 본문이 바뀌었으면 true
     */
    private function overwriteLayout(TemplateLayout $row, array $identity, array $fileData, array $fileOriginal): bool
    {
        $changed = ! LayoutContentComparator::sameDocument($row->getRawOriginal('content'), $fileData);
        $values = $this->freshLayoutValues($identity, $fileData, $fileOriginal);

        if ($changed) {
            $values['content'] = $fileData;
            $values['lock_version'] = (int) $row->lock_version + 1;
        }

        $this->layouts->saveAttributes($row->id, $values);

        return $changed;
    }

    /**
     * 확장 조각 행을 파일 판으로 덮어씁니다 (내용이 같으면 원본 정보만 갱신).
     *
     * @param  LayoutExtension  $row  행
     * @param  array<string, mixed>  $content  파일 판
     * @return bool 본문이 바뀌었으면 true
     */
    private function overwriteExtension(LayoutExtension $row, array $content): bool
    {
        $changed = ! LayoutContentComparator::sameDocument($row->getRawOriginal('content'), $content);
        $values = $this->freshExtensionValues($content);

        if ($changed) {
            $values['content'] = $content;
            $values['lock_version'] = (int) $row->lock_version + 1;
        }

        $this->extensions->saveModelAttributes($row, $values);

        return $changed;
    }

    /**
     * 레이아웃 행의 원본·파생 컬럼 값을 만듭니다 — 원본 정보는 늘 새 파일 기준(다음 업데이트의 기준이 된다).
     *
     * @param  array<string, mixed>  $identity  행 식별 정보
     * @param  array<string, mixed>  $fileData  파일 판
     * @param  array<string, mixed>  $fileOriginal  파일 판 원본
     * @param  array<string, mixed>|null  $content  저장될 본문 (파생 컬럼 계산용, null 이면 파일 판)
     * @return array<string, mixed> 컬럼 값
     */
    private function freshLayoutValues(array $identity, array $fileData, array $fileOriginal, ?array $content = null): array
    {
        $values = [
            'source_type' => $identity['source_type'],
            'source_identifier' => $identity['source_identifier'],
            'original_content' => $this->encode($fileOriginal),
            'original_content_hash' => $this->computeContentHash($fileData),
            'original_content_size' => $this->computeContentSize($fileData),
        ];

        // 템플릿 자기 레이아웃은 `extends` 컬럼을 채우지 않아 왔다(본문의 extends 를 읽는다) — 종전 동작을 지킨다.
        // 모듈·플러그인은 본문에서, 오버라이드는 본문에 extends 가 없어 호출부가 파일 선언값을 직접 넘긴다.
        if (array_key_exists('extends', $identity)) {
            $values['extends'] = $identity['extends'];
        } elseif ($identity['track_extends'] ?? false) {
            $values['extends'] = ($content ?? $fileData)['extends'] ?? null;
        }

        return $values;
    }

    /**
     * 확장 조각 행의 원본·파생 컬럼 값을 만듭니다.
     *
     * @param  array<string, mixed>  $fileContent  파일 판
     * @param  array<string, mixed>|null  $content  저장될 본문 (null 이면 파일 판)
     * @return array<string, mixed> 컬럼 값
     */
    private function freshExtensionValues(array $fileContent, ?array $content = null): array
    {
        return [
            'priority' => ($content ?? $fileContent)['priority'] ?? 100,
            'is_active' => true,
            'original_content' => $this->encode($fileContent),
            'original_content_hash' => $this->computeContentHash($fileContent),
            'original_content_size' => $this->computeContentSize($fileContent),
        ];
    }

    /**
     * 병합·충돌이 난 레이아웃에 편집기 버전 이력을 남깁니다 — 운영자가 병합 전 판으로 되돌릴 수 있게.
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  array<string, mixed>  $before  병합 전 운영자 판
     * @param  LayoutMergeResult  $result  병합 결과
     * @return void 반환값 없음
     */
    private function recordLayoutVersion(int $layoutId, array $before, LayoutMergeResult $result): void
    {
        if ($this->layoutVersions->getNextVersion($layoutId) <= 1) {
            $this->layoutVersions->saveVersion($layoutId, $before, null);
        }

        $this->layoutVersions->saveVersion($layoutId, $result->merged, $before, null, $this->summaryExtra($result));
    }

    /**
     * 버전 이력 변경 요약에 덧붙일 병합 출처 정보.
     *
     * @param  LayoutMergeResult  $result  병합 결과
     * @return array{source: string, applied: list<array<string, string>>, conflicts: list<array<string, string>>} 부가 요약
     */
    private function summaryExtra(LayoutMergeResult $result): array
    {
        return ['source' => 'extension_update', 'applied' => $result->applied, 'conflicts' => $result->conflicts];
    }

    /**
     * 확장이 소유한 레이아웃 행 (지운 행 제외).
     *
     * @param  string  $type  확장 유형
     * @param  string  $identifier  확장 식별자
     * @return iterable<TemplateLayout> 행 목록
     */
    private function rowsOwnedBy(string $type, string $identifier): iterable
    {
        if ($type === 'templates') {
            $templateId = $this->templates->findByIdentifier($identifier)?->id;

            // 템플릿 자기 레이아웃 + 오버라이드 (둘 다 source_type = template). 본문을 싣는 조회를 쓴다 —
            // getByTemplateId() 는 목록용이라 content·original_content 를 고르지 않는다.
            return $templateId === null
                ? []
                : $this->layouts->getByTemplateIdWithFilter((int) $templateId, 'template', null)
                    ->concat($this->layouts->getByTemplateIdWithFilter((int) $templateId, 'template', $identifier));
        }

        return $this->layouts->getBySourceIdentifier($identifier, $type === 'modules' ? LayoutSourceType::Module : LayoutSourceType::Plugin);
    }

    /**
     * 행의 출처 타입을 enum 으로 읽습니다.
     *
     * @param  TemplateLayout  $row  행
     * @return LayoutSourceType|null 출처 타입
     */
    private function sourceTypeOf(TemplateLayout $row): ?LayoutSourceType
    {
        return $row->source_type instanceof LayoutSourceType ? $row->source_type : LayoutSourceType::tryFrom((string) $row->source_type);
    }

    /**
     * DB 원문을 빈 map 모양을 지키며 디코드합니다.
     *
     * @param  mixed  $raw  원문
     * @return array<string, mixed> 디코드 결과 (실패하면 빈 배열)
     */
    private function decodeRaw(mixed $raw): array
    {
        $decoded = is_string($raw) ? EmptyMap::decodePreserving($raw) : $raw;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 원본을 JSON 문자열로 저장용 인코드합니다.
     *
     * @param  array<string, mixed>  $value  원본
     * @return string JSON 문자열
     */
    private function encode(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

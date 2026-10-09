<?php

namespace App\Services;

use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\LayoutExtensionRepositoryInterface;
use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\LayoutVersionRepositoryInterface;
use App\Enums\LayoutSourceType;
use App\Exceptions\LayoutFileOperationException;
use App\Extension\HookManager;
use App\Extension\Traits\ClearsTemplateCaches;
use App\Models\LayoutExtension;
use App\Models\TemplateLayout;
use App\Rules\SafeCustomAssetPath;
use App\Support\LayoutMarkup\PartialReferenceNotation;
use Illuminate\Support\Collection;

/**
 * 레이아웃 **파일 단위** 조작 서비스 — 생성·삭제·이름변경·검색.
 *
 * {@see LayoutService} 는 한 레이아웃의 *본문*을 다룬다(병합·저장·판본). 이 서비스는 그보다
 * 한 층 위, **파일이라는 단위 자체**를 다룬다. 둘을 한 클래스에 합치지 않은 이유는 위험의
 * 성격이 다르기 때문이다 — 본문 저장은 판본으로 되돌릴 수 있지만, 참조가 걸린 파일을
 * 지우거나 이름을 바꾸면 그 파일을 가리키던 화면이 통째로 깨지고 판본으로는 복구되지 않는다.
 * 그래서 여기 있는 모든 파괴적 연산은 **사전 검사를 통과해야만** 실행된다.
 *
 * ## "파일" 은 파일이 아니다
 *
 * `template_layouts` 의 **DB 행**이다. `name` 이 `partials/admin_user_list/_modal_form` 같은
 * 경로 모양 문자열일 뿐 디스크에 폴더는 없다(결정 D-2 — 가상 폴더). 그래서 "폴더를 만든다"
 * 는 서버 연산이 없고, 폴더는 파일이 저장되는 순간 이름의 `/` 로 확정된다.
 *
 * ## 검색이 마크업을 보는 이유
 *
 * DB 에는 JSON 만 있는데 사용자가 편집기에서 보는 것은 `.g7.html` 마크업이다. 줄 번호가
 * 편집기와 일치해야 검색 결과를 눌러 그 줄로 갈 수 있으므로, 검색 대상도 역변환한 마크업이어야
 * 한다. 역변환은 비싸므로 `lock_version` 을 키에 넣어 캐시한다 — 저장하면 키가 바뀌어
 * 자동으로 무효화되고, 별도 무효화 훅이나 컬럼이 필요 없다.
 */
class LayoutFileService
{
    use ClearsTemplateCaches;

    /**
     * 새 레이아웃 이름 규칙 — 세그먼트는 영숫자·`_`·`-` 만, 선행·연속·후행 슬래시 금지.
     *
     * `.` 을 빼는 이유: 이름은 `{name}.g7.html` 로 파일명을 만들고 `{name}.json` 으로 참조되므로,
     * 이름 안에 확장자 구분자가 들어가면 그 두 표기가 모호해진다.
     */
    public const NAME_PATTERN = '/^[A-Za-z0-9_\-]+(\/[A-Za-z0-9_\-]+)*$/';

    /** 검색 — 돌려주는 파일 수 상한 */
    public const SEARCH_MAX_FILES = 100;

    /** 검색 — 파일당 매치 수 상한 */
    public const SEARCH_MAX_MATCHES_PER_FILE = 20;

    /** 검색 — 미리보기 문자열 길이 상한 */
    public const SEARCH_PREVIEW_LENGTH = 200;

    /** 역변환 마크업 캐시 TTL (초) */
    private const MARKUP_CACHE_TTL = 86400;

    /**
     * @param  LayoutRepositoryInterface  $layoutRepository  레이아웃 행 조회·영속
     * @param  LayoutVersionRepositoryInterface  $versionRepository  판본 이력 적재
     * @param  LayoutService  $layoutService  레이아웃 공통 규칙(참조 판정·기본 골격 검증)
     * @param  LayoutMarkupService  $markupService  JSON ↔ `.g7.html` 역변환·컴파일
     * @param  CacheInterface  $cache  역변환 마크업 캐시 (`lock_version` 을 키에 넣어 저장 시 자동 무효화)
     * @param  LayoutExtensionRepositoryInterface  $extensionRepository  이 템플릿에 주입된 확장 조각 조회
     */
    public function __construct(
        private LayoutRepositoryInterface $layoutRepository,
        private LayoutVersionRepositoryInterface $versionRepository,
        private LayoutService $layoutService,
        private LayoutMarkupService $markupService,
        private CacheInterface $cache,
        private LayoutExtensionRepositoryInterface $extensionRepository,
    ) {}

    /**
     * 새 레이아웃 파일을 만듭니다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $templateIdentifier  템플릿 identifier (source_identifier 로 기록)
     * @param  string  $name  레이아웃 이름 (경로 모양 문자열)
     * @param  array<string, mixed>  $content  컴파일이 끝난 레이아웃 JSON
     * @param  int|null  $userId  만든 사용자 ID
     * @return TemplateLayout 만들어진 레이아웃
     *
     * @throws LayoutFileOperationException 이름 규칙 위반·중복일 때
     */
    public function create(
        int $templateId,
        string $templateIdentifier,
        string $name,
        array $content,
        ?int $userId = null,
    ): TemplateLayout {
        $this->assertValidName($name);

        if ($this->layoutRepository->findByName($templateId, $name) !== null) {
            throw new LayoutFileOperationException('layouts.errors.name_taken', ['name' => $name]);
        }

        // 본문의 `layout_name` 을 파일 이름으로 맞춘다 — 이름변경과 같은 규칙이다. 코드 편집기의 «새 이름으로
        // 저장» 은 지워진 파일의 편집분(`<layout name="옛이름">`)을 그대로 싣는데, 맞추지 않으면 파일 이름과
        // 다른 이름의 산출물이 저장되고 그 마크업을 다시 저장할 때마다 옛 이름이 되살아난다.
        if (array_key_exists('layout_name', $content)) {
            $content['layout_name'] = $name;
        }

        HookManager::doAction('core.layout.before_create', $templateId, $name, $content);

        // 지워진(soft delete) 같은 이름의 행이 남아 있으면 **되살려 쓴다**.
        //
        // 유니크 키 `(template_id, name, source_type)` 는 지워진 행도 포함하므로, 그냥 INSERT 하면
        // 중복 키로 터진다(실측 2026-09-12: 화면에서 지운 이름을 다시 만들면 500). 사용자가 뜻하는
        // 것은 "그 이름의 파일을 다시 만든다" 이고, 되살려 쓰면 그 파일의 판본 이력도 이어진다 —
        // 새 행을 만들면 지우기 전 이력이 영영 끊긴다.
        $trashed = $this->layoutRepository->findWithTrashedByName($templateId, $name);

        if ($trashed !== null) {
            $this->layoutRepository->restoreTrashed($trashed);

            $restored = $this->layoutRepository->saveAttributes($trashed->id, [
                'content' => $content,
                'extends' => $content['extends'] ?? null,
                'source_type' => LayoutSourceType::Template,
                'source_identifier' => $templateIdentifier,
                // 원본 해시는 "파일에서 등록된 행" 의 표식이다 — 되살린 행이 지워진 오버라이드였다면 그 표식을 지워야
                // 템플릿 갱신의 고아 오버라이드 정리가 운영자가 만든 이 레이아웃을 지우지 않는다 (#703).
                'original_content_hash' => null,
                'original_content_size' => null,
                'updated_by' => $userId,
                'lock_version' => (int) ($trashed->lock_version ?? 0) + 1,
            ]);

            $this->versionRepository->saveVersion($restored->id, $content, null);
            $this->layoutService->clearDependentLayoutsCache($templateId, $name);
            $this->incrementExtensionCacheVersion();

            HookManager::doAction('core.layout.after_create', $restored, $templateId, $name);

            return $restored;
        }

        $layout = $this->layoutRepository->updateOrCreate(
            ['template_id' => $templateId, 'name' => $name],
            [
                'content' => $content,
                'extends' => $content['extends'] ?? null,
                'source_type' => LayoutSourceType::Template,
                'source_identifier' => $templateIdentifier,
                'created_by' => $userId,
                'updated_by' => $userId,
                'lock_version' => 0,
            ],
        );

        // 만든 직후 본문을 baseline 판본으로 남긴다 — 이게 없으면 첫 수정 뒤 "만들었을 때 상태"
        // 로 되돌릴 방법이 없다({@see LayoutService::updateLayout()} 의 baseline 과 같은 취지).
        $this->versionRepository->saveVersion($layout->id, $content, null);

        $this->layoutService->clearDependentLayoutsCache($templateId, $name);
        $this->incrementExtensionCacheVersion();

        HookManager::doAction('core.layout.after_create', $layout, $templateId, $name);

        return $layout;
    }

    /**
     * 레이아웃 파일 이름을 바꿉니다 (= 이동).
     *
     * **새 행을 만들지 않는다.** 같은 행의 `name` 을 갱신하므로 판본 이력이 그대로 승계된다 —
     * 복사 후 삭제로 구현하면 그 파일의 이력이 이름을 바꾼 지점에서 끊긴다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  현재 이름
     * @param  string  $newName  새 이름
     * @param  int|null  $userId  수행한 사용자 ID
     * @return TemplateLayout 이름이 바뀐 레이아웃
     *
     * @throws LayoutFileOperationException 대상 부재·규칙 위반·중복·참조 차단 시
     */
    public function rename(
        int $templateId,
        string $name,
        string $newName,
        ?int $userId = null,
    ): TemplateLayout {
        $this->assertValidName($newName);

        $layout = $this->requireLayout($templateId, $name);

        if ($newName === $name) {
            return $layout;
        }

        // 지워진 행도 유니크 키를 차지하므로 함께 본다 — 안 보면 UPDATE 가 중복 키로 터진다.
        $targetTaken = $this->layoutRepository->findWithTrashedByName($templateId, $newName) !== null;

        if ($targetTaken) {
            throw new LayoutFileOperationException('layouts.errors.name_taken', ['name' => $newName], [], 409);
        }

        $this->assertOwnedByTemplate($layout, 'layouts.errors.rename_blocked_source_type');
        $this->assertNotReferenced($templateId, $name, 'layouts.errors.rename_blocked_by_reference');

        $oldContent = $layout->content;
        $content = is_array($oldContent) ? $oldContent : [];

        // 본문의 `layout_name` 도 함께 옮긴다 — 남겨 두면 저장할 때마다 옛 이름이 되살아나고
        // 컴파일러가 파일명과 다른 이름으로 산출물을 만든다.
        if (array_key_exists('layout_name', $content)) {
            $content['layout_name'] = $newName;
        }

        HookManager::doAction('core.layout.before_rename', $templateId, $name, $newName);

        $layout = $this->layoutRepository->saveAttributes($layout->id, [
            'name' => $newName,
            'content' => $content,
            'lock_version' => (int) ($layout->lock_version ?? 0) + 1,
            'updated_by' => $userId,
        ]);

        $this->versionRepository->saveVersion($layout->id, $content, $oldContent);

        // 옛 이름·새 이름 양쪽의 캐시를 모두 턴다 — 한쪽만 털면 옛 이름으로 캐시된 병합본이
        // 남아 그 이름을 다시 만들었을 때 남의 본문이 나온다.
        $this->layoutService->clearDependentLayoutsCache($templateId, $name);
        $this->layoutService->clearDependentLayoutsCache($templateId, $newName);
        $this->forgetMarkupCache($layout->id);
        $this->incrementExtensionCacheVersion();

        HookManager::doAction('core.layout.after_rename', $layout->fresh(), $templateId, $name, $newName);

        return $layout->fresh();
    }

    /**
     * 레이아웃 파일을 지웁니다 (**soft delete** — 되돌릴 수 있다).
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  bool  $confirmed  라우트가 연결된 레이아웃을 지울 때 필요한 사용자 확인
     * @param  string|null  $routePath  그 레이아웃에 걸린 라우트 경로 — 확인 문구에 실어 무엇이 닫히는지 알린다
     * @return TemplateLayout 지워진 레이아웃 (soft delete 된 모델)
     *
     * @throws LayoutFileOperationException 대상 부재·참조 차단·확인 필요 시
     */
    public function delete(
        int $templateId,
        string $name,
        bool $confirmed = false,
        ?string $routePath = null,
    ): TemplateLayout {
        $layout = $this->requireLayout($templateId, $name);

        $this->assertOwnedByTemplate($layout, 'layouts.errors.delete_blocked_source_type');
        $this->assertNotReferenced($templateId, $name, 'layouts.errors.delete_blocked_by_reference');

        // 라우트가 걸린 레이아웃은 막지 않는다 — 지우는 것이 정당한 경우가 있다. 다만 그 화면이
        // 곧바로 404 가 된다는 사실을 사용자가 모른 채 지나갈 수는 없게 한 단계를 더 둔다.
        if ($routePath !== null && $routePath !== '' && ! $confirmed) {
            throw new LayoutFileOperationException(
                'layouts.errors.delete_requires_confirmation',
                ['name' => $name, 'route' => $routePath],
                [['name' => $name, 'kind' => 'route', 'route_path' => $routePath]],
            );
        }

        HookManager::doAction('core.layout.before_delete', $templateId, $name);

        // 역변환 캐시는 지우기 **전에** 턴다 — soft delete 뒤에는 `findById()` 가 전역 스코프에
        // 걸려 null 을 돌려주므로 마지막 키를 찾지 못해 무동작이 된다(6차 검증 실측).
        $this->forgetMarkupCache($layout->id);

        $this->layoutRepository->softDelete($layout);

        $this->layoutService->clearDependentLayoutsCache($templateId, $name);
        $this->incrementExtensionCacheVersion();

        HookManager::doAction('core.layout.after_delete', $layout, $templateId, $name);

        return $layout;
    }

    /**
     * 새 레이아웃의 기본 골격 마크업을 만듭니다.
     *
     * 빈 문자열로 만들면 컴파일 오류라 파일을 만들자마자 빨간 줄이 뜬다 — 곧바로 컴파일되는
     * 최소 골격에서 시작한다. partial 은 `<layout>` 래퍼 없이 노드 하나만 둔다(조각은 문서가
     * 아니라 다른 문서에 끼워지는 조각이므로 래퍼가 있으면 이중 문서가 된다).
     *
     * @param  string  $name  레이아웃 이름
     * @return string `.g7.html` 마크업
     */
    public function defaultSkeleton(string $name): string
    {
        // **이름이 무엇이든 문서 골격이다.** 이름의 `partials/`·선행 `_` 는 편집기 트리에서 폴더를
        // 만드는 문자열일 뿐, 행의 성격을 바꾸지 않는다 — `template_layouts` 의 한 행은 언제나
        // 하나의 문서다.
        //
        // 조각(`<partial>`)으로 만들지 않는 이유: 조각의 산출은 문서가 아니라 **노드**여서
        // 저장 경로(`UpdateLayoutContentRequest`)의 문서 규칙을 지날 수 없고, 억지로 통과시켜도
        // 본문 마스킹이 그 노드를 문서 골격으로 뭉갠다(실측 2026-09-12: 저장 뒤 본문이
        // `{layout_name, components: []}` 로 남았다). 화면에 실리는 조각은 디스크의 `.g7.html`
        // 이 설치 시점에 인라인되는 것이라, DB 행으로 만든 조각은 어느 화면도 쓰지 않는다.
        //
        // 빈 문자열 대신 골격을 넣는 이유: 빈 본문은 컴파일 오류라 파일을 만들자마자 빨간 줄이 뜬다.
        // `<slot>` 을 쓰지 않는 이유: 슬롯은 **상속 레이아웃**이 부모의 자리를 채우는 문법이라
        // 독립 문서에 쓰면 `components` 가 비어 구조 검증에서 막힌다.
        $segments = explode('/', $name);
        $documentName = (string) end($segments);

        return <<<HTML
        <layout name="{$documentName}" version="1.0.0">
          <div></div>
        </layout>

        HTML;
    }

    /**
     * 파일명·본문에서 검색어를 찾습니다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $templateIdentifier  템플릿 identifier (역변환 문맥)
     * @param  string  $keyword  검색어
     * @param  string  $scope  `name` | `content` | `both`
     * @param  int  $limit  돌려줄 파일 수 상한
     * @return array<int, array<string, mixed>> 검색 결과 행
     */
    public function search(
        int $templateId,
        string $templateIdentifier,
        string $keyword,
        string $scope = 'both',
        int $limit = self::SEARCH_MAX_FILES,
    ): array {
        $limit = max(1, min($limit, self::SEARCH_MAX_FILES));
        $needle = mb_strtolower($keyword);
        $results = [];

        // 본문이 필요하므로 `eachWithContent` 로 흘려 읽는다. `getByTemplateId()` 는 `content` 를
        // select 하지 않아 여기 쓰면 모든 파일이 빈 본문으로 보이고 내용 검색이 조용히 0건이 된다.
        $this->layoutRepository->eachWithContent($templateId, function (TemplateLayout $layout) use (
            &$results, $limit, $needle, $scope, $templateIdentifier
        ): ?bool {
            if (count($results) >= $limit) {
                return false;
            }

            $row = $this->matchOne(
                key: 'layout:'.$layout->name,
                kind: 'layout',
                name: (string) $layout->name,
                haystackName: (string) $layout->name,
                needle: $needle,
                scope: $scope,
                markup: fn (): string => $this->markupFor($layout, $templateIdentifier),
            );

            if ($row !== null) {
                $results[] = $row;
            }

            return null;
        });

        // 순회 커서가 기본키라 결과가 id 순으로 쌓인다 — 화면은 이름순 트리이므로 여기서 맞춘다.
        usort($results, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        // 확장 조각도 같은 방식으로 포함한다(§5.2). 이름 범위에서도 빼지 않는다 — `matchOne` 이
        // `scope=name` 이면 마크업 클로저를 부르지 않으므로 역변환 비용은 그대로 0 이다.
        foreach ($this->extensionsFor($templateId) as $extension) {
            if (count($results) >= $limit) {
                break;
            }

            $label = (string) ($extension->fragment_key ?: $extension->target_name);

            $row = $this->matchOne(
                key: 'ext:'.$extension->id,
                kind: 'extension',
                name: $label,
                haystackName: $label,
                needle: $needle,
                scope: $scope,
                markup: fn (): string => $this->extensionMarkupFor($extension, $templateIdentifier),
            );

            if ($row !== null) {
                $results[] = $row;
            }
        }

        return $results;
    }

    /**
     * 레이아웃의 역변환 마크업을 돌려줍니다 (`lock_version` 키 캐시).
     *
     * @param  TemplateLayout  $layout  대상 레이아웃
     * @param  string  $templateIdentifier  템플릿 identifier
     * @return string `.g7.html` 마크업 (역변환 실패 시 빈 문자열)
     */
    public function markupFor(TemplateLayout $layout, string $templateIdentifier): string
    {
        $raw = (string) $layout->getRawOriginal('content');

        // 본문이 실려 오지 않은 모델(그 컬럼을 select 하지 않은 조회)로는 역변환하지 않는다.
        // 여기서 빈 값을 캐시하면 그 키가 TTL 동안 살아남아, 본문을 제대로 실은 다음 조회까지
        // 같은 파일이 계속 빈 내용으로 보인다 — 조용하고 오래 가는 결함이 된다.
        if ($raw === '') {
            return '';
        }

        $key = $this->markupCacheKey((int) $layout->id, (int) ($layout->lock_version ?? 0));

        return (string) $this->cache->remember($key, function () use ($raw, $templateIdentifier): string {
            try {
                $json = $this->markupService->decodeStoredContent($raw);

                return $this->markupService->toMarkup($json, $templateIdentifier)['markup'] ?? '';
            } catch (\Throwable) {
                // 역변환이 실패해도 검색 전체를 세우지 않는다 — 그 파일만 내용 검색에서 빠진다.
                return '';
            }
        }, self::MARKUP_CACHE_TTL);
    }

    /**
     * 이 레이아웃을 참조하는 다른 레이아웃 목록을 돌려줍니다.
     *
     * 참조 종류는 둘이다 — `extends` 로 상속하는 자식, `partial` 로 끼워 넣는 문서
     * (`<include src>` · `<modal src>` 가 컴파일된 결과).
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  참조 대상 레이아웃 이름
     * @return array<int, array{name: string, kind: string, line: int}> 참조 목록
     */
    public function findReferences(int $templateId, string $name): array
    {
        $references = [];

        foreach ($this->layoutRepository->getChildrenByExtends($templateId, $name) as $child) {
            $references[] = ['name' => (string) $child->name, 'kind' => 'extends', 'line' => 0];
        }

        $partialToken = $name.PartialReferenceNotation::JSON_EXTENSION;
        $segments = explode('/', $partialToken);
        $partialNeedle = (string) end($segments);

        $this->layoutRepository->eachWithContent($templateId, function (TemplateLayout $layout) use (
            &$references, $name, $partialToken, $partialNeedle
        ): void {
            if ((string) $layout->name === $name) {
                return;
            }

            $raw = (string) $layout->getRawOriginal('content');

            // 값이 통째로 일치할 때만 참조다 — 부분 문자열 검사는 `_modal_form` 이
            // `_modal_form_extra` 를 참조로 오인하게 만든다. 먼저 싼 문자열 검사로 거르고,
            // 남은 것만 구조 순회로 확정한다(102개 레이아웃 전수 순회를 피한다).
            //
            // 거르는 토큰은 **마지막 세그먼트**다. 저장된 JSON 은 `json_encode` 기본 동작에 따라
            // 슬래시를 `\/` 로 이스케이프하므로, 경로 전체로 찾으면 언제나 빗나가 차단이 통째로
            // 무력화된다(실측: 참조가 걸린 조각이 200 으로 삭제됐다).
            if (! str_contains($raw, $partialNeedle)) {
                return;
            }

            $content = $layout->content;

            if (! is_array($content) || ! $this->containsPartialReference($content, $partialToken)) {
                return;
            }

            $references[] = [
                'name' => (string) $layout->name,
                'kind' => 'partial',
                'line' => $this->lineOfPartialReference($layout, $name),
            ];
        });

        return $references;
    }

    /**
     * 이름 규칙을 강제합니다.
     *
     * 세그먼트 판정은 {@see SafeCustomAssetPath} 와 같은 방식이다 — 문자열 포함
     * 검사(`str_contains('..')`)는 정상 이름을 막으면서 인코딩된 탈출을 놓친다.
     *
     * @param  string  $name  검사할 이름
     * @return void 반환값 없음
     *
     * @throws LayoutFileOperationException 규칙 위반 시
     */
    private function assertValidName(string $name): void
    {
        $invalid = $name === ''
            || strlen($name) > 255
            || ! preg_match(self::NAME_PATTERN, $name);

        if (! $invalid) {
            foreach (explode('/', $name) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    $invalid = true;
                    break;
                }
            }
        }

        if ($invalid) {
            throw new LayoutFileOperationException('layouts.errors.invalid_name', ['name' => $name]);
        }
    }

    /**
     * 레이아웃을 찾거나 예외를 던집니다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @return TemplateLayout 찾은 레이아웃
     *
     * @throws LayoutFileOperationException 없을 때 (404)
     */
    private function requireLayout(int $templateId, string $name): TemplateLayout
    {
        $layout = $this->layoutRepository->findByName($templateId, $name);

        if ($layout === null) {
            throw new LayoutFileOperationException('layouts.errors.not_found', ['name' => $name], [], 404);
        }

        return $layout;
    }

    /**
     * 템플릿이 소유한 레이아웃인지 확인합니다.
     *
     * 모듈·플러그인이 제공한 원본을 지우거나 이름을 바꾸면 그 확장이 깨진다. 오버라이드를
     * 되돌리는 것은 성질이 다른 별도 기능이다.
     *
     * @param  TemplateLayout  $layout  대상 레이아웃
     * @param  string  $errorKey  위반 시 쓸 다국어 키
     * @return void 반환값 없음
     *
     * @throws LayoutFileOperationException 템플릿 소유가 아닐 때
     */
    private function assertOwnedByTemplate(TemplateLayout $layout, string $errorKey): void
    {
        $sourceType = $layout->source_type instanceof LayoutSourceType
            ? $layout->source_type
            : LayoutSourceType::tryFrom((string) $layout->source_type);

        if ($sourceType !== null && $sourceType !== LayoutSourceType::Template) {
            throw new LayoutFileOperationException($errorKey, [
                'name' => (string) $layout->name,
                // 종류는 사용 언어로 싣는다 — 원값(`module`)을 그대로 넣으면 「module(sirsoft-board)이(가) …」 로 보인다.
                'source' => __('extension_owner_type.'.$sourceType->value),
                'identifier' => (string) ($layout->source_identifier ?? ''),
            ]);
        }
    }

    /**
     * 참조하는 레이아웃이 없는지 확인합니다.
     *
     * 참조를 **자동으로 고쳐 주지 않는다** — 표현식 문자열까지 치환하면 되돌릴 수 없는 사고가
     * 된다. 대신 무엇이 붙들고 있는지 목록으로 돌려주고 사용자가 정리하게 한다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @param  string  $name  레이아웃 이름
     * @param  string  $errorKey  위반 시 쓸 다국어 키
     * @return void 반환값 없음
     *
     * @throws LayoutFileOperationException 참조가 있을 때
     */
    private function assertNotReferenced(int $templateId, string $name, string $errorKey): void
    {
        $references = $this->findReferences($templateId, $name);

        if ($references !== []) {
            throw new LayoutFileOperationException(
                $errorKey,
                ['name' => $name, 'count' => count($references)],
                $references,
            );
        }
    }

    /**
     * JSON 트리에 주어진 `partial` 참조가 있는지 재귀 판정합니다.
     *
     * @param  mixed  $node  순회 대상
     * @param  string  $token  찾을 `partial` 값 (`이름.json`)
     * @return bool 있으면 true
     */
    private function containsPartialReference(mixed $node, string $token): bool
    {
        if (! is_array($node)) {
            return false;
        }

        if (isset($node['partial']) && is_string($node['partial']) && $node['partial'] === $token) {
            return true;
        }

        foreach ($node as $child) {
            if ($this->containsPartialReference($child, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 참조가 적힌 줄 번호를 마크업에서 찾습니다 (편집기와 같은 좌표계, 1-base).
     *
     * @param  TemplateLayout  $layout  참조하는 레이아웃
     * @param  string  $name  참조 대상 이름
     * @return int 줄 번호 (못 찾으면 0)
     */
    private function lineOfPartialReference(TemplateLayout $layout, string $name): int
    {
        $identifier = (string) ($layout->source_identifier ?? '');

        if ($identifier === '') {
            return 0;
        }

        $markup = $this->markupFor($layout, $identifier);

        if ($markup === '') {
            return 0;
        }

        $token = $name.PartialReferenceNotation::MARKUP_EXTENSION;

        foreach (explode("\n", $markup) as $index => $line) {
            if (str_contains($line, $token)) {
                return $index + 1;
            }
        }

        return 0;
    }

    /**
     * 한 대상에 대해 이름·내용 매칭을 수행합니다.
     *
     * @param  string  $key  트리 노드 키
     * @param  string  $kind  `layout` | `extension`
     * @param  string  $name  표시 이름
     * @param  string  $haystackName  이름 매칭 대상 문자열
     * @param  string  $needle  소문자 검색어
     * @param  string  $scope  검색 범위
     * @param  callable(): string  $markup  내용 매칭이 필요할 때만 호출되는 마크업 공급자
     * @return array<string, mixed>|null 매칭 결과 (없으면 null)
     */
    private function matchOne(
        string $key,
        string $kind,
        string $name,
        string $haystackName,
        string $needle,
        string $scope,
        callable $markup,
    ): ?array {
        $nameMatched = $scope !== 'content' && str_contains(mb_strtolower($haystackName), $needle);
        $lines = [];

        if ($scope !== 'name') {
            $lines = $this->scanLines($markup(), $needle);
        }

        if (! $nameMatched && $lines === []) {
            return null;
        }

        return [
            'key' => $key,
            'kind' => $kind,
            'name' => $name,
            'name_matched' => $nameMatched,
            'match_count' => count($lines),
            'lines' => $lines,
        ];
    }

    /**
     * 마크업을 줄 단위로 훑어 매치를 모읍니다.
     *
     * @param  string  $markup  대상 마크업
     * @param  string  $needle  소문자 검색어
     * @return array<int, array{line: int, column: int, preview: string}> 매치 목록
     */
    private function scanLines(string $markup, string $needle): array
    {
        if ($markup === '' || $needle === '') {
            return [];
        }

        $matches = [];

        foreach (explode("\n", $markup) as $index => $line) {
            if (count($matches) >= self::SEARCH_MAX_MATCHES_PER_FILE) {
                break;
            }

            $column = mb_strpos(mb_strtolower($line), $needle);

            if ($column === false) {
                continue;
            }

            $preview = trim($line);

            if (mb_strlen($preview) > self::SEARCH_PREVIEW_LENGTH) {
                $preview = mb_substr($preview, 0, self::SEARCH_PREVIEW_LENGTH).'…';
            }

            $matches[] = [
                'line' => $index + 1,
                'column' => $column + 1,
                'preview' => $preview,
            ];
        }

        return $matches;
    }

    /**
     * 이 템플릿에 주입되는 확장 조각을 돌려줍니다.
     *
     * 저장소가 **활성 조각만** 돌려준다 — 트리에 뜨지 않는 조각이 검색에만 걸리면 눌러도 갈 곳이 없다.
     *
     * @param  int  $templateId  대상 템플릿 ID
     * @return Collection<int, LayoutExtension> 조각 목록
     */
    private function extensionsFor(int $templateId)
    {
        return $this->extensionRepository->getByTemplateId($templateId);
    }

    /**
     * 확장 조각의 역변환 마크업을 돌려줍니다 (조각도 `lock_version` 키 캐시).
     *
     * @param  LayoutExtension  $extension  대상 조각
     * @param  string  $templateIdentifier  템플릿 identifier
     * @return string `.g7.html` 마크업
     */
    private function extensionMarkupFor(LayoutExtension $extension, string $templateIdentifier): string
    {
        $raw = (string) $extension->getRawOriginal('content');

        if ($raw === '') {
            return '';
        }

        $key = sprintf('layout:markup:ext:%d:%d', (int) $extension->id, (int) ($extension->lock_version ?? 0));

        return (string) $this->cache->remember($key, function () use ($raw, $templateIdentifier): string {
            try {
                $json = $this->markupService->decodeStoredContent($raw);

                return $this->markupService->toMarkup($json, $templateIdentifier)['markup'] ?? '';
            } catch (\Throwable) {
                return '';
            }
        }, self::MARKUP_CACHE_TTL);
    }

    /**
     * 역변환 마크업 캐시 키를 만듭니다.
     *
     * @param  int  $layoutId  레이아웃 ID
     * @param  int  $lockVersion  낙관적 잠금 번호
     * @return string 캐시 키
     */
    private function markupCacheKey(int $layoutId, int $lockVersion): string
    {
        return sprintf('layout:markup:%d:%d', $layoutId, $lockVersion);
    }

    /**
     * 이 레이아웃의 역변환 캐시를 지웁니다.
     *
     * 키에 `lock_version` 이 들어가 저장 시에는 자동으로 무효화되지만, 삭제·이름변경은
     * 행 자체가 사라지거나 옮겨 가므로 마지막 키를 명시적으로 지운다.
     *
     * @param  int  $layoutId  레이아웃 ID
     * @return void 반환값 없음
     */
    private function forgetMarkupCache(int $layoutId): void
    {
        $layout = $this->layoutRepository->findById($layoutId);

        if ($layout === null) {
            return;
        }

        $this->cache->forget($this->markupCacheKey($layoutId, (int) ($layout->lock_version ?? 0)));
        $this->cache->forget($this->markupCacheKey($layoutId, max(0, (int) ($layout->lock_version ?? 0) - 1)));
    }
}

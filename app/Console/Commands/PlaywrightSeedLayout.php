<?php

namespace App\Console\Commands;

use App\Contracts\Repositories\LayoutRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\Traits\ComputesLayoutContentHash;
use App\Extension\Traits\InvalidatesLayoutCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Playwright E2E 용 시드 레이아웃 설치/제거 커맨드.
 *
 * 레이아웃 편집기 E2E 중 **저장(PUT)까지 수행하는** spec 은 편집 결과가 그대로 영속된다.
 * 이들이 제품 화면(`home` / `admin_dashboard` 등)을 대상으로 하면 실행할 때마다 빈 표·차트가
 * 누적돼 개발 사이트가 오염된다(실측: `home` 20,321 → 33,696 bytes, 빈 표 7개). spec 안에
 * 원복 장치를 넣어도 편집기가 캐시한 문서를 다시 밀어 넣어 오염 시점 크기로 되돌아왔다.
 *
 * 그래서 저장 spec 전용 시드 화면(`e2e_sandbox`)을 제품 레이아웃과 분리한다. 이 커맨드가
 * 그 화면을 **활성 템플릿 디렉토리에만** 설치하므로 `_bundled`(배포 원본)은 손대지 않고,
 * 활성 디렉토리는 Git 무시 대상이라 릴리스 산출물에도 포함되지 않는다.
 *
 * 설치 절차(템플릿별):
 *   ① `tests/Playwright/fixtures/seed-layouts/{identifier}.e2e_sandbox.json` 을
 *      `templates/{identifier}/layouts/e2e_sandbox.json` 으로 복사
 *   ② 활성 `templates/{identifier}/routes.json` 에 라우트 1건 추가(멱등 — 마커로 식별).
 *      편집기 라우트 트리는 routes.json 에서 만들어지므로 이 항목이 없으면 `?route=` 로
 *      시드 화면을 열 수 없다
 *   ③ 시드 레이아웃 DB 행만 upsert. 편집기의 조회/저장 대상은 DB 행이라 이 단계가 없으면 404
 *
 * ③ 에서 `template:refresh-layout`(전체 재동기화)을 쓰지 않는 이유: 그 경로는 파일에 없는
 * DB 레이아웃을 지우고 모든 레이아웃을 파일 기준으로 되돌린다. 편집기 UI 로 저장한 변경은
 * 파일이 아니라 DB 에만 있으므로, E2E 를 돌릴 때마다 사람이 편집기로 만든 결과가 사라진다.
 * 시드 설치는 시드 행 하나만 건드린다.
 *
 * `--remove` 는 ①②를 되돌리고 시드 DB 행만 삭제한다 (다른 레이아웃 무영향).
 *
 * 보안 가드 (2중) — `PlaywrightIssueToken` 과 동일 규약:
 *   ① CLI 한정 — `php_sapi_name() === 'cli'` 확인. production 웹 요청에서 절대 도달 불가
 *   ② 명시 옵트인 — `G7_PLAYWRIGHT_BYPASS=1` 환경변수 부여 필수.
 *      `.env` 영구 수정 없이 인라인 환경변수로만 활성화 가능 → 무심코 production 으로 새지 않음
 *
 * 호출 예시 (PowerShell):
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-layout
 *   $env:G7_PLAYWRIGHT_BYPASS='1'; php artisan playwright:seed-layout --remove
 */
class PlaywrightSeedLayout extends Command
{
    use ComputesLayoutContentHash, InvalidatesLayoutCache;

    protected $signature = 'playwright:seed-layout
        {--template=* : 대상 템플릿 identifier (생략 시 기본 대상 전체)}
        {--remove : 시드 레이아웃/라우트를 제거한다}';

    protected $description = 'Playwright E2E 전용 시드 레이아웃 설치/제거 (CLI + G7_PLAYWRIGHT_BYPASS 가드)';

    /** 시드 레이아웃 이름 — 파일명·DB name·라우트 layout 이 모두 이 값을 공유한다. */
    private const LAYOUT_NAME = 'e2e_sandbox';

    /**
     * routes.json 의 시드 라우트 식별 마커.
     *
     * 경로 문자열은 템플릿 타입마다 다르므로(사용자 템플릿 vs 관리자 템플릿), 제거 시에는
     * 경로가 아니라 이 마커로 대상을 찾는다.
     */
    private const ROUTE_MARKER = 'playwright-seed-layout';

    /** 원본 routes.json 보관 파일 접미사 — 제거 시 서식까지 바이트 동일 복원용 */
    private const ROUTES_BACKUP_SUFFIX = '.playwright-backup';

    /**
     * 기본 대상 템플릿 → 라우트 경로.
     *
     * 관리자 템플릿 라우트는 관리자 URL 프리픽스 규약을 따른다.
     *
     * @var array<string, string>
     */
    private const DEFAULT_TARGETS = [
        'sirsoft-basic' => '/e2e-sandbox',
        'sirsoft-admin_basic' => '*/admin/e2e-sandbox',
    ];

    public function __construct(
        private LayoutRepositoryInterface $layoutRepository,
        private TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드 (0 성공)
     */
    public function handle(): int
    {
        // ① CLI 한정 — production 웹 요청에서 절대 도달 불가
        if (php_sapi_name() !== 'cli') {
            $this->error('CLI 전용 커맨드입니다. (현재 SAPI: '.php_sapi_name().')');

            return self::FAILURE;
        }

        // ② 명시 옵트인 — 환경변수 없이는 production 호출 실수 차단
        if (env('G7_PLAYWRIGHT_BYPASS') !== '1') {
            $this->error('G7_PLAYWRIGHT_BYPASS=1 환경변수가 필요합니다. (예: PowerShell — $env:G7_PLAYWRIGHT_BYPASS=\'1\')');

            return self::FAILURE;
        }

        // settings JSON 이 debug 를 덮어써도 시드 작업이 막히지 않도록 인라인 강제
        // (PlaywrightIssueToken 과 동일 근거 — bypass flag 인지 시 SettingsServiceProvider 는 이미 우회 상태).
        Config::set('app.debug', true);

        $remove = (bool) $this->option('remove');
        $targets = $this->resolveTargets();

        if ($targets === []) {
            $this->error('대상 템플릿이 없습니다.');

            return self::FAILURE;
        }

        foreach ($targets as $identifier => $routePath) {
            if (! $this->processTemplate($identifier, $routePath, $remove)) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * `--template` 옵션을 기본 대상 목록과 대조해 처리 대상을 결정합니다.
     *
     * @return array<string, string> identifier => 라우트 경로
     */
    private function resolveTargets(): array
    {
        $requested = $this->option('template') ?: [];

        if ($requested === []) {
            return self::DEFAULT_TARGETS;
        }

        $targets = [];
        foreach ($requested as $identifier) {
            if (! isset(self::DEFAULT_TARGETS[$identifier])) {
                $this->warn("시드 라우트 경로가 정의되지 않은 템플릿입니다 — 건너뜁니다: {$identifier}");

                continue;
            }
            $targets[$identifier] = self::DEFAULT_TARGETS[$identifier];
        }

        return $targets;
    }

    /**
     * 템플릿 1개에 대해 시드 레이아웃 설치 또는 제거를 수행합니다.
     *
     * @param  string  $identifier  템플릿 identifier
     * @param  string  $routePath  시드 라우트 경로
     * @param  bool  $remove  true 면 제거, false 면 설치
     * @return bool 성공 여부
     */
    private function processTemplate(string $identifier, string $routePath, bool $remove): bool
    {
        $templateDir = base_path("templates/{$identifier}");

        // 활성 디렉토리가 없으면 그 템플릿은 설치되지 않은 것 — 조용히 건너뛴다
        // (E2E 대상 템플릿이 환경마다 다를 수 있으므로 실패로 취급하지 않는다).
        if (! File::isDirectory($templateDir)) {
            $this->warn("활성 템플릿 디렉토리가 없어 건너뜁니다: {$identifier}");

            return true;
        }

        $template = $this->templateRepository->findByIdentifier($identifier);
        if (! $template) {
            $this->warn("템플릿이 DB 에 없어 건너뜁니다: {$identifier}");

            return true;
        }

        $layoutPath = "{$templateDir}/layouts/".self::LAYOUT_NAME.'.json';

        if ($remove) {
            if (File::exists($layoutPath)) {
                File::delete($layoutPath);
            }
            $this->removeSeedRoute($identifier, $templateDir);
            $this->layoutRepository->deleteByName($template->id, self::LAYOUT_NAME);
        } else {
            $fixturePath = base_path(
                'tests/Playwright/fixtures/seed-layouts/'.$identifier.'.'.self::LAYOUT_NAME.'.json'
            );
            if (! File::exists($fixturePath)) {
                $this->error("시드 레이아웃 fixture 가 없습니다: {$fixturePath}");

                return false;
            }

            $content = json_decode((string) File::get($fixturePath), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($content)) {
                $this->error("시드 레이아웃 fixture 파싱 실패: {$fixturePath}");

                return false;
            }

            File::copy($fixturePath, $layoutPath);
            $this->addSeedRoute($identifier, $templateDir, $routePath);

            $this->layoutRepository->updateOrCreate(
                ['template_id' => $template->id, 'name' => self::LAYOUT_NAME],
                [
                    'content' => $content,
                    'source_type' => 'template',
                    'original_content_hash' => $this->computeContentHash($content),
                    'original_content_size' => $this->computeContentSize($content),
                ]
            );
        }

        // 편집기/공개 서빙 캐시에서 시드 레이아웃 키를 지운다. 삭제 경로에서도 캐시가 남으면
        // 편집기가 stale 문서를 계속 받는다.
        $this->forgetSeedLayoutCache($template->id, $identifier);

        $this->info(($remove ? '제거' : '설치')." 완료: {$identifier} ({$routePath})");

        return true;
    }

    /**
     * 활성 routes.json 에 시드 라우트를 추가합니다.
     *
     * 재작성은 JSON 재직렬화라 원본의 주석 그룹 사이 빈 줄 같은 서식이 사라진다. 그래서 첫
     * 설치 시 원본을 `.playwright-backup` 으로 보관하고, 제거 때 그 파일을 그대로 되돌린다
     * (바이트 동일 복원). 백업이 이미 있으면 덮어쓰지 않는다 — 비정상 종료로 시드가 남은 상태의
     * routes.json 을 "원본" 으로 굳히지 않기 위함.
     *
     * @param  string  $identifier  템플릿 identifier
     * @param  string  $templateDir  활성 템플릿 디렉토리 절대 경로
     * @param  string  $routePath  등록할 시드 라우트 경로
     */
    private function addSeedRoute(string $identifier, string $templateDir, string $routePath): void
    {
        $path = "{$templateDir}/routes.json";
        $routes = $this->readRoutes($identifier, $path);
        if ($routes === null) {
            return;
        }

        $backupPath = $path.self::ROUTES_BACKUP_SUFFIX;
        if (! File::exists($backupPath)) {
            File::copy($path, $backupPath);
        }

        // 마커 항목을 먼저 걷어내 반복 실행이 라우트를 중복 추가하지 않게 한다.
        $entries = $this->withoutSeedRoutes($routes);
        $entries[] = [
            '_marker' => self::ROUTE_MARKER,
            '_comment' => 'Playwright E2E 전용 시드 화면 — playwright:seed-layout 이 설치/제거한다. 제품 라우트가 아니다.',
            'path' => $routePath,
            'layout' => self::LAYOUT_NAME,
            'auth_required' => str_starts_with($routePath, '*/admin'),
            'meta' => [
                'title' => 'E2E Sandbox',
            ],
        ];

        $routes['routes'] = $entries;

        File::put(
            $path,
            json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    /**
     * 활성 routes.json 에서 시드 라우트를 제거합니다.
     *
     * 백업이 있으면 그것을 그대로 복원해 서식까지 원상 복구한다. 백업이 없으면(다른 경로로 시드가
     * 들어간 경우) 마커 항목만 걷어낸 재직렬화로 대체한다.
     *
     * @param  string  $identifier  템플릿 identifier
     * @param  string  $templateDir  활성 템플릿 디렉토리 절대 경로
     */
    private function removeSeedRoute(string $identifier, string $templateDir): void
    {
        $path = "{$templateDir}/routes.json";
        $backupPath = $path.self::ROUTES_BACKUP_SUFFIX;

        if (File::exists($backupPath)) {
            File::put($path, File::get($backupPath));
            File::delete($backupPath);

            return;
        }

        $routes = $this->readRoutes($identifier, $path);
        if ($routes === null) {
            return;
        }

        $routes['routes'] = $this->withoutSeedRoutes($routes);

        File::put(
            $path,
            json_encode($routes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n"
        );
    }

    /**
     * routes.json 을 디코드해 반환합니다 (부재/파싱 실패 시 경고 후 null).
     *
     * @param  string  $identifier  템플릿 identifier (경고 메시지용)
     * @param  string  $path  routes.json 절대 경로
     * @return array<string, mixed>|null 디코드 결과
     */
    private function readRoutes(string $identifier, string $path): ?array
    {
        if (! File::exists($path)) {
            $this->warn("routes.json 이 없어 라우트 처리를 건너뜁니다: {$identifier}");

            return null;
        }

        $routes = json_decode((string) File::get($path), true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($routes)) {
            $this->warn("routes.json 을 파싱할 수 없어 라우트 처리를 건너뜁니다: {$identifier}");

            return null;
        }

        return $routes;
    }

    /**
     * 라우트 배열에서 시드 마커가 붙은 항목을 제외해 반환합니다.
     *
     * @param  array<string, mixed>  $routes  디코드된 routes.json
     * @return array<int, mixed> 마커 항목이 제거된 라우트 배열
     */
    private function withoutSeedRoutes(array $routes): array
    {
        return array_values(array_filter(
            $routes['routes'] ?? [],
            fn ($route) => ! is_array($route) || ($route['_marker'] ?? null) !== self::ROUTE_MARKER
        ));
    }

    /**
     * 시드 레이아웃의 캐시 키를 삭제합니다.
     *
     * `InvalidatesLayoutCache::forgetLayoutCacheKeys` 는 레이아웃 객체의
     * `template_id` / `name` / `source_type` / `source_identifier` 만 읽으므로,
     * 삭제 후(모델 부재) 에도 동일 형태의 경량 객체로 호출할 수 있다.
     *
     * @param  int  $templateId  템플릿 ID
     * @param  string  $identifier  템플릿 identifier (버전 포함 공개 서빙 키에 사용)
     */
    private function forgetSeedLayoutCache(int $templateId, string $identifier): void
    {
        $this->forgetLayoutCacheKeys(
            (object) [
                'template_id' => $templateId,
                'name' => self::LAYOUT_NAME,
                'source_type' => null,
                'source_identifier' => null,
            ],
            $identifier
        );
    }
}

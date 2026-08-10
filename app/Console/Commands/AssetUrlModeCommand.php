<?php

namespace App\Console\Commands;

use App\Seo\Contracts\SeoCacheManagerInterface;
use App\Services\SettingsService;
use App\Support\AssetUrl;
use Illuminate\Console\Command;

/**
 * 자산 URL 모드 조회/전환 Artisan 커맨드 (이슈 #486 §9)
 *
 * 정적 최적화 블록(`location ~* \.(js|css|json)$`)이 동적 응답을 가로채는 서버에서는
 * **관리자 화면 자체가 뜨지 않는다**. "브라우저로 설정을 바꾸세요" 는 순환 참조이므로
 * CLI 탈출구가 반드시 필요하다.
 *
 * ```
 * php artisan g7:asset-url-mode                # 현재 모드 + 진단 안내
 * php artisan g7:asset-url-mode extensionless  # 전환
 * ```
 */
class AssetUrlModeCommand extends Command
{
    /**
     * @var string 커맨드 시그니처
     */
    protected $signature = 'g7:asset-url-mode
        {mode? : 전환할 모드 (extension | extensionless). 생략 시 현재 모드만 출력}';

    /**
     * @var string 커맨드 설명
     */
    protected $description = '자산 URL 모드를 조회하거나 전환합니다 (정적 최적화 서버 대응)';

    /**
     * 커맨드를 실행합니다.
     *
     * @param  SettingsService  $settingsService  환경설정 서비스
     * @param  SeoCacheManagerInterface  $seoCacheManager  SEO 캐시 매니저
     * @return int 종료 코드
     */
    public function handle(SettingsService $settingsService, SeoCacheManagerInterface $seoCacheManager): int
    {
        $current = AssetUrl::mode();
        $requested = $this->argument('mode');

        if ($requested === null) {
            $this->showStatus($current);

            return Command::SUCCESS;
        }

        if (! in_array($requested, [AssetUrl::MODE_EXTENSION, AssetUrl::MODE_EXTENSIONLESS], true)) {
            $this->error(__('asset_url_mode.invalid', ['mode' => $requested]));

            return Command::FAILURE;
        }

        if ($requested === $current) {
            $this->info(__('asset_url_mode.already', ['mode' => $current]));

            return Command::SUCCESS;
        }

        $saved = $settingsService->saveSettings([
            '_tab' => 'general',
            'general' => ['asset_url_mode' => $requested],
        ]);

        if (! $saved) {
            $this->error(__('asset_url_mode.save_failed'));

            return Command::FAILURE;
        }

        // SEO 프리렌더 캐시에는 생성 시점의 자산 URL 이 그대로 구워져 있다.
        // 모드를 바꾸면 그 URL 들이 전부 어긋나므로 함께 비운다 (계획서 §알려진 한계).
        $seoCacheManager->clearAll();

        $this->info(__('asset_url_mode.switched', ['from' => $current, 'to' => $requested]));
        $this->line(__('asset_url_mode.seo_cache_cleared'));

        return Command::SUCCESS;
    }

    /**
     * 현재 모드와 진단 안내를 출력합니다.
     *
     * @param  string  $current  현재 모드
     */
    private function showStatus(string $current): void
    {
        $this->line('');
        $this->line('  '.__('asset_url_mode.current', ['mode' => $current]));
        $this->line('');

        // audit:allow asset-url-builder-required reason: 두 모드의 URL 형태를 나란히
        // 보여주는 대조표라 빌더로 생성할 수 없다 (빌더는 현재 모드 하나만 만든다).
        $rows = $current === AssetUrl::MODE_EXTENSION
            ? [
                ['/api/templates/{id}/routes.json', '/api/templates/{id}/routes'],
                ['/api/modules/bundle.js', '/api/modules/bundle/js'],
                ['/api/templates/assets/{id}/js/a.js', '/api/templates/assets/{id}?file=js/a.js'],
            ]
            : [
                ['/api/templates/{id}/routes', '/api/templates/{id}/routes.json'],
                ['/api/modules/bundle/js', '/api/modules/bundle.js'],
                ['/api/templates/assets/{id}?file=js/a.js', '/api/templates/assets/{id}/js/a.js'],
            ];

        $this->table(
            [__('asset_url_mode.table.in_use'), __('asset_url_mode.table.alternative')],
            $rows,
        );

        $this->line('  '.__('asset_url_mode.diagnose_title'));
        $this->line('    curl -i '.rtrim(config('app.url'), '/').'/api/system/asset-probe.js');
        $this->line('    curl -i '.rtrim(config('app.url'), '/').'/api/system/asset-probe');
        $this->line('');
        $this->line('  '.__('asset_url_mode.diagnose_hint'));
        $this->line('');
        $this->line('  '.__('asset_url_mode.switch_hint'));
        $this->line('');
    }
}

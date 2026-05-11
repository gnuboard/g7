<?php

namespace App\Console\Commands\Template;

use App\Contracts\Repositories\TemplateRepositoryInterface;
use App\Extension\TemplateManager;
use Illuminate\Console\Command;

class ListTemplateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'template:list
        {--type= : 템플릿 타입으로 필터 (admin, user)}
        {--status= : 상태로 필터 (installed, uninstalled, active, inactive)}
        {--hidden : 숨김(hidden=true) 템플릿도 함께 출력}';

    /**
     * The console command description.
     */
    protected $description = '설치된 템플릿 목록을 조회합니다';

    /**
     * 템플릿 관리자 및 리포지토리
     */
    public function __construct(
        private TemplateManager $templateManager,
        private TemplateRepositoryInterface $templateRepository
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // 템플릿 디렉토리 스캔 및 로드
        $this->templateManager->loadTemplates();

        $typeFilter = $this->option('type');
        $statusFilter = $this->option('status');
        $includeHidden = (bool) $this->option('hidden');

        // 타입 필터 검증
        if ($typeFilter && ! in_array($typeFilter, ['admin', 'user'])) {
            $this->error('❌ '.__('templates.commands.list.invalid_type'));

            return Command::FAILURE;
        }

        // 상태 필터 검증
        if ($statusFilter && ! in_array($statusFilter, ['installed', 'uninstalled', 'active', 'inactive'])) {
            $this->error('❌ '.__('templates.commands.list.invalid_status'));

            return Command::FAILURE;
        }

        // 설치된 템플릿 정보
        $installedTemplates = $this->templateManager->getInstalledTemplatesWithDetails();

        // 미설치 템플릿 정보
        $uninstalledTemplates = $this->templateManager->getUninstalledTemplates();

        // 테이블 데이터 준비
        $tableData = [];

        // 설치된 템플릿 추가
        foreach ($installedTemplates as $identifier => $template) {
            // 숨김 필터: --hidden 미지정 시 hidden=true 템플릿 제외
            if (! $includeHidden && ! empty($template['hidden'])) {
                continue;
            }

            // 타입 필터
            if ($typeFilter && $template['type'] !== $typeFilter) {
                continue;
            }

            // 상태 필터
            if ($statusFilter) {
                if ($statusFilter === 'uninstalled') {
                    continue;
                }
                if ($statusFilter === 'active' && $template['status'] !== 'active') {
                    continue;
                }
                if ($statusFilter === 'inactive' && $template['status'] !== 'inactive') {
                    continue;
                }
                if ($statusFilter === 'installed' && ! in_array($template['status'], ['active', 'inactive'])) {
                    continue;
                }
            }

            $tableData[] = [
                'identifier' => $identifier,
                'name' => $template['name'],
                'type' => __('templates.types.'.$template['type']),
                'version' => $template['version'],
                'status' => $this->formatStatus($template['status']),
            ];
        }

        // 미설치 템플릿 추가
        if (! $statusFilter || $statusFilter === 'uninstalled') {
            foreach ($uninstalledTemplates as $identifier => $template) {
                // 숨김 필터: --hidden 미지정 시 hidden=true 템플릿 제외
                if (! $includeHidden && ! empty($template['hidden'])) {
                    continue;
                }

                // 타입 필터
                if ($typeFilter && $template['type'] !== $typeFilter) {
                    continue;
                }

                // 상태 필터 (uninstalled 또는 필터 없음)
                if ($statusFilter && $statusFilter !== 'uninstalled') {
                    continue;
                }

                $tableData[] = [
                    'identifier' => $identifier,
                    'name' => $template['name'],
                    'type' => __('templates.types.'.$template['type']),
                    'version' => $template['version'],
                    'status' => $this->formatStatus('uninstalled'),
                ];
            }
        }

        // 템플릿이 없는 경우
        if (empty($tableData)) {
            $this->info(__('templates.commands.list.no_templates'));

            return Command::SUCCESS;
        }

        // 테이블 헤더
        $headers = [
            __('templates.commands.list.headers.identifier'),
            __('templates.commands.list.headers.name'),
            __('templates.commands.list.headers.type'),
            __('templates.commands.list.headers.version'),
            __('templates.commands.list.headers.status'),
        ];

        // 테이블 출력
        $this->table($headers, $tableData);

        // 요약 정보
        $totalCount = count($tableData);
        $activeCount = count(array_filter($tableData, fn ($t) => str_contains($t['status'], __('templates.status.active'))));
        $installedCount = count(array_filter($tableData, fn ($t) => ! str_contains($t['status'], __('templates.commands.list.status.uninstalled'))));

        $this->newLine();
        $this->info(__('templates.commands.list.summary', [
            'total' => $totalCount,
            'installed' => $installedCount,
            'active' => $activeCount,
        ]));

        return Command::SUCCESS;
    }

    /**
     * 상태 포맷팅
     */
    private function formatStatus(string $status): string
    {
        return match ($status) {
            'active' => '✅ '.__('templates.status.active'),
            'inactive' => '⏸️  '.__('templates.status.inactive'),
            'uninstalled' => '📦 '.__('templates.commands.list.status.uninstalled'),
            default => $status,
        };
    }
}

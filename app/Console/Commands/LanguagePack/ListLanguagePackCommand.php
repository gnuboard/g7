<?php

namespace App\Console\Commands\LanguagePack;

use App\Models\LanguagePack;
use App\Services\LanguagePackService;
use Illuminate\Console\Command;

/**
 * 언어팩 목록을 출력합니다.
 *
 * 모듈/플러그인/템플릿의 list 커맨드와 동일한 운영 도구. Service::list() 를 경유하므로
 * 설치된 DB 행뿐 아니라 "번들에 있으나 미설치"(uninstalled) 및 "active 로 기록됐으나
 * 설치본 파일 부재(드리프트)" 상태를 함께 표면화한다(이슈 #496).
 */
class ListLanguagePackCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'language-pack:list {--scope= : 특정 스코프만 (core/module/plugin/template)}';

    /**
     * @var string
     */
    protected $description = '언어팩 목록을 출력합니다 (미설치 번들 및 드리프트 상태 포함).';

    /**
     * @param  LanguagePackService  $service  언어팩 Service
     */
    public function __construct(
        private readonly LanguagePackService $service,
    ) {
        parent::__construct();
    }

    /**
     * 커맨드를 실행합니다.
     *
     * @return int 종료 코드
     */
    public function handle(): int
    {
        $filters = [];
        if ($scope = $this->option('scope')) {
            $filters['scope'] = $scope;
        }

        $paginator = $this->service->list($filters, 100);
        $packs = $paginator->items();

        if (empty($packs)) {
            $this->info('언어팩이 없습니다.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($packs as $pack) {
            $rows[] = [
                $pack->identifier,
                $pack->scope,
                $pack->target_identifier ?? '-',
                $pack->locale,
                $pack->vendor,
                $pack->version,
                $this->formatStatus($pack),
            ];
        }

        $this->table(
            ['Identifier', 'Scope', 'Target', 'Locale', 'Vendor', 'Version', 'Status'],
            $rows
        );

        return self::SUCCESS;
    }

    /**
     * 표시용 상태 문자열을 만듭니다. 드리프트(active + 설치본 부재)는 명시적으로 표기합니다.
     *
     * @param  LanguagePack  $pack  대상 팩
     * @return string 표시 상태
     */
    private function formatStatus(LanguagePack $pack): string
    {
        if ((bool) $pack->getAttribute('files_missing')) {
            return $pack->status.' (파일 없음)';
        }

        return (string) $pack->status;
    }
}

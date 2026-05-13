<?php

namespace App\Console\Commands\LanguagePack;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use Illuminate\Console\Command;

/**
 * 설치된 언어팩 목록을 출력합니다.
 *
 * 모듈/플러그인/템플릿의 list 커맨드와 동일한 운영 도구.
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
    protected $description = '설치된 언어팩 목록을 출력합니다.';

    /**
     * @param  LanguagePackRepositoryInterface  $repository  Repository
     */
    public function __construct(
        private readonly LanguagePackRepositoryInterface $repository,
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

        $paginator = $this->repository->paginate($filters, 100);
        $packs = $paginator->items();

        if (empty($packs)) {
            $this->info('설치된 언어팩이 없습니다.');

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
                $pack->status,
            ];
        }

        $this->table(
            ['Identifier', 'Scope', 'Target', 'Locale', 'Vendor', 'Version', 'Status'],
            $rows
        );

        return self::SUCCESS;
    }
}

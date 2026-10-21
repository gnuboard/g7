<?php

namespace App\Console\Commands\Traits;

use App\Contracts\Repositories\LanguagePackRepositoryInterface;
use App\Services\LanguagePack\LanguagePackBundledRegistrar;
use App\Services\LanguagePackService;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * 확장 활성화 커맨드(`module:activate`·`plugin:activate`·`template:activate`)가 호스트를 끌 때 함께 꺼진 언어팩을
 * 다시 켜는 공통 동작.
 *
 * 관리자 화면은 「언어팩 다시 켜기」 창으로 묻지만 CLI 는 아무것도 하지 않아, CLI 로 복구한 사이트(관리자 템플릿이 꺼져
 * 화면에 들어갈 수 없던 #137 경로)에 언어팩이 꺼진 채 남았다(#145 F7). CLI 는 기본으로 함께 켜고,
 * `--without-language-packs` 를 주면 켜지 않고 목록만 알린다(표시는 남아 관리자 화면이 다시 묻는다).
 *
 * 사용하는 커맨드는 생성자에서 `addLanguagePackRestoreOption()` 으로 `--without-language-packs` 옵션을 등록한다
 * (옵션 설명을 다국어 파일에서 읽기 위해 시그니처 대신 생성자에서 등록한다).
 */
trait RestoresHostLanguagePacks
{
    /**
     * `--without-language-packs` 옵션을 다국어 설명과 함께 등록합니다.
     *
     * @param  string  $langPrefix  옵션 설명 lang 접두 (modules·plugins·templates)
     * @return void 반환값 없음
     */
    protected function addLanguagePackRestoreOption(string $langPrefix): void
    {
        $this->addOption('without-language-packs', null, InputOption::VALUE_NONE, __($langPrefix.'.commands.activate.option_without_language_packs'));
    }

    /**
     * 호스트 활성화 직후 함께 꺼진 언어팩을 켜거나(기본) 남긴 목록을 알립니다.
     *
     * 항목별 실패(슬롯 충돌 등)는 경고로 출력하고 나머지를 계속한다 — 호스트 활성화 자체는 이미 끝났다.
     *
     * @param  string  $scope  언어팩 스코프 (module, plugin, template)
     * @param  string  $identifier  호스트 확장 식별자
     * @param  string  $langPrefix  출력 문구 lang 접두 (modules·plugins·templates)
     * @return void 반환값 없음
     */
    protected function restoreHostLanguagePacks(string $scope, string $identifier, string $langPrefix): void
    {
        $pending = app(LanguagePackBundledRegistrar::class)->getPendingForReactivation($scope, $identifier);
        if ($pending === []) {
            return;
        }

        if ($this->option('without-language-packs')) {
            $this->line('   - '.__($langPrefix.'.commands.activate.language_packs_kept', [
                'list' => implode(', ', array_column($pending, 'locale')),
            ]));

            return;
        }

        $service = app(LanguagePackService::class);
        $restored = [];

        foreach ($pending as $item) {
            try {
                $pack = app(LanguagePackRepositoryInterface::class)->findById((int) $item['id']);
                if (! $pack) {
                    continue;
                }
                $service->activate($pack);
                $restored[] = $item['locale'];
            } catch (Throwable $e) {
                $this->warn('⚠️  '.__($langPrefix.'.commands.activate.language_pack_restore_failed', [
                    'pack' => $item['identifier'],
                    'error' => $e->getMessage(),
                ]));
            }
        }

        if ($restored !== []) {
            $this->info('   - '.__($langPrefix.'.commands.activate.language_packs_restored', [
                'count' => count($restored),
                'list' => implode(', ', $restored),
            ]));
        }
    }
}

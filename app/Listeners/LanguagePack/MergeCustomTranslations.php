<?php

namespace App\Listeners\LanguagePack;

use App\Contracts\Repositories\TemplateCustomTranslationRepositoryInterface;
use App\Contracts\Repositories\TemplateRepositoryInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `template.language.merge` 필터 리스너 — 커스텀 다국어 키 병합.
 *
 * `MergeFrontendLanguage`(언어팩 병합) **다음**에 실행되어, 템플릿의
 * 활성 커스텀 키(`template_custom_translations`)를 언어 데이터 트리에
 * 덮어씁니다. 따라서 커스텀 키가 언어팩보다 높은 우선순위를 갖습니다.
 *
 * 동작:
 *  - templateIdentifier → Template 조회 → template_id
 *  - status=active 행 조회 → 각 translation_key (점 경로) 를 Arr::set
 *  - 값 = values[locale] ?? values[fallback] ?? key
 */
// audit:allow listener-must-implement-hooklistenerinterface reason: LanguagePackServiceProvider 가 HookManager::addFilter 로 직접 등록하는 명시 등록 패턴 (MergeFrontendLanguage 와 동일)
class MergeCustomTranslations
{
    /**
     * @param  TemplateRepositoryInterface  $templateRepository  템플릿 리포지토리 (식별자 → ID)
     * @param  TemplateCustomTranslationRepositoryInterface  $translationRepository  커스텀 키 리포지토리
     */
    public function __construct(
        private readonly TemplateRepositoryInterface $templateRepository,
        private readonly TemplateCustomTranslationRepositoryInterface $translationRepository,
    ) {}

    /**
     * 필터 호출 진입점.
     *
     * @param  array<string, mixed>  $data  병합 누적 데이터 (언어팩 병합 결과)
     * @param  string  $templateIdentifier  현재 렌더링 템플릿 식별자
     * @param  string  $locale  로케일
     * @return array<string, mixed> 커스텀 키가 병합된 다국어 데이터
     */
    public function __invoke(array $data, string $templateIdentifier, string $locale): array
    {
        if ($templateIdentifier === '') {
            return $data;
        }

        // 무손실 디그레이드: 본 리스너는 코어 언어 파이프라인에 후행 등록되는 부가 병합
        // 단계다. 자신의 조회/병합 실패(테이블 미적용·DB 일시 장애 등)가 전체 다국어
        // 엔드포인트(serveLanguage)를 500 으로 무너뜨리면 모든 화면(admin/user)이 깨진다.
        // 따라서 예외를 흡수하고 입력 $data 를 그대로 반환한다 — 커스텀 키 병합 누락은
        // fallback 으로 흡수된다.
        try {
            $template = $this->templateRepository->findByIdentifier($templateIdentifier);

            if ($template === null) {
                return $data;
            }

            $rows = $this->translationRepository->getActiveByTemplateId($template->id);

            if ($rows->isEmpty()) {
                return $data;
            }

            $fallback = (string) config('app.fallback_locale', 'ko');

            foreach ($rows as $row) {
                $values = is_array($row->values) ? $row->values : [];
                $value = $values[$locale]
                    ?? $values[$fallback]
                    ?? $row->translation_key;

                Arr::set($data, $row->translation_key, $value);
            }

            return $data;
        } catch (Throwable $e) {
            Log::warning('MergeCustomTranslations: 커스텀 다국어 키 병합 실패 — 입력 데이터로 디그레이드', [
                'template' => $templateIdentifier,
                'locale' => $locale,
                'error' => $e->getMessage(),
            ]);

            return $data;
        }
    }
}

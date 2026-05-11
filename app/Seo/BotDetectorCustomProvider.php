<?php

namespace App\Seo;

use Jaybizzle\CrawlerDetect\CrawlerDetect;
use Jaybizzle\CrawlerDetect\Fixtures\Crawlers;

/**
 * jaybizzle/crawler-detect 라이브러리에 G7 보강 패턴(미커버 봇 + 운영자 커스텀 패턴)을
 * 주입한 봇 감지기. 라이브러리의 `CrawlerDetect` 생성자가 외부 Crawlers 주입을 지원하지
 * 않으므로 서브클래싱을 통해 `$this->crawlers` 와 `$this->compiledRegex` 를 교체한다.
 */
class BotDetectorCustomProvider extends CrawlerDetect
{
    /**
     * jaybizzle 1.3.9 기준 라이브러리가 놓치는 봇.
     * 상류에 PR 후 커버되면 단계적 제거.
     */
    private const BUILTIN_EXTRA_PATTERNS = [
        'kakaotalk-scrap',
        'Meta-ExternalAgent',
        'ChatGPT-User',
    ];

    /**
     * @param  array<int, string>  $userPatterns  운영자가 관리자 UI 에서 추가한 커스텀 패턴
     */
    public function __construct(array $userPatterns = [])
    {
        parent::__construct();

        $this->crawlers = $this->makeCrawlers($userPatterns);
        $this->compiledRegex = $this->compileRegex($this->crawlers->getAll());
    }

    /**
     * 라이브러리 기본 패턴 + G7 보강 + 사용자 패턴을 병합한 Crawlers fixture.
     *
     * @param  array<int, string>  $userPatterns
     */
    private function makeCrawlers(array $userPatterns): Crawlers
    {
        return new class($userPatterns) extends Crawlers
        {
            /**
             * @param  array<int, string>  $userPatterns
             */
            public function __construct(array $userPatterns)
            {
                $this->data = array_merge(
                    $this->data,
                    BotDetectorCustomProvider::extraPatterns(),
                );

                foreach ($userPatterns as $pattern) {
                    $pattern = is_string($pattern) ? trim($pattern) : '';
                    if ($pattern === '') {
                        continue;
                    }

                    // 운영자 입력은 정규식 메타문자를 리터럴로 처리.
                    $this->data[] = preg_quote($pattern, '/');
                }
            }
        };
    }

    /**
     * 익명 자식 클래스가 const 에 접근하기 위한 정적 헬퍼.
     *
     * @return array<int, string>
     */
    public static function extraPatterns(): array
    {
        return self::BUILTIN_EXTRA_PATTERNS;
    }
}

<?php

namespace Plugins\Sirsoft\Popup\Services;

use App\Extension\Helpers\FilePermissionHelper;
use App\Extension\HookManager;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\Log;

/**
 * 직접 작성형 팝업 본문 정화기 — 저장할 때 언어별 HTML 을 HTMLPurifier 로 거른다(저장본 = 정화본).
 *
 * 코어에는 저장 시 HTML 정화기가 없다(게시판·페이지는 출력 때만 거른다). 팝업은 모든 사용자 화면 위에 뜨므로
 * 저장 때 한 번 거르고, 사용자 레이어가 출력 때 DOMPurify 로 한 번 더 거른다(결정 R6, 이커머스 상품 설명 선례).
 * 허용 목록은 `sirsoft-popup.popup.filter_sanitize_config` 필터로 다른 확장이 넓히거나 좁힐 수 있다.
 */
class PopupHtmlSanitizer
{
    /**
     * 플러그인 식별자
     */
    private const PLUGIN = 'sirsoft-popup';

    /**
     * 정의 캐시 디렉토리 권한 — CLI 가 먼저 만들어도 웹 계정이 쓸 수 있게.
     */
    private const CACHE_DIR_MODE = 0775;

    /**
     * 남기는 class — CKEditor5 가 이미지·표·글자 크기 표시에 쓰는 것만
     *
     * @var array<int, string>
     */
    public const EDITOR_CLASSES = [
        'image', 'image_resized', 'image-inline', 'image-style-side', 'image-style-align-left', 'image-style-align-center',
        'image-style-align-right', 'image-style-block-align-left', 'image-style-block-align-right', 'table',
        'text-tiny', 'text-small', 'text-big', 'text-huge', 'todo-list', 'marker-yellow', 'marker-green', 'marker-pink', 'marker-blue',
    ];

    /**
     * 캐시를 못 쓰는 상태를 한 번만 알리려는 표지
     */
    private static bool $cacheWarned = false;

    /**
     * 만든 정화기(요청 안에서 재사용)
     */
    private ?\HTMLPurifier $purifier = null;

    /**
     * 언어별 본문을 정화합니다.
     *
     * @param  array<string, string>  $bodies  언어 => 본문 HTML
     * @return array{bodies: array<string, string>, changed: bool} 정화한 본문과 내용이 바뀌었는지
     */
    public function sanitizeTranslations(array $bodies): array
    {
        $changed = false;
        $clean = [];

        foreach ($bodies as $locale => $html) {
            if (! is_string($html)) {
                continue;
            }
            $purified = $this->sanitize($html);
            if ($this->normalizeForCompare($purified) !== $this->normalizeForCompare($html)) {
                $changed = true;
            }
            $clean[$locale] = $purified;
        }

        return ['bodies' => $clean, 'changed' => $changed];
    }

    /**
     * HTML 한 덩어리를 정화합니다.
     *
     * @param  string  $html  본문 HTML
     * @return string 정화한 HTML
     */
    public function sanitize(string $html): string
    {
        $this->purifier ??= new \HTMLPurifier($this->buildConfig());

        return trim($this->purifier->purify($html));
    }

    /**
     * 정화 설정을 만듭니다.
     *
     * 허용: 문단·제목(h2~h4)·목록·강조·링크(http·https·mailto·사이트 내부, 새 창은 rel 강제)·이미지·표·인용·구분선,
     * `style` 은 글자색·배경색·정렬·글자 크기·굵기·밑줄·폭만, `class` 는 CKEditor 표시 클래스(`EDITOR_CLASSES`)만.
     *
     * @return \HTMLPurifier_Config 정화 설정
     */
    private function buildConfig(): \HTMLPurifier_Config
    {
        $config = \HTMLPurifier_Config::createDefault();

        $options = HookManager::applyFilters('sirsoft-popup.popup.filter_sanitize_config', [
            'HTML.Allowed' => 'p[style|class],br,strong,b,em,i,u,s,sub,sup,'
                .'h2[style|class],h3[style|class],h4[style|class],'
                .'ul[class],ol[class|start],li[style|class],'
                .'a[href|target|title],'
                .'img[src|alt|width|height|class|style],figure[class|style],figcaption,'
                .'table[class|style],thead,tbody,tfoot,tr,th[style|colspan|rowspan],td[style|colspan|rowspan],caption,colgroup,col,'
                .'blockquote[class],hr,span[style|class],div[style|class]',
            'CSS.AllowedProperties' => 'color,background-color,text-align,font-size,font-weight,text-decoration,width,height,max-width',
            'Attr.AllowedFrameTargets' => ['_blank'],
            // class 는 CKEditor 표시용만 — 사이트 스타일 클래스를 빌려 화면을 덮는 요소를 만들지 못하게
            'Attr.AllowedClasses' => self::EDITOR_CLASSES,
            'URI.AllowedSchemes' => ['http' => true, 'https' => true, 'mailto' => true],
            'HTML.TargetNoopener' => true,
            'HTML.TargetNoreferrer' => true,
            'HTML.Nofollow' => false,
        ]);

        foreach ($options as $key => $value) {
            $config->set($key, $value);
        }

        // HTMLPurifier 기본 정의(HTML4)에는 figure·figcaption 이 없다 — CKEditor5 가 이미지·표를 figure 로 감싸므로 더한다.
        // 정의 캐시는 ID·판 번호로 갈린다: 허용 요소를 바꾸면 DefinitionRev 를 올린다.
        $config->set('HTML.DefinitionID', 'sirsoft-popup-body');
        $config->set('HTML.DefinitionRev', 1);

        $cachePath = $this->cachePath();
        if ($cachePath === null) {
            $config->set('Cache.DefinitionImpl', null);
        } else {
            $config->set('Cache.SerializerPath', $cachePath);
            $config->set('Cache.SerializerPermissions', self::CACHE_DIR_MODE);
        }

        $def = $config->maybeGetRawHTMLDefinition();
        if ($def !== null) {
            $def->addElement('figure', 'Block', 'Flow', 'Common');
            $def->addElement('figcaption', 'Inline', 'Flow', 'Common');
        }

        return $config;
    }

    /**
     * 정의 캐시 경로를 준비합니다 — 쓸 수 없으면 null(캐시 없이 동작, 한 번만 error 로그).
     *
     * @return string|null 캐시 경로
     */
    private function cachePath(): ?string
    {
        $dir = ExtensionStoragePath::plugin(self::PLUGIN, 'cache/htmlpurifier');
        if (FilePermissionHelper::ensureWritableDirectory($dir, self::CACHE_DIR_MODE, $failure)) {
            return $dir;
        }

        if (! self::$cacheWarned) {
            self::$cacheWarned = true;
            // 출하 LOG_LEVEL=error 라 warning 으로는 남지 않는다 — 운영자가 권한을 고칠 근거를 남긴다
            Log::error('팝업 본문 정화기의 정의 캐시 디렉토리를 쓸 수 없어 캐시 없이 동작합니다', [
                'plugin' => self::PLUGIN,
                'expected_path' => $dir,
                'reason' => $failure['reason'] ?? 'unknown',
                'blocking_path' => $failure['path'] ?? $dir,
            ]);
        }

        return null;
    }

    /**
     * 정화 전후 비교용으로 공백 차이를 지웁니다(서식 손실이 아닌 줄바꿈 차이로 「바뀜」을 알리지 않게).
     *
     * @param  string  $html  HTML
     * @return string 비교용 문자열
     */
    private function normalizeForCompare(string $html): string
    {
        return preg_replace('/\s+/', ' ', trim(str_replace(['<br />', '<br/>'], '<br>', $html))) ?? $html;
    }
}

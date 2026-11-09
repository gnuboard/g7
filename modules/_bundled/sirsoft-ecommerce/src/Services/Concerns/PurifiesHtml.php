<?php

namespace Modules\Sirsoft\Ecommerce\Services\Concerns;

use App\Extension\Helpers\FilePermissionHelper;
use App\Support\Content\ContentHtmlPolicy;
use App\Support\ExtensionStoragePath;
use Illuminate\Support\Facades\Log;

/**
 * 이 모듈이 저장하는 HTML 을 하나의 규칙으로 정화합니다.
 *
 * 상품 설명(HTML 모드)과 회원·주문 없이 등록한 후기(다른 쇼핑몰에서 이관한 HTML 본문)가 같은 허용 목록을
 * 쓴다. 규칙이 서비스마다 갈리면 한쪽에서 막은 태그·속성이 다른 쪽으로 저장돼 같은 화면에 섞여 나온다.
 *
 * HTMLPurifier 정의 캐시는 `storage/` 아래에 둔다 — 지정하지 않으면 HTMLPurifier 는 자기 설치 폴더
 * (`{module}/vendor/ezyang/htmlpurifier/library/.../DefinitionCache/Serializer/`)에 캐시를 쓰는데, 배포본의
 * vendor 를 읽기 전용으로 두는 서버에서는 그 쓰기가 `E_USER_WARNING` 을 내고 Laravel 이 이를 `ErrorException`
 * 으로 승격시켜 저장이 매번 500 이 된다 (공개 #125).
 */
trait PurifiesHtml
{
    /**
     * HTMLPurifier 인스턴스 (지연 생성)
     */
    protected ?\HTMLPurifier $purifier = null;

    /**
     * 「본문 콘텐츠」 규칙 정화기 (규칙 해시별, #883)
     *
     * @var array<string, \HTMLPurifier>
     */
    protected array $contentPurifiers = [];

    /**
     * 정의 캐시 디렉토리의 소유 모듈 식별자
     */
    private const PURIFIER_MODULE_IDENTIFIER = 'sirsoft-ecommerce';

    /**
     * 기본 허용 목록 (상품 설명·이관 후기 공통). 「본문 콘텐츠」 규칙은 여기에 켜진 후보 요소를 더한다 — 설정에서 다시 읽으면
     * HTMLPurifier 설정이 확정돼 더 쓸 수 없다.
     */
    private const PURIFIER_BASE_ALLOWED = 'p,br,strong,em,b,i,u,s,ul,ol,li,a[href|target],img[src|alt|width|height],h1,h2,h3,h4,h5,h6,table,tr,td,th,thead,tbody,tfoot,caption,colgroup,col,blockquote,pre,code,div,span[style],hr';

    /**
     * HTMLPurifier 정의 캐시 디렉토리 권한
     *
     * 0775 인 이유: CLI(스케줄러/큐)와 웹(php-fpm)이 같은 캐시를 공유해야 한다. 그룹 쓰기가
     * 없으면 먼저 만든 프로세스가 상대를 잠근다. `Cache.SerializerPermissions` 로 HTMLPurifier 가
     * 만드는 하위 디렉토리에도 전파되며, `.ser` 파일에는 `$chmod & 0666` 이 적용되어 0664 가 된다.
     */
    private const PURIFIER_CACHE_DIR_MODE = 0775;

    /**
     * 정의 캐시 비활성 통지를 프로세스당 1회만 남기기 위한 플래그
     */
    private static bool $purifierCacheWarned = false;

    /**
     * HTML 문자열을 허용 목록으로 정화합니다.
     *
     * @param  string  $html  정화할 HTML
     * @param  string  $profile  정화 강도 — `baseline`(종전 허용 목록, 리뷰 등) | `content`(코어 「본문 콘텐츠」 규칙을 더함, 상품 설명)
     * @return string 정화된 HTML
     */
    protected function purifyHtml(string $html, string $profile = 'baseline'): string
    {
        // 상품 설명·공통 정보는 코어 「본문 콘텐츠」 규칙(그림 캡션·업로드 동영상·오디오·외부 영상)을 따른다 — 게시판 표시와 같은 규칙 (D20).
        // 그 밖(이관 후기 등 외부에서 들어온 HTML)은 기존 허용 목록 그대로다(baseline).
        if ($profile === 'content') {
            return $this->purifyContentHtml($html);
        }

        if ($this->purifier === null) {
            $this->purifier = $this->createPurifier();
        }

        return $this->purifier->purify($html);
    }

    /**
     * 「본문 콘텐츠」 규칙으로 정화합니다 — 기존 허용 목록 + 켜진 후보 요소, 정화 뒤 코어 마무리(미디어 출처·외부 영상 판정).
     *
     * 정의 캐시는 규칙 해시(`ContentHtmlPolicy::htmlPurifierDefinition`)를 DefinitionRev 로 써서 설정이 바뀌면 다시 만든다.
     *
     * @param  string  $html  정화할 HTML
     * @return string 정화된 HTML
     */
    protected function purifyContentHtml(string $html): string
    {
        $policy = ContentHtmlPolicy::current();
        $definition = $policy->htmlPurifierDefinition();

        if (! isset($this->contentPurifiers[$definition['id']])) {
            $cachePath = $this->resolvePurifierCachePath($failure);
            $this->noticePurifierCacheDisabled($cachePath, $failure);
            $config = $this->buildPurifierConfig($cachePath);
            $this->extendContentDefinition($config, $definition, $policy->customTags());
            $this->contentPurifiers[$definition['id']] = new \HTMLPurifier($config);
        }

        return $policy->finalize($this->contentPurifiers[$definition['id']]->purify($html), 'store');
    }

    /**
     * 켜진 후보 요소와 운영자 추가 태그를 HTMLPurifier 정의에 더합니다.
     *
     * 추가 태그는 게시판 표시·봇 정화기와 같은 규칙으로 남긴다(D18) — 속성은 받지 않고, 이벤트·`javascript:` 속성은
     * `ContentHtmlPolicy::finalize` 가 한 번 더 지운다.
     *
     * @param  \HTMLPurifier_Config  $config  기본 설정
     * @param  array{rev: int, id: string, elements: array<string, array<int, string>>}  $definition  규칙 정의
     * @param  array<int, string>  $customTags  운영자 추가 태그
     * @return void 반환값 없음
     */
    private function extendContentDefinition(\HTMLPurifier_Config $config, array $definition, array $customTags = []): void
    {
        // 후보 요소가 이미 정의한 태그는 그쪽 속성 규칙을 따른다
        $customTags = array_values(array_diff($customTags, array_keys($definition['elements'])));

        if ($definition['elements'] === [] && $customTags === []) {
            return;
        }

        $allowed = self::PURIFIER_BASE_ALLOWED;
        foreach ($definition['elements'] as $tag => $attributes) {
            $allowed .= ','.$tag.($attributes !== [] ? '['.implode('|', $attributes).']' : '');
        }
        foreach ($customTags as $tag) {
            $allowed .= ','.$tag;
        }
        $config->set('HTML.Allowed', $allowed);
        $config->set('HTML.DefinitionID', $definition['id']);
        $config->set('HTML.DefinitionRev', $definition['rev']);

        $raw = $config->maybeGetRawHTMLDefinition();
        if ($raw === null) {
            return;
        }

        $types = [
            'src' => 'URI', 'poster' => 'URI', 'url' => 'URI', 'controls' => 'Bool', 'playsinline' => 'Bool', 'default' => 'Bool',
            'width' => 'Length', 'height' => 'Length', 'class' => 'Class',
        ];
        $content = ['figure' => 'Flow', 'figcaption' => 'Flow', 'video' => 'Optional: source | track | #PCDATA', 'audio' => 'Optional: source | #PCDATA',
            'source' => 'Empty', 'track' => 'Empty', 'oembed' => 'Empty'];
        foreach ($definition['elements'] as $tag => $attributes) {
            $spec = [];
            foreach ($attributes as $attribute) {
                $spec[$attribute] = $types[$attribute] ?? 'Text';
            }
            $raw->addElement($tag, in_array($tag, ['source', 'track', 'oembed'], true) ? 'Inline' : 'Block', $content[$tag] ?? 'Flow', 'Common', $spec);
        }
        foreach ($customTags as $tag) {
            $raw->addElement($tag, 'Block', 'Flow', 'Common', []);
        }
    }

    /**
     * HTMLPurifier 인스턴스를 생성합니다.
     *
     * 캐시 디렉토리를 확보하지 못하면 정의 캐시만 끄고(`Cache.DefinitionImpl = null`) 정화는 그대로
     * 수행합니다 — 캐시는 성능 장치이고 정화는 보안 장치라, 캐시 실패가 보안 장치를 건너뛰게
     * 만들어서는 안 됩니다.
     *
     * @return \HTMLPurifier 설정이 적용된 인스턴스
     */
    protected function createPurifier(): \HTMLPurifier
    {
        $cachePath = $this->resolvePurifierCachePath($failure);
        $this->noticePurifierCacheDisabled($cachePath, $failure);

        return new \HTMLPurifier($this->buildPurifierConfig($cachePath));
    }

    /**
     * 정의 캐시 디렉토리를 확보하지 못했으면 프로세스당 1회 운영자 통지를 남깁니다 — 기본·「본문 콘텐츠」 정화기 공통.
     *
     * @param  string|null  $cachePath  확보한 캐시 경로 (null 이면 확보 실패)
     * @param  array{reason: string, path: string}|null  $failure  확보 실패 사유와 그 대상 경로
     * @return void 반환값 없음
     */
    private function noticePurifierCacheDisabled(?string $cachePath, ?array $failure): void
    {
        if ($cachePath === null && ! self::$purifierCacheWarned) {
            self::$purifierCacheWarned = true;

            // `error` 로 남기는 이유: G7 출하 기본값(config/settings/defaults.json)의 `log_level` 이
            // `error` 라, `warning` 으로 남기면 기본 설치 상태에서는 이 통지가 로그 파일에 아예
            // 기록되지 않는다. 저장은 성공하므로 사용자 피해는 없지만, 캐시를 못 쓰는 상태가
            // 흔적 없이 영구히 유지되어 운영자가 조치할 근거를 얻지 못한다.
            Log::error('HTMLPurifier 정의 캐시 디렉토리를 사용할 수 없어 캐시 없이 동작합니다', [
                'module' => self::PURIFIER_MODULE_IDENTIFIER,
                'expected_path' => $this->purifierCacheDirectory(),
                // 사유마다 고쳐야 할 대상이 다르다 — 상위 디렉토리 권한 / 그 자리를 차지한 파일 /
                // 대상 자신의 권한. 사유 없이 경로만 남기면 운영자가 어디를 볼지 알 수 없다.
                'reason' => $failure['reason'] ?? 'unknown',
                'blocking_path' => $failure['path'] ?? $this->purifierCacheDirectory(),
                'impact' => 'HTML 정화(상품 설명·이관 후기)가 요청마다 정의를 다시 계산합니다. 해당 디렉토리의 쓰기 권한을 확인하세요.',
            ]);
        }
    }

    /**
     * HTMLPurifier 설정을 조립합니다.
     *
     * @param  string|null  $cachePath  정의 캐시 디렉토리 절대 경로 (null 이면 캐시 비활성)
     * @return \HTMLPurifier_Config 조립된 설정
     */
    protected function buildPurifierConfig(?string $cachePath): \HTMLPurifier_Config
    {
        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.Allowed', self::PURIFIER_BASE_ALLOWED);
        $config->set('CSS.AllowedProperties', 'color,background-color,font-size,font-weight,text-align,text-decoration,margin,padding,border,width,height');
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        if ($cachePath === null) {
            $config->set('Cache.DefinitionImpl', null);

            return $config;
        }

        $config->set('Cache.SerializerPath', $cachePath);
        // HTMLPurifier 가 `{경로}/HTML` 하위 디렉토리와 `.ser` 파일을 만들 때 쓰는 권한.
        // 기본값 0755 는 CLI 가 먼저 만들면 웹 계정이 못 쓴다.
        $config->set('Cache.SerializerPermissions', self::PURIFIER_CACHE_DIR_MODE);

        return $config;
    }

    /**
     * 정의 캐시 디렉토리를 확보하고 절대 경로를 반환합니다.
     *
     * 디렉토리가 이미 있는데 쓰기 불가라면 요청 경로에서 권한을 고치려 들지 않고 null 을
     * 돌려줍니다 — 호출측이 캐시를 끄고 정화는 그대로 수행합니다.
     *
     * 확보 자체(경고를 내지 않는 생성 · umask 무력화 · POSIX setgid · 소유권 상속 · 쓰기 판정)는
     * 코어 공통 프리미티브가 맡습니다. 그 프리미티브는 예외도 PHP 경고도 내지 않으므로, 이 수정이
     * 막으려던 500 이 확보 실패 지점에서 다시 나는 일이 없습니다.
     *
     * @param  array{reason: string, path: string}|null  $failure  out — 확보 실패 사유와 그 대상 경로
     * @return string|null 사용 가능한 절대 경로. 확보 실패 시 null
     */
    protected function resolvePurifierCachePath(?array &$failure = null): ?string
    {
        $dir = $this->purifierCacheDirectory();

        return FilePermissionHelper::ensureWritableDirectory($dir, self::PURIFIER_CACHE_DIR_MODE, $failure)
            ? $dir
            : null;
    }

    /**
     * 정의 캐시 디렉토리의 절대 경로 (존재 여부와 무관한 순수 계산).
     *
     * 경로는 코어 해석기가 `modules` 디스크 root 를 단일 출처로 삼아 조립합니다.
     * `getStorageBasePath('cache')` 를 경유하지 않는 이유는 그 반환값이 `Storage::disk()->path()`
     * 위임이라, 확장이 카테고리 디스크를 비로컬(S3 등)로 오버라이드하면 파일시스템 경로가 아니게
     * 되기 때문입니다 — HTMLPurifier 는 `file_put_contents` 로 쓰므로 반드시 로컬 절대 경로여야
     * 합니다.
     *
     * @return string 절대 경로
     */
    protected function purifierCacheDirectory(): string
    {
        return ExtensionStoragePath::module(self::PURIFIER_MODULE_IDENTIFIER, 'cache/htmlpurifier');
    }
}

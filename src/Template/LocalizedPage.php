<?php declare(strict_types=1);

namespace GES\Botlock\Template;

use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Response\HtmlResponse;
use GES\Botlock\I18n\LanguageNegotiator;
use GES\Botlock\I18n\TranslationLoader;

/**
 * One browser page in the language negotiated from Accept-Language. Each
 * language is rendered once and then served from the on-disk cache in the
 * state directory; the cache version covers the template, the partials it
 * includes and the translation file.
 */
final readonly class LocalizedPage
{
    private const JSON_FLAGS = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT
        | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    /**
     * @param string       $page         Cache name of the page, lowercase letters only
     * @param string       $templatePath Page template
     * @param list<string> $includes     Partials the template includes
     */
    public function __construct(
        private LanguageNegotiator $negotiator,
        private TranslationLoader  $translations,
        private TemplateRenderer   $renderer,
        private RenderedPageCache  $cache,
        private string             $page,
        private string             $templatePath,
        private array              $includes = [],
    ) {}

    /**
     * @param array<string,string> $headers Added to the language and caching headers
     */
    public function respond(Request $request, int $status, array $headers = []): HtmlResponse
    {
        $lang = $this->negotiator->negotiate($request->getHeader('Accept-Language'));
        $version = RenderedPageCache::versionOf(...[$this->templatePath, ...$this->includes, $this->translations->file($lang)]);

        $headers = [
            'Content-Language' => $lang,
            'Vary' => 'Accept-Language',
            'Cache-Control' => 'no-store',
        ] + $headers;

        if (null !== ($cached = $this->cache->find($this->page, $lang, $version))) {
            return new HtmlFileResponse($cached, $status, $headers);
        }

        $trans = $this->translations->load($lang);

        $html = $this->renderer->render($this->templatePath, [
            'lang' => $lang,
            'trans' => $trans,
            'transJson' => \json_encode($trans, self::JSON_FLAGS),
        ]);

        $stored = $this->cache->store($this->page, $lang, $version, $html);

        // The cache is best effort: an unwritable state dir must not block the page.
        return $stored !== null
            ? new HtmlFileResponse($stored, $status, $headers)
            : new HtmlResponse($status, $html, $headers);
    }
}

<?php declare(strict_types=1);

namespace GES\Botlock\Middleware;

use GES\Botlock\Http\Middleware\MiddlewareInterface;
use GES\Botlock\Http\Request;
use GES\Botlock\Http\Response;
use GES\Botlock\Http\Response\HtmlFileResponse;
use GES\Botlock\Http\Response\HtmlResponse;
use GES\Botlock\I18n\LanguageNegotiator;
use GES\Botlock\I18n\TranslationLoader;
use GES\Botlock\Template\RenderedPageCache;
use GES\Botlock\Template\TemplateRenderer;

/**
 * Serves the browser challenge page in the language negotiated from
 * Accept-Language unless the session holds a grant for at least the
 * current threat level. Each language is rendered once and then served from
 * the on-disk cache in the state directory.
 */
final readonly class ChallengeDocumentMiddleware implements MiddlewareInterface
{
    private const JSON_FLAGS = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT
        | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

    public function __construct(
        private LanguageNegotiator $negotiator,
        private TranslationLoader  $translations,
        private TemplateRenderer   $renderer,
        private RenderedPageCache  $cache,
        private string             $templatePath,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->context->isGrantSufficient())
        {
            return $next($request);
        }

        $request->context->session->commit();

        $lang = $this->negotiator->negotiate($request->getHeader('Accept-Language'));
        $version = RenderedPageCache::versionOf($this->templatePath, $this->translations->file($lang));

        $headers = [
            'Content-Language' => $lang,
            'Vary' => 'Accept-Language',
            'Cache-Control' => 'no-store',
        ];

        if (null !== ($cached = $this->cache->find($lang, $version))) {
            return new HtmlFileResponse($cached, 401, $headers);
        }

        $trans = $this->translations->load($lang);

        $html = $this->renderer->render($this->templatePath, [
            'lang' => $lang,
            'trans' => $trans,
            'transJson' => \json_encode($trans, self::JSON_FLAGS),
        ]);

        $stored = $this->cache->store($lang, $version, $html);

        // The cache is best effort: an unwritable state dir must not block the challenge.
        return $stored !== null
            ? new HtmlFileResponse($stored, 401, $headers)
            : new HtmlResponse(401, $html, $headers);
    }
}

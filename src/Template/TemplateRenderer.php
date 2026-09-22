<?php declare(strict_types=1);

namespace GES\Botlock\Template;

/**
 * Renders a native PHP template with output buffering. The template runs
 * in an isolated scope and receives the given variables plus an HTML
 * escape helper as $e.
 */
final readonly class TemplateRenderer
{
    /**
     * @param string              $file Template path
     * @param array<string,mixed> $vars Become local variables in the template
     *
     * @throws \RuntimeException when the template does not exist
     */
    public function render(string $file, array $vars): string
    {
        if (!\is_file($file)) {
            throw new \RuntimeException("Template not found: {$file}");
        }

        $vars['e'] = self::escape(...);
        $level = \ob_get_level();

        \ob_start();

        try
        {
            // Own scope: no $this, no renderer locals; the template only sees $vars.
            (static function (array $__vars): void {
                \extract($__vars, \EXTR_SKIP);
                unset($__vars);
                include \func_get_arg(1);
            })($vars, $file);

            return (string) \ob_get_clean();
        }
        catch (\Throwable $th)
        {
            while (\ob_get_level() > $level) {
                \ob_end_clean();
            }

            throw $th;
        }
    }

    public static function escape(string $value): string
    {
        return \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
    }
}

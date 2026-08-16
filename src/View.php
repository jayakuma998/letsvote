<?php
declare(strict_types=1);

final class View
{
    /**
     * Renders templates/<name>.php inside templates/layout.php.
     *
     * @param array<string,mixed> $data becomes local variables in the template
     */
    public static function render(string $name, array $data = [], string $title = 'LetsVote'): void
    {
        $file = APP_ROOT . '/templates/' . $name . '.php';
        if (!is_file($file)) {
            throw new RuntimeException("Template not found: {$name}");
        }

        // Resolved before extract() so the content template can use them too.
        // EXTR_SKIP then stops a template's own data from clobbering these,
        // or $name / $file / $title / $data.
        $flashes = Session::takeFlashes();
        $user    = $data['user'] ?? (Auth::check() ? Auth::user() : null);

        extract($data, EXTR_SKIP);

        ob_start();
        require $file;
        $content = (string) ob_get_clean();

        require APP_ROOT . '/templates/layout.php';
    }
}

/** Escape for HTML output. Used everywhere in the templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

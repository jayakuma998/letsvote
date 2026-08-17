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

/**
 * CSS class carrying a candidate's party colour, e.g. "APC" -> "party-apc".
 *
 * The colour has to arrive as a class rather than a style attribute: the
 * Content-Security-Policy is style-src 'self', so an inline
 * style="--accent: #..." would be dropped by the browser. Parties without a
 * rule in style.css fall back to the neutral default, so an unknown party
 * still renders correctly.
 */
function candidate_party_class(mixed $abbr): string
{
    $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $abbr));

    return $slug === '' ? 'party-default' : 'party-' . $slug;
}

/** First letters of the first two words, for the no-image fallback avatar. */
function candidate_initials(mixed $fullName): string
{
    $words = preg_split('/\s+/', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $letters = '';

    foreach (array_slice($words, 0, 2) as $word) {
        $letters .= mb_strtoupper(mb_substr($word, 0, 1));
    }

    return $letters === '' ? '?' : $letters;
}

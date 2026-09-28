<?php
declare(strict_types=1);

namespace Calage;

final class View
{
    /** Renders templates/{template}.php, wrapped in templates/{layout}.php (null = no layout). */
    public static function render(string $template, array $vars = [], ?string $layout = 'layout'): void
    {
        if ($layout === 'layout') {
            $vars += ['nav' => self::nav()];
        }
        $content = self::capture($template, $vars);
        echo $layout === null ? $content : self::capture($layout, $vars + ['content' => $content]);
    }

    /**
     * Newsletter HTML for the preview <iframe sandbox>. The "sandbox" CSP header also protects
     * when the URL is opened directly: no scripts, origin isolated from the application.
     */
    public static function sandboxed(string $html): void
    {
        // No charset forced: the browser follows the newsletter's <meta charset>
        // (otherwise PHP adds "charset=UTF-8", which wins over a Windows-1252 newsletter).
        ini_set('default_charset', '');
        header('Content-Type: text/html');
        header('Content-Security-Policy: ' . self::previewPolicy(
            ($_GET['fonts'] ?? '') === 'off',
            ($_GET['images'] ?? '') === 'off'
        ));
        header('Referrer-Policy: no-referrer');
        echo $html;
    }

    /**
     * CSP header of the preview. "Degraded preview": the browser refuses downloaded fonts
     * (fallback font, like Outlook on Windows) and/or images (alt texts, like an email client
     * that blocks images). The newsletter HTML is not modified.
     */
    public static function previewPolicy(bool $noFonts, bool $noImages): string
    {
        return implode('; ', array_merge(
            ['sandbox'],
            $noFonts ? ["font-src 'none'"] : [],
            $noImages ? ["img-src 'none'"] : []
        ));
    }

    public static function error(int $code, string $detail = '', string $layout = 'layout'): void
    {
        $titles = [
            400 => __('Bad request'),
            403 => __('Access denied'),
            404 => __('Page not found'),
            405 => __('Method not allowed'),
            413 => __('Upload too large'),
            500 => __('Internal error'),
        ];
        if (!headers_sent()) {
            http_response_code($code);
        }
        self::render('error', [
            'title' => $titles[$code] ?? __('Error'),
            'code' => $code,
            'detail' => $detail,
            'backOffice' => $layout === 'layout',
        ], $layout);
    }

    /** Folder pane of the back office (null when logged out: sign-in, setup). */
    private static function nav(): ?array
    {
        $loggedIn = session_status() === PHP_SESSION_ACTIVE && ($_SESSION['auth'] ?? false) === true;
        if (!$loggedIn || !App::isConfigured()) {
            return null;
        }
        $db = App::db();
        return [
            'folders' => (new Repo\FolderRepo($db))->all(),
            'total' => (int) $db->query('SELECT COUNT(*) FROM newsletters')->fetchColumn(),
            'withoutFolder' => (new Repo\NewsletterRepo($db))->countWithoutFolder(),
        ];
    }

    private static function capture(string $__template, array $__vars): string
    {
        extract($__vars, EXTR_SKIP);
        ob_start();
        try {
            require APP_ROOT . '/templates/' . $__template . '.php';
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        return (string) ob_get_clean();
    }
}

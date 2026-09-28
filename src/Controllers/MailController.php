<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\App;
use Calage\Html\Renderer;
use Calage\Mailer;
use Calage\Repo\NewsletterRepo;
use Calage\Repo\VersionRepo;
use Calage\Session;
use Calage\View;

final class MailController
{
    /** Sends the displayed version (hosted images, version subject) to one or more addresses. */
    public function sendPreview(array $params): void
    {
        $db = App::db();
        $versions = new VersionRepo($db);
        $version = $versions->find((int) $params['id']);
        $newsletter = $version === null ? null : (new NewsletterRepo($db))->find((int) $version['newsletter_id']);
        if ($version === null || $newsletter === null) {
            View::error(404);
            return;
        }
        $back = '/newsletters/' . $newsletter['id'] . '?v=' . $version['id'];

        $input = (string) ($_POST['recipients'] ?? '');
        $_SESSION['recipients'] = mb_substr(trim($input), 0, 2000); // pre-filled next time
        [$recipients, $invalid] = Mailer::parseAddresses($input);

        $mailer = Mailer::fromConfig();
        $error = match (true) {
            !$mailer->isConfigured() => __('Email sending is not set up: fill in the SMTP settings on the Settings page.'),
            $invalid !== [] => __n('Invalid address: {list}', 'Invalid addresses: {list}', count($invalid), ['list' => implode(', ', $invalid)]),
            $recipients === [] => __('Enter at least one address.'),
            count($recipients) > Mailer::MAX_RECIPIENTS => __('At most {n} addresses per sending.', ['n' => Mailer::MAX_RECIPIENTS]),
            default => null,
        };
        if ($error !== null) {
            Session::flash('error', $error);
            redirect($back);
        }

        $html = Renderer::absolute($version['html'], $versions->images((int) $version['id']), App::absoluteUrl('/'));
        $subject = $version['subject'] !== '' ? $version['subject'] : $newsletter['name'];
        $results = $mailer->send($recipients, $subject, $html);

        $sent = array_keys(array_filter($results, fn(?string $e): bool => $e === null));
        $failed = array_filter($results, fn(?string $e): bool => $e !== null);
        if ($sent !== []) {
            Session::flash('success', __('Preview sent to {list}.', ['list' => implode(', ', $sent)]));
        }
        foreach ($failed as $address => $message) {
            Session::flash('error', __('Failed for {address}: {message}', ['address' => $address, 'message' => $message]));
        }
        if ($sent !== [] && SettingsController::isLocalBaseUrl()) {
            Session::flash('warning', __('The public address is a local one: recipients will not see the images.'));
        }
        redirect($back);
    }
}

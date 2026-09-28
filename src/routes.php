<?php
declare(strict_types=1);

use Calage\Controllers\AuthController;
use Calage\Controllers\ClientController;
use Calage\Controllers\DashboardController;
use Calage\Controllers\ExportController;
use Calage\Controllers\FolderController;
use Calage\Controllers\ImportController;
use Calage\Controllers\MailController;
use Calage\Controllers\NewsletterController;
use Calage\Controllers\SettingsController;
use Calage\Controllers\SetupController;
use Calage\Controllers\UploadController;
use Calage\Controllers\VersionController;
use Calage\Router;

$r = new Router();

// Setup: reachable only until config.php is filled in.
$r->get('/setup', [SetupController::class, 'show'], ['public' => true, 'setup' => true]);
$r->post('/setup', [SetupController::class, 'install'], ['public' => true, 'setup' => true]);
$r->post('/setup/smtp-test', [SetupController::class, 'smtpTest'], ['public' => true, 'setup' => true]);

// Authentication
$r->get('/login', [AuthController::class, 'showLogin'], ['public' => true]);
$r->post('/login', [AuthController::class, 'login'], ['public' => true]);
$r->post('/logout', [AuthController::class, 'logout']);

// Share page (public link, no account)
$r->get('/c/{token}', [ClientController::class, 'latest'], ['public' => true]);
$r->get('/c/{token}/v/{n}', [ClientController::class, 'version'], ['public' => true]);
$r->get('/c/{token}/v/{n}/render', [ClientController::class, 'render'], ['public' => true]);
$r->get('/c/{token}/v/{n}/zip', [ExportController::class, 'clientZip'], ['public' => true]);

// Back office
$r->get('/', [DashboardController::class, 'index']);

// Folders
$r->post('/folders', [FolderController::class, 'create']);
$r->post('/folders/{id}/rename', [FolderController::class, 'rename']);
$r->post('/folders/{id}/delete', [FolderController::class, 'delete']);

// Import: upload → review → confirm
$r->get('/import', [ImportController::class, 'form']);
$r->post('/imports', [ImportController::class, 'upload']); // plain upload (without JavaScript)
$r->post('/uploads', [UploadController::class, 'start']);  // chunked upload
$r->post('/uploads/{upload}/chunk', [UploadController::class, 'chunk']);
$r->post('/uploads/{upload}/finish', [UploadController::class, 'finish']);
$r->get('/imports/{import}', [ImportController::class, 'review']);
$r->post('/imports/{import}/compile', [ImportController::class, 'compile']);
$r->post('/imports/{import}/confirm', [ImportController::class, 'confirm']);

// Newsletters and versions
$r->get('/newsletters/{id}', [NewsletterController::class, 'show']);
$r->get('/newsletters/{id}/edit', [NewsletterController::class, 'edit']);
$r->post('/newsletters/{id}/draft', [NewsletterController::class, 'saveDraft']);
$r->post('/newsletters/{id}/draft/discard', [NewsletterController::class, 'discardDraft']);
$r->post('/newsletters/{id}/publish', [NewsletterController::class, 'publish']);
$r->post('/newsletters/{id}/rename', [NewsletterController::class, 'rename']);
$r->post('/newsletters/{id}/move', [NewsletterController::class, 'move']);
$r->post('/newsletters/{id}/duplicate', [NewsletterController::class, 'duplicate']);
$r->post('/newsletters/{id}/delete', [NewsletterController::class, 'delete']);
$r->get('/versions/{id}/render', [VersionController::class, 'render']);
$r->post('/versions/{id}/restore', [VersionController::class, 'restore']);
$r->get('/versions/{id}/export.zip', [ExportController::class, 'zip']);
$r->get('/versions/{id}/export.html', [ExportController::class, 'html']);
$r->get('/versions/{id}/export-mjml.zip', [ExportController::class, 'mjmlZip']);
$r->get('/versions/{id}/export.mjml', [ExportController::class, 'mjml']);
$r->post('/versions/{id}/send', [MailController::class, 'sendPreview']);

// Settings (rewrite config.php)
$r->get('/settings', [SettingsController::class, 'show']);
$r->post('/settings/language', [SettingsController::class, 'saveLanguage']);
$r->post('/settings/address', [SettingsController::class, 'saveAddress']);
$r->post('/settings/account', [SettingsController::class, 'saveAccount']);
$r->post('/settings/smtp', [SettingsController::class, 'saveSmtp']);
$r->post('/settings/smtp-test', [SettingsController::class, 'smtpTest']);

return $r;

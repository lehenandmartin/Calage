<?php
declare(strict_types=1);

namespace Calage\Controllers;

use Calage\Auth;
use Calage\Session;
use Calage\View;

final class AuthController
{
    public function showLogin(array $params): void
    {
        if (Auth::check()) {
            redirect('/');
        }
        View::render('login', ['title' => __('Sign in'), 'username' => '']);
    }

    public function login(array $params): void
    {
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if (!Auth::attempt($username, $password)) {
            http_response_code(401);
            Session::flash('error', __('Incorrect username or password.'));
            View::render('login', ['title' => __('Sign in'), 'username' => $username]);
            return;
        }

        $intended = $_SESSION['intended'] ?? '/';
        unset($_SESSION['intended']);
        // Only an internal path, never an external URL.
        redirect(is_string($intended) && preg_match('#^/(?!/)#', $intended) ? $intended : '/');
    }

    public function logout(array $params): void
    {
        Auth::logout();
        redirect('/login');
    }
}

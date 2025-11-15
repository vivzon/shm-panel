<?php
// File: app/controllers/AuthController.php

require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../models/User.php';

class AuthController extends Controller
{
    public function login()
    {
        Auth::startSession();
        $config = require __DIR__ . '/../../config/config.php';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $login    = trim($_POST['login'] ?? '');
            $password = $_POST['password'] ?? '';

            $userModel = new User();
            $user = $userModel->findByEmailOrUsername($login);

            if ($user && password_verify($password, $user['password']) && $user['status'] == 1) {
                Auth::login($user);

                // Redirect based on role
                $base = $config['base_url'];
                if ($user['role'] === 'super_admin') {
                    $this->redirect($base . '/admin/dashboard');
                } elseif ($user['role'] === 'reseller') {
                    $this->redirect($base . '/admin/dashboard'); // later: /reseller/dashboard
                } else {
                    $this->redirect($base . '/client/dashboard');
                }
            } else {
                $error = "Invalid credentials or account inactive.";
                $this->view('auth/login', compact('error'));
                return;
            }
        } else {
            $this->view('auth/login');
        }
    }

    public function logout()
    {
        Auth::logout();
        $config = require __DIR__ . '/../../config/config.php';
        $this->redirect($config['base_url'] . '/auth/login');
    }
}

<?php
// File: app/core/Auth.php

class Auth
{
    public static function startSession()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    public static function login($user)
    {
        self::startSession();
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role']    = $user['role'];
        $_SESSION['name']    = $user['name'];
    }

    public static function logout()
    {
        self::startSession();
        session_destroy();
    }

    public static function check()
    {
        self::startSession();
        return isset($_SESSION['user_id']);
    }

    public static function user()
    {
        self::startSession();
        if (!self::check()) return null;
        return [
            'id'   => $_SESSION['user_id'],
            'role' => $_SESSION['role'],
            'name' => $_SESSION['name'],
        ];
    }

    public static function requireRole($roles = [])
    {
        self::startSession();
        if (!self::check()) {
            header('Location: /shm-panel/public/auth/login');
            exit;
        }

        if (!in_array($_SESSION['role'], (array)$roles)) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }
    }
}

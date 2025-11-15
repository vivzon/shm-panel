<?php
// File: app/models/User.php

require_once __DIR__ . '/../core/Model.php';

class User extends Model
{
    public function findByEmailOrUsername($login)
    {
        $sql = "SELECT * FROM users WHERE email = ? OR username = ? LIMIT 1";
        $stmt = $this->prepare($sql);
        $stmt->bind_param('ss', $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    public function createAdmin($name, $email, $username, $password)
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'super_admin';
        $status = 1;

        $sql = "INSERT INTO users (role, name, email, username, password, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $this->prepare($sql);
        $stmt->bind_param('sssssi', $role, $name, $email, $username, $hash, $status);
        return $stmt->execute();
    }
}

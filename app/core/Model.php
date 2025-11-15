<?php
// File: app/core/Model.php

class Model
{
    protected $db;

    public function __construct()
    {
        $config = require __DIR__ . '/../../config/config.php';

        $this->db = new mysqli(
            $config['db_host'],
            $config['db_user'],
            $config['db_pass'],
            $config['db_name']
        );

        if ($this->db->connect_error) {
            die('Database connection failed: ' . $this->db->connect_error);
        }

        $this->db->set_charset('utf8mb4');
    }

    protected function prepare($sql)
    {
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            die('SQL error: ' . $this->db->error);
        }
        return $stmt;
    }
}

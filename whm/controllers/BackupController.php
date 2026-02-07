<?php
require_once __DIR__ . '/../../shared/config.php';

class BackupController
{

    public function index()
    {
        // We will fetch backups via AJAX to prevent locking the UI during listing
        return [];
    }
}

<?php
// File: app/controllers/AdminController.php

require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../models/Server.php';

class AdminController extends Controller
{
    public function dashboard()
    {
        Auth::requireRole(['super_admin', 'reseller']);

        $serverModel = new Server();
        $servers = $serverModel->all();
        $serverCount = count($servers);

        // For now, mock stats:
        $totalResellers = 3;
        $totalClients   = 10;
        $activeAccounts = 8;

        $this->view('admin/dashboard', compact('serverCount', 'totalResellers', 'totalClients', 'activeAccounts'));
    }
}

<?php
// File: app/controllers/ServerController.php

require_once __DIR__ . '/../core/Controller.php';
require_once __DIR__ . '/../core/Auth.php';
require_once __DIR__ . '/../models/Server.php';

class ServerController extends Controller
{
    public function index()
    {
        Auth::requireRole(['super_admin']);

        $serverModel = new Server();
        $servers = $serverModel->all();

        $this->view('servers/index', compact('servers'));
    }

    public function create()
    {
        Auth::requireRole(['super_admin']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'name'      => htmlspecialchars(trim($_POST['name'] ?? '')),
                'hostname'  => htmlspecialchars(trim($_POST['hostname'] ?? '')),
                'ip_address'=> htmlspecialchars(trim($_POST['ip_address'] ?? '')),
                'ssh_port'  => htmlspecialchars(trim($_POST['ssh_port'] ?? '22')),
                'notes'     => htmlspecialchars(trim($_POST['notes'] ?? '')),
                'status'    => htmlspecialchars(trim($_POST['status'] ?? 'active')),
            ];

            $serverModel = new Server();
            $serverModel->create($data);

            $config = require __DIR__ . '/../../config/config.php';
            $this->redirect($config['base_url'] . '/servers');
        } else {
            $server = [
                'name' => '',
                'hostname' => '',
                'ip_address' => '',
                'ssh_port' => '22',
                'notes' => '',
                'status' => 'active'
            ];
            $this->view('servers/form', compact('server'));
        }
    }

    public function edit($id)
    {
        Auth::requireRole(['super_admin']);

        $serverModel = new Server();

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $data = [
                'name'      => htmlspecialchars(trim($_POST['name'] ?? '')),
                'hostname'  => htmlspecialchars(trim($_POST['hostname'] ?? '')),
                'ip_address'=> htmlspecialchars(trim($_POST['ip_address'] ?? '')),
                'ssh_port'  => htmlspecialchars(trim($_POST['ssh_port'] ?? '22')),
                'notes'     => htmlspecialchars(trim($_POST['notes'] ?? '')),
                'status'    => htmlspecialchars(trim($_POST['status'] ?? 'active')),
            ];

            $serverModel->update($id, $data);
            $config = require __DIR__ . '/../../config/config.php';
            $this->redirect($config['base_url'] . '/servers');
        } else {
            $server = $serverModel->find($id);
            $this->view('servers/form', compact('server'));
        }
    }

    public function delete($id)
    {
        Auth::requireRole(['super_admin']);

        $serverModel = new Server();
        $serverModel->delete($id);

        $config = require __DIR__ . '/../../config/config.php';
        $this->redirect($config['base_url'] . '/servers');
    }
}

<?php
// File: app/models/Server.php

require_once __DIR__ . '/../core/Model.php';

class Server extends Model
{
    public function all()
    {
        $result = $this->db->query("SELECT * FROM servers ORDER BY id DESC");
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public function find($id)
    {
        $sql = "SELECT * FROM servers WHERE id = ?";
        $stmt = $this->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function create($data)
    {
        $sql = "INSERT INTO servers (name, hostname, ip_address, ssh_port, notes, status)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->prepare($sql);
        $stmt->bind_param(
            'ssssss',
            $data['name'],
            $data['hostname'],
            $data['ip_address'],
            $data['ssh_port'],
            $data['notes'],
            $data['status']
        );
        return $stmt->execute();
    }

    public function update($id, $data)
    {
        $sql = "UPDATE servers
                SET name=?, hostname=?, ip_address=?, ssh_port=?, notes=?, status=?
                WHERE id=?";
        $stmt = $this->prepare($sql);
        $stmt->bind_param(
            'ssssssi',
            $data['name'],
            $data['hostname'],
            $data['ip_address'],
            $data['ssh_port'],
            $data['notes'],
            $data['status'],
            $id
        );
        return $stmt->execute();
    }

    public function delete($id)
    {
        $sql = "DELETE FROM servers WHERE id = ?";
        $stmt = $this->prepare($sql);
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }
}

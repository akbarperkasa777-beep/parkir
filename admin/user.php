<?php
require_once __DIR__ . '/../includes/app.php';
require_role('admin');
$crud = [
    'title' => 'Data User',
    'table' => 'tb_user',
    'pk' => 'id_user',
    'route' => 'admin/user.php',
    'fields' => [
        'nama_lengkap' => ['label' => 'Nama lengkap', 'type' => 'text', 'required' => true, 'max' => 50],
        'username' => ['label' => 'Username', 'type' => 'text', 'required' => true, 'max' => 50],
        'password' => ['label' => 'Password', 'type' => 'password', 'required' => true, 'max' => 100],
        'role' => ['label' => 'Role', 'type' => 'select', 'required' => true, 'options' => ['admin' => 'Admin', 'petugas' => 'Petugas', 'owner' => 'Owner']],
        'status_aktif' => ['label' => 'Status', 'type' => 'select', 'required' => true, 'options' => ['1' => 'Aktif', '0' => 'Nonaktif']],
    ],
    'columns' => ['id_user' => 'ID', 'nama_lengkap' => 'Nama', 'username' => 'Username', 'role' => 'Role', 'status_aktif' => 'Aktif'],
];
require __DIR__ . '/../includes/admin_crud.php';

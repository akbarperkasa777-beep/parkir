<?php
require_once __DIR__ . '/../includes/app.php';
require_role('admin');
$crud = [
    'title' => 'Tarif Parkir',
    'table' => 'tb_tarif',
    'pk' => 'id_tarif',
    'route' => 'admin/tarif.php',
    'fields' => [
        'jenis_kendaraan' => [
            'label' => 'Jenis kendaraan',
            'type' => 'text',
            'required' => true,
            'max' => 20,
            'lowercase' => true,
            'suggestions' => ['motor' => 'Motor', 'mobil' => 'Mobil', 'lainnya' => 'Lainnya'],
        ],
        'tarif_per_jam' => ['label' => 'Tarif per jam (Rp)', 'type' => 'number', 'required' => true, 'min' => 0],
    ],
    'columns' => ['id_tarif' => 'ID', 'jenis_kendaraan' => 'Jenis kendaraan', 'tarif_per_jam' => 'Tarif per jam (Rp)'],
];
require __DIR__ . '/../includes/admin_crud.php';

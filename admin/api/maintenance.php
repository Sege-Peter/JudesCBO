<?php
// Polled by maintenance.html: ends maintenance once its time is up and tells the page to reload.
require __DIR__ . '/common.php';

header('Cache-Control: no-store');
maintenance_tick();
echo json_encode(['on' => maintenance_on()]);

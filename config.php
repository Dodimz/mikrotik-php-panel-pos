<?php
return [
    // Settingan api router
    'host' => '192.168.88.1',
    'user' => 'admin',        // username
    'pass' => '9V47C8HSKD', // password
    'port' => 8728,
    'timeout' => 5,

    // Billing untuk harga room
    'price_per_hour' => 100000,
    'currency' => 'Rp',
    'tax_percent' => 0,        // e.g., 10 for 10%
    'service_percent' => 0,    // e.g., 5 for 5%

    // Admin PC (optional). If empty, use the "Select Admin IP" button on the panel.
    'admin_ip' => '192.168.88.10',
];

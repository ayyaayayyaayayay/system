<?php

require_once __DIR__ . '/db.php';

sendJson([
    'success' => false,
    'deprecated' => true,
    'error' => 'setup_db.php has been retired. Use database/datacode.txt and database/datauser.txt for schema and seed data.',
], 410);

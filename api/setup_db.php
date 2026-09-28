<?php

require_once __DIR__ . '/db.php';

sendJson([
    'success' => false,
    'deprecated' => true,
    'error' => 'setup_db.php has been retired. Use database/datacode.txt and database/datauser.txt for fresh installs, then run php api/migrate_schema.php --check or --apply for existing database migrations.',
], 410);

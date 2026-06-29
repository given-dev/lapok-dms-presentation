<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ccba.php';

require_roles(['admin', 'manager', 'executive']);

json_ok([
    'portal_url' => ccba_portal_url(),
    'integration_mode' => 'assisted_portal',
]);

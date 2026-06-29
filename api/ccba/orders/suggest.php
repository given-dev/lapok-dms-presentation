<?php
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/includes/ccba.php';

require_roles(['admin', 'manager']);

json_ok(['lines' => ccba_suggest_order_lines()]);

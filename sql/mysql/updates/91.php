<?php

ChangeColumnName('forpreprint', 'paymentmethods', 'tinyint(1)', 'NOT NULL', '0', 'usepreprintedstationery', $db);

UpdateDBNo(91, $db);

?>
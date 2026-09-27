<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| -------------------------------------------------------------------------
| PolitiClash secrets. Copy this file to secrets.php and fill it in.
| -------------------------------------------------------------------------
| Never committed (.gitignore) and never served: application/ refuses every
| request. database.php and config.php read it. An empty value falls back to
| the local default in the comment beside it.
*/
return array(
	'db_hostname'    => 'localhost',   // correct on nearly every cPanel host
	'db_username'    => '',   // empty: root (local XAMPP)
	'db_password'    => '',
	'db_database'    => '',   // empty: politiclash
	// 32 hex characters, fresh for every server:
	//   php -r "echo bin2hex(random_bytes(16)), PHP_EOL;"
	'encryption_key' => '',
);

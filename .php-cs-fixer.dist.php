<?php
declare( strict_types = 1 );
require __DIR__ . '/vendor/autoload.php';

$fixer_config = new JosephScott\PHPCSFixer_Config( __DIR__ );
return $fixer_config->get_config();

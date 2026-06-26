<?php
// secrets.php — NOT inside the web-served subdomain folder
// Holds sensitive credentials. Never expose this path via any URL.

define('SMTP_USER', 'askglobaladvisorydemo@gmail.com');
define('SMTP_PASS', 'sish nsvh imfn qzea');   // 16-char Gmail app password
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_SECURE', 'tls');
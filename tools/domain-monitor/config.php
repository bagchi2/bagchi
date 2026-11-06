<?php
/**
 * Domain Monitor configuration.
 * Keep this file server-side. The included .htaccess denies direct access.
 * For production, rotate the API key if it has been shared publicly.
 */
define('APP_NAME', 'Domain Monitor');
define('APP_BASE_URL', ''); // Optional: set e.g. https://example.com (no trailing slash)
define('APP_FROM_EMAIL', 'connect@atreyagroup.in'); // Hostinger mailbox used as the sender
define('APP_FROM_NAME', 'Domain Monitor');

// Hostinger Email SMTP settings. Use port 465 + SSL, or port 587 + STARTTLS.
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', 465);
define('SMTP_ENCRYPTION', 'ssl'); // 'ssl' for 465, 'tls' for 587
define('SMTP_USERNAME', 'connect@atreyagroup.in');
define('SMTP_PASSWORD', 'AtreYagroup@2648');
define('SMTP_TIMEOUT', 20);

define('TRYAPI_KEY', 'sk_live_34190d610eb609b07bd07db7dee173ae8c49b6212555a7cabd23294922fd5253');
define('TRYAPI_URL', 'https://tryapi.dev/api/v1/domain/availability');

define('STORAGE_PATH', __DIR__ . '/storage');
define('MAX_CHECKS_PER_REQUEST', 3);
define('STOP_ON_AVAILABLE', true);
define('DEFAULT_INTERVAL_MINUTES', 15);
define('MIN_INTERVAL_MINUTES', 15);
define('MAX_INTERVAL_MINUTES', 43200); // 30 days

define('RDAP_BOOTSTRAP_URL', 'https://data.iana.org/rdap/dns.json');
define('RDAP_BOOTSTRAP_CACHE_SECONDS', 2592000); // 30 days

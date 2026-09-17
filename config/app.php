<?php

/*
 * PDF Regenerator — application configuration.
 *
 * Every tunable value lives here. Do not scatter configuration through the code:
 * read it with config('key') instead.
 */

return [
    'app_name' => 'PDF Regenerator',
    'admin_email' => 'admin@example.com',

    // Uploads & PDF bomb guard (Part C §12)
    'max_upload_size' => 10 * 1024 * 1024,
    'max_pdf_pages' => 50,
    'max_extracted_text_bytes' => 5 * 1024 * 1024,
    'max_logo_size' => 2 * 1024 * 1024,

    // Retention for bin/cleanup-storage.php (Part D §15)
    'retention_uploads_days' => 30,
    'retention_generated_days' => 90,
    'retention_temp_days' => 7,          // progress jobs, stale sessions, rotated logs, drafts/
    'auto_cleanup' => true,              // run the cleanup by itself once a day (no cron job needed)

    'max_top_pages' => 15,               // cap for the Top Performing Pages list (plugin_updates is never capped)
    'pdf_font' => 'inter',               // registered from resources/fonts; fallback 'dejavusans'

    // Authentication & session (Part B §5, Part C §11)
    'session_timeout' => 1800,           // 30 minutes idle
    'login_max_attempts' => 5,
    'login_lockout_seconds' => 900,      // 15 minutes
    'login_max_attempts_per_ip' => 20,   // extra guard against username spraying from one IP

    // Dates. Timezone default in the spec is UTC; set to the owner's local zone.
    'timezone' => 'Asia/Dhaka',
    'date_format' => 'M d, Y h:i A',     // date + time (upload/generated timestamps)
    'display_date_format' => 'M d, Y',   // date-only values (report dates, backup dates)

    // Engines
    'pdftotext_path' => 'auto',          // 'auto' = look in the usual places; a full path; or null/'' = smalot only
    'mpdf_temp_path' => __DIR__ . '/../storage/tmp/mpdf',

    // Paths (all outside the public/ web root)
    'storage_path' => __DIR__ . '/../storage',
    'template_path' => __DIR__ . '/../stock-templates',
    'source_profile_path' => __DIR__ . '/../source-profiles',
    'font_path' => __DIR__ . '/../resources/fonts',
    'users_csv' => __DIR__ . '/users.csv',
];

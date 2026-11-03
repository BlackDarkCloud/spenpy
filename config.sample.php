<?php
/**
 * Spenpy shop settings.
 * 1) Copy this file and name the copy  config.php  (same folder).
 * 2) In cPanel > MySQL Databases: create a database, a user, and add the user to the database (All privileges).
 * 3) Fill in the details below.
 * 4) Open  https://YOUR-DOMAIN/mart-setup.php  once to create the tables and your admin account.
 *
 * config.php is private: the web server blocks direct access to it.
 */
return [
    'db_host'   => 'localhost',
    'db_name'   => 'cpanelname_spenpy',      // full database name from cPanel
    'db_user'   => 'cpanelname_spenpyuser',  // full database user name from cPanel
    'db_pass'   => 'PUT-THE-DATABASE-PASSWORD-HERE',

    // Table name prefix. Keep it: it stops clashes if the database is also used by WordPress.
    'prefix'    => 'spenpy_',

    // A secret word that only you know. You type it on mart-setup.php to create the admin account.
    'setup_key' => 'CHANGE-THIS-TO-A-LONG-SECRET',

    // Shown to customers
    'currency'  => 'GH₵',
    'whatsapp'  => '233500805719',   // Spencer's WhatsApp (country code, no +)
];

<?php
// Copy this file to config.secret.php and fill in real values.
// NEVER commit config.secret.php itself — see .gitignore.
return [

    // The Bearer key every reporting Fourge site's "Fleet Dashboard" plugin
    // setting must present to POST a status report. One shared key for the
    // whole fleet — simple to operate for a small, known set of sites. Make
    // it long and random; there is no rotation flow, so replacing it means
    // updating every site's saved key too.
    'report_key' => 'REPLACE-WITH-A-LONG-RANDOM-STRING',

    // bcrypt hash of the password used to VIEW this dashboard (not the same
    // key as above). Generate one with:
    //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), \"\n\";"
    'dashboard_password_hash' => 'REPLACE-WITH-A-BCRYPT-HASH',

];

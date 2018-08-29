<?php
//********************************************************************************************************************
// MYSQL DATABASE CONNECTION:
// Replace the values inside the single quotes below with the values for your MySQL configuration.
// If not using the default port 3306, then append a colon and port number to the hostname (e.g. $hostname = 'example.com:3307';).
$hostname       = getenv('DB_HOSTNAME');
$db             = getenv('DB_NAME');
$username       = getenv('DB_USERNAME');
$password       = getenv('DB_PASSWORD');
// You may optionally utilize a database connection over SSL/TLS for improved security. To do so, at minimum
// you must provide the path of the key file, the certificate file, and certificate authority file.
$db_ssl_key     = '';           // e.g., '/etc/mysql/ssl/client-key.pem'
$db_ssl_cert    = '';           // e.g., '/etc/mysql/ssl/client-cert.pem'
$db_ssl_ca      = '';           // e.g., '/etc/mysql/ssl/ca-cert.pem'
$db_ssl_capath  = NULL;
$db_ssl_cipher  = NULL;
// For greater security, you may instead want to place the database connection values in a separate file that is not
// accessible via the web. To do this, uncomment the line below and set it as the path to your database connection file
// located elsewhere on your web server. The file included should contain all the variables from above.
// include 'path_to_db_conn_file.php';
//********************************************************************************************************************
// SALT VARIABLE:
// Add a random value for the $salt variable below, preferably alpha-numeric with 8 characters or more. This value wll be
// used for data de-identification hashing for data exports. Do NOT change this value once it has been initially set.
$salt = getenv('SALT');
//********************************************************************************************************************
// DATA TRANSFER SERVICES (DTS):
// If using REDCap DTS, uncomment the lines below and provide the database connection values for connecting to
// the MySQL database containing the DTS tables (even if the same as the values above).
// $dtsHostname         = 'your_dts_host_name';
// $dtsDb                       = 'your_dts_db_name';
// $dtsUsername         = 'your_dts_db_username';
// $dtsPassword         = 'your_dts_db_password';
<?php

// Set up MySQL

$mysqlHost = $_ENV['MYSQL_HOST'];
$mysqlUsername = $_ENV['MYSQL_USER'];
$mysqlPassword = $_ENV['MYSQL_PASS'];
$mysqlDB = $_ENV['MYSQL_DB'];
$mysqlPort = $_ENV['MYSQL_PORT'];

// Create connection
$conn = mysqli_connect($mysqlHost, $mysqlUsername, $mysqlPassword, $mysqlDB, $mysqlPort);
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

else {
	echo "Seems to have connected OK!";
}


?>
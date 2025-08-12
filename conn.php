<?php
$servername = "localhost";   // or your DB server IP
$username = "root";          // change if different
$password = "";              // change if you set a password
$database = "elective4";     // your database name

// Create connection
$conn = new mysqli($servername, $username, $password, $database);

// Check connection
// if ($conn->connect_error) {
//     die("Connection failed: " . $conn->connect_error);
// }
// echo "Connected successfully to the database!";
?>

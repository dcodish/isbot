<?php



$conn = mysqli_connect('localhost','root','5400','bot');
mysqli_set_charset($conn, 'utf8mb4');

// Create connection
// Check connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}
?>
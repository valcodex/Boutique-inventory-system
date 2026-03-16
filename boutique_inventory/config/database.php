<?php
$host = "localhost";
$user = "root";
$password = "";
$db = "boutique_inventory";

$conn = new mysqli($host,$user,$password,$db);

if($conn->connect_error){
    die("Database connection failed: " . $conn->connect_error);
}
?>
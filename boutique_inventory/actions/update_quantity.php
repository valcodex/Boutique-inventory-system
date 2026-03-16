<?php
include "../config/database.php";

$id=$_POST['id'];
$quantity=$_POST['quantity'];

$conn->query("UPDATE products SET quantity='$quantity' WHERE id=$id");

header("Location:../index.php");
?>
<?php
include "../config/database.php";

$name=$_POST['name'];
$category=$_POST['category'];
$size=$_POST['size'];
$color=$_POST['color'];
$price=$_POST['price'];
$quantity=$_POST['quantity'];
$shelf=$_POST['shelf'];

$conn->query("INSERT INTO products(name,category,size,color,price,quantity,shelf)
VALUES('$name','$category','$size','$color','$price','$quantity','$shelf')");

header("Location:../index.php");
?>
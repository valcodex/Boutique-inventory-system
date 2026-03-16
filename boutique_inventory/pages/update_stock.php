<?php
include "../config/database.php";

$id=$_GET['id'];

$result=$conn->query("SELECT * FROM products WHERE id=$id");

$product=$result->fetch_assoc();
?>

<!DOCTYPE html>
<html>

<head>

<title>Update Stock</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="../assets/style.css">

</head>

<body>

<div class="container">

<h1>Update Stock</h1>

<form action="../actions/update_quantity.php" method="POST">

<input type="hidden" name="id" value="<?php echo $product['id']; ?>">

<label>Product</label>
<p><?php echo $product['name']; ?></p>

<label>Quantity</label>
<input name="quantity" value="<?php echo $product['quantity']; ?>">

<button type="submit">Update Stock</button>

</form>

</div>

</body>

</html>
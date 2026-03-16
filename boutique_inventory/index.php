<?php
session_start();

if(!isset($_SESSION['user'])){
    header("Location:login.php");
    exit();
}

include "config/database.php";
?>

<!DOCTYPE html>
<html>
   <div class="container">
<head>
<title>Boutique Inventory</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="assets/style.css">

</head>

<body>

<h1>Boutique Inventory</h1>

<p>Welcome, <?php echo $_SESSION['user']; ?> |
<a href="logout.php">LOGOUT</a></p>

<br>

<a href="pages/add_product.php" class="button">Add Product</a>

<br><br>

<table>

<tr>
<th>Name</th>
<th>Category</th>
<th>Size</th>
<th>Color</th>
<th>Price</th>
<th>Stock</th>
<th>Shelf</th>
<th>Status</th>
<th>Actions</th>
</tr>

<?php

$result = $conn->query("SELECT * FROM products ORDER BY name");

while($row = $result->fetch_assoc()){

$status = $row['quantity'] > 0 ? "In Stock" : "Out of Stock";

echo "
<tr>

<td>{$row['name']}</td>
<td>{$row['category']}</td>
<td>{$row['size']}</td>
<td>{$row['color']}</td>
<td>{$row['price']}</td>
<td>{$row['quantity']}</td>
<td>{$row['shelf']}</td>
<td>$status</td>

<td>

<a href='pages/update_stock.php?id={$row['id']}'>Update</a> |

<a href='actions/delete_product.php?id={$row['id']}'
onclick=\"return confirm('Delete this product?')\">
Delete
</a>

</td>

</tr>
";

}

?>

</table>

</body>
</div>
</html>
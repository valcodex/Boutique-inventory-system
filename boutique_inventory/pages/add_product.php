<!DOCTYPE html>
<html>

<head>

<title>Add Product</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="../assets/style.css">

</head>

<body>

<div class="container">

<h1>Add Product</h1>

<form action="../actions/save_product.php" method="POST">

<label>Product Name</label>
<input name="name" required>

<label>Category</label>
<input name="category">

<label>Size</label>
<input name="size">

<label>Color</label>
<input name="color">

<label>Price</label>
<input type="number" name="price" step="0.01">

<label>Quantity</label>
<input type="number" name="quantity">

<label>Shelf</label>
<input name="shelf">

<button type="submit">Save Product</button>

</form>

</div>

</body>

</html>
<?php
session_start();
include "config/database.php";

if(isset($_SESSION['user'])){
    header("Location: index.php");
    exit();
}

$error = "";

if($_SERVER["REQUEST_METHOD"] == "POST"){

    $username = $_POST['username'];
    $password = $_POST['password'];

    $result = $conn->query("SELECT * FROM users WHERE username='$username' AND password='$password'");

    if($result->num_rows > 0){

        $_SESSION['user'] = $username;

        header("Location: index.php");
        exit();

    } else {

        $error = "Invalid username or password";

    }
}
?>

<!DOCTYPE html>
<html>

<head>

<title>Boutique Inventory Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link rel="stylesheet" href="assets/style.css">

</head>

<body>

<div class="login-wrapper">

<div class="login-box">

<h2>Boutique Inventory</h2>

<?php
if($error != ""){
    echo "<p style='color:red; text-align:center; margin-bottom:10px;'>$error</p>";
}
?>

<form method="POST">

<input type="text" name="username" placeholder="Username" required>

<input type="password" name="password" placeholder="Password" required>

<button type="submit">Login</button>

</form>

</div>

</div>

</body>

</html>
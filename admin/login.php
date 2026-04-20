<?php
session_start();
try {
    require_once __DIR__ . '/../bootstrap/app.php';
} catch (Exception $e) {
    die("<h1>Configuration Error</h1><p>" . $e->getMessage() . "</p><p>Check if MySQL is running and .env has correct DB credentials.</p>");
} catch (Error $e) {
    die("<h1>Fatal Error</h1><p>" . $e->getMessage() . "</p>");
}

if (isset($_POST['submit'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $valid_username = env_value('ADMIN_USERNAME', 'admin');
    $valid_password = env_value('ADMIN_PASSWORD', 'change-me');

    if ($username === $valid_username && $password === $valid_password) {
        $_SESSION['loggedin'] = true;
        header('Location: index.php');
        exit();
    } else {
        $error = "Invalid username or password";
    }
}
?>

<!DOCTYPE html>
<html lang="he" dir="rtl">
<head>
    <meta charset="UTF-8">
    <title>Login</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/css/bootstrap.min.css">
</head>
<body>
<div class="container">
    <h2>Login</h2>
    <?php if (isset($error)) { echo "<div class='alert alert-danger'>$error</div>"; } ?>
    <form action="login.php" method="post">
        <div class="form-group">
            <label for="username">Username:</label>
            <input type="text" class="form-control" id="username" name="username" required>
        </div>
        <div class="form-group">
            <label for="password">Password:</label>
            <input type="password" class="form-control" id="password" name="password" required>
        </div>
        <button type="submit" name="submit" class="btn btn-primary">Login</button>
    </form>
</div>
</body>
</html>

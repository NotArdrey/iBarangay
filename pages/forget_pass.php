
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forget Password</title>
    <link rel="stylesheet" href="../styles/forget_pass.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

</head>
<body>
    <div class="forget-password-container">
        <h2>Forget Password</h2>
        <form action="../functions/forget_pass.php" method="post">
            <input type="email" name="email" placeholder="Enter your email" required>
            <input type="submit" value="Reset Password">
        </form>

        <div class="message">
            <?php
                if (isset($_GET['message'])) {
                    echo htmlspecialchars($_GET['message']);
                }
            ?>
        </div>
    </div>
</body>
</html>
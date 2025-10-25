<?php
session_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión — Data Synergy</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/styles.css">
</head>
<body class="login-body">
    <main class="login-wrapper two-col">
        <form class="login-card" action="/login_process.php" method="post" aria-label="Formulario de inicio de sesión">
            <img src="/siomalogo.png" alt="Logo" class="login-logo">
            <h1 class="login-title">Acceso</h1>
            <?php if (isset($_SESSION['login_error'])): ?>
                <div class="error-message" style="background-color: #fee; color: #c33; padding: 10px; border-radius: 4px; margin-bottom: 15px; border: 1px solid #fcc;">
                    <?php echo htmlspecialchars($_SESSION['login_error']); ?>
                </div>
                <?php unset($_SESSION['login_error']); ?>
            <?php endif; ?>
            <label class="login-field">
                <span>Usuario</span>
                <input type="text" name="username" required autocomplete="username">
            </label>
            <label class="login-field">
                <span>Contraseña</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            <button class="btn btn-primary login-btn" type="submit">Ingresar</button>
            <p class="login-footnote">Al continuar aceptas las políticas de uso.</p>
        </form>
        <aside class="login-side">
            <img src="/imagensioma.png" alt="Ilustración" class="login-side-image">
        </aside>
    </main>
</body>
</html>



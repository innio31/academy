<?php
// offline.php - Shown when user is offline
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>You're Offline</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: linear-gradient(135deg, #1B2A4A 0%, #2E7D32 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .container {
            background: white;
            padding: 40px;
            border-radius: 28px;
            text-align: center;
            max-width: 400px;
            width: 100%;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }

        .offline-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 1.8rem;
            color: #1B2A4A;
            margin-bottom: 10px;
        }

        p {
            color: #666;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        button {
            background: #1B2A4A;
            color: white;
            border: none;
            padding: 14px 28px;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
        }

        button:hover {
            background: #2E7D32;
        }

        .info {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #eee;
            font-size: 0.8rem;
            color: #999;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="offline-icon">📡</div>
        <h1>You're Offline</h1>
        <p>Your internet connection is lost. Don't worry!</p>
        <p>You can still access previously viewed pages.</p>
        <button onclick="location.reload()">Try Again</button>
        <div class="info">
            <strong>Great Optimist School</strong><br>
            Once connection is restored, click "Try Again"
        </div>
    </div>

    <script>
        // Automatically reload when connection returns
        window.addEventListener('online', () => {
            location.reload();
        });
    </script>
</body>

</html>
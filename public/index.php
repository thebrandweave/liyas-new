<?php
/**
 * Reward Code Redeem Page
 * Users manually enter the unique reward code from their bottle sticker
 */

require_once __DIR__ . '/../config/config.php';

$message = '';
$message_type = '';
$reward_code = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reward_code'])) {
    $reward_code = trim($_POST['reward_code']);
    
    if (empty($reward_code)) {
        $message = 'Please enter a reward code';
        $message_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            // Check if reward code exists and lock it
            $stmt = $pdo->prepare("SELECT id, reward_code, is_used, used_at FROM codes WHERE reward_code = ? FOR UPDATE");
            $stmt->execute([$reward_code]);
            $code_data = $stmt->fetch();
            
            if (!$code_data) {
                // Code doesn't exist
                $message = 'Invalid code';
                $message_type = 'error';
                $pdo->rollBack();
            } elseif ($code_data['is_used'] == 1) {
                // Code already used
                $message = 'Already redeemed';
                $message_type = 'warning';
                $pdo->rollBack();
            } else {
                // Code is valid and unused - mark as used
                $updateStmt = $pdo->prepare("UPDATE codes SET is_used = 1, used_at = NOW() WHERE id = ? AND is_used = 0");
                $updateStmt->execute([$code_data['id']]);
                
                if ($updateStmt->rowCount() > 0) {
                    $pdo->commit();
                    $message = 'Success';
                    $message_type = 'success';
                } else {
                    // Race condition - code was used between check and update
                    $message = 'Already redeemed';
                    $message_type = 'warning';
                    $pdo->rollBack();
                }
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $message = 'An error occurred. Please try again.';
            $message_type = 'error';
            error_log("Reward code redemption error: " . $e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redeem Reward Code - LIYAS Mineral Water</title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/jpeg" href="../assets/images/logo/logo-bg.jpg">
    <link rel="shortcut icon" type="image/jpeg" href="../assets/images/logo/logo-bg.jpg">
    <link rel="apple-touch-icon" href="../assets/images/logo/logo-bg.jpg">
    <link rel="icon" type="image/jpeg" sizes="32x32" href="../assets/images/logo/logo-bg.jpg">
    <link rel="icon" type="image/jpeg" sizes="16x16" href="../assets/images/logo/logo-bg.jpg">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .logo {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
        }

        h1 {
            color: #333;
            margin-bottom: 10px;
            font-size: 28px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }

        input[type="text"] {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 16px;
            font-family: inherit;
            transition: border-color 0.3s;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s;
            margin-top: 10px;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .message {
            margin-top: 20px;
            padding: 15px;
            border-radius: 10px;
            font-size: 14px;
            text-align: center;
        }

        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .message.warning {
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffeaa7;
        }

        .info-text {
            margin-top: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            font-size: 13px;
            color: #666;
            text-align: left;
        }

        .info-text strong {
            color: #333;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">💧</div>
        <h1>🎁 Redeem Your Reward</h1>
        <p class="subtitle">LIYAS Mineral Water - Enter your unique reward code</p>

        <?php if ($message): ?>
            <div class="message <?= $message_type ?>">
                <?php if ($message_type === 'success'): ?>
                    <h3 style="margin-bottom: 10px;">✅ Reward Redeemed Successfully!</h3>
                    <p>Thank you for choosing LIYAS Mineral Water!</p>
                <?php elseif ($message_type === 'warning'): ?>
                    <h3 style="margin-bottom: 10px;">⚠️ Already Redeemed</h3>
                    <p>This reward code has already been used. Each code can only be redeemed once.</p>
                <?php else: ?>
                    <h3 style="margin-bottom: 10px;">❌ Invalid Code</h3>
                    <p><?= htmlspecialchars($message) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($message_type !== 'success'): ?>
            <form method="POST" action="" id="redeemForm">
                <div class="form-group">
                    <label for="reward_code">
                        <i class="fas fa-ticket-alt"></i> Enter Reward Code
                    </label>
                    <input 
                        type="text" 
                        name="reward_code" 
                        id="reward_code" 
                        placeholder="e.g., Liyas-SFA123Fcg"
                        autocomplete="off"
                        required
                        autofocus
                        value="<?= htmlspecialchars($reward_code) ?>"
                        maxlength="50"
                    >
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    <i class="fas fa-gift"></i> Redeem Code
                </button>
            </form>

            <div class="info-text">
                <strong>How to redeem:</strong><br>
                1. Scan the QR code on your bottle (or visit this page)<br>
                2. Find the unique reward code on the back of the sticker<br>
                3. Enter the code above and click "Redeem Code"
            </div>
        <?php endif; ?>

        <div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #e0e0e0; color: #999; font-size: 12px;">
            <p>One-time use only • Each code can be redeemed once</p>
        </div>
    </div>

    <script>
        // Auto-uppercase input
        document.getElementById('reward_code')?.addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });

        // Handle form submission
        document.getElementById('redeemForm')?.addEventListener('submit', function(e) {
            const btn = document.getElementById('submitBtn');
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            }
        });
    </script>
</body>
</html>

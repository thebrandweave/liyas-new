<?php
/**
 * Generate Reward Codes via Web Interface
 * Admin can generate reward codes through the browser
 */

require_once '../../config/config.php';
require_once '../includes/auth_check.php';
require_once '../includes/activity_logger.php';

$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Admin');
$current_page = "qr-rewards";
$page_title = "Generate Reward Codes";

$message = '';
$message_type = '';
$generated_count = 0;
$sample_codes = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $count = isset($_POST['count']) ? (int)$_POST['count'] : 100;
    $prefix = isset($_POST['prefix']) ? trim($_POST['prefix']) : 'Liyas-';
    $prefix = rtrim($prefix, '-') . '-';
    
    // Validate
    if ($count < 1 || $count > 10000) {
        $message = 'Count must be between 1 and 10,000';
        $message_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            $insertStmt = $pdo->prepare("INSERT INTO codes (reward_code) VALUES (?)");
            $generated = 0;
            $errors = 0;
            
            for ($i = 0; $i < $count; $i++) {
                $code_length = random_int(6, 10);
                $code = generateUniqueRewardCode($pdo, $prefix, $code_length);
                
                try {
                    $insertStmt->execute([$code]);
                    $generated++;
                    
                    // Store first 5 for display
                    if ($generated <= 5) {
                        $sample_codes[] = $code;
                    }
                } catch (PDOException $e) {
                    if ($e->getCode() != 23000) { // Not duplicate
                        $errors++;
                    }
                }
            }
            
            $pdo->commit();
            $generated_count = $generated;
            
            // Log activity
            quickLog($pdo, 'generate', 'qr_reward', null, "Generated {$generated_count} reward code(s) with prefix: {$prefix}");
            
            $message = "Successfully generated $generated reward codes!";
            $message_type = 'success';
            
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

/**
 * Generate unique random reward code
 */
function generateUniqueRewardCode($pdo, $prefix, $length) {
    $max_attempts = 100;
    $attempts = 0;
    
    do {
        $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $suffix = '';
        for ($i = 0; $i < $length; $i++) {
            $suffix .= $characters[random_int(0, strlen($characters) - 1)];
        }
        
        $code = $prefix . $suffix;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM codes WHERE reward_code = ?");
        $stmt->execute([$code]);
        $exists = $stmt->fetchColumn() > 0;
        
        $attempts++;
    } while ($exists && $attempts < $max_attempts);
    
    if ($exists) {
        throw new Exception("Could not generate unique code after $max_attempts attempts");
    }
    
    return $code;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	
	<!-- Favicon -->
	<link rel="icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="shortcut icon" type="image/jpeg" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="apple-touch-icon" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="icon" type="image/jpeg" sizes="32x32" href="../../assets/images/logo/logo-bg.jpg">
	<link rel="icon" type="image/jpeg" sizes="16x16" href="../../assets/images/logo/logo-bg.jpg">
	
	<!-- Google Font: Poppins -->
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
	<link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
	<link rel="stylesheet" href="../assets/css/prody-admin.css">
	<title>Generate Reward Codes - Liyas Admin</title>

	<style>
		/* Centered modal with blurred background for generate codes form */
		.modal-overlay {
			position: fixed;
			inset: 0;
			background: rgba(15, 23, 42, 0.35);
			backdrop-filter: blur(4px);
			display: flex;
			align-items: center;
			justify-content: center;
			z-index: 999;
			padding: 1.5rem;
		}

		.modal-card {
			max-width: 720px;
			width: 100%;
		}

		@media (max-width: 768px) {
			.modal-overlay {
				align-items: flex-start;
				padding-top: 4rem;
			}
		}
	</style>
</head>
<body>
	<div class="container">
		<?php include '../includes/sidebar.php'; ?>
		
		<div class="main-content">
			<div class="header">
				<div class="breadcrumb">
					<i class='bx bx-home'></i>
					<span>QR Rewards</span>
					<span>/</span>
					<span>Generate</span>
				</div>
				<div class="header-actions">
					<a href="index.php" class="header-btn">
						<i class='bx bx-arrow-back'></i>
						<span>Back</span>
					</a>
				</div>
			</div>
			
			<div class="content-area">
				<div class="modal-overlay">
					<div class="modal-card">
						<?php if ($message): ?>
							<div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>" style="margin-bottom: 1rem;">
								<?= htmlspecialchars($message) ?>
							</div>
						<?php endif; ?>

						<?php if ($generated_count > 0 && !empty($sample_codes)): ?>
							<div class="form-card" style="margin-bottom: 1.5rem; background: var(--green-light); border-color: var(--green);">
								<h3 style="margin-bottom: 1rem;">Sample codes generated:</h3>
								<?php foreach ($sample_codes as $sample): ?>
									<code style="display: block; margin: 0.5rem 0; padding: 0.5rem; background: white; border-radius: 4px; font-family: monospace; color: var(--text-primary);"><?= htmlspecialchars($sample) ?></code>
								<?php endforeach; ?>
								<p style="margin-top: 1rem;">
									<a href="index.php" style="color: var(--blue);">View all codes →</a>
								</p>
							</div>
						<?php endif; ?>

						<div class="form-card">
							<div class="form-header">
								<h2>Generate Reward Codes</h2>
							</div>

							<form method="POST" action="" class="form-modern">
								<div class="form-group">
									<label for="count">Number of Codes to Generate <span style="color: var(--red);">*</span></label>
									<input 
										type="text" 
										name="count" 
										id="count" 
										class="form-input"
										value="100" 
										min="1" 
										max="10000" 
										required
									>
									<small style="color: var(--text-muted); font-size: 12px; margin-top: 0.25rem; display: block;">Between 1 and 10,000 codes</small>
								</div>

								<div class="form-group">
									<label for="prefix">Code Prefix <span style="color: var(--red);">*</span></label>
									<input 
										type="text" 
										name="prefix" 
										id="prefix" 
										class="form-input"
										value="Liyas" 
										placeholder="Liyas"
										maxlength="20"
										required
									>
									<small style="color: var(--text-muted); font-size: 12px; margin-top: 0.25rem; display: block;">Example: "Liyas" will create codes like "Liyas-ABC123XYZ"</small>
								</div>

								<div class="form-actions">
									<button type="submit" name="generate" class="btn btn-primary">
										<i class='bx bx-plus-circle'></i> Generate Codes
									</button>
									<a href="index.php" class="btn btn-secondary">
										<i class='bx bx-x'></i> Cancel
									</a>
								</div>
							</form>

							<div style="padding: 1.5rem; margin-top: 1.5rem; background: var(--bg-main); border-radius: 8px; border: 1px solid var(--border-light);">
								<h4 style="margin-bottom: 0.75rem; font-size: 16px;">💡 How it works:</h4>
								<ul style="color: var(--text-secondary); font-size: 13px; line-height: 1.8; padding-left: 1.5rem;">
									<li>All bottles use ONE common QR code that redirects to the redeem page</li>
									<li>Each bottle has a UNIQUE reward code printed on the sticker</li>
									<li>Users manually enter the reward code on the redeem page</li>
									<li>Codes are 6-10 random characters after the prefix</li>
									<li>Example format: <code style="background: white; padding: 0.25rem 0.5rem; border-radius: 4px; font-family: monospace;">Liyas-SFA123Fcg</code></li>
								</ul>
							</div>
						</div>
					</div>
				</div>
		</div>
	</div>
</body>
</html>

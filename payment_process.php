<?php
// payment_process.php
// Process payment for profile reveal using Midtrans

require_once 'config.php';
require_once 'payment_gateway.php';

// Make sure user is logged in
requireLogin();

// Check if order ID is provided
if (!isset($_GET['order_id']) || !isset($_GET['snap_token'])) {
    redirect('dashboard.php?page=chat');
    exit();
}

$orderId = $_GET['order_id'];
$snapToken = $_GET['snap_token'];
$userId = $_SESSION['user_id'];

// Initialize payment gateway with your Midtrans keys
$clientKey = 'YOUR_CLIENT_KEY';
$serverKey = 'YOUR_SERVER_KEY';
$isProduction = false; // Set to true for production

$paymentGateway = new PaymentGateway($clientKey, $serverKey, $isProduction);

// Check payment status
$payment = $paymentGateway->checkPaymentStatus($orderId);

// Make sure the payment exists and belongs to the current user
if ($payment['status'] === 'not_found' || $payment['user_id'] != $userId) {
    redirect('dashboard.php?page=chat&error=invalid_payment');
    exit();
}

// Get payment details
$targetUserId = $payment['target_user_id'];

// Get target user information
$sql = "SELECT u.name, p.profile_pic, p.bio FROM users u 
        LEFT JOIN profiles p ON u.id = p.user_id 
        WHERE u.id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $targetUserId);
$stmt->execute();
$targetUser = $stmt->get_result()->fetch_assoc();

// If payment is already completed, redirect to view profile
if ($payment['status'] === 'completed') {
    redirect('view_profile.php?id=' . $targetUserId . '&from_payment=1');
    exit();
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pembayaran Lihat Profil - Cupid</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <script type="text/javascript" src="https://app.sandbox.midtrans.com/snap/snap.js" data-client-key="<?php echo $clientKey; ?>"></script>
    <style>
        /* Your existing CSS styles */
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="container">
            <div class="header-content">
                <a href="index.php" class="logo">
                    <i class="fas fa-heart"></i> Cupid
                </a>
                <nav>
                    <ul>
                        <li><a href="dashboard.php">Dashboard</a></li>
                        <li>
                            <a href="dashboard.php?page=chat" class="btn btn-outline">Kembali ke Chat</a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
    </header>

    <!-- Payment Section -->
    <section class="payment-container">
        <div class="container">
            <div class="card">
                <div class="card-header">
                    <h1>Lihat Profil Lengkap</h1>
                </div>
                
                <div class="user-preview">
                    <div class="user-avatar">
                        <img src="<?php echo !empty($targetUser['profile_pic']) ? htmlspecialchars($targetUser['profile_pic']) : '/api/placeholder/60/60'; ?>" alt="User Avatar">
                    </div>
                    <div class="user-info">
                        <h3><?php echo htmlspecialchars($targetUser['name']); ?></h3>
                        <p>Lihat profil lengkap untuk mengetahui info lebih detail</p>
                    </div>
                </div>
                
                <div class="benefits">
                    <h3>Dengan melihat profil, Anda akan mendapatkan:</h3>
                    <ul>
                        <li><i class="fas fa-check-circle"></i> Info lengkap tentang minat dan hobi</li>
                        <li><i class="fas fa-check-circle"></i> Jurusan dan fakultas</li>
                        <li><i class="fas fa-check-circle"></i> Bio lengkap</li>
                        <li><i class="fas fa-check-circle"></i> Foto profil yang jelas</li>
                        <li><i class="fas fa-check-circle"></i> Informasi kecocokan</li>
                    </ul>
                </div>
                
                <div class="payment-details">
                    <div class="row">
                        <div class="label">Order ID:</div>
                        <div class="value"><?php echo htmlspecialchars($orderId); ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Lihat Profil:</div>
                        <div class="value"><?php echo htmlspecialchars($targetUser['name']); ?></div>
                    </div>
                    <div class="row">
                        <div class="label">Harga:</div>
                        <div class="value price-highlight">Rp <?php echo number_format($payment['amount'], 0, ',', '.'); ?></div>
                    </div>
                </div>
                
                <button id="pay-button" class="btn" style="width: 100%;">Bayar Sekarang</button>
                
                <p class="disclaimer">
                    Dengan menekan tombol "Bayar Sekarang", Anda setuju dengan syarat dan ketentuan Cupid mengenai pembayaran dan penggunaan fitur premium.
                </p>
            </div>
        </div>
    </section>

    <script>
        // Trigger Snap popup when pay button is clicked
        document.getElementById('pay-button').onclick = function() {
            // Open Snap popup with given token
            snap.pay('<?php echo $snapToken; ?>', {
                onSuccess: function(result) {
                    // Payment success, redirect to profile page
                    window.location.href = 'view_profile.php?id=<?php echo $targetUserId; ?>&from_payment=1&new=1';
                },
                onPending: function(result) {
                    // Payment pending, show message
                    alert('Pembayaran sedang diproses. Silakan cek status pembayaran Anda.');
                },
                onError: function(result) {
                    // Payment error, show message
                    alert('Pembayaran gagal: ' + result.status_message);
                },
                onClose: function() {
                    // User closed the popup without finishing the payment
                    alert('Anda belum menyelesaikan pembayaran.');
                }
            });
        };
    </script>
</body>
</html>
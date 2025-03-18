<?php
// payment_gateway.php
// Midtrans payment gateway integration for profile reveal

require_once 'lib/midtrans-php/Midtrans.php';

use Midtrans\Config;
use Midtrans\Snap;
use Midtrans\Notification;

class PaymentGateway {
    private $clientKey;
    private $serverKey;
    private $isProduction;
    
    public function __construct($clientKey, $serverKey, $isProduction = false) {
        $this->clientKey = $clientKey;
        $this->serverKey = $serverKey;
        $this->isProduction = $isProduction;
        
        // Configure Midtrans
        Config::$serverKey = $serverKey;
        Config::$clientKey = $clientKey;
        Config::$isProduction = $isProduction;
        Config::$isSanitized = true;
        Config::$is3ds = true;
    }
    
    /**
     * Create a payment request for profile reveal
     * 
     * @param int $userId User requesting the reveal
     * @param int $targetUserId Target user whose profile is being revealed
     * @param int $amount Payment amount (in IDR)
     * @return array Payment details including redirect URL
     */
    public function createProfileRevealPayment($userId, $targetUserId, $amount = 5000) {
        global $conn;
        
        try {
            // Generate unique order ID
            $orderId = 'REVEAL-' . time() . '-' . $userId . '-' . $targetUserId;
            
            // Get user data for transaction
            $userSql = "SELECT name, email FROM users WHERE id = ?";
            $userStmt = $conn->prepare($userSql);
            $userStmt->bind_param("i", $userId);
            $userStmt->execute();
            $userResult = $userStmt->get_result();
            
            if ($userResult->num_rows === 0) {
                throw new Exception("User not found");
            }
            
            $userData = $userResult->fetch_assoc();
            
            // Get target user data
            $targetSql = "SELECT name FROM users WHERE id = ?";
            $targetStmt = $conn->prepare($targetSql);
            $targetStmt->bind_param("i", $targetUserId);
            $targetStmt->execute();
            $targetResult = $targetStmt->get_result();
            
            if ($targetResult->num_rows === 0) {
                throw new Exception("Target user not found");
            }
            
            $targetData = $targetResult->fetch_assoc();
            
            // Store payment request in database
            $sql = "INSERT INTO profile_reveal_payments 
                    (order_id, user_id, target_user_id, amount, status, created_at) 
                    VALUES (?, ?, ?, ?, 'pending', NOW())";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("siid", $orderId, $userId, $targetUserId, $amount);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to store payment request: " . $conn->error);
            }
            
            // Set up transaction parameters for Midtrans
            $transaction_details = array(
                'order_id' => $orderId,
                'gross_amount' => (int)$amount,
            );
            
            // Customer details
            $customer_details = array(
                'first_name' => $userData['name'],
                'email' => $userData['email'],
            );
            
            // Item details
            $item_details = array(
                array(
                    'id' => 'profile-reveal',
                    'price' => (int)$amount,
                    'quantity' => 1,
                    'name' => 'Lihat Profil ' . $targetData['name'],
                    'category' => 'Digital Service',
                    'merchant_name' => 'Cupid App',
                ),
            );
            
            // Transaction data to be sent to Midtrans
            $transaction_data = array(
                'transaction_details' => $transaction_details,
                'customer_details' => $customer_details,
                'item_details' => $item_details,
            );
            
            // Create Snap payment page URL
            $snapToken = Snap::getSnapToken($transaction_data);
            $redirectUrl = 'payment_process.php?order_id=' . $orderId . '&snap_token=' . $snapToken;
            
            return [
                'order_id' => $orderId,
                'amount' => $amount,
                'payment_url' => $redirectUrl,
                'snap_token' => $snapToken,
                'status' => 'pending'
            ];
        } catch (\Exception $e) {
            // Log error and return error state
            error_log('Midtrans Payment Creation Error: ' . $e->getMessage());
            return [
                'order_id' => isset($orderId) ? $orderId : 'ERROR',
                'amount' => $amount,
                'error' => $e->getMessage(),
                'status' => 'failed'
            ];
        }
    }
    
    /**
     * Check payment status
     * 
     * @param string $orderId The order ID to check
     * @return array Payment status details
     */
    public function checkPaymentStatus($orderId) {
        global $conn;
        
        try {
            // Validate input
            if (empty($orderId)) {
                throw new Exception("Order ID cannot be empty");
            }
            
            // Get payment status from database
            $sql = "SELECT 
                    p.*, 
                    u1.name as user_name, 
                    u2.name as target_name 
                    FROM profile_reveal_payments p
                    JOIN users u1 ON p.user_id = u1.id
                    JOIN users u2 ON p.target_user_id = u2.id
                    WHERE p.order_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("s", $orderId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                return ['status' => 'not_found'];
            }
            
            return $result->fetch_assoc();
        } catch (\Exception $e) {
            error_log('Payment Status Check Error: ' . $e->getMessage());
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Complete a payment based on Midtrans notification
     * 
     * @param object $notification Notification data from Midtrans
     * @return bool Success status
     */
    public function processPaymentNotification($notification) {
        global $conn;
        
        try {
            // Validate notification object
            if (!is_object($notification)) {
                throw new Exception("Invalid notification: Not an object");
            }
            
            if (!isset($notification->order_id) || !isset($notification->transaction_status)) {
                throw new Exception("Invalid notification: Missing required fields");
            }
            
            $orderId = $notification->order_id;
            $transactionStatus = $notification->transaction_status;
            $fraudStatus = isset($notification->fraud_status) ? $notification->fraud_status : null;
            
            // Log notification for debugging
            error_log('Payment Notification Received: Order ID: ' . $orderId . 
                     ', Transaction Status: ' . $transactionStatus . 
                     ', Fraud Status: ' . $fraudStatus);
            
            $paymentStatus = 'pending';
            
            if ($transactionStatus == 'capture') {
                if ($fraudStatus == 'challenge') {
                    $paymentStatus = 'challenge';
                } else if ($fraudStatus == 'accept') {
                    $paymentStatus = 'completed';
                }
            } else if ($transactionStatus == 'settlement') {
                $paymentStatus = 'completed';
            } else if ($transactionStatus == 'cancel' || $transactionStatus == 'deny' || $transactionStatus == 'expire') {
                $paymentStatus = 'failed';
            } else if ($transactionStatus == 'pending') {
                $paymentStatus = 'pending';
            }
            
            // Update payment status
            $sql = "UPDATE profile_reveal_payments SET status = ?, paid_at = NOW() WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Database preparation error: " . $conn->error);
            }
            
            $stmt->bind_param("ss", $paymentStatus, $orderId);
            $result = $stmt->execute();
            
            if (!$result) {
                throw new Exception("Failed to update payment status: " . $stmt->error);
            }
            
            // If payment is successful, update user permissions for profile viewing
            if ($paymentStatus === 'completed') {
                $this->grantProfileViewPermission($orderId);
            }
            
            return true;
        } catch (\Exception $e) {
            error_log('Payment Notification Processing Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Grant permission to view a profile after successful payment
     * 
     * @param string $orderId Order ID of the completed payment
     * @return bool Success status
     */
    private function grantProfileViewPermission($orderId) {
        global $conn;
        
        try {
            // Get payment details
            $sql = "SELECT user_id, target_user_id FROM profile_reveal_payments WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Database preparation error: " . $conn->error);
            }
            
            $stmt->bind_param("s", $orderId);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 0) {
                throw new Exception("Payment not found for order ID: " . $orderId);
            }
            
            $payment = $result->fetch_assoc();
            
            // Grant permission to view the profile
            $sql = "INSERT INTO profile_view_permissions (user_id, target_user_id, created_at) 
                    VALUES (?, ?, NOW()) 
                    ON DUPLICATE KEY UPDATE created_at = NOW()";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Database preparation error: " . $conn->error);
            }
            
            $stmt->bind_param("ii", $payment['user_id'], $payment['target_user_id']);
            $result = $stmt->execute();
            
            if (!$result) {
                throw new Exception("Failed to grant profile view permission: " . $stmt->error);
            }
            
            error_log('Profile view permission granted for Order ID: ' . $orderId . 
                     ', User ID: ' . $payment['user_id'] . 
                     ', Target User ID: ' . $payment['target_user_id']);
            
            return true;
        } catch (\Exception $e) {
            error_log('Grant Profile Permission Error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get the Midtrans Client Key
     * 
     * @return string Midtrans Client Key
     */
    public function getClientKey() {
        return $this->clientKey;
    }
    
    /**
     * Manually complete a payment (for admin use or testing)
     * 
     * @param string $orderId Order ID to complete
     * @return bool Success status
     */
    public function manualCompletePayment($orderId) {
        global $conn;
        
        try {
            // Update payment status
            $paymentStatus = 'completed';
            $sql = "UPDATE profile_reveal_payments SET status = ?, paid_at = NOW() WHERE order_id = ?";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                throw new Exception("Database preparation error: " . $conn->error);
            }
            
            $stmt->bind_param("ss", $paymentStatus, $orderId);
            $result = $stmt->execute();
            
            if (!$result) {
                throw new Exception("Failed to update payment status: " . $stmt->error);
            }
            
            // Grant permission to view profile
            $this->grantProfileViewPermission($orderId);
            
            return true;
        } catch (\Exception $e) {
            error_log('Manual Payment Completion Error: ' . $e->getMessage());
            return false;
        }
    }
}
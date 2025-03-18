<?php
// notification_handler.php
// Handle payment notifications from Midtrans

require_once 'config.php';
require_once 'payment_gateway.php';

// Initialize Midtrans config
$clientKey = 'YOUR_CLIENT_KEY';
$serverKey = 'YOUR_SERVER_KEY';
$isProduction = false; // Set to true for production

// Initialize payment gateway
$paymentGateway = new PaymentGateway($clientKey, $serverKey, $isProduction);

// Get notification
$notificationBody = file_get_contents('php://input');
$notification = json_decode($notificationBody);

// Process notification
$result = $paymentGateway->processPaymentNotification($notification);

// Return response to Midtrans
header('Content-Type: application/json');
if ($result) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error']);
}
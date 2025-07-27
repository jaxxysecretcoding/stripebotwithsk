<?php
/*
 * Stripe Card Checker Telegram Bot
 * 
 * Bot made by j1xxy
 * GitHub: https://github.com/j1xxy
 * 
 * Features:
 * - Card authorization and charging via Stripe API
 * - Invoice creation and payment processing
 * - Payment link generation
 * - Direct payment intents
 * - Multi-user support with individual Stripe keys
 * 
 * Currency: AUD (Australian Dollars)
 * Framework: Pure PHP (no dependencies)
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Configuration
define('BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE'); // Replace with your bot token
define('DATABASE_FILE', 'users.json');

// Helper function to send requests to Telegram
function sendTelegramRequest($method, $data = []) {
    $url = "https://api.telegram.org/bot" . BOT_TOKEN . "/" . $method;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// Helper function to send message
function sendMessage($chat_id, $text, $parse_mode = 'HTML') {
    return sendTelegramRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => $parse_mode
    ]);
}

// Database functions
function loadUserData() {
    if (file_exists(DATABASE_FILE)) {
        $data = file_get_contents(DATABASE_FILE);
        return json_decode($data, true) ?: [];
    }
    return [];
}

function saveUserData($data) {
    file_put_contents(DATABASE_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function getUserStripeKey($user_id) {
    $data = loadUserData();
    return isset($data[$user_id]['stripe_key']) ? $data[$user_id]['stripe_key'] : null;
}

function setUserStripeKey($user_id, $stripe_key) {
    $data = loadUserData();
    $data[$user_id]['stripe_key'] = $stripe_key;
    saveUserData($data);
}

// Stripe API functions
function makeStripeRequest($endpoint, $data, $stripe_key) {
    $url = "https://api.stripe.com/v1/" . $endpoint;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripe_key,
        'Content-Type: application/x-www-form-urlencoded'
    ]);
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return [
        'response' => json_decode($response, true),
        'http_code' => $http_code
    ];
}

function checkStripeKey($stripe_key) {
    $result = makeStripeRequest('balance', [], $stripe_key);
    return $result['http_code'] === 200;
}

function createPaymentMethod($card_data) {
    $parts = explode('|', $card_data);
    if (count($parts) < 4) {
        return null;
    }
    
    list($number, $month, $year, $cvc) = $parts;
    
    // Try Sources API first (more compatible)
    return [
        'type' => 'card',
        'card' => [
            'number' => $number,
            'exp_month' => $month,
            'exp_year' => $year,
            'cvc' => $cvc
        ],
        'currency' => 'aud' // Use AUD for Australian account
    ];
}

function createCardSource($card_data, $stripe_key) {
    $parts = explode('|', $card_data);
    if (count($parts) < 4) {
        return ['success' => false, 'message' => 'Invalid card format'];
    }
    
    list($number, $month, $year, $cvc) = $parts;
    
    // Try creating a source instead
    $source_data = [
        'type' => 'card',
        'currency' => 'aud',
        'card[number]' => $number,
        'card[exp_month]' => $month,
        'card[exp_year]' => $year,
        'card[cvc]' => $cvc
    ];
    
    $result = makeStripeRequest('sources', $source_data, $stripe_key);
    
    if ($result['http_code'] === 200) {
        return [
            'success' => true,
            'source' => $result['response']
        ];
    }
    
    return [
        'success' => false,
        'message' => $result['response']['error']['message'] ?? 'Source creation failed',
        'error_code' => $result['response']['error']['code'] ?? null
    ];
}

function authCard($card_data, $stripe_key) {
    // First try to create a source
    $source_result = createCardSource($card_data, $stripe_key);
    
    if (!$source_result['success']) {
        // If source fails due to raw card data restriction, show helpful message
        if (strpos($source_result['message'], 'unsafe') !== false || 
            strpos($source_result['message'], 'raw card data') !== false) {
            return [
                'success' => false,
                'message' => '⚠️ Your Stripe account has restricted raw card data access.\n\n' .
                           '🔧 To enable card checking:\n' .
                           '1. Go to: https://dashboard.stripe.com/account/integration/settings\n' .
                           '2. Enable "Process payments using raw card data"\n' .
                           '3. Complete the security review if required\n\n' .
                           '📧 Or contact Stripe support to enable this feature.'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Card validation failed: ' . $source_result['message']
        ];
    }
    
    $source = $source_result['source'];
    $card = $source['card'];
    
    // For auth-only, we just validate the source creation
    return [
        'success' => true,
        'status' => $source['status'],
        'card' => $card,
        'message' => 'Auth successful'
    ];
}

function chargeCard($card_data, $stripe_key, $amount = 50) { // 50 cents in cents
    // First try to create a source
    $source_result = createCardSource($card_data, $stripe_key);
    
    if (!$source_result['success']) {
        // If source fails due to raw card data restriction, show helpful message
        if (strpos($source_result['message'], 'unsafe') !== false || 
            strpos($source_result['message'], 'raw card data') !== false) {
            return [
                'success' => false,
                'message' => '⚠️ Your Stripe account has restricted raw card data access.\n\n' .
                           '🔧 To enable card checking:\n' .
                           '1. Go to: https://dashboard.stripe.com/account/integration/settings\n' .
                           '2. Enable "Process payments using raw card data"\n' .
                           '3. Complete the security review if required\n\n' .
                           '📧 Or contact Stripe support to enable this feature.'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Card validation failed: ' . $source_result['message']
        ];
    }
    
    $source = $source_result['source'];
    $source_id = $source['id'];
    $card = $source['card'];
    
    // Create charge using the source
    $charge_data = [
        'amount' => $amount,
        'currency' => 'aud', // Use AUD for Australian account
        'source' => $source_id,
        'description' => 'Card validation charge'
    ];
    
    $charge_result = makeStripeRequest('charges', $charge_data, $stripe_key);
    
    if ($charge_result['http_code'] === 200) {
        $charge = $charge_result['response'];
        $status = $charge['status'];
        
        return [
            'success' => true,
            'status' => $status,
            'card' => $card,
            'amount' => $amount / 100,
            'currency' => 'AUD',
            'charge_id' => $charge['id'],
            'message' => 'Charge successful'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Charge failed: ' . ($charge_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
}

function createInvoice($stripe_key, $amount = 100) { // $1.00 AUD in cents
    // First create a customer
    $customer_data = [
        'email' => 'telegram-bot-customer@example.com',
        'description' => 'Telegram Bot Invoice Customer'
    ];
    
    $customer_result = makeStripeRequest('customers', $customer_data, $stripe_key);
    
    if ($customer_result['http_code'] !== 200) {
        return [
            'success' => false,
            'message' => 'Customer creation failed: ' . ($customer_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
    
    $customer_id = $customer_result['response']['id'];
    
    // Create invoice item
    $invoice_item_data = [
        'customer' => $customer_id,
        'amount' => $amount,
        'currency' => 'aud',
        'description' => 'Telegram Bot Invoice - $' . ($amount / 100) . ' AUD'
    ];
    
    $item_result = makeStripeRequest('invoiceitems', $invoice_item_data, $stripe_key);
    
    if ($item_result['http_code'] !== 200) {
        return [
            'success' => false,
            'message' => 'Invoice item creation failed: ' . ($item_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
    
    // Create invoice
    $invoice_data = [
        'customer' => $customer_id,
        'auto_advance' => 'false' // Don't auto-finalize
    ];
    
    $invoice_result = makeStripeRequest('invoices', $invoice_data, $stripe_key);
    
    if ($invoice_result['http_code'] !== 200) {
        return [
            'success' => false,
            'message' => 'Invoice creation failed: ' . ($invoice_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
    
    $invoice = $invoice_result['response'];
    
    // Finalize the invoice
    $finalize_result = makeStripeRequest('invoices/' . $invoice['id'] . '/finalize', [], $stripe_key);
    
    if ($finalize_result['http_code'] !== 200) {
        return [
            'success' => false,
            'message' => 'Invoice finalization failed: ' . ($finalize_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
    
    $finalized_invoice = $finalize_result['response'];
    
    return [
        'success' => true,
        'invoice' => $finalized_invoice,
        'customer_id' => $customer_id,
        'amount' => $amount / 100,
        'currency' => 'AUD'
    ];
}

function payInvoice($invoice_id, $card_data, $stripe_key) {
    // First create a source for the card
    $source_result = createCardSource($card_data, $stripe_key);
    
    if (!$source_result['success']) {
        return [
            'success' => false,
            'message' => 'Card validation failed: ' . $source_result['message']
        ];
    }
    
    $source = $source_result['source'];
    $source_id = $source['id'];
    $card = $source['card'];
    
    // Pay the invoice
    $payment_data = [
        'source' => $source_id
    ];
    
    $payment_result = makeStripeRequest('invoices/' . $invoice_id . '/pay', $payment_data, $stripe_key);
    
    if ($payment_result['http_code'] === 200) {
        $invoice = $payment_result['response'];
        
        return [
            'success' => true,
            'invoice' => $invoice,
            'card' => $card,
            'message' => 'Invoice paid successfully'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Invoice payment failed: ' . ($payment_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
}

function createPaymentLink($stripe_key, $amount = 100) { // $1.00 AUD in cents
    // Step 1: Create a product
    $product_data = [
        'name' => 'Telegram Bot Payment',
        'description' => 'Payment via Telegram Bot'
    ];
    
    $product_result = makeStripeRequest('products', $product_data, $stripe_key);
    
    if ($product_result['http_code'] !== 200) {
        return [
            'success' => false,
            'message' => 'Product creation failed: ' . ($product_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
    
    $product_id = $product_result['response']['id'];
    
    // Step 2: Create a price
    $price_data = [
        'product' => $product_id,
        'unit_amount' => $amount,
        'currency' => 'aud'
    ];
    
    $price_result = makeStripeRequest('prices', $price_data, $stripe_key);
    
    if ($price_result['http_code'] !== 200) {
        return [
            'success' => false,
            'message' => 'Price creation failed: ' . ($price_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
    
    $price_id = $price_result['response']['id'];
    
    // Step 3: Create payment link
    $payment_link_data = [
        'line_items[0][price]' => $price_id,
        'line_items[0][quantity]' => 1,
        'after_completion[type]' => 'redirect',
        'after_completion[redirect][url]' => 'https://example.com/success'
    ];
    
    $payment_link_result = makeStripeRequest('payment_links', $payment_link_data, $stripe_key);
    
    if ($payment_link_result['http_code'] === 200) {
        $payment_link = $payment_link_result['response'];
        
        return [
            'success' => true,
            'payment_link' => $payment_link,
            'amount' => $amount / 100,
            'currency' => 'AUD'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Payment link creation failed: ' . ($payment_link_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
}

function createPaymentIntent($card_data, $stripe_key, $amount = 100) { // $1.00 AUD in cents
    // Step 1: Create Payment Intent
    $payment_intent_data = [
        'amount' => $amount,
        'currency' => 'aud',
        'description' => 'Telegram Bot Payment',
        'automatic_payment_methods[enabled]' => 'true'
    ];
    
    $payment_intent_result = makeStripeRequest('payment_intents', $payment_intent_data, $stripe_key);
    
    if ($payment_intent_result['http_code'] !== 200) {
        return [
            'success' => false,
            'message' => 'Payment Intent creation failed: ' . ($payment_intent_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
    
    $payment_intent = $payment_intent_result['response'];
    
    // Step 2: Create Payment Method
    $card_parts = explode('|', $card_data);
    if (count($card_parts) < 4) {
        return [
            'success' => false,
            'message' => 'Invalid card format'
        ];
    }
    
    list($number, $month, $year, $cvc) = $card_parts;
    
    $payment_method_data = [
        'type' => 'card',
        'card[number]' => $number,
        'card[exp_month]' => $month,
        'card[exp_year]' => $year,
        'card[cvc]' => $cvc
    ];
    
    $payment_method_result = makeStripeRequest('payment_methods', $payment_method_data, $stripe_key);
    
    if ($payment_method_result['http_code'] !== 200) {
        $error_msg = $payment_method_result['response']['error']['message'] ?? 'Unknown error';
        
        // Check for raw card data restriction
        if (strpos($error_msg, 'unsafe') !== false || strpos($error_msg, 'raw card data') !== false) {
            return [
                'success' => false,
                'message' => '⚠️ Your Stripe account has restricted raw card data access.\n\n' .
                           '🔧 To enable card processing:\n' .
                           '1. Go to: https://dashboard.stripe.com/account/integration/settings\n' .
                           '2. Enable "Process payments using raw card data"\n' .
                           '3. Complete the security review if required\n\n' .
                           '📧 Or contact Stripe support to enable this feature.'
            ];
        }
        
        return [
            'success' => false,
            'message' => 'Payment Method creation failed: ' . $error_msg
        ];
    }
    
    $payment_method = $payment_method_result['response'];
    
    // Step 3: Confirm Payment Intent
    $confirm_data = [
        'payment_method' => $payment_method['id']
    ];
    
    $confirm_result = makeStripeRequest('payment_intents/' . $payment_intent['id'] . '/confirm', $confirm_data, $stripe_key);
    
    if ($confirm_result['http_code'] === 200) {
        $confirmed_payment = $confirm_result['response'];
        
        return [
            'success' => true,
            'payment_intent' => $confirmed_payment,
            'payment_method' => $payment_method,
            'amount' => $amount / 100,
            'currency' => 'AUD'
        ];
    } else {
        return [
            'success' => false,
            'message' => 'Payment confirmation failed: ' . ($confirm_result['response']['error']['message'] ?? 'Unknown error')
        ];
    }
}

// Get webhook data
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update) {
    exit('No update received');
}

// Extract message data
$message = $update['message'] ?? null;
if (!$message) {
    exit('No message found');
}

$chat_id = $message['chat']['id'];
$user_id = $message['from']['id'];
$text = $message['text'] ?? '';

// Command handling
if (strpos($text, '/start') === 0) {
    $welcome_text = "🤖 <b>Stripe Card Checker Bot</b>\n\n";
    $welcome_text .= "Welcome! This bot can check credit cards using Stripe API.\n\n";
    $welcome_text .= "<b>Commands:</b>\n";
    $welcome_text .= "🔑 <code>/setkey sk_xxx</code> - Set your Stripe secret key\n";
    $welcome_text .= "✅ <code>/au 4242424242424242|12|25|123</code> - Auth card only (no charge)\n";
    $welcome_text .= "💳 <code>/chk 4242424242424242|12|25|123</code> - Charge $0.50\n";
    $welcome_text .= "🧾 <code>/invoice</code> - Create $1.00 invoice\n";
    $welcome_text .= "💰 <code>/pay invoice_id 4242424242424242|12|25|123</code> - Pay invoice\n";
    $welcome_text .= "🔗 <code>/link</code> - Create $1.00 payment link\n";
    $welcome_text .= "⚡ <code>/paynow 4242424242424242|12|25|123</code> - Direct $1.00 payment\n\n";
    $welcome_text .= "<b>Card format:</b> number|month|year|cvc\n";
    $welcome_text .= "<i>Example: 4242424242424242|12|25|123</i>";
    
    sendMessage($chat_id, $welcome_text);
}

elseif (strpos($text, '/setkey') === 0) {
    $parts = explode(' ', $text, 2);
    if (count($parts) < 2) {
        sendMessage($chat_id, "❌ Please provide your Stripe secret key.\n\n<b>Usage:</b> <code>/setkey sk_xxxxx</code>");
    } else {
        $stripe_key = trim($parts[1]);
        
        if (!preg_match('/^sk_/', $stripe_key)) {
            sendMessage($chat_id, "❌ Invalid Stripe key format. Key should start with 'sk_'");
        } else {
            // Test the key
            if (checkStripeKey($stripe_key)) {
                setUserStripeKey($user_id, $stripe_key);
                sendMessage($chat_id, "✅ <b>Stripe key saved successfully!</b>\n\nYou can now use /au and /chk commands.");
            } else {
                sendMessage($chat_id, "❌ <b>Invalid Stripe key!</b>\n\nPlease check your key and try again.");
            }
        }
    }
}

elseif (strpos($text, '/au') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            sendMessage($chat_id, "❌ Please provide card details.\n\n<b>Usage:</b> <code>/au 4242424242424242|12|25|123</code>");
        } else {
            $card_data = trim($parts[1]);
            sendMessage($chat_id, "🔄 <b>Processing auth request...</b>");
            
            $result = authCard($card_data, $stripe_key);
            
            if ($result['success']) {
                $card = $result['card'];
                $response_text = "✅ <b>AUTH SUCCESSFUL</b>\n\n";
                $response_text .= "💳 <b>Card Info:</b>\n";
                $response_text .= "• Brand: " . strtoupper($card['brand'] ?? 'Unknown') . "\n";
                $response_text .= "• Last4: " . ($card['last4'] ?? 'Unknown') . "\n";
                $response_text .= "• Country: " . strtoupper($card['country'] ?? 'Unknown') . "\n";
                $response_text .= "• Funding: " . ucfirst($card['funding'] ?? 'Unknown') . "\n";
                $response_text .= "• Status: " . strtoupper($result['status']) . "\n";
                $response_text .= "\n🔒 <i>Authorization completed - No charge made</i>";
                
                sendMessage($chat_id, $response_text);
            } else {
                sendMessage($chat_id, "❌ <b>AUTH FAILED</b>\n\n" . $result['message']);
            }
        }
    }
}

elseif (strpos($text, '/chk') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            sendMessage($chat_id, "❌ Please provide card details.\n\n<b>Usage:</b> <code>/chk 4242424242424242|12|25|123</code>");
        } else {
            $card_data = trim($parts[1]);
            sendMessage($chat_id, "🔄 <b>Processing charge request...</b>");
            
            $result = chargeCard($card_data, $stripe_key);
            
            if ($result['success']) {
                $card = $result['card'];
                $response_text = "✅ <b>CHARGE SUCCESSFUL</b>\n\n";
                $response_text .= "💳 <b>Card Info:</b>\n";
                $response_text .= "• Brand: " . strtoupper($card['brand'] ?? 'Unknown') . "\n";
                $response_text .= "• Last4: " . ($card['last4'] ?? 'Unknown') . "\n";
                $response_text .= "• Country: " . strtoupper($card['country'] ?? 'Unknown') . "\n";
                $response_text .= "• Funding: " . ucfirst($card['funding'] ?? 'Unknown') . "\n";
                $response_text .= "• Status: " . strtoupper($result['status']) . "\n";
                $response_text .= "• Amount: $" . $result['amount'] . " " . $result['currency'] . "\n";
                $response_text .= "\n💰 <i>Charge completed successfully</i>";
                
                sendMessage($chat_id, $response_text);
            } else {
                sendMessage($chat_id, "❌ <b>CHARGE FAILED</b>\n\n" . $result['message']);
            }
        }
    }
}

elseif (strpos($text, '/invoice') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        sendMessage($chat_id, "🔄 <b>Creating $1.00 AUD invoice...</b>");
        
        $result = createInvoice($stripe_key);
        
        if ($result['success']) {
            $invoice = $result['invoice'];
            $response_text = "✅ <b>INVOICE CREATED</b>\n\n";
            $response_text .= "📄 <b>Invoice Details:</b>\n";
            $response_text .= "• Invoice ID: <code>" . $invoice['id'] . "</code>\n";
            $response_text .= "• Amount: $" . $result['amount'] . " " . $result['currency'] . "\n";
            $response_text .= "• Status: " . strtoupper($invoice['status']) . "\n";
            $response_text .= "• Number: " . ($invoice['number'] ?? 'N/A') . "\n";
            $response_text .= "• Created: " . date('Y-m-d H:i:s', $invoice['created']) . "\n";
            if (isset($invoice['hosted_invoice_url'])) {
                $response_text .= "• Invoice URL: " . $invoice['hosted_invoice_url'] . "\n";
            }
            $response_text .= "\n💰 <b>To pay this invoice:</b>\n";
            $response_text .= "<code>/pay " . $invoice['id'] . " 4242424242424242|12|25|123</code>\n";
            $response_text .= "\n<i>Replace the card details with your actual card</i>";
            
            sendMessage($chat_id, $response_text);
        } else {
            sendMessage($chat_id, "❌ <b>INVOICE CREATION FAILED</b>\n\n" . $result['message']);
        }
    }
}

elseif (strpos($text, '/pay') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        $parts = explode(' ', $text, 3);
        if (count($parts) < 3) {
            sendMessage($chat_id, "❌ Please provide invoice ID and card details.\n\n<b>Usage:</b> <code>/pay in_xxxxx 4242424242424242|12|25|123</code>");
        } else {
            $invoice_id = trim($parts[1]);
            $card_data = trim($parts[2]);
            
            if (!preg_match('/^in_/', $invoice_id)) {
                sendMessage($chat_id, "❌ Invalid invoice ID format. Should start with 'in_'");
                return;
            }
            
            sendMessage($chat_id, "🔄 <b>Processing invoice payment...</b>");
            
            $result = payInvoice($invoice_id, $card_data, $stripe_key);
            
            if ($result['success']) {
                $invoice = $result['invoice'];
                $card = $result['card'];
                $response_text = "✅ <b>INVOICE PAID SUCCESSFULLY</b>\n\n";
                $response_text .= "📄 <b>Invoice Details:</b>\n";
                $response_text .= "• Invoice ID: <code>" . $invoice['id'] . "</code>\n";
                $response_text .= "• Amount: $" . number_format($invoice['amount_paid'] / 100, 2) . " AUD\n";
                $response_text .= "• Status: " . strtoupper($invoice['status']) . "\n";
                $response_text .= "• Paid: " . date('Y-m-d H:i:s', $invoice['status_transitions']['paid_at']) . "\n\n";
                
                $response_text .= "💳 <b>Card Used:</b>\n";
                $response_text .= "• Brand: " . strtoupper($card['brand'] ?? 'Unknown') . "\n";
                $response_text .= "• Last4: " . ($card['last4'] ?? 'Unknown') . "\n";
                $response_text .= "• Country: " . strtoupper($card['country'] ?? 'Unknown') . "\n";
                $response_text .= "• Funding: " . ucfirst($card['funding'] ?? 'Unknown') . "\n";
                
                if (isset($invoice['receipt_url'])) {
                    $response_text .= "\n🧾 Receipt: " . $invoice['receipt_url'];
                }
                
                $response_text .= "\n\n💰 <i>Payment completed successfully!</i>";
                
                sendMessage($chat_id, $response_text);
            } else {
                sendMessage($chat_id, "❌ <b>PAYMENT FAILED</b>\n\n" . $result['message']);
            }
        }
    }
}

elseif (strpos($text, '/link') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        sendMessage($chat_id, "🔄 <b>Creating $1.00 AUD payment link...</b>");
        
        $result = createPaymentLink($stripe_key);
        
        if ($result['success']) {
            $payment_link = $result['payment_link'];
            $response_text = "✅ <b>PAYMENT LINK CREATED</b>\n\n";
            $response_text .= "🔗 <b>Payment Link Details:</b>\n";
            $response_text .= "• Link ID: <code>" . $payment_link['id'] . "</code>\n";
            $response_text .= "• Amount: $" . $result['amount'] . " " . $result['currency'] . "\n";
            $response_text .= "• Status: " . ($payment_link['active'] ? 'Active' : 'Inactive') . "\n";
            $response_text .= "• Created: " . date('Y-m-d H:i:s', $payment_link['created']) . "\n\n";
            $response_text .= "🌐 <b>Payment URL:</b>\n";
            $response_text .= $payment_link['url'] . "\n\n";
            $response_text .= "💳 <b>Test Card:</b> 4242424242424242|12|25|123\n";
            $response_text .= "🖱️ <i>Click the link above to complete payment in browser</i>";
            
            sendMessage($chat_id, $response_text);
        } else {
            sendMessage($chat_id, "❌ <b>PAYMENT LINK CREATION FAILED</b>\n\n" . $result['message']);
        }
    }
}

elseif (strpos($text, '/paynow') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        $parts = explode(' ', $text, 2);
        if (count($parts) < 2) {
            sendMessage($chat_id, "❌ Please provide card details.\n\n<b>Usage:</b> <code>/paynow 4242424242424242|12|25|123</code>");
        } else {
            $card_data = trim($parts[1]);
            sendMessage($chat_id, "🔄 <b>Processing $1.00 AUD payment...</b>");
            
            $result = createPaymentIntent($card_data, $stripe_key);
            
            if ($result['success']) {
                $payment_intent = $result['payment_intent'];
                $payment_method = $result['payment_method'];
                
                $response_text = "✅ <b>PAYMENT SUCCESSFUL</b>\n\n";
                $response_text .= "💰 <b>Payment Details:</b>\n";
                $response_text .= "• Payment ID: <code>" . $payment_intent['id'] . "</code>\n";
                $response_text .= "• Amount: $" . $result['amount'] . " " . $result['currency'] . "\n";
                $response_text .= "• Status: " . strtoupper($payment_intent['status']) . "\n";
                $response_text .= "• Created: " . date('Y-m-d H:i:s', $payment_intent['created']) . "\n\n";
                
                if (isset($payment_method['card'])) {
                    $card = $payment_method['card'];
                    $response_text .= "💳 <b>Card Used:</b>\n";
                    $response_text .= "• Brand: " . strtoupper($card['brand']) . "\n";
                    $response_text .= "• Last4: " . $card['last4'] . "\n";
                    $response_text .= "• Country: " . strtoupper($card['country']) . "\n";
                    $response_text .= "• Funding: " . ucfirst($card['funding']) . "\n";
                }
                
                if (isset($payment_intent['charges']['data'][0])) {
                    $charge = $payment_intent['charges']['data'][0];
                    $response_text .= "\n🧾 Charge ID: " . $charge['id'];
                    if (isset($charge['receipt_url'])) {
                        $response_text .= "\n📄 Receipt: " . $charge['receipt_url'];
                    }
                }
                
                $response_text .= "\n\n⚡ <i>Direct payment completed successfully!</i>";
                
                sendMessage($chat_id, $response_text);
            } else {
                sendMessage($chat_id, "❌ <b>PAYMENT FAILED</b>\n\n" . $result['message']);
            }
        }
    }
}

elseif (strpos($text, '/invoice') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        sendMessage($chat_id, "🔄 <b>Creating $1.00 AUD invoice...</b>");
        
        $result = createInvoice($stripe_key);
        
        if ($result['success']) {
            $invoice = $result['invoice'];
            $response_text = "✅ <b>INVOICE CREATED</b>\n\n";
            $response_text .= "📄 <b>Invoice Details:</b>\n";
            $response_text .= "• Invoice ID: <code>" . $invoice['id'] . "</code>\n";
            $response_text .= "• Amount: $" . $result['amount'] . " " . $result['currency'] . "\n";
            $response_text .= "• Status: " . strtoupper($invoice['status']) . "\n";
            $response_text .= "• Number: " . ($invoice['number'] ?? 'N/A') . "\n";
            $response_text .= "• Created: " . date('Y-m-d H:i:s', $invoice['created']) . "\n";
            if (isset($invoice['hosted_invoice_url'])) {
                $response_text .= "• Invoice URL: " . $invoice['hosted_invoice_url'] . "\n";
            }
            $response_text .= "\n💰 <b>To pay this invoice:</b>\n";
            $response_text .= "<code>/pay " . $invoice['id'] . " 4242424242424242|12|25|123</code>\n";
            $response_text .= "\n<i>Replace the card details with your actual card</i>";
            
            sendMessage($chat_id, $response_text);
        } else {
            sendMessage($chat_id, "❌ <b>INVOICE CREATION FAILED</b>\n\n" . $result['message']);
        }
    }
}

elseif (strpos($text, '/pay') === 0) {
    $stripe_key = getUserStripeKey($user_id);
    if (!$stripe_key) {
        sendMessage($chat_id, "❌ <b>No Stripe key found!</b>\n\nPlease set your key first using: <code>/setkey sk_xxxxx</code>");
    } else {
        $parts = explode(' ', $text, 3);
        if (count($parts) < 3) {
            sendMessage($chat_id, "❌ Please provide invoice ID and card details.\n\n<b>Usage:</b> <code>/pay in_xxxxx 4242424242424242|12|25|123</code>");
        } else {
            $invoice_id = trim($parts[1]);
            $card_data = trim($parts[2]);
            
            if (!preg_match('/^in_/', $invoice_id)) {
                sendMessage($chat_id, "❌ Invalid invoice ID format. Should start with 'in_'");
                return;
            }
            
            sendMessage($chat_id, "🔄 <b>Processing invoice payment...</b>");
            
            $result = payInvoice($invoice_id, $card_data, $stripe_key);
            
            if ($result['success']) {
                $invoice = $result['invoice'];
                $card = $result['card'];
                $response_text = "✅ <b>INVOICE PAID SUCCESSFULLY</b>\n\n";
                $response_text .= "📄 <b>Invoice Details:</b>\n";
                $response_text .= "• Invoice ID: <code>" . $invoice['id'] . "</code>\n";
                $response_text .= "• Amount: $" . number_format($invoice['amount_paid'] / 100, 2) . " AUD\n";
                $response_text .= "• Status: " . strtoupper($invoice['status']) . "\n";
                $response_text .= "• Paid: " . date('Y-m-d H:i:s', $invoice['status_transitions']['paid_at']) . "\n\n";
                
                $response_text .= "💳 <b>Card Used:</b>\n";
                $response_text .= "• Brand: " . strtoupper($card['brand'] ?? 'Unknown') . "\n";
                $response_text .= "• Last4: " . ($card['last4'] ?? 'Unknown') . "\n";
                $response_text .= "• Country: " . strtoupper($card['country'] ?? 'Unknown') . "\n";
                $response_text .= "• Funding: " . ucfirst($card['funding'] ?? 'Unknown') . "\n";
                
                if (isset($invoice['receipt_url'])) {
                    $response_text .= "\n🧾 Receipt: " . $invoice['receipt_url'];
                }
                
                $response_text .= "\n\n💰 <i>Payment completed successfully!</i>";
                
                sendMessage($chat_id, $response_text);
            } else {
                sendMessage($chat_id, "❌ <b>PAYMENT FAILED</b>\n\n" . $result['message']);
            }
        }
    }
}

else {
    $help_text = "❓ <b>Unknown command</b>\n\n";
    $help_text .= "<b>Available commands:</b>\n";
    $help_text .= "🔑 <code>/setkey sk_xxx</code> - Set Stripe key\n";
    $help_text .= "✅ <code>/au card|month|year|cvc</code> - Auth only\n";
    $help_text .= "💳 <code>/chk card|month|year|cvc</code> - Charge $0.50\n";
    $help_text .= "🧾 <code>/invoice</code> - Create $1.00 invoice\n";
    $help_text .= "💰 <code>/pay invoice_id card|month|year|cvc</code> - Pay invoice\n";
    $help_text .= "🔗 <code>/link</code> - Create payment link\n";
    $help_text .= "⚡ <code>/paynow card|month|year|cvc</code> - Direct payment\n\n";
    $help_text .= "Type /start for more info.";
    
    sendMessage($chat_id, $help_text);
}

http_response_code(200);
echo "OK";
?>

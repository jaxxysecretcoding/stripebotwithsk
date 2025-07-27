# Stripe Card Checker Bot

This is a Telegram bot I built to work with Stripe's API for testing and processing credit cards. It's written in pure PHP without any external dependencies - just upload and run.

## What It Does

The bot handles multiple types of payment operations:

- **Card Testing**: Check if cards are valid without charging them (`/au`)
- **Small Charges**: Test cards with a $0.50 AUD charge (`/chk`) 
- **Invoice System**: Create invoices and accept payments (`/invoice` + `/pay`)
- **Payment Links**: Generate secure payment links for browser checkout (`/link`)
- **Direct Payments**: Process $1.00 AUD payments instantly (`/paynow`)

Each user can set their own Stripe API key, so multiple people can use the same bot with their own accounts. All card details are processed through Stripe's secure API - nothing sensitive gets stored locally.

## Getting Started

### Step 1: Create Your Bot

Head over to [@BotFather](https://t.me/botfather) on Telegram and create a new bot:
1. Send `/newbot` 
2. Choose a name and username for your bot
3. Copy the bot token you receive

### Step 2: Upload and Configure

1. Download the `bot.php` file to your web server
2. Edit line 17 and replace `YOUR_BOT_TOKEN_HERE` with your actual bot token
3. Make sure your server has PHP 7.0+ with cURL enabled
4. The bot will automatically create a `users.json` file to store user data

### Step 3: Set Up Webhook

Tell Telegram where to find your bot by setting the webhook URL:

```bash
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
     -d "url=https://yourdomain.com/path/to/bot.php"
```

### Step 4: Get Your Stripe Keys

Go to your [Stripe Dashboard](https://dashboard.stripe.com/apikeys) and grab your secret key (the one that starts with `sk_`). You'll need this to process payments.

## How to Use

Once your bot is running, start a chat with it and use these commands:

### Basic Commands
- **`/start`** - Shows the welcome message and command list
- **`/setkey sk_xxxxx`** - Save your Stripe secret key (required first step)

### Card Testing
- **`/au 4242424242424242|12|25|123`** - Test card authorization without charging
- **`/chk 4242424242424242|12|25|123`** - Test card with $0.50 AUD charge

### Payment Processing  
- **`/paynow 4242424242424242|12|25|123`** - Process $1.00 AUD payment immediately
- **`/link`** - Create a $1.00 AUD payment link for browser checkout

### Invoice System
- **`/invoice`** - Create a $1.00 AUD invoice 
- **`/pay in_xxxxx 4242424242424242|12|25|123`** - Pay an existing invoice

### Card Format
All card commands use this format: `number|month|year|cvc`

**Example**: `4242424242424242|12|25|123` (Visa test card)

## Working with Invoices

The invoice system is pretty straightforward:

1. **Create Invoice**: Use `/invoice` to generate a new $1.00 AUD invoice
2. **Get Invoice ID**: Copy the invoice ID from the response (starts with `in_`)  
3. **Payment**: Use `/pay in_xxxxx card|month|year|cvc` to pay the invoice
4. **Receipt**: Get confirmation with receipt URL and payment details

## Test Cards for Development

Stripe provides these test cards you can use:

- **Visa**: `4242424242424242|12|25|123`
- **Visa Debit**: `4000056655665556|12|25|123`  
- **Mastercard**: `5555555555554444|12|25|123`
- **American Express**: `378282246310005|1234` (note: Amex uses 4-digit CVC)
- **Declined Card**: `4000000000000002|12|25|123` (will always fail)

## Technical Details

The bot is built with security in mind:
- Validates Stripe keys before saving them
- Tests API connectivity before storing credentials  
- Uses HTTPS for all API communications
- Stores user data in a simple JSON file
- All card processing happens through Stripe's secure API

### File Structure
```
stripebotjaxxy/
├── bot.php          # Main bot code
├── users.json       # User data (auto-created)
├── README.md        # This documentation
└── config files...  # Additional setup docs
```

### Requirements
- PHP 7.0 or newer
- cURL extension enabled
- Web server with HTTPS (Telegram requires SSL for webhooks)
- Valid SSL certificate

## Deployment

1. Upload `bot.php` to your HTTPS-enabled web server
2. Make sure the directory is writable (the bot needs to create `users.json`)
3. Set your webhook URL with Telegram
4. Send `/start` to your bot to test everything works

## Important Notes

- **Security**: This bot processes real payment data, so use a secure server with proper SSL
- **Testing**: Always test with Stripe's test keys before switching to live mode
- **Data Protection**: The `users.json` file contains Stripe API keys - keep it secure
- **Compliance**: Make sure your usage complies with Stripe's terms of service

## Troubleshooting

**Bot not responding?**
- Check that your webhook URL is correct and accessible
- Verify your bot token is valid
- Make sure your server supports HTTPS

**Stripe errors?**
- Confirm your Stripe key starts with `sk_` and is valid
- Check if you have the necessary permissions in your Stripe account
- Some features require additional Stripe account setup

**Payment issues?**
- Raw card data processing requires specific Stripe account permissions
- Payment links work regardless of account restrictions
- Use test cards for development, real cards for production

## Credits

Bot created by j1xxy - Feel free to modify and use for your projects!

Need help? Check out the Stripe API docs or Telegram Bot API documentation.

# treez-woocommerce-integration
Treez–WooCommerce Integration connects Treez with WooCommerce using simple API-based code. Products, inventory, and pricing sync automatically via cron jobs, ensuring real-time updates with minimal setup, fast performance, and an easy-to-maintain backend.


Treez–WooCommerce Integration

This project connects Treez with WooCommerce using simple PHP code and API calls. It allows automatic order syncing, customer verification, and token management.

Features
API token generation and storage
Automatic token refresh based on expiry
Sync WooCommerce orders to Treez
Customer verification using driver license
Supports delivery and pickup orders
Sends email and SMS notifications
How It Works
Generate access token from Treez API
Store token in database with expiry time
On order completion, fetch customer and order data
Send order details to Treez API
Handle success or failure response

Code reference:

Requirements
WordPress with WooCommerce
PHP with cURL enabled
MySQL database
Treez API credentials
Setup
Add your credentials in the code:
client_id=YOUR_CLIENT_ID
apikey=YOUR_API_KEY
Update API URL with your store name:
https://api.treez.io/v2.0/dispensary/your-store/
Create database table:
ApiCredentials (id, accessToken, accessTokenExpiresAt)
Add the code to your theme or custom plugin
Hooks Used
template_redirect: Handles token generation and refresh
woocommerce_thankyou: Sends order data to Treez
Error Handling
If API fails, order is marked as failed
If successful, order note is added and notifications are sent
Summary

This integration provides a simple and efficient way to connect WooCommerce with Treez, reducing manual work and ensuring accurate order processing.

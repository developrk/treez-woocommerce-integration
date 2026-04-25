<?php
//get api token
function getApiToken() {
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.treez.io/v2.0/dispensary/your-store/config/api/gettokens',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => 'client_id=&apikey=',
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),
    ));

    $response = curl_exec($curl);

    if ($response === false) {
        curl_close($curl);
        die('Error: "' . curl_error($curl) . '" - Code: ' . curl_errno($curl));
    }
 //echo "from api";
    curl_close($curl);

    // Decode the JSON response
    $response_data = json_decode($response);
    //echo "<pre>";print_r($response_data);die('test');

    // Check if the response contains the access_token
    if (isset($response_data->access_token)) {
        //echo $response_data->access_token;
        return $response_data->access_token;
    } else {
        die('Error: Access token not found in the response.');
    }
}
//save and update it in database
function getAccessToken() {
    global $wpdb;
    $table_name = 'ApiCredentials';

    // Retrieve the record with ID 2
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table_name WHERE id = %d", 2));

    if ($row) {
        if (current_time('mysql', 1) <= $row->accessTokenExpiresAt) {
            //echo "from database";
            return $row->accessToken;
        } else {
            $newAccessToken = getApiToken();
            $accessTokenExpiry = date('Y-m-d H:i:s', strtotime(current_time('mysql', 1)) + 2 * 60 * 60);

            // Update the database with the new token and expiry date
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table_name SET accessToken = %s, accessTokenExpiresAt = %s WHERE id = %d",
                    $newAccessToken,
                    $accessTokenExpiry,
                    2
                )
            );
//echo getApiToken();
            return $newAccessToken;
        }
    } else {
        return null;
    }
}

//echo getApiToken();
add_action('template_redirect','getAccessToken');

//when user click on proceed at checkout page woocommerce
add_action('woocommerce_thankyou', function ($order_id) {
    $user_id = get_current_user_id();
    $customerId = null;

    // If user is logged in, try to get the driver's license
    if ($user_id && $driverLicense = get_user_meta($user_id, 'drivers_license', true)) {
        // Treez API token and client ID
        $authorization = getApiToken();
        $client_id = '';
        $url = 'https://api.treez.io/v2.0/dispensary/your-store/customer/driverlicense/' . urlencode($driverLicense);

        // Initialize cURL request to Treez API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: ' . $authorization,
            'client_id: ' . $client_id
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            echo 'Error: ' . curl_error($ch);
        } else {
            $responseData = json_decode($response, true);

            // If the API returned a customer ID, store it
            if (isset($responseData['data']['customer_id'])) {
                $customerId = $responseData['data']['customer_id'];
                $is_verified = $responseData['data']['verification_status'];
            } else {
                echo 'Customer ID not found';
            }
        }

        curl_close($ch);
    }

    // Load the WooCommerce order object and continue with the order process
    $order = new WC_Order($order_id);
    //echo "<pre>";print_r($order);die;
    $delivery_location = get_post_meta($order_id, 'Delivery Location', true);
    $service_type = get_post_meta($order_id, 'Delivery Type', true);
    $serice_time = get_post_meta($order_id, 'Select Time', true);
    $order_date = $order->get_date_created();
    $date_only = new DateTime($order_date);
    $phoneNumber = $order->get_billing_phone();
    //echo $phoneNumber;die;

    // Get the customer's name and email from the order
    $billing_first_name = $order->get_billing_first_name();
    $billing_last_name = $order->get_billing_last_name();
    $billing_email = $order->get_billing_email();
    $customer_note = $order->get_customer_note();
    
//echo "<pre>";print_r($customer_note);die('working');
    // Prepare order items data
    $order_data = array(
        'items' => array()
    );

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        $order_data['items'][] = array(
            'size_id' => $product->get_meta('size_id'),  // Custom meta field for size
            'quantity' => $item->get_quantity()
        );
    }

           $licence = get_post_meta( $order_id, '_checkout_licence', true );
           //$delivery_type = isset($_POST['delivery_type']) ? strtoupper( sanitize_text_field($_POST['delivery_type']) ) : '';

           $ticket_note = 'Name: ' . $billing_first_name . ' ' . $billing_last_name . ', Email: ' . $billing_email;
           
           if ( ! empty( $licence ) ) {
               $ticket_note .= ', Licence: ' . $licence;
           }
           // If DELIVERY is selected, include address in the ticket note
           if ( $service_type === 'DELIVERY' ) {
               $ticket_note .= ', Address: ' . $delivery_location . ', ' . $order->get_billing_city() . ', ' . $order->get_billing_state() . ' - ' . $order->get_billing_postcode();
           }
           if ($customer_note){
           $ticket_note .= ', Customer Note : ' . $customer_note;
           }
           if ($phoneNumber){
           $ticket_note .= ', Customer Number : ' . $phoneNumber;
           }
           //echo "<pre>";print_r($ticket_note);die;
    // Build the payload to send to Treez API (customer_id is optional)
           $payload_data = array(
               'type' => $service_type,
               'order_source' => 'ECOMMERCE',
               // Set default order status to 'PENDING' if $is_verified is not set or empty
               'order_status' => isset($is_verified) && !empty($is_verified) ? ($is_verified == 'VERIFIED' ? 'AWAITING_PROCESSING' : $is_verified) : 'AWAITING_PROCESSING',
               'ticket_note' => $ticket_note,
               'items' => $order_data['items'],
               'revenue_source' => 'your-store online',
           );
//           $payload_data['webhook'] = array(
//                "listener_url" => "https://files.readme.io/503fea9-Create_Order.jpg",
//           );
 
//echo "<pre>";print_r($payload_data);die;
    // Include customer_id only if it exists
    if ($customerId) {
        $payload_data['customer_id'] = $customerId;
    };

    // If delivery is selected, include delivery address details
    if ($service_type == 'DELIVERY') {
        $payload_data['delivery_address'] = array(
            'street' => $delivery_location,
            'city' => $order->get_billing_city(),
            'state' => $order->get_billing_state(),
            'zip' => $order->get_billing_postcode()
        );
    }

    // Send the payload via cURL to Treez API
           
           
    $payload = json_encode($payload_data);
    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://api.treez.io/v2.0/dispensary/your-store/ticket/detailticket',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => array(
            'Authorization: ' . getApiToken(),
            'client_id: ',
            'Content-Type: application/json'
        ),
    ));

           $response = curl_exec($curl);
           $responseData_order = json_decode($response, true);
          // echo "<pre>";print_r($responseData_order);die;
           $result_code = $responseData_order['resultCode'];

           // If the API response is successful, redirect to orders page
           if ($result_code == 'SUCCESS') {
               $orderNumber = $responseData_order['data']['order_number']; // Assuming this is part of the response
               add_order_meta_data($order_id, $orderNumber, 'Website');

               //$phoneNumber = $responseData_order['customerPhone'] ?? null; // Check if phone number exists

               if ($phoneNumber) {
                   sendOrderSMS($phoneNumber, $orderNumber);
               }
                
           
           // Assuming you have a function to update order status and add notes

                        //update_order_status($order_id, 'processing');

                        add_order_note($order_id, 'treez  Order: '.$orderNumber);

           $user_email = $order->get_billing_email();
           //$orderNumber = $order->get_order_number();
           //$delivery_type = $order->get_meta('delivery_type');
           //$delivery_label = $delivery_type === 'DELIVERY' ? 'Delivery' : 'Pickup';
           $track_url = "your-siteorder-checker.php?order_number=$orderNumber";

           $subject = "Your Order #$orderNumber is Processing";

           // HTML message with anchor tag
           $message = "
           <html>
           <head>
             <title>Your Order is Processing</title>
           </head>
           <body style=\"font-family: Arial, sans-serif; color: #333;\">
             <p>Hello,</p>

             <p>Thank you for your order <strong>#$orderNumber</strong>!</p>

             <p>We're happy to let you know that your order is now <strong>processing</strong>.</p>

             <p>🔗 <a href=\"$track_url\" style=\"color: #0066cc; text-decoration: underline;\">
                Click here to track your order</a></p>

             <p>If you have any questions, feel free to contact us on </p>

             <p>Thank you for shopping with us!<br><strong></strong></p>
           </body>
           </html>
           ";

           // Set HTML headers for mail()
           $headers = "MIME-Version: 1.0" . "\r\n";
           $headers .= "Content-type: text/html; charset=UTF-8" . "\r\n";
           $headers .= "From: info@your-store.com" . "\r\n";

           if ($user_email) {
               mail($user_email, $subject, $message, $headers);
           }

               echo "<script>
                   alert('Your Order has been placed. Please check your mail.');
                   window.location.href = 'your-site';
               </script>";
           } else {
               // If the response is not successful, update the order status to 'failed' and add reason in notes
               $resultReason = $responseData_order['resultReason'] ?? 'Unknown Error';
               
               // Assuming you have a function to update order status and add notes
               update_order_status($order_id, 'failed');
               add_order_note($order_id, 'Order failed: ' . $resultReason);

			   
			   $user_email = $order->get_billing_email();

				// Email content
				$subject = "Your Order #$order_id Failed";
				$message = "Hello,\n\nUnfortunately, your order (#$order_id) has failed.\nReason: $resultReason\n\nPlease try again or contact support.";
				$headers = "From: info@your-store.com";

				// Send email
				if ($user_email) {
					mail($user_email, $subject, $message, $headers);
				}

			   
			   
               echo "<script>
                   alert('" . $resultReason . "');
                   window.location.href = '';
               </script>";
           }


    curl_close($curl);
});

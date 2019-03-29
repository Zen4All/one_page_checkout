<?php
// -----
// Part of the One-Page Checkout plugin, provided under GPL 2.0 license by lat9 (cindy@vinosdefrutastropicales.com).
// Copyright (C) 2013-2019, Vinos de Frutas Tropicales.  All rights reserved.
//
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

class checkout_one_observer extends base 
{
    private $enabled = false;
            
    public function __construct() 
    {
        // -----
        // Determine if the current session browser is an Internet Explorer version less than 9 (that don't properly support
        // jQuery).
        //
        require DIR_WS_CLASSES . 'Vinos_Browser.php';
        $browser = new Vinos_Browser();
        $unsupported_browser = ($browser->getBrowser() == Vinos_Browser::BROWSER_IE && $browser->getVersion() < 9);
        $this->browser = $browser->getBrowser() . '::' . $browser->getVersion();
        
        // -----
        // The 'opctype' variable is applied to the checkout_shipping page's link by the checkout_one page's alternate link
        // (available if there's a jQuery error affecting that page's ability to perform a 1-page checkout).
        //
        // If that's set, set a session variable to override the OPC processing, allowing the customer to check out!  As a
        // developer "assist", that value can be reset by supplying &opctype=retry to any link to try again.
        //
        if (isset($_GET['opctype'])) {
            if ($_GET['opctype'] == 'jserr') {
                $_SESSION['opc_error'] = OnePageCheckout::OPC_ERROR_NO_JS;
            }
            if ($_GET['opctype'] == 'retry') {
                unset($_SESSION['opc_error']);
            }
        }
        
        // -----
        // If no previous jQuery error was noted and an account-holder is not logged in,
        // check the shopping-cart's current contents to see if one or more Gift Certificates
        // are present (only account-holders can purchase GC's).
        //
        // If one or more GC is present in the customer's cart:
        // - If the customer is not on the login or checkout_one page, let them know that they'll need
        // to create an account (or sign in) to make that purchase.  If the customer is in the
        // middle of a guest-checkout (e.g. they started without a GC in-cart and added one after
        // the guest-checkout started), let them know that continuing with the checkout will
        // result in a loss of the information that they previously entered.
        //
        // - If the customer has continued (via button- or link-click) to the login/checkout_one
        // page, reset any guest-related information that was previously entered.  The guest-checkout
        // will be disabled.
        //
        // NOTE: Using the session-based OPC class to determine logged-in/guest-checkout status, since
        // the observers for the zen_is_logged_in/zen_in_guest_checkout functions haven't yet been
        // attached!
        //
        if (!(isset($_SESSION['opc_error']) && $_SESSION['opc_error'] == OnePageCheckout::OPC_ERROR_NO_JS) && $_SESSION['opc']->guestCheckoutEnabled()) {
            if (!$_SESSION['opc']->isLoggedIn() || $_SESSION['opc']->isGuestCheckout()) {
                unset($_SESSION['opc_error']);
                $cart_products = $_SESSION['cart']->get_products();
                foreach ($cart_products as $current_product) {
                    if (strpos($current_product['model'], 'GIFT') === 0) {
                        $pages_to_reset_for_gc = array(
                            FILENAME_LOGIN,
                            FILENAME_CHECKOUT_ONE
                        );
                        if (!in_array($GLOBALS['current_page_base'], $pages_to_reset_for_gc)) {
                            $gift_certificate_message = WARNING_GUEST_NO_GCS;
                            if ($_SESSION['opc']->isGuestCheckout()) {
                                $gift_certificate_message .= ' ' . WARNING_GUEST_GCS_RESET . '<br /><br />' . WARNING_GUEST_REMOVE_GC;
                            }
                            $GLOBALS['messageStack']->add('header', $gift_certificate_message, 'caution');
                        } else {
                            $_SESSION['opc']->resetGuestSessionValues();
                            $_SESSION['opc_error'] = OnePageCheckout::OPC_ERROR_NO_GC;
                        }
                        break;
                    }
                }
            }
        }
        
        // -----
        // Perform a little "session-cleanup".  If a guest just placed an order and has navigated off
        // the checkout_success or other, customizable, pages, need to remove all session-variables associated with that
        // guest checkout.
        //
        $post_checkout_pages = explode(',', str_replace(' ', '', CHECKOUT_ONE_GUEST_POST_CHECKOUT_PAGES_ALLOWED));
        $post_checkout_pages[] = FILENAME_CHECKOUT_SUCCESS;
        if (isset($_SESSION['order_placed_by_guest']) && !in_array($GLOBALS['current_page_base'], $post_checkout_pages)) {
            unset($_SESSION['order_placed_by_guest'], $_SESSION['order_number_created']);
            $_SESSION['opc']->resetGuestSessionValues();
        }
        
        // -----
        // Determine, via call to the OPC's session-handler, whether the overall OPC processing is to be
        // enabled.
        //
        $plugin_enabled = $_SESSION['opc']->checkEnabled();
        
        // -----
        // If the current browser is supported and the plugin's environment is supportable, then the processing for the
        // OPC is enabled.  We'll attach notifiers to the various elements of the 3-page checkout to consolidate that
        // processing into a single page.
        //
        if (!$unsupported_browser && $plugin_enabled) {
            $this->enabled = true;
            $this->debug = (CHECKOUT_ONE_DEBUG == 'true' || CHECKOUT_ONE_DEBUG == 'full');
            if ($this->debug && CHECKOUT_ONE_DEBUG_EXTRA != '' && CHECKOUT_ONE_DEBUG_EXTRA != '*') {
                $debug_customers = explode(',', str_replace(' ', '', CHECKOUT_ONE_DEBUG_EXTRA));
                if (!in_array($_SESSION['customer_id'], $debug_customers)) {
                    $this->debug = false;
                }
            }
            $this->debug_logfile = $_SESSION['opc']->getDebugLogFileName();
            $this->current_page_base = $GLOBALS['current_page_base'];
            
            // -----
            // If the customer is currently active in a guest-checkout ...
            //
            if ($_SESSION['opc']->isGuestCheckout()) {
                // -----
                // ... check to see that guest-checkout is **still** enabled.  If so, check to see that
                // the current page is "allowed" during a guest-checkout; otherwise, reset the
                // OPC's guest-checkout settings so that the checkout-process will revert to the
                // built-in 3-page version.
                //
                if ($_SESSION['opc']->guestCheckoutEnabled()) {
                    $disallowed_pages = explode(',', str_replace(' ', '', CHECKOUT_ONE_GUEST_PAGES_DISALLOWED));
                    if (in_array($this->current_page_base, $disallowed_pages)) {
                        $GLOBALS['messageStack']->add_session('header', ERROR_GUEST_CHECKOUT_PAGE_DISALLOWED, 'error');
                        zen_redirect(zen_href_link(FILENAME_DEFAULT));
                    }
                } else {
                    $GLOBALS['messageStack']->add_session('header', WARNING_GUEST_CHECKOUT_NOT_AVAILABLE, 'warning');
                    $_SESSION['opc']->resetGuestSessionValues();
                }
            }
                    
            $this->attach(
                $this, 
                array(
                    'NOTIFY_LOGIN_SUCCESS',
                    'NOTIFY_LOGIN_SUCCESS_VIA_CREATE_ACCOUNT',
                    'NOTIFY_HEADER_START_CHECKOUT_SHIPPING', 
                    'NOTIFY_HEADER_START_CHECKOUT_PAYMENT', 
                    'NOTIFY_HEADER_START_CHECKOUT_SHIPPING_ADDRESS', 
                    'NOTIFY_HEADER_START_CHECKOUT_CONFIRMATION',
                    'NOTIFY_HEADER_START_ADDRESS_BOOK_PROCESS',
                    'NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET',
                    'NOTIFY_ZEN_IN_GUEST_CHECKOUT',
                    'NOTIFY_ZEN_IS_LOGGED_IN',
                )
            );
        }
            
        // -----
        // If the OPC's guest-/account-registration is enabled, some additional notifications
        // need to be monitored.
        //
        // Note: This is left as "legacy", just in case they need to be 'unobserved' in the future.
        // Right now (v2.1.0), that opc method returns an unconditional (bool)true.
        //
        if ($this->enabled && $_SESSION['opc']->initTemporaryAddresses()) {    
            $this->attach(
                $this, 
                array(
                    'NOTIFY_ORDER_CART_AFTER_ADDRESSES_SET',
                    'NOTIFY_ORDER_DURING_CREATE_ADDED_ORDER_HEADER',
                    'NOTIFY_ORDER_INVOICE_CONTENT_READY_TO_SEND',
                    'NOTIFY_HEADER_START_CHECKOUT_SUCCESS',
                    'NOTIFY_OT_COUPON_USES_PER_USER_CHECK',
                    'NOTIFY_PAYMENT_PAYPALEC_BEFORE_SETEC',
                    'NOTIFY_PAYPALEXPRESS_BYPASS_ADDRESS_CREATION',
                    'NOTIFY_HEADER_START_SHOPPING_CART',
                )
            );
        }
        
        // -----
        // Finally, need to "clean up" any email_address value that was injected into
        // the session by the order_status page's processing, in support of not-logged-in
        // customers whose orders included downloads.
        //
        // If the customer has navigated off of the order_status/download pages, remove
        // those variables from the session.
        //
        if (isset($_SESSION['email_is_os']) && ($GLOBALS['current_page_base'] != FILENAME_ORDER_STATUS && $GLOBALS['current_page_base'] != FILENAME_DOWNLOAD)) {
            unset($_SESSION['email_is_os'], $_SESSION['email_address']);
        }
    }
  
    public function update(&$class, $eventID, $p1, &$p2, &$p3, &$p4, &$p5, &$p6, &$p7) 
    {
        switch ($eventID) {     
            // -----
            // If a customer has just successfully logged in, they might have logged in after
            // starting a guest-checkout.  Let the session-based OPC controller perform any
            // clean-up required.
            //
            case 'NOTIFY_LOGIN_SUCCESS':
            case 'NOTIFY_LOGIN_SUCCESS_VIA_CREATE_ACCOUNT':     //-Fall-through from above ...
                $_SESSION['opc']->cleanupGuestSession();
                break;
                
            // -----
            // Redirect any accesses to the "3-page" checkout process to the one-page.
            //
            case 'NOTIFY_HEADER_START_CHECKOUT_SHIPPING':
            case 'NOTIFY_HEADER_START_CHECKOUT_PAYMENT':
            case 'NOTIFY_HEADER_START_CHECKOUT_CONFIRMATION':
                $this->debug_message('checkout_one redirect: ', true, 'checkout_one_observer');
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_ONE, zen_get_all_get_params(), 'SSL'));
                break;
                
            // -----
            // If the customer leaves the checkout process to view and/or make changes to their
            // cart, the shipping-estimator might change the order's ship-to address-book-id, causing
            // OPC's processing to get out-of-sync.  On entry to the 'shopping_cart' page, record
            // the order's current ship-to address for restoration when/if the customer re-enters
            // the checkout processing.
            //
            case 'NOTIFY_HEADER_START_SHOPPING_CART':
                $_SESSION['opc']->saveOrdersSendtoAddress();
                break;
                
            // -----
            // When a customer navigates to the 'checkout_shipping_address' page, reset the
            // shipping=billing flag to indicate that shipping is no longer the same as billing.
            //
            case 'NOTIFY_HEADER_START_CHECKOUT_SHIPPING_ADDRESS':
                $_SESSION['shipping_billing'] = false;
                break;
                
            // -----
            // Issued by the zen_in_guest_checkout function, allowing an observer to note that
            // the store is "in-guest-checkout".
            //
            // On entry:
            //
            // $p1 ... n/a
            // $p2 ... (r/w) Value is set to boolean true/false to indicate the condition.
            //
            case 'NOTIFY_ZEN_IN_GUEST_CHECKOUT':
                $p2 = $_SESSION['opc']->isGuestCheckout();
                break;
                
            // -----
            // Issued by the zen_is_logged_in function, allowing an observer to note that
            // a customer is currently logged into the store.
            //
            // On entry:
            //
            // $p1 ... n/a
            // $p2 ... (r/w) Value is set to boolean true/false to indicate the condition.
            //
            case 'NOTIFY_ZEN_IS_LOGGED_IN':
                $p2 = $_SESSION['opc']->isLoggedIn();
                break;
                
            // -----
            // If the customer has just added an address, force that address to be the
            // primary if the customer currently has no permanent addresses.
            //
            case 'NOTIFY_HEADER_START_ADDRESS_BOOK_PROCESS':
                if (zen_is_logged_in()) {
                    if (isset($_POST['action']) && $_POST['action'] == 'process') {
                        $check = $GLOBALS['db']->Execute(
                            "SELECT address_book_id
                               FROM " . TABLE_ADDRESS_BOOK . "
                              WHERE customers_id = " . (int)$_SESSION['customer_id'] . "
                              LIMIT 1"
                        );
                        if ($check->EOF) {
                            $_POST['primary'] = 'on';
                        }
                    }
                
                }
                break;

            // -----
            // Issued by the order-class at the beginning of the order-creation process (i.e.
            // the cart contents are "converted" to an order.  Gives us the chance to see
            // if this is a guest-checkout and/or an order using a temporary address.
            //
            // If so, the address section(s) of the base order could be modified and the
            // order's tax-basis is re-determined.
            //
            // On entry:
            //
            // $p1 ... n/a
            // $p2 ... (r/w) A reference to the order's $taxCountryId value
            // $p3 ... (r/w) A reference to the order's $taxZoneId value
            //
            case 'NOTIFY_ORDER_CART_AFTER_ADDRESSES_SET':
                $_SESSION['opc']->updateOrderAddresses($class, $p2, $p3);
                break;
                
            // -----
            // Issued by the order-class just after creating a new order's "header",
            // i.e. the information in the orders table.  This gives us the opportunity
            // to note that the order was created via guest-checkout, if needed.
            //
            // On entry:
            //
            // $p1 ... (r/o) A copy of the SQL data-array used to create the header.
            // $p2 ... (r/w) A reference to the newly-created order's ID value.
            //
            case 'NOTIFY_ORDER_DURING_CREATE_ADDED_ORDER_HEADER':
                if (zen_in_guest_checkout()) {
                    $GLOBALS['db']->Execute(
                        "UPDATE " . TABLE_ORDERS . "
                            SET is_guest_order = 1
                          WHERE orders_id = " . (int)$p2 . "
                          LIMIT 1"
                    );
                }
                break;
                
            // -----
            // Issued at the very end of the checkout_process page's handling.  If the
            // order was placed by a guest, capture the order-number created to allow
            // the OPC's guest checkout_success processing to offer the guest the
            // opportunity to create an account using the information in the just-placed
            // order.
            //
            // These values remain in the session ... so long as the guest-customer
            // doesn't navigate off of the checkout_success page.  This class'
            // constructor will remove this (and other guest-related values) from
            // the session if the variable is set and the current page is other
            // than the checkout_success one.
            //
            // Unconditionally, reset the OPC's "common" session variables.
            //
            // On entry:
            //
            // $p1 ... (r/o) The just-created order's order_id.
            //
            case 'NOTIFY_CHECKOUT_PROCESS_BEFORE_CART_RESET':
                if (zen_in_guest_checkout()) {
                    $_SESSION['order_placed_by_guest'] = (int)$p1;
                }
                $_SESSION['opc']->resetSessionVariables();
                break;
                
            // -----
            // At the start of the checkout_success page, check to see if we're
            // at the tail-end of a guest-checkout.  If so, it's possible that the
            // customer is attempting to create an account from the information in
            // the just-placed order.
            //
            // If so, check to see if the page's processing has previously removed
            // the "normal" order-id from the session and restore that value so that
            // the base page-header processing will continue to properly gather the
            // information from that order.
            //
            case 'NOTIFY_HEADER_START_CHECKOUT_SUCCESS':
                if (zen_in_guest_checkout() && !isset($_SESSION['order_number_created'])) {
                    $_SESSION['order_number_created'] = $_SESSION['order_placed_by_guest'];
                }
                break;
                
            // -----
            // Issued by the order-class just prior to sending the order-confirmation email.
            //
            // If either the sendto or billto addresses are "temporary", we'll do some reconstruction.
            //
            // On entry:
            //
            // $p1 ... (r/o) An associative array; the order's order_id is in the zf_insert_id element.
            // $p2 ... (r/w) The current text email string.
            // $p3 ... (r/w) The current HTML email array.
            //
            case 'NOTIFY_ORDER_INVOICE_CONTENT_READY_TO_SEND':
                $temp_addresses = array(
                    CHECKOUT_ONE_GUEST_SENDTO_ADDRESS_BOOK_ID,
                    CHECKOUT_ONE_GUEST_BILLTO_ADDRESS_BOOK_ID
                );
                if (in_array($_SESSION['sendto'], $temp_addresses) || $_SESSION['billto'] == CHECKOUT_ONE_GUEST_BILLTO_ADDRESS_BOOK_ID) {
                    $order_id = (int)$p1['zf_insert_id'];
                    $email_text = $p2;
                    $html_msg = $p3;
                    
                    // -----
                    // If the checkout is for a guest, change the "invoice" link to reference the 'order_status' page.
                    //
                    if (zen_in_guest_checkout()) {
                        $account_history_link = zen_href_link(FILENAME_ACCOUNT_HISTORY_INFO, "order_id=$order_id", 'SSL', false);
                        $account_history_link_text = EMAIL_TEXT_INVOICE_URL . ' ' . $account_history_link;
                        
                        $order_status_link = zen_href_link(FILENAME_ORDER_STATUS, '', 'SSL');
                        $order_status_link_text = EMAIL_TEXT_INVOICE_URL_GUEST . ' ' . $order_status_link;

                        $email_text = str_replace($account_history_link_text, $order_status_link_text, $email_text);
                        
                        $html_msg['INTRO_URL_TEXT'] = EMAIL_TEXT_INVOICE_URL_CLICK_GUEST;
                        $html_msg['INTRO_URL_VALUE'] = $order_status_link;
                    }
                    
                    if ($class->content_type != 'virtual' && in_array($_SESSION['sendto'], $temp_addresses)) {
                        $which = ($_SESSION['sendto'] == CHECKOUT_ONE_GUEST_SENDTO_ADDRESS_BOOK_ID) ? 'ship' : 'bill';
                        $shipping_address = $_SESSION['opc']->formatAddress($which);
                        
                        $new_shipping_address = 
                            EMAIL_TEXT_DELIVERY_ADDRESS . "\n" . 
                            EMAIL_SEPARATOR . "\n" . 
                            $shipping_address;
                        $old_shipping_address = 
                            EMAIL_TEXT_DELIVERY_ADDRESS . "\n" . 
                            EMAIL_SEPARATOR . "\n" . 
                            zen_address_label($_SESSION['customer_id'], $_SESSION['sendto'], 0, '', "\n");
                        $email_text = str_replace($old_shipping_address, $new_shipping_address, $email_text);
                        
                        $html_msg['ADDRESS_DELIVERY_DETAIL'] = nl2br($shipping_address);
                    }
                    
                    if ($_SESSION['billto'] == CHECKOUT_ONE_GUEST_BILLTO_ADDRESS_BOOK_ID) {
                        $billing_address = $_SESSION['opc']->formatAddress('bill');
                        
                        $new_billing_address =
                            EMAIL_TEXT_BILLING_ADDRESS . "\n" .
                            EMAIL_SEPARATOR . "\n" .
                            $billing_address;
                        $old_billing_address =
                            EMAIL_TEXT_BILLING_ADDRESS . "\n" .
                            EMAIL_SEPARATOR . "\n" .
                            zen_address_label($_SESSION['customer_id'], $_SESSION['billto'], 0, '', "\n");
                        $email_text = str_replace($old_billing_address, $new_billing_address, $email_text);
                        
                        $html_msg['ADDRESS_BILLING_DETAIL'] = nl2br($billing_address);
                    }
                    
                    $p2 = $email_text;
                    $p3 = $html_msg;
                }
                break;
                
            // -----
            // Issued by the ot_coupon handling when determining if the uses_per_user defined in the active
            // coupon is restricted.  The main OPC controller will check to see if the uses "per email address"
            // is acceptable.
            //
            // On entry,
            //
            // $p1 ... (r/o) The result of a SQL query gathering information about the to-be-checked coupon.
            // $p2 ... (r/w) A reference to the (boolean) processing flag that indicates whether (true) or
            //               not (false) the coupon's use has been exceeded.
            //
            case 'NOTIFY_OT_COUPON_USES_PER_USER_CHECK':
                $p2 = $_SESSION['opc']->validateUsesPerUserCoupon($p1, $p2);
                break;

            // -----
            // Issued by paypalwpp::ec_step1 just before sending the customer up to PayPal for payment
            // fulfilment.  Gives us the opportunity to record any temporary shipping address into the
            // request.
            //
            // On entry,
            //
            // $p1 ... n/a
            // $p2 ... (r/w) A reference to PayPal's current $options, possibly updated with any temporary address values.
            // $p3 ... (r/w) A reference to the current order-object
            // $p4 ... (r/w) A reference to the order's current totals array.
            //
            case 'NOTIFY_PAYMENT_PAYPALEC_BEFORE_SETEC':
                $p2 = array_merge($p2, $_SESSION['opc']->createPayPalTemporaryAddressInfo());
                break;
                
            // -----
            // Issued by paypalwpp::ec_step2_finish just before its check/creation of an address-book
            // entry for the customer's address values sent back from PayPal.  Gives us the opportunity
            // to bypass that processing if the order currently is using temporary addresses.
            //
            // Note: Not in core for Zen Cart versions prior to 1.5.6b!
            //
            // $p1 ... (r/o) A copy of the PayPal payer/shipto-address information returned.
            // $p2 ... (r/w) A reference to a boolean flag that indicates whether or not the default processing should proceed.
            //
            case 'NOTIFY_PAYPALEXPRESS_BYPASS_ADDRESS_CREATION':
                if ($p2 !== false) {
                    $this->debug_message('NOTIFY_PAYPALEXPRESS_BYPASS_ADDRESS_CREATION previously handled!');
                } else {
                    $p2 = $_SESSION['opc']->setPayPalAddressCreationBypass($p1);
                }
                break;
                
            default:
                break;
        }
    }
    
    public function debug_message($message, $include_request = false, $other_caller = '')
    {
        if ($this->debug) {
            $extra_info = '';
            if ($include_request) {
                $the_request = $_REQUEST;
                foreach ($the_request as $name => $value) {
                    if (strpos($name, 'cc_number') !== false || strpos($name, 'cc_cvv') !== false || strpos($name, 'card-number') !== false || strpos($name, 'cv2-number') !== false) {
                        unset($the_request[$name]);
                    }
                }
                $extra_info = var_export($the_request, true);
            }
            
            // -----
            // Change any occurrences of [code] to ["code"] in the logs so that they can be properly posted between [CODE} tags on the Zen Cart forums.
            //
            $message = str_replace('[code]', '["code"]', $message);
            error_log(date('Y-m-d H:i:s') . ' ' . (($other_caller != '') ? $other_caller : $this->current_page_base) . ": $message$extra_info" . PHP_EOL, 3, $this->debug_logfile);
            $this->notify($message);
        }
    }
    
    public function hashSession($current_order_total)
    {
        $session_data = $_SESSION;
        if (isset($session_data['shipping'])) {
           unset($session_data['shipping']['extras']);
        }
        unset($session_data['shipping_billing'], $session_data['comments'], $session_data['navigation']);
        
        // -----
        // The ot_gv order-total in Zen Cart 1.5.4 sets its session-variable to either 0 or '0.00', which results in
        // false change-detection by this function.  As such, if the order-total's variable is present in the session
        // and is 0, set the variable to a "known" representation of 0!
        //
        if (isset($session_data['cot_gv']) && $session_data['cot_gv'] == 0) {
            $session_data['cot_gv'] = '0.00';
        }
        
        // -----
        // Some of the payment methods (e.g. ceon_manual_card) and possibly shipping/order_totals update
        // information into the session upon their processing ... and ultimately cause the hash on entry
        // to be different from the hash on exit.  Simply update the following list with the variables that
        // can be safely ignored in the hash.
        //
        unset (
            $session_data['ceon_manual_card_card_holder'],
            $session_data['ceon_manual_card_card_type'],
            $session_data['ceon_manual_card_card_expiry_month'],
            $session_data['ceon_manual_card_card_expiry_year'],
            $session_data['ceon_manual_card_card_cv2_number_not_present'],
            $session_data['ceon_manual_card_card_start_month'],
            $session_data['ceon_manual_card_card_start_year'],
            $session_data['ceon_manual_card_card_issue_number'],
            $session_data['ceon_manual_card_data_entered']
        );
        
        // -----
        // Add the order's current total to the blob that's being hashed, so that changes in the total based on
        // payment-module selection can be properly detected (e.g. COD fee).
        //
        // Some currencies use a non-ASCII symbol for its symbol, e.g. £.  To ensure that we don't get into
        // a checkout-loop, make sure that the order's current total is scrubbed to convert any "HTML entities"
        // into their character representation.
        //
        // This is needed since the order's current total, as passed into the confirmation page, is created by
        // javascript that captures the character representation of any symbols.
        //
        // Note: Some templates also include carriage-returns within the total's display, so remove them from
        // the mix, too!
        //
        $current_order_total = str_replace(array("\n", "\r"), '', $current_order_total);
        $session_data['order_current_total'] = html_entity_decode($current_order_total, ENT_COMPAT, CHARSET);
        
        // -----
        // If the order's current total is 0 (which it will be after a 100% coupon), don't include the session's
        // defined payment method, as that might change.
        //
        if (preg_replace('/\D+/', '', $current_order_total) == 0) {
            unset($session_data['payment']);
        }
        
        $hash_values = var_export($session_data, true);
        $this->debug_message("hashSession returning an md5 of $hash_values", false, 'checkout_one_observer');
        return md5($hash_values);
    }
    
    public function isEnabled()
    {
        return $this->enabled;
    }

}
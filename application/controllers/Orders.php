<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

use BitWasp\BitcoinLib\BitcoinLib;
use BitWasp\BitcoinLib\RawTransaction;

/**
 * Orders Controller
 *
 * This class handles the buyer and vendor side of the order process.
 *
 * @package        BitWasp
 * @subpackage    Controllers
 * @category    Orders
 * @author        BitWasp
 *
 */
class Orders extends MY_Controller
{

    /**
     * Constructor
     *
     * Load libs/models.
     *
     * @access    public
     * @see        Libraries/Bw_Bitcoin
     * @see        Libraries/Bw_Messages
     * @see        Models/Order_Model
     * @see        Models/Items_Model
     * @see        Models/Accounts_Model
     * @see        Models/Bitcoin_Model
     * @see        Models/Escrow_Model
     * @see        Models/Messages_Model
     */
    public function __construct()
    {
        parent::__construct();

        $this->load->library('form_validation');
        $this->load->library('bw_messages');
        $this->load->model('order_model');
        $this->load->model('items_model');
        $this->load->model('accounts_model');
        $this->load->model('bitcoin_model');
        $this->load->model('messages_model');

        $this->coin = $this->bw_config->currencies[0];
    }

    /**
     * Vendor Orders
     * User Role: Vendor
     * URI: /orders
     *
     * Displays a vendors orders.
     *
     * @return    void
     */
    public function vendor_orders()
    {

        $this->load->model('review_auth_model');

        /*$cancel = $this->input->post('cancel');
        if(is_array($cancel) ) {

            $id = array_keys($cancel);
            $current_order = $this->order_model->load_order($id, array('2'));
            if($current_order == FALSE)
                redirect('purchases');

            $this->load->model('transaction_cache_model');
            if(count($this->transaction_cache_model->payments_to_address($current_order['address'])) > 0) {
                $data['returnMessage'] == 'Payments have been made to this address - cannot cancel!';
                break;
            }

            if($this->order_model->buyer_cancel($id) == TRUE)
                $data['returnMessage'] = 'This order has been cancelled.';
        }*/

        $data['orders'] = $this->order_model->vendor_orders();

        $id_list = array();
        foreach ($data['orders'] as $t_order) {
            $id_list[] = $t_order['id'];
        }
        $data['review_auth'] = $this->review_auth_model->user_tokens_by_order($id_list);
        $data['page'] = 'orders/order_list';
        $data['title'] = 'My Orders';
        $this->_render($data['page'], $data);
    }

    /**
     * Vendor Accept
     *
     * This page displays the form for vendors to approve an order. This
     * involves selecting if the payment will be up-front, or escrow.
     *
     * @param    int $id
     */
    public function vendor_accept($id)
    {
        if (!(is_numeric($id) && $id >= 0)) {
            $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Invalid order ID.')));
            redirect('orders');
        }

        $data['order'] = $this->order_model->load_order($id, array('1'));
        if ($data['order'] == FALSE) {
            $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Invalid order ID.')));
            redirect('orders');
        }

        $this->load->model('review_model');
        $data['available_public_keys'] = $this->accounts_model->bitcoin_public_keys($this->current_user->user_id);
        $data['fees']['shipping_cost'] = number_format($data['order']['shipping_costs'], 8);
        $data['fees']['fee'] = number_format($data['order']['fees'], 8);

        $data['request_order_type'] = $this->order_model->requested_order_type($data['order']);
        $data['trusted_vendor'] = $this->review_model->decide_trusted_user($data['order'], 'vendor');
        $data['order_type'] = ($data['trusted_vendor'] AND $data['request_order_type'] == 'upfront') ? 'upfront' : 'escrow';

        if ($data['order_type'] == 'escrow') {
            $data['fees']['vendor_fees'] = number_format($data['fees']['fee'] + ((($data['order']['price'] + $data['fees']['shipping_cost']) / 100) * $this->bw_config->escrow_rate), 8);
        } else {
            $data['fees']['vendor_fees'] = number_format($data['fees']['fee'] + ((($data['order']['price'] + $data['fees']['shipping_cost']) / 100) * $this->bw_config->upfront_rate), 8);
        }

        if ($this->input->post('vendor_accept_order') == 'Accept Order') {
            if ($this->form_validation->run('submit_vendor_accept_order') == TRUE) {
                if($data['order']['id'] !== $this->input->post('vendor_accept_order_id')){
                    $data['returnMessage'] = 'An error occured during form submission.';
                } else if ($data['available_public_keys'] == FALSE) {
                    $data['returnMessage'] = 'You have no available public keys to use in this order!';
                } else {
                    $accept_details = array('vendor_public_keys' => $data['available_public_keys'],
                        'order_type' => $data['order_type'],
                        'order' => $data['order'],
                        'initiating_user' => 'vendor',
                        'update_fields' => array());

                    $vendor_accept = $this->order_model->vendor_accept_order($accept_details);

                    if ($vendor_accept == TRUE) {
                        redirect('orders');
                    } else if (is_string($vendor_accept) == TRUE) {
                        $data['returnMessage'] = $vendor_accept;
                    }
                }
            }
        }

        $data['title'] = 'Accept Order #' . $data['order']['id'];
        $data['page'] = 'orders/vendor_accept';
        $this->_render($data['page'], $data);
    }

    /**
     * Vendor Finalize Early
     *
     * This controller allows a vendor who is trusted to request early
     * finalization of an escrow order.
     *
     * @param        int $order_id
     */
    public function vendor_finalize_early($order_id)
    {
        $data['order'] = $this->order_model->load_order($order_id, array('4'));
        if ($data['order'] == FALSE)
            redirect('orders');

        $this->load->model('review_model');

        if ($this->review_model->decide_trusted_user($data['order'], 'vendor') == FALSE
            OR $data['order']['vendor_selected_upfront'] == '1') {
            $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Unable to finalize this order early!')));
            redirect('orders/details/' . $data['order']['id']);
        }

        $this->form_validation->set_rules('upfront', '', 'check_bool_areyousure');

        if ($this->input->post('request_FE') == 'Continue') {
            if ($this->form_validation->run() == TRUE) {
                if ($this->input->post('upfront') == '0') {
                    redirect('orders/details/' . $data['order']['id']);
                } else {
                    $update = array('progress' => '3',
                        'vendor_selected_upfront_time' => time(),
                        'vendor_selected_upfront' => '1',
                        'partially_signed_transaction' => '',
                        'partially_signed_time' => '',
                        'partially_signing_user_id' => '');
                    if ($this->order_model->update_order($data['order']['id'], $update) == TRUE) {
                        $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'You have requested to finalize the order early.')));
                        redirect('orders/details/' . $data['order']['id']);
                    } else {
                        $data['returnMessage'] = 'An error occured processing your request.';
                    }
                }
            }
        }

        $data['page'] = 'orders/vendor_finalize_early';
        $data['title'] = 'Request Early Finalization';
        $this->_render($data['page'], $data);
    }

    /**
     * Vendor Refund
     * Role: Vendor
     * URI: orders/refund/<order_id>
     *
     * This page allows a vendor to initiate a refund to the buyer by
     * creating a raw transaction paying the buyer.
     *
     * @param    int $order_id
     */
    public function vendor_refund($order_id)
    {
        $data['order'] = $this->order_model->load_order($order_id, array('3', '4'));
        if ($data['order'] == FALSE) {
            $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Unable to refund this order.')));
            redirect('orders');
        }

        if (!in_array($data['order']['progress'], array('3', '4'))) {
            $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Unable to refund this order.')));
            redirect('orders');
        }

        $this->load->model('transaction_cache_model');

        $this->form_validation->set_rules('refund', '', 'check_bool_areyousure');

        if ($this->input->post('issue_refund') == 'Issue Refund') {
            if ($this->form_validation->run() == TRUE) {
                if ($this->input->post('refund') == '0') {
                    $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'You have chosen not to refund this order.')));
                    redirect('orders/details/' . $data['order']['id']);
                } else {
                    // Construct new raw transaction!

                    $tx_outs = array();
                    // Add outputs for the sites fee, buyer, and vendor.
                    $buyer_address = BitcoinLib::public_key_to_address($data['order']['buyer_public_key'], $this->coin['crypto_magic_byte']);
                    $tx_outs[$buyer_address] = (float)$data['order']['total_paid'] - 0.0001;

                    $create_spend_transaction = $this->order_model->create_spend_transaction($data['order']['address'], $tx_outs, $data['order']['redeemScript']);
                    if ($create_spend_transaction == TRUE) {
                        if ($this->order_model->update_order($data['order']['id'], array('progress' => '8',
                                'refund_time' => time())) == TRUE
                        ) {
                            $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'A refund has been issued for this order. Please sign to ensure the funds can be claimed ASAP.')));
                            redirect('orders/details/' . $data['order']['id']);
                        } else {
                            $data['returnMessage'] = 'An error occured processing the refund.';
                        }
                    } else {
                        $data['returnMessage'] = $create_spend_transaction;
                    }
                }
            }
        }

        $data['page'] = 'orders/vendor_refund';
        $data['title'] = 'Issue Refund';
        $this->_render($data['page'], $data);
    }

    // Buyer pages

    /**
     * Confirm Order
     * User Role: Buyer
     * URI: /purchases/confirm/$id
     *
     * @param    int $id
     * @return    void
     */
    public function buyer_confirm($id)
    {

        $this->load->model('bitcoin_model');
        $this->load->model('fees_model');
        $this->load->model('shipping_costs_model');
        $this->load->model('review_model');

        $data['order'] = $this->order_model->load_order($id, array('0'));
        if ($data['order'] == FALSE)
            redirect('purchases');

        $data['title'] = 'Place Order #' . $data['order']['id'];
        $data['page'] = 'orders/buyer_confirm_purchase';
        $data['header_meta'] = $this->load->view('orders/encryption_header', NULL, true);

        $data['fees']['shipping_cost'] = number_format($this->shipping_costs_model->costs_to_location($data['order']['items'], $data['order']['buyer']['location']), 8);
        $data['fees']['fee'] = number_format($this->fees_model->calculate(($data['order']['price'] + $data['fees']['shipping_cost'])), 8);
        $data['fees']['total'] = number_format($data['fees']['shipping_cost'] + $data['fees']['fee'], 8);
        $data['total'] = number_format($data['order']['price'] + $data['fees']['total'], 8);

        $data['vendor_public_keys'] = $this->accounts_model->bitcoin_public_keys($data['order']['vendor']['id']);
        $data['trusted_vendor'] = $this->review_model->decide_trusted_user($data['order'], 'vendor');
        $data['request_upfront'] = $this->order_model->requested_order_type($data['order']);
        $data['order_type'] = ($data['trusted_vendor'] && $data['request_upfront'] == 'upfront') ? 'upfront' : 'escrow';

        if ($this->form_validation->run('order_place') == TRUE) {
            $continue = FALSE;

            if ($data['order']['vendor']['block_non_pgp'] == '1') {
                $this->form_validation->set_rules("buyer_address", "Your address", 'check_pgp_encrypted');

                if ($this->form_validation->run() == TRUE)
                    $continue = TRUE;
            } else {
                $continue = TRUE;
            }


            if ($continue == TRUE) {
                // If the vendor has public keys, allow the order address to be created immediately.

                if ($data['vendor_public_keys'] !== FALSE) {
                    $accept_details = array('vendor_public_keys' => $data['vendor_public_keys'],
                        'order_type' => $data['order_type'],
                        'order' => $data['order'],
                        'initiating_user' => 'buyer',
                        'update_fields' => array(
                            'price' => $data['order']['price'],
                            'fees' => $data['fees']['fee'],
                            'confirmed_time' => time(),
                            'buyer_public_key' => $this->input->post('bitcoin_public_key'),
                            'shipping_costs' => $data['fees']['shipping_cost']
                        ));

                    $vendor_accept = $this->order_model->vendor_accept_order($accept_details);

                    if ($vendor_accept == TRUE) {
                        $subject = "New Order #{$data['order']['id']} from " . $this->current_user->user_name;
                        $message = "You have received a new order from {$this->current_user->user_name}.<br />\nOrder ID: #{$data['order']['id']}<br />\n";
                        for ($i = 0; $i < count($data['order']['items']); $i++) {
                            $message .= "{$data['order']['items'][$i]['quantity']} x {$data['order']['items'][$i]['name']}<br />\n";
                        }
                        $message .= "<br />Total price: {$data['order']['currency']['symbol']}{$data['order']['price']}<br /><br />\nBuyer Address: <br />\n" . $this->input->post('buyer_address');
                        $this->order_model->send_order_message($data['order']['id'], $data['order']['vendor']['user_name'], $subject, $message);
                        $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Your order has been accepted, please see the order details page for the payment address.')));

                        redirect('purchases');
                    } else if (is_string($vendor_accept) == TRUE) {
                        $data['returnMessage'] = $vendor_accept;
                    }
                } else {
                    // Eventually this will be deprecated as BIP0032 will ensure you have a key.
                    // No public keys available, so just submit to the vendor for approval.
                    $this->order_model->set_user_public_key($id, 'buyer', $this->input->post('bitcoin_public_key'));

                    $update = array('price' => $data['order']['price'],
                        'fees' => $data['fees']['fee'],
                        'confirmed_time' => time(),
                        'shipping_costs' => $data['fees']['shipping_cost']);

                    // Simply progress order from step 0 to step 1.
                    if ($this->order_model->progress_order($data['order']['id'], '0', '1', $update) == FALSE) {
                        $data['returnMessage'] = 'Unable to place your order at this time, please try again later.';
                    } else {
                        // Send message to vendor
                        $subject = "New Order #{$data['order']['id']} from " . $this->current_user->user_name;
                        $message = "You have received a new order from {$this->current_user->user_name}.<br />\nOrder ID: #{$data['order']['id']}<br />\n";
                        for ($i = 0; $i < count($data['order']['items']); $i++) {
                            $message .= "{$data['order']['items'][$i]['quantity']} x {$data['order']['items'][$i]['name']}<br />\n";
                        }
                        $message .= "<br />Total price: {$data['order']['currency']['symbol']}{$data['order']['price']}<br /><br />\nBuyer Address: <br />\n" . $this->input->post('buyer_address');
                        $this->order_model->send_order_message($data['order']['id'], $data['order']['vendor']['user_name'], $subject, $message);

                        $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Your order has been placed. Once accepted you will be able to pay to the address.')));

                        redirect('purchases');
                    }
                }
            }
        }

        $data['orders'] = $this->order_model->buyer_orders();
        $this->_render($data['page'], $data);
    }

    /**
     * Buyer Orders
     * URI: /purchases
     *
     * Lists all purchases a buyer has made in the past. Required User Role: Buyer.
     * Also handles updating an order, placing/cancelling/finalizing an order.
     *
     * @return    void
     */
    public function buyer_orders()
    {
        $this->load->model('items_model');
        $this->load->model('review_auth_model');
        $this->load->model('shipping_costs_model');

        if ($this->input->post('submit_purchase') == 'Purchase') {
            if ($this->form_validation->run('submit_buyer_purchase') == TRUE) {
                // Process Form Submission
                $item_info = $this->items_model->get($this->input->post('item_hash'), FALSE);
                if ($item_info == FALSE) {
                    $this->current_user->set_return_message('Unable to find this item', FALSE);
                    redirect('items');
                }

                $shipping_costs = $this->shipping_costs_model->find_location_cost($item_info['id'], $this->current_user->location['id']);

                if ($shipping_costs == FALSE) {
                    $this->current_user->set_return_message('This item is not available in your location. Message the vendor to discuss availability.', FALSE);
                    redirect('item/' . $item_info['hash']);
                }

                $order = $this->order_model->load($item_info['vendor_hash'], '0');
                if ($order == FALSE) {
                    // New order; Need to create
                    $new_order = array('buyer_id' => $this->current_user->user_id,
                        'vendor_hash' => $item_info['vendor_hash'],
                        'items' => $item_info['hash'] . "-1",
                        'price' => $item_info['price_b'],
                        'currency' => '0');

                    $add = $this->order_model->add($new_order);
                    $message = (($add == TRUE) ? 'Your order has been created!' : 'Unable to add your order at this time, please try again later.');
                    $this->current_user->set_return_message($message, $add);

                } else {
                    // Already have order, update it
                    if ($order['progress'] == '0') {
                        $update = array('item_hash' => $item_hash,
                            'quantity' => '1');
                        $res = $this->order_model->update_items($order['id'], $update);
                        $message = (($res == TRUE) ? 'Your order has been updated.' : 'Unable to update your order at this time.');
                        $this->current_user->set_return_message($message, $res);
                    } else {
                        $this->current_user->set_return_message('Your order has already been created, please contact your vendor to discuss any further changes');
                    }
                }
                redirect('purchases');
            }
        }

        // Check if we are Proceeding an order, or Recounting it.
        if ($this->input->post('place_order') == 'Confirm' || $this->input->post('recount') == 'Update') {
            $submission = ($this->input->post('recount') == 'Update')
                ? 'recount'
                : 'place';

            $rule = ($submission == 'recount')
                ? 'submit_buyer_order_recount'
                : 'submit_buyer_order_place';

            if($this->form_validation->run($rule) == TRUE) {
                $id = ($submission == 'recount')
                                ? $this->input->post('recount_order_id')
                                : $this->input->post('place_order_id');

                // If the order cannot be loaded (progress == 0), redirect to Purchases page.
                $current_order = $this->order_model->load_order($id, array('0'));
                if ($current_order == FALSE) {
                    $this->current_user->set_return_message('Unable to find this order.', FALSE);
                    redirect('purchases');
                }

                // Loop through items in order, and update each.
                $list = $this->input->post('quantity');
                foreach ($list as $hash => $quantity) {
                    $item_info = $this->items_model->get($hash);
                    if ($item_info !== FALSE) {
                        $update = array('item_hash' => $hash,
                            'quantity' => $quantity);
                        $this->order_model->update_items($current_order['id'], $update, 'force');
                    }
                }

                // If the order is being placed, redirect to there.
                $url = ($submission == 'recount')
                    ? 'purchases'
                    : 'purchases/confirm/' . $current_order['id'];

                redirect($url);
            }
        }

        // Cancel order at progress 1.
        if($this->input->post('cancel_order') == 'Cancel') {
            if($this->form_validation->run('submit_buyer_cancel_order') == TRUE) {
                $current_order = $this->order_model->load_order($this->input->post('order_cancel_id'), array('1'));
                if ($current_order == FALSE){
                    $this->current_user->set_return_message('Order could not be found.',FALSE);
                    redirect('purchases');
                }

                if ($this->order_model->buyer_cancel($this->input->post('order_cancel_id')) == TRUE){
                    $this->current_user->set_return_message('This order has been cancelled.',FALSE);
                    redirect('purchases');
                }
            }
        }

        // Handle received upfront order
        if($this->input->post('received_upfront_order') == 'Received') {
            if($this->form_validation->run('submit_buyer_received_upfront_order') == TRUE) {
                $current_order = $this->order_model->load_order($this->input->post('received_upfront_order_id'), array('5'));
                if ($current_order == FALSE){
                    $this->current_user->set_return_message('That order could not be found!', FALSE);
                    redirect('purchases');
                }

                // Prevent escrow orders from being marked as 'received'.
                if ($current_order['vendor_selected_upfront'] == '0') {
                    $this->current_user->set_return_message('You must sign and broadcast the transaction to finalize the order', FALSE);
                    redirect('purchases');
                }

                if ($this->order_model->progress_order($this->input->post('received_upfront_order_id'), '5', '7', array('received_time' => time(), 'time'=>time())) == TRUE) {
                    $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Your order has been marked as received. Please leave feedback for this user!')));
                    redirect('purchases');
                }
            }
        }

        // Page Data
        // Load information about orders.
        $data['orders'] = $this->order_model->buyer_orders();

        // Load review auth tokens
        if ($data['orders'] !== FALSE) {
            $id_list = array();
            foreach ($data['orders'] as $t_order) {
                $id_list[] = $t_order['id'];
            }
            $data['review_auth'] = $this->review_auth_model->user_tokens_by_order($id_list);
        }

        $data['page'] = 'orders/order_list';
        $data['title'] = 'My Purchases';
        $this->_render($data['page'], $data);
    }

    // All users can view these pages

    /**
     * Raise a Dispute over an order.
     * User Role: Buyer/Vendor
     * URI: /order/dispute/$id or orders/dispute/$id
     *
     * Displays dispute initiation form if it's not already done, otherwise
     * shows details pages.
     *
     * @param    int $id
     * @return    void
     */
    public function dispute($id)
    {
        $list_page = ($this->current_user->user_role == 'Vendor') ? 'purchases' : 'orders';
        $data['dispute_page'] = ($this->current_user->user_role == 'Vendor') ? 'orders/dispute/' . $id : 'purchases/dispute/' . $id;
        $data['cancel_page'] = ($this->current_user->user_role == 'Vendor') ? 'orders' : 'purchases';

        if (!(is_numeric($id) && $id >= 0))
            redirect($data['cancel_page']);

        $data['current_order'] = $this->order_model->load_order($id, array('7', '6', '5', '4', '3'));
        if ($data['current_order'] == FALSE)
            redirect($list_page);

        $this->load->model('disputes_model');

        $data['dispute'] = $this->disputes_model->get_by_order_id($id);
        $data['disputing_user'] = ($data['dispute']['disputing_user_id'] == $data['current_order']['buyer']['id']) ? $data['current_order']['buyer'] : $data['current_order']['vendor'];
        $data['other_user'] = ($data['dispute']['other_user_id'] == $data['current_order']['buyer']['id']) ? $data['current_order']['buyer'] : $data['current_order']['vendor'];

        $data['form'] = TRUE; // Tell the view whether to display the create dispute form
        $data['post_update'] = TRUE; // Tell the view whether to display the post_update (depends on $record['final_response'])

        if ($data['dispute'] == FALSE && !in_array($data['current_order']['progress'], array('6', '7'))) {
            // Display form to allow user to raise a dispute.
            $data['role'] = strtolower($this->current_user->user_role);
            $data['other_role'] = ($data['role'] == 'vendor') ? 'buyer' : 'vendor';

            if ($this->form_validation->run('order_dispute') == TRUE) {

                $other_user = ($this->current_user->user_id == $data['current_order']['buyer']['id']) ? $data['current_order']['vendor']['id'] : $this->current_user->user_id;

                $dispute = array('disputing_user_id' => $this->current_user->user_id,
                    'dispute_message' => $this->input->post('dispute_message'),
                    'last_update' => time(),
                    'other_user_id' => $other_user,
                    'order_id' => $id);

                // Need to force the new_progress to 6 if the order is at 3 or 4.
                $new_progress = (in_array($data['current_order']['progress'], array('4', '3'))) ? '6' : '0'; // 0 means unset, default value.

                if ($this->disputes_model->create($dispute) == TRUE && $this->order_model->progress_order($id, $data['current_order']['progress'], $new_progress, array('disputed' => '1', 'disputed_time' => time())) == TRUE) {
                    // Send message to vendor
                    $info['from'] = $this->current_user->user_id;
                    $details = array('username' => $data['current_order'][$data['other_role']]['user_name'],
                        'subject' => "Dispute raised for Order #{$data['current_order']['id']}");
                    $details['message'] = "{$this->current_user->user_name} has made a dispute regarding Order #{$data['current_order']['id']}. Their issue has been outlined below. An administrator will contact you soon to discuss the issue, but you should contact the other party to try come to some resolution.<br /><br />\nDispute Reason:<br />\n" . $this->input->post('dispute_message') . "\n<br /><br />";
                    $message = $this->bw_messages->prepare_input($info, $details);
                    $message['order_id'] = $data['current_order']['id'];
                    $this->messages_model->send($message);

                    redirect($data['dispute_page']);
                } else {
                    $data['returnMessage'] = 'There was an error';
                }
            }
        } else {
            $data['form'] = FALSE;

            // If the message is updated:

            if ($data['dispute']['final_response'] == '0' && $this->input->post('post_dispute_message') == 'Post Message') {
                if ($this->form_validation->run('add_dispute_update') == TRUE) {
                    // Update the dispute record.
                    $update = array('posting_user_id' => $this->current_user->user_id,
                        'order_id' => $data['current_order']['id'],
                        'dispute_id' => $data['dispute']['id'],
                        'message' => $this->input->post('update_message'));
                    if ($this->disputes_model->post_dispute_update($update) == TRUE)
                        redirect($data['dispute_page']);
                }
            }

        }

        $data['page'] = 'orders/dispute';
        $data['title'] = 'Raise Dispute';
        $this->_render($data['page'], $data);
    }

    /**
     * Details
     * Buyer URI: purchases/details/<order_id>
     * Vendor URI: orders/details/<order_id>
     * Admin URI: admin/orders/<order_id>
     *
     * Order details page. Shows buyer/vendor/admin the details of the
     * the order.
     *
     * @param    int $order_id
     */
    public function details($order_id)
    {
        if (!(is_numeric($order_id) && $order_id >= 0))
            redirect('');

        $data['order'] = $this->order_model->get($order_id); // no restriction on buyer/vendor
        if ($data['order'] == FALSE)
            redirect('');

        // Work out if the user is allowed to view this order.
        if (!$this->current_user->user_role == 'Admin'
            && !($this->current_user->user_id == $data['order']['buyer']['id'])
            && !($this->current_user->user_id == $data['order']['vendor']['id'])
        )
            redirect('');
        // Only allow access when the order is confirmed by the buyer.
        if ($data['order']['progress'] == '0')
            redirect('');

        if ($this->current_user->user_role == 'Buyer') {
            $data['action_page'] = 'purchases/details/' . $order_id;
            $data['cancel_page'] = 'purchases';
        } else if ($this->current_user->user_role == 'Vendor') {
            $data['action_page'] = 'orders/details/' . $order_id;
            $data['cancel_page'] = 'orders';
        } else if ($this->current_user->user_role == 'Admin') {
            $data['action_page'] = 'admin/order/' . $order_id;
            $data['cancel_page'] = 'admin/orders';
        }

        $this->load->model('transaction_cache_model');
        $this->load->model('review_model');

        $data['display_form'] = FALSE;

        // This block works out if the 'input partially signed transaction'
        // form should be displayed. This happens if the partially signed
        // transaction is unset, and any of the following is true:
        // - The order is progress 3, and the user is the buyer
        // - The order is progress 4, escrow, and the role is the vendor.
        // - The order has been disputed, and anyone may sign.
        $data['display_form'] = (($data['order']['partially_signed_transaction'] == '' AND $data['order']['unsigned_transaction'] != '')
            AND ($data['order']['progress'] == '3' AND $this->current_user->user_role == 'Buyer'
                OR $data['order']['progress'] == '4' AND $data['order']['vendor_selected_escrow'] == '1' AND $this->current_user->user_role == 'Vendor'
                OR $data['order']['progress'] == '6'
                OR $data['order']['progress'] == '8'));

        if ($data['order']['partially_signed_transaction'] == '' && $data['order']['unsigned_transaction'] !== '')
            if ($data['order']['progress'] == '3' && $this->current_user->user_role == 'Buyer'
                || $data['order']['progress'] == '4' && $data['order']['vendor_selected_escrow'] == '1' && $this->current_user->user_role == 'Vendor'
                || $data['order']['progress'] == '6'
                || $data['order']['progress'] == '8'
            )
                $data['display_form'] = TRUE;

        // Only allow access to the form handling script if the form is allowed to be displayed.
        if ($data['display_form'] == TRUE && $this->input->post('submit_signed_transaction') == 'Submit Transaction') {
            if ($this->form_validation->run('input_transaction') == TRUE) {
                $validate = RawTransaction::validate_signed_transaction($this->input->post('partially_signed_transaction'), $data['order']['json_inputs']);

                if ($validate == FALSE) {
                    $data['invalid_transaction_error'] = 'Enter a valid partially signed transaction.';
                } else {
                    $decode = RawTransaction::decode($this->input->post('partially_signed_transaction'));
                    // Check that the outputs are acceptable.
                    $check = $this->transaction_cache_model->check_if_expected_spend($decode['vout']);
                    // $check will contain the order address if the vouts
                    // lead to the same unique hash we store when generating the transaction.
                    if ($check == $data['order']['address']) {
                        if ($data['order']['progress'] == '3') {
                            // Buyer must sign early before vendor dispatches.
                            $update = array('partially_signed_transaction' => $this->input->post('partially_signed_transaction'),
                                'partially_signing_user_id' => $this->current_user->user_id,
                                'partially_signed_time' => time());
                            $this->order_model->progress_order($order_id, '3', '4', $update);
                        } else if ($data['order']['progress'] == '4') {
                            // Vendor indicates they have dispatched.
                            $update = array('partially_signed_transaction' => $this->input->post('partially_signed_transaction'),
                                'partially_signing_user_id' => $this->current_user->user_id,
                                'partially_signed_time' => time(),
                                'dispatched_time' => time(),
                                'dispatched' => '1');
                            $this->order_model->progress_order($order_id, '4', '5', $update);
                        } else if ($data['order']['progress'] == '6') {
                            $update = array('partially_signed_transaction' => $this->input->post('partially_signed_transaction'),
                                'partially_signing_user_id' => $this->current_user->user_id,
                                'partially_signed_time' => time());
                            $this->order_model->update_order($order_id, $update);
                        } else if ($data['order']['progress'] == '8') {
                            $update = array('partially_signed_transaction' => $this->input->post('partially_signed_transaction'),
                                'partially_signing_user_id' => $this->current_user->user_id,
                                'partially_signed_time' => time());
                            $this->order_model->update_order($order_id, $update);
                        }
                        $this->session->set_flashdata('returnMessage', json_encode(array('message' => 'Your partially signed transaction has been saved!')));
                        redirect($data['action_page']);
                    } else {
                        $data['invalid_transaction_error'] = 'This transaction is invalid.';
                    }
                }
            }
        }

        // Only allow a vendor to refund, if the progress is either 3 or 4 (not yet dispatched)
        $data['can_refund'] = ($this->current_user->user_role == 'Vendor'
            AND in_array($data['order']['progress'], array('3', '4')));
        // Only allow a vendor to finalize early, if they are trusted (given admins definition),
        // if the progress is 4 (awaiting dispatch), and the order was initially escrow,
        // and they haven't already asked for the order to be finalized early.
        $data['can_finalize_early'] = ($this->current_user->user_role == 'Vendor'
            AND $this->review_model->decide_trusted_user($data['order'], 'vendor') == TRUE
            AND $data['order']['progress'] == '4'
            AND $data['order']['vendor_selected_escrow'] !== '0'
            AND $data['order']['vendor_selected_upfront'] == '0'
        );

        $data['addrs'] = array(BitcoinLib::public_key_to_address($data['order']['buyer_public_key'], $this->bw_config->currencies[0]['crypto_magic_byte']) => 'buyer',
            BitcoinLib::public_key_to_address($data['order']['vendor_public_key'], $this->bw_config->currencies[0]['crypto_magic_byte']) => 'vendor',
            BitcoinLib::public_key_to_address($data['order']['admin_public_key'], $this->bw_config->currencies[0]['crypto_magic_byte']) => 'admin');

        if (strlen($data['order']['partially_signed_transaction']) > 0) {
            $data['raw_tx'] = RawTransaction::decode($data['order']['partially_signed_transaction']);
            $data['signer'] = $this->accounts_model->get(array('id' => $data['order']['partially_signing_user_id']));
        } else if (strlen($data['order']['unsigned_transaction']) > 0) {
            $data['raw_tx'] = RawTransaction::decode($data['order']['unsigned_transaction']);
        }

        $checkStrangeAddress = function () use ($data) {
            $tx_addrs = array();
            foreach ($data['raw_tx']['vout'] as $vout) {
                $tx_addrs[] = $vout['scriptPubKey']['addresses'][0];
            }
            return count($tx_addrs) != count(array_intersect($tx_addrs, array_keys($data['addrs'])));
        };

        $data['strange_address'] = (isset($data['raw_tx'])) ? $checkStrangeAddress() : FALSE;

        $data['fees']['shipping_cost'] = $data['order']['shipping_costs'];
        $data['fees']['fee'] = $data['order']['fees'];
        $data['fees']['escrow_fees'] = $data['order']['extra_fees'];
        $data['fees']['total'] = $data['order']['shipping_costs'] + $data['order']['fees'];

        if ($this->current_user->user_role == 'Buyer'
            && ($data['order']['paid_time'] == '')
        ) {
            $this->load->library('ciqrcode');
            $data['payment_url'] = "bitcoin:{$data['order']['address']}?amount={$data['order']['order_price']}&message=Order+{$data['order']['id']}&label=Order+{$data['order']['id']}";
            $data['qr'] = $this->ciqrcode->generate_base64(array('data' => $data['payment_url']));
        }
        $data['page'] = 'orders/details';
        $data['title'] = 'Order Details: #' . $data['order']['id'];
        $this->_render($data['page'], $data);
    }

}

;

/* End of File: Orders.php */
/* Location: application/controllers/orders.php */

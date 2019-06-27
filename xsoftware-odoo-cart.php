<?php
/*
Plugin Name: XSoftware Cart Odoo
Description: Addon for XSoftware Cart on wordpress.
Version: 1.0
Author: Luca Gasperini
Author URI: https://xsoftware.it/
Text Domain: xsoftware_cart
*/

if(!defined("ABSPATH")) die;

if (!class_exists("xs_odoo_cart")) :

class xs_odoo_cart
{
        public function __construct()
        {
                add_action('add_meta_boxes', [$this, 'metaboxes']);
                add_action('save_post', [$this,'save_post'], 10, 2);
                add_filter('xs_cart_add', [$this, 'cart_add'], 10, 2);
                add_filter('xs_cart_approved', [$this, 'store_sale_order']);
                add_filter('xs_cart_sale_order', [$this, 'get_sale_order']);
                add_filter('xs_cart_item_price', [$this, 'item_price']);

                $this->options = get_option('xs_options_odoo');
        }

        function metaboxes()
        {
                add_meta_box(
                        'xs_cart_metaboxes',
                        'XSoftware Odoo Cart',
                        array($this,'metaboxes_print'),
                        ['xs_product'],
                        'advanced',
                        'high'
                );
        }

        function metaboxes_print($post)
        {
                $v = get_post_meta( $post->ID );

                $id = isset($v['xs_odoo_product_id'][0]) ? intval($v['xs_odoo_product_id'][0]) : '';

                $data[1][0] = 'Select Odoo product';
                $data[1][1] = xs_framework::create_select([
                        'name' => 'xs_odoo_product_id',
                        'selected' => $id,
                        'data' => $this->get_product_variant_list(),
                        'default' => 'Select an Odoo product'
                ]);

                xs_framework::create_table(array('data' => $data ));
        }

        function save_post($post_id, $post)
        {
                $post_type = get_post_type($post_id);

                if($post_type !== 'xs_product')
                        return;

                if(
                        isset($_POST['xs_odoo_product_id']) &&
                        !empty($_POST['xs_odoo_product_id'])
                ) {
                        update_post_meta(
                                $post_id,
                                'xs_odoo_product_id',
                                $_POST['xs_odoo_product_id']
                        );
                }
        }

        function item_price($post_id)
        {
                global $xs_odoo;
                $post_meta = get_post_meta($post_id);

                if(
                        !isset($post_meta['xs_odoo_product_id'][0]) ||
                        empty($post_meta['xs_odoo_product_id'][0])
                )
                        return '';

                $product_variant = intval($post_meta['xs_odoo_product_id'][0]);

                $price = $xs_odoo->search_read(
                        'product.product',
                        [
                                ['id', '=', $product_variant ]
                        ],
                        ['list_price']
                );
                return $price[0]['list_price'];
        }


        function get_sale_order($args)
        {
                $cart = $args['cart'];

                global $xs_odoo;
                $offset = array();

                if(
                        !isset($_SESSION['xs_cart_odoo']['sale_order']) ||
                        empty($_SESSION['xs_cart_odoo']['sale_order'])
                ) {

                        $partner_id = get_user_meta(get_current_user_id(), 'xs_odoo_partner_id');
                        /* Partner ID must be an integer! */
                        $partner_id = intval($partner_id[0]);
                        /* TODO: if xs_odoo_partner_id is not set? */
                        $payment_term_id = intval($this->options['cart']['payment_term']);
                        $str_validity_date = "+".$this->options['cart']['validity_days']." days";

                        $validity_date = date(DATE_ISO8601, strtotime($str_validity_date));

                        $sale_order_id = $xs_odoo->create(
                        'sale.order',
                        array(
                                array(
                                        'partner_id'=> $partner_id,
                                        'validity_date' => $validity_date,
                                        'payment_term_id' => $payment_term_id
                                )
                        ));

                        $sale_order_id = $sale_order_id[0];

                        $_SESSION['xs_cart_odoo']['sale_order'] = $sale_order_id;

                } else {

                        $sale_order_id = $_SESSION['xs_cart_odoo']['sale_order'];

                        $criteria = [
                        ['order_id', '=', $sale_order_id],
                        ];

                        $ids = $xs_odoo->search('sale.order.line', $criteria);

                        $tmp = $xs_odoo->unlink('sale.order.line', $ids);

                }


                foreach($cart as $id => $quantity) {
                        $post = get_post($id);
                        $post_meta = get_post_meta($id);

                        $product_variant = intval($post_meta['xs_odoo_product_id'][0]);

                        $tax_id = $xs_odoo->search_read(
                                'product.product',
                                [
                                        ['id', '=', $product_variant ]
                                ],
                                ['taxes_id']
                        );
                        $tax_id = $tax_id[0]['taxes_id'][0];

                        $line_id = $xs_odoo->create(
                        'sale.order.line',
                        array(
                                array(
                                        'product_id'=> $product_variant,
                                        'name'=>$post->post_title,
                                        'product_uom_qty'=>$quantity,
                                        'order_id' => $sale_order_id,
                                        'tax_id' => array(array(6,0,array($tax_id))),
                                        'discount' => $args['discount']
                                )
                        ));

                        $criteria = [
                        ['id', '=', $line_id],
                        ];

                        $ids = $xs_odoo->search('sale.order.line', $criteria);

                        $sale_order_line = $xs_odoo->read('sale.order.line', $ids, ['price_unit']);

                        $sale_order_line = $sale_order_line[0];


                        $item['id'] = $id;
                        $item['name'] = $post->post_title;
                        $item['quantity'] = $quantity;
                        $item['price'] = $sale_order_line['price_unit'];

                        $offset['items'][$id] = $item;
                }

                $criteria = [
                ['id', '=', $sale_order_id],
                ];

                $ids = $xs_odoo->search('sale.order', $criteria);

                $sale_order = $xs_odoo->read(
                        'sale.order',
                        $ids,
                        [
                                'amount_untaxed',
                                'amount_tax',
                                'amount_total'
                        ]
                );
                $sale_order = $sale_order[0];

                $offset['untaxed'] = $sale_order['amount_untaxed'];
                $offset['taxed'] = $sale_order['amount_tax'];
                $offset['total'] = $sale_order['amount_total'];

                return $offset;
        }

        function cart_add($id_item, $qt)
        {
                $meta = get_post_meta( $id_item );

                if(!isset($meta['xs_odoo_product_id'][0]))
                        return FALSE;
                if(empty($meta['xs_odoo_product_id'][0]))
                        return FALSE;

                $_SESSION['xs_cart'][$id_item] = $qt;
                return TRUE;
        }

        function store_sale_order($order_details)
        {
                global $xs_odoo;

                $payer_info = $order_details['payer']['payer_info'];

                $partner_id = get_user_meta(get_current_user_id(), 'xs_odoo_partner_id');
                $partner_id = intval($partner_id[0]);

                $partner_child = $xs_odoo->read('res.partner', $partner_id, ['child_ids']);


                $country_id = $xs_odoo->search(
                        'res.country',
                        [
                                ['code', '=', $payer_info['shipping_address']['country_code']]
                        ]
                );


                $user_info = [
                        'type' => 'invoice',
                        'name' => $payer_info['first_name'] . ' ' . $payer_info['last_name'],
                        'parent_id' => $partner_id,
                        'email' => $payer_info['email'],
                        'phone' => $payer_info['phone'],
                        'street' => $payer_info['shipping_address']['line1'],
                        'city' => $payer_info['shipping_address']['city'],
                        'zip' => $payer_info['shipping_address']['postal_code'],
                        'country_id' => $country_id[0]
                ];

                $fields = array_keys($user_info);
                $fields[] = 'id';

                $childs = $xs_odoo->read(
                        'res.partner',
                        $partner_child[0]['child_ids'],
                        $fields
                );

                $child_partner = FALSE;

                foreach($childs as $values) {
                        $c = FALSE;
                        foreach($user_info as $key => $v)
                                if($key === 'country_id')
                                        $current = $values[$key][0];
                                else
                                        $current = $values[$key];

                                if($current !== $v) {
                                        $c = TRUE;
                                        break;
                                }
                        if(!$c) {
                                $child_partner = $values['id'];
                                break;
                        }
                 }

                if($child_partner === FALSE) {
                        $child_partner = $xs_odoo->create(
                                'res.partner',
                                $user_info
                        );
                }

                $res = $xs_odoo->write(
                        'sale.order',
                        $_SESSION['xs_cart_odoo']['sale_order'],
                        [
                                'partner_id' => $child_partner,
                                'state' => 'sale'
                        ]
                );

                $invoice_id = $xs_odoo->command(
                        'sale.order',
                        'action_invoice_create',
                        [$_SESSION['xs_cart_odoo']['sale_order']]
                );

                $res = $xs_odoo->command(
                        'account.invoice',
                        'action_invoice_open',
                        $invoice_id
                );

                $journal_id = intval($this->options['cart']['journal']);
                $payment_date = date(DATE_ISO8601, strtotime('now'));
                $amount = $order_details['transactions'][0]['amount']['total'];

                $payment_id = $xs_odoo->create(
                        'account.payment',
                        [
                                'payment_date' => $payment_date,
                                'has_invoices' => true,
                                'invoice_ids' => [[6,0,$invoice_id]],
                                'amount' => $amount,
                                'payment_method_id' => 3,
                                'communication' => $order_details['id'],
                                'payment_reference' => $order_details['id'],
                                'journal_id' => $journal_id,
                                'partner_id' => $child_partner,
                                'payment_type' => 'inbound',
                                'partner_type' => 'customer'
                        ]
                );

                $res = $xs_odoo->command(
                        'account.payment',
                        'action_validate_invoice_payment',
                        $payment_id
                );

                $xs_odoo->write(
                        'account.payment',
                        $payment_id,
                        [ 'state' => 'reconciled' ]
                );

                if(isset($order_details['amount_fees']) && !empty($order_details['amount_fees'])) {

                        $partner_fees = intval($this->options['cart']['partner_fees']);

                        $payment_id = $xs_odoo->create(
                                'account.payment',
                                [
                                        'payment_date' => $payment_date,
                                        'amount' => $order_details['amount_fees'],
                                        'payment_method_id' => 3,
                                        'communication' => $order_details['id'],
                                        'payment_reference' => $order_details['id'],
                                        'journal_id' => $journal_id,
                                        'partner_id' => $partner_fees,
                                        'payment_type' => 'outbound',
                                        'partner_type' => 'supplier'
                                ]
                        );
                        if($this->options['cart']['confirm_fees']) {
                                $res = $xs_odoo->command(
                                        'account.payment',
                                        'post',
                                        $payment_id
                                );
                        }
                }

                unset($_SESSION['xs_cart_odoo']);
        }

        function get_product_variant_list()
        {
                global $xs_odoo;

                $offset = $xs_odoo->search_read('product.product', [], ['name']);

                foreach($offset as $n => $list) {
                        $return[$list['id']] = $list['name'];
                }


                return $return;
        }
}

endif;

$xs_odoo_cart = new xs_odoo_cart();

?>
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
                add_filter('xs_cart_approved', [$this, 'create_invoice']);
                add_filter('xs_cart_sale_order', [$this, 'get_sale_order']);
                add_filter('xs_cart_item_price', [$this, 'item_price']);
                add_filter('xs_cart_invoice_pdf', [$this, 'create_invoice_pdf']);
                add_filter('xs_cart_get_invoice', [$this, 'get_invoice_pdf']);
                add_filter('xs_cart_list_invoice', [$this, 'list_invoice']);

                $this->options = get_option('xs_options_odoo');
        }

        function metaboxes()
        {
                add_meta_box(
                        'xs_cart_metaboxes',
                        'XSoftware Odoo Cart',
                        [$this,'metaboxes_print'],
                        ['xs_product'],
                        'advanced',
                        'high'
                );
        }

        function metaboxes_print($post)
        {
                $v = get_post_meta( $post->ID );

                $id = isset($v['xs_odoo_product_id'][0]) ? intval($v['xs_odoo_product_id'][0]) : '';

                $variant_list = $this->get_product_variant_list();
                $variant_list[0] = 'Empty'; /* Empty value */

                $data[1][0] = 'Select Odoo product';
                $data[1][1] = xs_framework::create_select([
                        'name' => 'xs_odoo_product_id',
                        'selected' => $id,
                        'data' => $variant_list,
                        'default' => 'Select an Odoo product'
                ]);

                xs_framework::create_table(['data' => $data]);
        }

        function save_post($post_id, $post)
        {
                $post_type = get_post_type($post_id);

                if($post_type !== 'xs_product')
                        return;

                if(isset($_POST['xs_odoo_product_id'])) {
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
                        ['list_price', 'currency_id']
                );
                $currency = $xs_odoo->read(
                        'res.currency',
                        [
                                $price[0]['currency_id'][0]
                        ],
                        ['name', 'symbol']
                );

                $output = [
                        'price' => $price[0]['list_price'],
                        'currency' => $currency[0]['name'],
                        'currency_symbol' => $currency[0]['symbol']
                ];

                return $output;
        }


        function get_sale_order($args)
        {
                $cart = $args['cart'];

                global $xs_odoo;
                $o = array();

                if(
                        !isset($_SESSION['xs_cart_odoo']['sale_order']) ||
                        empty($_SESSION['xs_cart_odoo']['sale_order'])
                ) {
                        $partner_id = $this->get_partner_id(get_current_user_id());
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

                        $sale_order_line = $xs_odoo->search_read(
                                'sale.order.line',
                                [
                                        ['id', '=', $line_id],
                                ]
                        );

                        $sale_order_line = $sale_order_line[0];

                        $item['id'] = $id;
                        $item['name'] = $sale_order_line['name'];
                        $item['quantity'] = $quantity;
                        $item['price'] = $sale_order_line['price_unit'];
                        $item['subtotal'] = $sale_order_line['price_subtotal'];
                        $item['discount'] = $args['discount'];
                        $item['tax'] = $sale_order_line['price_tax'];

                        $item['tax_code'] = '';
                        $taxes_ids = array();

                        foreach($sale_order_line['tax_id'] as $taxes) {
                                $taxes_ids[] = $taxes;
                        }

                        $tax_list = $xs_odoo->read(
                                'account.tax',
                                $taxes_ids,
                                ['description']
                        );

                        foreach($tax_list as $tax) {
                                $item['tax_code'] .= $tax['description'] . ' ';
                        }

                        $item['tax_code'] = trim($item['tax_code']);


                        $o['items'][] = $item;
                }

                $criteria = [
                ['id', '=', $sale_order_id],
                ];

                $ids = $xs_odoo->search('sale.order', $criteria);

                $sale_order = $xs_odoo->read(
                        'sale.order',
                        $ids
                );

                $sale_order = $sale_order[0];

                $currency = $xs_odoo->read(
                        'res.currency',
                        [
                                $sale_order['currency_id'][0]
                        ],
                        ['name', 'symbol']
                );

                $o['sale_order'] = [
                        'name' => $sale_order['name'],
                        'date_order' => $sale_order['date_order'],
                        'validity_date' => $sale_order['validity_date'],
                ];

                $o['transaction'] = [
                        'currency' => $currency[0]['name'],
                        'subtotal' => $sale_order['amount_untaxed'],
                        'tax' => $sale_order['amount_tax'],
                        'total' => $sale_order['amount_total'],
                        'currency_symbol' => $currency[0]['symbol'],
                        'undiscounted' => $sale_order['amount_undiscounted']
                ];

                return $o;
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

        function create_invoice($info)
        {
                global $xs_odoo;

                $partner_id = $this->get_partner_id(get_current_user_id());

                $partner_child = $xs_odoo->read('res.partner', $partner_id, ['child_ids']);


                $country_id = $xs_odoo->search(
                        'res.country',
                        [
                                ['code', '=', $info['shipping_address']['country_code']]
                        ]
                );

                $user_info = [
                        'type' => 'invoice',
                        'name' => $info['payer']['first_name'] . ' ' . $info['payer']['last_name'],
                        'parent_id' => $partner_id,
                        'email' => $info['payer']['email'],
                        'phone' => $info['payer']['phone'],
                        'street' => $info['shipping_address']['line1'],
                        'city' => $info['shipping_address']['city'],
                        'zip' => $info['shipping_address']['zip'],
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
                $amount = $info['transaction']['total'];

                $payment_id = $xs_odoo->create(
                        'account.payment',
                        [
                                'payment_date' => $payment_date,
                                'has_invoices' => true,
                                'invoice_ids' => [[6,0,$invoice_id]],
                                'amount' => $amount,
                                'payment_method_id' => 3,
                                'communication' => $info['payment']['id'],
                                'payment_reference' => $info['payment']['id'],
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

                if(isset($info['transaction']['fee']) && !empty($info['transaction']['fee'])) {

                        $partner_fees = intval($this->options['cart']['partner_fees']);

                        $payment_id = $xs_odoo->create(
                                'account.payment',
                                [
                                        'payment_date' => $payment_date,
                                        'amount' => $info['transaction']['fee'],
                                        'payment_method_id' => 3,
                                        'communication' => $info['payment']['id'],
                                        'payment_reference' => $info['payment']['id'],
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

                $invoice = $xs_odoo->read(
                        'account.invoice',
                        $invoice_id
                );
                $invoice = $invoice[0];

                $info['invoice'] = [
                        'id' => $invoice['id'],
                        'name' => $invoice['display_name'],
                        'origin' => $invoice['origin'],
                        'reference' => $invoice['reference'],
                        'date_invoice' => $invoice['date_invoice'],
                        'date_due' => $invoice['date_due'],
                        'date' => $invoice['date'],
                ];

                foreach($invoice['invoice_line_ids'] as $id) {
                        $invoice_lines_ids[] = $id;
                }

                $invoice_lines = $xs_odoo->read(
                        'account.invoice.line',
                        $invoice_lines_ids
                );

                unset($info['items']);

                foreach($invoice_lines as $item) {
                        $tmp = array();
                        $tmp['name'] = $item['name'];
                        $tmp['price'] = $item['price_unit'];
                        $tmp['tax'] = $item['price_tax'];

                        $tmp['tax_code'] = '';
                        $taxes_ids = array();

                        foreach($item['invoice_line_tax_ids'] as $taxes) {
                                $taxes_ids[] = $taxes;
                        }

                        $tax_list = $xs_odoo->read(
                                'account.tax',
                                $taxes_ids,
                                ['description']
                        );

                        foreach($tax_list as $tax) {
                                $tmp['tax_code'] .= $tax['description'] . ' ';
                        }

                        $tmp['tax_code'] = trim($tmp['tax_code']);

                        $tmp['quantity'] = $item['quantity'];
                        $tmp['discount'] = $item['discount'];
                        $tmp['subtotal'] = $item['price_subtotal'];

                        $info['items'][] = $tmp;
                }

                $currency = $xs_odoo->read(
                        'res.currency',
                        [
                                $invoice['currency_id'][0]
                        ],
                        ['symbol']
                );


                $info['transaction']['currency_symbol'] = $currency[0]['symbol'];

                $company = $xs_odoo->read(
                        'res.company',
                        [
                                $invoice['company_id'][0]
                        ]
                );

                $company = $company[0];

                $info['company'] = [
                        'name' => $company['name'],
                        'logo' => $company['logo'],
                        'phone' => $company['phone'],
                        'website' => $company['website'],
                        'email' => $company['email'],
                ];

                $country_code = $xs_odoo->read(
                        'res.country',
                        [
                                $company['country_id'][0]
                        ],
                        [
                                'code'
                        ]
                );

                $country_code = $country_code[0];

                $state_code = $xs_odoo->read(
                        'res.country.state',
                        [
                                $company['state_id'][0]
                        ],
                        [
                                'code'
                        ]
                );

                $state_code = $state_code[0];

                $info['company_address'] = [
                        'recipient_name' => $company['name'],
                        'line1' => $company['street'],
                        'city' => $company['city'],
                        'state_code' => $state_code['code'],
                        'zip' => $company['zip'],
                        'country_code' => $country_code['code']
                ];

                return $info;
        }

        function create_invoice_pdf($info)
        {
                global $xs_odoo;

                $xs_odoo->report_pdf(
                        intval($this->options['cart']['invoice_report_id']),
                        $info['invoice']['id']
                );

                $pdf = $xs_odoo->search_read(
                        'ir.attachment',
                        [
                                ['res_model', '=', 'account.invoice'],
                                ['res_id', '=', $info['invoice']['id']]
                        ],
                        [
                                'datas',
                                'mimetype',
                                'name',
                                'datas_fname',
                                'checksum'
                        ]
                );
                $pdf = $pdf[0];

                $info['pdf'] = [
                        'base64' => $pdf['datas'],
                        'name' => $pdf['name'],
                        'mimetype' => $pdf['mimetype'],
                        'filename' => $pdf['datas_fname'],
                        'checksum' => $pdf['checksum'],
                ];

                return $info;
        }

        function get_invoice_pdf($invoice_id)
        {
                global $xs_odoo;

                $invoice = $xs_odoo->read(
                        'account.invoice',
                        [
                                $invoice_id
                        ],
                        [
                                'id',
                                'display_name',
                                'origin',
                                'reference',
                                'date_invoice',
                                'date_due',
                                'date',
                                'partner_id'
                        ]
                );

                $invoice = $invoice[0];

                $info['invoice'] = [
                        'id' => $invoice['id'],
                        'name' => $invoice['display_name'],
                        'origin' => $invoice['origin'],
                        'reference' => $invoice['reference'],
                        'date_invoice' => $invoice['date_invoice'],
                        'date_due' => $invoice['date_due'],
                        'date' => $invoice['date'],
                ];

                $user_partner = $invoice['partner_id'][0];

                $parent = $xs_odoo->read(
                        'res.partner',
                        [
                                $user_partner
                        ],
                        [
                                'parent_id',
                                'name',
                                'phone',
                                'email',
                                'country_id'
                        ]
                );

                $parent = $parent[0];

                $country_code = $xs_odoo->read(
                        'res.country',
                        [
                                $parent['country_id'][0]
                        ],
                        [
                                'code'
                        ]
                );

                $info['payer'] = [
                        'name' => $parent['name'],
                        'email' => $parent['email'],
                        'phone' => $parent['phone'],
                        'country_code' => $country_code[0]['code'],
                ];

                $user_partner = $parent['parent_id'][0];

                $partner_id = $this->get_partner_id(get_current_user_id());

                if(xs_framework::has_user_role('administrator')) {
                        $partner_id = $user_partner;
                }

                if($user_partner !== $partner_id) {
                        return 1;
                }

                $pdf = $xs_odoo->search_read(
                        'ir.attachment',
                        [
                                ['res_model', '=', 'account.invoice'],
                                ['res_id', '=', $info['invoice']['id']]
                        ],
                        [
                                'datas',
                                'mimetype',
                                'name',
                                'datas_fname',
                                'checksum'
                        ]
                );
                $pdf = $pdf[0];

                $info['pdf'] = [
                        'base64' => $pdf['datas'],
                        'name' => $pdf['name'],
                        'mimetype' => $pdf['mimetype'],
                        'filename' => $pdf['datas_fname'],
                        'checksum' => $pdf['checksum'],
                ];

                return $info;
        }

        function list_invoice($user_id)
        {
                global $xs_odoo;

                $parent_id = $this->get_partner_id($user_id);

                $parent = $xs_odoo->read(
                        'res.partner',
                        [
                                $parent_id
                        ],
                        [
                                'child_ids'
                        ]
                );

                $partner_list_ids = $parent[0]['child_ids'];
                $partner_list_ids[] = $parent_id;

                $partner_list = $xs_odoo->read(
                        'res.partner',
                        $partner_list_ids,
                        [
                                'id',
                                'name',
                                'phone',
                                'email',
                                'country_id'
                        ]
                );

                $payer_list = array();

                foreach($partner_list as $partner) {

                        if(!empty($partner['country_id'][0])) {
                                $country_code = $xs_odoo->read(
                                        'res.country',
                                        [
                                                $partner['country_id'][0]
                                        ],
                                        [
                                                'code'
                                        ]
                                );
                                $country_code = $country_code[0]['code'];
                        } else {
                                $country_code = NULL;
                        }

                        $payer_list[$partner['id']] = [
                                'name' => $partner['name'],
                                'email' => $partner['email'],
                                'phone' => $partner['phone'],
                                'country_code' => $country_code,
                        ];
                }


                /* Create a criteria in polish notation */
                $criteria = array();
                $polish_count = count($payer_list) - 2;
                $index = 0;

                foreach($payer_list as $id => $payer) {
                        if($polish_count >= $index)
                                $criteria[] = '|';
                        $criteria[] = ['partner_id', '=', $id];

                        $index = $index + 1;
                }


                $invoice_list = $xs_odoo->search_read(
                        'account.invoice',
                        $criteria,
                        [
                                'id',
                                'display_name',
                                'origin',
                                'reference',
                                'date_invoice',
                                'date_due',
                                'date',
                                'partner_id',
                                'currency_id',
                                'amount_untaxed',
                                'amount_tax',
                                'amount_total'
                        ]
                );

                $info = array();

                foreach($invoice_list as $invoice) {
                        $tmp['invoice'] = [
                                'id' => $invoice['id'],
                                'name' => $invoice['display_name'],
                                'origin' => $invoice['origin'],
                                'reference' => $invoice['reference'],
                                'date_invoice' => $invoice['date_invoice'],
                                'date_due' => $invoice['date_due'],
                                'date' => $invoice['date'],
                        ];
                        $tmp['payer'] = $payer_list[$invoice['partner_id'][0]];

                        $currency = $xs_odoo->read(
                                'res.currency',
                                [
                                        $invoice['currency_id'][0]
                                ],
                                ['name', 'symbol']
                        );


                        $tmp['transaction'] = [
                                'currency' => $currency[0]['name'],
                                'subtotal' => $invoice['amount_untaxed'],
                                'tax' => $invoice['amount_tax'],
                                'total' => $invoice['amount_total'],
                                'currency_symbol' => $currency[0]['symbol']
                        ];

                        $info[] = $tmp;
                }

                return $info;
        }

        function get_partner_id($user_id)
        {
                $partner_id = get_user_meta($user_id, 'xs_odoo_partner_id');

                if(!empty($partner_id[0]))
                        $partner_id = $partner_id[0];
                else if(xs_framework::has_user_role('administrator'))
                        $partner_id = $this->options['cart']['fallback_user'];
                else
                        exit;

                /* Partner ID must be an integer! */
                $partner_id = intval($partner_id);

                return $partner_id;
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

$xs_odoo_cart = new xs_odoo_cart();

endif;

?>
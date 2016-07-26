<?php
/**
 * @author    G2A Team
 * @copyright Copyright (c) 2016 G2A.COM
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

// Load WP_List_Table if not loaded
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class G2APayPaymentHistoryTable extends WP_List_Table
{
    public $per_page = 30;
    public $total_count;

    /**
     * G2APayPaymentHistoryTable constructor.
     */
    public function __construct()
    {
        parent::__construct([
            'singular' => 'g2apay_payment_history_link',
            'plural'   => 'g2apay_payment_history_links',
            'ajax'     => false,
        ]);
    }

    /**
     * @return array
     */
    public function get_columns()
    {
        return array(
            'id'              => __('Id', 'easy-digital-downloads'),
            'order_id'        => __('Payment Id', 'easy-digital-downloads'),
            'transaction_id'  => __('G2A Pay transaction id', 'easy-digital-downloads'),
            'status'          => __('Status', 'easy-digital-downloads'),
            'amount_paid'     => __('Amount paid via G2A Pay', 'easy-digital-downloads'),
            'amount_refunded' => __('Amount refunded via G2A Pay', 'easy-digital-downloads'),
            'action'          => __('Action', 'easy-digital-downloads'),
        );
    }

    /**
     * @return array|null|object
     */
    public function g2apay_payments_data()
    {
        global $wpdb;

        $per_page = $this->per_page;
        $orderby  = isset($_GET['orderby']) ? $_GET['orderby'] : 'id';
        $order    = isset($_GET['order']) ? $_GET['order'] : 'ASC';
        $page     = isset($_GET['paged']) ? (int) $_GET['paged'] : null;
        $limit    = ($page && $page > 1) ? $per_page * ($page - 1) . ',' . $per_page : $per_page;

        $query = $wpdb->prepare('SELECT * FROM ' . G2APayHelper::G2APAY_TRANSACTION_TABLE_NAME . ' ORDER BY '
            . '%s' . ' ' . '%s', $orderby, $order);

        $this->total_count = $wpdb->query($query);

        $query .= ' LIMIT ' . $limit;

        $data = $wpdb->get_results($query);

        return $data;
    }

    /**
     * Prepare G2A Pay payment history table.
     */
    public function prepare_items()
    {
        $columns  = $this->get_columns();
        $hidden   = array(); // No hidden columns
        $sortable = array();
        $data     = $this->g2apay_payments_data();

        $this->_column_headers = array($columns, $hidden, $sortable);

        $this->items = $data;

        $this->set_pagination_args([
                'total_items' => $this->total_count,
                'per_page'    => $this->per_page,
                'total_pages' => ceil($this->total_count / $this->per_page),
            ]
        );
    }

    /**
     * @param object $item
     * @param string $column_name
     * @return string
     */
    public function column_default($item, $column_name)
    {
        switch ($column_name) {
            case 'id':
            case 'order_id':
            case 'transaction_id':
            case 'status':
                return $item->$column_name;
            case 'amount_paid':
            case 'amount_refunded':
                return G2APayHelper::getValidAmount($item->$column_name);
            case 'action':
                return $this->row_actions(['Refund' => '#'], $item->order_id, true);
            default:
                return;
        }
    }

    /**
     * @param array $actions
     * @param bool $order_id
     * @param bool $always_visible
     * @return string|void
     */
    protected function row_actions($actions, $order_id, $always_visible = false)
    {
        if (!count($actions)) {
            return;
        }

        $out = '';
        foreach ($actions as $action => $link) {
            $out .= '<form id="refund" action="' . $link . '" method="post">';
            $out .= '<input type="hidden" id="order_id" name="order_id" value="' . $order_id . '"/>';
            $out .= '<input type="submit" value="' . $action . '" />';
            $out .= '</form>';

            return $out;
        }
    }
}

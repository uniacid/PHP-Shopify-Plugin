<?php

class Shopify
{
    public $api_key;
    public $password;
    public $secret;
    public $debug = false;
    public $development_mode = 'false';
    public $method = null;
    public $request_headers = null;
    public $query = null;
    public $post_data = array();
    public $base_url;

    private $api_url = null;
    private $url = null;
    private $api_extension = '';
    private $request;
    private $result;
    private $last_response_headers = null;

    public function __construct()
    {
        parent::__construct();  //Can be exnteded
        $this->api_url = 'https://'.$this->api_key.':'.$this->password.'@'.$this->base_url; //Set API Info
    }

    public function submit()
    {
        $this->result = new stdClass();
        $this->result->success = false;
        $this->result->status = 0; //0 failed 1 success
        $this->result->error = '';
        $this->result->data = null;

        $ch = curl_init(); //Initialize CURL
        if ( !empty($this->query) ) { // set url
            if ( is_array($this->query) )
                $this->url = $this->api_url.'/'.$this->api_extension.'?'.http_build_query($this->query);
            else
                $this->url = $this->api_url.'/'.$this->api_extension.'?'.$this->query;
        }
        else
            $this->url = $this->api_url.'/'.$this->api_extension;

        curl_setopt($ch, CURLOPT_URL, $this->url); //API post url
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);// allow redirects
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); //return var
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'shopify-php-api-client');
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method); // set method

        // set post_data
        if ( $this->method != 'GET' && !empty($this->post_data) )
        {
            if( in_array($this->method, array('POST','PUT') ) )
            {
                $this->post_data = stripslashes(json_encode($this->post_data));
                $this->request_headers = array("Accept: application/json","Content-Type: application/json; charset=utf-8", 'Expect:');
            }

            if ( is_array($this->post_data) )
                $this->post_data = http_build_query($this->post_data);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->post_data);
        }

        if ( !empty($this->request_headers) )
            curl_setopt($ch, CURLOPT_HTTPHEADER, $this->request_headers);

        // submit request
        $response = curl_exec($ch);
        $info = curl_getinfo($ch);

        if ( !empty($response) )
        {
            list($message_headers, $message_body) = preg_split("/\r\n\r\n|\n\n|\r\r/", $response, 2);
            $this->result->response_headers = $this->_parseHeaders($message_headers);
            $this->last_response_headers = $this->result->response_headers;

            if ( $this->last_response_headers['http_status_code'] >= '200' && $this->last_response_headers['http_status_code'] <= '206' )   // HTTP Success Code
            {
                $this->result->callLimit = $this->callLimit();
                $this->result->callsMade = $this->callsMade();
                $this->result->callsLeft = $this->callsLeft();
                $this->result->success = true;
                $this->result->status = $this->last_response_headers['http_status_message'];
                $this->result->data = json_decode($message_body, true);
        } else {                                                                                                                            // HTTP Failed
                $this->result->message = curl_error($ch);
                $this->result->error = curl_error($ch);
                $this->result->error_number = curl_errno($ch);
                $this->result->success = false;
                $this->result->status = $this->last_response_headers['http_status_message'];
                $this->result->error = json_decode($message_body, true);
            }
        }

        curl_close($ch); //Close CURL

        if($this->debug) {
            FB::log($response);
            FB::log($info);
            print_r($info);
            print_r($response);
        };

        return $this->result;
    }

    /*
     * Get list of all  published products
     */
    public function get_products()
    {
        $this->api_extension = 'products.json';
        $this->method = 'GET';
        $this->query = array('published_status'=>'published');
//        $this->debug = true;

        return $this->submit();
    }

    /*
     * Find customer(s) either using and/or combining the $name and $filters variables
     * @var $name (string) Customer Name (can be full/first/last name)
     * @var $filters (array) Array of filters to query against customer fields
     */
    public function findCustomer($name=null, $filters=null)
    {
        $this->api_extension = 'customers/search.json';
        $this->method = 'GET';
        $this->query = 'query=';
        $queryFilters = '';

        if( is_object($filters) ) {
            $filters = obj2array($filters);
        }

        if ( !empty($name) )
            $this->query .= $name;

        if ( is_array($filters) )
        {
            foreach($filters as $field => $filter)
                $queryFilters .= $field.':'.$filter.' ';

            $this->query .= rawurlencode($queryFilters);
        }
        else
            $this->query .= $filters;

        return $this->submit();
    }

    /*
     * Returns All Customers can be used with filters var to define limits
     * @var $filters (array) Options: {'since_id','created_at_min','created_at_max','updated_at_min','updated_at_max',
     * 'limit','page','fields'}
     */
    public function findAllCustomers($filters=null)
    {
        $this->api_extension = 'customers.json';
        $this->method = 'GET';
        $this->query = '';
        $queryFilters = '';

        if( !empty($filters) )
        {
            if( is_object($filters) )
                $filters = obj2array($filters);

            if ( is_array($filters) )
            {
                foreach($filters as $field => $filter)
                    $queryFilters .= $field.'='.$filter.'&';

                $this->query .= $queryFilters;
            }
            else
                $this->query .= $filters;
        }

        return $this->submit();
    }

    /*
     * Find Customer by Shopify ID
     * @var $customerID (int) - Shopify Customer ID
     */
    public function findByCustomerID($customerID)
    {
        $this->api_extension = 'customers/'.$customerID.'.json';
        $this->method = 'GET';

        return $this->submit();
    }

    /*
     * Create New Shopify Customer
     * @var $customerData (array)
     */
    public function createNewCustomer($customerData=null)
    {
        $this->api_extension = 'customers.json';
        $this->method = 'POST';

        if ( !empty($customerData) )
        {
            $this->post_data = $customerData;
        }

        return $this->submit();
    }

    /*
     * Parse returned headers to be readable and usable
     * @var $message_headers (string)
     */
    private function _parseHeaders($message_headers)
    {
        $header_lines = preg_split("/\r\n|\n|\r/", $message_headers);
        $headers = array();
        list(, $headers['http_status_code'], $headers['http_status_message']) = explode(' ', trim( array_shift($header_lines) ), 3);
        foreach ($header_lines as $header_line)
        {
            list($name, $value) = explode(':', $header_line, 2);
            $name = strtolower($name);
            $headers[$name] = trim($value);
        }

        return $headers;
    }

    /*
     * Return number of API calls made to Shopify
     */
    public function callsMade()
    {
        return $this->shopApiCallLimitParam(0);
    }

    /*
     * Return max limit of API calls from Shopify
     */
    public function callLimit()
    {
        return $this->shopApiCallLimitParam(1);
    }

    /*
     * Return number of API calls remaining
     */
    public function callsLeft()
    {
        return $this->callLimit() - $this->callsMade();
    }

    /*
     * Return number of API calls made @private
     */
    private function shopApiCallLimitParam($index)
    {
        if ($this->last_response_headers == null)
            return 0;

        $params = explode('/', $this->last_response_headers['http_x_shopify_shop_api_call_limit']);
        return (int) $params[$index];
    }

}

?>

<?php

/*
Plugin Name: XSoftware Odoo Connector
Description: Odoo management on wordpress.
Version: 1.0
Author: Luca Gasperini
Author URI: https://xsoftware.it/
Text Domain: xsoftware_odoo_connector
*/

if(!defined("ABSPATH")) die;

include 'vendor/autoload.php';
include 'xsoftware-odoo-options.php';
include 'xsoftware-odoo-users.php';
include 'xsoftware-odoo-cart.php';

use Ripcord\Ripcord;


if (!class_exists("xs_odoo_connector")) :

class xs_odoo
{

        private $options = array( );

        	/**
 * Host to connect to
	 *
	 * @var string
	 */
	public $host;

	/**
	 * Unique identifier for current user
	 *
	 * @var integer
	 */
	protected $uid;

	/**
	 * Current users username
	 *
	 * @var string
	 */
	protected $user;

	/**
	 * Current database
	 *
	 * @var string
	 */
	protected $database;

	/**
	 * Password for current user
	 *
	 * @var string
	 */
	protected $password;

	/**
	 * Ripcord Client
	 *
	 * @var Client
	 */
	protected $client;

	/**
	 * XmlRpc endpoint
	 *
	 * @var string
	 */
	protected $path;

        function __construct()
        {
                $this->options = get_option('xs_options_odoo');

                $this->host = $this->options['conn']['url'];
                $this->database = $this->options['conn']['db'];
                $this->user = $this->options['conn']['mail'];
                $this->password = $this->options['conn']['pass'];

        }

	/**
	 * Get version
	 *
	 * @return array Odoo version
	 */
	public function version()
	{
		$response = $this->getClient('common')->version();

		return $response;
	}

	/**
	 * Search models
	 *
	 * @param string  $model    Model
	 * @param array   $criteria Array of criteria
	 * @param integer $offset   Offset
	 * @param integer $limit    Max results
	 *
	 * @return array Array of model id's
	 */
	public function search($model, $criteria = array(), $offset = 0, $limit = 100, $order = '')
	{
		$response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            'search',
            [$criteria],
            ['offset'=>$offset, 'limit'=>$limit, 'order' => $order]
        );

		return $response;
	}

	/**
	 * Search_count models
	 *
	 * @param string  $model    Model
	 * @param array   $criteria Array of criteria
	 *
	 * @return array Array of model id's
	 */
	public function search_count($model, $criteria = array())
	{
		$response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            'search_count',
            [$criteria]
        );

		return $response;
	}

	/**
	 * Read model(s)
	 *
	 * @param string $model  Model
	 * @param array  $ids    Array of model id's
	 * @param array  $fields Index array of fields to fetch, an empty array fetches all fields
	 *
	 * @return array An array of models
	 */
	public function read($model, $ids, $fields = array())
	{

        $response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            'read',
            [$ids],
            ['fields'=>$fields]
        );

		return $response;
	}

	/**
	 * Search and Read model(s)
	 *
	 * @param string $model     Model
     * @param array  $criteria  Array of criteria
	 * @param array  $fields    Index array of fields to fetch, an empty array fetches all
fields
     * @param integer $limit    Max results
	 *
	 * @return array An array of models
	 */
	public function search_read($model, $criteria = [], $fields = [], $limit=100, $order = '')
	{
        $response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            'search_read',
            [$criteria],
            ['fields'=>$fields,'limit'=>$limit, 'order' => $order]
        );

		return $response;
	}

    /**
   	 * Create model
   	 *
   	 * @param string $model Model
   	 * @param array  $data  Array of fields with data (format: ['field' => 'value'])
   	 *
   	 * @return integer Created model id
   	 */
   	public function create($model, $data)
   	{
        $response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            'create',
            [$data]
        );

//        print_r($response);
   		return $response;
   	}

	/**
	 * Update model(s)
	 *
	 * @param string $model  Model
	 * @param array  $ids     Model ids to update
	 * @param array  $fields A associative array (format: ['field' => 'value'])
	 *
	 * @return array
	 */
	public function write($model, $ids, $fields)
	{
        $response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            'write',
            [
                 $ids,
                $fields
            ]
        );

		return $response;
	}

	/**
	 * Unlink model(s)
	 *
	 * @param string $model Model
	 * @param array  $ids   Array of model id's
	 *
	 * @return boolean True is successful
	 */
	public function unlink($model, $ids)
	{
        $response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            'unlink',
            [$ids]
        );

		return $response;
	}

	public function command($model, $command, $values)
	{
        $response = $this->getClient('object')->execute_kw(
            $this->database,
            $this->uid(),
            $this->password,
            $model,
            $command,
            [$values]
        );

		return $response;
	}

	/**
	 * Get XmlRpc Client
	 *
	 * This method returns an XmlRpc Client for the requested endpoint.
	 * If no endpoint is specified or if a client for the requested endpoint is
	 * already initialized, the last used client will be returned.
	 *
	 * @param null|string $path The api endpoint
	 *
	 * @return Client
	 */
	protected function getClient($path = null)
	{
		if ($path === null) {
			return $this->client;
		}

		if ($this->path === $path) {
			return $this->client;
		}

		$this->path = $path;

		$this->client = Ripcord::client($this->host . '/xmlrpc/2/' . $path);

        return $this->client;
	}

    /**
	 * Get uid
	 *
	 * @return int $uid
	 */
	protected function uid()
	{
		if ($this->uid === null) {
			$client = $this->getClient('common');

			$this->uid = $client->authenticate(
				$this->database,
				$this->user,
				$this->password,
                array()
			);
		}

		return $this->uid;
	}
}

endif;

$xs_odoo = new xs_odoo();

?>
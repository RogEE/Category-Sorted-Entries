<?php

/**
 * RogEE Category Sorted Entries: helper functions
 *
 * @package		RogEE Category Sorted Entries
 * @author		Michael Rog <michael@michaelrog.com>
 * @copyright	Copyright (c) 2011 Michael Rog
 * @link		http://michaelrog.com/ee/category-sorted-entries
 */

class Category_sorted_entries_helpers {

	// Local reference to EE superobject
	private $EE;
	
	// Write debug logs to DB table?
	private $dev_on = FALSE;

	// And, here... we... go!
	public function __construct()
	{
		$this->EE =& get_instance();
	}


	/**
	* ==============================================
	* Explode list param
	* ==============================================
	*
	* Converts EE parameter to workable php vars
	*
	* @access public
	* @param string: String like 'not 1|2|3' or '40|15|34|234'
	* @return array: [0] => array of items, [1] => boolean whether to include or exclude (TRUE means include, FALSE means exclude)
	*/
	public function explode_list_param($str = "")
	{

		$in = TRUE;

		// ---------------------------------------------
		// Check if parameter is "not blah|blah"
		// ---------------------------------------------

		if (strtolower(substr($str, 0, 4)) == 'not ')
		{
			// Change $in var accordingly
			$in = FALSE;

			// Strip 'not ' from string
			$str = substr($str, 4);
		}

		// --------------------------------------
		// Return two values in an array
		// --------------------------------------

		return array(explode('|', trim($str)), $in);
	} // END explode_list_param()



	/**
	* ==============================================
	* Fetch disable param
	* ==============================================
	*
	* Sets keys in supplied array based on EE template disable="" param
	*
	* @access public
	* @param array: Array containing current/default enable/disable values
	* @return array: Array containing updated enable/disable values
	*/
	public function fetch_disable_param($enabled = array())
	{
		if ($disable = $this->EE->TMPL->fetch_param('disable'))
		{
			foreach (explode("|", $disable) as $val)
			{
				if (isset($enabled[$val]))
				{
					$enabled[$val] = FALSE;
				}
			}
		}
		return $enabled ;
	} // END fetch_disable_param()



	/**
	* ==============================================
	* Spit (for debugging)
	* ==============================================
	*
	* For spitting out [nicely formatted] raw data during debugging
	*
	* @access public
	* @return string
	*/
	public function spit($obj="") {

		return '<pre>' . print_r($obj, TRUE) . '</pre>';

	} // END spit()



	/**
	 * ==============================================
	 * Log (for debugging)
	 * ==============================================
	 *
	 * This method places a string into my debug log table AND logs it in the template parser.
	 *
	 * @access	public
	 * @param	string: The debug string
	 * @return	string: The debug string parameter
	 */
	public function log($statement = "")
	{

		if ($this->dev_on)
		{

			if (! $this->EE->db->table_exists('rogee_debug_log'))
			{
				$this->EE->load->dbforge();
				$this->EE->dbforge->add_field(array(
					'event_id'    => array('type' => 'INT', 'constraint' => 5, 'unsigned' => TRUE, 'auto_increment' => TRUE),
					'class'    => array('type' => 'VARCHAR', 'constraint' => 50),
					'event'   => array('type' => 'VARCHAR', 'constraint' => 200),
					'timestamp'  => array('type' => 'INT', 'constraint' => 20, 'unsigned' => TRUE)
				));
				$this->EE->dbforge->add_key('event_id', TRUE);
				$this->EE->dbforge->create_table('rogee_debug_log');
			}

			$log_item = array('class' => __CLASS__, 'event' => $statement, 'timestamp' => time());
			$this->EE->db->set($log_item);
			$this->EE->db->insert('rogee_debug_log');

		}

		if (isset($this->EE->TMPL) && is_object($this->EE->TMPL))
		{
			$this->EE->TMPL->log_item("Category Sorted Entries" . ": " . $statement);
		}

		return $statement;

	} // END log()



} // END Category_sorted_entries class

/* End of file helpers.php */ 
/* Location: ./system/expressionengine/third_party/category_sorted_entries/helpers.php */
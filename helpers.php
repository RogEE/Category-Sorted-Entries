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

	private $EE;

	public function __construct()
	{
		$this->EE =& get_instance();
	}
	
	public function _test($str = "test string!")
	{
		return $str ;
	}
	
	
	
	/**
	* Converts EE parameter to workable php vars
	*
	* @access private
	* @param string: String like 'not 1|2|3' or '40|15|34|234'
	* @return array: [0] = array of ids, [1] = boolean whether to include or exclude (TRUE means include, FALSE means exclude)
	*/	
	public function fetch_list_param($str = "")
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

		return array(explode('|', $str), $in);
	}
	
	
	
	/**
	* Sets keys in supplied array based on EE template disable="" param
	*
	* @access private
	* @param array: Array containing current/default enable/disable values
	* @return array: Array containing new enable/disable values
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
	}
	
	
	
} // END Category_sorted_entries class

/* End of file helpers.php */ 
/* Location: ./system/expressionengine/third_party/category_sorted_entries/helpers.php */
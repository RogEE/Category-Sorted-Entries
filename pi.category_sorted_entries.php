<?php

/*
=====================================================

RogEE "Category Sorted Entries"
a plug-in for ExpressionEngine 2
by Michael Rog
version 2.0.0

Please e-mail me with questions, feedback, suggestions, bugs, etc.
>> michael@michaelrog.com
>> http://michaelrog.com/ee

This plugin is compatible with NSM Addon Updater:
>> http://github.com/newism/nsm.addon_updater.ee_addon

=====================================================

*/

if (!defined('APP_VER') || !defined('BASEPATH')) { exit('No direct script access allowed'); }

// ---------------------------------------------
// 	Include config file
//	(I get the version and other info from config.php, so everything stays in sync.)
// ---------------------------------------------

require_once PATH_THIRD.'category_sorted_entries/config.php';

// ---------------------------------------------
//	Provide plugin info to EE
// ---------------------------------------------

$plugin_info = array(
	'pi_name'			=> ROGEE_CSE_NAME,
	'pi_version'		=> ROGEE_CSE_VERSION,
	'pi_author'			=> "Michael Rog",
	'pi_author_url'		=> ROGEE_CSE_DOCS,
	'pi_description'	=> 'Like the built-in EE Category Archive, but way mo\' better (with additional variables and parameters for added control.)',
	'pi_usage'			=> Category_sorted_entries::usage()
);

// ---------------------------------------------
//	Helper functions offloaded to a second file, for neatness' sake.
// ---------------------------------------------

require_once PATH_THIRD.'category_sorted_entries/helpers.php';

// ---------------------------------------------
//	Okay, here goes nothing...
// ---------------------------------------------

/**
 * RogEE Category Sorted Entries
 *
 * @package		RogEE Category Sorted Entries
 * @author		Michael Rog <michael@michaelrog.com>
 * @copyright	Copyright (c) 2011 Michael Rog
 * @link		http://michaelrog.com/ee/category-sorted-entries
 */

class Category_sorted_entries {

	/**
	* Local reference to the ExpressionEngine super object
	*
	* @access private
	* @var object
	*/
	private $EE;

	/**
	* Instance of the helper class
	*
	* @access private
	* @var object
	*/
	private $H;

	/**
	* Plugin return data
	*
	* @access public
	* @var string
	*/
	public $return_data = "";

	/**
	* Parameter data from template (NOT including "disable" param)
	*
	* @access private
	* @var string
	*/
	private $params = array();

	/**
	* Enabled features, to be altered by the disable="" parameter
	*
	* @access private
	* @var array
	*/
	private $enable = array();

	/**
	* Big complicated object containing entry data from SQL query
	*
	* @access private
	* @var object
	*/
	private $entry_data_q;

	/**
	* Big complicated object containing category data from SQL query
	*
	* @access private
	* @var object
	*/
	private $category_data_q;

	/**
	* Array containing entry [and custom field] data, keyed by entry_id
	*
	* @access private
	* @var array
	*/
	private $entry_data_a = array();

	/**
	* Array containing category [and category field] data, keyed by cat_id
	*
	* @access private
	* @var array
	*/
	private $category_data_a = array();

	/**
	* Array containing lists of entry IDs for each category, keyed by cat_id
	*
	* @access private
	* @var array
	*/
	private $entries_by_category_a = array();

	/**
	* Final variable replacement array
	*
	* @access private
	* @var array
	* @see http://expressionengine.com/user_guide/development/usage/template.html#parsing_variables
	*/
	private $tag_vars_a = array();

	/**
	* Debug switch
	*
	* @access private
	* @var boolean
	*/
	private $dev_on = FALSE;

	/**
	* Other misc variables I don't care to comment right now...
	*/

	// Entry filter lists

	var $entries_list = array();
	var $entries_exclude_list = array();

	// Important numbers

	var $group_id;
	var $channel_id;

	// For making category data query efficient despite show_empty

	var $assigned_categories_a = array();
	var $selected_categories_a = array();
	var $category_parents_a = array();

	// For creating URIs

	var $reserved_cat_segment = "";
	var $use_category_names = FALSE;



	/**
	* ==============================================
	* Constructors
	* ==============================================
	*
	* @access      public
	* @return      null
	*/
	public function Category_sorted_entries($str="") {

		$this->__construct($str);

	} // END Category_sorted_entries()

	public function __construct($str="")
	{

		// ---------------------------------------------
		//	Establish local references to EE object and helper class
		// ---------------------------------------------

		$this->EE =& get_instance();
		$this->H = new Category_sorted_entries_helpers;

		// ---------------------------------------------
		//	This plugin is only meant to be called from a template (not as a text processor).
		// ---------------------------------------------

		if (! isset($this->EE->TMPL) || ! is_object($this->EE->TMPL))
		{
			$this->return_data = "";
		}
		else
		{

			// ---------------------------------------------
			//	Default settings: Category trigger words / cat. name vs. cat ID
			// ---------------------------------------------

			if ($this->EE->config->item("use_category_name") == 'y' && $this->EE->config->item("reserved_category_word") != '')
			{
				$this->use_category_names = $this->EE->config->item("use_category_name");
				$this->reserved_cat_segment = $this->EE->config->item("reserved_category_word");
			}

			// ---------------------------------------------
			//	By default, everything is enabled.
			// ---------------------------------------------

			$this->enable = array(
				'category_fields' => TRUE,
				'custom_fields' => TRUE,
				'member_data' => TRUE
				);

			$this->enable = $this->H->fetch_disable_param($this->enable);

			// ---------------------------------------------
			//	Fetch and process params once at the beginning, so we can use them later without reinvoking the TMPL functions.
			//	 - "group_id" (deprecated) is supported if "display_by_group" is not supplied
			//	 - "order_by" is supported if "orderby" is not supplied
			//	 - "disable" param is captured here for debugging, but $enable is set by a helper (i.e. not with this data)
			// ---------------------------------------------

			$this->params = array(
				'channel' => $this->EE->TMPL->fetch_param("channel"),
				'site_id' => ( $this->EE->TMPL->fetch_param("site_id") ? $this->EE->TMPL->fetch_param("site_id") : $this->EE->config->item('site_id') ),
				'show' => $this->EE->TMPL->fetch_param("show"),
				'parent_only' => $this->EE->TMPL->fetch_param("parent_only", "no"),
				'show_empty' => $this->EE->TMPL->fetch_param("show_empty", "yes"),
				'show_future_entries' => $this->EE->TMPL->fetch_param("show_future_entries", "no"),
				'show_expired_entries' => ( $this->EE->TMPL->fetch_param("show_expired_entries") ? $this->EE->TMPL->fetch_param("show_expired_entries") : $this->EE->TMPL->fetch_param("show_expired") ),
				'status' => $this->EE->TMPL->fetch_param("status"),
				'entry_id' => $this->EE->TMPL->fetch_param("entry_id"),
				'category' => $this->EE->TMPL->fetch_param("category"),
				'display_by_group' => ( $this->EE->TMPL->fetch_param("display_by_group") ? $this->EE->TMPL->fetch_param("display_by_group") : $this->EE->TMPL->fetch_param("group_id") ),
				'order_by' => ( $this->EE->TMPL->fetch_param("order_by") ? $this->EE->TMPL->fetch_param("order_by") : $this->EE->TMPL->fetch_param("orderby") ),
				'sort' => $this->EE->TMPL->fetch_param("sort"),
				'style' => $this->EE->TMPL->fetch_param("style","linear"),
				'class' => $this->EE->TMPL->fetch_param("class"),
				'id' => $this->EE->TMPL->fetch_param("id"),
				'container_tag' => ( $this->EE->TMPL->fetch_param("container_tag", "ul") != "" ? $this->EE->TMPL->fetch_param("container_tag", "ul") : "ul" ),
				'container_class' => $this->EE->TMPL->fetch_param("container_class"),
				'item_tag' => ( $this->EE->TMPL->fetch_param("item_tag", "li") != "" ? $this->EE->TMPL->fetch_param("item_tag", "li") : "li" ),
				'item_class' => $this->EE->TMPL->fetch_param("item_class"),
				'backspace' => $this->EE->TMPL->fetch_param("backspace"),
				'disable' => $this->EE->TMPL->fetch_param("disable")
			);

			// ---------------------------------------------
			//	Launch it
			// ---------------------------------------------

			$this->return_data = $this->_entries();

			// ---------------------------------------------
			//	During debugging, also output $params and $enable arrays
			// ---------------------------------------------

			if ($this->dev_on)
			{
				$this->return_data .= "\n<br /><hr />" ;
				$this->return_data .= $this->H->spit($this->params);
				$this->return_data .= $this->H->spit($this->enable);
			}

		}

	} // END __construct()



	/**
	* ==============================================
	* Entries
	* ==============================================
	*
	* @access private
	* @return string
	*/
	private function _entries()
	{

		// ---------------------------------------------
		//	Set category groups
		// ---------------------------------------------

		$this->EE->db->select('DISTINCT cat_group, channel_id', FALSE)
			->from('channels')
			->where('site_id', $this->params['site_id']);

		if ($this->params['channel']){
			$this->EE->db->where('channel_name', $this->params['channel']);
		}

		$cat_group_q = $this->EE->db->get();

		if ($cat_group_q->num_rows() != 1)
		{
			$this->H->log("No results -- No category groups assigned to the specified channel");
			return $this->EE->TMPL->no_results();
		}

		$this->channel_id = $cat_group_q->row('channel_id');
		$this->group_id = $cat_group_q->row('cat_group');

		if ($this->params['display_by_group'] !== FALSE)
		{

			$group_ids = explode('|', $this->group_id);

			list($ids, $in) = $this->H->explode_list_param($this->params['display_by_group']);

			// Either remove $ids from $group_ids OR limit $group_ids to $ids
			$method = $in ? 'array_intersect' : 'array_diff';

			// Alter group_ids
			$group_ids = $method($group_ids, $ids);

			// Replace with new group_id list
			$this->group_id = implode("|", $group_ids);

			// Clean up
			unset($cat_group_q, $group_ids, $ids, $in);

		}

		// ---------------------------------------------
		//	Bail out if there are no groups to display
		// ---------------------------------------------

		if ($this->group_id == "")
		{
			$this->H->log("No results -- No category groups to display");
			return $this->EE->TMPL->no_results();
		}

		// ---------------------------------------------
		//	Filter entries by entry_id
		// ---------------------------------------------

		if ($this->params['entry_id'] !== FALSE)
		{

			list($ids, $in) = $this->H->explode_list_param($this->params['entry_id']);

			// ---------------------------------------------
			//	If there are entry IDs in the param, add them to the appropriate list.
			// ---------------------------------------------

			if ($in)
			{
				$this->entries_list = $ids;
				$this->H->log("Filtering by entries -- entries_list = " . implode(', ', $this->entries_list));
			}
			else
			{
				$this->entries_exclude_list = $ids;
				$this->H->log("Filtering by entries -- entries_exclude_list = " . implode(', ', $this->entries_exclude_list));
			}

			// Clean up
			unset($ids, $in);

		}

		// ---------------------------------------------
		//	Filter entries by category IDs
		// ---------------------------------------------

		if ($this->params['category'] !== FALSE)
		{

			list($ids, $in) = $this->H->explode_list_param($this->params['category']);

			// ---------------------------------------------
			//	Get a list of all the entries that match the param.
			// ---------------------------------------------

			$this->EE->db->select('DISTINCT entry_id')
				->from('category_posts')
				->where_in('cat_id', $ids);

			$entries_by_category_q = $this->EE->db->get();

			$matched_entries = array();

			if ($entries_by_category_q->num_rows() > 0)
			{
  				foreach ($entries_by_category_q->result() as $row)
   				{
	     			$matched_entries[] = $row->entry_id;
     			}
     		}

			// ---------------------------------------------
			//	Bail out if there will be no entries to display
			// ---------------------------------------------

			elseif ($in)
			{
				$this->H->log("No results -- No entries match the requested categories");
				return $this->EE->TMPL->no_results();
			}

			// ---------------------------------------------
			//	Add the matched entries to the appropriate filter list
			// ---------------------------------------------

			if ($in)
			{
				$method = empty($this->entries_list) ? 'array_merge' : 'array_intersect';
				$this->entries_list = $method($this->entries_list, $matched_entries);
				$this->H->log("Filter by category: Entries list + " . $method . " + ". implode(', ', $matched_entries) . " -> " . implode(', ', $this->entries_list));
			}
			else {
				$this->entries_exclude_list = array_merge($this->entries_exclude_list, $matched_entries);
				$this->H->log("Filter by category: Entries exclude list + array_merge + ". implode(', ', $matched_entries) . " -> " . implode(', ', $this->entries_exclude_list));
			}

			// Clean up
			unset($entries_by_category_q, $matched_entries, $ids, $in);

		}

		// ---------------------------------------------
		//	Go fetch a crapload of entry data
		// ---------------------------------------------

		$this->EE->db->from('channel_titles channel_titles, category_posts category_posts')
			->where('channel_titles.channel_id', $this->channel_id, FALSE)
			->where('channel_titles.entry_id = category_posts.entry_id', NULL, FALSE);

		// Filter by chosen entry IDs

		if ($this->entries_list)
		{
			$this->EE->db->where_in('channel_titles.entry_id', $this->entries_list);
		}
		if ($this->entries_exclude_list)
		{
			$this->EE->db->where_not_in('channel_titles.entry_id', $this->entries_exclude_list);
		}

		// Filter future/expired entries

		$timestamp = ($this->EE->TMPL->cache_timestamp != '') ? $this->EE->localize->set_gmt($this->EE->TMPL->cache_timestamp) : $this->EE->localize->now;

		if ($this->params['show_future_entries'] != 'yes')
		{
			$this->EE->db->where("channel_titles.entry_date <", $timestamp, FALSE);
		}

		if ($this->params['show_expired_entries'] != 'yes')
		{
			$this->EE->db->where("(channel_titles.expiration_date = 0 OR channel_titles.expiration_date > ".$timestamp.")", NULL, FALSE);
		}

		// Filter by status

		if ($this->params['status'] !== FALSE)
		{
			$status = str_replace('Open', 'open', $this->params['status']);
			$status = str_replace('Closed', 'closed', $status);
			list($statuses, $in) = $this->H->explode_list_param($status);
			$this->EE->db->{($in ? 'where_in' : 'where_not_in')}('channel_titles.status', $statuses);
			unset($statuses, $in);
		}
		else
		{
			$this->EE->db->where('channel_titles.status', "'open'", FALSE);
		}

		// Filter by "show" param

		if ($this->params['show'] !== FALSE)
		{
			list($cat_ids, $in) = $this->H->explode_list_param($this->params['show']);
			$this->EE->db->{($in ? 'where_in' : 'where_not_in')}('category_posts.cat_id', $cat_ids);
			unset($cat_ids, $in);
		}

		// Order entries

		$order_by = trim($this->params['order_by']);
		$sort = trim($this->params['sort']);

		switch ($sort)
		{
			case 'asc' :
				break;
			case 'desc' :
				break;
			default :
				$sort = "asc" ;
				break;
		}

		switch ($order_by)
		{
			case 'date' :
				$this->EE->db->order_by('channel_titles.entry_date', $sort);
				break;
			case 'expiration_date' :
				$this->EE->db->order_by('channel_titles.expiration_date', $sort);
				break;
			case 'title' :
				$this->EE->db->order_by('channel_titles.title', $sort);
				break;
			case 'comment_total' :
				$this->EE->db->order_by('channel_titles.entry_date', $sort);
				break;
			case 'most_recent_comment' :
				$this->EE->db->order_by('channel_titles.recent_comment_date', 'desc');
				$this->EE->db->order_by('channel_titles.entry_date', $sort);
				break;
			default :
				$this->EE->db->order_by('channel_titles.title', $sort);
				break;
		}

		// Join with custom field data

		if ($this->enable['custom_fields'])
		{
			$this->EE->db->join('channel_data channel_data', 'channel_data.entry_id = channel_titles.entry_id');
		}

		// Join with member data

		if ($this->enable['member_data'])
		{
			$this->EE->db->join('members members', 'members.member_id = channel_titles.author_id');
		}

		// And here's the result!!!!

		$this->entry_data_q = $this->EE->db->get();

		// ---------------------------------------------
		//	Bail if there are no results
		// ---------------------------------------------

		if ($this->entry_data_q->num_rows() < 1)
		{
			$this->H->log("No results -- No entries returned from query");
  			return $this->EE->TMPL->no_results();
     	}

		// ---------------------------------------------
		//	Some entry variables will get nuked in a moment, for security, or just because they don't belong.
		// ---------------------------------------------

		$tags_to_unset = array_fill_keys(
			array(
				'cat_id',
				'password',
				'unique_id',
				'crypt_key',
				'authcode',
				'ignore_list',
				'private_messages',
				'accept_messages',
				'last_view_bulletins',
				'last_bulletin_date',
				'accept_admin_email',
				'accept_user_email',
				'notify_by_default',
				'notify_of_pm',
				'display_avatars',
				'display_signatures',
				'parse_smileys',
				'smart_notifications',
				'cp_theme',
				'profile_theme',
				'forum_theme',
				'tracker',
				'template_size',
				'notepad',
				'notepad_size',
				'quick_links',
				'quick_tabs',
				'show_sidebar',
				'pmember_id',
				'profile_views'
			), "");

		// ---------------------------------------------
		//	Process the results into the entry_data_a and entries_by_category_a arrays
		// ---------------------------------------------

		foreach ($this->entry_data_q->result_array() as $q_row)
		{

			// Add entry data to entry_data_a (but only once per entry_id).
			// As we do so, unset the keys we specified earlier.

     		if ( ! isset($this->entry_data_a[ $q_row['entry_id'] ]) )
     		{
     			$this->entry_data_a[$q_row['entry_id']] = array_diff_key($q_row, $tags_to_unset);
     		}
     
     		// If this cat_id hasn't been added to entries_by_category_a yet, initialize an array there.
     
     		if ( ! isset($this->entries_by_category_a[ $q_row['cat_id'] ]) )
     		{
     			$this->entries_by_category_a[$q_row['cat_id']] = array();
     		}
     
     		// Add this entry_id to entries_by_category under the appropriate cat_id.
     
     		$this->entries_by_category_a[$q_row['cat_id']][] = $q_row['entry_id'];
     
     		// Add this cat_id to assigned_categories_a
     
     		$this->assigned_categories_a[] = $q_row['cat_id'] ;
     
 		}

		// ---------------------------------------------
		//	If enabled, process Custom Channel Fields
		// ---------------------------------------------

		if ($this->enable['custom_fields'])
		{
			$this->_process_custom_channel_fields();
		}

		// ---------------------------------------------
		//	Next stop: Categories!
		// ---------------------------------------------

		// FOR DEBUGGING
		// return $this->H->spit($this->entry_data_a) . $this->H->spit($this->entries_by_category_a);

		return $this->_categories();

	} // END entries()



	/**
	* ==============================================
	* Categories
	* ==============================================
	*
	* @access private
	* @return string
	*/
	private function _categories()
	{

		// ---------------------------------------------
		//	If we aren't going to show empty categories...
		// ---------------------------------------------

		if ($this->params['show_empty'] == 'no')
		{

			// ---------------------------------------------
			//	...we only need to query categories we know are assigned to our selected entries...
			// ---------------------------------------------

			$this->selected_categories_a = array_unique($this->assigned_categories_a);

			// ---------------------------------------------
			//	...unless we also need their parents for display in a tree. ("Categories are not assexual.")
			// ---------------------------------------------

			if ($this->params['style'] == 'nested')
			{

				// Grab cat_id and parent_id for every category in the specified group(s)

				$this->EE->db->select('cat_id, parent_id')
					->from('categories')
					->where_in('group_id', explode("|", $this->group_id))
					->order_by('group_id ASC, parent_id ASC, cat_order ASC');

				$category_parents_q = $this->EE->db->get();

				// No categories exist? Back to the barn for the night..

				if ($category_parents_q->num_rows() < 1)
				{
					$this->H->log("No results -- No categories exist in this group");
					return $this->EE->TMPL->no_results();
				}

				// Add all the kids and their parents to the big family list.

				foreach($category_parents_q->result() as $cat)
				{
					$this->category_parents_a[$cat->cat_id] = $cat->parent_id;
				}

				// For all the [child] categories in selected_categories_a, add their parents too!

				foreach ($this->selected_categories_a as $cat)
				{
					$this->_include_parents($cat, $this->category_parents_a);
				}

				$this->selected_categories_a = array_unique($this->selected_categories_a);

			}

		}

		// ---------------------------------------------
		//	Massive category data query!
		// ---------------------------------------------

		$select_sql = 'DISTINCT (c.cat_id), c.cat_name, c.cat_url_title, c.cat_description, c.cat_image, c.group_id, c.parent_id' 
			. ( $this->enable['category_fields'] ? ", cg.field_html_formatting, fd.*" : "" );

		$this->EE->db->select($select_sql)
			->from('categories c');

		if ($this->enable['category_fields'])
		{
			$this->EE->db->join('category_field_data AS fd', 'fd.cat_id = c.cat_id', 'LEFT');
			$this->EE->db->join('category_groups AS cg', 'cg.group_id = c.group_id', 'LEFT');
		}

		if ($this->params['show_empty'] == 'no')
		{
			$this->EE->db->where_in('c.cat_id', $this->selected_categories_a);
		}

		// Only get categories from the groups we want to display.

		$this->EE->db->where_in('c.group_id', explode("|", $this->group_id));

		// Filter by category IDs in "show" param.

		if ($this->params['show'] !== FALSE)
		{
			list($cat_ids, $in) = $this->H->explode_list_param($this->params['show']);
			$this->EE->db->{($in ? 'where_in' : 'where_not_in')}('c.cat_id', $cat_ids);
			unset($cat_ids, $in);
		}

		// If parent_only param is set, display only root categories (i.e. those without parents).

		if ($this->params['parent_only'] == "yes")
		{
			$this->EE->db->where('c.parent_id', 0);
		}

		// Respect the cat_order!

		$this->EE->db->order_by('c.group_id ASC, c.parent_id ASC, c.cat_order ASC');
	 
	 	$this->category_data_q = $this->EE->db->get();

		// ---------------------------------------------
		//	No category data comes back? Eff that noise.
		// ---------------------------------------------

		if ($this->category_data_q->num_rows() < 1)
		{
			$this->H->log("No results -- No category data returned from query");
			return $this->EE->TMPL->no_results();
		}

		// ---------------------------------------------
		//	Get all the category data into a nice array, keyed by cat_id
		// ---------------------------------------------

		foreach($this->category_data_q->result_array() as $q_row)
		{
			// Add the category data to category_data_a (but only once per cat_id)
			if ( ! isset($this->category_data_a[ $q_row['cat_id'] ]) )
			{
				$this->category_data_a[$q_row['cat_id']] = $q_row;
			}
		}

		// ---------------------------------------------
		//	If necessary, prep Custom Category Fields
		// ---------------------------------------------

		if ($this->enable['category_fields'])
		{
			$this->_process_custom_category_fields();
		}

		// ---------------------------------------------
		//	Figure out which style to output, and move on.
		// ---------------------------------------------

		// FOR DEBUGGING
		// return $this->H->spit($this->category_data_a);

		switch ($this->params['style']) {
			case "linear":
				return $this->_linear();
				break;
			case "nested":
				return $this->_nested();
				break;
			default:
				return "";
		}

	} // end _categories()



	/**
	* ==============================================
	* Linear display
	* ==============================================
	*
	* @access private
	* @return string
	*/
	private function _linear()
	{

		// ---------------------------------------------
		//	Assemble output string...
		// ---------------------------------------------

		$return_string = "";

		foreach($this->category_data_q->result() as $q_row)
		{

			$return_string .= $this->_parse_cat_row($q_row->cat_id);

		}

		// ---------------------------------------------
		//	Whack a few characters off the end, maybe...
		// ---------------------------------------------

		if ($this->params['backspace'])
		{
			$b = (int)$this->EE->params['backspace'];

			$return_string = substr($return_string, 0, - $b);
		}

		// ---------------------------------------------
		//	And away we go!
		// ---------------------------------------------

		return $return_string;

	}



	/**
	* ==============================================
	* Nested display
	* ==============================================
	*
	* @access private
	* @return string
	*/
	private function _nested()
	{

		$return_string = "\n";

		if ($this->params['container_tag'] != "")
		{
			$root_id = $this->params['id'];
			$root_classes = array($this->params['class'], $this->params['container_class']);
			$root_class = trim(implode(" ", $root_classes));
			$return_string .= "\r" 
				. "<"
				. $this->params['container_tag']
				. ( $root_id != "" ? ' id="'.$root_id.'"' : "" )
				. ( $root_class != "" ? ' class="'.$root_class.'"' : "" )
				. ">" ;
		}

		foreach (explode("|",$this->group_id) as $group_id)
		{
			$parsed_tree = $this->_parse_cat_tree($group_id,0,0);
			$return_string .= $parsed_tree['contents'];
		}


		if ($this->params['container_tag'] != "")
		{
			$return_string .= "\r" . "</" . $this->params['container_tag'] . ">" ;
		}

		return $return_string;

	} // END _nested()



	/**
	* ==============================================
	* Parse category tree
	* ==============================================
	*
	* Yes, this is recursive and ugly.
	* Yes, worst case is that every category is a parent of the next, and the stack will get big.
	* Yes, it might be better to construct the tree first as a node list, pushing and popping items to a generation queue...
	* But this is simpler, and categories, practically speaking, will rarely be more than a few levels nested.
	*
	* @access private
	* @return array: [contents_is_empty] => boolean, [contents] => string
	*/
	private function _parse_cat_tree($group=0, $parent=0, $depth=0)
	{

		$return_string = "";

		$has_contents = FALSE;

		// Each "new line" starts with a carriage return and {depth} tabs.
		$nl = "\n" . str_repeat("\t", $depth);

		// And "indented new line" starts with a carriage return and {depth+1} tabs.

		$nl_i = $nl . "\t";

		// ---------------------------------------------
		//	If we are above the root, open a container
		// ---------------------------------------------

		if ($depth > 0 && $this->params['container_tag'] != "")
		{
			$return_string .= $nl . "<" . $this->params['container_tag'] . ( $this->params['container_class'] ? " class=\"".$this->params['container_class']."\">" : ">" );
		}

		// ---------------------------------------------
		//	Search through ALL the categories (which are sorted by group_id, parent_id, cat_order) and build the tree
		//	(This will take O(n^2) time, but I'm pretty sure there's no easy way to do it better, since we MUST preserve the sort order.)
		// ---------------------------------------------

		foreach ($this->category_data_q->result() as $q_row)
		{

			if ($q_row->group_id == $group && $q_row->parent_id == $parent)
			{

				$has_contents = TRUE;

				// Open item

				if ($this->params['item_tag'] != "")
				{
					$return_string .= $nl_i . "<" . $this->params['item_tag'] . ( $this->params['item_class'] ? ' class="'.$this->params['item_class'].'">' : ">" );
				}

				// Include contents for this node

				$return_string .= $this->_parse_cat_row($q_row->cat_id);

				// Include subtree contents, if subtree has contents

				$subtree = $this->_parse_cat_tree($group, $q_row->cat_id, $depth+2);

				if (!$subtree['contents_is_empty'])
				{
					$return_string .= $subtree['contents'];
				}

				// Close item

				if ($this->params['item_tag'] != "")
				{
					$return_string .= $nl_i . "</" . $this->params['item_tag'] . ">";
				}

			}

		}

		// ---------------------------------------------
		//	If we opened a container, it's only good manners to close it, too!
		// ---------------------------------------------

		if ($depth > 0 && $this->params['container_tag'] != "")
		{
			$return_string .= $nl . "</" . $this->params['container_tag'] . ">";
		}

		// ---------------------------------------------
		//	Return the node array
		// ---------------------------------------------

		return array(
			'contents_is_empty' => !$has_contents,
			'contents' => $return_string
		);

	} // END _parse_cat_tree



	/**
	* ==============================================
	* Parse category row
	* ==============================================
	*
	* @access private
	* @return string
	*/
	private function _parse_cat_row($cat_id="0")
	{

		// ---------------------------------------------
		//	Fetch category vars, and fix a few field names
		// ---------------------------------------------

		$category_vars = array($this->category_data_a[$cat_id]);

		$tag_name_replacements = array(
			'cat_id' => "category_id",
			'cat_name' => "category_name",
			'cat_url_title' => "category_url_title",
			'cat_description' => "category_description",
			'cat_image' => "category_image"
		);

		foreach ($tag_name_replacements as $key => $val)
		{
			$category_vars[0][$val] = isset($category_vars[0][$key]) ? $category_vars[0][$key] : "" ;
		}

		// ---------------------------------------------
		//	Call upon EE's built-in var-parsing awesomeness
		// ---------------------------------------------

		$variables = array();
		$variables[0] = array();

		$variables[0]['category'] = $category_vars;

		$variables[0]['entries'] = array();

		if (isset($this->entries_by_category_a[$cat_id]) && is_array($this->entries_by_category_a[$cat_id])){
			foreach ($this->entries_by_category_a[$cat_id] as $entry_array)
			{
				$variables[0]['entries'][] = $this->entry_data_a[$entry_array];
			}
		}

		// FOR DEBUG ONLY
		// return $this->H->spit($variables) ;

		return $this->EE->TMPL->parse_variables($this->EE->TMPL->tagdata, $variables);

	} // END _parse_cat_row()


	/**
	* ==============================================
	* Process custom category fields
	* ==============================================
	*
	* @access private
	* @return void
	*/
	private function _process_custom_category_fields()
	{

		// ---------------------------------------------
		//	Fetch category field names and IDs
		// ---------------------------------------------

		$this->EE->db->select('field_id, field_name')
			->from('category_fields')
			->where('site_id', $this->params['site_id'])
			->where_in('group_id', explode("|",$this->group_id));

		$category_fields_info_q = $this->EE->db->get();

		// ---------------------------------------------
		//	Make sure there are results before we try to process them...
		// ---------------------------------------------

		if ($category_fields_info_q->num_rows() < 1)
		{
			// No Custom Category Field results
			return;
		}

		// ---------------------------------------------
		//	Iterate over the category_data_a, processing/replacing custom fields
		// ---------------------------------------------

		foreach ($category_fields_info_q->result() as $field)
		{

			foreach($this->category_data_a as $key => $data_row)
			{

				if ( isset($data_row[ 'field_id_'.$field->field_id ]) )
				{

					$this->category_data_a[$key]["".$field->field_name] = array();

					$this->category_data_a[$key][ $field->field_name ][] = $data_row[ 'field_id_'.$field->field_id ];

					$this->category_data_a[$key][ $field->field_name ][] = array(
						'text_format'		=> $data_row['field_ft_'.$field->field_id],
						'html_format'		=> $data_row['field_html_formatting'],
						'auto_links'		=> 'n',
						'allow_img_url'	=> 'y'
					);

				}

				else
				{
					$this->category_data_a[$key]["".$field->field_name] = "";
				}

			}

		}

	} // END _process_custom_cat_fields



	/**
	* ==============================================
	* Process custom channel fields
	* ==============================================
	*
	* @access private
	* @return void
	*/
	private function _process_custom_channel_fields()
	{
		// return;
		
		// ---------------------------------------------
		//	Fetch field groups
		// ---------------------------------------------

		$this->EE->db->select('field_id, field_name, channels.channel_html_formatting')
			->from('channel_fields channel_fields, channels channels')
			->where('channel_id', $this->channel_id)
			->where('channels.field_group = channel_fields.group_id', NULL, FALSE);
			
		$channel_fields_info_q = $this->EE->db->get();

		// ---------------------------------------------
		//	Make sure there are results before we try to process them...
		// ---------------------------------------------

		if ($channel_fields_info_q->num_rows() < 1)
		{
			// No Custom Channel Field results
			return;
		}

		// ---------------------------------------------
		//	Iterate over the category_data_a, processing/replacing custom fields
		// ---------------------------------------------

		foreach ($channel_fields_info_q->result() as $field)
		{

			foreach($this->entry_data_a as $key => $data_row)
			{
				
				if ( isset($data_row[ 'field_id_'.$field->field_id ]) )
				{

					$this->entry_data_a[$key][ $field->field_name ] = array();

					$this->entry_data_a[$key][ $field->field_name ][] = $data_row[ 'field_id_'.$field->field_id ];

					$this->entry_data_a[$key][ $field->field_name ][] = array(
						'text_format' => $data_row['field_ft_'.$field->field_id],
						'html_format' => $field->channel_html_formatting,
						'auto_links' => 'n',
						'allow_img_url' => 'y'
					);

				}

				else
				{
					$this->entry_data_a[$key][ $field->field_name ] = "";
				}

			}

		}
		
	}



	/**
	* ==============================================
	* Include parents
	* ==============================================
	*
	* Takes in a category and a {category => parent} list
	* Adds the parents of the category to the global list of "selected categories"
	*
	* (If we don't do this we will run into a problem in which a parent category that is not assigned to a channel will be supressed, and therefore, any of its children will be supressed also, even if they are assigned to entries. "Categories are not asexual.")
	*
	* @access private
	* @return void
	*/
	private function _include_parents($child, $all)
	{
		foreach ($all as $cat_id => $parent_id)
		{
			if ($cat_id == $child && $parent_id != 0)
			{
				$this->selected_categories_a[] = $parent_id;
				$this->_include_parents($parent_id, $all);
			}
		}
	}



	/**
	* ==============================================
	* Include parents
	* ==============================================
	*
	* Takes in a category and a {category => parent} list
	* Adds the parents of the category to the global list of "selected categories"
	*
	* @access public
	* @return string output buffer
	*/
	function usage()
	{

		ob_start(); 
		?>

		This plugin behaves like the standard Category Archive Tag:

		http://expressionengine.com/user_guide/modules/channel/category_archive.html

		---------------

		BUT, you also have some additional parameters to control the output:

		group_id: Only categories in these groups are displayed.
		entry_id: Only entries matching these IDs are returned.
		category: Only entries assigned to these categories are returned.

		AND, all of the default and Custom Channel Fields are available for use.

		See http://michaelrog.com/ee/category-sorted-entries for detailed documentation.

		---------------

		This plugin is compatible with NSM Addon Updater:

		http://github.com/newism/nsm.addon_updater.ee_addon

		<?php
		$buffer = ob_get_contents();

		ob_end_clean(); 

		return $buffer;

	} // END usage()



} // END Category_sorted_entries class

/* End of file pi.category_sorted_entries.php */ 
/* Location: ./system/expressionengine/third_party/category_sorted_entries/pi.category_sorted_entries.php */
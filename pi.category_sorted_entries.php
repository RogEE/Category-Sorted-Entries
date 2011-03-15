<?php

/*
=====================================================

RogEE "Category Sorted Entries"
a plug-in for ExpressionEngine 2
by Michael Rog
v2.a.1

Please e-mail me with questions, feedback, suggestions, bugs, etc.
>> michael@michaelrog.com
>> http://michaelrog.com/ee

This plugin is compatible with NSM Addon Updater:
>> http://github.com/newism/nsm.addon_updater.ee_addon

Changelog:
0.0.1 - alpha: filtering by entry_id and display by group_id
0.0.2 - beta: improved filtering by entry_id, added filtering by category, more variables
1.0.0 - release: cleaned up the file, added BitBucket details

=====================================================

*/

if (!defined('APP_VER') || !defined('BASEPATH')) { exit('No direct script access allowed'); }

// ---------------------------------------------
// 	Include config file
//	(I get the version and other info from config.php, so everything stays in sync.)
// ---------------------------------------------

require_once PATH_THIRD.'category_sorted_entries/config.php';

// ---------------------------------------------
//	Helper functions offloaded to a second file, for neatness' sake.
// ---------------------------------------------

require_once PATH_THIRD.'category_sorted_entries/helpers.php';

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
	* Big complicated objects containing category field info/data from SQL query
	*
	* @access private
	* @var object
	*/
	private $category_fields_data_q;
	private $category_fields_info_q;
	
	/**
	* Debug switch
	*
	* @access private
	* @var boolean
	*/
	private $debug_on = TRUE;

	/**
	* Other misc variables
	*/

	var $entries_list = array();
	var $entries_exclude_list = array();
	
	var $group_id;
	var $channel_id;
	
	var $category_fields_info = array();

	// These are used for creating URIs

	var $reserved_cat_segment 	= '';
	var $use_category_names		= FALSE;


	// These are used with the nested category trees

	var $category_list  		= array();
	var $cat_full_array			= array();
	var $cat_array				= array();
	var $temp_array				= array();
	var $category_count			= 0;


	// TRASH THESE
	
	var $catfields = array();



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
				$this->use_category_names	= $this->EE->config->item("use_category_name");
				$this->reserved_cat_segment	= $this->EE->config->item("reserved_category_word");
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
				'show_empty' => $this->EE->TMPL->fetch_param("show_empty"),
				'show_future_entries' => $this->EE->TMPL->fetch_param("show_future_entries"),
				'show_expired_entries' => ( $this->EE->TMPL->fetch_param("show_expired_entries") ? $this->EE->TMPL->fetch_param("show_expired_entries") : $this->EE->TMPL->fetch_param("show_expired") ),
				'status' => $this->EE->TMPL->fetch_param("status"),
				'entry_id' => $this->EE->TMPL->fetch_param("entry_id"),
				'category' => $this->EE->TMPL->fetch_param("category"),
				'display_by_group' => ( $this->EE->TMPL->fetch_param("display_by_group") ? $this->EE->TMPL->fetch_param("display_by_group") : $this->EE->TMPL->fetch_param("group_id") ),
				'order_by' => ( $this->EE->TMPL->fetch_param("orderby") ? $this->EE->TMPL->fetch_param("orderby") : $this->EE->TMPL->fetch_param("order_by") ),
				'sort' => $this->EE->TMPL->fetch_param("sort"),
				'style' => $this->EE->TMPL->fetch_param("style","linear"),
				'class' => $this->EE->TMPL->fetch_param("class"),
				'id' => $this->EE->TMPL->fetch_param("id"),
				'container_tag' => ( $this->EE->TMPL->fetch_param("container_tag", "ul") != "" ? $this->EE->TMPL->fetch_param("container_tag", "ul") : "ul" ),
				'item_tag' => ( $this->EE->TMPL->fetch_param("item_tag", "li") != "" ? $this->EE->TMPL->fetch_param("item_tag", "li") : "li" ),
				'item_class' => $this->EE->TMPL->fetch_param("item_class"),
				'backspace' => $this->EE->TMPL->fetch_param("backspace"),
				'disable' => $this->EE->TMPL->fetch_param("disable")
			);
			
			// ---------------------------------------------
			//	Launch it
			// ---------------------------------------------

			$this->return_data = $this->entries();
			
			// ---------------------------------------------
			//	During debugging, also output $params and $enable arrays
			// ---------------------------------------------
			
			if ($this->debug_on)
			{
				$this->return_data .= "<br /><hr />" ;
				$this->return_data .= $this->spit_params();
				// $this->return_data .= $this->spit_enable();	
			}
			
		}

	} // END __construct()
	
	
	
	/**
	* ==============================================
	* Entries
	* ==============================================
	*
	* @access public
	* @return string
	*/
	public function entries($str='')
	{
	
		$this->H->debug("Starting entries processing...");
	
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
		
		$this->H->debug("Group IDs set: ".$this->group_id);
		
		// ---------------------------------------------
		//	Bail out if there are no groups to display
		// ---------------------------------------------
		
		if ($this->group_id == "")
		{
			$this->H->debug("No category groups to display; returning no_results.");
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
				$this->H->debug("Filter by entries: Entries list = ".print_r($this->entries_list, TRUE));
			}
			else
			{
				$this->entries_exclude_list = $ids;
				$this->H->debug("Filter by entries: Entries_exclude_list = ".print_r($this->entries_exclude_list, TRUE));
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
				$this->H->debug("No entries match the requested categories; returning no_results.");
				return $this->EE->TMPL->no_results();
			}
			
			// ---------------------------------------------
			//	Add the matched entries to the appropriate filter list
			// ---------------------------------------------
			
			if ($in)
			{
				// Either remove $ids from $entry_ids OR limit $entry_ids to $ids
				$method = empty($this->entries_list) ? 'array_merge' : 'array_intersect';
				// Alter entry_ids
				$this->entries_list = $method($this->entries_list, $matched_entries);
				
				$this->H->debug("Filter by category: Entries list + " . $method . " + ".print_r($matched_entries, TRUE) . " = " . $this->entries_list, TRUE);
			}
			else {
				$this->entries_exclude_list = array_merge($this->entries_exclude_list, $matched_entries);
				
				$this->H->debug("Filter by category: Entries exclude list + array_merge + ".print_r($matched_entries, TRUE) . " = " . $this->entries_exclude_list, TRUE);
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
		
		if ($this->enable['category_fields'])
		{
			$this->EE->db->join('channel_data', 'channel_data.entry_id = channel_titles.entry_id');
		}

		// Join with member data

		if ($this->enable['member_data'])
		{
			$this->EE->db->join('members', 'members.member_id = channel_titles.author_id');
		}
		
		// And here's the result!!!!

		$this->entry_data_q = $this->EE->db->get();
		
		// ---------------------------------------------
		//	Bail if there are no results
		// ---------------------------------------------
		
		if ($this->entry_data_q->num_rows() < 1)
		{
			$this->H->debug("There are no results! Returning no_results.");
  			return $this->EE->TMPL->no_results();
     	}

		// ---------------------------------------------
		//	Process the results!
		// ---------------------------------------------

		foreach ($this->entry_data_q->result() as $row)
		{
     		$results_entries[] = $row->entry_id;
 		}
		
//		return "<br /><pre>".print_r($entry_data_q->result_array(), TRUE)."</pre><br />";

		return $this->EE->TMPL->parse_variables($this->EE->TMPL->tagdata, $this->entry_data_q->result_array()) . "<br /><pre>".print_r($this->entry_data_q->result_array(), TRUE)."</pre><br />";;

	} // END entries()	







	/** ------------------------------------------------------------------------
	/**  Category archives
	/** ------------------------------------------------------------------------*/

	public function category_archive()
	{

		$result = $this->EE->db->query($sql);
		$channel_array = array();

		$parent_only = ($this->EE->TMPL->fetch_param('parent_only') == 'yes') ? TRUE : FALSE;

		$cat_chunk  = (preg_match("/".LD."categories\s*".RD."(.*?)".LD.'\/'."categories\s*".RD."/s", $this->EE->TMPL->tagdata, $match)) ? $match[1] : '';

		$c_path = array();

		if (preg_match_all("#".LD."path(=.+?)".RD."#", $cat_chunk, $matches))
		{
			for ($i = 0; $i < count($matches[0]); $i++)
			{
				$c_path[$matches[0][$i]] = $this->EE->functions->create_url($this->EE->functions->extract_path($matches[1][$i]));
			}
		}

		$tit_chunk = (preg_match("/".LD."entry_titles\s*".RD."(.*?)".LD.'\/'."entry_titles\s*".RD."/s", $this->EE->TMPL->tagdata, $match)) ? $match[1] : '';

		$t_path = array();

		if (preg_match_all("#".LD."path(=.+?)".RD."#", $tit_chunk, $matches))
		{
			for ($i = 0; $i < count($matches[0]); $i++)
			{
				$t_path[$matches[0][$i]] = $this->EE->functions->create_url($this->EE->functions->extract_path($matches[1][$i]));
			}
		}

		$id_path = array();

		if (preg_match_all("#".LD."entry_id_path(=.+?)".RD."#", $tit_chunk, $matches))
		{
			for ($i = 0; $i < count($matches[0]); $i++)
			{
				$id_path[$matches[0][$i]] = $this->EE->functions->create_url($this->EE->functions->extract_path($matches[1][$i]));
			}
		}

		$entry_date = array();

		preg_match_all("/".LD."entry_date\s+format\s*=\s*(\042|\047)([^\\1]*?)\\1".RD."/s", $tit_chunk, $matches);
		{
			$j = count($matches[0]);
			for ($i = 0; $i < $j; $i++)
			{
				$matches[0][$i] = str_replace(array(LD,RD), '', $matches[0][$i]);

				$entry_date[$matches[0][$i]] = $this->EE->localize->fetch_date_params($matches[2][$i]);
			}
		}





		$str = '';


		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////
		
		NESTED
		
		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////		


		if ($this->EE->TMPL->fetch_param('style') == '' OR $this->EE->TMPL->fetch_param('style') == 'nested')
		{
			if ($result->num_rows() > 0 && $tit_chunk != '')
			{
					$i = 0;
				foreach($result->result_array() as $row)
				{
					$chunk = "<li>".str_replace(LD.'category_name'.RD, '', $tit_chunk)."</li>";

					foreach($t_path as $tkey => $tval)
					{
						$chunk = str_replace($tkey, $this->EE->functions->remove_double_slashes($tval.'/'.$row['url_title']), $chunk);
					}

					foreach($id_path as $tkey => $tval)
					{
						$chunk = str_replace($tkey, $this->EE->functions->remove_double_slashes($tval.'/'.$row['entry_id']), $chunk);
					}

					foreach($this->EE->TMPL->var_single as $key => $val)
					{
						if (isset($entry_date[$key]))
						{
							$val = str_replace($entry_date[$key], $this->EE->localize->convert_timestamp($entry_date[$key], $row['entry_date'], TRUE), $val);
							$chunk = $this->EE->TMPL->swap_var_single($key, $val, $chunk);
						}

					}

					/* */
					// {entry_id}, {url_title}
					// An extra replace statement to allow display of entry_id and url_title variables.
					// (This block takes effect when style="nested" is used.)
					// ------------
					
					$chunk = str_replace(array(LD.'entry_id'.RD, LD.'url_title'.RD),
										 array($row['entry_id'],$row['url_title']),
										 $chunk);
										 
					// ------------
					// end {entry_id}, {url_title} replacement
					/* */	


					$channel_array[$i.'_'.$row['cat_id']] = str_replace(LD.'title'.RD, $row['title'], $chunk);
					$i++;
				}
			}

			$this->category_tree(
									array(
											'group_id'		=> $group_id,
											'channel_id'		=> $channel_id,
											'path'			=> $c_path,
											'template'		=> $cat_chunk,
											'channel_array' 	=> $channel_array,
											'parent_only'	=> $parent_only,
											'show_empty'	=> $this->EE->TMPL->fetch_param('show_empty'),
											'strict_empty'	=> 'yes'										
										  )
								);

			if (count($this->category_list) > 0)
			{
				$id_name = ($this->EE->TMPL->fetch_param('id') === FALSE) ? 'nav_cat_archive' : $this->EE->TMPL->fetch_param('id');
				$class_name = ($this->EE->TMPL->fetch_param('class') === FALSE) ? 'nav_cat_archive' : $this->EE->TMPL->fetch_param('class');

				$this->category_list[0] = '<ul id="'.$id_name.'" class="'.$class_name.'">'."\n";

				foreach ($this->category_list as $val)
				{
					$str .= $val;
				}
			}
		}


		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////
		
		LINEAR
		
		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////
		////////////////////////////////////////////////////////////////////////////		



		else
		{
			// fetch category field names and id's

			if ($this->enable['category_fields'] === TRUE)
			{
				$query = $this->EE->db->query("SELECT field_id, field_name FROM exp_category_fields
									WHERE site_id IN ('".implode("','", $this->EE->TMPL->site_ids)."')
									AND group_id IN ('".str_replace('|', "','", $this->EE->db->escape_str($group_id))."')
									AND group_id NOT IN ('WOOHOO-LINE-372')");

				if ($query->num_rows() > 0)
				{
					foreach ($query->result_array() as $row)
					{
						$this->catfields[] = array('field_name' => $row['field_name'], 'field_id' => $row['field_id']);
					}
				}

				$field_sqla = ", cg.field_html_formatting, fd.* ";
				$field_sqlb = " LEFT JOIN exp_category_field_data AS fd ON fd.cat_id = c.cat_id
								LEFT JOIN exp_category_groups AS cg ON cg.group_id = c.group_id ";
			}
			else
			{
				$field_sqla = '';
				$field_sqlb = '';
			}

			$sql = "SELECT DISTINCT (c.cat_id), c.cat_name, c.cat_url_title, c.cat_description, c.cat_image, c.group_id, c.parent_id {$field_sqla}
					FROM (exp_categories AS c";

			if ($this->EE->TMPL->fetch_param('show_empty') != 'no' AND $channel_id != '')
			{
				$sql .= ", exp_category_posts ";
			}

			$sql .= ") {$field_sqlb}";

			if ($this->EE->TMPL->fetch_param('show_empty') == 'no')
			{
				$sql .= " LEFT JOIN exp_category_posts ON c.cat_id = exp_category_posts.cat_id ";

				if ($channel_id != '')
				{
					$sql .= " LEFT JOIN exp_channel_titles ON exp_category_posts.entry_id = exp_channel_titles.entry_id ";
				}
			}
			
			$sql .= " WHERE c.group_id IN ('".str_replace('|', "','", $this->EE->db->escape_str($group_id))."')
						AND c.group_id NOT IN ('WOOHOO-LINE-413') ";
			
			/* */
			// FILTER BY ENTRY LISTS
			// Tack on a bit of query to include only the entries that are/aren't in the set we have specied.
			// (This block takes effect when style="linear" is used.)
			// ------------
			if ($this->filter_by_entries_not)
			{
				$sql .= "AND exp_category_posts.entry_id NOT IN ('".implode("','", $this->entries_not_list)."') ";
			}
			if ($this->filter_by_entries)
			{
				$sql .= "AND exp_category_posts.entry_id IN ('".implode("','", $this->entries_list)."') ";
			}
			// ------------
			// end filter block
			/* */			
			

			if ($this->EE->TMPL->fetch_param('show_empty') == 'no')
			{
				if ($channel_id != '')
				{
					$sql .= "AND exp_channel_titles.channel_id = '".$channel_id."' ";
				}
				else
				{
					$sql .= " AND exp_channel_titles.site_id IN ('".implode("','", $this->EE->TMPL->site_ids)."') ";
				}

				if ($status = $this->EE->TMPL->fetch_param('status'))
				{
					$status = str_replace('Open',	'open',	$status);
					$status = str_replace('Closed', 'closed', $status);

					$sql .= $this->EE->functions->sql_andor_string($status, 'exp_channel_titles.status');
				}
				else
				{
					$sql .= "AND exp_channel_titles.status = 'open' ";
				}

				if ($this->EE->TMPL->fetch_param('show_empty') == 'no')
				{
					$sql .= "AND exp_category_posts.cat_id IS NOT NULL ";
				}
			}

			if ($this->EE->TMPL->fetch_param('show') !== FALSE)
			{
				$sql .= $this->EE->functions->sql_andor_string($this->EE->TMPL->fetch_param('show'), 'c.cat_id').' ';
			}

			if ($parent_only == TRUE)
			{
				$sql .= " AND c.parent_id = 0";
			}

			$sql .= " ORDER BY c.group_id, c.parent_id, c.cat_order";
		 	$query = $this->EE->db->query($sql);

			if ($query->num_rows() > 0)
			{
				$this->EE->load->library('typography');
				$this->EE->typography->initialize();

				$this->EE->typography->convert_curly = FALSE;

				$used = array();

				foreach($query->result_array() as $row)
				{
					if ( ! isset($used[$row['cat_name']]))
					{
						$chunk = $cat_chunk;

						$cat_vars = array('category_name'			=> $row['cat_name'],
										  'category_url_title'		=> $row['cat_url_title'],
										  'category_description'	=> $row['cat_description'],
										  'category_image'			=> $row['cat_image'],
										  'category_id'				=> $row['cat_id'],
										  'parent_id'				=> $row['parent_id']
										);

						foreach ($this->catfields as $v)
						{
							$cat_vars[$v['field_name']] = ( ! isset($row['field_id_'.$v['field_id']])) ? '' : $row['field_id_'.$v['field_id']];
						}

						$chunk = $this->EE->functions->prep_conditionals($chunk, $cat_vars);

						$chunk = str_replace( array(LD.'category_id'.RD,
													LD.'category_name'.RD,
													LD.'category_url_title'.RD,
													LD.'category_image'.RD,
													LD.'category_description'.RD,
													LD.'parent_id'.RD),
											  array($row['cat_id'],
											  		$row['cat_name'],
													$row['cat_url_title'],
											  		$row['cat_image'],
											  		$row['cat_description'],
													$row['parent_id']),
											  $chunk);

						foreach($c_path as $ckey => $cval)
						{
							$cat_seg = ($this->use_category_names == TRUE) ? $this->reserved_cat_segment.'/'.$row['cat_url_title'] : 'C'.$row['cat_id'];
							$chunk = str_replace($ckey, $this->EE->functions->remove_double_slashes($cval.'/'.$cat_seg), $chunk);
						}

						// parse custom fields

						foreach($this->catfields as $cfv)
						{
							if (isset($row['field_id_'.$cfv['field_id']]) AND $row['field_id_'.$cfv['field_id']] != '')
							{
								$field_content = $this->EE->typography->parse_type($row['field_id_'.$cfv['field_id']],
																			array(
																				  'text_format'		=> $row['field_ft_'.$cfv['field_id']],
																				  'html_format'		=> $row['field_html_formatting'],
																				  'auto_links'		=> 'n',
																				  'allow_img_url'	=> 'y'
																				)
																		);
								$chunk = str_replace(LD.$cfv['field_name'].RD, $field_content, $chunk);
							}
							else
							{
								// garbage collection
								$chunk = str_replace(LD.$cfv['field_name'].RD, '', $chunk);
							}
						}

						$str .= $chunk;
						$used[$row['cat_name']] = TRUE;
					}

					foreach($result->result_array() as $trow)
					{
						if ($trow['cat_id'] == $row['cat_id'])
						{
							$chunk = str_replace(array(LD.'title'.RD, LD.'category_name'.RD),
												 array($trow['title'],$row['cat_name']),
												 $tit_chunk);
												 
							/* */
							// {entry_id}, {url_title}
							// An extra replace statement to allow display of entry_id and url_title variables.
							// (This block takes effect when style="linear" is used.)
							// ------------
							
							$chunk = str_replace(array(LD.'entry_id'.RD, LD.'url_title'.RD),
												 array($trow['entry_id'],$trow['url_title']),
												 $chunk);
												 
							// ------------
							// end {entry_id}, {url_title} replacement
							/* */	

							foreach($t_path as $tkey => $tval)
							{
								$chunk = str_replace($tkey, $this->EE->functions->remove_double_slashes($tval.'/'.$trow['url_title']), $chunk);
							}

							foreach($id_path as $tkey => $tval)
							{
								$chunk = str_replace($tkey, $this->EE->functions->remove_double_slashes($tval.'/'.$trow['entry_id']), $chunk);
							}

							foreach($this->EE->TMPL->var_single as $key => $val)
							{
								if (isset($entry_date[$key]))
								{
									$val = str_replace($entry_date[$key], $this->EE->localize->convert_timestamp($entry_date[$key], $trow['entry_date'], TRUE), $val);

									$chunk = $this->EE->TMPL->swap_var_single($key, $val, $chunk);
								}

							}

							$str .= $chunk;
						}
					}
				}
			}

			if ($this->EE->TMPL->fetch_param('backspace'))
			{
				$str = substr($str, 0, - $this->EE->TMPL->fetch_param('backspace'));
			}
		}

		return $str;
	}


	/**
	* ==============================================
	* _Linear
	* ==============================================
	*
	* @access public
	* @return string
	*/
	private function _linear()
	{
	
		if ($this->enable['category_fields'])
		{
		
			$this->EE->db->select('field_id, field_name')
				->from('category_fields')
				->where('site_id', $this->params['site_id'])
				->where_in('group_id', explode("|",$this->group_id));
			
			$this->category_fields_info_q = $this->EE->db->get();
			
			if ($this->category_fields_info_q->num_rows() > 0)
			{
				foreach ($this->category_fields_info_q->result_array() as $row)
				{
					$this->category_fields_info[$row['field_id']] = array(
						'field_name' => $row['field_name'],
						'field_id' => $row['field_id']
					);
				}
			}
		
		}
		
		
		
		
		
		
		
		
		
		
		
		// fetch category field names and id's

		if ($this->enable['category_fields'] === TRUE)
		{

			if ($query->num_rows() > 0)
			{
				foreach ($query->result_array() as $row)
				{
					$this->catfields[] = array('field_name' => $row['field_name'], 'field_id' => $row['field_id']);
				}
			}

			$field_sqla = ", cg.field_html_formatting, fd.* ";
			$field_sqlb = " LEFT JOIN exp_category_field_data AS fd ON fd.cat_id = c.cat_id
							LEFT JOIN exp_category_groups AS cg ON cg.group_id = c.group_id ";
		}
		else
		{
			$field_sqla = '';
			$field_sqlb = '';
		}

		$sql = "SELECT DISTINCT (c.cat_id), c.cat_name, c.cat_url_title, c.cat_description, c.cat_image, c.group_id, c.parent_id {$field_sqla}
				FROM (exp_categories AS c";

		if ($this->EE->TMPL->fetch_param('show_empty') != 'no' AND $channel_id != '')
		{
			$sql .= ", exp_category_posts ";
		}

		$sql .= ") {$field_sqlb}";

		if ($this->EE->TMPL->fetch_param('show_empty') == 'no')
		{
			$sql .= " LEFT JOIN exp_category_posts ON c.cat_id = exp_category_posts.cat_id ";

			if ($channel_id != '')
			{
				$sql .= " LEFT JOIN exp_channel_titles ON exp_category_posts.entry_id = exp_channel_titles.entry_id ";
			}
		}
		
		$sql .= " WHERE c.group_id IN ('".str_replace('|', "','", $this->EE->db->escape_str($group_id))."')
					AND c.group_id NOT IN ('WOOHOO-LINE-413') ";
		
		/* */
		// FILTER BY ENTRY LISTS
		// Tack on a bit of query to include only the entries that are/aren't in the set we have specied.
		// (This block takes effect when style="linear" is used.)
		// ------------
		if ($this->filter_by_entries_not)
		{
			$sql .= "AND exp_category_posts.entry_id NOT IN ('".implode("','", $this->entries_not_list)."') ";
		}
		if ($this->filter_by_entries)
		{
			$sql .= "AND exp_category_posts.entry_id IN ('".implode("','", $this->entries_list)."') ";
		}
		// ------------
		// end filter block
		/* */			
		

		if ($this->EE->TMPL->fetch_param('show_empty') == 'no')
		{
			if ($channel_id != '')
			{
				$sql .= "AND exp_channel_titles.channel_id = '".$channel_id."' ";
			}
			else
			{
				$sql .= " AND exp_channel_titles.site_id IN ('".implode("','", $this->EE->TMPL->site_ids)."') ";
			}

			if ($status = $this->EE->TMPL->fetch_param('status'))
			{
				$status = str_replace('Open',	'open',	$status);
				$status = str_replace('Closed', 'closed', $status);

				$sql .= $this->EE->functions->sql_andor_string($status, 'exp_channel_titles.status');
			}
			else
			{
				$sql .= "AND exp_channel_titles.status = 'open' ";
			}

			if ($this->EE->TMPL->fetch_param('show_empty') == 'no')
			{
				$sql .= "AND exp_category_posts.cat_id IS NOT NULL ";
			}
		}

		if ($this->EE->TMPL->fetch_param('show') !== FALSE)
		{
			$sql .= $this->EE->functions->sql_andor_string($this->EE->TMPL->fetch_param('show'), 'c.cat_id').' ';
		}

		if ($parent_only == TRUE)
		{
			$sql .= " AND c.parent_id = 0";
		}

		$sql .= " ORDER BY c.group_id, c.parent_id, c.cat_order";
	 	$query = $this->EE->db->query($sql);

		if ($query->num_rows() > 0)
		{
			$this->EE->load->library('typography');
			$this->EE->typography->initialize();

			$this->EE->typography->convert_curly = FALSE;

			$used = array();

			foreach($query->result_array() as $row)
			{
				if ( ! isset($used[$row['cat_name']]))
				{
					$chunk = $cat_chunk;

					$cat_vars = array('category_name'			=> $row['cat_name'],
									  'category_url_title'		=> $row['cat_url_title'],
									  'category_description'	=> $row['cat_description'],
									  'category_image'			=> $row['cat_image'],
									  'category_id'				=> $row['cat_id'],
									  'parent_id'				=> $row['parent_id']
									);

					foreach ($this->catfields as $v)
					{
						$cat_vars[$v['field_name']] = ( ! isset($row['field_id_'.$v['field_id']])) ? '' : $row['field_id_'.$v['field_id']];
					}

					$chunk = $this->EE->functions->prep_conditionals($chunk, $cat_vars);

					$chunk = str_replace( array(LD.'category_id'.RD,
												LD.'category_name'.RD,
												LD.'category_url_title'.RD,
												LD.'category_image'.RD,
												LD.'category_description'.RD,
												LD.'parent_id'.RD),
										  array($row['cat_id'],
										  		$row['cat_name'],
												$row['cat_url_title'],
										  		$row['cat_image'],
										  		$row['cat_description'],
												$row['parent_id']),
										  $chunk);

					foreach($c_path as $ckey => $cval)
					{
						$cat_seg = ($this->use_category_names == TRUE) ? $this->reserved_cat_segment.'/'.$row['cat_url_title'] : 'C'.$row['cat_id'];
						$chunk = str_replace($ckey, $this->EE->functions->remove_double_slashes($cval.'/'.$cat_seg), $chunk);
					}

					// parse custom fields

					foreach($this->catfields as $cfv)
					{
						if (isset($row['field_id_'.$cfv['field_id']]) AND $row['field_id_'.$cfv['field_id']] != '')
						{
							$field_content = $this->EE->typography->parse_type($row['field_id_'.$cfv['field_id']],
																		array(
																			  'text_format'		=> $row['field_ft_'.$cfv['field_id']],
																			  'html_format'		=> $row['field_html_formatting'],
																			  'auto_links'		=> 'n',
																			  'allow_img_url'	=> 'y'
																			)
																	);
							$chunk = str_replace(LD.$cfv['field_name'].RD, $field_content, $chunk);
						}
						else
						{
							// garbage collection
							$chunk = str_replace(LD.$cfv['field_name'].RD, '', $chunk);
						}
					}

					$str .= $chunk;
					$used[$row['cat_name']] = TRUE;
				}

				foreach($result->result_array() as $trow)
				{
					if ($trow['cat_id'] == $row['cat_id'])
					{
						$chunk = str_replace(array(LD.'title'.RD, LD.'category_name'.RD),
											 array($trow['title'],$row['cat_name']),
											 $tit_chunk);
											 
						/* */
						// {entry_id}, {url_title}
						// An extra replace statement to allow display of entry_id and url_title variables.
						// (This block takes effect when style="linear" is used.)
						// ------------
						
						$chunk = str_replace(array(LD.'entry_id'.RD, LD.'url_title'.RD),
											 array($trow['entry_id'],$trow['url_title']),
											 $chunk);
											 
						// ------------
						// end {entry_id}, {url_title} replacement
						/* */	

						foreach($t_path as $tkey => $tval)
						{
							$chunk = str_replace($tkey, $this->EE->functions->remove_double_slashes($tval.'/'.$trow['url_title']), $chunk);
						}

						foreach($id_path as $tkey => $tval)
						{
							$chunk = str_replace($tkey, $this->EE->functions->remove_double_slashes($tval.'/'.$trow['entry_id']), $chunk);
						}

						foreach($this->EE->TMPL->var_single as $key => $val)
						{
							if (isset($entry_date[$key]))
							{
								$val = str_replace($entry_date[$key], $this->EE->localize->convert_timestamp($entry_date[$key], $trow['entry_date'], TRUE), $val);

								$chunk = $this->EE->TMPL->swap_var_single($key, $val, $chunk);
							}

						}

						$str .= $chunk;
					}
				}
			}
		}

		if ($this->EE->TMPL->fetch_param('backspace'))
		{
			$str = substr($str, 0, - $this->EE->TMPL->fetch_param('backspace'));
		}
	
	} // END _linear()






	// ------------------------------------------------------------------------

	/**
	  *  Category Tree
	  *
	  * This function and the next create a nested, hierarchical category tree
	  */
	function category_tree($cdata = array())
	{
		$default = array('group_id', 'channel_id', 'path', 'template', 'depth', 'channel_array', 'parent_only', 'show_empty', 'strict_empty');

		foreach ($default as $val)
		{
			$$val = ( ! isset($cdata[$val])) ? '' : $cdata[$val];
		}

		if ($group_id == '')
		{
			return FALSE;
		}

		if ($this->enable['category_fields'] === TRUE)
		{
			$query = $this->EE->db->query("SELECT field_id, field_name
								FROM exp_category_fields
								WHERE site_id IN ('".implode("','", $this->EE->TMPL->site_ids)."')
								AND group_id IN ('".str_replace('|', "','", $this->EE->db->escape_str($group_id))."')
								AND group_id NOT IN ('WOOHOO-LINE-605')");

			if ($query->num_rows() > 0)
			{
				foreach ($query->result_array() as $row)
				{
					$this->catfields[] = array('field_name' => $row['field_name'], 'field_id' => $row['field_id']);
				}
			}

			$field_sqla = ", cg.field_html_formatting, fd.* ";
			$field_sqlb = " LEFT JOIN exp_category_field_data AS fd ON fd.cat_id = c.cat_id
							LEFT JOIN exp_category_groups AS cg ON cg.group_id = c.group_id";
		}
		else
		{
			$field_sqla = '';
			$field_sqlb = '';
		}

		/** -----------------------------------
		/**  Are we showing empty categories
		/** -----------------------------------*/

		// If we are only showing categories that have been assigned to entries
		// we need to run a couple queries and run a recursive function that
		// figures out whether any given category has a parent.
		// If we don't do this we will run into a problem in which parent categories
		// that are not assigned to a channel will be supressed, and therefore, any of its
		// children will be supressed also - even if they are assigned to entries.
		// So... we will first fetch all the category IDs, then only the ones that are assigned
		// to entries, and lastly we'll recursively run up the tree and fetch all parents.
		// Follow that?  No?  Me neither...

		if ($show_empty == 'no')
		{
			// First we'll grab all category ID numbers

			$query = $this->EE->db->query("SELECT cat_id, parent_id FROM exp_categories
								 WHERE group_id IN ('".str_replace('|', "','", $this->EE->db->escape_str($group_id))."')
								 AND group_id NOT IN ('WOOHOO-LINE-635')
								 ORDER BY group_id, parent_id, cat_order");

			$all = array();

			// No categories exist?  Back to the barn for the night..
			if ($query->num_rows() == 0)
			{
				return FALSE;
			}

			foreach($query->result_array() as $row)
			{
				$all[$row['cat_id']] = $row['parent_id'];
			}

			// Next we'l grab only the assigned categories

			$sql = "SELECT DISTINCT(exp_categories.cat_id), parent_id
					FROM exp_categories
					LEFT JOIN exp_category_posts ON exp_categories.cat_id = exp_category_posts.cat_id
					LEFT JOIN exp_channel_titles ON exp_category_posts.entry_id = exp_channel_titles.entry_id ";
			

			$sql .= "WHERE group_id IN ('".str_replace('|', "','", $this->EE->db->escape_str($group_id))."')
						AND group_id NOT IN ('WOOHOO-LINE-669') ";

			$sql .= "AND exp_category_posts.cat_id IS NOT NULL ";
			
			
			/* */
			// FILTER BY ENTRY LISTS
			// Tack on a bit of query to include only the entries that are/aren't in the set we have specied.
			// (This block takes effect when style="nested" is used AND show_empty="no".)
			// ------------
			if ($this->filter_by_entries_not)
			{
				$sql .= "AND exp_channel_titles.entry_id NOT IN ('".implode("','", $this->entries_not_list)."') ";
			}
			if ($this->filter_by_entries)
			{
				$sql .= "AND exp_channel_titles.entry_id IN ('".implode("','", $this->entries_list)."') ";
			}
			// ------------
			// end filter block
			/* */
			
						

			if ($channel_id != '' && $strict_empty == 'yes')
			{
				$sql .= "AND exp_channel_titles.channel_id = '".$channel_id."' ";
			}
			else
			{
				$sql .= "AND exp_channel_titles.site_id IN ('".implode("','", $this->EE->TMPL->site_ids)."') ";
			}

			if (($status = $this->EE->TMPL->fetch_param('status')) !== FALSE)
	        {
				$status = str_replace(array('Open', 'Closed'), array('open', 'closed'), $status);
	            $sql .= $this->EE->functions->sql_andor_string($status, 'exp_channel_titles.status');
	        }
	        else
	        {
	            $sql .= "AND exp_channel_titles.status != 'closed' ";
	        }

			/**------
			/**  We only select entries that have not expired
			/**------*/

			$timestamp = ($this->EE->TMPL->cache_timestamp != '') ? $this->EE->localize->set_gmt($this->EE->TMPL->cache_timestamp) : $this->EE->localize->now;

			if ($this->EE->TMPL->fetch_param('show_future_entries') != 'yes')
			{
				$sql .= " AND exp_channel_titles.entry_date < ".$timestamp." ";
			}

			if ($this->EE->TMPL->fetch_param('show_expired') != 'yes')
			{
				$sql .= " AND (exp_channel_titles.expiration_date = 0 OR exp_channel_titles.expiration_date > ".$timestamp.") ";
			}

			if ($parent_only === TRUE)
			{
				$sql .= " AND parent_id = 0";
			}

			$sql .= " ORDER BY group_id, parent_id, cat_order";

			$query = $this->EE->db->query($sql);

			if ($query->num_rows() == 0)
			{
				return FALSE;
			}

			// All the magic happens here, baby!!

			foreach($query->result_array() as $row)
			{
				if ($row['parent_id'] != 0)
				{
					$this->find_parent($row['parent_id'], $all);
				}

				$this->cat_full_array[] = $row['cat_id'];
			}

			$this->cat_full_array = array_unique($this->cat_full_array);

			$sql = "SELECT c.cat_id, c.parent_id, c.cat_name, c.cat_url_title, c.cat_image, c.cat_description {$field_sqla}
			FROM exp_categories AS c
			{$field_sqlb}
			WHERE c.cat_id IN (";

			foreach ($this->cat_full_array as $val)
			{
				$sql .= $val.',';
			}

			$sql = substr($sql, 0, -1).')';

			$sql .= " ORDER BY c.group_id, c.parent_id, c.cat_order";

			$query = $this->EE->db->query($sql);

			if ($query->num_rows() == 0)
			{
				return FALSE;
			}
		}
		else
		{
			$sql = "SELECT DISTINCT(c.cat_id), c.parent_id, c.cat_name, c.cat_url_title, c.cat_image, c.cat_description {$field_sqla}
					FROM exp_categories AS c
					{$field_sqlb}
					WHERE c.group_id IN ('".str_replace('|', "','", $this->EE->db->escape_str($group_id))."')
					AND c.group_id NOT IN ('WOOHOO-LINE-763') ";

			if ($parent_only === TRUE)
			{
				$sql .= " AND c.parent_id = 0";
			}

			$sql .= " ORDER BY c.group_id, c.parent_id, c.cat_order";

			$query = $this->EE->db->query($sql);

			if ($query->num_rows() == 0)
			{
				return FALSE;
			}
		}

		// Here we check the show parameter to see if we have any
		// categories we should be ignoring or only a certain group of
		// categories that we should be showing.  By doing this here before
		// all of the nested processing we should keep out all but the
		// request categories while also not having a problem with having a
		// child but not a parent.  As we all know, categories are not asexual

		if ($this->EE->TMPL->fetch_param('show') !== FALSE)
		{
			if (strncmp($this->EE->TMPL->fetch_param('show'), 'not ', 4) == 0)
			{
				$not_these = explode('|', trim(substr($this->EE->TMPL->fetch_param('show'), 3)));
			}
			else
			{
				$these = explode('|', trim($this->EE->TMPL->fetch_param('show')));
			}
		}

		foreach($query->result_array() as $row)
		{
			if (isset($not_these) && in_array($row['cat_id'], $not_these))
			{
				continue;
			}
			elseif(isset($these) && ! in_array($row['cat_id'], $these))
			{
				continue;
			}

			$this->cat_array[$row['cat_id']]  = array($row['parent_id'], $row['cat_name'], $row['cat_image'], $row['cat_description'], $row['cat_url_title']);

			foreach ($row as $key => $val)
			{
				if (strpos($key, 'field') !== FALSE)
				{
					$this->cat_array[$row['cat_id']][$key] = $val;
				}
			}
		}

		$this->temp_array = $this->cat_array;

		$open = 0;

		$this->EE->load->library('typography');
		$this->EE->typography->initialize();
		$this->EE->typography->convert_curly = FALSE;

		$this->category_count = 0;
		$total_results = count($this->cat_array);

		foreach($this->cat_array as $key => $val)
		{
			if (0 == $val[0])
			{
				if ($open == 0)
				{
					$open = 1;

					$this->category_list[] = "<ul>\n";
				}

				$chunk = $template;

				$cat_vars = array('category_name'			=> $val[1],
								  'category_url_title'		=> $val[4],
								  'category_description'	=> $val[3],
								  'category_image'			=> $val[2],
								  'category_id'				=> $key,
								  'parent_id'				=> $val[0]
								);

				// add custom fields for conditionals prep

				foreach ($this->catfields as $v)
				{
					$cat_vars[$v['field_name']] = ( ! isset($val['field_id_'.$v['field_id']])) ? '' : $val['field_id_'.$v['field_id']];
				}

				$cat_vars['count'] = ++$this->category_count;
				$cat_vars['total_results'] = $total_results;

				$chunk = $this->EE->functions->prep_conditionals($chunk, $cat_vars);

				$chunk = str_replace( array(LD.'category_id'.RD,
											LD.'category_name'.RD,
											LD.'category_url_title'.RD,
											LD.'category_image'.RD,
											LD.'category_description'.RD,
											LD.'parent_id'.RD),
									  array($key,
									  		$val[1],
											$val[4],
									  		$val[2],
									  		$val[3],
											$val[0]),
									  $chunk);

				foreach($path as $pkey => $pval)
				{
					if ($this->use_category_names == TRUE)
					{
						$chunk = str_replace($pkey, $this->EE->functions->remove_double_slashes($pval.'/'.$this->reserved_cat_segment.'/'.$val[4]), $chunk);
					}
					else
					{
						$chunk = str_replace($pkey, $this->EE->functions->remove_double_slashes($pval.'/C'.$key), $chunk);
					}
				}

				// parse custom fields
				foreach($this->catfields as $cval)
				{
					if (isset($val['field_id_'.$cval['field_id']]) AND $val['field_id_'.$cval['field_id']] != '')
					{
						$field_content = $this->EE->typography->parse_type($val['field_id_'.$cval['field_id']],
																	array(
																		  'text_format'		=> $val['field_ft_'.$cval['field_id']],
																		  'html_format'		=> $val['field_html_formatting'],
																		  'auto_links'		=> 'n',
																		  'allow_img_url'	=> 'y'
																		)
																);
						$chunk = str_replace(LD.$cval['field_name'].RD, $field_content, $chunk);
					}
					else
					{
						// garbage collection
						$chunk = str_replace(LD.$cval['field_name'].RD, '', $chunk);
					}
				}

				/** --------------------------------
				/**  {count}
				/** --------------------------------*/

				if (strpos($chunk, LD.'count'.RD) !== FALSE)
				{
					$chunk = str_replace(LD.'count'.RD, $this->category_count, $chunk);
				}

				/** --------------------------------
				/**  {total_results}
				/** --------------------------------*/

				if (strpos($chunk, LD.'total_results'.RD) !== FALSE)
				{
					$chunk = str_replace(LD.'total_results'.RD, $total_results, $chunk);
				}

				$this->category_list[] = "\t<li>".$chunk;

				if (is_array($channel_array))
				{
					$fillable_entries = 'n';

					foreach($channel_array as $k => $v)
					{
						$k = substr($k, strpos($k, '_') + 1);

						if ($key == $k)
						{
							if ($fillable_entries == 'n')
							{
								$this->category_list[] = "\n\t\t<ul>\n";
								$fillable_entries = 'y';
							}

							$this->category_list[] = "\t\t\t$v\n";
						}
					}
				}

				if (isset($fillable_entries) && $fillable_entries == 'y')
				{
					$this->category_list[] = "\t\t</ul>\n";
				}

				$this->category_subtree(
											array(
													'parent_id'		=> $key,
													'path'			=> $path,
													'template'		=> $template,
													'channel_array' 	=> $channel_array
												  )
									);
				$t = '';

				if (isset($fillable_entries) && $fillable_entries == 'y')
				{
					$t .= "\t";
				}

				$this->category_list[] = $t."</li>\n";

				unset($this->temp_array[$key]);

				$this->close_ul(0);
			}
		}
	}

	// ------------------------------------------------------------------------

	/**
	  *  Category Sub-tree
	  */
	function category_subtree($cdata = array())
	{
		$default = array('parent_id', 'path', 'template', 'depth', 'channel_array', 'show_empty');

		foreach ($default as $val)
		{
				$$val = ( ! isset($cdata[$val])) ? '' : $cdata[$val];
		}

		$open = 0;

		if ($depth == '')
				$depth = 1;

		$tab = '';
		for ($i = 0; $i <= $depth; $i++)
			$tab .= "\t";

		$total_results = count($this->cat_array);

		foreach($this->cat_array as $key => $val)
		{
			if ($parent_id == $val[0])
			{
				if ($open == 0)
				{
					$open = 1;
					$this->category_list[] = "\n".$tab."<ul>\n";
				}

				$chunk = $template;

				$cat_vars = array('category_name'			=> $val[1],
								  'category_url_title'		=> $val[4],
								  'category_description'	=> $val[3],
								  'category_image'			=> $val[2],
								  'category_id'				=> $key,
								  'parent_id'				=> $val[0]);

				// add custom fields for conditionals prep
				foreach ($this->catfields as $v)
				{
					$cat_vars[$v['field_name']] = ( ! isset($val['field_id_'.$v['field_id']])) ? '' : $val['field_id_'.$v['field_id']];
				}

				$cat_vars['count'] = ++$this->category_count;
				$cat_vars['total_results'] = $total_results;

				$chunk = $this->EE->functions->prep_conditionals($chunk, $cat_vars);

				$chunk = str_replace( array(LD.'category_id'.RD,
											LD.'category_name'.RD,
											LD.'category_url_title'.RD,
											LD.'category_image'.RD,
											LD.'category_description'.RD,
											LD.'parent_id'.RD),
									  array($key,
									  		$val[1],
											$val[4],
									  		$val[2],
									  		$val[3],
											$val[0]),
									  $chunk);

				foreach($path as $pkey => $pval)
				{
					if ($this->use_category_names == TRUE)
					{
						$chunk = str_replace($pkey, $this->EE->functions->remove_double_slashes($pval.'/'.$this->reserved_cat_segment.'/'.$val[4]), $chunk);
					}
					else
					{
						$chunk = str_replace($pkey, $this->EE->functions->remove_double_slashes($pval.'/C'.$key), $chunk);
					}
				}

				// parse custom fields
				foreach($this->catfields as $ccv)
				{
					if (isset($val['field_id_'.$ccv['field_id']]) AND $val['field_id_'.$ccv['field_id']] != '')
					{
						$field_content = $this->EE->typography->parse_type($val['field_id_'.$ccv['field_id']],
																	array(
																		  'text_format'		=> $val['field_ft_'.$ccv['field_id']],
																		  'html_format'		=> $val['field_html_formatting'],
																		  'auto_links'		=> 'n',
																		  'allow_img_url'	=> 'y'
																		)
																);
						$chunk = str_replace(LD.$ccv['field_name'].RD, $field_content, $chunk);
					}
					else
					{
						// garbage collection
						$chunk = str_replace(LD.$ccv['field_name'].RD, '', $chunk);
					}
				}


				/** --------------------------------
				/**  {count}
				/** --------------------------------*/

				if (strpos($chunk, LD.'count'.RD) !== FALSE)
				{
					$chunk = str_replace(LD.'count'.RD, $this->category_count, $chunk);
				}

				/** --------------------------------
				/**  {total_results}
				/** --------------------------------*/

				if (strpos($chunk, LD.'total_results'.RD) !== FALSE)
				{
					$chunk = str_replace(LD.'total_results'.RD, $total_results, $chunk);
				}

				$this->category_list[] = $tab."\t<li>".$chunk;

				if (is_array($channel_array))
				{
					$fillable_entries = 'n';

					foreach($channel_array as $k => $v)
					{
						$k = substr($k, strpos($k, '_') + 1);

						if ($key == $k)
						{
							if ( ! isset($fillable_entries) OR $fillable_entries == 'n')
							{
								$this->category_list[] = "\n{$tab}\t\t<ul>\n";
								$fillable_entries = 'y';
							}

							$this->category_list[] = "{$tab}\t\t\t$v";
						}
					}
				}

				if (isset($fillable_entries) && $fillable_entries == 'y')
				{
					$this->category_list[] = "{$tab}\t\t</ul>\n";
				}

				$t = '';

				if ($this->category_subtree(
											array(
													'parent_id'		=> $key,
													'path'			=> $path,
													'template'		=> $template,
													'depth' 			=> $depth + 2,
													'channel_array' 	=> $channel_array
												  )
									) != 0 );

			if (isset($fillable_entries) && $fillable_entries == 'y')
			{
				$t .= "$tab\t";
			}

				$this->category_list[] = $t."</li>\n";

				unset($this->temp_array[$key]);

				$this->close_ul($parent_id, $depth + 1);
			}
		}
		return $open;
	}

	// ------------------------------------------------------------------------

	/**
	  *  Close </ul> tags
	  *
	  * This is a helper function to the above
	  */
	function close_ul($parent_id, $depth = 0)
	{
		$count = 0;

		$tab = "";
		for ($i = 0; $i < $depth; $i++)
		{
			$tab .= "\t";
		}

		foreach ($this->temp_array as $val)
		{
		 	if ($parent_id == $val[0])

		 	$count++;
		}

		if ($count == 0)
			$this->category_list[] = $tab."</ul>\n";
	}



	// ------------------------------------------------------------------------

	/** --------------------------------
	/**  Locate category parent
	/** --------------------------------*/
	// This little recursive gem will travel up the
	// category tree until it finds the category ID
	// number of any parents.  It's used by the function
	// below
	function find_parent($parent, $all)
	{
		foreach ($all as $cat_id => $parent_id)
		{
			if ($parent == $cat_id)
			{
				$this->cat_full_array[] = $cat_id;

				if ($parent_id != 0)
					$this->find_parent($parent_id, $all);
			}
		}
	}

	// ------------------------------------------------------------------------
		
					
	/** ----------------------------------------
	/**  Plugin Usage
	/** ----------------------------------------*/

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
		
		{entry_id} - The entry ID of each entry (in the {entry_titles} section)
		{url_title} - The URL title of each entry (in the {entry_titles} section)
		
		See http://michaelrog.com/ee/category-sorted-entries for detailed documentation.
		
		---------------
		
		This plugin is compatible with NSM Addon Updater:
		
		http://github.com/newism/nsm.addon_updater.ee_addon
	
		<?php
		$buffer = ob_get_contents();
		
		ob_end_clean(); 
	
		return $buffer;
		
	} // END usage()



	/**
	* ==============================================
	* Debug functions
	* ==============================================
	*
	* @access public
	* @return string
	*/
	
	public function spit_enable() {
	
		return '<pre>' . print_r($this->enable, TRUE) . '</pre>';
	
	} // END spit_enable()
	
	public function spit_params() {
	
		return '<pre>' . print_r($this->params, TRUE) . '</pre>';
	
	} // END spit_params()

	public function spit_entry_data_q() {
	
		return '<pre>' . print_r($this->entry_data_q, TRUE) . '</pre>';
	
	} // END spit_entry_data_q()
	
	public function spit_categories_data_q() {
	
		return '<pre>' . print_r($this->categories_data_q, TRUE) . '</pre>';
	
	} // END spit_categories_data_q()
	
	public function spit_debug() {
	
		$a = array();
		$b = array();
		$b[] = "";
		$b[] = "a";
		$c = 'array_merge';
		$d = $c($a, $b);
		
		$e = "";
		$e = explode("|", $e);
		
		return '<pre>' . print_r($e, TRUE) . '</pre>';
	
	} // END spit_debug()



} // END Category_sorted_entries class

/* End of file pi.category_sorted_entries.php */ 
/* Location: ./system/expressionengine/third_party/category_sorted_entries/pi.category_sorted_entries.php */
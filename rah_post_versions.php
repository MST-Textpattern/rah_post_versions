<?php

/**
 * Rah_post_versions plugin for Textpattern CMS
 *
 * @author Jukka Svahn
 * @date 2010-
 * @license GNU GPLv2
 * @link http://rahforum.biz/plugins/rah_post_versions
 *
 * Copyright (C) 2012 Jukka Svahn <http://rahforum.biz>
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	if(@txpinterface == 'admin') {
		rah_post_versions::install();
		rah_post_versions::push();
		add_privs('rah_post_versions', '1,2');
		add_privs('rah_post_versions_delete', '1');
		add_privs('rah_post_versions_preferences', '1');
		add_privs('plugin_prefs.rah_post_versions', '1,2');
		register_tab('extensions','rah_post_versions', gTxt('rah_post_versions'));
		register_callback(array('rah_post_versions', 'deliver'), 'rah_post_versions');
		register_callback(array('rah_post_versions', 'head'), 'admin_side', 'head_end');
		register_callback(array('rah_post_versions', 'messager'), 'admin_side', 'pagetop_end');
		register_callback(array('rah_post_versions', 'install'), 'plugin_lifecycle.rah_post_versions');
		register_callback(array('rah_post_versions', 'prefs'), 'plugin_prefs.rah_post_versions');
	}

/**
 * Main class
 */

class rah_post_versions {

	protected $event = 'rah_post_versions';
	protected $pfx = 'rah_post_versions';
	protected $prefs_group = 'rah_postver';
	protected $list_filters = array();
	protected $ui;
	protected $diff;
	protected $sort;
	protected $events = array();
	protected $static_dir = false;
	protected $static_header = '';
	protected $nowrite = false;
	protected $compress = false;

	/**
	 * Installer
	 * @param string $event
	 * @param string $step
	 * @return nothing
	 */

	static public function install($event='', $step='') {
		
		global $prefs;
		
		if($step == 'deleted') {
			
			safe_delete(
				'txp_prefs',
				"name like 'rah\_post\_versions\_%'"
			);
			
			@safe_query(
				'DROP TABLE IF EXISTS '.
				safe_pfx('rah_post_versions').', '.
				safe_pfx('rah_post_versions_sets')
			);
			
			return;
		}
		
		$version = '1.0';
		
		$current = isset($prefs['rah_post_versions_version']) ? 
			$prefs['rah_post_versions_version'] : 'base';
			
		if($version == $current)
			return;
		
		/*
			Stores all changed grouped items
			
			* id: The row primary key
			* event: The event the post was recorded form.
			* step: Same, but the step.
			* title: The identifier (Article title, form name) that is used to identify the changeset record.
			* grid: The ident used to group the commit to sets (form name, article id etc).
			* modified: Time of the last change to the changeset.
			* changes: The number of changes the set holds.
		*/
		
		safe_query(
			'CREATE TABLE IF NOT EXISTS '.safe_pfx('rah_post_versions_sets')." (
				`id` INT(11) NOT NULL auto_increment,
				`event` VARCHAR(255) NOT NULL,
				`step` VARCHAR(255) NOT NULL,
				`grid` VARCHAR(255) NOT NULL,
				`title` VARCHAR(255) NOT NULL,
				`modified` DATETIME NOT NULL default '0000-00-00 00:00:00',
				`changes` INT(12) NOT NULL default 0,
				PRIMARY KEY(`id`),
				KEY `event_grid_idx` (`event`(24),`grid`(32))
			) PACK_KEYS=1 AUTO_INCREMENT=1 CHARSET=utf8"
		);
		
		/*
			Stores the changes
			
			* id: The row primary key
			* event: The event the post was recorded form.
			* step: Same, but the step.
			* title: The identifier (Article title, form name) that is used to identify the changeset record.
			* grid: The ident used to group the commit to sets (form name, article id etc).
			* author: The user posted the post.
			* posted: Time of the post.
			* data: All the post data
		*/
		
		safe_query(
			'CREATE TABLE IF NOT EXISTS '.safe_pfx('rah_post_versions')." (
				`id` INT(11) NOT NULL auto_increment,
				`event` VARCHAR(255) NOT NULL,
				`step` VARCHAR(255) NOT NULL,
				`title` VARCHAR(255) NOT NULL,
				`grid` VARCHAR(255) NOT NULL,
				`setid` INT(11) NOT NULL,
				`author` VARCHAR(64) NOT NULL,
				`posted` DATETIME NOT NULL default '0000-00-00 00:00:00',
				`data` LONGTEXT NOT NULL,
				PRIMARY KEY(`id`),
				KEY `setid_idx` (`setid`)
			) PACK_KEYS=1 AUTO_INCREMENT=1 CHARSET=utf8"
		);
		
		
		$ini =
			array(
				'exclude' => '',
				'authors' => '',
				'events' => 'article:edit, article:create, article:publish, article:save, page:page_create, page:page_edit, page:page_save, form:form_create, form:form_edit, form:form_save, prefs:prefs_save, prefs:advanced_prefs_save, category:cat_article_create, category:cat_image_create, category:cat_file_create, category:cat_link_create, category:cat_article_save, category:cat_image_save, category:cat_file_save, category:cat_link_save, section:section_create, section:section_save, link:link_post, link:link_save, discuss:discuss_save, image:image_save, file:file_save, css:css_save, rah_external_output:save, rah_autogrowing_textarea:save',
				'hidden' => 'event,step,save',
				'ident' => 
					'switch($event) {'.n.
					'	case "article":'.n.
					'		$grid = rah_post_versions_id();'.n.
					'		$title = gps("Title");'.n.
					'	break;'.n.
					'	case "category":'.n.
					'		$grid = rah_post_versions_id("id");'.n.
					'		$title = gps("title");'.n.
					'	break;'.n.
					'	case "link":'.n.
					'		$grid = rah_post_versions_id("id");'.n.
					'		$title = gps("linkname");'.n.
					'	break;'.n.
					'	case "prefs":'.n.
					'		$grid = $title = ($step == "advanced_prefs_save") ? "Advanced prefs" : "Preferences";'.n.
					'	break;'.n.
					'	case "discuss":'.n.
					'		$grid = gps("discussid");'.n.
					'		$title = gps("name");'.n.
					'	break;'.n.
					'	case "section":'.n.
					'		$grid = gps("name");'.n.
					'		$title = (gps("title")) ? gps("title") : gps("name");'.n.
					'	break;'.n.
					'	case "image":'.n.
					'		$grid = rah_post_versions_id("id");'.n.
					'		$title = (gps("name")) ? gps("name") : $grid;'.n.
					'	break;'.n.
					'	case "file":'.n.
					'		$grid = gps("id");'.n.
					'		$title = gps("filename");'.n.
					'	break;'.n.
					'	default:'.n.
					'		$grid = $title = gps("name");'.n.
					'}',
			);
		
		/*
			Migrate preferences from <= 0.9 to >= 1.0
		*/
		
		if($current == 'base') {
			
			@$rs = 
				safe_rows(
					'name, value',
					'rah_post_versions_prefs',
					'1=1'
				);
			
			if(!empty($rs) && is_array($rs)) {
				
				foreach($rs as $a) {
					
					if(!isset($ini[$a['name']]))
						continue;
					
					$ini[$a['name']] = $a['value'];
				}

				@safe_query(
					'DROP TABLE IF EXISTS '.safe_pfx('rah_post_versions_prefs')
				);
			}
		}
		
		$position = 250;
		
		/*
			Add preference strings
		*/
		
		foreach($ini as $name => $val) {

			$n = 'rah_post_versions_' . $name;
			
			if(!isset($prefs[$n])) {

				switch($name) {
					case 'events':
					case 'ident':
						$html = 'rah_post_versions_textarea';
						break;
					default:
						$html = 'text_input';
				}

				safe_insert(
					'txp_prefs',
					"prefs_id=1,
					name='".doSlash($n)."',
					val='".doSlash($val)."',
					type=1,
					event='rah_postver',
					html='$html',
					position=".$position
				);
				
				$prefs[$n] = $val;
			}
			
			$position++;
		}
		
		set_pref('rah_post_versions_version', $version, 'rah_postver', 2, '', 0);
		$prefs['rah_post_versions_version'] = $version;
	}

	/**
	 * Redirects to the plugin's admin-side panel
	 */

	static public function prefs() {
		header('Location: ?event=rah_post_versions');
		echo 
			'<p id="message">'.n.
			'	<a href="?event=rah_post_versions">'.gTxt('continue').'</a>'.n.
			'</p>';
	}

	/**
	 * Adds the panel's CSS and JavaScript to the <head>.
	 */

	static public function head() {
		global $event;

		if($event != 'rah_post_versions')
			return;

		$msg = gTxt('are_you_sure');
		$pfx = 'rah_post_versions';

		echo <<<EOF
			<script type="text/javascript">
				<!--
				{$pfx} = function () {
					
					var pfx = '{$pfx}';
					var pane = $('#'+pfx+'_container');
					var l10n = {
						'are_you_sure' : '{$msg}'
					};
					
					/**
						Multi-edit function, auto-hiden dropdown
					*/
				
					(function() {
						
						var steps = $('#'+pfx+'_step');
					
						if(!steps.length)
							return;
						
						steps.children('.smallerbox').hide();
						
						pane.find('th.rah_ui_selectall').html(
							'<input type="checkbox" name="selectall" value="1" />'
						);
						
						if(pane.children('input[type=checkbox]:checked').val() == null)
							steps.hide();
						
						/*
							Reset the value
						*/
	
						steps.children('select[name="step"]').val('');
						
						/*
							Check all
						*/
	
						pane.find('input[name="selectall"]').live('click',
							function() {
								var tr = pane.find('table tbody input[type=checkbox]');
								
								if($(this).is(':checked'))
									tr.attr('checked', true);
								else
									tr.removeAttr('checked');
							}
						);
						
						/*
							Every time something is checked, check if
							the dropdown should be shown
						*/
						
						pane.find('table input[type=checkbox], td').live('click',
							function(){
								steps.children('select[name="step"]').val('');
								
								if(pane.find('tbody input[type=checkbox]:checked').val() != null)
									steps.slideDown();
								else
									steps.slideUp();
							}
						);
						
						/*
							Uncheck the check all box if an item is unchecked
						*/
						
						pane.find('tbody input[type=checkbox]').live('click',
							function() {
								pane.find('input[name="selectall"]').removeAttr('checked');
							}
						);
	
						/*
							If value is changed, send the form
						*/
	
						steps.change(
							function(){
								steps.parents('form').submit();
							}
						);
	
						/*
							Verify if the sent is allowed
						*/
						
						
						$('form').submit(
							function() {
								if(!verify(l10n['are_you_sure'])) {
									steps.children('select[name="step"]').val('');
									return false;
								}
							}
						);
					})();
					
					/**
						Verify form submits
					*/
					
					(function() {
						$('form.'+pfx+'_verify').submit(
							function() {
								return verify(l10n['are_you_sure']);
							}
						);
					})();
				};

				$(document).ready(function(){
					{$pfx}();
				});
				-->
			</script>
			<style type="text/css">
				#{$pfx}_container {
					width: 950px;
					margin: 0 auto;
				}
				#{$pfx}_container .rah_ui_list_actions {
					margin: 10px 0;
					overflow: hidden;
				}
				#{$pfx}_container .rah_ui_view_limit,
				#{$pfx}_container .rah_ui_pages, 
				#{$pfx}_container .rah_ui_step {
					margin: 0;
					padding: 0;
					display: inline;
					float: left;
					width: 300px;
				}
				#{$pfx}_container .rah_ui_pages {
					text-align: center;
					margin: 0 0 0 25px;
				}
				#{$pfx}_container .rah_ui_active {
					color: #000;
					text-decoration: underline;
				}
				#{$pfx}_container .rah_ui_step {
					float: right;
					text-align: right;
				}
				#{$pfx}_container .rah_ui_step select {
					width: 120px;
				}
				#{$pfx}_actions {
					border-bottom: 1px solid #ccc;
					padding: 0 0 5px 0;
				}
				#{$pfx}_container table {
					width: 100%;
				}
				#{$pfx}_container input.edit,
				#{$pfx}_container textarea {
					width: 948px;
					padding: 0;
				}
				.{$pfx}_label {
					margin: 10px 0 1px 0;
				}
				.{$pfx}_diff {
					overflow: auto;
					white-space: pre;
					font: 1.1em "Courier New", Courier, "Andele Mono", Menlo, Consolas, Console, Monaco, monospace;
					line-height: 1.6em;
					background: #eee;
					border: 1px solid #ccc;
					padding: 10px;
					margin: 0;
					-moz-box-shadow: inset 1px 0 0 #efefef, inset 4px 0 0 #e5e3e3, inset 5px 0 0 #dcdada;
					-webkit-box-shadow: inset 1px 0 0 #efefef, inset 4px 0 0 #e5e3e3, inset 5px 0 0 #dcdada;
					-khtml-box-shadow: inset 1px 0 0 #efefef, inset 4px 0 0 #e5e3e3, inset 5px 0 0 #dcdada;
					-o-box-shadow: inset 1px 0 0 #efefef, inset 4px 0 0 #e5e3e3, inset 5px 0 0 #dcdada;
					-ms-box-shadow: inset 1px 0 0 #efefef, inset 4px 0 0 #e5e3e3, inset 5px 0 0 #dcdada;
					box-shadow: inset 1px 0 0 #efefef, inset 4px 0 0 #e5e3e3, inset 5px 0 0 #dcdada;
				}
				.{$pfx}_diff,
				.{$pfx}_add,
				.{$pfx}_del {
					-moz-border-radius: 3px;
					-webkit-border-radius: 3px;
					-khtml-border-radius: 3px;
					-o-border-radius: 3px;
					-ms-border-radius: 3px;
					border-radius: 3px;
				}
				.{$pfx}_add,
				.{$pfx}_del {
					-moz-box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
					-webkit-box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
					-khtml-box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
					-o-box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
					-ms-box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
					box-shadow: inset 0 1px 0 rgba(255,255,255,0.1);
					color: #000;
				}
				.{$pfx}_add {
					background: #a7d726;
					border: 1px solid #829b3e;
				}
				.{$pfx}_del {
					background: #c54e4e;
					border: 1px solid #8b3e3e;
				}
			</style>

EOF;
	}

	/**
	 * Picks changes from HTTP POST data.
	 * @param string $callback Callback event the function was called on.
	 * @return Nothing
	 */

	static public function push($callback=NULL) {
		
		global $txp_user, $event, $step, $prefs;
		
		if($callback === NULL) {

			foreach(explode(',',$prefs['rah_post_versions_events']) as $e)
				if(($e = explode(':',$e)) && count($e) == 2)
					register_callback(array('rah_post_versions', 'push'),trim($e[0]),trim($e[1]),0);

			return;
		}

		/*
			Check if the step has post
		*/
		
		if(!isset($_POST) || !is_array($_POST) || empty($_POST))
			return;
		
		/*
			Evaluates ident code
		*/
		
		eval($prefs['rah_post_versions_ident']);
		
		/*
			Check if the ident code returned the data we want
		*/
		
		if(isset($kill) || !isset($grid) || !isset($title))
			return;
		
		$f = new rah_post_versions();
		
		/*
			Build data array
		*/
		
		foreach($_POST as $key => $value)
			$data[$key] = ps($key);
		
		$f->create_revision(
			array(
				'grid' => $grid,
				'title' => $title,
				'author' => $txp_user,
				'event' => $event,
				'step' => $step,
				'data' => $data
			)
		);
	}

	/**
	 * Shows a message after reverting
	 */

	static public function messager() {
		
		if(!ps('rah_post_versions_repost_item') || ps('_txp_token') != form_token())
			return;
		
		extract(
			doArray(
				psa(array(
					'rah_post_versions_repost_item',
					'rah_post_versions_repost_id'
				)), 'htmlspecialchars'
			)
		);
		
		echo 
			'<div id="rah_post_versions_messager" style="text-align: center;">'.
				gTxt(
					'rah_post_versions_reposted_form_id',
					array(
						'{id}' => 'r'.$rah_post_versions_repost_id,
						'{go_back}' => '<a href="?event=rah_post_versions&amp;step=changes&amp;item='.$rah_post_versions_repost_item.'">'.gTxt('rah_post_versions_go_back_to_listing').'</a>'
					),
					false
				).
			'</div>';
		
		callback_event('rah_post_versions_tasks', 'messager_called');
	}

	/**
	 * Deliver panels
	 */

	static public function deliver() {
		$uix = new rah_post_versions_panes();
		$uix->panes();
	}

	/**
	 * Initialize required
	 */

	public function __construct() {
		$this->go_static();
		$this->compression();
	}

	/**
	 * Passes activity message to UIX
	 * @param string $msg
	 * @return obj
	 */

	protected function msg($msg) {
		$this->ui->msg($msg);
		return $this;
	}

	/**
	 * Shows requested admin-side page
	 */

	public function panes() {
		require_privs($this->event);

		global $step;

		$steps = 
			array(
				'items' => false,
				'changes' => false,
				'view' => false,
				'diff' => false,
				'delete_item' => true,
				'delete_revision' => true
			);

		if(!$step || !bouncer($step, $steps))
			$step = 'items';
		
		$this->ui->title($this->event);
		
		if($this->nowrite == true) {
			$this->ui->add('<p id="warning">'.gTxt($this->pfx.'_repository_data_missing').'</p>');
		}
		
		$this->$step();
	}

	/**
	 * Lists event names and labels
	 * @return array
	 */

	protected function pop_events() {

		if($this->events || !function_exists('areas') || !is_array(areas()))
			return $this->events;
		
		foreach(areas() as $tab_group) {
			foreach($tab_group as $label => $event) 
				$this->events[$event] = $label;
		}
		
		return $this->events;
	}
	
	/**
	 * Check for availability of compression methods
	 * @return bool
	 */

	protected function compression() {
		if(
			defined('rah_post_versions_compress') &&
			function_exists('gzencode') &&
			function_exists('gzinflate')
		) {
			$this->compress = true;
		}
		
		return $this->compress;
	}

	/**
	 * Get revision from database
	 * @param string $where SQL where statement
	 * @return array
	 */

	public function get_revision($where) {
		
		$r =
			safe_row(
				'*',
				$this->pfx,
				$where . ' LIMIT 0, 1'
			);
		
		if(!$r) {
			return array();
		}
		
		if($this->nowrite == true) {
			$r['data'] = '';
		}
		
		if($r && $this->static_dir) {
		
			$file = $this->static_dir . DS . $r['setid'] . '_r' . $r['id'] . '.php';
			
			if(file_exists($file) && is_file($file) && is_readable($file)) {
				ob_start();
				include $file;
				$r['data'] = ob_get_contents();
				ob_end_clean();
			}
			
			else {
				$r['data'] = '';
			}
		}
		
		if(!empty($r['data'])) {
			@$r['data'] = base64_decode($r['data']);

			if($this->compress && strncmp($r['data'], "\x1F\x8B", 2) === 0) {
				$r['data'] = gzinflate(substr($r['data'], 10));
			}
			
			$r['data'] = unserialize($r['data']);
		}

		return $r;
	}
	
	/**
	 * Creates a new revision
	 * @param array $data
	 * @return bool FALSE on error, TRUE on success or when new commit isn't needed.
	 */
	
	public function create_revision($data) {
		
		global $prefs;
		
		if(
			$this->nowrite == true ||
			empty($data['data']) ||
			count($data) != 6 ||
			!is_array($data['data']) ||
			!isset($data['author']) ||
			in_array($data['author'], do_list($prefs[$this->pfx.'_authors']))
		)
			return false;
		
		$exclude = 
			array_merge(
				do_list($prefs[$this->pfx.'_exclude']),
				array(
					$this->pfx.'_repost_item',
					$this->pfx.'_repost_id',
					'_txp_token'
				)
			);
		
		foreach($exclude as $name)
			unset($data['data'][$name]);
		
		if(empty($data['data']))
			return false;
		
		/*
			Revision data is required
		*/
		
		foreach(array('event','step','title','grid') as $name) {
			if(!isset($data[$name]) || !is_scalar($data[$name]) || !trim($data[$name]))
				return false;
		}
		
		/*
			Author needs to be a real account
		*/
		
		if(
			!safe_row(
				'name',
				'txp_users',
				"name='".doSlash($data['author'])."' LIMIT 0, 1"
			)
		)
			return false;
		
		foreach($data as $name => $value)
			$sql[$name] = $name."='".doSlash($value)."'";
		
		$sql['posted'] = 'posted=now()';
		
		$r = 
			safe_row(
				'id',
				$this->pfx.'_sets',
				$sql['event'].' and '.$sql['grid'].' LIMIT 0, 1'
			);
		
		/*
			If no existing records, create a new item
		*/
		
		if(!$r) {
			
			if(
				safe_insert(
					$this->pfx.'_sets',
					'modified=now(),changes=1,'.
					$sql['title'].','.
					$sql['event'].','.
					$sql['step'].','.
					$sql['grid']
				) == false
			)
				return false;
			
			$data['setid'] = mysql_insert_id();
		}
		
		else {
			
			$data['setid'] = $r['id'];
			
			/*
				If no changes were done, end here.
			*/
			
			$latest = $this->get_revision("setid='".doSlash($data['setid'])."' ORDER BY id desc");
			
			if($latest && $data['data'] == $latest['data'])
				return true;
			
			if(
				safe_update(
					$this->pfx.'_sets',
					'modified=now(), changes=changes+1,'.$sql['title'],
					"id='".doSlash($data['setid'])."'"
				) == false
			)
				return false;
		}
		
		if($this->compress) {
			$data['data'] = base64_encode(gzencode(serialize($data['data'])));
		}
		else {
			$data['data'] = base64_encode(serialize($data['data']));
		}
		
		if($this->static_dir) {
			unset($sql['data']);
		}
		
		else {
			$sql['data'] = "data='".doSlash($data)."'";
		}
		
		$sql['setid'] = "setid='".doSlash($data['setid'])."'";
		
		if(
			safe_insert(
				$this->pfx,
				implode(',', $sql)
			) == false
		)
			return false;
		
		$id = $data['id'] = mysql_insert_id();
		
		if($this->static_dir) {
			if(
				!$id || 
				file_put_contents(
					$this->static_dir . DS . $data['setid'] . '_r' . $id . '.php',
					$this->static_header . $data['data']
				) === false
			)
				return false;
		}

		callback_event('rah_post_versions_tasks', 'revision_created', 0, $data);
		return true;
	}

	/**
	 * Exports revision data to static files
	 * @return bool FALSE on error, TRUE on success. Nothing when export isn't required.
	 */

	protected function go_static() {
		
		global $prefs;
		
		if($this->static_dir !== false)
			return;
		
		$this->static_header = 
			'<'.
				'?php'.n.
					' if(!defined("rah_post_versions_static_dir"))'.n.
					'  die("rah_post_versions_static_dir undefined"); '.n.
				'?'.
			'>';
		
		if(
			defined('rah_post_versions_static_dir') &&
			file_exists(rah_post_versions_static_dir) &&
			is_dir(rah_post_versions_static_dir) &&
			is_readable(rah_post_versions_static_dir) &&
			is_writable(rah_post_versions_static_dir)
		)
			$this->static_dir = rtrim(rah_post_versions_static_dir, '/\\');

		if(!$this->static_dir && isset($prefs[$this->pfx.'_static'])) {
			$this->nowrite = true;
			return;
		}

		if(!$this->static_dir || isset($prefs[$this->pfx.'_static']))
			return;
		
		$r = getThings('describe '.safe_pfx($this->pfx));
		
		if(!$r || !is_array($r) || !in_array('data', $r))
			return;
		
		$rs =
			safe_rows(
				'data, setid, id',
				$this->pfx,
				'1=1'
			);
		
		foreach($rs as $a) {
			
			$file = $this->static_dir . DS . $a['setid'] . '_r' . $a['id'] . '.php';
			
			if(file_exists($file))
				continue;
			
			if(
				file_put_contents(
					$file,
					$this->static_header . $a['data']
				) === false
			)
				return false;
		}
		
		if(
			safe_alter(
				$this->pfx,
				'DROP data'
			) === false
		)
			return false;
		
		set_pref($this->pfx.'_static', '1', $this->prefs_group, 2, '', 0);
		$prefs[$this->pfx.'_static'] = '1';

		return true;
	}
}

/**
 * Admin-side panels.
 */

class rah_post_versions_panes extends rah_post_versions {

	/**
	 * Initialize required objects
	 */

	public function __construct() {
		$this->go_static();
		$this->compression();
		$this->sort = new rah_post_versions_sort();
		$this->ui = new rah_post_versions_widgets($this->sort);
		$this->diff = new rah_post_versions_diff();
	}

	/**
	 * Lists all items
	 */

	protected function items() {
		
		$q[] = '1=1';
		
		$filter_event = gps('filter_event');
		
		if($filter_event)
			$q[] = "event='".doSlash($filter_event)."'";
		
		$total = 
			safe_count(
				$this->pfx.'_sets',
				implode(' and ',$q)
			);
		
		$this->sort->
			add('id')->
			add('event')->
			add('title')->
			add('modified')->
			add('changes')->
			col('modified')->
			dir('desc')->
			view('items')->
			total($total)->
			limit(20)->
			page(gps('page'))->
			set();
		
		if($filter_event)
			$this->ui->label($this->pfx.'_main')->
				url('?event='.$this->event);
		
		if(has_privs('prefs') && has_privs($this->pfx.'_preferences'))
			$this->ui->label($this->pfx.'_preferences')->
				url('?event=prefs&amp;step=advanced_prefs#prefs-'.$this->pfx.'_exclude');
		
		$this->ui->nav();
		
		$this->ui->add(
			n.'	<form method="post" action="index.php">'.n.
			
			eInput($this->event).n.
			tInput().n.
			
			'		<table cellspacing="0" cellpadding="0" id="list">'.n
		);
		
		$show_selects = has_privs($this->pfx.'_delete');

		$this->ui->
			label($this->pfx.'_id')->th('id')->
			label($this->pfx.'_event')->th('event')->
			label($this->pfx.'_title')->th('title')->
			label($this->pfx.'_modified')->th('modified')->
			label($this->pfx.'_changes')->th('changes');
		
		if($show_selects)
			$this->ui->label('')->attr('class', 'rah_ui_selectall')->th('');
		
		$this->ui->thead();
		
		$rs = 
			safe_rows(
				'id,event,step,grid,title,modified,changes',
				$this->pfx.'_sets',
				implode(' and ', $q) . 
				' ORDER BY '.
					$this->sort->col().' '.
					$this->sort->dir().
				' LIMIT ' .
					$this->sort->offset().', '.
					$this->sort->limit()
			);
		
		if($rs){
			
			$events = $this->pop_events();
		
			foreach($rs as $a) {
				
				if(!$a['title'])
					$a['title'] = gTxt('untitled');
				
				if(isset($events[$a['event']]))
					$a['event'] = $events[$a['event']];
				
				$url = '?event='.$this->event.'&amp;step=changes&amp;item='.$a['id'];
				
				$this->ui->add(
					'				<tr>'.n.
					'					<td>'.$a['id'].'</td>'.n.
					'					<td>'.htmlspecialchars($a['event']).'</td>'.n.
					'					<td><a href="'.$url.'">'.htmlspecialchars($a['title']).'</a></td>'.n.
					'					<td>'.safe_strftime(gTxt($this->pfx.'_date_format'),strtotime($a['modified'])).'</td>'.n.
					'					<td><a href="'.$url.'">'.$a['changes'].'</a></td>'.n.
					($show_selects ?
						'					<td><input type="checkbox" name="selected[]" value="'.$a['id'].'" /></td>'.n : ''
					).
					'				</tr>'.n
				);
			}

		} else 
			$this->ui->add(
				'				<tr>'.n.
				'					<td colspan="'.($show_selects? 6 : 5).'">'.gTxt($this->pfx.'_no_changes').'</td>'.n.
				'				</tr>'.n
			);
		
		$this->ui->url('?event='.$this->event);
		$this->ui->add(
			'			</tbody>'.n.
			'		</table>'.n.
			'		<div id="'.$this->pfx.'_list_actions" class="rah_ui_list_actions">'.n
		)->
		view_limit(array(10,20,100))->pages();

		$this->ui->add(
			
			($show_selects ?
				'		<div id="'.$this->pfx.'_step" class="rah_ui_step">'.n.
				'			<select name="step">'.n.
				'				<option value="">'.gTxt($this->pfx.'_with_selected').'</option>'.n.
				'				<option value="delete_item">'.gTxt($this->pfx.'_delete').'</option>'.n.
				'			</select>'.n.
				'			<input type="submit" class="smallerbox" value="'.gTxt('go').'" />'.n.
				'		</div>'.n : ''
			).
			
			'		</div>'.n.
			
			'	</form>'.n
		);

		$this->ui->pane();
	}

	/**
	 * Lists changes committed to an item
	 */

	protected function changes() {
		
		$id = gps('item');
		
		if(
			!safe_row(
				'id',
				$this->pfx.'_sets',
				"id='".doSlash($id)."' LIMIT 0, 1"
			)
		) {
			$this->msg('unknown_selection')->items();
			return;
		}
		
		$total = 
			safe_count(
				$this->pfx,
				"setid='".doSlash($id)."'"
			);
		
		$this->sort->
			add('id')->
			add('title')->
			add('posted')->
			add('step')->
			add('author')->
			col('posted')->
			dir('desc')->
			limit(20)->
			total($total)->
			page(gps('page'))->
			view('changes')->
			set();
		
		$this->ui->
				label($this->pfx.'_main')->
				url('?event='.$this->event)->
				nav();
		
		$this->ui->add(
		
			'	<form method="post" action="index.php">'.n.
			
			eInput($this->event).n.
			hInput('item', $id).n.
			tInput().n.
			
			'		<table id="list" cellspacing="0" cellpadding="0">'.n
		);
		
		$this->ui->
			label($this->pfx.'_id')->th('id')->
			label($this->pfx.'_title')->th('title')->
			label($this->pfx.'_posted')->th('posted')->
			label($this->pfx.'_step')->th('step')->
			label($this->pfx.'_author')->th('author')->
			label('')->attr('class', 'rah_ui_selectall')->th('')->
			thead('&amp;step=changes&amp;item='.$id);
		
		$rs = 
			safe_rows(
				'id,title,posted,author,step',
				$this->pfx,
				"setid='".doSlash($id)."'".
				' ORDER BY ' . 
					$this->sort->col().' '.
					$this->sort->dir().' '.
				' LIMIT ' .
					$this->sort->offset().', '.
					$this->sort->limit()
			);
		
		if($rs){
		
			foreach($rs as $a) {
			
				$url = '?event='.$this->event.'&amp;step=view&amp;item='.$id.'&amp;r='.$a['id'];
			
				$this->ui->add(  
					
					'				<tr>'.n.
					'					<td>'.$a['id'].'</td>'.n.
					'					<td><a href="'.$url.'">'.htmlspecialchars($a['title']).'</a></td>'.n.
					'					<td>'.safe_strftime(gTxt($this->pfx.'_date_format'),strtotime($a['posted'])).'</td>'.n.
					'					<td>'.htmlspecialchars($a['step']).'</td>'.n.
					'					<td>'.htmlspecialchars(get_author_name($a['author'])).'</td>'.n.
					'					<td><input type="checkbox" name="selected[]" value="'.$a['id'].'" /></td>'.n.
					'				</tr>'.n
				);
			}
			
		} else 
			$this->ui->add(
				'				<tr>'.n.
				'					<td colspan="6">'.gTxt($this->pfx.'_no_changes').'</td>'.n.
				'				</tr>'.n
			);
		
		$this->ui->url('?event='.$this->event.'&amp;step='.$this->sort->view().'&amp;item='.gps('item'));
		
		$this->ui->add(
			'			</tbody>'.n.
			'		</table>'.n.
			'		<div id="'.$this->pfx.'_list_actions" class="rah_ui_list_actions">'.n
		)->
		view_limit(array(10,20,100))->pages();
		
		$this->ui->add(
			
			'			<div id="'.$this->pfx.'_step" class="rah_ui_step">'.n.
			'				<select name="step">'.n.
			'					<option value="">'.gTxt($this->pfx.'_with_selected').'</option>'.n.
			'					<option value="diff">'.gTxt($this->pfx.'_diff').'</option>'.n.
			(has_privs($this->pfx.'_delete') ?
				'					<option value="delete_revision">'.gTxt($this->pfx.'_delete').'</option>'.n : ''
			).
			'				</select>'.n.
			'				<input type="submit" class="smallerbox" value="'.gTxt('go').'" />'.n.
			'			</div>'.n.
			'		</div>'.n.
			'	</form>'.n
		);

		$this->ui->pane();
	}

	/**
	 * Removes items and their all revisions
	 */

	protected function delete_item() {
		
		if(!has_privs($this->pfx.'_delete')) {
			$this->items();
			return;
		}
		
		$selected = ps('selected');
		
		if(empty($selected) || !is_array($selected)) {
			$this->msg('select_something')->items();
			return;
		}
		
		$in = implode(',',quote_list($selected));
		
		if(
			safe_delete(
				$this->pfx,
				'setid in('.$in.')'
			) == false ||
			safe_delete(
				$this->pfx.'_sets',
				'id in('.$in.')'
			) == false
		) {
			$this->msg('error_removing')->items();
			return;
		}
		
		if($this->static_dir) {
			$dir = preg_replace('/(\*|\?|\[)/', '[$1]', $this->static_dir);

			foreach($selected as $id) {
				$id = (int) $id;

				foreach(glob($dir.DS.$id.'_r*.php') as $file) {
					unlink($file);
				}
			}
		}
		
		callback_event('rah_post_versions_tasks', 'item_deleted');
		$this->msg('removed')->items();
	}
	
	/**
	 * Deletes individual revisions
	 */

	protected function delete_revision() {
		
		if(!has_privs($this->pfx.'_delete')) {
			$this->changes();
			return;
		}
		
		$selected = ps('selected');
		
		if(!is_array($selected) || empty($selected)) {
			$this->msg('select_something')->changes();
			return;
		}

		$in = implode(',',quote_list($selected));
		$setid = (int) ps('item');
		
		if(
			safe_delete(
				$this->pfx,
				"id in(".$in.") and setid='".doSlash($setid)."'"
			) == false
		) {
			$this->msg('error_removing')->changes();
			return;
		}
		
		if($this->static_dir) {
			foreach($selected as $id) {
				$id = (int) $id;
				@unlink($this->static_dir.DS.$setid.'_r'.$id.'.php');
			}
		}
		
		callback_event('rah_post_versions_tasks', 'revision_deleted');
		$this->msg('removed')->changes();
	}
	
	/**
	 * Shows differences between two revisions
	 */

	protected function diff() {
		
		extract(
			gpsa(
				array(
					'r',
					'selected'
				)
			)
		);
		
		$selection = $r && is_string($r) ?
			explode('-', $r) : $selected;
		
		if(!is_array($selection) || count($selection) < 2) {
			$this->msg('select_two_items')->changes();
			return;
		}
		
		sort($selection);
		
		$rs = $this->get_revision("id='".doSlash($selection[0])."' and setid='".doSlash(gps('item'))."'");
		
		if(!$rs) {
			$this->msg('unknown_selection')->changes();
			return;
		}
		
		$old = $rs['data'];
		
		$r = $this->get_revision("id='".doSlash(end($selection))."' and setid='".doSlash($rs['setid'])."'");
		
		if(!$r || !$old) {
			$this->msg('unknown_selection')->changes();
			return;
		}
		
		$new = $r['data'];
		
		if($new == $old) {
			$this->msg('revisions_match')->changes();
			return;
		}
		
		$this->ui->add(
		
			'	<p id="'.$this->pfx.'_actions">'.
			
			gTxt(
				$this->pfx.'_view_other_revisions',
				array(
					'{previous}' => 
						'<a href="?event='.$this->event.'&amp;step=view&amp;item='.$rs['setid'].'&amp;r='.$rs['id'].'">r'.
							$rs['id'].
						'</a>',
					'{current}' => 
						'<a href="?event='.$this->event.'&amp;step=view&amp;item='.$rs['setid'].'&amp;r='.$r['id'].'">r'.
							$r['id'].
						'</a>'
				),
				false
			). ' ' .
			
			gTxt(
				$this->pfx.'_revision_item_name',
				array(
					'{name}' => '<a href="?event='.$this->event.'&amp;step=changes&amp;item='.$rs['setid'].'">'.htmlspecialchars($r['title']).'</a>'
				),
				false
			) . ' ' .

			gTxt(
				$this->pfx.'_revision_committed_by',
				array(
					'{author}' => htmlspecialchars(get_author_name($r['author'])),
					'{posted}' => safe_strftime(gTxt($this->pfx.'_date_format'),strtotime($r['posted'])),
				),
				false
			). ' ' .
			
			gTxt(
				$this->pfx.'_revision_from_panel',
				array(
					'{event}' => '<a href="?event='.$this->event.'&amp;filter_event='.htmlspecialchars($r['event']).'">'.htmlspecialchars($r['event']).'</a>',
					'{step}' => htmlspecialchars($r['step'])
				),
				false
			). ' ' .
			
			'</p>'
		);
		
		foreach($new as $key => $val) {
			
			if(!isset($old[$key]))
				$old[$key] = '';
			
			/*
				If the field is exact same do not show it at all
			*/
			
			if($old[$key] == $val) {
				unset($old[$key]);
				continue;
			}

			$this->diff->old = $old[$key];
			$this->diff->new = $val;
			
			$this->ui->add( 
				'<div class="'.$this->pfx.'_label">'.htmlspecialchars($key).'</div>'.n.
				'<div class="'.$this->pfx.'_diff">'.
					$this->diff->html().
				'</div>'
			);
			
			unset($old[$key]);
		}
		
		/*
			List removed/emptied fields
		*/
		
		if($old)
			foreach($old as $key => $val) 
				$this->ui->add(
					'<div class="'.$this->pfx.'_label"><del>'.htmlspecialchars($key).'</del></div>'.n.
					'<div class="'.$this->pfx.'_diff">'.
						'<span class="'.$this->pfx.'_del">'.
							htmlspecialchars($val).
						'</span>'.
					'</div>'.n
				);
	
		$this->ui->pane();

	}

	/**
	 * Displays individual revision
	 */

	protected function view() {
		
		global $prefs;
		
		$id = gps('r');
		$hidden = do_list($prefs[$this->pfx.'_hidden']);
		
		$rs = $this->get_revision("id='".doSlash($id)."' and setid='".doSlash(gps('item'))."'");
			
		if(!$rs) {
			$this->msg('unknown_selection')->changes();
			return;
		}
		
		if(!$rs['data']) {
			$this->msg('missing_revision_data')->changes();
			return;
		}
		
		$prev_rev = 
			safe_field(
				'id',
				$this->pfx,
				'setid='.$rs['setid'].' and id <= '.$rs['id'].' ORDER BY id desc LIMIT 1, 1'
			);
		
		$data = $rs['data'];
			
		$out[] =
			
			'		<form method="post" class="'.$this->pfx.'_verify" action="index.php">'.n.
			'			<p id="'.$this->pfx.'_actions">'.
			
			gTxt(
				$this->pfx.'_revision_item_name',
				array(
					'{name}' => '<a href="?event='.$this->event.'&amp;step=changes&amp;item='.$rs['setid'].'">'.htmlspecialchars($rs['title']).'</a>'
				),
				false
			) . ' ' .

			gTxt(
				$this->pfx.'_revision_committed_by',
				array(
					'{author}' => htmlspecialchars(get_author_name($rs['author'])),
					'{posted}' => safe_strftime(gTxt($this->pfx.'_date_format'),strtotime($rs['posted'])),
				),
				false
			). ' ' .
			
			gTxt(
				$this->pfx.'_revision_from_panel',
				array(
					'{event}' => '<a href="?event='.$this->event.'&amp;filter_event='.htmlspecialchars($rs['event']).'">'.htmlspecialchars($rs['event']).'</a>',
					'{step}' => htmlspecialchars($rs['step'])
				),
				false
			). ' ' .
			
			($prev_rev ?
				'<a href="?event='.$this->event.'&amp;step=diff&amp;item='.htmlspecialchars(gps('item')).'&amp;r='.$prev_rev.'-'.$rs['id'].'">'.
					gTxt(
						$this->pfx.'_compare_to_previous',
						array(
							'{prev_id}' => 'r'.$prev_rev,
						)
					).
				'</a>' : ''
			).
			
			'</p>'.n;
		
		$post = array();
		
		foreach($data as $key => $value) {
			if(is_array($value)) {
				foreach($value as $needle => $selection)
					$post[$key.'['.$needle.']'] = $selection;
			}
			else $post[$key] = $value;
		}
		
		foreach($post as $key => $val) {

			$name = htmlspecialchars($key);
		
			if(in_array($key, $hidden)) {
				$out[] = hInput($name, $val);
				continue;
			}
			
			$value = htmlspecialchars($val);
			
			$input = 
				strpos($value,n) === false ?
					'<input type="text" '.
						'name="'.$name.'" '.
						'value="'.$value.'" '.
						'class="edit" '.
					'/>' 
				:
					'<textarea '.
						'name="'.$name.'" '.
						'cols="100" '.
						'rows="6" '.
						'class="code"'.
					'>'.$value.'</textarea>'
				;
			
			$out[] = '<p><label>'.$name.'<br />'.$input.'</label></p>';
		}
		
		$out[] =
			hInput($this->pfx.'_repost_item', $rs['setid']).
			hInput($this->pfx.'_repost_id', $id).
			tInput().
			
			(isset($post['event']) && has_privs($post['event']) ?
				'			<p id="'.$this->pfx.'_warning">'.gTxt($this->pfx.'_repost_notice').'</p>'.n.
				'			<p><input type="submit" class="publish" value="'.gTxt($this->pfx.'_repost_this').'" /></p>'.n
				: ''
			).
				
			'		</form>';

		$this->ui->add($out)->pane();
	}
}

/**
 * Tools for handling sorting of lists, pagination and so on
 */

class rah_post_versions_sort extends rah_post_versions {
	
	public $offset;
	public $pages;
	protected $view;
	protected $column;
	protected $direction;
	protected $columns;
	protected $limit;
	protected $total;
	protected $page;
	protected $filter = array();

	public function __construct() {
	}

	/**
	 * Add column to the list of sortables
	 * @param string $column
	 */

	public function add($column) {
		$this->columns[$column] = $column;
		return $this;
	}
	
	/**
	 * Set or get viewed list's name
	 * @param string $view
	 * @return obj
	 */
	
	public function view($view=NULL) {
		
		if($view === NULL)
			return $this->view;
		
		$this->view = $view;
		return $this;
	}
	
	/**
	 * Sets or gets the sorting column
	 * @param string $col DB table column name.
	 * @return obj
	 */
	
	public function col($col=NULL) {
		
		if($col === NULL)
			return $this->column;
		
		$this->column = $col;
		return $this;
	}
	
	/**
	 * Sets or gets the direction
	 * @param string $dir asc or desc.
	 * @return obj
	 */
	
	public function dir($dir=NULL) {
		
		if($dir === NULL)
			return $this->direction;
		
		$this->direction = $dir;
		return $this;
	}
	
	/**
	 * Sets or gets the list item limit
	 * @param int $limit
	 * @return obj
	 */
	
	public function limit($limit=NULL) {
		
		if($limit === NULL)
			return $this->limit;
		
		$this->limit = (int) $limit;
		return $this;
	}
	
	/**
	 * Sets or gets the list item offset
	 * @param int $offset
	 * @return obj
	 */
	
	public function offset($offset=NULL) {
		
		if($offset === NULL)
			return $this->offset;
		
		$this->offset = (int) $offset;
		return $this;
	}
	
	/**
	 * Sets or gets the total list item count
	 * @param int $total
	 * @return obj
	 */
	
	public function total($total=NULL) {
		
		if($total === NULL)
			return $this->total;
		
		$this->total = $total;
		return $this;
	}
	
	/**
	 * Sets or gets current page
	 * @param int $page
	 * @return obj
	 */
	
	public function page($page=NULL) {
		
		if($page === NULL)
			return $this->page;
		
		$this->page = $page;
		return $this;
	}

	/**
	 * Filter list and set the sorting values
	 * @return obj
	 */

	public function set() {
		
		global $prefs;
		
		extract(
			gpsa(
				array(
					'sort',
					'dir',
					'limit'
				)
			)
		);
		
		$group = $this->prefs_group;
		
		if(!$sort && isset($prefs[$this->pfx.'_sort_'.$this->view]))
			$sort = $prefs[$this->pfx.'_sort_'.$this->view];
		
		if(!$dir && isset($prefs[$this->pfx.'_dir_'.$this->view]))
			$dir = $prefs[$this->pfx.'_dir_'.$this->view];
		
		if(!$limit && isset($prefs[$this->pfx.'_limit_'.$this->view]))
			$limit = $prefs[$this->pfx.'_limit_'.$this->view];
		
		if(
			$sort && isset($this->columns[$sort]) && 
			($dir == 'asc' || $dir == 'desc')
		) {
			set_pref($this->pfx.'_sort_'.$this->view, $sort, $group, 2, '', 0, PREF_PRIVATE);
			set_pref($this->pfx.'_dir_'.$this->view, $dir, $group, 2, '', 0, PREF_PRIVATE);
			$this->col($sort)->dir($dir);
		}
		
		if($limit && in_array($limit, range(0, 200, 5))) {
			set_pref($this->pfx.'_limit_'.$this->view, $limit, $group, 2, '', 0, PREF_PRIVATE);
			$this->limit($limit);
		}

		foreach($this->list_filters as $filter) {
				
			$name = $this->pfx.'_filter_by_'.$filter;
			
			if(!has_privs($name)) {
				$prefs[$name] = '';
				continue;
			}
			
			$current = isset($prefs[$name]) ? $prefs[$name] : '';
			$value = isset($_GET[$filter]) ? gps($filter) : $current;
			
			if($value !== $current)
				set_pref($name, $value, $group, 2, '', 0, PREF_PRIVATE);
			
			$this->filter[$name] = $prefs[$name] = $value;
		}
		
		list($this->page, $this->offset, $this->pages) = 
			pager($this->total(), $this->limit(), $this->page());
		
		return $this;
	}
}

/**
 * Contains UI widgets
 */

class rah_post_versions_widgets extends rah_post_versions {

	private $label;
	private $link = array();
	private $attr = array();
	private $out;
	private $wrap;
	private $title;
	private $message = '';
	private $th;
	protected $sort;

	/**
	 * Pass sort to here
	 */

	public function __construct(&$sort) {
		$this->sort = $sort;
	}

	/**
	 * Sets element's label text.
	 * @param string $label
	 * @return obj
	 */
	
	public function label($label) {
		$this->label = $label ? gTxt($label) : $label;
		return $this;
	}

	/**
	 * Sets, unsets or gets HTML tag attribute.
	 * @param string $name
	 * @param string $value
	 * @return obj
	 */

	public function attr($name=NULL, $value=NULL) {
		
		if($name === NULL) {
			
			if(empty($this->attr))
				return;
			
			foreach($this->attr as $name => $value)
				$out[] = ' '.$name.'="'.$value.'"';
			
			$this->attr = array();

			return implode('', $out);
		}
		
		if($value === NULL)
			return isset($this->attr[$name]) ? $this->attr[$name] : '';
		
		if($value === FALSE) {
			unset($this->attr[$name]);
			return $this;
		}
		
		$this->attr[$name] = trim($value);
		return $this;
	}
	
	/**
	 * Sets link URL.
	 * @param string $uri
	 * @return obj
	 */
	
	public function url($uri=NULL) {
		
		if($uri === NULL)
			return $this->link;
		
		$this->link[$this->label] = $uri;
		return $this;
	}

	/**
	 * Build navigation bar
	 * @return obj
	 */
	
	public function nav() {
		
		foreach($this->link as $label => $uri)
			$nav[] = '<span class="rah_ui_sep">&#187;</span> <a href="'.$uri.'">'.$label.'</a>';
		
		$this->out[] = 
			'	<p class="rah_ui_nav">' . implode(' ', $nav) . '</p>'.n;
			
		$this->link = array();
		
		return $this;
	}
	
	/**
	 * Set page title.
	 * @param string $title
	 * @return obj
	 */
	
	public function title($title) {
		
		if($title)
			$this->title = gTxt($title);
		
		return $this;
	}

	/**
	 * Sets activity message.
	 * @param string $msg
	 * @param bool $pfx Whether to prefix.
	 * @return obj
	 */
	
	public function msg($msg, $pfx=true) {
		
		if($msg) {
			$msg = $pfx ? $this->pfx . '_' . $msg : $msg;
			$this->message = gTxt($msg);
		}
		
		return $this;
	}
	
	/**
	 * Adds HTML markup to the pane
	 * @param string $out
	 * @return obj
	 */
	
	public function add($out) {
		$this->out[] = is_array($out) ? implode('', $out) : $out;
		return $this;
	}
	
	/**
	 * Returns the pane's markup
	 * @return string HTML markup.
	 */
	
	public function pane() {
		
		pagetop($this->title, $this->message);
		
		if(is_array($this->out))
			$this->out = implode('', $this->out);
		
		echo 
			n.'<div id="'.$this->event.'_container" class="rah_ui_container">'.n.
				$this->out.n.
			'</div>'.n;
	}

	/**
	 * Adds a heading to a table column
	 * @param string $column
	 * @return obj
	 */

	public function th($column) {
		$this->th[$column] = array('label' => $this->label, 'attr' => $this->attr);
		return $this;
	}
	
	/**
	 * Adds table heading
	 * @param string $additional Additional URL parameters.
	 * @return obj
	 */

	public function thead($additional = '') {
		
		$this->out[] = 
			'			<thead>'.n.
			'				<tr>'.n;
		
		foreach($this->th as $column => $c) {
			
			extract($c);
			
			$this->attr = $attr; 
			
			if(!$label) {
				$this->out[] = '					<th'.$this->attr().'>&#160;</th>'.n;
				continue;
			}
			
			$order = 'desc';
			
			if($this->sort->col() == $column) {
				$this->attr('class', $this->attr('class') . ' ' . $this->sort->dir());
				$order = $this->sort->dir() == 'desc' ? 'asc' : 'desc';
			}
			
			$this->out[] = 
				'					<th'.$this->attr().'>'.
				'<a href="?event='.$this->event.$additional.
				'&amp;sort='.$column.'&amp;dir='.$order.'">'.
					$label.
				'</a></th>'.n
			;
		}
		
		$this->out[] = 
			'				</tr>'.n.
			'			</thead>'.n.
			'			<tbody>'.n;
		
		return $this;
	}

	/**
	 * Builds pagination.
	 * @param string $page_att GET parameter setting the page number
	 * @return obj
	 */

	public function pages() {
		
		if($this->sort->pages <= 1)
			return $this;
		
		$end = ($this->sort->page() + 3) > $this->sort->pages ? $this->sort->pages : $this->sort->page() + 3;
		$start = ($end - 3) < 1 ? 1 : ($end - 3);
		
		$url = implode('', $this->url()).'&amp;page=';
		
		if($start > 1) 
			$out[] = 
				'<a title="'.gTxt($this->pfx.'_go_to_first_page').'" href="'.
					$url.'1">&#171;</a>';
		
		for($i=$start;$i<=$end;$i++)
			
			$out[] = 
				'<a'.($i == $this->sort->page() ? 
					' class="'.$this->pfx.'_active rah_ui_active"' : '').' href="'.
					$url.$i.'">'.$i.'</a>';
		
		if($end < $this->sort->pages)
			$out[] = 
				'<a title="'.gTxt($this->pfx.'_go_to_last_page').'" href="'.
					$url.$this->sort->pages.'">&#187;</a>';
		
		$this->out[] = 
			'			<div id="'.$this->pfx.'_pages" class="rah_ui_pages">'.
			implode(' ',$out).
			'</div>'.n;
		
		return $this;
	}

	/**
	 * Build page by links.
	 * @param array $values List of limit values.
	 * @return obj
	 */

	public function view_limit($values) {
		
		$url = implode('', $this->url()).'&amp;limit=';
		
		foreach($values as $val)
			$out[] = 
				'<a'.
					($val == $this->sort->limit() ? 
						' class="'.$this->pfx.'_active rah_ui_active"' : ''
					).
					' title="'.
						gTxt(
							$this->pfx.'_view_n_per_page',
							array(
								'{items}' => $val
							)
						).'"'.
					' href="'.
					$url.$val.'">'.$val.'</a>';
		
		$this->out[] = 
			'			<div id="'.$this->pfx.'_view_limit" class="rah_ui_view_limit">'.
			'<strong>'.gTxt($this->pfx.'_set_view_limit').'</strong> '.
			implode(' <span class="rah_ui_sep">|</span> ',$out).
			'</div>'.n;
		
		return $this;
	}
}

/**
 * Produces inline diffs, comparison tool
 */

class rah_post_versions_diff extends rah_post_versions {

	public $old;
	public $new;
	private $delimiter = n;
	
	public function __construct() {
	}

	/**
	 * Clean line breaks.
	 * @param string|array $string
	 */

	private function lines($string) {
		
		if(is_array($string))
			$string = implode(n, $string);
		
		return 	
			explode($this->delimiter,
				str_replace(array("\r\n","\r"), n, htmlspecialchars($string))
			);
	}

	/**
	 * Returns HTML presentation of the diff.
	 * @return string HTML markup.
	 */

	public function html(){
		
		$this->old = $this->lines($this->old);
		$this->new = $this->lines($this->new);
		
		foreach($this->diff($this->old, $this->new) as $key => $line){
			if(is_array($line)) {
				
				if(
					!empty($line['d']) &&
					($d = implode($this->delimiter,$line['d'])) !== ''
				)
					$out[] = '<span class="'.$this->pfx.'_del">'.$d.'</span>';
				
				if(!empty($line['i']))
					$out[] = 
						'<span class="'.$this->pfx.'_add">'.
							implode($this->delimiter,$line['i']).
						'</span>';
			} else
				$out[] = $line;
		}
		
		return implode($this->delimiter,$out);
	}

	/**
	 * Compares lines/words and retuns differences marked
	 * @param array $old Contents of old revision.
	 * @param array $new Contents of new revision.
	 * @return array
	 */

	public function diff($old, $new){

		/*
			This (rah_post_versions_diff::diff()) methods's contents are based on:
			
			Paul's Simple Diff Algorithm v 0.1
			(C) Paul Butler 2007 <http://www.paulbutler.org/>
			Licensed under GNU GPL compatible zlib/libpng license.

			This software is provided 'as-is', without any express or implied
			warranty. In no event will the authors be held liable for any damages
			arising from the use of this software.

			Permission is granted to anyone to use this software for any purpose,
			including commercial applications, and to alter it and redistribute it
			freely, subject to the following restrictions:

			1. The origin of this software must not be misrepresented; you must not
			claim that you wrote the original software. If you use this software
			in a product, an acknowledgment in the product documentation would be
			appreciated but is not required.

			2. Altered source versions must be plainly marked as such, and must not be
			misrepresented as being the original software.

			3. This notice may not be removed or altered from any source
			distribution.
		*/

		$maxlen = 0;

		foreach($old as $oindex => $ovalue){
			$nkeys = array_keys($new, $ovalue);
			foreach($nkeys as $nindex){
				$matrix[$oindex][$nindex] = 
					isset($matrix[$oindex - 1][$nindex - 1]) ? 
						$matrix[$oindex - 1][$nindex - 1] + 1 : 1;
				if($matrix[$oindex][$nindex] > $maxlen){
					$maxlen = $matrix[$oindex][$nindex];
					$omax = $oindex + 1 - $maxlen;
					$nmax = $nindex + 1 - $maxlen;
				}
			}
		}

		if($maxlen == 0)
			return array(array('d' => $old, 'i' => $new));
		
		return 
			array_merge(
				$this->diff(
					array_slice($old, 0, $omax),
					array_slice($new, 0, $nmax)
				),
				array_slice($new, $nmax, $maxlen),
				$this->diff(
					array_slice($old, $omax + $maxlen),
					array_slice($new, $nmax + $maxlen)
				)
			)
		;
	}
}

/**
 * Returns the item's ID (if any).
 * @param string $key Name of the POST field containing the ID.
 * @return int
 */

	function rah_post_versions_id($key='ID') {
		$id = gps($key);
		if(!$id && isset($GLOBALS['ID']))
			$id = $GLOBALS['ID'];
		return $id;
	}

/**
 * Textarea for preferences panel
 * @param string $name
 * @param int $val
 * @return string HTML markup
 */

	function rah_post_versions_textarea($name,$val) {
		return text_area($name, 200, 300, $val, $name);
	}
?>
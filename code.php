<?php	##################
	#
	#	rah_post_versions-plugin for Textpattern
	#	version 0.7
	#	by Jukka Svahn
	#	http://rahforum.biz
	#
	#	Copyright (C) 2010 Jukka Svahn
	#	Licensed under GNU Genral Public License version 2
	#	http://www.gnu.org/licenses/gpl-2.0.html
	#
	###################

	if (@txpinterface == 'admin') {
		rah_post_versions_install();
		add_privs('rah_post_versions','1,2');
		register_tab('extensions','rah_post_versions','Post versions');
		register_callback('rah_post_versions_page','rah_post_versions');
		register_callback('rah_post_versions_css','admin_side','head_end');
		register_callback('rah_post_versions_messager','admin_side','pagetop_end');
		rah_post_versions_register();
	}

/**
	Registers backend events
*/

	function rah_post_versions_register() {
		
		global $txp_user;
		
		extract(
			rah_post_versions_do_prefs()
		);
		
		$authors = explode(',',$authors);
		
		if(in_array(
			$txp_user,
			$authors
		))
			return;

		$events = explode(',',$events);
		foreach($events as $event) {
			$item = explode(':',$event);
			if(isset($item[1]))
				register_callback('rah_post_versions',trim($item[0]),trim($item[1]),0);
		}
		
		return;
	}

/**
	Creates the tables required for the plugin to operate.
*/

	function rah_post_versions_install() {
		
		/*
			Stores the changesets
			
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
				PRIMARY KEY(`id`)
			) PACK_KEYS=1 AUTO_INCREMENT=1"
		);
		
		/*
			Stores the changes (versions) in changesets
			
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
				`author` VARCHAR(255) NOT NULL,
				`posted` DATETIME NOT NULL default '0000-00-00 00:00:00',
				`data` LONGTEXT NOT NULL,
				PRIMARY KEY(`id`)
			) PACK_KEYS=1 AUTO_INCREMENT=1"
		);
		
		/*
			Creates preferences table
		*/
		
		safe_query(
			'CREATE TABLE IF NOT EXISTS '.safe_pfx('rah_post_versions_prefs')." (
				`name` VARCHAR(255) NOT NULL,
				`value` LONGTEXT NOT NULL,
				PRIMARY KEY(`name`)
			)"
		);
		
		/*
			Add default settings
		*/
		
		rah_post_versions_add_pref(
			array(
				'exclude' => '',
				'statuses' => '',
				'authors' => '',
				'email' => 'No',
				'email_additional' => '',
				'posting_now' => 'No',
				'events' => 'article:edit, article:create, article:publish, article:save, page:page_create, page:page_edit, page:page_save, form:form_create, form:form_edit, form:form_save, prefs:prefs_save, prefs:advanced_prefs_save, category:cat_article_create, category:cat_image_create, category:cat_file_create, category:cat_link_create, category:cat_article_save, category:cat_image_save, category:cat_file_save, category:cat_link_save, section:section_create, section:section_save, link:link_post, link:link_save, discuss:discuss_save, image:image_save, file:file_save, css:css_save',
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
				'version' => '0.1'
			)
		);
	}

/**
	Deliver panel
*/

	function rah_post_versions_page() {
		require_privs('rah_post_versions');
		global $step;
		if(in_array($step,array(
			'rah_post_versions_group',
			'rah_post_versions_group_delete',
			'rah_post_versions_diff',
			'rah_post_versions_view',
			'rah_post_versions_delete',
			'rah_post_versions_prefs',
			'rah_post_versions_prefs_save',
		))) $step();
		else rah_post_versions_list();
	}

/**
	Adds the panel's CSS to the head segment.
*/

	function rah_post_versions_css() {
		global $event,$step;
		
		if($event != 'rah_post_versions')
			return;
		
		if(empty($step) || $step == 'rah_post_versions_group')
			echo <<<EOF
			<script type="text/javascript">
				$(document).ready(function(){
					$('#rah_post_versions_nav').append(' # <span id="rah_post_versions_filter_link">Filter results</span>');
					$('#rah_post_versions_filter,.rah_post_versions_step').hide();
					$('#rah_post_versions_filter_link').click(function(){
						$('#rah_post_versions_filter').slideToggle();
					});
					$('#rah_post_versions_container input[type=checkbox]').click(function(){
						if($('#rah_post_versions_container input[type=checkbox]:checked').val() != null) {
							$('.rah_post_versions_step').slideDown();
						} else {
							$('.rah_post_versions_step').slideUp();
						}
					});
				});
			</script>
EOF;

		echo <<<EOF
			<style type="text/css">
				#rah_post_versions_container {
					width: 950px;
					margin: 0 auto;
				}
				#rah_post_versions_container table input {
					padding: 0;
					margin: 0;
				}
				#rah_post_versions_container table td {
					padding: 4px 3px;
				}
				#rah_post_versions_container .rah_post_versions_step {
					text-align: right;
					padding: 10px 0 0 0;
				}
				#rah_post_versions_actions {
					border-top: 1px solid #ccc;
					border-bottom: 1px solid #ccc;
					padding: 5px 0;
				}
				#rah_post_versions_container table {
					width:100%;
				}
				#rah_post_versions_container input.edit {
					width: 940px;
					padding: 1px;
				}
				#rah_post_versions_container textarea {
					width: 940px;
					padding: 1px;
				}
				#rah_post_versions_container select {
					width: 640px;
				}
				#rah_post_versions_container .rah_post_versions_step select {
					width: 120px;
				}
				#rah_post_versions_filter {
					text-align: center;
					border-bottom: 1px solid #ccc;
					border-top: 1px solid #ccc;
					padding: 5px 20px;
					background: #f5f5f5;
				}
				#rah_post_versions_filter select {
					width: 200px;
					margin: 0 2px;
					padding: 0;
				}
				#rah_post_versions_filter_link {
					cursor: pointer;
					text-decoration: underline;
				}
				#rah_post_versions_pages {
					text-align: center;
					padding: 10px 10px 0 10px;
				}
				#rah_post_versions_pages .rah_post_version_active {
					color: #000;
					text-decoration: underline;
				}
				#rah_post_versions_container .rah_post_versions_diff {
					overflow: auto;
					white-space: pre;
					font: 1.1em monospace;
					line-height: 1.2em;
					background: #f5f5f5;
					border: 1px solid #ccc;
					padding: 10px;
					margin: 0 0 10px 0;
				}
				#rah_post_versions_container .rah_post_versions_diff .rah_post_versions_add {
					background: #a7d726;
					border: 1px solid #829b3e;
					display: block;
					margin-bottom: -1.1em;
				}
				#rah_post_versions_container .rah_post_versions_diff .rah_post_versions_del {
					background: #c54e4e;
					border: 1px solid #8b3e3e;
					display: block;
					margin-bottom: -1.1em;
				}
			</style>

EOF;
	}

/**
	Builds URI
*/

	function rah_post_versions_uri($defaults=array(),$segments=array('filter_event','filter_limit','filter_order','filter_page','group_step','group_limit','group_order')) {
		foreach($segments as $segment)
			if(gps($segment))
				$get[$segment] = $segment.'='.htmlspecialchars(gps($segment));
		
		foreach($defaults as $key => $val)
			if(!empty($val)) $get[$key] = $key.'='.htmlspecialchars($val);
		
		return 
			'?'.implode('&amp;',$get);
	}

/**
	Builds inputs
*/

	function rah_post_versions_input($defaults=array(),$segments=array('filter_event','filter_limit','filter_order','filter_page','group_step','group_limit','group_order')) {
		foreach($segments as $segment)
			if(gps($segment)) $get[$segment] = '			<input type="hidden" name="'.$segment.'" value="'.htmlspecialchars(gps($segment)).'" />';
		
		foreach($defaults as $key => $val)
			if(!empty($val)) $get[$key] = '			<input type="hidden" name="'.$key.'" value="'.htmlspecialchars($val).'" />';
		
		return 
			implode(n,$get);
	}

/**
	Builds pagination
*/

	function rah_post_versions_pagination($total=0,$limit='filter_limit',$page_att='filter_page') {
		
		if($total == 0)
			return;
		
		global $event, $step;
		
		$limit = gps($limit);
		$page = gps($page_att);
		$group_id = gps('group_id');
		
		if(trim($limit,'0123456789') || empty($limit) || $limit <= 0)
			$limit = 20;
		
		$pages = ceil(($total/$limit));
		
		if(trim($page,'0123456789') || empty($page) || !is_numeric($page) || $page <= 0 || $page > $pages)
			$page = 1;
		
		$end = (($page+3) > $pages) ? $pages : ($page+3);
		$start = (($end-3) < 1) ? 1 : ($end-3);
		
		if($start > 1) 
			$out[] = 
				'<a href="'.
				rah_post_versions_uri(
					array(
						'event' => $event,
						'step' => $step,
						$page_att => 1,
						'group_id' => $group_id
					)
				).
				'">&#171;</a>';

		
		for($i=$start;$i<=$end;$i++)
			$out[] = 
				'<a'.(($i == $page) ? ' class="rah_post_version_active"' : '').' href="'.
					rah_post_versions_uri(
						array(
							'event' => $event,
							'step' => $step,
							$page_att => $i,
							'group_id' => $group_id
						)
					).
				'">'.$i.'</a>';
		
		if($end < $pages)
			$out[] = 
				'<a href="'.
					rah_post_versions_uri(
						array(
							'event' => $event,
							'step' => $step,
							$page_att => $pages,
							'group_id' => $group_id
						)
					).
				'">&#187;</a>';
		
		return 
			'			<div id="rah_post_versions_pages">'.n.
			implode(' ',$out).n.
			'			</div>';
	}

/**
	The main listing
*/

	function rah_post_versions_list($message='') {
	
		global $event;
		
		$events = rah_post_versions_eventer();
		
		extract(doSlash(gpsa(array(
			'filter_event',
			'filter_title',
			'filter_page',
			'filter_limit',
			'filter_order'
		))));
		
		$q[] = '1=1';
		
		if($filter_event)
			$q[] = "event='".doSlash($filter_event)."'";
		
		if(!$filter_limit || !is_numeric($filter_limit) || trim($filter_limit,'0123456789') != '')
			$filter_limit = 20;
		
		if(is_numeric($filter_page) && $filter_page > 0 && is_numeric($filter_limit) && $filter_limit > 0)
			$offset = ($filter_page*$filter_limit)-$filter_limit;
		else 
			$offset = 0;
			
		if(!in_array($filter_order,array(
			'id',
			'event',
			'title',
			'changes'
		)))
			$filter_order = 'modified';
		
		$rs = 
			safe_rows(
				'id,event,step,grid,title,modified,changes',
				'rah_post_versions_sets',
				implode(' and ',$q)." order by $filter_order desc LIMIT $offset, $filter_limit"
			);
		
		$total = 
			safe_count(
				'rah_post_versions_sets',
				implode(' and ',$q)
			);
		
		$out[] = 
			
			'		<form id="rah_post_versions_filter" method="get" action="index.php">'.n.
			
			rah_post_versions_input(
				array(
					'event' => $event
				),
				array(
					'group_step',
					'group_limit',
					'group_order',
					'group_page'
				)
			).n.
			
			'			<select name="filter_event">'.n.
			'				<option value="">Filter by the event...</option>'.n;
		
		foreach($events as $key => $value) 
			$out[] = '			<option value="'.htmlspecialchars($key).'">'.htmlspecialchars($value).'</option>'.n;
		
		
		$out[] = 
			'			</select>'.n.
			'			<select name="filter_limit">'.n.
			'				<option value="">Items per page...</option>'.n.
			'				<option value="10">10</option>'.n.
			'				<option value="25">25</option>'.n.
			'				<option value="50">50</option>'.n.
			'				<option value="100">100</option>'.n.
			'			</select>'.n.
			'			<select name="filter_order">'.n.
			'				<option value="">Sort by...</option>'.n.
			'				<option value="id">ID</option>'.n.
			'				<option value="event">Event</option>'.n.
			'				<option value="title">Title</option>'.n.
			'				<option value="modified">Modified</option>'.n.
			'				<option value="changes">Changes</option>'.n.
			'			</select>'.n.
			'			<input type="submit" value="Filter" class="smallerbox" />'.n.
			'		</form>'.n.
			'		<form method="post" action="index.php" onsubmit="return verify(\'Are you sure?\')">'.n.
			
			rah_post_versions_input(
				array(
					'event' => $event
				)
			).n.
			
			'			<table id="list" class="list" cellspacing="0" cellpadding="0">'.n.
			'				<tr>'.n.
			'					<th>#ID</th>'.n.
			'					<th>Event</th>'.n.
			'					<th>Title</th>'.n.
			'					<th>Modified</th>'.n.
			'					<th>Changes</th>'.n.
			'					<th>&#160;</th>'.n.
			'				</tr>'.n;
		
		if($rs){
		
			foreach($rs as $a) {
				
				if(!$a['title'])
					$a['title'] = gTxt('untitled');
				
				if(isset($events[$a['event']]))
					$a['event'] = $events[$a['event']];
				
				$url = 
					rah_post_versions_uri(
						array(
							'event' => $event,
							'step' => 'rah_post_versions_group',
							'group_id' => $a['id']
						)
					);
				
				$out[] =  
					
					'				<tr>'.n.
					'					<td>'.$a['id'].'</td>'.n.
					'					<td>'.htmlspecialchars($a['event']).'</td>'.n.
					'					<td><a href="'.$url.'">'.htmlspecialchars($a['title']).'</a></td>'.n.
					'					<td>'.safe_strftime('%b %d %Y %H:%M:%S',strtotime($a['modified'])).'</td>'.n.
					'					<td><a href="'.$url.'">'.$a['changes'].'</a></td>'.n.
					'					<td><input type="checkbox" name="selected[]" value="'.$a['id'].'" /></td>'.n.
					'				</tr>'.n;
			}
			
		} else 
			$out[] = 
				'				<tr>'.n.
				'					<td colspan="6">No article version data found.</td>'.n.
				'				</tr>'.n;
		
		$out[] = 
			'			</table>'.n.
			rah_post_versions_pagination($total).n.
			
			'			<p class="rah_post_versions_step">'.n.
			'				<select name="step">'.n.
			'					<option value="">With selected...</option>'.n.
			'					<option value="rah_post_versions_group_delete">Delete changeset</option>'.n.
			'				</select>'.n.
			'				<input type="submit" class="smallerbox" value="Go" />'.n.
			'			</p>'.n.
			'		</form>'.n.
			'	</div>'.n;
		
		rah_post_versions_header(
			$out,
			$message,
			'Revision control'
		);
		
	}

/**
	Remove whole groups
*/

	function rah_post_versions_group_delete() {
		
		$selected = ps('selected');
		
		if(!is_array($selected)) {
			rah_post_versions_list('Nothing selected.');
			return;
		}
		
		foreach($selected as $id)
			$in[] = "'".doSlash($id)."'";
		
		if(!isset($in)) {
			rah_post_versions_list('Something gone wrong.');
			return;
		}
		
		$in = implode(',',$in);
		
		
		safe_delete(
			'rah_post_versions',
			'setid in('.$in.')'
		);
		
		safe_delete(
			'rah_post_versions_sets',
			'id in('.$in.')'
		);
		
		rah_post_versions_list('Selection removed.');
		
	}

/**
	View list of items in a group
*/

	function rah_post_versions_group($message='') {
		
		global $event,$step;
		
		$id = 
			gps('group_id');
		
		extract(doSlash(gpsa(array(
			'group_step',
			'group_page',
			'group_limit',
			'group_order'
		))));
		
		$q[] = "setid='".doSlash($id)."'";
		
		if($group_step)
			$q[] = "step='".doSlash($group_step)."'";
		
		if(!$group_limit || !is_numeric($group_limit) || trim($group_limit,'0123456789') != '')
			$group_limit = 20;
		
		if(is_numeric($group_page) && $group_page > 0 && is_numeric($group_limit) && $group_limit > 0)
			$offset = ($group_page*$group_limit)-$group_limit;
		else 
			$offset = 0;
		
		if(!in_array($group_order,array(
			'title',
			'posted',
			'step',
			'author',
		)))
			$group_order = 'id';
		
		$rs = 
			safe_rows(
				'id,title,posted,author,step',
				'rah_post_versions',
				implode(' and ',$q)." order by $group_order desc LIMIT $offset, $group_limit"
			);
		
		$total = 
			safe_count(
				'rah_post_versions',
				implode(' and ',$q)
			);
		
		$out[] = 
			
			'		<form id="rah_post_versions_filter" method="get" action="index.php">'.n.
			
			rah_post_versions_input(
				array(
					'event' => $event,
					'step' => $step,
					'group_id' => $id
				),
				array(
					'filter_step',
					'filter_limit',
					'filter_order',
					'filter_page'
				)
			).n.
			
			'			<select name="group_step">'.n.
			'				<option value="">Filter by the step...</option>'.n.
			rah_post_versions_steper().n.
			'			</select>'.n.
			'			<select name="group_limit">'.n.
			'				<option value="">Items per page...</option>'.n.
			'				<option value="10">10</option>'.n.
			'				<option value="25">25</option>'.n.
			'				<option value="50">50</option>'.n.
			'				<option value="100">100</option>'.n.
			'			</select>'.n.
			'			<select name="group_order">'.n.
			'				<option value="">Sort by...</option>'.n.
			'				<option value="id">ID</option>'.n.
			'				<option value="title">Title</option>'.n.
			'				<option value="posted">Posted</option>'.n.
			'				<option value="step">Step</option>'.n.
			'				<option value="author">Author</option>'.n.
			'			</select>'.n.
			'			<input type="submit" value="Filter" class="smallerbox" />'.n.
			'		</form>'.n.
			
			'		<form method="post" action="index.php" onsubmit="return verify(\'Are you sure?\')">'.n.
			
			rah_post_versions_input(
				array(
					'event' => $event,
					'step' => $step,
					'group_id' => $id
				)
			).n.
			
			'			<table id="list" class="list" cellspacing="0" cellpadding="0">'.n.
			'				<tr>'.n.
			'					<th>#ID</th>'.n.
			'					<th>Title</th>'.n.
			'					<th>Posted</th>'.n.
			'					<th>Step</th>'.n.
			'					<th>Author</th>'.n.
			'					<th>&#160;</th>'.n.
			'				</tr>'.n;
		
		if($rs){
		
			foreach($rs as $a) {
			
				$url = 
					rah_post_versions_uri(
						array(
							'event' => $event,
							'step' => 'rah_post_versions_view',
							'group_id' => $id,
							'id' => $a['id']
						)
					);
			
				$out[] =  
					
					'				<tr>'.n.
					'					<td>'.$a['id'].'</td>'.n.
					'					<td><a href="'.$url.'">'.htmlspecialchars($a['title']).'</a></td>'.n.
					'					<td>'.safe_strftime('%b %d %Y %H:%M:%S',strtotime($a['posted'])).'</td>'.n.
					'					<td>'.htmlspecialchars($a['step']).'</td>'.n.
					'					<td>'.htmlspecialchars($a['author']).'</td>'.n.
					'					<td><input type="checkbox" name="selected[]" value="'.$a['id'].'" /></td>'.n.
					'				</tr>'.n;
			}
			
		} else 
			$out[] = 
				'				<tr>'.n.
				'					<td colspan="6">No version data found.</td>'.n.
				'				</tr>'.n;
		
		$out[] = 
			'			</table>'.n.
			
			rah_post_versions_pagination($total,'group_limit','group_page').n.
			
			'			<p class="rah_post_versions_step">'.n.
			'				<select name="step">'.n.
			'					<option value="">With selected...</option>'.n.
			'					<option value="rah_post_versions_diff">Diff</option>'.n.
			'					<option value="rah_post_versions_delete">Delete</option>'.n.
			'				</select>'.n.
			'				<input type="submit" class="smallerbox" value="Go" />'.n.
			'			</p>'.n.
			'		</form>'.n.
			'	</div>'.n;
		
		rah_post_versions_header(
			$out,$message,'Viewing set #'.htmlspecialchars($id)
		);
		
	}

/**
	Deletes individual item from a group
*/

	function rah_post_versions_delete() {
		$selected = ps('selected');
		
		if(!is_array($selected)) {
			rah_post_versions_group('Nothing selected.');
			return;
		}
		
		foreach($selected as $id)
			$in[] = "'".doSlash($id)."'";
		
		if(!isset($in)) {
			rah_post_versions_group('Something gone wrong.');
			return;
		}
		
		$in = implode(',',$in);
		
		
		safe_delete(
			'rah_post_versions',
			'id in('.$in.')'
		);
		
		rah_post_versions_group('Selection removed.');
	}

/**
	View differences
*/

	function rah_post_versions_diff($message='') {
		
		global $event;
		
		$selection = ps('selected');
		
		if(!is_array($selection) or count($selection) != 2) {
			rah_post_versions_group('Invalid selection. Select two items to compare.');
			return;
		}
		
		sort($selection);
		
		$results = rah_post_versions_diff_data($selection[0],$selection[1]);
		
		if(empty($results)) {
			rah_post_versions_group('Selected items not found.');
			return;
		}
		
		rah_post_versions_header(
			$results,$message,'Diff of <a href="'.
				rah_post_versions_uri(
					array(
						'event' => $event,
						'step' => 'rah_post_versions_view',
						'group_id' => gps('group_id'),
						'id' => $selection[0]
					)
				).'">r'.$selection[0].'</a> and <a href="'.
				rah_post_versions_uri(
					array(
						'event' => $event,
						'step' => 'rah_post_versions_view',
						'group_id' => gps('group_id'),
						'id' => $selection[1]
					)
				).'">r'.$selection[1].'</a>'
		);
	}

/**
	Build comparison of two data array()s
*/

	function rah_post_versions_diff_data($old,$new) {
		
		$old = 
			safe_field(
				'data',
				'rah_post_versions',
				"id='".doSlash($old)."' and setid='".doSlash(gps('group_id'))."'"
			);
		
		$new = 
			safe_field(
				'data',
				'rah_post_versions',
				"id='".doSlash($new)."' and setid='".doSlash(gps('group_id'))."'"
			);
		
		if(!$new || !$old)
			return;
		
		$new = unserialize(base64_decode($new));
		$old = unserialize(base64_decode($old));
		
		unset(
			$old['rah_article_versions_repost_is'],
			$old['rah_article_versions_repost_uri'],
			$old['rah_article_versions_repost_id'],
			$new['rah_article_versions_repost_id'],
			$new['rah_article_versions_repost_is'],
			$new['rah_article_versions_repost_uri']
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
			
			/*
				Check if array. If true make the array a single block
			*/
			
			if(is_array($old[$key]))
				$old[$key] = implode(n,$old[$key]);
			if(is_array($val))
				$val = implode(n,$val);
			
			$out[] = 
				'<p><strong>'.htmlspecialchars($key).':</strong></p>'.n.
				'<div class="rah_post_versions_diff">'.
					rah_post_versions_diff_lib(
						$old[$key],
						$val
					).n.
				'</div>';
			
			unset($old[$key]);
		}
		
		/*
			List removed fields (removed custom-fields / updated TXP) as removed
		*/
		
		if($old)
			foreach($old as $key => $val) 
				$out[] = 
					'<p><strong><del>'.htmlspecialchars($key).'</del>:</strong></p>'.n.
					'<div class="rah_post_versions_diff"><span class="rah_post_versions_del">'.htmlspecialchars($val).'</span></div>'.n
				;
		
		if(!isset($out))
			$out[] = '<p>The revisions are exact match. No changes to show.</p>';
		
		return implode('',$out);
	}

/**
	Starts splitting the lines, and then implodes the results
*/

	function rah_post_versions_diff_lib($old, $new){
		
		foreach(
			rah_post_versions_diff_compare(
				rah_post_versions_explode($old),
				rah_post_versions_explode($new)
			) as $key => $line
		){
			if(is_array($line)) {
				if(!empty($line['d'])) 
					$out[] = '<span class="rah_post_versions_del">'.htmlspecialchars(implode(n,$line['d'])).'</span>';
				if(!empty($line['i']))
					$out[] = '<span class="rah_post_versions_add">'.htmlspecialchars(implode(n,$line['i'])).'</span>';
			} else
				$out[] = htmlspecialchars($line);
		}
		
		return implode(n,$out);
	}

/**
	Compares lines we just split
*/

	function rah_post_versions_diff_compare($old, $new, $maxlen = 0){

		/*
			rah_post_version_diff_compare() function's contents are based on:
			
			Paul's Simple Diff Algorithm v 0.1
			(C) Paul Butler 2007 <http://www.paulbutler.org/>
			Licensed under GNU GPL compatible zlib/libpng license.
			
			***

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
			
			***
		*/

		foreach($old as $oindex => $ovalue){
			$nkeys = array_keys($new, $ovalue);
			foreach($nkeys as $nindex){
				$matrix[$oindex][$nindex] = isset($matrix[$oindex - 1][$nindex - 1]) ? $matrix[$oindex - 1][$nindex - 1] + 1 : 1;
				if($matrix[$oindex][$nindex] > $maxlen){
					$maxlen = $matrix[$oindex][$nindex];
					$omax = $oindex + 1 - $maxlen;
					$nmax = $nindex + 1 - $maxlen;
				}
			}
		}
		
		if($maxlen == 0)
			return array(array('d'=>$old, 'i'=>$new));
		
		return 
			array_merge(
				rah_post_versions_diff_compare(
					array_slice($old, 0, $omax),
					array_slice($new, 0, $nmax)
				),
				array_slice($new, $nmax, $maxlen),
				rah_post_versions_diff_compare(
					array_slice($old, $omax + $maxlen),
					array_slice($new, $nmax + $maxlen)
				)
			)
		;
	}

/**
	Clean line breaks and do explode.
*/

	function rah_post_versions_explode($string='') {
		$string = str_replace(array("\r\n","\r"), n, $string);
		return explode(n,$string);
	}

/**
	Shows input fields
*/

	function rah_post_versions_field($value='',$key='',$i=1,$hidden=false) {
		if($hidden == true) 
			return 
				'						<input type="hidden" name="'.htmlspecialchars($key).'" value="'.htmlspecialchars($value).'" />';
		
		return
			'				<p>'.n.
			'					<label for="rah_post_versions_label_'.$i.'">'.htmlspecialchars($key).'</label><br />'.n.
			((substr_count($value,n) == 0) ?
				'						<input id="rah_post_versions_label_'.$i.'" type="text" name="'.htmlspecialchars($key).'" class="edit" value="'.htmlspecialchars($value).'" />'
			:
				'						<textarea id="rah_post_versions_label_'.$i.'" name="'.htmlspecialchars($key).'" class="code" cols="100" rows="6">'.htmlspecialchars($value).'</textarea>'
			).n.
			'				</p>'.n
		;
	}

/**
	Views individual changeset
*/

	function rah_post_versions_view($message='') {
		
		global $event;
		
		$id = 
			gps('id');
			
		extract(
			rah_post_versions_do_prefs()
		);
		
		$hidden = explode(',',$hidden);
		
		$rs = 
			safe_row(
				'*',
				'rah_post_versions',
				"id='".doSlash($id)."'"
			);
			
		if($rs) {
			$data = base64_decode($rs['data']);
			$data = unserialize($data);
			
			$back = 
				rah_post_versions_uri(
					array(
						'event' => $event,
						'step' => 'rah_post_versions_group',
						'group_id' => gps('group_id')
					)
				);
			
			$out[] = 
				'		<form method="post" action="index.php" onsubmit="return verify(\'Are you sure? There is no going back.\')">'.n.
				'			<p id="rah_post_versions_actions">&#171; <a href="'.$back.'">Go back</a> <strong>Ident:</strong> '.$rs['title'].' <strong>Saved:</strong> '.$rs['posted'].' by '.$rs['author'].'. <strong>Event</strong> <a href="?event='.$rs['event'].'">'.$rs['event'].'</a>, '.$rs['step'].'</p>'.n;
			
			$i = 0;
			
			foreach($data as $key => $value) {

				$i++;
				
				if(is_array($value)) {
					foreach($value as $needle => $selection) 
						$out[] = 
							rah_post_versions_field(
								$selection,
								$key.'['.$needle.']',
								$i,
								in_array($key,$hidden)
							);
					continue;
				}
				
				$out[] = 
					rah_post_versions_field(
						$value,
						$key,
						$i,
						in_array($key,$hidden)
					);
				
			}

			$out[] = 
				'			<input type="hidden" name="rah_article_versions_repost_is" value="1" />'.n.
				'			<input type="hidden" name="rah_article_versions_repost_uri" value="'.htmlspecialchars($back).'" />'.n.
				'			<input type="hidden" name="rah_article_versions_repost_id" value="'.htmlspecialchars($id).'" />'.n.
				
				'			<p id="rah_post_versions_warning"><strong>Notice:</strong> Only click the <em>Re-post this</em> button when you are certain what you are about to do. Clicking the button will redo the exact posting, and depending of the information state stored, it might either overwrite, partially replace or dublicate something.</p>'.n.
				'			<p><input type="submit" class="publish" value="Re-post this" /></p>'.n.
				'		</form>';
		} else
			$out[] = '<p>Nothing to show</p>';
		
		$slogan = 'Viewing change #'.htmlspecialchars($id);
			
		rah_post_versions_header(
			$out,$message,$slogan
		);
		
	}

/**
	Shows message after repost
*/

	function rah_post_versions_messager() {
		
		if(!ps('rah_article_versions_repost_is'))
			return;
		
		extract(gpsa(array(
			'rah_article_versions_repost_uri',
			'rah_article_versions_repost_id'
		)));
		
		echo 
			n.
			'	<p id="rah_post_versions_messager" style="text-align:center;padding: 5px 20px;">'.
			'		Reposted the form #'.htmlspecialchars($rah_article_versions_repost_id).'. '.n.
			'		<a href="'.$rah_article_versions_repost_uri.'">Go back to the version listing</a>.'.n.
			'	</p>'.n;
	}

/**
	Fetch preferences to usable format from the database
*/

	function rah_post_versions_do_prefs() {
		
		$rs = 
			safe_rows(
				'name,value',
				'rah_post_versions_prefs',
				'1=1'
			);
			
		if(!$rs)
			return;
		
		foreach($rs as $a) 
			$out[$a['name']] = $a['value'];
		
		return $out;
	}

/**
	Adds preferences array to database
*/

	function rah_post_versions_add_pref($array) {
		foreach($array as $name => $value) {
			if(
				safe_count(
					'rah_post_versions_prefs',
					"name='".doSlash($name)."'"
				) == 0
			)
				safe_insert(
					'rah_post_versions_prefs',
					"name='".doSlash($name)."',
					value='".doSlash($value)."'"
				);
		}
	}

/**
	Preferences panel
*/

	function rah_post_versions_prefs($message='') {
		
		
		global $event;
		
		extract(
			rah_post_versions_do_prefs()
		);
		
		$out = 
			
			'		<form method="post" action="index.php">'.n.
			
			rah_post_versions_input(
				array(
					'event' => $event,
					'step' => 'rah_post_versions_prefs_save'
				)
			).n.
			
			'			<p>'.n.
			'				<label>Excluded fields. Comma seperated list if multiple.</label><br />'.n.
			'				<input class="edit" type="text" name="exclude" value="'.htmlspecialchars($exclude).'" />'.n.
			'			</p>'.n.
			
			'			<p>'.n.
			'				<label>Set fields hidden. Comma seperated list if multiple. These fields are stored, but hid.</label><br />'.n.
			'				<input class="edit" type="text" name="hidden" value="'.htmlspecialchars($hidden).'" />'.n.
			'			</p>'.n.
			
			'			<p>'.n.
			'				<label>Monitor following pages. Comma seperated list if multiple. The format of item is <code>event:step</code>.</label><br />'.n.
			'				<textarea name="events" rows="4" cols="30">'.htmlspecialchars($events).'</textarea>'.n.
			'			</p>'.n.
			
			'			<p>'.n.
			'				<label>Excluded authors. Comma seperated list if multiple. Uses login names.</label><br />'.n.
			'				<input class="edit" type="text" name="authors" value="'.htmlspecialchars($authors).'" />'.n.
			'			</p>'.n.
			
			'			<p>'.n.
			'				<label>Send versions to author\'s email?</label><br />'.n.
			'				<select name="email">'.n.
			'					<option value=""'.(($email == 'No') ? ' selected="selected"' : '').'>No</option>'.n.
			'					<option value=""'.(($email == 'Yes') ? ' selected="selected"' : '').'>Yes</option>'.n.
			'				</select>'.n.
			'			</p>'.n.
			
			'			<p>'.n.
			'				<label>Additional email addresses. Sends changes to this address. Comma seperated list if multiple.</label><br />'.n.
			'				<input class="edit" type="text" name="email_additional" value="'.htmlspecialchars($email_additional).'" />'.n.
			'			</p>'.n.
			
			'			<p>'.n.
			'				<label>Ident code. Code that generates the Ident for a change.</label><br />'.n.
			'				<textarea class="code" cols="30" rows="18" name="ident">'.htmlspecialchars($ident).'</textarea>'.n.
			'			</p>'.n.
			
			'			<p><input type="submit" class="publish" value="Save settings" /></p>'.n.
			'		</form>'.n;
			
		
		rah_post_versions_header(
			$out,
			$message,
			'Preferences'
		);
		
	}

/**
	Saves preferences
*/

	function rah_post_versions_prefs_save() {
		
		extract(
			gpsa(
				array(
					'exclude',
					'events',
					'email_additional',
					'authors',
					'hidden',
					'ident',
					'email'
				)
			)
		);
		
		$authors = rah_post_versions_list_trim($authors);
		$exclude = rah_post_versions_list_trim($exclude);
		$hidden = rah_post_versions_list_trim($hidden);
		$email_additional = rah_post_versions_list_trim($email_additional);
		
		rah_post_versions_pref_update(
			array(
				'exclude' => $exclude,
				'events' => $events,
				'email_additional' => $email_additional,
				'email' => $email,
				'authors' => $authors,
				'hidden' => $hidden,
				'ident' => $ident
			)
		);

		rah_post_versions_prefs(
			'Preferences saved.'
		);
		
	}
	
/**
	Goes thru an array and pushes it to the preferences table.
*/

	function rah_post_versions_pref_update($array) {
		foreach($array as $name => $value)
			safe_update(
				'rah_post_versions_prefs',
				"value='".doSlash($value)."'",
				"name='".doSlash($name)."'"
			);
	}

/**
	Trims a $delim seperated list of items.
*/

	function rah_post_versions_list_trim($array='',$delim=',') {
		
		if(!$array)
			return;
		
		if(!is_array($array))
			$array = explode($delim,$array);
		
		foreach($array as $item) {
			$item = trim($item);
			if(!empty($item))
				$out[] = $item;
		}
		
		if(!isset($out))
			return;
		
		return implode($delim,$out);
		
	}

/**
	Returns the ID
*/

	function rah_post_versions_id($key='ID') {
		$id = gps($key);
		if(!$id and isset($GLOBALS['ID']))
			$id = $GLOBALS['ID'];
		return $id;
	}

/**
	Saves versions from POSTs
*/

	function rah_post_versions() {
		
		global $txp_user,$event,$step,$sitename;
		
		/*
			Check if the step has post
		*/
		
		$post = (isset($_POST) and is_array($_POST)) ? $_POST : '';
		
		if(empty($post))
			return;
			
		extract(
			rah_post_versions_do_prefs()
		);
		
		/*
			Evaluates ident
		*/
		
		eval(
			$ident
		);
		
		/*
			Check if the ident returned the data we want
		*/
		
		if(isset($kill) or !isset($grid) or !isset($title))
			return;
		
		if(empty($title) or empty($grid))
			return;
		
		/*
			Excludes fields
		*/
		
		$exclude = explode(',',$exclude);
		
		foreach($post as $key => $value) {
			
			if(in_array($key,array(
				'rah_article_versions_repost_is',
				'rah_article_versions_repost_uri',
				'rah_article_versions_repost_id'
			)))
				continue;
			
			if(in_array($key,$exclude))
				continue;
			
			$out[$key] = ps($key);
		}
		
		if(!isset($out))
			return;
		
		$data = doSlash(base64_encode(serialize($out)));
		
		if(
			safe_count(
				'rah_post_versions_sets',
				"event='".doSlash($event)."' and grid='".doSlash($grid)."'"
			) == 0
		) {
			safe_insert(
				'rah_post_versions_sets',
				"modified=now(),
				changes=1,
				title='".doSlash($title)."',
				event='".doSlash($event)."',
				step='".doSlash($step)."',
				grid='".doSlash($grid)."'"
			);
			$setid = mysql_insert_id();
		}
		else {
			safe_update(
				'rah_post_versions_sets',
				'modified=now(),changes=changes+1',
				"event='".doSlash($event)."' and grid='".doSlash($grid)."'"
			);
			$setid = 
				safe_field(
					'id',
					'rah_post_versions_sets',
					"event='".doSlash($event)."' and grid='".doSlash($grid)."'"
				);
		}
		
		safe_insert(
			'rah_post_versions',
			"grid='".doSlash($grid)."',
			setid='".doSlash($setid)."',
			title='".doSlash($title)."',
			posted=now(),
			author='".doSlash($txp_user)."',
			event='".doSlash($event)."',
			step='".doSlash($step)."',
			data='$data'"
		);
		
		/*
			Emailing
		*/
		
		if($email != 'Yes' && empty($email_additional))
			return;
		
		if(!empty($email_additional))
			$mails = explode(',',$email_additional);
		
		if($email == 'Yes') {
			$mail = trim(fetch('email','txp_users','name',$txp_user));
			if(!empty($mail))
				$mails[] = $mail;
		}
		
		if(!isset($mails))
			return;
		
		$message = 
			gTxt('greeting').' '.$txp_user.','.n.n.
			'View the changes at:'.n.
			hu.'textpattern/index.php?event=rah_post_versions'
		;
		
		foreach($mails as $mail)
			txpMail(
				$mail,
				"[$sitename] Change in the content",
				$message
			);
	}

/**
	Echoes the panels and header
*/

	function rah_post_versions_header($content='',$message='',$slogan='Version index') {
		
		global $event;
		pagetop('rah_post_versions',$message);
		
		if(is_array($content))
			$content = implode('',$content);
		
		echo 
			n.
			'	<div id="rah_post_versions_container">'.n.
			'		<h1><strong>rah_post_versions</strong> | '.$slogan.'</h1>'.n.
			
			'		<p id="rah_post_versions_nav">'.
			' &#187; <a href="'.rah_post_versions_uri(array('event' => $event)).'">Main</a> '.
			' &#187; <a href="'.rah_post_versions_uri(array('event' => $event,'step' => 'rah_post_versions_prefs')).'">Preferences</a> '.
			' &#187; <a href="?event=plugin&amp;step=plugin_help&amp;name=rah_post_versions">Documentation</a>'.
					
			'</p>'.n.
			$content.n.
			'	</div>'.n;
	}

/**
	Lists event names
*/

	function rah_post_versions_eventer() {
		global $plugin_areas;
		
		$out = array();
		
		$areas['content'] = array(
			gTxt('tab_organise') => 'category',
			gTxt('tab_write') => 'article',
			gTxt('tab_list') =>  'list',
			gTxt('tab_image') => 'image',
			gTxt('tab_file') => 'file',					 
			gTxt('tab_link') => 'link',
			gTxt('tab_comments') => 'discuss'
		);
		$areas['presentation'] = array(
			gTxt('tab_sections') => 'section',
			gTxt('tab_pages') => 'page',
			gTxt('tab_forms') => 'form',
			gTxt('tab_style') => 'css'
		);
		$areas['admin'] = array(
			gTxt('tab_diagnostics') => 'diag',
			gTxt('tab_preferences') => 'prefs',
			gTxt('tab_site_admin')  => 'admin',
			gTxt('tab_logs') => 'log',
			gTxt('tab_import') => 'import'
		);
		
		$areas['extensions'] = array();
		if(is_array($plugin_areas))
			$areas = array_merge_recursive($areas, $plugin_areas);
		
		foreach ($areas as $group) 
			foreach ($group as $title => $name) 
				$out[$name] = $title;
			
		
		return $out;
	}

/**
	Generates the list of available steps
*/
	
	function rah_post_versions_steper() {
		
		$id = gps('group_id');
		
		$rs = 
			safe_rows(
				'step',
				'rah_post_versions',
				"setid='".doSlash($id)."' GROUP BY step ORDER BY step asc"
			);
			
		if(!$rs)
			return;
		
		foreach($rs as $a)
			$out[] = '				<option value="'.htmlspecialchars($a['step']).'">'.htmlspecialchars($a['step']).'</option>';
		
		return
			implode(n,$out);
	}
?>
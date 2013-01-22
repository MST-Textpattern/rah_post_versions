<?php

/**
 * Rah_post_versions plugin for Textpattern CMS.
 *
 * @author  Jukka Svahn
 * @date    2010-
 * @license GNU GPLv2
 * @link    http://rahforum.biz/plugins/rah_post_versions
 *
 * Copyright (C) 2012 Jukka Svahn http://rahforum.biz
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

	rah_post_versions::get();

/**
 * The plugin class.
 */

class rah_post_versions
{
	/**
	 * Version number.
	 *
	 * @var string
	 */

	static public $version = '1.0';

	/**
	 * Stores instances.
	 *
	 * @var rah_post_versions
	 */

	static public $instance = NULL;

	/**
	 * List of event labels.
	 *
	 * @var array
	 */

	protected $events = array();

	/**
	 * Path to repository.
	 *
	 * @var string
	 */

	protected $static_dir = false;

	/**
	 * Static heading.
	 *
	 * @var string
	 */

	protected $static_header = '';

	/**
	 * Write status.
	 *
	 * @var bool
	 */

	protected $nowrite = false;

	/**
	 * Compress or not.
	 *
	 * @var bool
	 */

	protected $compress = false;

	/**
	 * Installer.
	 *
	 * @param string $event Plugin-lifecycle event
	 * @param string $step  Plugin-lifecycle step
	 */

	static public function install($event = '', $step = '')
	{
		global $prefs;

		if ($step == 'deleted')
		{
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

		if ((string) get_pref(__CLASS__.'_version') === self::$version)
		{
			return;
		}

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

		$position = 250;

		foreach (
			array(
				'gzip'            => array('yesnoradio', 0),
				'repository_path' => array('text_input', ''),
			) as $name => $val
		)
		{
			$n = __CLASS__.'_'.$name;

			if (!isset($prefs[$n]))
			{
				set_pref($n, $val[1], 'rah_postver', PREF_ADVANCED, $val[0], $position);
			}

			$position++;
		}

		set_pref(__CLASS__.'_version', self::$version, 'rah_postver', PREF_HIDDEN);
	}

	/**
	 * Redirects to the plugin's admin-side panel.
	 */

	public function prefs()
	{
		header('Location: ?event=rah_post_versions');

		echo 
			'<p>'.n.
			'	<a href="?event=rah_post_versions">'.gTxt('continue').'</a>'.n.
			'</p>';
	}

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		add_privs('rah_post_versions', '1,2');
		add_privs('rah_post_versions_delete_item', '1');
		add_privs('rah_post_versions_delete_revision', '1');
		add_privs('rah_post_versions_diff', '1,2');
		add_privs('rah_post_versions_preferences', '1');
		add_privs('plugin_prefs.rah_post_versions', '1,2');
		add_privs('prefs.rah_post_versions', '1');

		register_callback(array(__CLASS__, 'install'), 'plugin_lifecycle.rah_post_versions');
		register_callback(array($this, 'panes'), 'rah_post_versions');
		register_callback(array($this, 'prefs'), 'plugin_prefs.rah_post_versions');
		register_tab('extensions','rah_post_versions', gTxt('rah_post_versions'));

		$this->initialize();
	}

	/**
	 * Initializes.
	 */

	public function initialize()
	{
		global $prefs;

		if (!defined('rah_post_versions_static_dir'))
		{
			define('rah_post_versions_static_dir', true);
		}

		if ($prefs['rah_post_versions_gzip'] && function_exists('gzencode') && function_exists('gzinflate'))
		{
			$this->compress = true;
		}

		$this->go_static();
	}

	/**
	 * Gets an instance.
	 *
	 * @return rah_post_versions
	 */

	static public function get()
	{
		if (self::$instance === null)
		{
			self::$instance = new rah_post_versions();
		}

		return self::$instance;
	}

	/**
	 * Shows requested admin-side page.
	 */

	public function panes()
	{
		require_privs('rah_post_versions');

		global $step;

		$steps = array(
			'browser'    => false,
			'changes'    => false,
			'diff'       => false,
			'multi_edit' => true,
			'revert'     => true,
		);

		if (!$step || !bouncer($step, $steps))
		{
			$step = 'browser';
		}

		$this->$step();
	}

	/**
	 * Lists admin-side event names and labels.
	 *
	 * @return array
	 */

	protected function pop_events()
	{
		if ($this->events || !function_exists('areas') || !is_array(areas()))
		{
			return $this->events;
		}

		foreach (areas() as $tab_group)
		{
			foreach ($tab_group as $label => $event)
			{
				$this->events[$event] = $label;
			}
		}

		return $this->events;
	}

	/**
	 * Get a revision from the database.
	 *
	 * @param  string $where SQL where clause
	 * @return array
	 */

	public function get_revision($where)
	{
		global $prefs;

		$r = safe_row('*', 'rah_post_versions', $where);

		if (!$r)
		{
			return array();
		}

		if ($this->nowrite == true)
		{
			$r['data'] = '';
		}

		if ($r && $this->static_dir)
		{
			$file = $this->static_dir . DS . $r['setid'] . '_r' . $r['id'] . '.php';

			if (file_exists($file) && is_file($file) && is_readable($file))
			{
				ob_start();
				include $file;
				$r['data'] = ob_get_contents();
				ob_end_clean();
			}
			else
			{
				$r['data'] = '';
			}
		}

		if (!empty($r['data']))
		{
			@$r['data'] = base64_decode($r['data']);

			if ($this->compress && strncmp($r['data'], "\x1F\x8B", 2) === 0)
			{
				$r['data'] = gzinflate(substr($r['data'], 10));
			}

			@$r['data'] = unserialize($r['data']);
		}

		return $r;
	}

	/**
	 * Creates a new revision.
	 *
	 * @param  string $grid   Group identifier
	 * @param  string $title  The title
	 * @param  string $author The author
	 * @param  string $event  The event
	 * @param  string $step   The step
	 * @param  array  $data   The revision data
	 * @return bool   FALSE on failure
	 */

	public function create_revision($grid, $title, $author, $event, $step, $data)
	{
		global $prefs;

		if ($this->nowrite || !$grid || !$event || !$step)
		{
			return false;
		}

		foreach (array('grid', 'title', 'author', 'event', 'step') as $name)
		{
			$sql[$name] = $name."='".doSlash($$name)."'";
		}

		if ($title === '')
		{
			$sql['title'] = "title='".doSlash($grid)."'";
		}

		$sql['posted'] = 'posted=now()';

		$setid = safe_field(
			'id',
			'rah_post_versions_sets',
			$sql['event'].' and '.$sql['grid'].' limit 1'
		);

		if (!$setid)
		{
			$setid = safe_insert(
				'rah_post_versions_sets',
				'modified=now(), changes=1,'.
				$sql['title'].','.
				$sql['event'].','.
				$sql['step'].','.
				$sql['grid']
			);

			if ($setid === false)
			{
				return false;
			}
		}
		else
		{	
			$latest = $this->get_revision("setid='".doSlash($setid)."' ORDER BY id desc");

			if ($latest && $data === $latest['data'])
			{
				return true;
			}

			if (
				safe_update(
					'rah_post_versions_sets',
					'modified=now(), changes=changes+1 '.($title ? ','.$sql['title'] : '' ),
					"id='".doSlash($setid)."'"
				) == false
			)
			{
				return false;
			}
		}

		if ($this->compress)
		{
			$data = base64_encode(gzencode(serialize($data)));
		}
		else
		{
			$data = base64_encode(serialize($data));
		}

		if (!$this->static_dir)
		{
			$sql['data'] = "data='".doSlash($data)."'";
		}

		$sql['setid'] = "setid='".doSlash($setid)."'";

		$id = safe_insert(
			'rah_post_versions',
			implode(',', $sql)
		);
	
		if ($id === false)
		{
			return false;
		}

		if ($this->static_dir)
		{
			if (
				file_put_contents(
					$this->static_dir.'/'.$setid.'_r'.$id.'.php',
					$this->static_header.$data
				) === false
			)
			{
				return false;
			}
		}

		callback_event('rah_post_versions.revision_created', '', 0, $data);
		return true;
	}

	/**
	 * Exports revision data from the database to static files.
	 *
	 * @return bool|null FALSE on error, TRUE on success. NULL when export isn't required.
	 */

	protected function go_static()
	{
		global $prefs;

		if ($this->static_dir !== false)
		{
			return;
		}

		$this->static_header = 
			'<?php if(!defined("rah_post_versions_static_dir")) die("rah_post_versions_static_dir undefined"); ?>';

		$dir = trim(rtrim($prefs['rah_post_versions_repository_path'], '/\\'));

		if ($dir && strpos($dir, './') === 0)
		{
			$dir = txpath.DS.substr($dir, 2);
		}

		if ($dir && file_exists($dir) && is_dir($dir) && is_readable($dir) && is_writable($dir))
		{
			$this->static_dir = $dir;
		}

		if (!$this->static_dir && isset($prefs['rah_post_versions_static']))
		{
			$this->nowrite = true;
			return;
		}

		if (!$this->static_dir || isset($prefs['rah_post_versions_static']))
		{
			return;
		}

		$r = @getThings('describe '.safe_pfx('rah_post_versions'));

		if (!$r || !is_array($r) || !in_array('data', $r))
		{
			return;
		}

		$rs = safe_rows(
			'data, setid, id',
			'rah_post_versions',
			'1=1'
		);

		foreach ($rs as $a)
		{
			$file = $this->static_dir . DS . $a['setid'] . '_r' . $a['id'] . '.php';

			if (!file_exists($file) && file_put_contents($file, $this->static_header . $a['data']) === false)
			{
				return false;
			}
		}

		if (safe_alter('rah_post_versions', 'DROP data') === false)
		{
			return false;
		}

		set_pref('rah_post_versions_static', 1, 'rah_postver', 2, '', 0);
		$prefs['rah_post_versions_static'] = 1;

		return true;
	}

	/**
	 * Lists all items.
	 *
	 * @param string|array $message The activity message
	 */

	public function browser($message = '')
	{	
		global $event;

		extract(gpsa(array(
			'filter_event',
			'sort',
			'dir',
		)));

		$methods = array();
		$columns = array('id', 'event', 'title', 'modified', 'changes');

		if (has_privs('rah_post_versions_delete_item'))
		{
			$methods['delete_item'] = gTxt('delete');
		}

		if ($dir !== 'desc' && $dir !== 'asc')
		{
			$dir = get_pref($event.'_browser_dir', 'desc');
		}

		if (!in_array((string) $sort, $columns))
		{
			$sort = get_pref($event.'_browser_column', 'modified');
		}

		set_pref($event.'_browser_column', $sort, $event, 2, '', 0, PREF_PRIVATE);
		set_pref($event.'_browser_dir', $dir, $event, 2, '', 0, PREF_PRIVATE);

		$sql = array('1=1');

		if ($filter_event)
		{
			$sql[] = "event='".doSlash($filter_event)."'";
		}

		$total = safe_count(
			'rah_post_versions_sets',
			implode(' and ', $sql)
		);

		$limit = 15;

		list($page, $offset, $num_pages) = pager($total, $limit, gps('page'));

		if ($methods)
		{
			$column[] = hCell(fInput('checkbox', 'select_all', 1, '', '', '', '', '', 'select_all'), '', ' title="'.gTxt('toggle_all_selected').'" class="multi-edit"');
		}

		foreach ($columns as $name)
		{
			$column[] = column_head($event.'_'.$name, $name, $event, true, $name === $sort && $dir === 'asc' ? 'desc' : 'asc', '', '', ($name === $sort ? $dir : ''));
		}

		if (has_privs('rah_post_versions_preferences'))
		{
			$out[] = '<p class="txp-buttons"><a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_post_versions_gzip">'.gTxt('rah_post_versions_preferences').'</a></p>';
		}

		$out[] =
			'<form method="post" action="index.php" class="multi_edit_form">'.
			eInput($event).
			tInput().
			'<div class="txp-listtables">'.
			'<table class="txp-list">'.
			'<thead>'.tr(implode('', $column)).'</thead>'.
			'<tbody>';

		$rs = safe_rows(
			'id, event, step, grid, title, modified, changes',
			'rah_post_versions_sets',
			implode(' and ', $sql) . " order by {$sort} {$dir} limit {$offset}, {$limit}"
		);

		$events = $this->pop_events();

		foreach ($rs as $a)
		{		
			if (trim($a['title']) === '')
			{
				$a['title'] = gTxt('untitled');
			}
	
			if (isset($events[$a['event']]))
			{
				$a['event'] = $events[$a['event']];
			}

			$column = array();

			if ($methods)
			{
				$column[] = td(fInput('checkbox', 'selected[]', $a['id']), '', 'multi-edit');
			}

			$column[] = td($a['id']);
			$column[] = td(txpspecialchars($a['event']));
			$column[] = td('<a href="?event='.$event.'&amp;step=changes&amp;item='.$a['id'].'">'.txpspecialchars($a['title']).'</a>');
			$column[] = td(safe_strftime(gTxt('rah_post_versions_date_format'), strtotime($a['modified'])));
			$column[] = td($a['changes']);

			$out[] = tr(implode('', $column));
		}

		if (!$rs)
		{
			$out[] = tr('<td colspan="'.count($column).'">'.gTxt('rah_post_versions_no_changes').'</td>');
		}

		$out[] = 
			'</tbody>'.n.
			'</table>'.n.
			'</div>'.n;

		if ($methods)
		{
			$out[] = multi_edit($methods, $event, 'multi_edit');
		}

		$out[] = 
			'</form>'.
			$this->pages('browser', $total, $limit);

		$this->pane($out, $message);
	}

	/**
	 * Outputs a panel.
	 *
	 * @param string       $content Pane's HTML markup
	 * @param string|array $message The activity message
	 */

	private function pane($content, $message = '')
	{	
		global $event;

		pagetop(gTxt('rah_post_versions'), $message ? $message : '');

		if (is_array($content))
		{
			$content = implode(n, $content);
		}

		echo '<h1 class="txp-heading">'.gTxt('rah_post_versions').'</h1>'.n;

		if ($this->nowrite == true)
		{
			echo '<p class="alert-block warning">'.gTxt('rah_post_versions_repository_data_missing').'</p>';
		}

		echo '<div class="txp-container">'.n.
			$content.n.
			'</div>'.n;
	}

	/**
	 * Lists changes committed to an item.
	 *
	 * @param string|array $message The activity message
	 */

	protected function changes($message = '')
	{
		global $event;

		extract(gpsa(array(
			'item',
			'sort',
			'dir',
		)));

		if (
			!safe_row(
				'id',
				'rah_post_versions_sets',
				"id='".doSlash($item)."' LIMIT 0, 1"
			)
		)
		{
			$this->browser(array(gTxt('rah_post_versions_unknown_selection'), E_WARNING));
			return;
		}

		$methods = array();
		$columns = array('id', 'title', 'posted', 'step', 'author');

		if (has_privs('rah_post_versions_diff'))
		{
			$methods['diff'] = gTxt('rah_post_versions_diff');
		}

		if (has_privs('rah_post_versions_delete_revision'))
		{
			$methods['delete_revision'] = gTxt('delete');
		}
		
		if ($dir !== 'desc' && $dir !== 'asc')
		{
			$dir = get_pref($event.'_changes_dir', 'desc');
		}
		
		if (!$sort)
		{
			$sort = get_pref($event.'_changes_column', 'posted');
		}

		if (!in_array((string) $sort, $columns))
		{
			$sort = 'posted';
		}

		set_pref($event.'_changes_column', $sort, $event, 2, '', 0, PREF_PRIVATE);
		set_pref($event.'_changes_dir', $dir, $event, 2, '', 0, PREF_PRIVATE);

		$total = safe_count(
			'rah_post_versions',
			"setid='".doSlash($item)."'"
		);

		$limit = 15;

		list($page, $offset, $num_pages) = pager($total, $limit, gps('page'));

		if ($methods)
		{
			$column[] = hCell(fInput('checkbox', 'select_all', 1, '', '', '', '', '', 'select_all'), '', ' title="'.gTxt('toggle_all_selected').'" class="multi-edit"');
		}

		foreach ($columns as $name)
		{
			$column[] = hCell('<a href="?event='.$event.a.'step=changes'.a.'item='.urlencode(gps('item')).a.'page='.$page.a.'sort='.$name.a.'dir='.($name === $sort && $dir === 'asc' ? 'desc' : 'asc').'">'.gTxt($event.'_'.$name).'</a>', '',  ($name === $sort ? ' class="'.$dir.'"' : ''));
		}

		$out[] = '<p class="txp-buttons">';
		$out[] = '<a href="?event='.$event.'">'.gTxt('rah_post_versions_main').'</a>';

		if (has_privs('rah_post_versions_preferences'))
		{
			$out[] = '<a href="?event=prefs&amp;step=advanced_prefs#prefs-rah_post_versions_gzip">'.gTxt('rah_post_versions_preferences').'</a>'.n;
		}

		$out[] = '</p>';

		$out[] = 
			'<form method="post" action="index.php" class="multi_edit_form">'.n.
			eInput($event).n.
			hInput('item', $item).n.
			tInput().n.
			'<div class="txp-listtables">'.n.
			'<table class="txp-list">'.n.
			'<thead>'.tr(implode('', $column)).'</thead>'.
			'<tbody>'.n;

		$rs = safe_rows(
			'id, title, posted, author, step',
			'rah_post_versions',
			"setid='".doSlash($item)."' ORDER BY {$sort} {$dir} LIMIT {$offset}, {$limit}"
		);

		foreach ($rs as $a)
		{
			$column = array();
			
			if ($methods)
			{
				$column[] = td(fInput('checkbox', 'selected[]', $a['id']), '', 'multi-edit');
			}

			$column[] = td($a['id']);
			$column[] = td('<a href="?event='.$event.'&amp;step=diff&amp;item='.$item.'&amp;r='.$a['id'].'">'.txpspecialchars($a['title']).'</a>');
			$column[] = td(safe_strftime(gTxt('rah_post_versions_date_format'), strtotime($a['posted'])));
			$column[] = td(txpspecialchars($a['step']));
			$column[] = td(txpspecialchars(get_author_name($a['author'])));

			$out[] = tr(implode('', $column));
		}

		if (!$rs)
		{
			$out[] =
				'<tr>'.n.
				'	<td colspan="'.count($column).'">'.gTxt('rah_post_versions_no_changes').'</td>'.n.
				'</tr>'.n;
		}

		$out[] =
			'</tbody>'.n.
			'</table>'.n.
			'</div>'.n;

		if ($methods)
		{
			$out[] = multi_edit($methods, $event, 'multi_edit');
		}

		$out[] =
			'</form>'.
			$this->pages('changes', $total, $limit);

		$this->pane($out, $message);
	}

	/**
	 * Shows differences between two revisions.
	 */

	public function diff()
	{	
		global $event;

		extract(gpsa(array(
			'r',
			'item',
			'plain',
		)));

		$new = $old = NULL;

		if (!$r || !is_string($r))
		{
			$this->changes(array(gTxt('rah_post_versions_select_something'), E_WARNING));
			return;
		}

		$r = explode('-', $r);

		if (count($r) === 1 && ($id = (int) $r[0]))
		{
			$new = $this->get_revision("id={$id} and setid='".doSlash($item)."'");
			$old = $this->get_revision("id < {$id} and setid='".doSlash($item)."' ORDER BY id desc LIMIT 1");
		}
		else
		{
			$old = $this->get_revision("id='".doSlash($r[0])."' and setid='".doSlash($item)."'");
			$new = $this->get_revision("id='".doSlash($r[1])."' and setid='".doSlash($item)."'");
		}

		if (!$old && !$new)
		{
			$this->changes(array(gTxt('rah_post_versions_unknown_selection'), E_WARNING));
			return;
		}

		$out[] = '<p>'.

			gTxt(
				'rah_post_versions_view_other_revisions',
				array(
					'{previous}' => ($old ? '<a href="?event='.$event.'&amp;step=diff&amp;item='.$item.'&amp;r='.$old['id'].'">r'.$old['id'].'</a>' : '-'),
					'{current}' => '<a href="?event='.$event.'&amp;step=diff&amp;item='.$item.'&amp;r='.$new['id'].'">r'.$new['id'].'</a>'
				),
				false
			). ' ' .

			gTxt(
				'rah_post_versions_revision_item_name',
				array(
					'{name}' => '<a href="?event='.$event.'&amp;step=changes&amp;item='.$new['setid'].'">'.txpspecialchars($new['title']).'</a>'
				),
				false
			) . ' ' .

			gTxt(
				'rah_post_versions_revision_committed_by',
				array(
					'{author}' => txpspecialchars(get_author_name($new['author'])),
					'{posted}' => safe_strftime(gTxt('rah_post_versions_date_format'), strtotime($new['posted'])),
				),
				false
			). ' ' .

			gTxt(
				'rah_post_versions_revision_from_panel',
				array(
					'{event}' => '<a href="?event='.$event.'&amp;filter_event='.txpspecialchars($new['event']).'">'.txpspecialchars($new['event']).'</a>',
					'{step}' => txpspecialchars($new['step'])
				),
				false
			). ' ' .

			'</p>';

		if ($old && $old['data'] === $new['data'])
		{
			$out[] = '<p class="warning alert-block">'.gTxt('rah_post_versions_revisions_match').'</p>';
		}

		if ($plain)
		{
			$out[] = '<h2>r'.$new['id'].'</h2>';

			foreach ($new['data'] as $key => $val)
			{
				if ($val !== '')
				{
					$out[] = 
						'<p>'.txpspecialchars($key).'</p>'.n.
						'<pre>'.txpspecialchars($val).'</pre>';
				}
			}

			if (!empty($old['data']))
			{
				$out[] = '<h2>r'.$old['id'].'</h2>';

				foreach ($old['data'] as $key => $val)
				{
					if ($val !== '')
					{
						$out[] = 
							'<p>'.txpspecialchars($key).'</p>'.n.
							'<pre>'.txpspecialchars($val).'</pre>';
					}
				}
			}
		}
		else
		{
			$diff = new rah_post_versions_diff();

			foreach ($new['data'] as $key => $val)
			{
				if (!isset($old['data'][$key]))
				{
					$old['data'][$key] = '';
				}

				if ($old['data'][$key] === $val)
				{
					unset($old['data'][$key]);
					continue;
				}

				$diff->old = $old['data'][$key];
				$diff->new = $val;

				$out[] = 
					'<p>'.txpspecialchars($key).'</p>'.n.
					'<pre>'.$diff->html().'</pre>';

				unset($old['data'][$key], $new['data'][$key]);
			}

			if (!empty($old['data']) && is_array($old['data']))
			{
				foreach($old['data'] as $key => $val)
				{
					$out[] = 
						'<p>'.txpspecialchars($key).'</p>'.n.
						'<pre>'.
							'<span class="error">'.
								txpspecialchars($val).
							'</span>'.
						'</pre>'.n;
				}
			}
		}

		$this->pane($out);
	}

	/**
	 * Reverts to revision.
	 */

	public function revert()
	{	
		extract(gpsa(array(
			'revert',
		)));

		$data = $this->get_revision("id='".doSlash($revert)."'");
		callback_event('rah_post_versions.revert', '', 0, $data);
		$this->diff();
	}

	/**
	 * Handles multi-edit methods.
	 */

	public function multi_edit()
	{	
		extract(psa(array(
			'selected',
			'edit_method',
		)));

		require_privs('rah_post_versions_'.((string) $edit_method));

		if (!is_string($edit_method) || empty($selected) || !is_array($selected))
		{
			$this->browser(array(gTxt('rah_post_versions_select_something'), E_WARNING));
			return;
		}

		$method = 'multi_option_' . $edit_method;

		if (!method_exists($this, $method))
		{
			$method = 'browse';
		}

		$this->$method();
	}

	/**
	 * Removes items and all their revisions.
	 */

	protected function multi_option_delete_item()
	{	
		$selected = ps('selected');
		$in = implode(',', quote_list($selected));

		if (
			safe_delete(
				'rah_post_versions',
				'setid in('.$in.')'
			) == false ||
			safe_delete(
				'rah_post_versions_sets',
				'id in('.$in.')'
			) == false
		)
		{
			$this->browser(array(gTxt('rah_post_versions_error_removing'), E_ERROR));
			return;
		}

		if ($this->static_dir)
		{
			$dir = preg_replace('/(\*|\?|\[)/', '[$1]', $this->static_dir);

			foreach ($selected as $id)
			{
				$id = (int) $id;

				foreach (glob($dir.DS.$id.'_r*.php') as $file)
				{
					unlink($file);
				}
			}
		}

		$this->browser(gTxt('rah_post_versions_removed'));
	}

	/**
	 * Removes individual revisions.
	 */

	protected function multi_option_delete_revision()
	{	
		$selected = ps('selected');
		$in = implode(',', quote_list($selected));
		$setid = (int) ps('item');

		if (
			safe_delete(
				'rah_post_versions',
				"id in(".$in.") and setid = '".doSlash($setid)."'"
			) == false
		)
		{
			$this->changes(array(gTxt('rah_post_versions_error_removing'), E_ERROR));
			return;
		}

		if ($this->static_dir)
		{
			foreach ($selected as $id)
			{
				$id = (int) $id;
				@unlink($this->static_dir.DS.$setid.'_r'.$id.'.php');
			}
		}

		$this->changes(gTxt('rah_post_versions_removed'));
	}

	/**
	 * Shows diffs.
	 */

	protected function multi_option_diff()
	{
		$selected = ps('selected');

		if (count($selected) !== 2)
		{
			$this->changes(array(gTxt('rah_post_versions_select_two_items'), E_ERROR));
			return;
		}

		$selected = doArray($selected, 'intval');
		sort($selected);
		$_GET['r'] = $selected[0] . '-' . end($selected);
		$this->diff();
	}

	/**
	 * Generates pagination.
	 *
	 * @param  string $step  The step
	 * @param  int    $total Total items  
	 * @param  int    $limit The limit
	 * @return string HTML
	 */

	protected function pages($step, $total, $limit)
	{	
		global $event;

		list($page, $offset, $num_pages) = pager($total, $limit, gps('page'));

		if ($num_pages <= 1)
		{
			return;
		}

		$start = max(1, $page-5);
		$end = min($num_pages, $start+10);

		if ($page > 1 && $num_pages > 1)
		{
			$out[] = '<a class="navlink" href="?event='.$event.a.'step='.$step.a.'item='.urlencode(gps('item')).a.'page='.($page-1).'">'.gTxt('prev').'</a>';
		}
		else
		{
			$out[] = '<span class="navlink-disabled">'.gTxt('prev').'</span>';
		}

		for ($pg = $start; $pg <= $end; $pg++)
		{	
			if ($pg == $page)
			{
				$class = 'navlink-active';
			}
			else
			{
				$class = 'navlink';
			}

			$out[] = '<a class="'.$class.'" href="?event='.$event.a.'step='.$step.a.'item='.urlencode(gps('item')).a.'page='.$pg.'">'.$pg.'</a>';
		}

		if ($page < $num_pages && $num_pages > 1)
		{
			$out[] = '<a class="navlink" href="?event='.$event.a.'step='.$step.a.'item='.urlencode(gps('item')).a.'page='.($page+1).'">'.gTxt('next').'</a>';
		}
		else
		{
			$out[] = '<span class="navlink-disabled">'.gTxt('next').'</span>';
		}

		return '<p class="nav-tertiary">'.implode('', $out).'</p>';
	}
}
<?php

/**
 * Tracks changes and creates revisions
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

new rah_post_versions_track();

/**
 * Tracks changes.
 */

class rah_post_versions_track
{
	/**
	 * The form data.
	 *
	 * @var array
	 */

	private $form = array();

	/**
	 * Constructor.
	 */

	public function __construct()
	{
		if (!empty($_POST))
		{
			$this->form = psa(array_keys($_POST));
			unset($this->form['_txp_token']);
		}

		register_callback(array($this, 'push_article'), 'article_saved');
		register_callback(array($this, 'push_article'), 'article_posted');
		register_callback(array($this, 'push_named'), 'page');
		register_callback(array($this, 'push_named'), 'form');
		register_callback(array($this, 'push_named'), 'css');
		register_callback(array($this, 'push_named'), 'section');
		register_callback(array($this, 'push_link'), 'link');
		register_callback(array($this, 'push_multi_edit'), 'list');
	}

	/**
	 * Tracks articles.
	 *
	 * @param string $event
	 * @param string $step
	 * @param array  $r
	 */

	public function push_article($e, $s, $r)
	{
		global $txp_user, $event, $step;

		rah_post_versions::get()->create_revision(
			(string) $r['ID'], (string) $r['Title'], $txp_user, $event, $step, $this->form
		);
	}

	/**
	 * Track posts.
	 */

	public function push_named()
	{
		global $txp_user, $event, $step;

		if (!$this->form || !ps('name'))
		{
			return;
		}

		rah_post_versions::get()->create_revision(
			(string) ps('name'), (string) ps('name'), $txp_user, $event, $step, $this->form
		);
	}

	/**
	 * Tracks links.
	 */

	public function push_link()
	{
		global $txp_user, $event, $step, $ID;

		if (!$this->form || !ps('id'))
		{
			return;
		}

		rah_post_versions::get()->create_revision(
			(string) ps('id'), (string) ps('linkname'), $txp_user, $event, $step, $this->form
		);
	}

	/**
	 * Multi-edit tracking.
	 */

	public function push_multi_edit()
	{
		global $txp_user, $event, $step, $ID;

		if (!$this->form || !ps('edit_method') || !ps('selected') || !is_array(ps('selected')))
		{
			return;
		}

		foreach (ps('selected') as $id)
		{
			rah_post_versions::get()->create_revision(
				(string) $id, '', $txp_user, $event, ps('edit_method'), $this->form
			);
		}
	}
}
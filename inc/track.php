<?php

/**
 * Tracks changes and creates revisions
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

new rah_post_versions_track();

class rah_post_versions_track {
	
	private $form = array();

	/**
	 * Constructor
	 */

	public function __construct() {

		if(!empty($_POST)) {
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
	}
	
	/**
	 * Articles
	 */
	
	public function push_article($e, $s, $r) {
		global $txp_user, $event, $step;
	
		rah_post_versions::get()->create_revision(
			$r['ID'], $r['Title'], $txp_user, $event, $step, $this->form
		);
	}
	
	/**
	 * Track posts
	 */
	
	public function push_named() {
		global $txp_user, $event, $step;
		
		if(!$this->form || !ps('name')) {
			return;
		}
		
		rah_post_versions::get()->create_revision(
			ps('name'), ps('name'), $txp_user, $event, $step, $this->form
		);
	}
	
	/**
	 * Tracks link saving
	 */
	
	public function push_link() {
	
		global $txp_user, $event, $step, $ID;
		
		if(!$this->form || !ps('id')) {
			return;
		}
		
		rah_post_versions::get()->create_revision(
			ps('id'), ps('linkname'), $txp_user, $event, $step, $this->form
		);
	}
}

?>
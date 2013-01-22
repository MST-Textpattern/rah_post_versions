<?php

/**
 * Generates HTML inline diffs, a comparison tool.
 *
 * Uses Paul Butler's Simple Diff Algorithm.
 *
 * @package rah_post_versions
 * @author  Jukka Svahn
 * @date    2010-
 * @license GNU GPLv2
 * @link    http://rahforum.biz/plugins/rah_post_versions
 *
 * Copyright (C) 2012 Jukka Svahn http://rahforum.biz
 * Licensed under GNU Genral Public License version 2
 * http://www.gnu.org/licenses/gpl-2.0.html
 *
 * The diff generator uses Paul Butler's Simple Diff Algorithm.
 *
 * @author  Paul Butler
 * @license zlib/libpng
 * @link    http://www.paulbutler.org
 * @link    https://github.com/paulgb/simplediff
 */

class rah_post_versions_diff
{
	/**
	 * Old value.
	 *
	 * @var string
	 */

	public $old;

	/**
	 * New value.
	 *
	 * @var string
	 */

	public $new;

	/**
	 * Line delimiter.
	 *
	 * @var string
	 */

	protected $delimiter = n;

	/**
	 * Clean line breaks.
	 *
	 * @param  string|array $string
	 * @return array
	 */

	protected function lines($string)
	{
		if (is_array($string))
		{
			$string = json_encode($string);
		}

		return 	explode($this->delimiter,
			str_replace(array("\r\n","\r"), n, htmlspecialchars($string))
		);
	}

	/**
	 * Returns HTML presentation of the diff.
	 *
	 * @return string HTML markup.
	 */

	public function html()
	{	
		$this->old = $this->lines($this->old);
		$this->new = $this->lines($this->new);

		foreach ($this->diff($this->old, $this->new) as $key => $line)
		{
			if (is_array($line))
			{
				if (
					!empty($line['d']) &&
					($d = implode($this->delimiter,$line['d'])) !== ''
				)
				{
					$out[] = '<span class="error">'.$d.'</span>';
				}

				if (!empty($line['i']))
				{
					$out[] = 
						'<span class="success">'.
							implode($this->delimiter, $line['i']).
						'</span>';
				}
			}
			else
			{
				$out[] = $line;
			}
		}

		return implode($this->delimiter, $out);
	}

	/**
	 * Compares lines/words and retuns differences marked.
	 *
	 * @param  array $old Contents of old revision.
	 * @param  array $new Contents of new revision.
	 * @return array
	 */

	public function diff($old, $new)
	{
		/*
			This (rah_post_versions_diff::diff()) methods's contents are based on:
			
			Paul's Simple Diff Algorithm v 0.1
			(C) Paul Butler 2007 <http://www.paulbutler.org/>
			May be used and distributed under the zlib/libpng license.

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

		foreach ($old as $oindex => $ovalue)
		{
			$nkeys = array_keys($new, $ovalue);

			foreach ($nkeys as $nindex)
			{
				$matrix[$oindex][$nindex] = 
					isset($matrix[$oindex - 1][$nindex - 1]) ? 
						$matrix[$oindex - 1][$nindex - 1] + 1 : 1;

				if ($matrix[$oindex][$nindex] > $maxlen)
				{
					$maxlen = $matrix[$oindex][$nindex];
					$omax = $oindex + 1 - $maxlen;
					$nmax = $nindex + 1 - $maxlen;
				}
			}
		}

		if ($maxlen == 0)
		{
			return array(array('d' => $old, 'i' => $new));
		}

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

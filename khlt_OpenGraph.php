<?php
/*
  Copyright 2010 Scott MacVicar

   Licensed under the Apache License, Version 2.0 (the "License");
   you may not use this file except in compliance with the License.
   You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

   Unless required by applicable law or agreed to in writing, software
   distributed under the License is distributed on an "as IS" BASIS,
   WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
   See the License for the specific language governing permissions and
   limitations under the License.
   
	Original can be found at https://github.com/scottmac/opengraph/blob/master/OpenGraph.php
   
	modified:
	= wp_remote_get instead of curl for wordpress.
	= class name changed.
	= fix for multibyte characters.
*/

class khlt_OpenGraph implements Iterator
{
  /**
   * There are base schema's based on type, this is just
   * a map so that the schema can be obtained
   *
   */
	public static $TYPES = array(
		'activity' => array('activity', 'sport'),
		'business' => array('bar', 'company', 'cafe', 'hotel', 'restaurant'),
		'group' => array('cause', 'sports_league', 'sports_team'),
		'organization' => array('band', 'government', 'non_profit', 'school', 'university'),
		'person' => array('actor', 'athlete', 'author', 'director', 'musician', 'politician', 'public_figure'),
		'place' => array('city', 'country', 'landmark', 'state_province'),
		'product' => array('album', 'book', 'drink', 'food', 'game', 'movie', 'product', 'song', 'tv_show'),
		'website' => array('blog', 'website'),
	);

  /**
   * Holds all the Open Graph values we've parsed from a page
   *
   */
	private $_values = array();

  /**
   * Fetches a URI and parses it for Open Graph data, returns
   * false on error.
   *
   * @param $URI    URI to page to parse for Open Graph data
   * @return khlt_OpenGraph
   */
	static public function fetch($URI) {
		$response = wp_remote_retrieve_body( wp_remote_get($URI) );

        if (!empty($response)) {
            return self::_parse($response);
        } else {
            return false;
        }
	}

  /**
   * Parses HTML and extracts Open Graph data, this assumes
   * the document is at least well formed.
   *
   * @param $HTML    HTML to parse
   * @return khlt_OpenGraph
   */
	static private function _parse($HTML) {
		$old_libxml_error = libxml_use_internal_errors(true);

		$encoding = mb_detect_encoding($HTML, "UTF-8, UTF-7, ASCII, EUC-JP, SJIS, eucJP-win, SJIS-win, JIS, ISO-2022-JP");
		$doc = new DOMDocument();
		$ret = $doc->loadHTML(mb_convert_encoding($HTML, 'HTML-ENTITIES', $encoding));

/* debug
		echo "<textarea>" . PHP_EOL;
		echo "detected encoding: $encoding" . PHP_EOL;
		if (!$ret) {
			foreach (libxml_get_errors() as $error) {
				var_dump($error);
			}
			libxml_clear_errors();
		}
		$saved = $doc->saveHTML();
		$saved_encoding = mb_detect_encoding($saved, "UTF-8, UTF-7, ASCII, EUC-JP, SJIS, eucJP-win, SJIS-win, JIS, ISO-2022-JP");
		echo "detected encoding of saved: $saved_encoding" . PHP_EOL;
		$saved = preg_replace_callback("/(&#[0-9]+;)/", function($m) { return mb_convert_encoding($m[1], 'UTF-8', 'HTML-ENTITIES'); }, $saved); // for HTML comment
		echo $saved . PHP_EOL;
		echo "</textarea>" . PHP_EOL;
*/

		libxml_use_internal_errors($old_libxml_error);

		$tags = $doc->getElementsByTagName('meta');
		if (!$tags || $tags->length === 0) {
			return false;
		}

		$page = new self();

		$nonOgDescription = null;
		
		foreach ($tags as $tag) {
			if ($tag->hasAttribute('property') &&
			    strpos($tag->getAttribute('property'), 'og:') === 0) {
				$key = strtr(mb_substr($tag->getAttribute('property'), 3), '-', '_');
				$page->_values[$key] = $tag->getAttribute('content');
			}
			
			//Added this if loop to retrieve description values from sites like the New York Times who have malformed it. 
			if ($tag ->hasAttribute('value') && $tag->hasAttribute('property') &&
			    strpos($tag->getAttribute('property'), 'og:') === 0) {
				$key = strtr(mb_substr($tag->getAttribute('property'), 3), '-', '_');
				$page->_values[$key] = $tag->getAttribute('value');
			}
			//Based on modifications at https://github.com/bashofmann/opengraph/blob/master/src/OpenGraph/OpenGraph.php
			if ($tag->hasAttribute('name') && $tag->getAttribute('name') === 'description') {
                $nonOgDescription = $tag->getAttribute('content');
            }
			
		}
		//Based on modifications at https://github.com/bashofmann/opengraph/blob/master/src/OpenGraph/OpenGraph.php
		if (!isset($page->_values['title'])) {
            $titles = $doc->getElementsByTagName('title');
            if ($titles->length > 0) {
                $page->_values['title'] = $titles->item(0)->textContent;
            }
        }
        if (!isset($page->_values['description']) && $nonOgDescription) {
            $page->_values['description'] = $nonOgDescription;
        }

        //Fallback to use image_src if ogp::image isn't set.
        if (!isset($page->values['image'])) {
            $domxpath = new DOMXPath($doc);
            $elements = $domxpath->query("//link[@rel='image_src']");

            if ($elements->length > 0) {
                $domattr = $elements->item(0)->attributes->getNamedItem('href');
                if ($domattr) {
                    $page->_values['image'] = $domattr->value;
                    $page->_values['image_src'] = $domattr->value;
                }
            }
        }

		if (empty($page->_values)) { return false; }
		
		return $page;
	}

  /**
   * Helper method to access attributes directly
   * Example:
   * $graph->title
   *
   * @param $key    Key to fetch from the lookup
   */
	public function __get($key) {
		if (array_key_exists($key, $this->_values)) {
			return $this->_values[$key];
		}
		
		if ($key === 'schema') {
			foreach (self::$TYPES as $schema => $types) {
				if (array_search($this->_values['type'], $types)) {
					return $schema;
				}
			}
		}
	}

  /**
   * Return all the keys found on the page
   *
   * @return array
   */
	public function keys() {
		return array_keys($this->_values);
	}

  /**
   * Helper method to check an attribute exists
   *
   * @param $key
   */
	public function __isset($key) {
		return array_key_exists($key, $this->_values);
	}

  /**
   * Will return true if the page has location data embedded
   *
   * @return boolean Check if the page has location data
   */
	public function hasLocation() {
		if (array_key_exists('latitude', $this->_values) && array_key_exists('longitude', $this->_values)) {
			return true;
		}
		
		$address_keys = array('street_address', 'locality', 'region', 'postal_code', 'country_name');
		$valid_address = true;
		foreach ($address_keys as $key) {
			$valid_address = ($valid_address && array_key_exists($key, $this->_values));
		}
		return $valid_address;
	}

  /**
   * Iterator code
   */
	private $_position = 0;
	public function rewind() { reset($this->_values); $this->_position = 0; }
	public function current() { return current($this->_values); }
	public function key() { return key($this->_values); }
	public function next() { next($this->_values); ++$this->_position; }
	public function valid() { return $this->_position < sizeof($this->_values); }
}

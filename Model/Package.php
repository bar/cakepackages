<?php
App::uses('Sanitize', 'Utility');
App::uses('Characterizer', 'Lib');
App::uses('HttpSocket', 'Network/Http');
App::uses('Xml', 'Utility');

class Package extends AppModel {

	public $name = 'Package';

	public $belongsTo = array('Maintainer');

	public $actsAs = array(
		'Softdeletable',
	);

	public $validTypes = array(
		'model', 'controller', 'view',
		'behavior', 'component', 'helper',
		'shell', 'theme', 'datasource',
		'lib', 'test', 'vendor',
		'app', 'config', 'resource',
	);

	public $folder = null;

	public $Github = null;

	public $HttpSocket = null;

	public $SearchIndex = null;

	public $findMethods = array(
		'autocomplete'      => true,
		'download'          => true,
		'edit'              => true,
		'index'             => true,
		'latest'            => true,
		'listformaintainer' => true,
		'random'            => true,
		'randomids'         => true,
		'repoclone'         => true,
		'view'              => true,
	);

	public function __construct($id = false, $table = null, $ds = null) {
		parent::__construct($id, $table, $ds);
		$this->order = "`{$this->alias}`.`last_pushed_at` asc";
		$this->validate = array(
			'maintainer_id' => array(
				'numeric' => array(
					'rule' => array('numeric'),
					'message' => __('must contain only numbers'),
				),
			),
			'name' => array(
				'notempty' => array(
					'rule' => array('notempty'),
					'message' => __('cannot be left empty'),
				),
			),
		);
		$this->tabs = array(
			'ratings'    => array('text' => __('Rating'),       'sort' => 'rating'),
			'watchers'   => array('text' => __('Watchers'),     'sort' => 'watchers', 'direction' => 'desc'),
			'title'      => array('text' => __('Title'),        'sort' => 'name'),
			'maintainer' => array('text' => __('Maintainer'),   'sort' => 'Maintainer.name'),
			'date'       => array('text' => __('Date Created'), 'sort' => 'created_at'),
			'updated'    => array('text' => __('Date Updated'), 'sort' => 'last_pushed_at'),
		);
	}

	public function _findAutocomplete($state, $query, $results = array()) {
		if ($state == 'before') {
			if (empty($query['term'])) {
				throw new InvalidArgumentException(__('Invalid query'));
			}

			$query['term'] = Sanitize::clean($query['term']);
			$query['conditions'] = array("{$this->alias}.{$this->displayField} LIKE" => "%{$query['term']}%");
			$query['contain'] = array('Maintainer' => array('username'));
			$query['fields'] = array($this->primaryKey, $this->displayField);
			$query['limit'] = 10;
			return $query;
		} elseif ($state == 'after') {
			$searchResults = array();
			foreach ($results as $package) {
				$searchResults[] = array(
					'id' => $package['Package']['id'],
					'slug' => sprintf("%s/%s", $package['Maintainer']['username'], $package['Package']['name']),
					'value' => $package['Package']['name'],
					"label" => preg_replace("/".$query['term']."/i", "<strong>$0</strong>", $package['Package']['name'])
				);
			}
			return json_encode($searchResults);
		}
	}

	public function _findDownload($state, $query, $results = array()) {
		if ($state == 'before') {
			$query['conditions'] = array(
				"{$this->alias}.{$this->primaryKey}" => $query[$this->primaryKey],
			);
			$query['contain'] = array('Maintainer' => array('username'));
			$query['fields'] = array('id', 'name');
			$query['limit'] = 1;
			return $query;
		} elseif ($state == 'after') {
			if (empty($results[0])) {
				return false;
			}
			return sprintf(
				'https://github.com/%s/%s/zipball/%s',
				$results[0]['Maintainer']['username'],
				$results[0]['Package']['name'],
				$query['branch']
			);
		}
	}

	public function _findIndex($state, $query, $results = array()) {
		if ($state == 'before') {
			$query['named'] = array_merge(array(
				'query' => null,
				'since' => null,
				'watchers' => null,
				'with' => null,
			), $query['named']);

			$query['conditions'] = array("{$this->alias}.deleted" => false);
			$query['contain'] = array('Maintainer' => array('id','username', 'name'));
			$query['fields'] = array_diff(
				array_keys($this->schema()),
				array('deleted', 'modified', 'repository_url', 'homepage', 'tags', 'bakery_article')
			);

			$query['order'][] = array("{$this->alias}.created DESC");

			if ($query['named']['query']) {
				$query['conditions'][]['OR'] = array(
					"{$this->alias}.name LIKE" => '%' . $query['named']['query'] . '%',
					"{$this->alias}.description LIKE" => '%' . $query['named']['query'] . '%',
					"Maintainer.username LIKE" => '%' . $query['named']['query'] . '%',
				);
			}

			if ($query['named']['since']) {
				$time = date('Y-m-d H:i:s', strtotime($query['named']['since']));
				$query['conditions']["{$this->alias}.last_pushed_at >"] = $time;
			}

			if ($query['named']['watchers']) {
				$query['conditions']["{$this->alias}.watchers >"] = $query['named']['watchers'];
			}

			if ($query['named']['with']) {
				$query['named']['with'] = Inflector::singularize($query['named']['with']);
			}

			if (in_array($query['named']['with'], $this->validTypes)) {
				$query['conditions']["{$this->alias}.contains_{$query['named']['with']}"] = true;
			}

			if (!empty($query['operation'])) {
				return $this->_findCount($state, $query, $results);
			}
			return $query;
		} elseif ($state == 'after') {
			if (!empty($query['operation'])) {
				return $this->_findCount($state, $query, $results);
			}
			return $results;
		}
	}

	public function _findLatest($state, $query, $results = array()) {
		if ($state == 'before') {
			$query['contain'] = array('Maintainer' => array('id', 'username', 'name'));
			$query['fields'] = array_diff(
				array_keys($this->schema()),
				array('deleted', 'modified', 'repository_url', 'homepage', 'tags', 'bakery_article')
			);
			$query['limit'] = (empty($query['limit'])) ? 6 : $query['limit'];
			$query['order'] = array("{$this->alias}.{$this->primaryKey} DESC");
			if (!empty($query['operation'])) {
				return $this->_findCount($state, $query, $results);
			}
			return $query;
		} elseif ($state == 'after') {
			if (!empty($query['operation'])) {
				return $this->_findCount($state, $query, $results);
			}
			return $results;
		}
	}

	public function _findListformaintainer($state, $query, $results = array()) {
		if ($state == 'before') {
			if (empty($query[0])) {
				throw new InvalidArgumentException(__('Invalid package'));
			}

			$query['conditions'] = array("{$this->alias}.maintainer_id" => $query[0]);
			$query['fields'] = array("{$this->alias}.{$this->primaryKey}", "{$this->alias}.{$this->displayField}");
			$query['order'] = array("{$this->alias}.{$this->displayField} DESC");
			$query['recursive'] = -1;
			return $query;
		} elseif ($state == 'after') {
			if (empty($results)) {
				return array();
			}
			return Set::combine(
				$results,
				"{n}.{$this->alias}.{$this->primaryKey}",
				"{n}.{$this->alias}.{$this->displayField}"
			);
		}
	}

	public function _findRepoclone($state, $query, $results = array()) {
		if ($state == 'before') {
			if (empty($query[0])) {
				throw new InvalidArgumentException(__('Invalid package'));
			}

			$query['conditions'] = array("{$this->alias}.{$this->primaryKey}" => $query[0]);
			$query['contain'] = array('Maintainer.username');
			$query['fields'] = array('id', 'name', 'repository_url');
			$query['limit'] = 1;
			$query['order'] = array("{$this->alias}.{$this->primaryKey} ASC");
			return $query;
		} elseif ($state == 'after') {
			if (empty($results[0])) {
				throw new OutOfBoundsException(__('Invalid package'));
			}
			return $results[0];
		}
	}

	public function _findView($state, $query, $results = array()) {
		if ($state == 'before') {
			if (empty($query['maintainer']) || empty($query['package'])) {
				throw new InvalidArgumentException(__('Invalid package'));
			}

			$query['conditions'] = array(
				"{$this->alias}.{$this->displayField}" => $query['package'],
				'Maintainer.username' => $query['maintainer'],
			);
			$query['contain'] = array('Maintainer' => array($this->displayField, 'username'));
			$query['limit'] = 1;
			return $query;
		} elseif ($state == 'after') {
			if (empty($results[0])) {
				throw new OutOfBoundsException(__('Invalid package'));
			}
			return $results[0];
		}
	}

	public function setupRepository($id = null) {
		if (!$id) {
			return false;
		}

		$package = $this->find('repoclone', $id);
		if (!$package) {
			return false;
		}

		if (!$this->folder) {
			$this->folder = new Folder();
		}

		$path = rtrim(trim(TMP), DS);
		$appends = array(
			'repos',
			strtolower($package['Maintainer']['username'][0]),
			$package['Maintainer']['username'],
		);

		foreach ($appends as $append) {
			$this->folder->cd($path);
			$read = $this->folder->read();

			if (!in_array($append, $read['0'])) {
				$this->folder->create($path . DS . $append);
			}
			$path = $path . DS . $append;
		}

		$this->folder->cd($path);
		$read = $this->folder->read();

		if (!in_array($package['Package']['name'], $read['0'])) {
			if (($paths = Configure::read('paths')) !== false) {
				putenv('PATH=' . implode(':', $paths) . ':' . getenv('PATH'));
			}
			$var = shell_exec(sprintf("cd %s && git clone %s %s%s%s 2>&1 1> /dev/null",
				$path,
				$package['Package']['repository_url'],
				$path,
				DS,
				$package['Package']['name']
			));

			if (stristr($var, 'fatal')) {
				$this->log($var);
				return false;
			}
		}

		$var = shell_exec(sprintf("cd %s && git pull",
			$path . DS . $package['Package']['name']
		));
		if (stristr($var, 'fatal')) {
			$this->log($var);
			return false;
		}

		return array($package['Package']['id'], $path . DS . $package['Package']['name']);
	}

	public function characterize($id) {
		$this->Behaviors->detach('Softdeletable');
		list($package_id, $path) = $this->setupRepository($id);
		if (!$package_id || !$path) {
			return !$this->broken($id);
		}

		$characterizer = new Characterizer($path);
		$data = $characterizer->classify();
		$this->create(false);
		return $this->save(array('Package' => array_merge(
			$data, array('id' => $package_id, 'deleted' => false)
		)));
	}

	public function broken($id) {
		$this->id = $id;
		return $this->saveField('deleted', true);
	}

	public function updateAttributes($package) {
		if (!$this->Github) {
			$this->Github = ClassRegistry::init('Github');
		}

		$repo = $this->Github->find('reposShowSingle', array(
			'username' => $package['Maintainer']['username'],
			'repo' => $package['Package']['name']
		));
		if (empty($repo) || !isset($repo['Repository'])) return false;

		// Detect homepage
		$homepage = (string) $repo['Repository']['url'];
		if (!empty($repo['Repository']['homepage'])) {
			if (is_array($repo['Repository']['homepage'])) {
				$homepage = $repo['Repository']['homepage'];
			} else {
				$homepage = $repo['Repository']['homepage'];
			}
		} else if (!empty($repo['Repsitory']['homepage'])) {
			$homepage = $repo['Repository']['homepage'];
		}

		// Detect issues
		$issues = null;
		if ($repo['Repository']['has_issues']) {
			$issues = $repo['Repository']['open_issues'];
		}

		// Detect total contributors
		$contribs = 1;
		$contributors = $this->Github->find('reposShowContributors', array(
			'username' => $package['Maintainer']['username'], 'repo' => $package['Package']['name']
		));
		if (!empty($contributors)) {
			$contribs = count($contributors);
		}

		$collabs = 1;
		$collaborators = $this->Github->find('reposShowCollaborators', array(
			'username' => $package['Maintainer']['username'], 'repo' => $package['Package']['name']
		));

		if (!empty($collaborators)) {
			$collabs = count($collaborators);
		}

		if (isset($repo['Repository']['description'])) {
			$package['Package']['description'] = $repo['Repository']['description'];
		}

		if (!empty($homepage)) {
			$package['Package']['homepage'] = $homepage;
		}
		if ($collabs !== null) {
			$package['Package']['collaborators'] = $collabs;
		}
		if ($contribs !== null) {
			$package['Package']['contributors'] = $contribs;
		}
		if ($issues !== null) {
			$package['Package']['open_issues'] = $issues;
		}

		$package['Package']['forks'] = $repo['Repository']['forks'];
		$package['Package']['watchers'] = $repo['Repository']['watchers'];
		$package['Package']['created_at'] = substr(str_replace('T', ' ', $repo['Repository']['created_at']), 0, 20);
		$package['Package']['last_pushed_at'] = substr(str_replace('T', ' ', $repo['Repository']['pushed_at']), 0, 20);

		$this->create();
		return $this->save($package);
	}

	public function fixRepositoryUrl($package = null) {
		if (!$package) return false;

		if (!is_array($package)) {
			$package = $this->find('first', array(
				'conditions' => array("{$this->alias}.{$this->primaryKey}" => $package),
				'contain' => array('Maintainer' => array('fields' => 'username')),
				'fields' => array('name', 'repository_url')
			));
		}
		if (!$package) return false;

		$package[$this->alias]['repository_url']	= array();
		$package[$this->alias]['repository_url'][]	  = "git://github.com";
		$package[$this->alias]['repository_url'][]	  = $package['Maintainer']['username'];
		$package[$this->alias]['repository_url'][]	  = $package[$this->alias]['name'];
		$package[$this->alias]['repository_url']	= implode("/", $package[$this->alias]['repository_url']);
		$package[$this->alias]['repository_url']   .= '.git';
		return $this->save($package);
	}

	public function findOnGithub($package = null) {
		if (!is_array($package)) {
			$package = $this->find('first', array(
				'conditions' => array("{$this->alias}.{$this->primaryKey}" => $package),
				'contain' => array('Maintainer' => array('fields' => 'username')),
				'fields' => array('name', 'repository_url')
			));
		}

		if (!$package) {
			return false;
		}

		if (!$this->Github) {
			$this->Github = ClassRegistry::init('Github');
		}

		$response = $this->Github->find('reposShowSingle', array(
			'username' => $package['Maintainer']['username'],
			'repo' => $package[$this->alias]['name']
		));

		return !empty($response['Repository']);
	}

	public function cleanParams($named, $options = array()) {
		if (empty($named)) {
			return array();
		}
		if (is_bool($options)) {
			$options = array('rinse' => $options);
		}

		$options = array_merge(array(
			'rinse' => true,
			'allowed' => array(),
		), $options);

		if ($options['rinse']) {
			$search = '+';
			$replace = ' ';
		} else {
			$search = ' ';
			$replace = '+';
		}

		if (!empty($options['allowed'])) {
			$named = array_intersect_key($named, array_combine($options['allowed'], $options['allowed']));
		}

		foreach ($named as $key => $value) {
			if (is_array($value)) {
				$values = array();
				foreach ($value as $v) {
					$values[] = str_replace($search, $replace, Sanitize::clean($v));
				}
				$named[$key] = $values;
			} else {
				$named[$key] = str_replace($search, $replace, Sanitize::clean($value));
			}
		}
		return $named;
	}

	public function suggest($data) {
		if (empty($data['username']) || empty($data['repository'])) {
			return false;
		}

		$job = $this->load('SuggestPackageJob', $data['username'], $data['repository']);
		if (!$job) {
			return false;
		}

		return $this->enqueue($job);
	}

	public function seoView($package) {
		$title = array();
		$title[] = Sanitize::clean($package['Package']['name'] . ' by ' . $package['Maintainer']['username']);
		$title[] = 'CakePHP Plugins and Applications';
		$title[] = 'CakePackages';
		$title = implode(' | ', $title);

		$description = Sanitize::clean($package['Package']['description']) . ' - CakePHP Package on CakePackages';

		$keywords = explode(' ', $package['Package']['name']);
		if (count($keywords) > 1) {
			$keywords[] = $package['Package']['name'];
		}
		$keywords[] = 'cakephp package';
		$keywords[] = 'cakephp';

		foreach ($this->validTypes as $type) {
			if (isset($package['Package']['contains_' . $type]) && $package['Package']['contains_' . $type] == 1) {
				$keywords[] = $type;
			}
		}
		$keywords = implode(', ', $keywords);

		return array($title, $description, $keywords);
	}

	public function rss($package, $options = array()) {
		$options = array_merge(array(
			'cache' => null,
			'limit' => 4,
			'key' => null,
			'uri' => null,
		), $options);

		if (!is_array($options['cache'])) {
			$options['cache'] = array(
				'key' => 'package.rss.' . md5($package['Maintainer']['username'] . $package['Package']['name']),
				'time' => '+6 hours',
			);
		}

		if (!$options['uri']) {
			$options['uri'] = sprintf("https://github.com/%s/%s/commits/master.atom",
				$package['Maintainer']['username'],
				$package['Package']['name']
			);
		}

		if (!$options['key']) {
			$options['key'] = MiCache::key(md5($options['uri']));
		}

		$items = array();
		if (($items = MiCache::read($options['key'])) !== false) {
			return array($items, $options['cache']);
		}

		if (!$this->_HttpSocket()) {
			return array($items, $options['cache']);
		}

		$result = $this->HttpSocket->request(array('uri' => $options['uri']));
		$code = $this->HttpSocket->response['status']['code'];
		$isError = is_array($result) && isset($result['Html']);

		if ($code != 404  && $result && !$isError) {
			$xmlError = libxml_use_internal_errors(true);
			$result = simplexml_load_string($result['body']);
			libxml_use_internal_errors($xmlError);
		}

		if ($result) {
			$result = Xml::toArray($result);
		}

		if (!empty($result['feed']['entry'])) {
			$result = array($result['feed']['entry']);
			if (!empty($result[0])) {
				$result = array_slice($result[0], 0, $options['limit'], true);
			}

			foreach ($result as $item) {
				if (!empty($item['id'])) {
					$item['hash'] = explode("Commit/", $item['id']);
					$item['hash'] = end($item['hash']);
				} else {
					$item['hash'] = '';
				}

				if (!empty($item['title'])) {
					$item['title'] = Sanitize::clean($item['title']);
				} else {
					$item['title'] = 'Empty Commit Message';
				}

				if (!empty($item['link']['@href'])) {
					$item['link'] = $item['link']['@href'];
				} else {
					$item['link'] = '';
				}

				if (!empty($item['content']['@'])) {
					$item['content'] = $item['content']['@'];
				} else {
					$item['content'] = '';
				}

				if (!empty($item['media:thumbnail']['@url'])) {
					$item['avatar'] = $item['media:thumbnail']['@url'];
					unset($item['media:thumbnail']);
				} else {
					$item['avatar'] = '';
				}

				$items[] = array('Entry' => $item);
			}
		}

		MiCache::write($options['key'], $items);
		return array($items, $options['cache']);
	}

	public function disqus($package = array()) {
		return array(
			'disqus_shortname' => Configure::read('Disqus.disqus_shortname'),
			'disqus_identifier' => $package[$this->alias][$this->primaryKey],
			'disqus_title' => Sanitize::clean(implode(' ', array(
				$package['Package']['name'],
				'by',
				$package['Maintainer']['username'],
			))),
			'disqus_url' => Router::url(array(
				'controller' => 'packages',
				'action' => 'view',
				$package['Maintainer']['username'],
				$package['Package']['name']
			), true),
		);
	}

	protected function _HttpSocket() {
		if ($this->HttpSocket) {
			return $this->HttpSocket;
		}

		return $this->HttpSocket = new HttpSocket();
	}

}
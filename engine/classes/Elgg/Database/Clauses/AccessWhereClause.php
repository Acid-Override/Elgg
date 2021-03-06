<?php

namespace Elgg\Database\Clauses;

use Elgg\Database\QueryBuilder;

/**
 * Builds queries to restrict access
 */
class AccessWhereClause extends WhereClause {

	/**
	 * @var string
	 */
	public $access_column = 'access_id';
	/**
	 * @var string
	 */
	public $owner_guid_column = 'owner_guid';

	/**
	 * @var string
	 */
	public $guid_column = 'guid';

	/**
	 * @var string
	 */
	public $enabled_column = 'enabled';

	/**
	 * @var bool
	 */
	public $ignore_access;

	/**
	 * @var bool
	 */
	public $use_enabled_clause;

	/**
	 * @var int
	 */
	public $viewer_guid;

	/**
	 * {@inheritdoc}
	 */
	public function prepare(QueryBuilder $qb, $table_alias = null) {

		$alias = function ($column) use ($table_alias) {
			return $table_alias ? "{$table_alias}.{$column}" : $column;
		};

		if (!isset($this->viewer_guid)) {
			$this->viewer_guid = elgg_get_logged_in_user_guid();
		}

		if (!isset($this->ignore_access)) {
			$this->ignore_access = _elgg_services()->userCapabilities->canBypassPermissionsCheck($this->viewer_guid);
		}

		if (!isset($this->use_enabled_clause)) {
			$this->use_enabled_clause = !access_get_show_hidden_status();
		}

		$ors = [];
		$ands = [];

		$ands[] = parent::prepare($qb, $table_alias);

		if (!$this->ignore_access) {
			if ($this->viewer_guid) {
				// include content of user's friends
				$ors['friends_access'] = $qb->merge([
					$qb->compare($alias($this->access_column), '=', ACCESS_FRIENDS, ELGG_VALUE_INTEGER),
					$qb->compare(
						$alias($this->owner_guid_column),
						'in',
						$qb->subquery('entity_relationships')
							->select('guid_one')
							->where($qb->compare('relationship', '=', 'friend', ELGG_VALUE_STRING))
							->andWhere($qb->compare('guid_two', '=', $this->viewer_guid, ELGG_VALUE_INTEGER))
							->getSQL()
					)
				]);

				// include user's content
				$ors['owner_access'] = $qb->compare($alias($this->owner_guid_column), '=', $this->viewer_guid, ELGG_VALUE_INTEGER);
			}

			// include standard accesses (public, logged in, access collections)
			$access_list = _elgg_services()->accessCollections->getAccessArray($this->viewer_guid);
			$ors['acl_access'] = $qb->compare($alias($this->access_column), '=', $access_list, ELGG_VALUE_INTEGER);
		}

		if ($this->use_enabled_clause) {
			$ands[] = $qb->compare($alias($this->enabled_column), '=', 'yes', ELGG_VALUE_STRING);
		}

		$hook_params = [
			'table_alias' => $alias,
			'user_guid' => $this->viewer_guid,
			'ignore_access' => $this->ignore_access,
			'use_enabled_clause' => $this->use_enabled_clause,
			'access_column' => $this->access_column,
			'owner_guid_column' => $this->owner_guid_column,
			'guid_column' => $this->guid_column,
			'enabled_column' => $this->enabled_column,
			'query_builder' => $qb,
		];

		$clauses = elgg_trigger_plugin_hook('get_sql', 'access', $hook_params, [
			'ors' => $ors,
			'ands' => $ands,
		]);

		$ors = array_filter($clauses['ors']);
		$ands = array_filter($clauses['ands']);

		if (!empty($ors)) {
			$ands[] = $qb->merge($ors, 'OR');
		}

		return $qb->merge($ands);
	}

}
